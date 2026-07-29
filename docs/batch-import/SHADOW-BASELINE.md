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
| prošlo | **60** (96,8 %) |
| nálezy | **2** (3,2 %) |
| — z toho `legacy_gap` (údaj se tehdy nesbíral) | 2 |
| — z toho **`real_mismatch`** (hodnoty si protiřečí) | **0** |
| popisných řádků (legitimní dle V43b) | 4 |

## Podle pravidla

| pravidlo | nálezů | kategorie |
|---|---:|---|
| `items.*.description` | 2 | `legacy_gap` |

## Podle zdroje zápisu

Heuristika, viz limity v `SHADOW-VALIDATION.md`.

| zdroj | dokladů | nálezů | podíl |
|---|---:|---:|---:|
| `ai_pdf` | 50 | 0 | 0,0 % |
| `isdoc` | 6 | 2 | 33,3 % |
| `ai_pdf` nebo ruční s PDF | 6 | 0 | 0,0 % |

## Podle typu dokladu

| typ | dokladů | nálezů | podíl |
|---|---:|---:|---:|
| `invoice` | 38 | 2 | 5,3 % |
| `advance` | 19 | 0 | 0,0 % |
| `tax_document` | 4 | 0 | 0,0 % |
| `credit_note` | 1 | 0 | 0,0 % |

Všechny doklady jsou z roku 2026 (instance běží od jara 2026).

---

## Co z toho plyne

**V historii není ani jeden skutečný rozpor** — po přepsání V43 (viz níže) zůstávají
jen dva prázdné popisy položek, tedy úklid, ne překážka.

### Jak se z jednoho `real_mismatch` stala nula

První měření našlo jediný `real_mismatch`: **zaplacený dobropis, jehož součty jsou
v pořádku**, ale část řádků má nulové množství i cenu a celou částku nese jeden řádek.
Ověřeno, že to **nejsou** systémové řádky vyúčtování zálohy podle § 37a ani zaokrouhlovací
řádek (`is_settlement_rounding = 0`, `settlement_source_purchase_invoice_id IS NULL`) —
bez toho ověření by závěr byl jen domněnka.

Ukázalo se, že chyba nebyla v datech, ale **v pravidle**. Původní V43 („quantity > 0")
byla napsaná příliš hrubě: textové řádky na dokladu jsou normální věc a rozhodující není
nulové množství, ale **jestli řádek tvrdí, že něco stojí**. V43 proto bylo přepsáno na
V43/V43b–V43e (`docs/batch-import/PLAN.md`, sekce 13) a skener podle nové definice
klasifikuje. Ty čtyři řádky jsou teď legitimní `text_line` a doklad prochází.

**Kdyby se tohle neodhalilo, vynucení validace by odmítalo účetně bezvadné doklady.**
To je přesně ten důvod, proč stínový režim existuje.

### Ty dva `legacy_gap`

Dvě položky s prázdným popisem na běžných fakturách — typický import bez textu položky.
Prázdný popis u **peněžního** řádku zůstává nálezem právem; jde doplnit ručně.

### Doporučení

1. **Vynucení ještě nezapínat.** Ne kvůli číslům — ta jsou příznivá — ale proto, že
   62 dokladů neukázalo dost *různých* režimů selhání. Hodnota stínového režimu je v tom,
   že vyjmenuje způsoby, jak se to rozbije, ne že spočítá poměr. Rozhodnutí patří až za
   dokončený doménový validátor, kdy bude v ruce reálný katalog nálezů.
2. **Nechat běžet.** Z nových importů se mezitím nabírá materiál bez rizika.
3. **Přepsat `InvoiceAmountPolicy::validateItem` podle V43b** — ale až s doménovým
   validátorem, je to změna chování ruční cesty a zaslouží si vlastní commit.
4. **`isdoc` má nejvyšší podíl nálezů** (33 %), ale na 6 dokladech — statisticky
   bezcenné. Znovu změřit, až jich bude víc.

---

## Poznámky k platnosti měření

* **Malý vzorek — a nejde jen o procenta.** 62 dokladů je málo hlavně proto, že to
  neukázalo dost různých režimů selhání. Skript se dá pustit znovu kdykoli, náklad je
  nulový (běh trvá setiny sekundy).
* **Skener klasifikuje podle V43b, produkční validátor zatím ne.** Analýza tedy ukazuje
  stav podle dohodnutého pravidla; vynucení se nezměnilo.
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
