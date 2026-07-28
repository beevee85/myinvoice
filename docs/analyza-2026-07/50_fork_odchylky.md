# 50 · Odchylky forku od upstreamu a od manuálu

Snapshot: 2026-07-28. Zdroje: lokální git (`/opt/myinvoice`), databáze běžící instance
(jen čtecí dotazy), `CUSTOMIZATIONS.md`, `IMPROVEMENTS.md`, routy API a menu aplikace.

> **Aktualizováno 28. 7. večer.** Analýza vznikla ráno nad verzí 4.51.1; během dne padlo
> 49 commitů (upstream merge na 4.52.1, dokončení DDKPZ, koš dokladů). Dokument popisuje
> **večerní stav**; co se během dne změnilo, je označeno „⟳ během dne".

## 1. Shrnutí

| Otázka ze zadání | Odpověď |
|---|---|
| Zaostáváme za upstreamem? | **Ne.** `custom` obsahuje celý `upstream/master` (0 commitů pozadu), verze **4.52.1**. ⟳ Během dne mergnuty tagy v4.52.0 a v4.52.1 — a **v4.52.0 obsahuje náš přijatý PR #245**. |
| Rozsah odchylky | Vlastní migrace **0900–0911** (0900 mezitím zahozena — viz § 2.2). Fork drží pokladnu, interní poznámku, redesign UI+PDF, koš dokladů a DDKPZ na přijaté straně. |
| Chybí něco z manuálu v menu? | **Prakticky nic.** Všechny moduly manuálu jsou v menu (§ 4). Neexistují jen věci, které nemá ani upstream: cenové nabídky, sklad, objednávky, dodací listy. |
| Máme něco navíc proti manuálu (upstream)? | Ano — pokladní doklady, interní poznámka, DDKPZ na přijaté straně vč. § 37a, koš dokladů, redesign UI a PDF (§ 2). |

## 2. Co má fork navíc proti upstreamu

### 2.1 Funkční nadstavby

- **Pokladní doklady PPD/VPD** (migrace 0901) — Finance → Pokladna, číselné řady per dodavatel,
  PDF s částkou slovy, vazba na hotovostní fakturu. Vědomě v1 bez vlastní DPH evidence.
- **Interní poznámka u faktury** (migrace 0902) — pole viditelné jen v aplikaci, nikdy na PDF.
- **PDF šablona (redesign F8, migrace 0903)** — platební pás s QR přes celou šířku, rekapitulace
  DPH, razítko/podpis, právní věta, volitelný Code128, štítek ZAPLACENO, Strana X z Y,
  vypnutelná attribution patička, živý náhled v Nastavení → Vzhled dokladu.
- **Kompletní redesign UI (8 fází)** — app shell (tmavá plocha + plovoucí panel), 13 UI
  komponent, redesign dashboardu, seznamu a detailu faktur. Bez změn API a datového modelu.
- ⟳ **DDKPZ na přijaté straně + § 37a** (migrace 0904, 0906, 0907, 0909, 0910, 0911) — dokončeno
  ve třech dávkách během dne, viz § 2.3. Podklad: [docs/dph-zalohy.md](../dph-zalohy.md).
- ⟳ **Koš dokladů + tvrdé mazání** (migrace 0905) — dokončeno během dne, viz § 2.3.

### 2.2 Co se během dne vyřešilo směrem k upstreamu

- ✅ **PR #245 PŘIJAT** — opravy DPH výkazů (dobropisy, zahraniční RC, forma podání, termíny dle
  pracovních dnů), zámek dokladu s odemykáním, kontrola EPO identifikace a CZ-NACE jsou od
  **v4.52.0 součástí upstreamu**. Fork si přinesl zpět i autorovy revizní opravy — hlavně
  **CZ-NACE kanonizaci proti číselníku ČINNOSTI** místo dřívějšího paddingu.
- ⚠️ **Dodatečné DP3 (formy D/E) dočasně VYPNUTY** (commit 247867e4): builder pro ně generoval
  plné částky období místo rozdílu proti poslední známé dani (§ 141 odst. 2 DŘ) — uživatel by
  jedním klikem stáhl věcně špatné XML ve svůj neprospěch. Nabízeny jsou jen formy B/O.
  **Následné KH (N/E) funguje dál** — podává se kompletní, takže rozdílový dopočet nepotřebuje.
  Re-enable = doplnit dopočet rozdílů. **Tím padá tvrzení z ranní verze analýzy, že dodatečná
  přiznání jsou náš unikát vůči konkurenci** — platí to už jen pro následné KH.
