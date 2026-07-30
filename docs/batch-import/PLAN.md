# Dávkový import přijatých dokladů přes předplatné (Claude Code) — Commit 0: recon

Verze repa při průzkumu: **v4.52.1**, větev `custom` (commit `ed2c08e9`), nová větev
`feat/batch-import-subscription`. **Žádný kód se v tomto commitu nepíše.**

Tento dokument je závazný podklad pro Commity 1–9. Kde se zadání rozchází s realitou repa,
je to výslovně označeno jako **ODCHYLKA** a doplněno návrhem řešení (sekce 9).

---

> ## STAV K 30. 7. 2026 — čtěte první
>
> Původní text níže je **recon z Commitu 0** a nechává se čitelný jako to, co platilo tehdy.
> Tato hlavička je **aditivní korekce**, ne přepis. Kde si původní text a tato hlavička
> odporují, **platí hlavička**.
>
> | co v původním textu | stav k 30. 7. 2026 |
> |---|---|
> | „závazný podklad pro Commity **1–9**" (úvod) | plán běží do **Commitu 16** |
> | větev `custom` na commitu `ed2c08e9` (úvod) | merge-base je **`313b5776`** |
> | „~1 950 testů, ~34 skipped" (§5.1) | ověřuje se; z 31 skipů je 26 závislých na cizí službě `dev.myinvoice.cz` |
> | §12: „čeká se na rozhodnutí O-2, O-6, O-8, O-9, O-10, O-11, O-13 … **nepokračuji na Commit 1**" | **Commit 1 je hotový**; rozhodnutí padla jako **A1–A7** |
> | O-6 (§9): „**čekám na tvoje rozhodnutí; sám závislost nepřidávám**" | rozhodnuto (A2): lokální PHP dekodér QR, ale jako **volitelná** závislost — když v systému není, kód ho neimportuje a stav ověření je `unavailable`. Featura musí být plně funkční bez ní. Nová běhová závislost se nepřidává bez výslovného souhlasu vlastníka a předkládá se s licencí, verzí, počtem tranzitivních závislostí a datem posledního release. |
> | **O-3** (§9) — „nový sloupec nepřidávat, naplnit stávající `import_batch_id`" | **ODVOLÁNO.** Platí **A3** a **sekce 15**. Podrobně u O-3. |
> | „Nové tabulky dostanou čísla `0912+`" (§3 a §11) | `0912` je **obsazené**; platí rezervované pásmo **0913–0919** |
> | katalog „V1–V74" (§8, §8.5) | doplněno o **V43b–V43e** (§13) a **V75–V86** (§8.5) |
>
> **Parita mezi vstupními cestami neexistuje** a v tomto dokumentu se o ní nikde netvrdí, že
> je splněná. Aktuální stav konvergence je **3 ze 7 cest** (rohatka `NOT_YET_CONVERGED`).
> Které pravidlo je na které cestě vynucené, se nečte z prózy, ale z **matice pravidlo × cesta
> generované z kódu** — próza o cílech zestárne, matice ne.

---

## 1. Stack a runtime

| vrstva | co to je |
|---|---|
| Backend | PHP **8.5**, Slim 4 (`slim/slim`), PHP-DI 7 (autowiring), Monolog, Guzzle |
| DB | MariaDB 11 (min. 10.6), PDO, žádné ORM — ruční repozitáře |
| PDF | `mpdf/mpdf` (výstup), `smalot/pdfparser` (čtení textové vrstvy) |
| QR | `chillerlan/php-qrcode` + `rikudou/czqrpayment` — **jen generátor, ne dekodér** |
| IBAN | `rikudou/iban` + vlastní mod-97 v `BankAccountParser` |
| XML | ext-dom + XSD validace (`api/xsd/isdoc-invoice-6.0.2.xsd`) |
| Frontend | Vue 3.5 + TS, Vite, Tailwind 4 (CSS-first), Pinia, vue-i18n |
| Nasazení | Docker (`Dockerfile.alpine`), nginx + php-fpm, cron uvnitř kontejneru |

**Kritické zjištění k prostředí:** produkční image `myinvoice:latest` **neobsahuje**
`pdftotext`, `pdftoppm`, `pdfinfo`, `zbarimg`, `gs` ani `convert`. Veškerá práce s PDF
na serveru je čistě v PHP (`smalot/pdfparser` pro text, vlastní `PdfImageExtractor`
pro obrázkové XObjecty). Hostitelský server (kde běží Claude Code) poppler **má**
(`/usr/bin/pdftotext`, `pdftoppm`, `pdfinfo`), ale `zbarimg` ani `pyzbar`/`cv2` ne.

Důsledky jsou rozepsané u pravidel V3, V8, V33–V35 v sekci 7 a v odchylkách O-5, O-6.

---

## 2. Router, autentizace, middleware

### 2.1 Router
Všechny cesty se registrují v jednom souboru `api/src/Routes.php` (817 řádků, statická
metoda `Routes::register(App $app)`). Akce jsou invokovatelné třídy v `api/src/Action/**`,
autowirované PHP-DI. Žádné atributy/anotace, žádný route cache.

Veřejné API je **totéž** co interní — `ApiVersionRewriteMiddleware` přepíše `/api/v1/...`
na `/api/...` ještě před routerem a přidá hlavičku `X-API-Version: 1`.

### 2.2 Middleware stack (`api/src/Bootstrap.php:186-199`)
Slim 4 je LIFO, takže reálné pořadí zvenku dovnitř je:

1. `ApiVersionRewriteMiddleware` — `/api/v1/*` → `/api/*`
2. `IpAllowlistMiddleware`
3. `FirstRunLockMiddleware` — 423 dokud není žádný uživatel
4. `AuthMiddleware` — **session NEBO Bearer PAT**; atributy `auth.user`, `auth.method`
5. `SessionLockMiddleware`
6. `RequireMfaMiddleware` (bearer skip)
7. `RoleMiddleware` — RBAC podle `METHOD + path` regexů
8. `SupplierScopeMiddleware` — `X-Supplier-Id` → atribut `supplier.current_id`
9. `ApiScopeMiddleware` — **jen pro bearer**: path allowlist + `read`/`read_write` scope
10. `RateLimitMiddleware`
11. `CsrfMiddleware` (bearer skip)
12. `WebAuthnBodyLimitMiddleware`
13. routing + body parsing

**Pro dávkový import to znamená:** jednorázový „batch token“ ze zadání (V63) musí projít
**čtyřmi** vrstvami, které o něm nic nevědí (Auth, Role, SupplierScope, ApiScope). Návrh
řešení viz O-2.

### 2.3 Autentizace
* Session (browser SPA) — cookie + CSRF token, plná práva role.
* Bearer PAT — `api_tokens`, volitelně přišpendlený na `supplier_id`; `ApiScopeMiddleware`
  drží allowlist cest (`api/src/Middleware/ApiScopeMiddleware.php:46`) a scope. **Cesta
  `/api/purchase-invoices(/|$)` v allowlistu JE**, `/api/import-batches` by v něm nebyla.

---

## 3. Migrace a jejich verzování

* Adresář `db/migrations/`, prostý runner `api/bin/migrate.php`.
* Řadí se **abecedně podle názvu souboru**, evidence v tabulce `migrations (filename, applied_at, duration_ms)`.
* **Down migrace neexistují.** Runner umí jen `--status` a `--no-backfills`. Rollback = obnova z dumpu.
* Každá migrace **musí být idempotentní** nativními `IF [NOT] EXISTS` (pravidlo z `AGENTS.md`).
* Upstream je na `0148_user_suppliers.sql`; **fork-only migrace se číslují od `0900`**
  (aktuálně 0900–0911, poslední `0911_extraction_blocking.sql`).
* Migrace se pouští automaticky při startu kontejneru; ručně
  `docker compose exec app php api/bin/migrate.php`.

→ ~~**Nové tabulky dostanou čísla `0912+`.**~~ **OPRAVENO 30. 7. 2026: `0912` je obsazené**
(`0912_default_expense_categories.sql`, vzala si ho souběžná session). Pro dávkový import je
rezervované pásmo **`0913–0919`** (dohoda v `CUSTOMIZATIONS.md`, commit `7b9bd581`).
**Číslo se přiděluje až v okamžiku commitu**, ne dopředu, a vždy s ověřením, že je pořád volné.
Požadavek „migrace up/down idempotentně”
z Commitu 2 je splnitelný jen v části „up idempotentně“ (viz O-4).

---

## 4. Background / dlouhé úlohy

Mechanismus **existuje a je jediný**:

* Tabulka `import_jobs` (status, progress, log, cancel_requested) + repozitář `ImportJobRepository`.
* Worker `api/bin/import-worker.php --job-id=N`, dispatch podle `source`:
  `idoklad | fakturoid | monthly_export | document_zip_import | document_zip_export | document_folder_import`.
* Spouštění: `MyInvoice\Service\BackgroundProcess::spawnPhp()` — POSIX `nohup … &`,
  Windows `start /B` přes `popen`. Fire-and-forget, PHP CLI se hledá přes `PhpCliLocator`.
* Frontend polluje `GET /api/admin/imports/{id}`.
* Velké uploady dokumentů jdou přes chunkovaný upload
  (`/api/documents/upload/start|chunk-bytes|chunk-files|finish`) + job.

**Naopak AI extrakce PDF je dnes synchronní** — `POST /api/admin/imports/ai-extract-pdf`
zpracuje jedno PDF v requestu (10–30 s), dávku řeší **frontend** sériovým voláním
v cyklu (`web/src/pages/admin/Integrations.vue:391 runAiBatch`).

→ Pro dávkový import: rozbalení ZIPu, hashování, detekce stran a QR patří do jobu
(`source = 'purchase_batch_import'`), samotný `apply` je krátký a může být synchronní.

---

## 5. Testy

### 5.1 Runner
`api/phpunit.xml`, PHPUnit 13, tři suity: **Unit**, **Integration**, **Architecture**.
Bootstrap `api/tests/bootstrap.php` zapíná `DG\BypassFinals` (celý kód je `final`).

Spuštění v tomto prostředí (host PHP 8.4 nestačí, image má 8.5):

