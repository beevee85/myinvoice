# Dávkový import přijatých dokladů — provozní stav a postup

> **Stav k 6. 8. 2026.** Tenhle dokument popisuje, co je HOTOVÉ a co NENÍ.
> `PLAN.md` je návrh; tohle je skutečnost. Kde se rozejdou, platí tenhle soubor,
> protože každé tvrzení níž je ověřené proti kódu, ne proti záměru.

## Nejdůležitější věta

**Featura dnes NENÍ použitelná od začátku do konce.** Chybí krok, kterým se dávka
zakládá, a krok, kterým z ověřených výsledků vzniknou koncepty. Prostředek —
validace a příjem `results.json` — hotový je.

Kdo si tohle nepřečte a spolehne se na `PLAN.md`, bude hledat tlačítko, které
neexistuje.

## Co featura dělá (záměr)

1. Uživatel nahraje dávku PDF přijatých dokladů.
2. Aplikace z nich udělá balíček + prompt.
3. Extrakci provede lokální nástroj **mimo aplikaci** (proto „přes předplatné" —
   neplatí se API kredity).
4. Nástroj vrátí `results.json`.
5. **Server si ho ověří NEZÁVISLE.** Nevěří ani jednomu číslu — všechno si
   přepočítá sám.
6. Vzniknou **jen koncepty** ke schválení člověkem.

Bod 5 je celý smysl. `results.json` je nedůvěryhodný vstup: přišel od nástroje,
který server neřídí, a nese obsah cizích dokladů.

## Co je hotové

| Díl | Stav | Zapojeno? |
|---|---|---|
| `BatchBuilder` — sestavení balíčku, kontrola magic bytes, all-or-nothing | hotový, otestovaný | **NE — nikdo ho nevolá** |
| `StrictJson` — duplicitní klíče, `__proto__`, velká čísla, root | hotový | ano |
| `IdentityRules` — strany, IČO mod-11, čísla, datumy | hotový | ano |
| `AmountRules` — částky v haléřích, bez floatu | hotový | ano |
| `ResultsValidator` — orchestrace, párování na manifest | hotový | ano |
| `ResultsIntake` — příjem, uložení, označení dávky | hotový | ano |
| `SpaydParser` + `QrCheck` — QR platba | hotový, otestovaný | **NE — nikdo je nevolá** |
| `GET /batch-import/{id}` + `POST /{id}/results` | hotové | ano (za příznakem) |
| Review UI `web/src/pages/purchase-invoices/BatchImport.vue` | hotové | ano |

## Co chybí

1. **Založení dávky.** `BatchBuilder` nemá akci ani CLI. Dávka dnes vznikne jen
   přímým zápisem do databáze — což není postup, jen konstatování.
2. **Vznik konceptů.** `ResultsIntake` doklad ZÁMĚRNĚ nezakládá; zápis má jít přes
   `PurchaseInvoiceWriteService`, tedy stejnou cestou jako ruční pořízení. Ten krok
   zatím není napsaný. Sloupec `purchase_invoice_id` je připravený a prázdný.
3. **Zapojení QR kontroly.** `ResultsValidator` si injektuje `StrictJson`,
   `IdentityRules` a `AmountRules`. `QrCheck` ne — V33–V35 proto na dávkové cestě
   nikdy nedoběhnou. V katalogu jsou vedené jako nevynucené, ne jako hotové.
4. **Položka v menu.** Stránka je per-dávka a bez `id` nemá kam vést. Statický
   odkaz by vedl nikam, tak tam žádný není.

## Zapnutí

Příznak v `cfg.php`:

```php
'purchase_invoice' => [
    'batch_import' => [
        'enabled' => true,
    ],
],
```

**Vypnutý příznak znamená 404, ne 403.** Routy se vůbec neregistrují. 403 by
prozradilo, že featura existuje a je jen vypnutá — to je informace, kterou
nepřihlášený dostat nemá. „Neregistrovat vůbec" je silnější než „registrovat
a odmítat".

## Postup, až budou chybějící kroky hotové

1. Nahrát PDF → vznikne dávka a **token dávky**.
2. Stáhnout balíček, pustit lokální extrakci, dostat `results.json`.
3. Na `/purchase-invoices/batch-import/{id}` vložit token a obsah `results.json`.
4. Projít nálezy. Doklady s FAILem jsou nahoře.
5. Schválit, co je v pořádku → vzniknou koncepty.

**Tlačítko „přijmout vše" tam záměrně není.** U dávky, kde má doklad FAIL, by se
kliklo dřív, než by si to kdokoli přečetl.

## Co se z aplikace nikdy nedozvíte

- **Obsah dokladů.** `GET /batch-import/{id}` nevrací `raw_json` ani
  `normalized_json`. UI potřebuje nálezy, ne obsah. Kdyby je vracel, vzniklo by
  druhé místo, odkud osobní údaje odcházejí — a retence na serveru by ztratila
  smysl, protože co odešlo, se nedá odmazat.
- **Hodnoty v nálezech.** `message` je redigovaný; echo hodnoty je povolený jen
  u uzavřeného seznamu polí.
- **Jméno souboru od uživatele.** Na disk se ukládá jako `<sha256>.pdf`.
- **Hash tokenu.** Je to tajemství, ne stav.

## Vlastnosti, které vypadají jako detail a nejsou

**Tělo `POST /results` se čte jako syrový text.** Ne přes parsovaný JSON.
Duplicitní klíče by parser zahodil dřív, než je kontrola uvidí — a pravidlo V80
je postavené právě na jejich odhalení. Proto ani klient nesmí tělo protahovat
přes `JSON.parse` + `JSON.stringify`; ve `web/src/api/batchImport.ts` to zajišťuje
`transformRequest: [(body) => body]`. Sanitizace na klientovi by tuhle kontrolu
tiše vypnula.

**Token jde hlavičkou `X-Batch-Token`.** V těle by ho zachytil každý log requestů.
Je to druhý faktor, ne přihlášení: endpoint je za běžnou autentizací a token jen
váže požadavek na konkrétní dávku.

**Sweeper opuštěných dávek běží při odeslání výsledků**, ne z cronu. Nezávisí to
na plánovači, ale znamená to, že v tichém období se neuklidí nic.

## Testy

```bash
cd api && php vendor/bin/phpunit --filter 'BatchImport|PurchaseBatchImport'
```

Integrační část potřebuje účet `myinvoice_test` a databázi `myinvoice_test_batch`
(`cfg.php`, `db`). Bez nich se testy samy přeskočí — přeskočení je vidět,
tichý průchod ne.

Stav pravidel katalogu hlídá `api/tests/Architecture/BatchImportRuleCatalogTest.php`;
co který stav znamená, je v hlavičce `RULE-STATUS.tsv`.