- ✅ **FÁZE 2 (omezení uživatele na firmy) převzata jinak:** PR #247 autor zavřel a vydal
  vlastní implementaci — tabulka `user_suppliers` (migrace 0148) se schématem sdíleným
  s MyÚčto.cz + **override role per firmu**, resoluce v `SupplierAccessResolver`. Naše
  `user_supplier_access` odstraněna migrací **0908** (v produkci 0 řádků). Funkčně jsme tedy
  o schopnost nepřišli, naopak je bohatší (role per firmu) — a udržuje ji upstream.

### 2.3 Co se během dne dokončilo (bylo „rozdělané" v ranní verzi)

- **DDKPZ na přijaté straně** — typ dokladu `tax_document`, vazba na vyúčtovací fakturu,
  automatické odpočtové řádky dle § 37a s korektním zaokrouhlením (haléřové rozdíly nesou
  viditelný řádek „Zaokrouhlení § 37a"), nová sazba **CZ-NA „Mimo DPH"** pro zálohy (nikdy
  nespadne do DP3/KH), invariant znamének, vyloučení záloh z nákladových agregací, opravy
  exportů (Pohoda: DIČ + evidenční číslo dodavatele + platební VS; ISDOC: `TaxedDeposits`
  a `AlreadyClaimed` dle § 37a), blokující rozpor v AI extrakci a CLI přepočet dokladů.
  **Reálně v provozu: BEKRON má 4 DDKPZ.** → uzavírá návrh N-008.
- **Koš dokladů** (0905) — soft-delete se snapshoty, retence (výchozí **30 dní**), cron výsyp,
  hromadné operace, read-only guard a vyloučení z agregací; DPH blokace u dokladů ve výkazech.
  → uzavírá návrh N-019 (retenci i hlídání vazeb doporučovala analýza a jsou v implementaci).

Obě funkce jsou v `CUSTOMIZATIONS.md` vedené jako **kandidáti pro upstream odložení na po
provozním ověření** (ideálně po podání KH za 05–07/2026), v pořadí: nejdřív koš, pak DDKPZ.

## 3. Fork vs. publikovaný manuál

Manuál je součástí repa (`manual/*.md`, ⟳ nyní **44 kapitol**, publikovaná verze na myinvoice.cz
měla ráno 42) a fork ho rozšířil o vlastní sekce: 9.7/17.9 (koš vs. storno vs. trvalé smazání),
10.10 (interní poznámka), 24.7 (pokladní doklady), rozšíření kap. 29 (forma podání, termíny,
dobropisy, CZ-NACE, EPO identifikace). Publikovaný manuál popisuje upstream; reálný stav forku
= manuál + tyto sekce.

## 4. Menu vs. manuál — ověření položek ze zadání

Levé menu má sekce Prodej / Nákup / Klienti / Finance / Dokumenty / Daně / Systém a **všechny**
sporné položky ze zadání v něm jsou:

| Položka ze zadání | Stav v menu/aplikaci |
|---|---|
| Kniha jízd | ✅ `/logbook` (jízdy, tankování, vozidla, kategorie, exporty) |
| Daňový průvodce / Daň z příjmů | ✅ `/reports/income-tax` |
| Daňový optimalizátor | ✅ `/tax` (srovnání daňových režimů) |
| Elektronické podpisy | ✅ `/admin/electronic-signatures` + podpisové profily (PAdES) |
| Sklad | ❌ neexistuje ani v upstreamu (vědomě zavrženo, IMPROVEMENTS.md) |
| Ceník | ✅ `/admin/price-list` — **v instanci nevyužito (0 položek)** |
| Cenové nabídky | ❌ neexistují ani v upstreamu (kandidát na issue autorovi) |
| Zálohové faktury | ✅ proforma + vazba záloha→konečná faktura (§ 37a) + DDKPZ na obou stranách |
| Koš / obnova smazaného | ⟳ ✅ **hotovo** — koš u faktur i přijatých dokladů (0905), retence 30 dní |

Dále v menu: CRM, Tržby, Náklady, Banka, Pokladna (fork), Platební příkazy, Schvalování, Archiv
podání, Hromadný export, OSS přiznání, Kniha DPH, SHV, Aktualizace, API tokeny, Číselníky,
E-mail šablony + odesílací profily + SMTP log analýza, Manuál.

**Korekce premisy zadání:** `/help` vrací 200 jen jako SPA fallback (není to routa), ale
**in-app nápověda existuje** — `/manual` (nginx → `manual/index.php`) odkázaný z menu. Co chybí,
není manuál, ale **kontextová** nápověda (odkazy z konkrétních obrazovek do kapitol) → N-011.

## 5. Reálná konfigurace a data instance (večer 2026-07-28)

- **Dodavatelé (2):** BEKRON, s.r.o. (IČ 28173309, plátce, PO, řada `{YY}FA/{MM}/{CC}`,
  splatnost 14 dní) · PROPSOL, s.r.o. (IČ 24682993, plátce, PO, kvartální DPH,
  řada `{YYYY}{MM}{CC}`).
  ⚠ **BEKRON stále nemá vyplněné `vat_period`** (PROPSOL má quarterly) → součást N-001.
- **Uživatelé:** 1 aktivní admin (2FA), 1 deaktivovaný. **Žádný účet s rolí účetní** —
  infrastruktura existuje (nově upstream `user_suppliers` + role per firmu), ale účetní
  přístup nemá. Klíčové pro personu B.
- **Data:** 13 klientů (7 dodavatelů), 13 vydaných faktur, přijaté doklady:
  BEKRON 2 faktury + 4 zálohy + **4 DDKPZ**, PROPSOL 36 faktur + 15 záloh + 1 dobropis;
  15 dokumentů v DMS, **156+ záznamů v archivu podání**, sazby DPH 21/12/0/RC + ⟳ CZ-NA „Mimo DPH".
- **AI import:** BYOK Anthropic, 55+ extrakcí. ARES i VIES se používají.
- **Banka — infrastruktura nevyužita:** 0 výpisů, 0 transakcí, 0 IMAP účtů, 0 spárovaných plateb.
  Crony `cron-bank-scan` a `cron-bank-email-notices` běží naprázdno. Fio nemá ani avízo provider,
  ani API napojení → platby se označují ručně (N-003).
- **Nevyužité moduly:** Ceník (0), Pravidelné fakturace (0), Pokladna (0), Kniha jízd (0),
  Výkazy práce (0), API tokeny (0), kategorie nákladů/tržeb (0), e-mailové odesílací profily (0).
- **Crony běží:** scan-purchase-inbox, bank-email-notices, bank-scan, generate-recurring,
  version-check, cleanup, zálohy (DB/PDF/dokumenty), send-reminders, send-approval-reminders
  (⟳ + výsyp koše). Automatické upomínky zapnuté (3 dny po splatnosti); poděkování za platbu vypnuté.
- **Konfigurace (`cfg.local.php`):** jen `require_totp=true` a URL; Redis nezapnut; watcher
  aktualizací vědomě nevyužit (fork build).

## 6. Důsledky pro gap analýzu

1. Řada „mezer" nalezených u konkurence je **mezera v adopci, ne v software** (banka, ceník,
   opakované fakturace, e-mailové profily, role účetní). Návrhy to rozlišují: „zapnout" vs. „dostavět".
2. Persona B dnes nemá do systému žádný vstup — přitom role, přiřazení firem i exporty existují.
   Chybí **workflow** (předání podkladů, kontrola úplnosti, zámek období).
3. ⟳ Dva ze čtyř Must návrhů (N-008 DDKPZ přijatá strana, N-019 koš) jsou během dne **hotové
   a nasazené**. Zbývající Must: N-001 (adopce) a N-002 (auto DDKPZ na vydané straně
   s hlídáním 15denní lhůty) + N-003 (Fio).
4. ⟳ Nově vzniklý dluh k zaevidování: **dopočet rozdílů pro dodatečné DP3** (§ 141 odst. 2 DŘ) —
   dnes vypnuto, do té doby se dodatečné přiznání dělá mimo aplikaci.
