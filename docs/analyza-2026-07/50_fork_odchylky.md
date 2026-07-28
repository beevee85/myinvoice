# 50 · Odchylky forku od upstreamu a od manuálu

Snapshot: 2026-07-28. Zdroje: lokální git (`/opt/myinvoice`, větev `custom`), databáze běžící
instance (jen čtecí dotazy), `CUSTOMIZATIONS.md`, `IMPROVEMENTS.md`, routy API a menu aplikace.

## 1. Shrnutí

| Otázka ze zadání | Odpověď |
|---|---|
| Zaostáváme za upstreamem? | **Ne.** `custom` obsahuje celý `upstream/master` (0 commitů pozadu) a upstream master = tag `v4.51.0`. Fork je naopak napřed: release **4.51.1** (fork-only bump) + vlastní úpravy. |
| Rozsah odchylky | ~150 změněných souborů proti upstreamu, +9 884 / −1 416 řádků. Vlastní migrace **0900–0904** (a 0905 v rozdělaném worktree). |
| Chybí něco z manuálu v menu? | **Prakticky nic.** Všechny moduly manuálu jsou v menu (viz § 4). Neexistují jen věci, které nemá ani upstream: cenové nabídky, sklad, objednávky, dodací listy. |
| Máme něco navíc proti manuálu (upstream)? | Ano — pokladní doklady, omezení uživatele na dodavatele, interní poznámka, zámek dokladu s odemykáním, opravy DPH výkazů, redesign UI + PDF (viz § 2). |

## 2. Co má fork navíc proti upstreamu

### 2.1 Funkční nadstavby (FÁZE 1–8, červenec 2026)

- **Omezení uživatele na vybrané dodavatele** (`user_supplier_access`, migrace 0900) — admin
  přiřadí uživateli s rolí `accountant`/`readonly` konkrétní firmy; enforcement v middleware
  (403 i při přímém API volání). Přesně tohle je základ pro přístup externí účetní
  v multi-supplier instanci — upstream to nemá.
- **Pokladní doklady PPD/VPD** (migrace 0901) — Finance → Pokladna, číselné řady per dodavatel,
  PDF s částkou slovy, vazba na hotovostní fakturu. Vědomě v1 bez vlastní DPH evidence.
- **Interní poznámka u faktury** (migrace 0902) — pole viditelné jen v aplikaci, nikdy na PDF.
- **PDF šablona (redesign F8, migrace 0903)** — platební pás s QR přes celou šířku,
  rekapitulace DPH, razítko/podpis (`signature_path` oživen + upload API), právní věta,
  volitelný Code128, štítek ZAPLACENO, Strana X z Y, vypnutelná attribution patička,
  živý náhled v Nastavení → Vzhled dokladu (`GET /api/settings/document-preview.pdf`).
- **Kompletní redesign UI (8 fází)** — app shell (tmavá plocha + plovoucí panel), 13 UI
  komponent, redesign dashboardu (graf obratu s přepínačem období), seznamu a detailu faktur.
  Bez změn API a datového modelu.

### 2.2 Opravy odeslané upstreamu (PR radekhulan/myinvoice#245, issue #244 — čeká na přijetí)

7 chyb DPH výkazů + navazující UX: znaménko přijatých dobropisů (-ABS), fallback klasifikace
zahraničního reverse charge (24e/24), forma podání B/O/N/D/E + datum zjištění, posun termínů
na pracovní den (`CzechWorkingDays`, § 33/4 DŘ), konzistence `reverse_charge` vs. klasifikace,
**zámek dokladu s odemykáním v UI** (modal, audit `force_edit` s diffem, `rebuild-snapshots`),
**`EpoIdentityValidator`** (422 při nekompletní identifikaci podatele), CZ-NACE normalizace
na 6místný kód (EPO chyba 30) + warning zaokrouhlení (chyba 49), VIES výjimka pro CZ699.
Po přijetí PR se tento blok stane součástí upstreamu (řízený merge tagu dle CLAUDE.md).

