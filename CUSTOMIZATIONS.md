# CUSTOMIZATIONS.md — evidence vlastních úprav této instalace

Instalace: `faktury.betka.eu`, VPS, `/opt/myinvoice`. Pravidla práce viz `CLAUDE.md` (gitignored, jen na serveru).

Po každém updatu z upstreamu projdi celý seznam níže a ověř, že žádná úprava tiše nevypadla.

---

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