```bash
docker run --rm --network host -v /opt/myinvoice:/work -w /work/api myinvoice:latest vendor/bin/phpunit
```

Vyžaduje `cfg.php` v kořeni repa mířící na CI databázi `myinvoice_ci` (port 3307) a v něm
i sekci `varsymbol.templates`, jinak padá 6 testů `RecurringGeneratorTest`. Testy zapisují
log `api-YYYY-MM-DD` do kořene repa — po běhu smazat. Stav k dnešku: **253 testovacích
souborů**, suita zelená (~1 950 testů, ~34 skipped bez Redis/AI klíčů).

### 5.2 Konvence fixtures / factories
**Žádný factory framework ani fixture adresář neexistuje.**
* Unit testy: čistě statická data v testu (`PurchaseInvoiceValidationTest::validBase()`).
* Integration testy: bootují **reálný DI kontejner proti reálné DB**, na začátku
  `markTestSkipped`, pokud `cfg.php` chybí, data si vytvoří ručně v `setUp()` a uklidí
  v `tearDown()` (vzor `tests/Integration/PurchaseInvoice/PurchaseImportBatchAndKindTest.php`).
* HTTP úroveň se testuje jen u middlewarů a několika akcí přes ručně sestavené
  `ServerRequest` (`tests/Unit/Middleware/RoleMiddlewareTest.php`) — **plnohodnotný
  e2e přes `$app->handle()` v repu není**.

### 5.3 Frontend testy
`web/tests/*.test.mjs`, `node --test` (`pnpm test:pwa`). Jsou to **regex testy nad zdrojáky**,
ne komponentové testy — žádný Vitest, žádné Playwright/Cypress. Gate pro build je
`vue-tsc --noEmit` (`pnpm build` = type-check + vite build).

→ Požadované „E2E“ testy z Commitu 8 budou v praxi **Integration testy proti DB + testy
služeb**, ne prohlížečové e2e (viz O-7).

---

## 6. Role, tenant scoping, i18n, navigace

### 6.1 Role
Hierarchie `admin > accountant > readonly`, vynucuje `RoleMiddleware` regexy nad
`METHOD + path`; fallback bez shody je **admin-only**.

* `'* #^/api/purchase-invoices(/|$)#'` → **accountant stačí** na celý purchase modul.
* `scan-inbox` navíc kontroluje roli **v akci**: `ScanInboxAction:34` — `admin|accountant`.
* `ai-extract-pdf` totéž: `AiExtractPdfAction:38` — `admin|accountant`, ale cesta
  `/api/admin/*` sama padá do admin-only fallbacku RoleMiddleware → **reálně admin-only**.

### 6.2 Tenant scoping
`SupplierScopeMiddleware` → `$request->getAttribute('supplier.current_id')`, v akcích přes
`SupplierGuard::currentId($request)` a `SupplierGuard::owns($request, $row)`. Repozitáře
berou `supplier_id` jako povinný parametr **každé** metody (`find($id, $supplierId)` atd.).
Od 4.52.0 navíc `user_suppliers` + `SupplierAccessResolver` (403 `forbidden_supplier`).

### 6.3 i18n
* Backend: `MyInvoice\I18n\ErrorCatalog` — mapa **literální CZ text → EN**, aplikuje se
  v `Json::error()`. Nová hláška bez záznamu se vrátí česky i v EN režimu.
* Frontend: vue-i18n, `web/src/i18n/cs.json` + `en.json` — **vždy obě**.

### 6.4 Registrace routy a položky navigace
* Backend route: řádek v `api/src/Routes.php` + (u veřejného API) záznam v `api/openapi.yaml`
  (8 044 řádků; purchase modul tam je).
* Frontend routa: `web/src/router/index.ts`.
* Menu: `web/src/components/layout/AppLayout.vue:176-183` — sekce „Nákup“. Dnes obsahuje
  Přijaté faktury, Platební příkazy, Export a **jen pro admina** `/admin/import?tab=purchase`
  a `/admin/integrations?tab=ai`.
* Manuál: `manual/NN_Nazev.md` + `php tools/generateManualHtml.php` (generated/ není v gitu).

---

## 7. Kde přesně je co (mapa dotčeného kódu)

| co | kde |
|---|---|
| **BYOK AI extract endpoint** | `POST /api/admin/imports/ai-extract-pdf` → `Action/Admin/Import/AiExtractPdfAction.php` (`Routes.php:527`). **Ne** `/api/integrations/anthropic/extract` (O-1) |
| AI pipeline | `Service/Import/AiPdfExtractor.php:61 extractAndCreate()`, `:368 validateAiData()`, `:396 createDraft()` (423 řádků) |
| Anthropic klient + prompt + JSON schema | `Service/Import/AnthropicClient.php:132 extractInvoice()` (system prompt + schema inline, ř. 155–430) |
| Anthropic credentials | `Action/Admin/Import/AnthropicCredentialsAction.php`, `Routes.php:524-526` |
| **ISDOC parser** | `Service/Import/IsdocParser.php` |
| ISDOC → přijatá faktura | `Service/Import/IsdocToPurchaseInvoiceMapper.php:42 map()` |
| ISDOC v PDF/A-3 | `Service/Import/PdfIsdocExtractor.php` |
| ISDOCX (ZIP balíček) | `Service/Import/IsdocxExtractor.php` |
| **Pohoda dataPack** | `Service/Import/PohodaXmlParser.php` |
| Bundle importér (ZIP/XML admin) | `Service/Import/InvoiceImportService.php:68 importBundle()`, akce `Action/Admin/ImportAction.php` |
| **scan-inbox** | `POST /api/purchase-invoices/scan-inbox` → `Action/PurchaseInvoice/ScanInboxAction.php`, služba `Service/Import/PurchaseInvoiceInboxScanner.php` |
| Ruční založení | `POST /api/purchase-invoices` → `Action/PurchaseInvoice/CreatePurchaseInvoiceAction.php` |
| **Sdílená validace** | `Service/Validation/PurchaseInvoiceValidation.php:47 invoice()`, `:170 warnings()`; per-položka `Service/Validation/InvoiceAmountPolicy.php:157 validateItem()` |
| Zápis + přepočet | `Repository/PurchaseInvoiceRepository.php:938 createDraft()`, `:1334 replaceItems()`, `Service/Invoice/PurchaseInvoiceCalculator.php:31 recompute()`, `Service/Invoice/InvoiceMath.php` |
| **Dedup přes pdf_hash** | `PurchaseInvoiceRepository.php:2390 findIdByPdfHash()`; druhý dedup `:2406 findIdByVendorInvoice()`; DB `uq_pi_vendor_invoice (supplier_id, vendor_id, vendor_invoice_number, issue_date)` |
| Archivace PDF | `Service/Import/PurchaseInvoicePdfArchiver.php`, `PurchaseInvoiceRepository:2422 setPdfMetadata()` |
| **Dodavatel z ARES** | `Service/Import/ClientResolver.php:50 resolveVendor()` (match **podle IČO**, pak DIČ), `Service/Ares/AresClient.php:32`, plátcovství `Service/Ares/VendorVatPayerResolver.php:39`, `Service/Ares/CrpDphClient.php:43`, `Service/Ares/ViesClient.php:41` |
| **link-advance / advance-candidates** | `Routes.php:412-416`, `PurchaseInvoiceRepository:1566 linkAdvance()`, `:1616 suggestAdvanceLink()`, `:1642 advanceCandidates()` |
| **link-settlement-doc (§ 37a)** | `Routes.php:418-421`, `Service/Invoice/PurchaseSettlementService.php` |
| **payment-qr / extract-account** | `Routes.php:433-435` → `Action/PurchaseInvoice/PaymentQrAction.php:80 extractAccount()`; generátor `Service/Qr/QrPaymentGenerator.php`; heuristika obrázku QR `Service/Pdf/PdfImageExtractor.php:44 findQrLikeImage()` |
| **payment-orders/verify-account (§ 109)** | `Routes.php:440` → `Service/Payment/PaymentOrderService.php:406 verifyInvoiceAccount()` |
| **codebooks/cnb-rate** | `Routes.php:265` → `Action/Codebook/CnbRateAction.php`, klient `Service/Currency/CnbExchangeRateClient.php` |
| ISDOC exporter | `Service/Export/IsdocExporter.php` (`buildXml()` — použitelný i k sestavení vstupu pro importér) |
| Uploader Dokumentů | `Action/Document/UploadDocumentAction.php`, `DocumentJobsAction.php` (chunked), `Service/Document/DocumentStorage.php`, `DocumentIngestService.php`, `ZipImporter.php` |
| DPH evidence a výkazy | `Service/Report/VatLedgerService.php`; náhledy `GET /api/reports/dphdp3/preview`, `/api/reports/dphkh1/preview`; podání `GET /api/reports/submissions` |
| Audit log | `Service/ActivityLogger.php` |
| Koš (fork) | `Http/TrashGuard.php`, `Service/Invoice/DocumentTrashPolicy.php`, migrace 0905 |

### 7.1 Existující „import batch“ — **kolize názvů**