### 2.3 Rozdělaná práce (in-flight, NEzacommitováno — do analýzy nesahat)

- **DDKPZ na přijaté straně** — pracovní kopie `/opt/myinvoice`: migrace
  `0904_purchase_tax_document.sql` (document_kind `tax_document`, vazba na vyúčtovací fakturu
  dle § 37a, auto-odpočtové řádky), `PurchaseSettlementService`, link/unlink akce, úpravy
  editoru a AI extrakce. Podklad: [docs/dph-zalohy.md](../dph-zalohy.md) (rešerše § 20a, § 28,
  § 37a, § 72–73, KH — s rozhodovacím stromem). Na **vydané** straně DDKPZ už existuje
  (upstream: `invoice_type='tax_document'`, `POST /api/invoices/{id}/payments/{paymentId}/tax-document`).
- **Koš dokladů (soft-delete faktur)** — worktree `/root/mi-trash`, větev
  `feature/document-trash`, migrace 0905, `DocumentTrashService/Policy`, restore/force-delete
  akce. To je ta **už zadaná feature „admin mazání dokladů / koš"** — tato analýza ji
  neduplikuje, jen na ni navazuje (viz 40_navrhy.md).

## 3. Fork vs. publikovaný manuál

Manuál je součástí repa (`manual/*.md`, 42 kapitol) a fork ho rozšířil o vlastní sekce, které
publikovaná verze na myinvoice.cz nemá: 10.10 (interní poznámka), 24.7 (pokladní doklady),
36.2.3 (omezení uživatele na dodavatele), rozšíření kap. 29 (forma podání, termíny, dobropisy,
CZ-NACE troubleshooting, EPO identifikace) a kap. 9/17 (zámek dokladu). Publikovaný manuál
tedy popisuje upstream v4.51.0; reálný stav forku = manuál + tyto sekce.

## 4. Menu vs. manuál — ověření položek ze zadání

Levé menu (AppLayout) má sekce Prodej / Nákup / Klienti / Finance / Dokumenty / Daně / Systém
a **všechny** sporné položky ze zadání v něm jsou:

