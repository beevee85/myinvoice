# Stínová validace importních cest (V76)

Krátký provozní návod: co to je, jak z toho dostat číslo a co s ním pak udělat.

---

## 1. K čemu to je

`PurchaseInvoiceValidation::invoice()` dosud hlídala **jen ruční pořízení**. Importní
cesty (AI, ISDOC, scan-inbox) ji nikdy nevolaly a mají vlastní, slabší kontroly — proto
v datech mohly vzniknout doklady, které by ručním editorem neprošly.

Vynutit validaci na importy naslepo je riskantní: kdyby jí neprocházel běžný provoz,
zablokuje se import a účetní zůstane stát. Proto mezikrok — **stínový režim**:
validace se spustí, ale **nic neodmítne**, jen zapíše nález do logu.

Až budeme vědět, kolik dokladů a proč by neprošlo, dá se rozhodnout kvalifikovaně.

Zapojení: `PurchaseInvoiceWriteService::recordShadowValidation()`. Týká se všech cest,
které přes tu službu zapisují — dnes **ruční pořízení, AI import a ISDOC/scan-inbox**.
Zbylé tři legacy cesty (banka, iDoklad, Fakturoid) přes ni ještě nejdou, viz seznam
`NOT_YET_CONVERGED` v `api/tests/Architecture/PurchaseInvoiceCreationPathsTest.php`.

---

## 2. Co v logu JE a co tam nikdy nebude

Log je uvnitř kontejneru v `/data/log/app-YYYY-MM-DD.log` (rotace po dnech).
Každý nález je jeden řádek úrovně `WARNING` s prefixem `shadow-validation:`.

Záznam nese **výhradně metadata**: identifikátor pravidla (cesta k poli s vynulovaným
indexem řádku, např. `items.*.quantity`), severity, typ dokladu, zdroj zápisu, tenanta
a **id dokladu pro dohledání**. Nikdy tam nejsou částky, IČO, názvy firem, jména,
čísla dokladů ani volné texty — logy se rotují, kopírují a někdy posílají do agregátorů,
takže je to jiná bezpečnostní zóna než databáze. Hlídá to test
`ShadowValidationTest::testLogNeverContainsDocumentContent`.

Konkrétní hodnoty se dohledávají přes `purchase_invoice_id` v databázi, kam patří.

## 3. Jak číst výsledky

**Kolik nálezů celkem** (za dobu, co sahá rotace logu):

```bash
docker compose -f /opt/myinvoice/docker-compose.yml exec -T app \
  sh -c "grep -h 'shadow-validation:' /data/log/app-*.log | wc -l"
```

**Rozpad podle cesty** — tohle je to podstatné číslo, protože rozhodnutí nemusí být
pro všechny cesty stejné:

```bash
docker compose -f /opt/myinvoice/docker-compose.yml exec -T app \
  sh -c "grep -h 'shadow-validation:' /data/log/app-*.log \
         | grep -o '\"source\":\"[a-z_]*\"' | sort | uniq -c | sort -rn"
```

**Rozpad podle toho, KTERÉ pravidlo neprošlo:**

```bash
docker compose -f /opt/myinvoice/docker-compose.yml exec -T app \
  sh -c "grep -h 'shadow-validation:' /data/log/app-*.log \
         | grep -o '\"rules\":\[[^]]*\]' | sort | uniq -c | sort -rn"
```

## 4. Jmenovatel — kolik dokladů za totéž období vůbec vzniklo

Bez něj je čitatel k ničemu. Zdroj zápisu se na dokladu neukládá, takže se odvozuje
heuristikou ze stop, které jednotlivé cesty zanechávají:

```sql
SELECT
  CASE
    WHEN idoklad_id                IS NOT NULL         THEN 'idoklad'
    WHEN fakturoid_id              IS NOT NULL         THEN 'fakturoid'
    WHEN source_format             IS NOT NULL         THEN 'isdoc'
    WHEN import_batch_id           IS NOT NULL         THEN 'ai_pdf'
    WHEN vendor_invoice_number LIKE 'BANK-%'           THEN 'banka'
    WHEN pdf_path                  IS NOT NULL         THEN 'ai_pdf (jednotlivě)'
    ELSE 'ruční'
  END                                    AS zdroj,
  COUNT(*)                               AS dokladu,
  MIN(created_at)                        AS od,
  MAX(created_at)                        AS do
FROM purchase_invoices
WHERE created_at >= CURDATE() - INTERVAL 30 DAY
  AND deleted_at IS NULL
GROUP BY zdroj
ORDER BY dokladu DESC;
```

Spuštění proti běžící instanci:

```bash
docker compose -f /opt/myinvoice/docker-compose.yml exec -T db \
  sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" myinvoice -t' < dotaz.sql
```

**Limity heuristiky** (ať se čísla nečtou přísněji, než unesou): `source_format` se plní
jen u ISDOC vytaženého z PDF/A-3 a u `.isdocx`, samotný `.isdoc` ho nemá; `import_batch_id`
označuje jen dávkový AI import, ne jednotlivý; ruční doklad s nahraným PDF je od AI importu
k nerozeznání. Na poměr „kolik procent by neprošlo" to stačí, na audit ne.

> **Rychlejší cesta k číslům:** čekat na nové importy není nutné — `api/bin/shadow-validate-existing.php`
> pustí tutéž validaci nad **historií** v databázi (read-only) a dá distribuci nálezů
> hned. Viz `docs/batch-import/SHADOW-BASELINE.md`.

---

## 5. Jak se podle toho rozhodnout

| výsledek | co to znamená | doporučení |
|---|---|---|
| 0 nálezů | importy už dnes tvoří data, která by ruční validací prošla | vynutit validaci i na importy — je to zadarmo |
| jednotky % a vždy táž pole | systematická odchylka jedné cesty | opravit tu cestu, pak vynutit |
| desítky % | validace je na reálná data přísná, nebo importy tvoří polotovary záměrně | **nevynucovat**; nejdřív rozhodnout, která pravidla pro importy dávají smysl |

Pozor na falešně klidný výsledek: nález vzniká jen při **zakládání** dokladu. Když se
delší dobu nic neimportuje, prázdný log neznamená „vše v pořádku", ale „nebylo co měřit".
Vždy si k číslu vezmi i jmenovatel.

---

## 6. Až se bude vynucovat

Stínový režim je záměrně **jen zápis do logu** — žádná tabulka, žádná migrace, nic,
co by se muselo odinstalovávat. Vynucení bude samostatné rozhodnutí a samostatná změna:
`recordShadowValidation()` se doplní o režim, který místo logu vyhodí výjimku, a volající
ji přeloží na chybu importu.

Do té doby platí: **nálezy nemají žádný vliv na zápis.** Doklad vznikne stejně jako dřív.

Selhání samotného záznamu (rozbitý log, plný disk) zápis dokladu neshodí — telemetrie
není důležitější než data. Takový případ se zaloguje jako `ERROR` se stejným prefixem.
