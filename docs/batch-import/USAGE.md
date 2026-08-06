# Dávkový import přijatých dokladů — provozní stav a postup

> **Stav k 6. 8. 2026 (večer).** Tenhle dokument popisuje, co je HOTOVÉ a co
> NENÍ. `PLAN.md` je návrh; tohle je skutečnost. Kde se rozejdou, platí tenhle
> soubor, protože každé tvrzení níž je ověřené proti kódu, ne proti záměru.
>
> Ranní verze tohoto souboru začínala větou „featura dnes není použitelná od
> začátku do konce". **To už neplatí** — chybějící kroky (založení dávky,
> balíček, apply, UI) vznikly v commitech 17–20 téhož dne.

## Co featura dělá

1. Uživatel nahraje dávku PDF (menu **Nákup → Dávkový import**).
2. Aplikace vrátí **token dávky** (zobrazí se PRÁVĚ JEDNOU) a **balíček** —
   ZIP s PDF pod jmény `<sha256>.pdf`, `manifest.json` a `prompt.txt`.
3. Extrakci provede lokální nástroj **mimo aplikaci** (proto „přes
   předplatné" — neplatí se API kredity). Výstupem je `results.json`.
4. Uživatel vloží token + `results.json` na stránce dávky.
5. **Server výsledky ověří NEZÁVISLE** — nevěří ani jednomu číslu, všechno
   si přepočítá (V4–V81b; katalog viz `RULE-STATUS.tsv`).
6. Uživatel schvaluje **po dokladech**: každé „Schválit → koncept" založí
   jeden KONCEPT přes `PurchaseInvoiceWriteService` — stejnou cestou jako
   ruční pořízení. Tlačítko „přijmout vše" neexistuje záměrně.

## Stav dílů

| Díl | Stav | Zapojeno? |
|---|---|---|
| `BatchBuilder` — balíček, magic bytes, all-or-nothing | hotový | ano — `CreateBatchAction` |
| `PromptBuilder` — deterministický prompt | hotový | ano — `GetBatchPackageAction` |
| `StrictJson` / `IdentityRules` / `AmountRules` | hotové | ano |
| `QrCheck` + `SpaydParser` (V33–V35) | hotové | ano — běží ve stavu `unavailable`, viz níže |
| `ResultsValidator` + `ResultsIntake` | hotové | ano |
| `BatchApply` — vznik konceptů | hotový | ano — `ApplyResultAction` |
| Review UI + seznam dávek + menu | hotové | ano |

## Co pořád chybí

**Dekodér QR rastru.** V33–V35 jsou zapojené do validátoru, ale v systému
není nic, co by z PDF vytáhlo a dekódovalo QR kód (A2: volitelná závislost
provozovatele). Kontrola proto běží ve stavu `unavailable` a každý doklad
dostane INFO „QR platba nebyla ověřena" — poctivější než ticho, ale pořád
to není kontrola. V34/V35 začnou porovnávat dnem, kdy dekodér vznikne;
V33 navíc potřebuje, aby schéma results.json neslo `payment.iban`, což dnes
nenese.

**Vazby záloh (§ 37a).** `linked_documents` z extrakce apply nezakládá —
jen o nich napíše varování na koncept. Párování DDKPZ je ruční krok.

## Zapnutí

Příznak v `cfg.php`:

```php
'purchase_invoice' => [
    'batch_import' => [
        'enabled' => true,
        // volitelné limity (defaulty): max_files 50, max_file_bytes 20 MiB,
        // max_batch_bytes 200 MiB, token_ttl_minutes 120,
        // max_norm_json_bytes 512 KiB
    ],
],
```

**Vypnutý příznak znamená 404, ne 403** — routy se vůbec neregistrují.
Frontend nemá vlastní konfigurační kanál: menu detekuje featuru GETem
seznamu (404 = položka se nenabízí). Vypnutá featura je neviditelná
v odpovědích i v navigaci.

## Token dávky

- Vzniká při založení dávky, zobrazí se **právě jednou**; v DB je od první
  chvíle jen SHA-256. Ztracený token = nová dávka. To je záměr — obnovitelný
  token by přestal být druhým faktorem.
- Váže **odeslání výsledků** (`X-Batch-Token` hlavičkou, ne v těle — tělo
  loguje kdeco). Schvalování konceptů token nežádá: schvaluje člověk
  v přihlášené session a jeho identita jde do `created_by`.
- V balíčku token NENÍ — balíček smí ležet na disku a sdílet se s nástrojem.
- Expirace: `token_ttl_minutes` (default 120 min).

## Čemu server nevěří (výběr)

- **`results.json` jako celku** — syrový text, duplicitní klíče (V80),
  párování na manifest z DATABÁZE (V4/V5/V6), částky v halířích bez floatu.
- **Sazbě DPH z dokladu** — hledá se PŘESNÁ shoda s číselníkem; nenalezená
  sazba je chyba apply (422), ne tichých 0 %.
- **Duplicitám** — (dodavatel, číslo, datum) se kontroluje před zápisem → 409.
- **Totálům z papíru** — koncept se počítá z položek; rozdíl proti papíru se
  PŘIZNÁ varováním na konceptu (`extraction_warning`), nikdy tiše nepřepíše.
- **Manifestu balíčku** — rekonstrukce z DB se ověřuje proti hashi z doby
  založení; dávka změněná po založení balíček nevydá.

## Co se z aplikace nikdy nedozvíte

- **Obsah dokladů.** `GET /batch-import/{id}` nevrací `raw_json` ani
  `normalized_json`; nálezy jsou redigované (echo hodnot jen z uzavřeného
  seznamu polí).
- **Jméno souboru od uživatele na disku.** Ukládá se `<sha256>.pdf`.
- **Hash tokenu.** Je to tajemství, ne stav.
- **Do logů** jde počet a id, nikdy jména souborů ani token.

## Životní cyklus dávky

```
pending ──(výsledky ok)──► validating ──(1. apply)──► applying ──(poslední apply)──► done
   │                                                                                  │
   └──(výsledky vadné / opuštěná)──► failed                                 applied_at + purge raw_json
```

- Koncept: `varsymbol` (naše interní číslo) zůstává NULL až do
  draft→received; VS dodavatele jde do `payment_variable_symbol`.
- Vazba koncept ↔ dávka: `purchase_invoices.purchase_import_batch_id`
  (forkový sloupec) a `purchase_import_batch_results.purchase_invoice_id`.
- Retence (V79b): terminální stav → `raw_json` pryč; na aplikovaných řádcích
  zůstává `normalized_json` (≤ `max_norm_json_bytes`; nadlimitní doklad se
  uloží bez obsahu s přiznáním). Opuštěné dávky uklízí sweeper.

## Testy

```bash
cd api && php vendor/bin/phpunit --filter 'BatchImport|PurchaseBatchImport|BatchBuilder|BatchApply|ResultsIntake'
```

Integrační část potřebuje účet `myinvoice_test` a databázi
`myinvoice_test_batch` (`cfg.php`, `db`). Fixtury dodavatelů jsou záměrně
bez IČO/DIČ — s nimi by `ClientResolver` chodil do ARES/VIES a testy by
stály na síti.

Stav pravidel katalogu hlídá `api/tests/Architecture/BatchImportRuleCatalogTest.php`,
pokrytí OpenAPI `BatchImportOpenApiCoverageTest.php` — obě pojistky jsou
červené, když se skutečnost rozejde s deklarací.
