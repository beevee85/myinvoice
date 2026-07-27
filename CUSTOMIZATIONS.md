# CUSTOMIZATIONS.md — evidence vlastních úprav této instalace

Instalace: `faktury.betka.eu`, VPS, `/opt/myinvoice`. Pravidla práce viz `CLAUDE.md` (gitignored, jen na serveru).

Po každém updatu z upstreamu projdi celý seznam níže a ověř, že žádná úprava tiše nevypadla.

---

## 2026-07-27 — REDESIGN Fáze 5: detail faktury (větev feature/redesign)

**Co se změnilo (`web/src/pages/invoices/InvoiceDetail.vue` — cílené úpravy sekcí, logika+ActionBar tiery beze změny):**
1. Hlavička: Zpět jako kruhové ikonové tlačítko, číslo faktury 28/700 (`h1.h1-doc` v redesign.css), všechny badge → pilulky (rounded-full).
2. Karta klienta na `--surface-muted` s avatarem z iniciál (computed `clientInitials`), IČO/DIČ vpravo.
3. Tři info karty (Data / Měna a DPH / Bankovní účet): `--surface-muted` + radius-card, label 13 px muted vlevo, hodnota 15/600 vpravo, bez borderů.
4. Položky → `.ui-table` (hlavička surface-muted, `.num` sloupce); Sumace jako `--surface-muted` blok, „Celkem" 22/700 v primary. Poznámka nad položkami sladěna.
5. Přílohy: drag&drop zóna s dashed borderem 2 px a radius-card, centrovaný obsah, hover/drag zvýraznění.
6. Aktivita: místo tabulky+karet jednotná **timeline s barevnými tečkami** (nový helper `actionDot` zrcadlí kategorie `actionColor`; štítek akce dál používá actionColor pilulku).

**Proč:** Fáze 5 redesignu dle zadání. **Jak ověřit po merge:** build ✓, testy 50/50 ✓; detail: hlavička s pilulkami, muted karty, timeline v Aktivitě, dashed upload zóna.

## 2026-07-27 — REDESIGN Fáze 4: přestavba stránky Vydané faktury (větev feature/redesign)

