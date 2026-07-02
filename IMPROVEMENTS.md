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
  Nápady pro v2: DPH režim „zjednodušený daňový doklad" (přes VatLedgerService),
  výběr protistrany z klientů, souhrn stavu pokladny (zůstatek za období).

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
