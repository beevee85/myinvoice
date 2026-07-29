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

## 2. Jak číst výsledky

Log je uvnitř kontejneru v `/data/log/app-YYYY-MM-DD.log` (rotace po dnech).
Každý nález je jeden řádek úrovně `WARNING` s prefixem `shadow-validation:`.

**Kolik nálezů celkem** (za posledních 30 dnů rotace):

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

**Rozpad podle toho, CO neprošlo** (které pole validaci nesplnilo):

```bash
docker compose -f /opt/myinvoice/docker-compose.yml exec -T app \
  sh -c "grep -h 'shadow-validation:' /data/log/app-*.log \
         | grep -o '\"fields\":\[[^]]*\]' | sort | uniq -c | sort -rn"
```

**Jmenovatel** (kolik dokladů za totéž období vůbec vzniklo) — z databáze, ne z logu:

```sql
SELECT COUNT(*) FROM purchase_invoices
 WHERE created_at >= CURDATE() - INTERVAL 30 DAY;
```

Podíl nálezů k tomuhle číslu je hledané „kolik procent dnešních dokladů by validací
neprošlo". Není to přesné na doklad (log drží 30 rotací, DB všechno), ale na rozhodnutí
to stačí.

---

## 3. Jak se podle toho rozhodnout

| výsledek | co to znamená | doporučení |
|---|---|---|
| 0 nálezů | importy už dnes tvoří data, která by ruční validací prošla | vynutit validaci i na importy — je to zadarmo |
| jednotky % a vždy táž pole | systematická odchylka jedné cesty | opravit tu cestu, pak vynutit |
| desítky % | validace je na reálná data přísná, nebo importy tvoří polotovary záměrně | **nevynucovat**; nejdřív rozhodnout, která pravidla pro importy dávají smysl |

Pozor na falešně klidný výsledek: nález vzniká jen při **zakládání** dokladu. Když se
delší dobu nic neimportuje, prázdný log neznamená „vše v pořádku", ale „nebylo co měřit".
Vždy si k číslu vezmi i jmenovatel.

---

## 4. Až se bude vynucovat

Stínový režim je záměrně **jen zápis do logu** — žádná tabulka, žádná migrace, nic,
co by se muselo odinstalovávat. Vynucení bude samostatné rozhodnutí a samostatná změna:
`recordShadowValidation()` se doplní o režim, který místo logu vyhodí výjimku, a volající
ji přeloží na chybu importu.

Do té doby platí: **nálezy nemají žádný vliv na zápis.** Doklad vznikne stejně jako dřív.

Selhání samotného záznamu (rozbitý log, plný disk) zápis dokladu neshodí — telemetrie
není důležitější než data. Takový případ se zaloguje jako `ERROR` se stejným prefixem.