Upstream **už má** koncept dávky: migrace `0141_purchase_invoice_import_batch.sql` přidává
`purchase_invoices.import_batch_id VARCHAR(32)` — náhodný identifikátor generovaný
**frontendem**, endpoint `GET /api/purchase-invoices/import-batches`
(`PurchaseInvoiceImportBatchesAction`), filtr v seznamu a `PurchaseInvoiceRepository:2355
recentImportBatches()`. Slouží k dohledání „co se naimportovalo“ po hromadném AI importu (#232).

→ Navrhované tabulky `import_batches` / `import_batch_files` / `import_batch_results`
by kolidovaly pojmenováním s tímto konceptem i s tabulkou `import_jobs`. Viz O-3.

---

## 8. Soupis stávajících validací (požadavek zadání § 2.1) a mapování na katalog V1–V74

Legenda sloupce „znovupoužitelné“: **ANO** = lze zavolat přímo, **ČÁST** = existuje, ale je
zadrátované uvnitř importéru/akce, **NE** = neexistuje, musíme napsat.

### 8.1 Ruční cesta — `POST /api/purchase-invoices` (+ `PUT /{id}`)

| # | pravidlo | implementace | chybový kód | znovupoužitelné | → katalog |
|---|---|---|---|---|---|
| R1 | `vendor_id` povinné, > 0 | `PurchaseInvoiceValidation:51` | `validation_failed` / `fields.vendor_id` | ANO | — |
| R2 | `vendor_invoice_number` neprázdné, ≤ 50 znaků, bez řídících znaků | `PurchaseInvoiceValidation:56-65` | `fields.vendor_invoice_number` | ANO | **V26** |
| R3 | `document_kind` ∈ `invoice, receipt, credit_note, advance, tax_document` | `PurchaseInvoiceValidation:67-72` | `fields.document_kind` | ANO | **V55** (částečně — shodu s titulkem dokladu neřeší) |
| R4 | `currency_id` > 0 | `:74` | `fields.currency_id` | ANO | — |
| R5 | `issue_date` povinné + formát | `:78-82` | `fields.issue_date` | ANO | část **V38** |
| R6 | `due_date` povinné + formát | `:84-88` | `fields.due_date` | ANO | část **V36** |
| R7 | `tax_date`, `received_at` formát (nepovinné) | `:90-96` | — | ANO | část **V37/V40** |
| R8 | `varsymbol` ≤ 20 znaků, bez řídících znaků | `:99-107` | `fields.varsymbol` | ANO | část **V28** (numeričnost NE) |
| R9 | `exchange_rate`, `payment_exchange_rate` v (0; 100 000> | `:110-124` | — | ANO | část **V52** |
| R10 | položka: popis povinný | `InvoiceAmountPolicy:161` | `items.N.description` | ANO | — |
| R11 | položka: `quantity != 0` | `InvoiceAmountPolicy:165` | `items.N.quantity` | ANO | část **V43** |
| R12 | položka: `vat_rate_id` povinné a číselné | `InvoiceAmountPolicy:170` | `items.N.vat_rate_id` | ANO | — |
| R13 | položka: `unit_price_without_vat` číselné | `InvoiceAmountPolicy:174` | — | ANO | část **V43** |
| R14 | položka: zákaz záporné qty **i** ceny zároveň | `InvoiceAmountPolicy:178` | — | ANO | část **V57** |
| R15 | `vat_rate_id` musí existovat v číselníku | `PurchaseInvoiceValidation:139-144` (mapa z `vatRateMap()`) | `items.N.vat_rate_id` | ANO | část **V41** (platnost k DUZP NE) |
| R16 | `advance_paid_amount` ≥ 0 | `:147-150` | — | ANO | část **V59** |
| R17 | poznámky ≤ 64 KB | `:153-158` | — | ANO | — |
| R18 | vendor existuje **a patří tenantovi** | `CreatePurchaseInvoiceAction:56-59` (`SupplierGuard::owns`) | `vendor_not_found` | ČÁST | **V64** |
| R19 | neplátce DPH → `vat_deduction='none'` (pokud volající neurčil) | `CreatePurchaseInvoiceAction:70-78` | — | ČÁST | část **V23** |
| R20 | RC klasifikace na řádku/hlavičce vynutí `reverse_charge=1` | `CreatePurchaseInvoiceAction:88-104` | warning `reverse_charge_forced_by_classification` | ČÁST | část **V53** |
| R21 | auto-default `vat_classification_code` (21 % → 40, 12 % → 41) | `CreatePurchaseInvoiceAction:158 applyVatClassificationDefaults()` + `Service/Report/VatClassificationDefaulter` | — | ANO | **V54** |
| R22 | kolize interního čísla (`uq_pi_supplier_varsymbol`) → 409 | `CreatePurchaseInvoiceAction:110-117` | `varsymbol_duplicate` | ČÁST | — |
| R23 | dobropis s kladným součtem / smíšenými znaménky | `PurchaseInvoiceValidation:170 warnings()` | warning `credit_note_positive_total`, `credit_note_mixed_sign_items` | ANO | **V57** |
| R24 | rozpor znaménka základ × daň v rekapitulaci | `PurchaseInvoiceValidation::hasVatSignMismatch()` | warning `vat_sign_mismatch` | ANO | část **V45** |
| R25 | neplátce + přesto odpočet | `CreatePurchaseInvoiceAction:135-137` | warning `vendor_non_payer_deduction` | ČÁST | část **V23** |
| R26 | `vat_overrides` (§ 73) se ukládají PŘED `recompute()` | `CreatePurchaseInvoiceAction:123-127` + `InvoiceMath::compute()` | — | ANO | **V48** |

### 8.2 ISDOC / ISDOCX / Pohoda importér

| # | pravidlo | implementace | chybový kód | znovupoužitelné | → katalog |
|---|---|---|---|---|---|
| I1 | XSD validace ISDOC | `Service/Validation/XmlSchemaValidator.php` + `api/xsd/` | — | ANO | část **V9** |
| I2 | zákaz DOCTYPE (XXE) | `PohodaXmlParser:51` | `RuntimeException` | ČÁST | část **V62** |
| I3 | root musí být `dataPack`/`responsePack` | `PohodaXmlParser:68` | — | ČÁST | část **V9** |
| I4 | chybí `invoiceHeader` / `symVar` | `PohodaXmlParser:102,115` | — | ČÁST | — |
| I5 | **cross-tenant guard**: buyer IČO == tenant IČO | `IsdocToPurchaseInvoiceMapper:46-53`, `InvoiceImportService:238 detectRoute()` | `InvalidArgumentException` | ČÁST | **V15** |
| I6 | vendor musí mít IČO | `IsdocToPurchaseInvoiceMapper:55-59` | — | ČÁST | část **V17** (jen existence, ne mod-11) |
| I7 | vendor match podle IČO → reuse, jinak založit + ARES | `ClientResolver:50/77` | — | ANO | **V19, V24** |
| I8 | tenant musí mít vyplněné IČO | `InvoiceImportService:76` | — | ČÁST | část **V15** |
| I9 | měna musí být nakonfigurovaná pro suppliera | `InvoiceImportService:652` | — | ČÁST | — |
| I10 | ZIP: max 500 položek, 50 MiB rozbaleno, 10 MiB/položka (zip-bomb) | `InvoiceImportService:34-36, 725-761` | `RuntimeException` | ČÁST | část **V2** |
| I11 | upload: max 50 souborů, 20 MiB/soubor, 50 MiB celkem | `Action/Admin/ImportAction.php:35-37` | — | ČÁST | **V2** |
| I12 | rekapitulace z `<TaxTotal>` do `vat_overrides` | `Service/Import/PurchaseVatRecapSeeder.php` | — | ANO | **V48** |
| I13 | ČNB kurz u cizí měny | `Service/Import/PurchaseInvoiceCnbApplier.php` | — | ANO | **V52** |

### 8.3 `scan-inbox`

| # | pravidlo | implementace | znovupoužitelné | → katalog |
|---|---|---|---|---|
| S1 | role `admin|accountant` | `ScanInboxAction:34` | ANO (vzor) | **V65** |
| S2 | realpath guard — soubor musí být uvnitř `inbox_dir` (symlink/traversal) | `PurchaseInvoiceInboxScanner` (`$inboxReal` porovnání) | ČÁST | **V7** |
| S3 | whitelist přípon z cfg (`pdf, isdoc, isdocx, xml`) | tamtéž, `allowed_exts` | ČÁST | část **V1** (přípona, **ne** magic bytes) |
| S4 | max 20 MiB/soubor | `PurchaseInvoiceInboxScanner:41` | ČÁST | **V2** |
| S5 | max 500 souborů/běh | `:42` | ČÁST | — |
| S6 | dedup přes SHA-256 proti `pdf_hash` | `findIdByPdfHash()` | ANO | **V69** (v rámci dávky NE) |
| S7 | dry-run režim (nezapisuje) | `scan($supplierId, $userId, dryRun: true)` | ANO (vzor pro `/validate`) | — |

### 8.4 BYOK AI cesta (`AiPdfExtractor`) — **nejde přes společnou validaci**

| # | pravidlo | implementace | → katalog |
|---|---|---|---|
| A1 | vendor objekt existuje, má `company_name` nebo `ic` | `validateAiData:371-376` | část **V17** |
| A2 | `issue_date` ve formátu `YYYY-MM-DD` | `:379` | část **V36** |
| A3 | `currency` ISO 4217 | `:382` | — |
| A4 | ≥ 1 položka, každá má popis/qty/cenu | `:386-393` | část **V43** |
| A5 | dedup `pdf_hash` → vrátí existující ID | `extractAndCreate:97-110` | **V69** |
| A6 | ISDOC embed má přednost před AI | `:113-137` | tok v 3.1 |
| A7 | vendor↔customer swap detekce + odmítnutí „fakturuji sám sobě“ | `:176-215` | **V16** |
| A8 | customer IČO ≠ tenant → `wrong_tenant` | `:217-226` | **V15** |
| A9 | prohozené `issue_date`/`due_date` → oprava | `fixSwappedIssueDueDates()` | **V36** |
| A10 | dobropis podle záporných řádků (override AI) | `createDraft:424-444` | **V57** |
| A11 | neplátce → nulování sazeb + `vat_deduction='none'`, při rozporu **blokující** varování | `:502-520`, `setExtractionWarning(blocking: true)` | **V23** |
| A12 | jednosazbová konzistentní rekapitulace → verbatim (§ 73) | `authoritativeRecapBaseLine()`, `collapseToSummaryBaseLine()`, `singleRateConsistentRecap()` | **V44, V48** |
| A13 | reverse charge auto-detect + klasifikace 23/24/24e/25 + DUZP § 25 | `inferReverseCharge()`, `createDraft:574-655`, `euAcquisitionTaxDate()` | **V53** |
| A14 | rozdíl součtu řádků vs. „K úhradě“ z PDF ≥ 1 Kč → varování | `maybeFlagTotalsMismatch()`, `applyRoundingFromPdfTotal()` | **V44, V46, V49** |
| A15 | ČNB kurz k DUZP | `applyCnbRate()` | **V52** |
| A16 | návrh provázání zálohy z `advance_reference` (neaplikuje) | `maybeSuggestAdvanceLink()` | **V58** |
| A17 | sanitizace čísla dokladu | `sanitizeVendorNumber()` | část **V26** |

> **Zásadní zjištění k § 2 zadání:** `AiPdfExtractor::createDraft()` **NEVOLÁ**
> `PurchaseInvoiceValidation::invoice()`. Píše do DB přes `PurchaseInvoiceRepository`
> napřímo, s vlastní (slabší) `validateAiData()`. Parita mezi „ruční“ a „AI“ cestou tedy
> dnes **neexistuje** — jsou to dvě různé zapisovací cesty. Totéž platí pro
> `IsdocToPurchaseInvoiceMapper` a `PurchaseInvoiceInboxScanner`.
> Důsledek pro plán: viz sekce 10 (verdikt ke Commitu 1).

### 8.5 Mapování katalogu V1–V74 na realitu

| ID | stav | poznámka / kde vzít |
|---|---|---|
| V1 MIME + magic bytes | **NE** | dnes jen přípona (scan-inbox) nebo `finfo` bez porovnání s příponou (`DocumentStorage:265`). Nutno napsat. |
| V2 velikost | **ANO** | limity existují, ale **tři různé** (20/32/50 MiB) — sjednotit a zdůvodnit |
| V3 PDF otevřít, strany > 0, nešifrované | **ČÁST** | `smalot/pdfparser` umí; počet stran nutno dopočítat, detekce šifrování chybí. **Bez popplera v image.** |
| V4 sha256 shoda results ↔ manifest | **NE** | nové |
| V5 `batch_file_id` téže dávky | **NE** | nové |
| V6 chybějící/přebývající soubor | **NE** | nové |
| V7 path traversal | **ČÁST** | vzor `scan-inbox` realpath guard; pro ZIP `ZipImporter` |
| V8 vision vs. text | **ČÁST** | `DocumentTextExtractor` pozná „PDF bez textové vrstvy“ (vrací `unsupported`) |
| V9 JSON schema | **NE** | v repu není JSON Schema validátor (jen XSD). Nutná vlastní implementace nebo nová závislost — viz O-8 |
| V10 allowlist polí | **NE** | nové (kritické) |
| V11 strict mode | **NE** | nové |
| V12–V14 source/confidence/conflict | **NE** | nové, dnešní AI cesta confidence vůbec nevrací |
| V15 odběratel == tenant | **ANO** | `IsdocToPurchaseInvoiceMapper:46`, `AiPdfExtractor:217` |
| V16 dodavatel != tenant | **ANO** | `AiPdfExtractor:176-215` |
| V17 IČO mod-11 | **NE** | **v celém repu neexistuje kontrolní součet IČO** (ověřeno grepem) |
| V18 DIČ vs. IČO | **ČÁST** | `VendorVatPayerResolver::isCzGroupDic()` řeší jen skupinové DIČ CZ699 |
| V19 ARES existence | **ANO** | `AresClient:32` (+ cache) |
| V20 fuzzy název | **NE** | nové |
| V21 adresa | **NE** | nové |
| V22 likvidace / zrušený | **ČÁST** | `AresClient::normalize()` — nutno ověřit, která pole ARES vrací |
| V23 plátcovství k DUZP | **ČÁST** | `CrpDphClient` + `VendorVatPayerResolver` **k dnešku**, ne k datu DUZP → nové |
| V24 match podle IČO, ne názvu | **ANO** | `ClientResolver:77` |
| V25 VIES | **ANO** | `ViesClient:41`, výjimka pro CZ699 |
| V26 číslo dokladu | **ANO** | `PurchaseInvoiceValidation:56` |
| V27 duplicita (vendor, číslo) | **ČÁST** | DB unikát je **(supplier, vendor, číslo, issue_date)** — přísnější pravidlo V27 nesedí na DB (viz O-9) |
| V28 varsymbol | **ČÁST** | délka/znaky ANO, numeričnost NE |
| V29 účet mod-11 | **NE** | `BankAccountParser` mod-11 nedělá (jen IBAN mod-97) |
| V30 IBAN mod-97 | **ANO** | `BankAccountParser:80` |
| V31 BIC | **NE** | nové (triviální) |
| V32 § 109 účet | **ANO** | `PaymentOrderService:406 verifyInvoiceAccount()` |
| V33–V35 QR SPAYD | **NE — blokující gap** | v repu je jen **generátor** QR; dekodér není a v image není `zbarimg`. Viz O-6 |
| V36 issue ≤ due | **ČÁST** | `fixSwappedIssueDueDates()` to opravuje, netvrdí FAIL |
| V37 DUZP okno | **NE** | nové |
| V38 datum v budoucnu | **NE** | nové |
| V39 uzavřené období DPH | **ČÁST** | `TaxSubmissionRepository` + `DocumentTrashPolicy` už podobnou kontrolu dělá pro koš — znovupoužít |
| V40 received_at ≥ issue | **NE** | nové |
| V41 sazba platná k DUZP | **ČÁST** | `vat_rates.valid_from/valid_to` v DB **jsou**, `vatRateMap()` je ignoruje |
| V42 otevřené účetní období | **ČÁST** | `GET /api/codebooks/years` |
| V43 peněžní řádek: qty ≠ 0, qty × cena == základ | **ČÁST** | `InvoiceMath::compute()` + `reconcileLineAmount()` — viz DELTA níže |
| V43b popisný řádek (qty == 0 ∧ cena == 0 ∧ popis ≠ '') | **NE** | legitimní, INFO `text_line`; prázdný popis u nulového řádku = FAIL |
| V43c nekonzistentní řádek (qty == 0 ∧ cena ≠ 0, nebo základ ≠ qty × cena) | **NE** | tohle je skutečná chyba, kterou původní V43 mířila zasáhnout |
| V43d aspoň jeden peněžní řádek na dokladu | **NE** | doklad nesmí být jen z popisů |
| V43e dobropis: znaménko konzistentní napříč peněžními řádky | **ČÁST** | `warnings()` hlídá smíšené znaky, ale jen jako WARN |
| V44–V46 rekapitulace, tolerance | **ČÁST** | `maybeFlagTotalsMismatch()` (práh 2 %), `applyRoundingFromPdfTotal()` (práh 1 Kč) — jiné prahy než v zadání |
| V47 rounding ±0,50 | **NE** | nové |
| V48 vat_overrides § 73 | **ANO** | `setVatOverrides()` + `PurchaseVatRecapSeeder` + `InvoiceMath` |
| V49 self_check | **NE** | nové (model dnes `self_check` nevrací) |
| V50 částka slovy | **NE** | nové |
| V51 více sazeb | **ANO** | `InvoiceMath` per sazba |
| V52 cizí měna + ČNB | **ANO** | `PurchaseInvoiceCnbApplier`, `CnbExchangeRateClient` |
| V53 reverse charge | **ANO** | `AiPdfExtractor:574-655`, `PurchaseInvoiceValidation::REVERSE_CHARGE_CODES` |
| V54 klasifikace | **ANO** | `VatClassificationDefaulter` |
| V55 typ dokladu | **ČÁST** | seznam ANO, shoda s titulkem dokladu NE |
| V56 záloha mimo Knihu DPH | **ANO** | `VatLedgerService` — tvrdá podmínka `document_kind <> 'advance'` + sazba CZ-NA |
| V57 dobropis znaménka | **ANO** | `warnings()` + `InvoiceAmountPolicy` |
| V58 křížová kontrola záloh | **ČÁST** | `findAdvanceByReference()`, `advanceCandidates()`, `settlementDocCandidates()` |
| V59 advance_paid_amount | **POZOR** | aplikace to dělá **jinak** — viz O-10 |
| V60 is_fixed_asset | **ANO** | dnes se nikdy automaticky nenastavuje (default 0) |
| V61 expense_category | **ANO** | `expense_categories` prázdné pro supplier 1, `createDraft` bere default z karty dodavatele |
| V62 suspicious_content | **NE** | nové (kritické) |
| V63 batch token | **NE** | nové + kolize s middleware stackem (O-2) |
| V64 tenant scoping | **ANO** | `SupplierGuard`, `SupplierScopeMiddleware` |
| V65 role | **ANO** | vzor `ScanInboxAction:34` |
| V66 leak test | **NE** | nové |
| V67 transakčnost | **ČÁST — POZOR** | `createDraft()` + `replaceItems()` + `recompute()` **nejsou dnes v jedné transakci** |
| V68 idempotence | **ČÁST** | dedup přes `pdf_hash` a `findIdByVendorInvoice` |
| V69 dedup v dávce | **ČÁST** | mezi dávkami ANO, uvnitř jedné dávky NE |
| V70 žádný odchozí HTTPS s obsahem | **NE** | nové (test), ARES/CRPDPH/ČNB/VIES posílají jen IČO/DIČ/měnu — OK |
| V71 KH B.2 ≥ 10 000 Kč | **ČÁST** | `Service/Report/KontrolniHlaseniBuilder` sekce rozděluje, ale povinnost DIČ+ev. číslo nevaliduje při zápisu |
| V72 KH B.3 | **ČÁST** | tamtéž |
| V73 náhledy DP3/KH | **ANO** | `GET /api/reports/dphdp3/preview`, `/api/reports/dphkh1/preview` |
| V74 RC řádky 5/10/12 | **ANO** | pokryto testy `PurchaseReverseChargeConsistencyTest` |

**Souhrn:** z 74 pravidel je **20 plně znovupoužitelných**, **26 částečně** (existuje jiná
varianta nebo jiný práh) a **28 zcela nových**. Dvě pravidla (V33–V35, V59) narážejí na
prostředí/architekturu — viz odchylky.

---

### 8.6 Jak se tento katalog čte (platí pro celý dokument)

**Vynucení pravidla je vlastnost cesty, ne dokladu.** Kdo čte „zápis je atomický" nebo
„duplicita se odmítne", čte to o **té cestě, kterou matice označuje jako konvergovanou** —
ne o každém dokladu v databázi.

**Poměr konvergence se nikde neuvádí jako literál.** Odkazuje se na `RULE-PATH-MATRIX.md`,
která **nejdřív kanonicky vyjmenuje cesty** (bez definice „cesty" nevyjde poměr ani jedním
způsobem počítání) a číslo je z ní odvozené. Ručně přepsané číslo přežije svou pravdivost —
týž vzor jako čísla řádků a jako „62 dokladů".

**Stav každého pravidla je v `RULE-STATUS.tsv`**, ne v próze. Uzavřená množina stavů:
`vynuceno-a-otestovano` (povinné `test:`), `definovano-nevynuceno` (povinné `vynuceni:`,
`blokuje:`, `od:`), `nepouzitelne` (povinné `duvod:`). **Jedna gramatika pro všechny řádky** —
`klíč:hodnota`, tabulátor jako oddělovač, žádný volný text.

**Červená pojistka znamená jedinou věc: skutečnost neodpovídá deklarovanému stavu.**
Ne „ještě to není hotové". Parser na nerozpoznaném řádku **padá, nikdy ho nepřeskočí** —
přeskakující parser je umlčení kontroly o patro níž. A `od:` je povinné proto, aby
„dočasně nevynuceno" tiše nezestárlo na trvalý stav: stáří je měřitelné a hlásí se.

### 8.7 Kanonický seznam ID — jediný zdroj pro pojistku

Tvar: `V<číslo><volitelné písmeno>`. Pojistka čte odsud a odnikud jinud.

```
V1 V2 V3 V4 V5 V6 V7 V8 V9 V10 V11 V12 V13 V14 V15 V16 V17 V18 V19 V20
V21 V22 V23 V24 V25 V26 V27 V28 V29 V30 V31 V32 V33 V34 V35 V36 V37 V38 V39 V40
V41 V42 V43 V43b V43c V43d V43e V44 V45 V46 V47 V48 V49 V50
V51 V52 V53 V54 V55 V56 V57 V58 V59 V60 V61 V62 V63 V64 V65 V66 V67 V68 V69 V70
V71 V72 V73 V74 V75 V76 V77 V78 V79 V79b V80 V81a V81b V81c V82 V83 V83b V84 V85 V86
```

**Samotné `{{noref:V81}}` neexistuje** — rozděleno na `V81a`/`V81b`/`V81c`. Pojistka musí holé
ID bez markeru odmítnout jako neznámé; je to test obou směrů, ne jen kontrola úplnosti.
Marker `{{noref:…}}` označuje **záměrnou zmínku neexistujícího ID** — parser ho do směru 2
nezapočítá, ale vypíše zvlášť, aby nešel přehlédnout.

### 8.8 V75–V86

Rekonstruované popisují, co kód **dělá**, ne co by pravidlo mělo říkat. Rozchody jsou nálezy.

**V75 — Atomicita zápisu.** FAIL. `PurchaseInvoiceWriteService`: validace → `createDraft` →
`replaceItems` → `vat_overrides` → `recompute` v jedné transakci; pád kdekoli nesmí nechat
v DB nic. Testy: `PurchaseInvoiceWriteServiceTransactionTest`, charakterizace předchozího
stavu `PartialWriteCharacterizationTest`.
⚠️ **Vlastnost jedné cesty, ne dokladu** — cesty z rohatky V77 zapisují mimo write service.

**V76 — Stínová validace: zaznamenat, ne vynutit.** INFO. `PurchaseInvoiceWriteService`, nad
historií `HistoricalValidationScanner` + `api/bin/shadow-validate-existing.php`.
Testy: `ShadowValidationTest`, `HistoricalValidationScannerTest`, pomůcka `CollectingLogger`.
⚠️ Rozchody: analytická vrstva klasifikuje podle dohodnutých pravidel včetně V43b, produkční
`InvoiceAmountPolicy` nulové množství odmítá (§14). Skener měl **default „všichni tenanti"** —
scope se zpovinňuje, parametr bez hodnoty je chyba.

**V77 — Přijatou fakturu zakládá jen `PurchaseInvoiceWriteService`.** FAIL, statický test nad
zdrojáky. Testy: `PurchaseInvoiceCreationPathsTest` (rohatka `NOT_YET_CONVERGED`, smí se **jen
zkracovat**), `PurchaseInvoiceWritePathTest`.
⚠️ **Pravidlo dnes neplatí.** Nekonvergované cesty a jejich doložené překážky jsou
v `RULE-PATH-MATRIX.md`; počet se sem nepřepisuje.

**V78 — Dvojí odpočet v zálohovém řetězci (§ 37a).** FAIL: `advance_paid_amount` nenulové na
konečné faktuře (patří výhradně na DDKPZ); součet zápočtových řádků přesáhne součet záloh
doložených v dávce; táž záloha odečtena dvakrát (párování číslo DDKPZ / VS / trojice
dodavatel+částka+datum, překryv absolutních hodnot); zápočet slévající více sazeb do jednoho
řádku — zápočet musí být po sazbách a součet v každé sazbě se rovná doloženým zálohám v téže
sazbě. WARN (ne FAIL): konečná faktura na nulu bez řetězce v dávce — legitimní, když zálohy
přišly dřív, ale musí být v reportu vidět.
Testy: `testAdvancePaidOnFinalInvoiceIsFail`, `testNettingExceedingDocumentedAdvancesIsFail`,
`testSameAdvanceDeductedTwiceAcrossChainIsFail`, `testNettingCollapsingVatRatesIsFail`,
`testZeroTotalWithoutChainInBatchIsWarnNotFail`. Zlatá fixtura **syntetická a anonymizovaná**.

**V79 — Limity payloadu.** FAIL, konstanty na jednom místě, každý překročený limit hlásí název
limitu + JSON pointer (V82): max 50 dokladů v dávce, 20 MB soubor, 200 MB dávka, 2 MB
`raw_json`/doklad, 512 KB `normalized_json`, 500 položek/doklad, 4 096 znaků textové pole,
64 KB poznámkové, hloubka zanoření 20, 1 000 prvků pole, 200 klíčů v objektu. FAIL i na
binárku dokladu nebo base64 nad 1 KB v `raw_json`.
Testy: `testBatchOverFiftyDocumentsIsRejected`, `testFileOverSizeLimitIsRejected`,
`testRawJsonOverSizeLimitIsRejected`, `testItemCountOverLimitIsRejected`,
`testNestingDepthOverLimitIsRejected`, `testBase64BlobInRawJsonIsRejected`,
`testLimitsAreEnforcedNotOnlyDeclared`.

**V79b — Retence.** FAIL. Pojmenované konstanty **s jednotkou** na jednom místě:
`RAW_JSON_RETENTION_DAYS = 0`, `NORMALIZED_JSON_RETENTION_DAYS = 90`. Nikdy literál v dotazu.

**Dvě cesty, dva spouštěče, dva testy** — a nesmí se zaměnit:

| cesta | spouštěč | kdo ji vykoná |
|---|---|---|
| **A — terminální stav** (dokončeno, **selhalo**, **zrušeno**) | přechod stavu dávky | **in-process**, z cronu se nespouští; nula dní přes noční úlohu je až 24 h expozice |
| **B — stropní stáří** pro dávky, které terminálního stavu **nedosáhnou** | uplynulé stáří | **`cron-cleanup`** (denně 03:00) — jediný sweeper, který v aplikaci je |

Cesta B **nemůže být řízená stavem dávky, definičně**: ke změně stavu nedojde, právě proto
je ta dávka opuštěná. Musí ji vysbírat sweeper. Sweep je **tenantně omezený** (§4.1 —
`supplier_id` explicitně, žádný neomezený agregát) a **logovaný**.

Že opuštěné dávky reálně vznikají, není teorie: `BackgroundProcess::spawnPhp()` je
fire-and-forget přes `nohup … &`, takže worker, který zemře uprostřed, nechá dávku
v neterminálním stavu. To je zároveň ta dávka, která nejspíš obsahuje něco pokřiveného.

Purge je **idempotentní**, ve **vlastní transakci**, a jeho selhání nesmí vzít s sebou nic
cizího. Jeho log podléhá V82: **rozsah a počet, nikdy obsah.** Čtení `raw_json` jen role
účetní a výš.

Testy — cesta A: `testRawJsonPurgedAtBatchCompletion`, `testRawJsonPurgedOnFailedBatch`,
`testRawJsonPurgedOnCancelledBatch`, **`testTerminalPurgeRunsInProcessNotFromCron`**.
Cesta B: **`testAbandonedBatchIsPurgedByCleanupSweep`**, **`testSweepIsTenantScoped`**,
`testSweepLogsScopeAndCount`.
Společné: `testNormalizedJsonPurgedAfterNinetyDays`, `testPurgeTouchesOnlyItsOwnBatch`
(dvě dávky, druhá musí zůstat nedotčená), `testPurgeIsIdempotent`,
`testPurgeLogsScopeAndCountNeverContent`, `testPurgeNeverRunsInShadowMode`,
`testRetentionValuesComeFromNamedConstants`, `testRawJsonNotReadableBelowAccountantRole`.

**V80 — Nepřátelský JSON.** FAIL, odmítnout (ne tolerovat): duplicitní klíče (tiché „poslední
vyhrává" mění význam dokladu), `NaN`, `Infinity`, vedoucí nuly, `+1`, hex literály, jednoduché
uvozovky, koncové čárky, komentáře, neescapované řídicí znaky, neplatné UTF-8, osamocené
surrogáty, BOM, `NUL`, klíče `__proto__`/`constructor`/`prototype`, čísla mimo bezpečný
celočíselný rozsah, číselné literály nad 32 znaků, vědecká notace v peněžních polích,
neobjektový typ na nejvyšší úrovni, neznámé klíče (strict).
Testy: data-provider `testHostileJsonIsRejected` s pojmenovanými případy,
`testDuplicateKeysAreRejectedNotLastWins`, `testUnknownKeysAreRejectedInStrictMode`,
`testPrototypePollutionKeysAreRejected`, `testInvalidUtf8IsRejected`.

**V81a — Žádný `float` v našem kódu.** FAIL. Peníze jako string, drženo jako celočíselné
minimální jednotky nebo desítkový string. FAIL na více než dvě desetinná místa a na hodnotu,
u které round-trip string → interní → string není znak po znaku identický. Statická kontrola
nad **naším** jmenným prostorem (deny-list sdílený s V84).
Testy: `testMoneyNeverTouchesFloat`, `testThreeDecimalMoneyIsRejected`,
`testFloatBasedImplementationFailsTheSuite` (mutační důkaz, jen náš kód).

**V81b — Hraniční kontrola výstupu upstreamu.** FAIL dokladu. `InvoiceMath` ani
`PurchaseInvoiceCalculator` **nepředěláváme** — ale co z nich vyjde, ověřujeme proti přesným
identitám po sazbách: základ + DPH = celková částka přesně, součet položkových řádků = základ
v dané sazbě s tolerancí **nula**. Rozdíl ze zaokrouhlení smí projít **jen** jako řádek
s `is_settlement_rounding`.
Testy: `testPerRateTotalsMustMatchExactly`, `testRoundingDifferenceOnlyViaSettlementLine`.

**V81c — Charakterizace zaokrouhlování upstreamu.** Připne dnešní chování, aby budoucí změna
byla vidět jako změna, ne jako záhada. Test: `testUpstreamRoundingBehaviourIsCharacterized`.
Kdyby V81b ukázala drift z floatů v upstreamu, je to **nález a kandidát na upstream issue**,
ne práce v této featuře.

**V82 — Lokalizace chyb, redakce default-deny.** FAIL. Každý nález nese RFC 6901 JSON pointer,
ID pravidla, závažnost a lidskou zprávu.

**Redakce je výchozí stav** a děje se na **jednom serializačním místě**, ne u volajících.
Existuje explicitní **allow-list polí**, jejichž hodnota smí ve zprávě být (`vat_rate_id`, kód
měny, číslo řádku, `document_kind`, název limitu). Cokoli mimo allow-list se hlásí jen typem
a délkou — implementace nerozhoduje, co je osobní údaj, rozhoduje, co je na seznamu.

**Pointer prosakuje taky:** musí být **indexový, nikdy klíčovaný obsahem**.
`/lines/3/description` ano, `/parties/<jméno firmy>/…` ne.
Testy: `testEveryFindingHasResolvablePointer`, `testEveryFindingCarriesRuleId`,
`testFindingOrderIsDeterministic`, `testUnknownFieldIsRedactedWithoutCodeChange` (syntetický
nález s neznámým polem musí vyjít zredigovaný, aniž kdokoli přidal řádek kódu),
`testPointersAreIndexedNeverKeyedByContent`, `testEchoAllowListIsExactlyAsDocumented`.

**V83 — Testy neběží proti ostré ani sdílené databázi.** FAIL, zastaví běh s **`exit 78`**
(`EX_CONFIG`). Vzor: `/(^|[_\-])(tests?|testing|ci|qa|sandbox|clone)\d*([_\-]|$)/i`.
Testy: `TestDatabaseGuardTest`, `TestDatabaseGuardWiringTest`,
`testDisallowedDatabaseNameExitsWithSeventyEight` — tvrdí **konkrétní 78**, ne „nenula",
a **čte návratový kód přímo, ne přes rouru** (jinak se zámek měří přístrojem, který už jednou
ukázal nulu; `set -o pipefail` nebo `${PIPESTATUS[0]}`).

**V83b — Ověřuje se instance, ne jen jméno schématu.** FAIL. *Nové.* Guard podle vlastní hlášky
hlídá **jen jméno schématu, ne host** — schéma `neco_test` na ostrém serveru projde. V83b
vyžaduje: host a port musí být naše dockerová instance, a cílové schéma **nesmí být totožné**
s tím, které má nakonfigurovaná aplikace.
🔴 **NÁLEZ:** `api/bin/reset.php`, `migrate.php` a `sample.php` guardem chráněné **nejsou**.
`reset.php` umí smazat ostrá data a nic ho nezastaví. Z webu dosažitelný **není** — ověřeno
zvenčí přes 443 na neexistujících jménech včetně normalizačních variant (`%62in`, `%2f`,
traversal, dvojité lomítko, `/./`): vždy 403, identická odpověď 548 B.
**Ale ta ochrana je vlastnost nasazení, ne kódu** — kdo provozuje za Apachem nebo Caddy, ten
`location` blok nemá. Guard patří do skriptu; je to nejzávažnější z kandidátů na upstream issue.

**V84 — Jádro je čistá knihovna.** FAIL. Bez I/O, DB, filesystemu, náhody, globálů,
superglobálů, `getenv` a mutovatelného statického stavu; závislosti injektované.

**Zakázané je čtení hodin, ne datová aritmetika.** Zakázané: `time()`, `microtime()`,
`hrtime()`, `date()` bez explicitního timestampu, `new DateTimeImmutable()`/`new DateTime()`
bez argumentu **i s relativním klíčovým slovem** (`'now'`, `'today'`, `'tomorrow'`, `'+1 day'`,
`'midnight'`), `strtotime()` bez báze, `mt_rand`, `random_*`, `uniqid`.
Povolené: `new DateTimeImmutable('2026-07-15')` a operace nad hodnotami, které přišly zvenčí.
**`createFromFormat` musí mít kotvu `!` nebo `|`** — bez ní doplní nespecifikované složky
z aktuálních hodin, což je tichá chyba, která se projeví jednou za čas a vypadá jako náhoda.
**Aktuální čas se do jádra vždy injektuje** přes `ClockInterface`, nikdy nečte.
Testy: `testCoreLibraryHasNoForbiddenCalls`, `testCoreLibraryImportsNothingFromFramework`,
`testAllowListIsExactlyAsDocumented`, `testRelativeDateKeywordsAreForbidden`,
`testCreateFromFormatRequiresAnchor`, `testDateArithmeticOnPassedValuesIsAllowed` (doloží,
že pravidlo nezakazuje víc, než má).

**V85 — Determinismus.** FAIL. Shodný vstup → bajtově shodný výstup: dvakrát v témž procesu,
jednou v podprocesu, nezávisle na `LC_ALL`, `TZ`, pořadí klíčů, PHP hash seedu a PCRE JIT.
V normalizovaném výstupu není časová značka, `uniqid`, `spl_object_hash` ani nic odvozeného
od běhu — metadata dávky žijí mimo normalizované DTO. Řazení podle stabilního dokumentovaného
klíče, nikdy podle pořadí v hashmapě.
Testy: `testSameInputProducesIdenticalOutputTwice`, `testOutputIndependentOfLocaleAndTimezone`,
`testOutputIndependentOfInputKeyOrder`, `testNoTimeOrRandomInNormalizedOutput`,
`testSubprocessRunMatchesInProcessRun`.

**V86 — Žádná síť v analytické vrstvě.** Měla by být FAIL.
⚠️ **NEVYNUCENO — nemá implementaci ani test.** Jediný výskyt v repu je **komentář**
v `HistoricalValidationScanner.php`. Dnes platí jen proto, že to tak někdo napsal; příští
úprava může přidat volání ARESu a nikdo si toho nevšimne. Vynucení patří k runtime offline
guardu v Commitu 12 (včetně `ext-curl`).

---

## 9. Odchylky proti zadání (co jsem odhadl zvenčí špatně)

**O-1 — BYOK endpoint má jinou cestu.**
Zadání: `POST /api/integrations/anthropic/extract`. Realita: `POST /api/admin/imports/ai-extract-pdf`
(+ credentials na `/api/admin/imports/anthropic/credentials`). UI není „Externí integrace → AI“
ale **Admin → Integrace, tab `ai`** (`/admin/integrations?tab=ai`). Bez dopadu na návrh, jen
opravuji názvosloví.

**O-2 — jednorázový batch token nemá kam se v middleware stacku vejít.**
`POST .../results` s vlastním tokenem projde `AuthMiddleware` (403 bez session/PAT),
`RoleMiddleware` (admin fallback), `SupplierScopeMiddleware` (bez `X-Supplier-Id` fallback na
MIN(supplier)) a `ApiScopeMiddleware` (403 mimo allowlist). Zavést pátou cestu autentizace
znamená sáhnout do 4 upstream souborů — přesně to, čemu se má plán vyhnout.
**Doporučení:** batch token **nepoužívat jako náhradu autentizace**, ale jako *druhý faktor*
uvnitř už autentizovaného requestu (session nebo PAT s `read_write`), a endpointy zavěsit pod
už povolený prefix `/api/purchase-invoices/import-batches/...`. Tím se nemění ani jeden
middleware, role zůstává `accountant` (shodná s klasickým importem, V65) a tenant scoping
funguje sám (V64). Token dál splní svoji roli: jednorázový, expirující, scope na jednu dávku,
hash v DB, 403 na cizí dávku.

**O-3 — kolize pojmenování s existující dávkou (#232) a s `import_jobs`.**

> ### ⛔ ČÁST TÉTO ODCHYLKY BYLA ODVOLÁNA 30. 7. 2026 — NEŘIĎTE SE JÍ
>
> **Platí pojmenování tabulek** (`purchase_import_batches`, `purchase_import_batch_files`,
> `purchase_import_batch_results`) — ta část je v pořádku.
>
> **NEPLATÍ doporučení „nový sloupec nepřidávat, naplnit stávající `import_batch_id`”.**
> Platí **A3** a **sekce 15**: přidat vlastní `purchase_import_batch_id` **s cizím klíčem**
> a upstreamový `import_batch_id` **nechat být**.
>
> Důvod odvolání (vše doložené v §15): upstreamový sloupec je `VARCHAR(32) NULL`, **bez FK
> a bez UNIQUE**, plní ho **frontend** hodnotou `crypto.randomUUID()` bez pomlček
> (`Integrations.vue:384-390`), zapisuje se přes `setImportBatchId()`, který dělá tiché
> `substr(trim($id), 0, 32)` **bez chyby**, a to nad **case-insensitive** kolací
> `utf8mb4_unicode_ci`. Sémanticky znamená „označení dávky hromadného **AI** importu”,
> patří upstreamu (commit `9120ffe1`, 23. 7. 2026, #232) a je to živý kód.
>
> Sdílení by navíc (a) zkreslilo náš vlastní report, protože `classifySource()` mapuje
> neprázdnou hodnotu na zdroj `ai_pdf`, a (b) poškodilo upstreamovou funkci uživateli —
> do dropdownu „dohledat import” bez rozlišovače by se promíchaly dva druhy dávek a naše
> dávky by kvůli `limit = 20` vytlačovaly jeho AI dávky.
>
> **Cena rozhodnutí:** vlastní filtr „dohledat dávku” si napíšeme sami (patří k UI commitu).
> Za to dostáváme referenční integritu, nulové riziko smíchání entit a nulový zásah
> do upstream kódu.
>
> Ten původní text zůstává níže **přeškrtnutý**, ne smazaný — kdo hledá, proč se rozhodnutí
> otočilo, má vidět obojí. Implementující sezení se jím řídit nesmí.

`purchase_invoices.import_batch_id` už znamená něco jiného. ~~**Doporučení:** tabulky pojmenovat
`purchase_import_batches`, `purchase_import_batch_files`, `purchase_import_batch_results`
a nový sloupec na fakturu nepřidávat — místo toho **naplnit stávající `import_batch_id`**
hodnotou nové dávky, čímž zdarma získáme filtr v seznamu přijatých faktur a dropdown
„dohledat import”, který už existuje.~~ ← **ODVOLÁNO, viz rámec výše.**

**O-4 — down migrace neexistují.** Runner umí jen dopředu. Test „migrace up/down idempotentně“
z Commitu 2 zúžím na „up je idempotentní (dvojí běh = no-op)“ + ověření schématu; rollback
zůstane procesní (dump).

**O-5 — server neumí rasterizovat PDF.** V image není poppler ani ghostscript. Instrukce v promptu
(`pdftotext -layout`, `pdftoppm -r 300 -png`) se týkají **hostitele, kde běží Claude Code** — tam
poppler je, takže prompt může zůstat dle zadání. Server ale nesmí na tyto binárky spoléhat
u V3/V8 — počet stran a přítomnost textové vrstvy budu zjišťovat přes `smalot/pdfparser`.

**O-6 — QR (V33–V35) nemá čím dekódovat.** V repu je jen generátor QR; `zbarimg` není ani v image,
ani na hostiteli, a `PdfImageExtractor` umí obrázek QR jen *najít* a vrátit jako PNG data URI,
nikoli přečíst. Tři možnosti, seřazené podle mé preference:
1. **Přidat čistě PHP dekodér** (`khanamiryan/qrcode-detector-decoder`, MIT) — nová composer
   závislost, žádný zásah do Dockerfile, funguje na Windows i v Alpine. Splní i V34 (offline).
2. Nechat QR přečíst **Claude Code na hostiteli** a poslat SPAYD řetězec v `results.json` —
   ale pak QR **není nezávislý zdroj pravdy** a celý smysl V33 padá. Nedoporučuji.
3. Degradovat V33 na INFO, dokud dekodér není. Nejhorší varianta — ztrácíme nejsilnější
   obranu proti podvržení účtu (V62).
**Čekám na tvoje rozhodnutí; sám závislost nepřidávám.**

**O-7 — „E2E“ testy v tomto repu neexistují.** Není Playwright/Cypress ani komponentové testy.
Commit 8 tedy dodá Integration testy proti DB + service-level testy; „e2e validate → apply →
apply“ pojedu přes DI kontejner, ne přes HTTP.

**O-8 — JSON Schema validátor v repu není.** Pro V9/V11 (strict, `additionalProperties: false`)
je potřeba buď `justinrainbow/json-schema` / `opis/json-schema` (nová závislost), nebo vlastní
validátor omezený na náš tvar. Preferuji **vlastní validátor** (~200 řádků, plně testovatelný,
nula nových závislostí, upstream-friendly), protože náš tvar schématu je uzavřený a známý.

**O-9 — V27 je přísnější než databáze.** DB unikát je `(supplier_id, vendor_id,
vendor_invoice_number, issue_date)`, tedy stejné číslo s jiným datem projde. Navrhuji V27
implementovat jako **WARN při shodě (vendor, číslo) s jiným datem** a **FAIL při shodě všech
čtyř** (což by stejně shodilo DB). Jinak bychom blokovali legitimní případy, které dnes projdou.

**O-10 — V59 popisuje chování, které aplikace záměrně nemá.** Podle precedentu v datech
(doklady #55–59) i podle kódu sedí `advance_paid_amount` na **daňovém dokladu k záloze**
(doplní `linkAdvance()`), kdežto konečná faktura má **0** a zálohy se z ní odečítají
odpočtovými řádky § 37a (`PurchaseSettlementService`). Kdybych V59 implementoval dle zadání,
odečetla by se záloha dvakrát. Navrhuji V59 přeformulovat na: *„Σ navázaných záloh ≤ celková
částka finální faktury; `advance_paid_amount` na DDKPZ == částka navázané zálohy“*.

**O-11 — „Nákup → Import přijatých“ jako stránka neexistuje.** V sekci Nákup jsou dnes jen
Přijaté faktury / Platební příkazy / Export + dva **admin-only** odkazy do Adminu. Záložku
z Commitu 7 tedy buď zakládám jako novou stránku `/purchase-invoices/import`
(doporučuji — dostupná i pro účetní, konzistentní s V65), nebo jako další tab v
`/admin/integrations`. **Preferuji novou stránku.**

**O-12 — `expense_categories` jsou pro supplier 1 prázdné** (potvrzeno i v dřívějším reportu
k TUkas). V61 tedy zůstává jen návrhem v reportu, jak zadání chce.

**O-13 — testovací doklady k Commitu 3 nejsou v repu.** Zadání odkazuje na „5 TUkas dokladů
(26/28/29 bez textové vrstvy, 27/30 s textem)“ — ty leží mimo git v `/root/tmp/tukas/`
(ID 26–30 jsou `documents.id` v produkční DB). Do repa je jako fixture **dávat nebudu**
(reálné doklady třetí strany, VIN, bankovní účty). Navrhuji vygenerovat syntetické PDF
fixtures se stejnou charakteristikou (2 s textovou vrstvou, 3 bez) a TUkas doklady používat
jen jako lokální ověření mimo git.

---

## 10. Verdikt ke Commitu 1 (extrakce validace do sdílené služby)

**Commit 1 JE potřeba, ale v menším rozsahu, než zadání předpokládá.**

Validace **už sdílená je** — `PurchaseInvoiceValidation::invoice()` je statická, bez závislostí
a volají ji obě ruční akce. Přesouvat není co.

Chybí ale to podstatné: **žádná z importních cest ji nevolá.** `AiPdfExtractor::createDraft()`,
`IsdocToPurchaseInvoiceMapper::map()` i `PurchaseInvoiceInboxScanner` zapisují přes repozitář
mimo ni. Aby dávkový import mohl téct „stejným potrubím“ (§ 2.2 zadání), potřebuje **zapisovací
službu**, která dnes neexistuje: dnes je zápis rozsypaný do trojice
`createDraft()` + `replaceItems()` + `setVatOverrides()` + `recompute()`, kterou si každý volající
skládá sám (a `AiPdfExtractor` k tomu přidává dalších ~15 kroků).

Navrhuji **Commit 1 = `PurchaseInvoiceWriteService`**: čisté přesunutí sekvence
„validace → createDraft → replaceItems → vat_overrides → recompute → warnings“ z
`CreatePurchaseInvoiceAction` do služby + delegace, bez změny chování, **v jedné transakci**
(to je jediná drobná změna chování a řeší V67 — zdůvodním v commit message). Akce se zkrátí
na parsing requestu a serializaci odpovědi. Ostatní importní cesty v tomto commitu
**nepřepojuji** — to by byl zásah do upstream chování s rizikem regresí; dávkový import
na novou službu napojím rovnou.

Odhad zásahu do existujících souborů: `CreatePurchaseInvoiceAction.php` (delegace),
`UpdatePurchaseInvoiceAction.php` (jen pokud vyjde bez rizika, jinak beze změny).

---

## 11. Touchpointy do existujícího kódu a upstream-friendliness

Cíl: co nejméně editovaných upstream souborů, protože fork se pravidelně mergne
(43 konfliktních bloků při posledním merge v4.52.0 je čerstvá zkušenost).

| soubor | zásah | proč nutný | jak minimalizovat |
|---|---|---|---|
| `api/src/Routes.php` | +7 řádků rout | jiná možnost není | jeden souvislý blok s komentářem `// FORK: dávkový import` |
| `api/src/Action/PurchaseInvoice/CreatePurchaseInvoiceAction.php` | delegace na novou službu | Commit 1 | pure move, žádná změna sémantiky |
| `web/src/router/index.ts` | +1 routa | UI | jeden řádek |
| `web/src/components/layout/AppLayout.vue` | +1 položka menu za flagem | UI | jeden řádek v sekci Nákup |
| `web/src/i18n/{cs,en}.json` | nový namespace `batch_import.*` | i18n | vlastní top-level klíč = minimum konfliktů |
| `api/openapi.yaml` | nové cesty | pravidlo repa | na konec `paths`, vlastní `components/schemas` prefix |
| `cfg.sample.php` | blok `purchase_invoice.batch_import` | feature flag | přidat **dovnitř** existujícího `purchase_invoice` bloku |
| `CUSTOMIZATIONS.md`, `CHANGELOG.md`, `manual/` | dokumentace | pravidlo repa | — |

**Vše ostatní jsou nové soubory** v `api/src/Service/PurchaseBatchImport/**`,
`api/src/Action/PurchaseInvoice/ImportBatch/**`, `api/prompt_templates/`,
`web/src/pages/purchase-invoices/BatchImport*.vue`, `db/migrations/` v rezervovaném pásmu
**`0913–0919`** (ne `0912+`, to je obsazené — viz korekce v §3 a hlavička „Stav k 30. 7. 2026").

**Feature flag:** `purchase_invoice.batch_import.enabled`, default `false`, čtený přes
`Config::get()`. Vypnutý flag: routy se **neregistrují vůbec** (podmínka v `Routes.php`),
UI položka menu se nevykreslí, nová tabulka existuje, ale nikdo do ní nesahá → aplikace se
chová bit-pro-bit jako dnes.

---

## 12. Čím pokračovat

Čeká se na tvoje rozhodnutí ke: **O-2** (kam zavěsit endpointy), **O-6** (QR dekodér),
**O-8** (JSON schema), **O-9/O-10** (přeformulování V27 a V59), **O-11** (kde bude UI),
**O-13** (fixtures) a k rozsahu **Commitu 1**.

Do té doby nepokračuji na Commit 1 a nepřipravuji ani kostru.

---

## 13. DELTA KATALOGU — V43 přepsáno (rozhodnutí 29. 7. 2026)

Původní V43 („quantity > 0") byla napsaná příliš hrubě. **Textové řádky na dokladu jsou
normální věc** — rozhodující není nulové množství, ale jestli řádek tvrdí, že něco stojí.
Odhaleno retrospektivní stínovou validací: jediný `real_mismatch` v celé historii byl
zaplacený dobropis se správnými součty, jehož část řádků je popisná. Vynucení původního
pravidla by ho odmítlo.

| ID | pravidlo | severity |
|---|---|---|
| **V43** | Peněžní řádek: `quantity ≠ 0` ∧ `unit_price` číselné ∧ `quantity × unit_price == řádkový základ` (tolerance 0,01 Kč) | FAIL |
| **V43b** | Popisný řádek: `quantity == 0` ∧ `unit_price == 0` ∧ `description` neprázdná → legitimní, do matematiky nevstupuje, zapíše se jako INFO `text_line`. **Prázdná `description` u nulového řádku = FAIL** (to není popis, to je ztracená extrakce) | INFO / FAIL |
| **V43c** | Nekonzistentní řádek: `quantity == 0` ∧ `unit_price ≠ 0`, nebo `quantity ≠ 0` ∧ základ ≠ `quantity × unit_price` | FAIL |
| **V43d** | Aspoň jeden peněžní řádek na dokladu (doklad nesmí být jen z popisů) | FAIL |
| **V43e** | U dobropisu smí být `quantity` nebo cena záporná; znaménko musí být konzistentní napříč všemi peněžními řádky | FAIL na míchání |

### Dopad na prompt (Commit 10)

Do šablony dávkové cesty patří výslovně:

> Volný text z dokladu patří do `note_above_items` / `note_below_items` nebo do
> `description` peněžního řádku, **ne** do samostatných nulových řádků. Když už textový
> řádek vznikne, musí mít neprázdný popis. Popisné řádky nikdy nevytvářej jen proto,
> že se text na dokladu vizuálně nachází mezi položkami.

### Dopad na dnešní kód

`InvoiceAmountPolicy::validateItem()` (produkční validátor ruční cesty) nulové množství
pořád odmítá. **Přepis patří do commitu s doménovým validátorem**, ne sem — je to změna
chování ruční cesty a zaslouží si vlastní commit a charakterizaci.

Do té doby platí: **analytický** skener (`HistoricalValidationScanner`) už podle V43b
klasifikuje, aby baseline ukazoval realitu podle dohodnutého pravidla; **enforcement**
se nemění.

### 13.1 Motivující doklad (anonymizovaně)

Aby bylo za rok dohledatelné, **proč** se pravidlo rozvolnilo, a aby to nešlo zopakovat
bez důvodu — rozvolnění pravidla je totiž vždycky nejsnazší způsob, jak „vyzelenit"
baseline:

| | |
|---|---|
| typ dokladu | `credit_note` (dobropis), stav `paid` |
| počet řádků | 5 |
| z toho popisných | 4 — `quantity = 0` **i** `unit_price_without_vat = 0`, popis 35–49 znaků |
| peněžní řádek | 1, nese celou částku dokladu |
| součty | **v pořádku** — hlavička sedí na součet řádků |
| pole, které nález vyvolalo | `items.*.quantity` („Množství nesmí být 0.") |
| ověřeno, že NEJDE o systémové řádky | `is_settlement_rounding = 0`, `settlement_source_purchase_invoice_id IS NULL` — tedy ani odpočet § 37a, ani zaokrouhlovací řádek |

Doklad byl tedy účetně bezvadný a vynucení původní V43 by ho odmítlo. Chyba nebyla
v datech, ale v pravidle.

### 13.2 Důkaz, že se pravidlo rozvolnilo jen tam, kde mělo

Rozvolnění se smí týkat **výhradně** řádku, který netvrdí žádnou cenu. Ověřeno mutací —
pravidlo bylo dočasně rozbité a testy musely spadnout:

| mutace | očekávání | výsledek |
|---|---|---|
| V43b rozšířeno na *každé* nulové množství (tj. i řádek s cenou) | musí spadnout test na řádek `quantity = 0`, `cena ≠ 0` | **spadly 3 testy**, mezi nimi `testZeroQuantityWithPriceStaysAFinding` |
| V43d vypnuto (`if (false)`) | musí spadnout test na doklad složený jen z popisů | **spadl** `testDocumentMadeOnlyOfTextLinesIsAFinding` |

Řádek, který **tvrdí cenu bez množství**, tedy dál neprojde — a doklad bez jediného
peněžního řádku taky ne. Testy nejsou prázdné.

---

## 14. ZNÁMÉ DIVERGENCE — analytická klasifikace vs. produkční vynucení

Skener nad historií a produkční validátor se **záměrně** liší: analýza už pracuje podle
rozhodnutých pravidel, vynucení se mění až s doménovým validátorem, protože každá taková
změna je změna chování ruční cesty a zaslouží si vlastní commit a charakterizaci.

Tabulka je **checklist pro commit s doménovým validátorem** — ne archeologie.

| pravidlo | analytický skener (`HistoricalValidationScanner`) | produkční vynucení (`InvoiceAmountPolicy` / `PurchaseInvoiceValidation`) | srovnat v |
|---|---|---|---|
| **V43b** popisný řádek (`qty = 0` ∧ cena `= 0` ∧ popis ≠ '') | legitimní, nález se zahodí, počítá se jako `text_line` | **odmítá** („Množství nesmí být 0.") | commit s doménovým validátorem |
| **V43b** nulový řádek s prázdným popisem | nález zůstává (`items.*.description`) | odmítá (obojí: popis i množství) | tamtéž — sjednotit hlášku |
| **V43c** `qty = 0` ∧ cena ≠ 0 | nález zůstává, kategorie `real_mismatch` | odmítá (shodou okolností správně, ale z jiného důvodu) | tamtéž — explicitní pravidlo |
| **V43d** doklad bez peněžního řádku | vlastní nález `items.no_money_line` | **nekontroluje se vůbec** | tamtéž — nové pravidlo |
| **V43e** konzistence znamének u dobropisu | nekontroluje se | jen WARN (`credit_note_mixed_sign_items`) | tamtéž — povýšit na FAIL |

**Pravidlo pro budoucí změny:** každé další rozvolnění nebo zpřísnění v analytické vrstvě
musí přibýt do téhle tabulky **spolu s mutačním důkazem** (sekce 13.2), že se pravidlo
nezměnilo šířeji, než bylo zamýšleno.

---

## 15. A3 — DOLOŽENÉ ZJIŠTĚNÍ k `purchase_invoices.import_batch_id`

**Rozhodnutí: sloupec NESDÍLET, přidat vlastní `purchase_import_batch_id` s cizím klíčem.**

Zjištěno (vše doložené, ne odhad):

* **Definice:** `VARCHAR(32) NULL DEFAULT NULL`, jediný index `idx_pi_import_batch
  (supplier_id, import_batch_id)`, **žádný FK, žádný UNIQUE** — `db/migrations/0141_purchase_invoice_import_batch.sql:8,11`.
* **Tabulka dávek v DB neexistuje.** Grep `batch` přes celé `db/migrations/` vrací jen
  tři řádky, všechny uvnitř 0141. Jediná „importní" tabulka `import_jobs` má
  `BIGINT AUTO_INCREMENT` PK — nekompatibilní typ, žádná vazba.
* **Původ: čistý upstream.** Commit `9120ffe1` (Radek Hulan, 23. 7. 2026, „#232"),
  `git diff upstream/master HEAD -- <migrace>` je **prázdný**. Upstream do toho sáhl
  před šesti dny — je to živý kód, ne mrtvý sloupec.
* **Kdo plní:** výhradně `PurchaseInvoiceRepository::setImportBatchId()` (`:2273`) přes
  `UPDATE`, nikdy `INSERT`. Volá ho jen `AiPdfExtractor::tagImportBatch()`.
  **Hodnotu generuje frontend** — `crypto.randomUUID()` bez pomlček, 32 hex znaků
  (`web/src/pages/admin/Integrations.vue:384-390`).
* **Kdo čte:** filtr v seznamu (přesná rovnost), `recentImportBatches()` (GROUP BY nad
  syrovým sloupcem) a **náš vlastní `HistoricalValidationScanner::classifySource()`**,
  který neprázdnou hodnotu mapuje na zdroj `ai_pdf`.
* **UI:** dropdown „dohledat import" zobrazuje jen **datum a počet dokladů** — žádné id,
  žádný typ dávky. Limit je 20 posledních.

**Proč nesdílet** (tři důvody, každý sám o sobě dostačující):

1. **Má jinou sémantiku.** Migrace i commit upstreamu ho definují jako „označení dávky
   hromadného **AI** importu". To je přesně případ, na který mířila podmínka ze zadání
   („pokud má jinou sémantiku, přidej vlastní sloupec").
2. **Rozbil by naše vlastní měření.** `classifySource()` by každý doklad z dávkového
   importu ohlásil jako `ai_pdf` — a to je právě ten report, podle kterého se rozhoduje
   o vynucení validace.
3. **Poškodil by upstreamovou funkci uživateli.** Do jednoho dropdownu bez rozlišovače
   by se promíchaly dva druhy dávek a naše dávky by kvůli `limit = 20` **vytlačovaly**
   uživatelovy AI dávky z jeho vlastního seznamu.

Navíc: `setImportBatchId()` dělá `substr(trim($id), 0, 32)` — **tiché oříznutí** bez chyby,
a kolace `utf8mb4_unicode_ci` je case-insensitive. Obojí je latentní zdroj kolizí.

**Cena rozhodnutí:** vlastní filtr „dohledat dávku" si musíme napsat sami (patří k UI
commitu). Za to dostáváme referenční integritu (FK), nulové riziko smíchání entit
a nulový zásah do upstream kódu.
