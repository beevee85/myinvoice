# Stínová validace nad historií — výchozí měření

Výstup `api/bin/shadow-validate-existing.php` nad **klonem produkční databáze**
(`myinvoice_clone_shadow`, pořízeno 29. 7. 2026). Read-only, bez sítě, bez zápisu.

Dokument obsahuje **jen agregovaná čísla** — žádné údaje o konkrétních dokladech,
dodavatelích ani částky.

---

## Souhrn

| | |
|---|---:|
| dokladů prověřeno | **62** |
| prošlo | **59** (95,2 %) |
| nálezy | **3** (4,8 %) |
| — z toho `legacy_gap` (údaj se tehdy nesbíral) | 2 |
| — z toho **`real_mismatch`** (hodnoty si protiřečí) | **1** |

## Podle pravidla

| pravidlo | nálezů | kategorie |
|---|---:|---|
| `items.*.description` | 2 | `legacy_gap` |
| `items.*.quantity` | 1 | `real_mismatch` |

## Podle zdroje zápisu

Heuristika, viz limity v `SHADOW-VALIDATION.md`.

| zdroj | dokladů | nálezů | podíl |
|---|---:|---:|---:|
| `ai_pdf` | 50 | 1 | 2,0 % |
| `isdoc` | 6 | 2 | 33,3 % |
| `ai_pdf` nebo ruční s PDF | 6 | 0 | 0,0 % |

## Podle typu dokladu

| typ | dokladů | nálezů | podíl |
|---|---:|---:|---:|
| `invoice` | 38 | 2 | 5,3 % |
| `advance` | 19 | 0 | 0,0 % |
| `tax_document` | 4 | 0 | 0,0 % |
| `credit_note` | 1 | 1 | 100,0 % |

Všechny doklady jsou z roku 2026 (instance běží od jara 2026).

---

## Co z toho plyne

**Vynucení validace na importní cesty je reálné, ale ne bez jedné úpravy pravidla.**

Rozpad je příznivější, než se čekalo: 95 % historie by prošlo beze změny a jediný
`real_mismatch` v celé databázi má **jednu konkrétní příčinu**, ne systémovou vadu dat.

### Ten jediný skutečný rozpor

Jde o **zaplacený dobropis, jehož součty jsou v pořádku**. Část jeho řádků je popisná —
mají nulové množství i nulovou cenu a celou částku nese jeden řádek. Ověřeno, že to
**nejsou** systémové řádky vyúčtování zálohy podle § 37a ani zaokrouhlovací řádek
(`is_settlement_rounding = 0`, `settlement_source_purchase_invoice_id IS NULL`).

Pravidlo `InvoiceAmountPolicy::validateItem` ale nulové množství odmítá
(„Množství nesmí být 0."). Kdyby se validace na importy vynutila v dnešní podobě,
**odmítla by účetně bezvadný doklad** jen proto, že extrakce vyrobila popisné řádky.

### Ty dva `legacy_gap`

Dvě položky s prázdným popisem na běžných fakturách — typický import bez textu položky.
Úklid historie, ne důvod odkládat vynucení.

### Doporučení

1. **Nejdřív rozhodnout osud popisných řádků.** Buď je importní cesty nemají vůbec tvořit
   (slučovat je do popisu nosného řádku), nebo je pravidlo „množství ≠ 0" musí pro
   nulové řádky bez ceny připustit. Rozhodnout by měla účetní — z hlediska DPH je
   nulový řádek neškodný, z hlediska čitelnosti dokladu může být užitečný.
2. **Pak vynutit.** Po té úpravě by dnešní historie prošla na 100 % kromě dvou prázdných
   popisů, které jde doplnit ručně.
3. **`isdoc` má nejvyšší podíl nálezů** (33 %), ale na 6 dokladech — statisticky
   bezcenné. Znovu změřit, až jich bude víc.

---

## Poznámky k platnosti měření

* **Malý vzorek.** 62 dokladů je málo na procenta; čísla berte jako indikaci, ne statistiku.
  Skript se dá pustit znovu kdykoli, náklad je nulový (běh trvá setiny sekundy).
* **Neměří všechno.** Katalog V1–V86 je širší než `PurchaseInvoiceValidation`. Nevyhodnocené
  skupiny se hlásí ve výstupu výslovně:
  * `V1–V8, V33–V35` → `no_source_document` (integrita souboru a QR jen nad původní dávkou),
  * `V19–V25, V32, V52` → `needs_network` (ARES, registr plátců, VIES, ČNB — skener je offline),
  * `V9–V14, V49` → `not_applicable` (struktura odpovědi modelu, historické doklady ji nemají).
* **Smazané doklady se nepočítají** (`deleted_at IS NULL`).
* **Zdroj zápisu je odvozený**, ne uložený — viz limity heuristiky v `SHADOW-VALIDATION.md`.

## Jak měření zopakovat

```bash
# 1) záloha produkce a klon (klon MUSÍ mít v názvu test/ci/clone, jinak skript odmítne start)
docker compose -f /opt/myinvoice/docker-compose.yml exec -T db \
  sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" myinvoice' > /root/backup-myinvoice-db-$(date +%F-%H%M).sql
docker exec myinvoice-db-1 sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e \
  "DROP DATABASE IF EXISTS myinvoice_clone_shadow; CREATE DATABASE myinvoice_clone_shadow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
docker exec -i myinvoice-db-1 sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" myinvoice_clone_shadow' < /root/backup-myinvoice-db-*.sql

# 2) měření
cd /opt/myinvoice && docker run --rm --network host -e MYINVOICE_DB_NAME=myinvoice_clone_shadow \
  -v /opt/myinvoice:/work -w /work myinvoice:latest php api/bin/shadow-validate-existing.php

# strojově čitelně
… php api/bin/shadow-validate-existing.php --json
```
