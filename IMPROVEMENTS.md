# IMPROVEMENTS.md — zásobník nápadů na vylepšení

Výstup srovnání s konkurencí (Fakturoid, Vyfakturuj, iDoklad, POHODA příručka), 2026-07-02.
Před implementací čehokoli VŽDY ověř v aktuální verzi upstreamu, že to mezitím nepřibylo
(bod 4 a 5 z původního seznamu už v 4.43.4 existovaly).

## K implementaci ve forku (malé, aditivní)

- [x] **PWA** — manifest + ikony + apple-touch-icon; instalovatelná appka na plochu.
  HOTOVO 2026-07-02 (FÁZE 4, viz CUSTOMIZATIONS.md). Navazující nápad do budoucna:
  push notifikace (přišla platba / faktura po splatnosti) — vyžaduje service worker
  + Web Push na serveru; od iOS 16.4 funguje i na iPhonu.
- [x] **Pokladní doklady** — HOTOVO 2026-07-02 (FÁZE 5, viz CUSTOMIZATIONS.md).
  Nápady pro v2 (nezávislé na sobě):
  - **DPH režim (zjednodušený daňový doklad):** v1 je doklad jen o pohybu hotovosti —
    daňovým dokladem zůstává faktura a DPH výkazy pokladnu ignorují. Zákon o DPH ale
    u plateb do 10 000 Kč umožňuje zjednodušený daňový doklad („účtenka" místo faktury).
    V2 = rozpis základ + sazba + daň na dokladu a zapojení do VatLedgerService, aby se
    doklad propsal do DPH přiznání/KH — drobný hotovostní prodej pak jde odbavit jen
    pokladnou, bez faktury. Citlivý zásah do daňové evidence (proto není ve v1);
    má smysl JEN pokud se reálně prodává za hotové bez faktur.
  - **Zůstatek pokladny (pokladní kniha):** počáteční zůstatek + příjmy − výdaje =
    kolik má být fyzicky v kase; součty za měsíc/rok. Kontrola „sedí šuplík
    s evidencí?". Malé, bezpečné, lze udělat samostatně a rychle.
  - ~~Drobnost: výběr protistrany našeptávačem z klientů~~ — HOTOVO 2026-07-02 (FÁZE 6).

- [ ] **KPI dlaždice s trendy** — dashboard: srovnání s předchozím obdobím (↑/↓ %, barva dle směru, příp. sparkline). Zásah: dashboard API (data minulého období) + Dashboard.vue dlaždice. Střední náročnost.


### Per-řádková DPH při ISDOC importu se může lišit od dokladu o haléř (nález 29. 7. 2026)

`IsdocToPurchaseInvoiceMapper` zahodí řádkovou daň z ISDOC (`LineExtensionTaxAmount`)
a nechá ji dopočítat ZDOLA ze základu, kdežto řada dodavatelů ji počítá SHORA z brutto
(9 600 / 1,21 → daň 1 666,12; zdola vyjde 1 666,11). `PurchaseVatRecapSeeder` to nezachytí,
protože porovnává jen SOUČET za sazbu — a odchylky jednotlivých řádků se v součtu vyruší
(`if ($maxDiff <= 0.0) continue;`), takže `vat_overrides` zůstane NULL.

Důsledek: rekapitulace (to, co jde do DP3/KH a co vyžaduje § 73) je správně, ale jednotlivé
řádky se můžou od PDF dodavatele lišit o haléř. Reálně pozorováno u 4 z 11 řádků.

**Proč zatím neopraveno:** daňový dopad nulový — zákon vyžaduje základ a daň ZA SAZBU,
ne per řádek. Oprava by znamenala číst `LineExtensionTaxAmount` a po `recompute()` řádky
přišpendlit, tj. zásah do importní cesty všech ISDOC dokladů. Poměr přínos/riziko zatím
nevychází; udělat jako samostatný úkol s testem na doklad počítaný shora.

### `InvoiceMath::applyRateOverrides` může přišpendlit reziduum na ODPOČTOVÝ řádek § 37a

Reziduum se přišpendluje na řádek s největším `|base|` v sazbě. Je-li největší řádek
odpočtový (napárovaný DDKPZ — běžné, když zálohy převyšují největší položku faktury),
haléř skončí na něm a řádek přestane doslova odpovídat DDKPZ.

V cestě `PurchaseSettlementService` je to maskované — `pinAllSettlementRows()` řádek
vzápětí přepíše. Projevit se to může při přepočtu MIMO tuto cestu (editace dokladu,
dávkový `recompute-purchase-invoices.php`), kde odpočtový řádek zůstane posunutý;
doklad si toho všimne přes příznak `settlement_deduction_mismatch`.

**Proč zatím neopraveno:** `InvoiceMath` je sdílená peněžní matematika VŠECH dokladů
(vydaných i přijatých) a `$items`, které dostává, dnes příznak odpočtového řádku vůbec
nenesou (`PurchaseInvoiceCalculator` ho neselectuje). Oprava = protáhnout příznak skrz
kalkulátor a změnit výběr „nejsilnějšího řádku" — tedy zásah do jádra výpočtu kvůli
latentní, samodetekující se chybě o haléř. Udělat samostatně, s testem na doklad,
kde odpočtový řádek převyšuje největší položku.


## Kandidáti na issue u autora (radekhulan/myinvoice) — velké funkce

Levnější než vlastní implementace ve forku: když je autor přijme, získáme je updatem.

- [ ] **Platební brána + veřejná stránka faktury** (pay-by-link: odkaz s tlačítkem
  zaplatit kartou; GoPay/Comgate/Stripe). Dnes jen QR kód v PDF.
- [ ] **Přímé API napojení na banku** — začít Fio (jednoduché token API); auto-matching
  a cron infrastruktura už existují, chybí jen fetcher transakcí. Dnes GPC upload
  + e-mailová avíza.
- [ ] **Cenové nabídky** — nabídka → odsouhlasení klientem (veřejný link, vzor:
  existující schvalování) → konverze na fakturu.
- [ ] **PR: dark-mode gradienty** — `to-white` v gradientech září v dark modu
  (opraveno ve forku v ActionItemsWidget.vue commit 17ac040; poslat autorovi).

## Ověřeno, že UŽ EXISTUJE (neimplementovat)

- ~~Hlídání limitu registrace DPH~~ — je v sekci Daně (2 000 000 od 1. 1. + 2 536 500 ihned).
- ~~Export do POHODA XML~~ — Export vystavených/přijatých: Pohoda dataPack, ISDOC, STEREO; i import.
- ~~Automatické upomínky + poděkování za platbu~~ — per-supplier nastavení.
- ~~Odhad daní / daňový optimalizátor~~ — sekce Daně.
- ~~Přístup pro účetní~~ — role readonly/accountant + naše omezení na dodavatele (FÁZE 2).

## Vědomě zavrženo

Sklady, mzdy, dlouhodobý majetek, plné podvojné účetnictví — jiná liga produktu,
rozbilo by jednoduchost systému.

## Odloženo z auditu 2026-08-07 (DDKPZ § 37a v cizí měně)

- [ ] **§ 37a: záporný rozdíl v cizí měně přepočítán kurzem konečné faktury místo
  kurzu zálohy** (MEDIUM, nález auditu). `PurchaseSettlementService::link` páruje
  v libovolné měně a odpočtové řádky ukládá v měně dokladu; `VatLedgerService`
  pak VŠECHNY řádky konečné faktury (vč. odpočtů z DDKPZ) přepočítá jediným
  kurzem konečné faktury. Podle § 37a odst. 2 písm. b má být odpočet zálohy
  přepočten kurzem ZÁLOHY. Chyba dopadá na PŘEPLATKY (záporný rozdíl) a smíšené
  vícesazbové případy; kladný rozdíl (doplatek) je kurzem DUZP správně.
  **Proč odloženo:** správná oprava vyžaduje, aby odpočtové řádky nesly vlastní
  kurz (kurz zálohy) a ledger je konvertoval ODDĚLENĚ od zbytku faktury — to je
  schématická + ledger změna, ne bezpečná stejnodenní úprava daňového kódu.
  Úzký případ (cizoměnová záloha + pohyb kurzu + přeplatek). Vyžaduje vlastní
  návrh a ověření na reálných datech. Ostatních 5 DDKPZ nálezů auditu opraveno
  v commitu fix/audit-2026-08-07.