| Položka ze zadání | Stav v menu/aplikaci |
|---|---|
| Kniha jízd | ✅ `/logbook` (jízdy, tankování, vozidla, kategorie, exporty) |
| Daňový průvodce / Daň z příjmů | ✅ `/reports/income-tax` („Daň z příjmů") |
| Daňový optimalizátor | ✅ `/tax` („Daňový optimalizátor" — srovnání daňových režimů) |
| Elektronické podpisy | ✅ `/admin/electronic-signatures` + podpisové profily (PDF signing PAdES) |
| Sklad | ❌ neexistuje ani v upstreamu (vědomě zavrženo, viz IMPROVEMENTS.md) |
| Ceník | ✅ `/admin/price-list` (položky, ceny per měna, zákaznické ceny) — **v instanci nevyužito (0 položek)** |
| Cenové nabídky | ❌ neexistují ani v upstreamu (kandidát na issue autorovi, IMPROVEMENTS.md) |
| Zálohové faktury | ✅ proforma (`/invoices/new?type=proforma`, v quick menu „Zálohová faktura") + vazba záloha→konečná faktura (§ 37a: `link-advance`, `issue-final`) + DDKPZ k platbě |
| Koš / obnova smazaného | 🟡 u modulu Dokumenty ano (`/api/documents/trash`); u faktur zatím ne — řeší in-flight feature 0905 |

Dále v menu: CRM, Tržby, Náklady, Banka (výpisy + avíza), Pokladna (fork), Platební příkazy,
Schvalování, Archiv podání, Hromadný export, OSS přiznání, Kniha DPH, SHV, Aktualizace,
API tokeny, Číselníky, E-mail šablony + odesílací profily + SMTP log analýza, Manuál.

**Korekce premisy zadání:** `/help` vrací 200 jen jako SPA fallback (není to routa), ale
**in-app nápověda existuje** — `/manual` (nginx → `manual/index.php`, plný HTML manuál) a je
odkázaná z menu („Nápověda"). Co reálně chybí, není manuál, ale **kontextová** nápověda
(tooltipy/odkazy z konkrétních obrazovek do příslušné kapitoly).

## 5. Reálná konfigurace a data instance (k 2026-07-28)

- **Dodavatelé (2):** BEKRON, s.r.o. (IČ 28173309, plátce, PO, řada `{YY}FA/{MM}/{CC}` →
  `26FA/04/01`, splatnost 14 dní, perioda číslování měsíční) · PROPSOL, s.r.o. (IČ 24682993,
  plátce, PO, kvartální DPH, řada `{YYYY}{MM}{CC}`). Zvláštní řady pro proformu/dobropis/přijaté
  nejsou nastavené (dědí formát faktur).
  ⚠ **BEKRON nemá vyplněné `vat_period`** (PROPSOL má quarterly) — pro výkazy doplnit.
- **Uživatelé:** 1 aktivní admin (2FA zapnuto, `require_totp=true` globálně), 1 deaktivovaný
  admin. **Žádný účet s rolí `accountant`/`readonly` neexistuje** — infrastruktura pro účetní
  (role + omezení na dodavatele) je hotová, ale účetní reálně přístup nemá. Klíčový fakt pro
  personu B.
- **Data:** 13 klientů (z toho 7 dodavatelů), 13 vydaných faktur (5 BEKRON + 8 PROPSOL),
  57 přijatých dokladů (39 faktur, 17 záloh, 1 dobropis), 15 dokumentů v DMS,
  **156 záznamů v archivu podání** (aktivně využíváno), sazby DPH 21/12/0/RC.
- **AI import:** BYOK Anthropic, model `claude-opus-4-7`, 55 provedených extrakcí (9+46).
  ARES (12 cache záznamů) i VIES (4) se používají.
- **Banka — infrastruktura nevyužita:** 0 výpisů, 0 transakcí, 0 IMAP účtů pro avíza,
  0 spárovaných plateb. Crony `cron-bank-scan` a `cron-bank-email-notices` běží naprázdno.
  Seedovaný provider avíz jen Česká spořitelna; **Fio (banka instance) nemá ani avízo
  provider, ani API napojení** — platby se označují ručně. Účty: 3× CZK, EUR bez účtu.
- **Nevyužité moduly:** Ceník (0 položek), Pravidelné fakturace (0), Pokladna (0 dokladů),
  Kniha jízd (0 jízd/vozidel), Výkazy práce (0), API tokeny (0), kategorie nákladů/tržeb (0),
  e-mailové odesílací profily (0 — maily jdou přes globální SMTP), brandingové profily vypnuté.
- **Crony běží:** scan-purchase-inbox (à 5 min), bank-email-notices, bank-scan,
  generate-recurring, version-check, cleanup, backup (DB/PDF/dokumenty), send-reminders,
  send-approval-reminders. Automatické upomínky zapnuté (3 dny po splatnosti) u obou firem;
  poděkování za platbu vypnuté.
- **Konfigurace (`cfg.local.php`):** jen `require_totp=true` a URL — vše ostatní na výchozích
  hodnotách. Redis nezapnut (DB sessions), watcher aktualizací vědomě nevyužit (fork build).

## 6. Důsledky pro gap analýzu

1. Řada „mezer" nalezených u konkurence bude ve skutečnosti **mezera v adopci, ne v software**
   (banka, ceník, opakované fakturace, e-mailové profily, role účetní). Návrhy v 40_navrhy.md
   to rozlišují: „zapnout/nakonfigurovat" vs. „dostavět".
2. Persona B dnes nemá do systému žádný vstup — přitom role, omezení na firmy i exporty
   existují. Chybí hlavně **workflow** (předání podkladů, kontrola úplnosti, komunikace).
3. Fio napojení a DDKPZ na přijaté straně jsou už rozpracované směry (IMPROVEMENTS.md,
   migrace 0904) — analýza je má potvrdit/upřesnit, ne vymýšlet znovu.