**Co se změnilo (`web/src/pages/invoices/InvoiceList.vue` — logika 1:1, přestavěn template):**
1. **Taby stavů** (Všechny/Zaplacené/Nezaplacené/Po splatnosti/Koncepty) = presety EXISTUJÍCÍCH filtrů (paid/draft → `filter[status]`, Nezaplacené → `unpaid_only`, Po splatnosti → `overdue`); žádná nová API sémantika, URL query beze změny. Jiný stav z panelu (Vystaveno…) = žádný aktivní tab.
2. **Toolbar hromadných akcí jako kruhové ikony** — stejných 6 akcí + CSV (PDF výběru, vystavit, označit zaplaceno, odeslat, upomínky, klonovat), nově `disabled` dokud výběr nesplňuje podmínky akce (dřív se tlačítka objevovala/mizela). Tooltipy s počty přes stávající i18n klíče.
3. **Panel filtrů** (--surface-muted, radius 20) rozbalovaný tlačítkem „Filtry" s badge počtu; nativní selecty (5×) → `AppSelect`, date inputy (2×) → `DatePicker`, checkboxy → `Checkbox`. Deep-link s filtry mimo taby panel automaticky rozbalí. **Aktivní filtry jako odstranitelné chipy** (vč. odchylky roku od výchozího).
4. **Sticky měsíční pruh** --surface-muted (top-16 = pod topbarem) s názvem měsíce, počtem a součty/predikcí vpravo; tabulka `.ui-table` se sloupci dle zadání: checkbox (Checkbox s indeterminate), Var. symbol (odkaz primary/600, tabular-nums), Klient bold + zakázka 12 px muted, Typ (Badge), DUZP/Vystaveno (taxDateClass), Splatnost (červeně po splatnosti), K úhradě (.num), Stav (**StatusDot** s tooltipem, ✉/⚠ mini-indikátory zachovány, „Výkaz" button u konceptů zachován), **Akce** = hover ikony (Upravit u konceptů, PDF, Duplikovat — stejný flow jako na detailu vč. confirmů, ⋯ detail).
5. „Načíst další" zachováno (klasické stránkování tu nikdy nebylo), teď jako pilulkové tlačítko. Hromadný PDF export → sdílená `Modal` + `Checkbox` + pilulková tlačítka. Mobilní karty: Checkbox/StatusDot/displayStatus (sjednocena dřívější nekonzistence desktop vs mobil). i18n: + `invoice.tab_*` (cs+en).
6. `SearchableSelect.vue`: input restyle na pilulku (rounded-full, h-40px, SVG chevron) — projeví se i na 7 dalších místech, API beze změny.

**Proč:** Fáze 4 redesignu — vzorová seznamová stránka pro ostatní seznamy (F4+ vzor převezme purchase-invoices).

**Jak ověřit po merge:** build+testy ✓; /invoices: taby přepínají filtry (URL query stejné jako dřív), výběr → ikony toolbaru se aktivují, filtr panel + chipy, měsíční pruhy sticky, hover řádku ukáže akce; formulářová data filtrů identická (AppSelect/DatePicker drží stejný kontrakt). Pozor při upstream merge: template je celý forkový.

## 2026-07-27 — REDESIGN Fáze 3: sada reusable UI komponent (větev feature/redesign)

**Co se změnilo:**
1. **13 nových komponent ve `web/src/components/ui/`**: `Button` (primary/cta/secondary/ghost/danger × sm/md/lg, loading, polymorfní button/RouterLink/a, vždy pilulka), `IconButton` (kruh 40 px), `AppSelect` (náhrada nativního `<select>` — pilulkový trigger, listbox panel s ARIA + aria-activedescendant, klávesnice vč. type-ahead, hidden input form fallback přes `name`), `DatePicker` (náhrada `<input type=date>` — český zápis dd.MM.yyyy, kalendář Po–Ne, min/max, Dnes/Vymazat, stejný ISO kontrakt modelValue, hidden input fallback), `Checkbox`/`RadioInput`/`Switch` (sr-only nativní input = a11y+formuláře zadarmo; Switch iOS-like), `SegmentedControl`, `TabsNav` (podtržení 2 px, 15/600, count pilulky), `Badge` (8 barev soft), `StatusDot` (✓/✕/hodiny/—, text v tooltipu), `Card` (radius 20, bez borderu), `Pagination` (kruhová tlačítka + „Počet na stránku" AppSelect).
2. **Restyle stávajících**: `Modal.vue` (radius 20, kruhový křížek, nový volitelný slot `#footer` — API beze změny), `Toaster.vue` (karty s ikonami, sémantické barvy), `EmptyState.vue` (SVG ilustrace přes currentColor/tokeny, pilulkové CTA, sloty `#icon`/`#actions` — API 100% zpětně kompatibilní), `ActionBar.vue` (jen radius tlačítek → pilulky).
3. `redesign.css`: sekce `.ui-table` (hlavička --surface-muted 13/600, řádky 48 px, hover, `.row-actions` viditelné při hoveru řádku / vždy na dotyku, `.num` tabular-nums vpravo). i18n: + `common.per_page`, `common.page_first/prev/next/last` (cs+en).
4. Nativní prvky v 49 stránkách se budou vyměňovat postupně per stránka ve fázích 4–7 (u každé výměny ověřit, že formulář posílá stejná data — AppSelect/DatePicker drží stejný datový kontrakt i hidden-input fallback).

**Které soubory:** 13 nových `web/src/components/ui/*.vue`, upravené `Modal.vue`, `Toaster.vue`, `EmptyState.vue`, `ActionBar.vue`, `redesign.css`, `i18n/cs.json`+`en.json`.

**Proč:** Fáze 3 redesignu — jednotná pilulková komponentová sada místo ad-hoc utility tříd; základ pro přestavbu stránek (F4–F6).

**Jak ověřit po merge:** build + testy projdou; komponenty se dají ověřit dočasným mountem (vzor: viz commit — demo stránka se před commitem mazala); dark mode funguje čistě přepnutím tokenů (žádné dark: varianty v komponentách).

## 2026-07-27 — REDESIGN Fáze 2: layout shell — tmavá plocha + plovoucí panel (větev feature/redesign)

**Co se změnilo:**
1. **`AppLayout.vue` kompletně přestavěn** (logika beze změny — routing, guardy, handlery, `logout()`, `canLockSession` 1:1): tmavá plocha `--app-bg` pod celou appkou; sidebar 240 px sedí přímo na tmavém pozadí (světlý text, sekce oddělené linkou `white/10`, aktivní položka = plná `--primary` pilulka, hover 8 % bílá); nahoře zelené CTA „+ Vytvořit" (`--accent-cta`, pilulka 44 px, chevron → dropdown dosavadních quick akcí z topbaru); obsah = bílý plovoucí panel `bg-surface` + `radius-panel` + `space-panel` bez borderu/stínu; topbar zjednodušen na: pilulku „Pracuješ jako" s přepínačem firmy, ⋯ kruhové menu (vlajky CS/EN, motiv, nápověda, zámek relace), avatar s iniciálami + jméno, Odhlásit.
2. Satelity: `ThemeToggle.vue` + prop `on-dark`; `SupplierSwitcher.vue` trigger jako světlá pilulka; `GlobalSearch.vue` input tmavá pilulka; `AppShell.vue` (login/setup) na tmavé ploše; `NotFound.vue` + `bg-neutral-50`.
3. `redesign.css`: `body { background: var(--app-bg) }`. `index.html` + `manifest.webmanifest`: theme-color/background_color `#1E2050`.
4. **Testy zachovány beze změn souborů testů**: `layout-ui` i `auth-session-lifecycle` procházejí (mobilní patička sidebaru drží strukturu i komentář, přesně 2× `v-if="canLockSession"`, logout catch beze změny). i18n: + `nav.more`, `nav.language` (cs+en).

**Které soubory:** `web/src/components/layout/AppLayout.vue` (přestavba template), `AppShell.vue`, `ThemeToggle.vue`, `SupplierSwitcher.vue`, `GlobalSearch.vue`, `web/src/pages/NotFound.vue`, `web/src/styles/redesign.css`, `web/index.html`, `web/public/manifest.webmanifest`, `web/src/i18n/cs.json` + `en.json` (+2 klíče).

**Proč:** Fáze 2 redesignu — app shell ve stylu moderních fakturačních aplikací (plovoucí panel na tmavé ploše). Bez změny funkcionality.

**Jak ověřit po merge:** build + testy projdou; po přihlášení tmavé pozadí `#1E2050`, obsah v bílém zaobleném panelu, sidebar s aktivní indigo pilulkou a zeleným „+ Vytvořit" (rozbalí 8 akcí), topbar jen pilulka firmy + ⋯ menu + avatar + Odhlásit; na mobilu hamburger → off-canvas sidebar s patičkou (profil, motiv, vlajky, zámek/odhlásit). Pozor při upstream merge: template AppLayout je celý forkový — konflikty řešit ve prospěch redesignu a doplnit nové upstream nav položky ručně.

## 2026-07-27 — REDESIGN Fáze 1: design tokeny, self-host fonty, typografie (větev feature/redesign)

**Co se změnilo:**
1. **Nová forková CSS vrstva `web/src/styles/redesign.css`** — importovaná v `main.ts` jako poslední (vyhrává nad main.css, custom-theme.css i Tailwind utilities). Obsahuje: nové tokeny redesignu (`--app-bg #1E2050`, `--app-bg-dark #0B0F31`, `--surface-muted #F6F6FB`, `--radius-panel 28` / `--radius-card 20` / `--radius-control 999` / `--radius-input 12`, `--space-panel`, `--grid-gap`, `--sidebar-w 240`, `--control-h 40`, aliasy `--primary`/`--surface`/`--text*`), sytější sémantické barvy (success `#16A34A`, danger `#DC2626`, warning `#F59E0B` + zrcadlené `.dark` hodnoty) a typografii (h1 24/600, h2 20/600, nadpisy `--font-display` = Nunito).
2. **Fonty poprvé skutečně načtené**: Inter (tělo) + Nunito (nadpisy) self-host ve `web/src/assets/fonts/` (4× variabilní woff2, latin+latin-ext, OFL, ~200 KB) — Vite je hashuje do `dist/assets`, obslouží stávající nginx `/assets/` + SW cache-first, žádné externí requesty. Dřív bylo Inter jen deklarované a UI běželo na systémovém fontu.
3. **Plošné zrušení drobných UPPERCASE labelů** (407 výskytů): globální `.uppercase { text-transform:none; letter-spacing:normal }` + zvětšení 10/11px labelů na 12–13px. Markup se dočistí per stránka v dalších fázích.
4. `custom-theme.css`: zrušeno `h1.text-2xl{1.75rem}` (nahrazeno h1 24px v redesign.css). `.gitignore`: + `web/.pnpm-store/`.

**Které soubory:** `web/src/styles/redesign.css` (nový), `web/src/assets/fonts/*.woff2` (4 nové), `web/src/main.ts` (+1 import), `web/src/styles/custom-theme.css` (−1 pravidlo), `.gitignore`.

**Proč:** Fáze 1 kompletního redesignu (app shell ve stylu iDokladu, 8 fází na větvi `feature/redesign`) — tokeny a typografie jako základ pro shell (F2) a komponenty (F3). Bez změny funkcionality.

**Jak ověřit po merge:** `pnpm build` projde (vue-tsc + vite), `node --test tests/*.test.mjs` 50/50; v UI: nadpisy v Nunito (zaoblené), text Inter s diakritikou, hlavičky tabulek/labely bez VERZÁLEK, „Po splatnosti" červená `#DC2626`, zelená tlačítka `#16A34A`. Import pořadí v `main.ts`: main → custom-theme → redesign (poslední MUSÍ zůstat poslední).

## 2026-07-27 — UPDATE z upstreamu: v4.49.2 → v4.51.0

Merge 49 commitů (mj. passkeys + obecné MFA + zámek session — migrace 0145–0147, branding profily e-mailů — 0141–0144, přehled dávky AI importu, MONETA e-mailová avíza, paušální daň 2026, upstream PWA #231; migrace 0140–0147). Šest konfliktů:

1. `api/openapi.yaml` — upstream přešel z `nullable: true` na `type: [string, "null"]`; naše `internal_note` zachována a převedena na nový styl.
2. `api/src/Action/Admin/UserAdminAction.php` — náš `UserSupplierAccess` (FÁZE 2) + upstream `SessionManager` (revokace sessions při změně hesla/deaktivaci): sloučeny importy, constructor i obě větve `update()` (náš `if (!empty($sets))` guard zůstal — supplier_ids může přijít samo); FORK metoda `normalizeSupplierIds` + upstream docblock u `log()`.
3. `api/src/Action/Auth/MeAction.php` — sloučeny constructor závislosti: naše `UserSupplierAccess` + upstream passkey/MFA/lock (PasskeyCredentialRepository, MfaPolicyService, SessionLockPolicy, Clock).
4. `api/src/Middleware/SupplierScopeMiddleware.php` — upstream early-bypass pro `/api/auth/webauthn|mfa|session/` PŘED naším allowed-set enforcementem (obojí).
5. `api/src/Repository/InvoiceRepository.php` — `supportsInternalNote` (FÁZE 6) + upstream `branding_profile_id` resolve v `update()` — oba bloky vedle sebe.
6. `web/index.html` — **FÁZE 4 (vlastní PWA přes `styles/`) nahrazena upstream PWA #231**: převzat `manifest.webmanifest` + `/pwa/` ikony + service worker (nginx aliasy z upstreamu je servírují — důvod našeho workaroundu padl); zachována naše theme-color `#4F46E5`. Odstraněny `styles/manifest.json`, `styles/icon-*.png`, `styles/apple-touch-icon.png`, `tools/generatePwaIcons.php`; v `web/public/manifest.webmanifest` sladěna `theme_color` na `#4F46E5` (jediný in-place zásah do upstream souboru — při dalším merge ohlídat). Sekce 5.3.1 manuálu je generická, platí dál. iOS/Android instalace z plochy dle staré FÁZE 4 zůstávají funkční (manifest je při instalaci zakešovaný), nové instalace jedou přes upstream manifest.

Checklist FÁZE 1–7 prošel (supportsInternalNote ×3, pay-band, cash-documents v RoleMiddleware+Routes, UserSupplierAccess, custom-theme import, party-label, createCashDoc, i18n `cash` sekce cs+en, migrace 0900–0902). Fialové hexy v nových upstream souborech (BrandingProfilesSettings.vue) jsou e-mail branding pro klienty — dle FÁZE 3 záměrně nedotčeno. PHP soubory syntax-check OK. Migrace 0140–0147 + 0900–0902 aplikované, 0 pending; HTTP 200, VERSION 4.51.0, log čistý; `/manifest.webmanifest`, `/pwa/icon-192.png` i `/service-worker.js` vrací 200. Zálohy: `/root/backup-myinvoice-db-2026-07-27-1554.sql`, `/root/backup-myinvoice-data-2026-07-27-1554.tar.gz`.

## 2026-07-03 — ROZHODNUTÍ: update watcher (§ 19.4 manuálu) NEnasazovat

Watcher = jednoklikový upgrade z UI (Systém → Aktualizace → tlačítko), určený pro standardní GHCR instalace. Tato instalace jede fork s buildem ze zdrojáku — update vyžaduje řízený proces (záloha → merge tagu → checklist úprav → řešení konfliktů → rebuild → verifikace). Watcher by proces obešel a mohl přepsat customizovaný build čistým upstream image (ztráta všech FÁZÍ do rebuildu). Denní kontrola verzí (cron-version-check) běží a stačí — o nových verzích informuje badge ve footeru; upgrade se provádí vědomě přes Claude. Watcher nasadit JEN pokud by se instalace někdy vrátila na čisté GHCR image.

## 2026-07-03 — UPDATE z upstreamu: v4.44.0 → v4.49.2

Merge 99 commitů (mj. ceník položek, OSS základ — migrace 0137, iDoklad bank transakce, volitelný e-mail klienta; migrace 0126–0139). Dva konflikty: `InvoiceRepository.php` (naše supportsInternalNote vs. upstream supportsOssItemColumns na stejném místě — ponechány oba helpery) a `InvoiceDetail.vue` (import řádek — sloučeny typy + cashDocumentsApi). `invoice.twig` se zmergoval automaticky (FORK bloky drží). Checklist FÁZE 1–7 prošel; migrace 0126–0139 + naše 0900–0902 aplikované, log čistý.

## 2026-07-02 — UPDATE z upstreamu: v4.43.4 → v4.44.0

Merge 22 commitů (mj. e-mailové profily — migrace 0124, stavy transakcí — 0125, sjednocená stránka Banka se záložkami, Dodavatelé přesunuti do Číselníků). Jediný konflikt: `AppLayout.vue` — upstream přejmenoval nav položku Banka (sjednocená stránka) a náš řádek Pokladna byl hned pod ní; řešení = upstream položka + naše Pokladna pod ní. Checklist všech úprav (FÁZE 1–7) prošel; tokeny, grafy i invoice.css upstream neměnil. Migrace 0124–0125 + naše 0900–0902 aplikované.

## 2026-07-02 — FÁZE 7: redesign PDF faktury (inspirace iDoklad)

**Co se změnilo (POZOR — mění vzhled dokladů pro klienty):**
1. **Platební pás přes celou šířku nad položkami** (podpis iDokladu): účet + IBAN + VS + velká částka „K úhradě" + QR kód s bílou quiet-zone — platba nejde přehlédnout. Nahrazuje pay-panel, který byl dole vedle sumace. Všechny obsahové varianty (uhrazeno/karta/hotově/převod, částečné úhrady) zachovány 1:1.
2. **Lehčí typografický vzhled**: hlavička položek bez plného barevného bloku (akcentový text + silná linka), zrušené zebra pruhy, „Celkem" jako velké číslo s linkou místo plného pruhu, dodavatel bez podbarvení (tint jen odběratel), tenčí hlavičková linka, doc-type kapitálkami.
3. **PdfBranding::accentCss aktualizován** na nové selektory — per-supplier barvy (email_accent_color) dál fungují; UHRAZENO zůstává zelené i s brandingem (specificita).
4. **Dodatek (iterace s uživatelem):** monospace (JetBrains Mono) nahrazen Montserratem na VŠECH dokladech (faktura, pokladní doklad, platební příkaz, výkaz) — pryč „strojový" výraz s tečkovanou nulou; sekční popisky v tiché šedé (akcent jen brand + platební pás + součty); bloky Dodavatel/Odběratel kompaktní (`div.party-label` místo `h2` — mPDF na h2 lepí vlastní výchozí styl a class CSS ignoruje!).

**Které soubory:** `api/templates/invoice/invoice.twig` (pay-band blok + odstranění pay-panel — NEJVĚTŠÍ merge riziko, upstream šablonu často mění), `styles/invoice.css` (restyling, FORK bloky), `api/src/Service/Pdf/PdfBranding.php` (selektory). `work_report.twig` sdílí `.head`/`.items` → zdědí lehčí vzhled (záměr).

**Jak ověřit po merge:** PDF vystavené faktury — platební pás nad položkami s QR (sken funguje!), UHRAZENO zeleně, dobropis červeně, s per-supplier barvou vše obarvené, částečná úhrada ukazuje zbytek v pásu i sumaci. Konflikt v invoice.twig řešit: pay-band blok drží FORK komentáře.

## 2026-07-02 — FÁZE 6: interní poznámka + layout editoru + našeptávač pokladny

**Co se změnilo (3 nezávislé věci):**
1. **Interní poznámka u faktury** (à la Vyfakturuj) — nové pole viditelné jen v aplikaci, NIKDY na PDF/e-mailu klientovi. V editoru pod „Poznámkou pod položkami" (s nápovědou), v detailu žlutě zvýrazněný box „(netiskne se)". Neklonuje se do odvozených dokladů (proforma→faktura, kopie, recurring) — záměr, poznámka patří konkrétnímu dokladu.
2. **Layout editoru faktury** — „Poznámka pod položkami", „Interní poznámka" a „Sumace" jsou nyní boxy přes celou šířku (dřív grid 2/3+1/3); součty uvnitř Sumace drží vpravo v šířce sloupce (čitelnost dvojic popisek/částka). Dodatek: **Klasifikace přesunuta NAD „Poznámku nad položkami"** (hned pod hlavičku; nejdřív za Sumaci, finální pozice dle uživatele).
3. **Našeptávač protistrany v Pokladně** — nativní `<datalist>` plněný jmény klientů/dodavatelů (clientsApi, max 500, fail-safe).

**Které soubory:** `db/migrations/0902_invoice_internal_note.sql` (**nová**, ADD COLUMN IF NOT EXISTS); `api/src/Repository/InvoiceRepository.php` (supportsInternalNote — SHOW COLUMNS obrana dle upstream vzoru + INSERT/UPDATE větve); `api/src/Action/Invoice/UpdateInvoiceAction.php` (audit sloupec); `api/openapi.yaml` (+1 property v Invoice schématu — dle AGENTS.md pravidla o sync); `web/src/api/invoices.ts` (typy); `web/src/pages/invoices/InvoiceEditor.vue` (pole + layout); `web/src/pages/invoices/InvoiceDetail.vue` (žlutý box); `web/src/pages/cash/CashDocuments.vue` (datalist); i18n (`invoice.internal_note*`); `manual/10_Faktura_editor.md` (sekce 10.10 Poznámky, přečíslování 10.11/10.12).

**Jak ověřit po merge:** migrace 0902 aplikovaná; editor ukládá interní poznámku a po reloadu drží; PDF faktury ji NEOBSAHUJE (kritická kontrola!); detail ji ukazuje žlutě; pokladna našeptává jména klientů. Konfliktní místa: InvoiceRepository (INSERT/UPDATE bloky), InvoiceEditor (layout sekce sumace).

## 2026-07-02 — FÁZE 5: pokladní doklady (PPD/VPD)

**Co se změnilo:** nová sekce **Finance → Pokladna** — příjmové a výdajové pokladní doklady pro hotovost. Číselné řady `PPD/VPD-rok-pořadí` per dodavatel, PDF s částkou slovy (A5 na šířku), u hotovostní faktury checkbox „Vystavit příjmový pokladní doklad" v dialogu Označit jako zaplacenou. Mazat jde jen poslední doklad řady. Koncept v1: doklad o pohybu hotovosti — **žádná vlastní DPH evidence** (daňovým dokladem zůstává faktura; VatLedgerService nedotčen — záměr, viz návrh schválený uživatelem).

**Které soubory (nové):** `db/migrations/0901_cash_documents.sql`, `api/src/Action/CashDocument/CashDocumentAction.php`, `api/src/Service/Pdf/CashDocumentPdfRenderer.php`, `api/src/Service/Text/AmountInWordsCz.php`, `api/templates/cash-document/cash-document.twig`, `api/tests/Integration/CashDocument/CashDocumentTest.php`, `web/src/api/cashDocuments.ts`, `web/src/pages/cash/CashDocuments.vue`.

**Které soubory (malé edity):** `api/src/Routes.php` (+6 rout), `api/src/Middleware/RoleMiddleware.php` (+2 pravidla cash-documents), `web/src/router/index.ts` (+1 routa), `web/src/components/layout/AppLayout.vue` (+1 nav položka Finance), `web/src/pages/invoices/InvoiceDetail.vue` (checkbox + vystavení dokladu po mark-paid), `web/src/i18n/cs.json` + `en.json` (sekce `cash` + 3 klíče `invoice.*`, přidáno chirurgicky — POZOR: nikdy nepřeformátovat celý JSON, rozbije to merge), `manual/24_Banka.md` (sekce 24.7).

**Jak ověřit po merge:** migrace 0901 aplikovaná; Finance → Pokladna vystaví PPD-…-0001 a PDF; hotovostní faktura po Označit jako zaplacenou vystaví PPD s vazbou (VS v účelu); smazání neposledního dokladu vrací 409; `php vendor/bin/phpunit --filter CashDocumentTest` v dev prostředí. Konfliktní místa: RoleMiddleware (pravidla), Routes, InvoiceDetail (mark-paid blok), i18n.

## 2026-07-02 — FÁZE 4: PWA (instalovatelná aplikace)

**Co se změnilo:** aplikaci lze přidat na plochu mobilu (Android i iOS) jako samostatnou appku — web manifest + sada ikon. Bez service workeru (žádná offline cache, žádné riziko zaseknutých verzí); případné push notifikace by byly samostatná budoucí funkce.

**Které soubory:**
- `styles/manifest.json` — **nový** web manifest (start_url/scope `/`, standalone, theme #4F46E5). Leží ve `styles/`, protože nginx servíruje z `/` jen reálné soubory od kořene repa (web/public → dist root se neservíruje, jen `/assets/`).
- `styles/icon-{192,512}.png`, `styles/icon-maskable-512.png`, `styles/apple-touch-icon.png` — **nové** ikony, generované z tvarů `styles/logo.svg`.
- `tools/generatePwaIcons.php` — **nový** GD generátor ikon (logo = jednoduché tvary, kreslí se 1:1 se 4× supersamplingem; při změně loga přegenerovat: `php tools/generatePwaIcons.php <size> <plain|apple|maskable> > styles/….png`).
- `web/index.html` — +2 řádky (manifest, apple-touch-icon) a theme-color #3B2D83 → #4F46E5 (sladění s FÁZÍ 3).
- `manual/05_Po_instalaci.md` — sekce 5.3.1 s postupem přidání na plochu.

**Jak ověřit po merge:** `/styles/manifest.json` vrací 200 a JSON; Chrome DevTools → Application → Manifest bez chyb; na mobilu jde přidat na plochu a otevře se standalone s ikonou.

## 2026-07-02 — UPDATE z upstreamu: v4.41.0 → v4.43.4

Merge `v4.43.4` do větve `custom` proběhl **bez konfliktů** (69 souborů, mj. odesílací e-mailové profily + S/MIME, záložka Stavy na účtech, dělené úhrady UI, migrace 0120–0123 — aplikované při startu). Checklist úprav prošel kompletně: FÁZE 1 (pozice poznámky), FÁZE 2 (migrace 0900 + enforcement + data v `user_supplier_access`), FÁZE 3 (theme, grafy, import) — vše drží. Upstream nezměnil design tokeny ani nepřidal fialové hexy do grafů.

Navíc: `fix(dashboard)` — gradient hlavičky widgetu „Akce pro tebe" používal `to-white` (zářil v dark modu) → `to-surface`. Je to bug i v upstreamu — kandidát na PR autorovi.

## 2026-07-02 — FÁZE 3: modernizace UI (indigo/slate theme)

**Co se změnilo:** vizuální refresh aplikace — primární barva z tlumené fialové (#5C45A0) na moderní indigo (#4F46E5), neutrály z nafialovělé šedé na chladný slate, měkčí rádiusy (6–16 px), vrstvené stíny karet, sladěné barvy grafů. Light i dark mode.

**Které soubory:**
- `web/src/styles/custom-theme.css` — **nový** soubor s přepisem design tokenů (`:root` + `.dark`) a stíny. Jádro celé změny.
- `web/src/main.ts` — +1 řádek importu (jediný zásah do upstream souboru kvůli theme).
- `web/src/composables/useTheme.ts` — chart.js paleta (nečte CSS proměnné, hodnoty se zrcadlí ručně — viz komentář v souboru).
- `web/src/components/charts/{StatusDoughnut,PurchaseStatus,VatBreakdown,InvoiceSize,PaymentDaysHistogram}Chart.vue` — lokální hex barvy přemapované na indigo/slate ekvivalenty (mechanická náhrada, sémantické barvy success/warning/danger nedotčené).

**Co se záměrně NEmění:**
- PDF faktur a e-mail branding (server-side / per-supplier nastavení `email_accent_color`) — doklady pro klienty vypadají stejně.
- Fallback akcent veřejné work-report stránky a error banner (`WorkReportTrackingPublic.vue`, `api/client.ts`, `Settings.vue`) — patří k brand identitě dokladů.
- `manual/manual.css` — manuál si nechává upstream vzhled (AGENTS.md sice doporučuje sync tokenů, ale editace manual.css by přidala merge konflikty; vědomé rozhodnutí).

**Proč:** požadavek uživatele na modernější vzhled; přístup „vrstva vlastních tokenů" zvolen pro minimální konfliktní plochu s upstreamem (viz jednořádkový import + nový soubor).

**Jak ověřit po merge:**
1. Aplikace má indigo akcenty a slate neutrály (light i dark), kulatější karty/tlačítka.
2. Grafy (dashboard, tržby, DPH) používají indigo tóny — pokud upstream přidá nový graf s fialovými hex hodnotami, přemapovat podle tabulky v `custom-theme.css` hlavičce.
3. Po upstream merge zkontrolovat: `main.ts` (import řádek přežil), `useTheme.ts` (konflikt palety řešit ve prospěch indigo verze) a nové tokeny v upstream `main.css` (případně doplnit jejich override).

## 2026-07-02 — FÁZE 2: omezení uživatele na vybrané dodavatele

**Co se změnilo:** admin může uživateli (role `accountant`/`readonly`) přiřadit povolené dodavatele. Omezený uživatel vidí v přepínači firem jen povolené a k jiným se nedostane ani přímým API voláním (403). Žádný záznam = vidí vše (zpětná kompatibilita). Role `admin` vidí vždy vše.

**Které soubory:**
- `db/migrations/0900_user_supplier_access.sql` — **nová** tabulka `user_supplier_access` (user_id, supplier_id, FK cascade). Číslováno od **0900**, aby nekolidovalo s upstream migracemi (0120+); migrate.php řadí řetězcově, poběží vždy poslední.
- `api/src/Service/Auth/UserSupplierAccess.php` — **nový** service (allowedIds / replaceForUser / idsByUser).
- `api/src/Middleware/SupplierScopeMiddleware.php` — enforcement: header/query mimo povolený set → 403 (`supplier_forbidden`); chybějící header → fallback MIN(povolených); na cestách kde se scope ignoruje (`/api/auth/*`, `/api/codebooks*`, …) tichá korekce místo 403 (jinak by se FE zamknul na /auth/me); API token vázaný na nepovoleného supplier-a → 403.
- `api/src/Action/Auth/MeAction.php` — switcher dostává jen povolené dodavatele.
- `api/src/Action/Settings/SettingsAction.php` — `GET /api/suppliers` filtruje, `GET /api/suppliers/{id}` mimo set vrací 404.
- `api/src/Action/Admin/UserAdminAction.php` — `supplier_ids` v list/create/update/fetchUser + validace.
- `api/tests/Integration/Auth/UserSupplierAccessTest.php` — **nový** integrační test (2 useři, klon dodavatele, 403/fallback/filtr/admin bypass; soft-skip bez DB).
- FE: `web/src/api/admin.ts`, `web/src/pages/admin/Users.vue` (checkboxy + sloupec), `web/src/i18n/cs.json` + `en.json` (klíče `users.suppliers_*`).
- `manual/36_Nastaveni.md` — nová sekce 36.2.3 (HTML/PDF manuálu regeneruje Docker build).

**Proč:** požadavek uživatele — externí účetní/klient má vidět jen svoji firmu.

**Poznámka k openapi.yaml:** schéma odpovědí se nemění (jen se filtrují řádky dle oprávnění), admin mutace se dle AGENTS.md nedokumentují → openapi.yaml záměrně beze změny.

**Jak ověřit po merge:**
1. `php api/bin/migrate.php --status` — `0900_user_supplier_access` aplikovaná.
2. Admin → Systém → Uživatelé: u non-admin uživatele vybrat dodavatele, uložit, znovu otevřít — výběr drží.
3. Přihlásit se jako omezený uživatel: přepínač firem nabízí jen povolené; `curl -H "X-Supplier-Id: <nepovolené>" /api/invoices` → 403; bez headeru → data povoleného dodavatele.
4. `php vendor/bin/phpunit --filter UserSupplierAccessTest` (dev prostředí s DB).
5. Po upstream merge zkontrolovat: SupplierScopeMiddleware (nejrizikovější — upstream ho může měnit), MeAction select, UserAdminAction.

## 2026-07-02 — FÁZE 1: pole „Poznámka nad položkami" nad sekcí položek

**Co se změnilo:** v editoru faktury je pole „Poznámka nad položkami" přesunuto ze spodního bloku „Sumace + poznámky" nahoru — jako samostatný box těsně **nad** sekci Položky. Editor tak odpovídá pořadí na tiskovém PDF. „Poznámka pod položkami" zůstává dole.

**Které soubory:** `web/src/pages/invoices/InvoiceEditor.vue` (jen přesun bloku v šabloně, +6/−4 řádků, žádná změna logiky ani API). Commit `feat(invoice-editor): přesuň pole „Poznámka nad položkami" nad sekci položek`.

**Proč:** požadavek uživatele — pole se týká obsahu nad položkami, ale zadávalo se až pod nimi.

**Jak ověřit po merge:** otevřít editor faktury (nová i editace stávající) → box „Poznámka nad položkami" je nad sekcí Položky, text se ukládá a tiskne na PDF nad tabulkou položek. Pozor při upstream merge: upstream verzi tohoto bloku v „Sumace + poznámky" znovu nepřidat (konflikt řešit ve prospěch přesunuté pozice).

## 2026-07-02 — FÁZE 0: přepnutí provozu na build ze zdrojáku

**Co se změnilo:**
- Provoz přepnut z GHCR image (`ghcr.io/radekhulan/myinvoice:latest`, `docker-compose.production.yml`) na lokální build ze zdrojáku (`docker-compose.yml`, `Dockerfile.alpine`, image `myinvoice:latest`).
- Aktivní compose je od teď `docker-compose.yml`. Port drží `.env` (`APP_PORT=127.0.0.1:8090`) — **neměnit**, visí na tom reverzní proxy.
- Git: pracovní větev `custom`, založená z tagu `v4.41.0` (= verze, která běžela z GHCR; DB migrace 0001–0119 aplikované). Remotes: `origin` = `git@github.com:beevee85/myinvoice.git` (fork), `upstream` = autorův repozitář.
- Volumes beze změny: `myinvoice_app-data`, `myinvoice_db-data`. Konfigurace beze změny: `cfg.docker.php` (mount) + `cfg.local.php` (v `/data`).

**Které soubory:** žádná změna kódu aplikace. Commit `chore(cmd): nastav spustitelný bit na .sh skriptech (provoz na VPS)` — jen exec bity na `cmd/*.sh` (mode 100644 → 100755, 9 souborů).

**Proč:** příprava na vlastní úpravy kódu (CESTA B dle CLAUDE.md) — fork + build ze zdrojáku umožňuje vlastní změny a zároveň braní updatů od autora.

**Jak ověřit po merge/rebuildu:**
1. `docker compose -f docker-compose.yml build && docker compose -f docker-compose.yml up -d`
2. `docker ps` — app běží z image `myinvoice:latest`, port `127.0.0.1:8090->80`
3. `curl -I http://127.0.0.1:8090` → HTTP 200
4. `docker compose -f docker-compose.yml exec app cat /var/www/html/VERSION` odpovídá očekávané verzi
5. `docker compose -f docker-compose.yml exec app php api/bin/migrate.php --status` — žádná nevyřízená migrace
6. `cmd/*.sh` mají exec bit (`ls -l cmd/`)

**Zálohy před změnou:** `/root/backup-myinvoice-db-2026-07-02-0122.sql`, `/root/backup-myinvoice-data-2026-07-02-0123.tar.gz`.

**Rollback:** `docker compose -f docker-compose.production.yml up -d` (poslední GHCR image).
