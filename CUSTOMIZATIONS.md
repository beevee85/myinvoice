# CUSTOMIZATIONS.md — evidence vlastních úprav této instalace

Instalace: `faktury.betka.eu`, VPS, `/opt/myinvoice`. Pravidla práce viz `CLAUDE.md` (gitignored, jen na serveru).

Po každém updatu z upstreamu projdi celý seznam níže a ověř, že žádná úprava tiše nevypadla.

## Plán: co nabídnout do oficiální větve (upstream `radekhulan/myinvoice`)

Uživatel chce tyto fork funkce navrhnout autorovi. Detailní checklist „před odesláním upstreamu" je vždy u příslušné sekce níže.

| Funkce | Sekce | Stav | Hlavní překážka před PR |
|---|---|---|---|
| Opravy DPH výkazů + zámek dokladu + EPO identifikace + CZ-NACE | 2026-07-27/28 | ✅ **PŘIJATO** — PR #245 mergnut, vydáno v **v4.52.0** | hotovo — bloky níže přeznačeny na PŘIJATO |
| Koš + tvrdé mazání dokladů (0905) | 2026-07-28 | čeká na ověření v provozu | breaking DELETE (nutná zpětná kompatibilita), fork-only DDKPZ vazby v policy, přečíslovat migraci |
| Omezení uživatele na vybrané firmy (0900) | 2026-07-02 FÁZE 2 | ✅ **PŘEVZATO JINAK** — PR #247 zavřen, autor vydal vlastní implementaci (`user_suppliers` + role per firmu) ve **v4.52.0**; naše verze odstraněna migrací 0908 | hotovo |
| Daňový doklad k přijaté záloze (DDKPZ) + § 37a na přijaté straně (0904, 0906–0911) | 2026-07-28 (tři dávky) | **kandidát — ODLOŽENO na později** (rozhodnutí 28. 7. 2026: nejdřív provozní ověření, ideálně po podání KH za 05–07/2026; pořadí: nejdřív koš, pak DDKPZ, ať se nemusí odstřihávat) | přečíslovat migrace 0904/0906 do upstream řady; oddělit od fork-only koše (DocumentTrashPolicy, TrashGuard v settlement akcích) a od sazby CZ-NA, pokud ji upstream nechce; doplnit kapitolu manuálu + openapi (endpointy settlement-doc-candidates / final-candidates / link-settlement-doc) |

Ověřeno 2026-07-28 proti `upstream/master` (4.51.0, migrace do 0147): ani jednu z těchto funkcí upstream nemá.

---

## 2026-07-29 — Dávkový import: evidence k V43, tabulka divergencí, A3 doloženo

**Charakter: FORK DOKUMENTACE** — bez zásahu do kódu i testů.

**1. Evidence k deltě V43** (`docs/batch-import/PLAN.md`, sekce 13.1–13.2). Rozvolnění
pravidla je nejsnazší způsob, jak „vyzelenit" baseline, takže u něj musí být doložený
motivující doklad i důkaz, že se rozvolnilo jen tam, kde mělo:
* anonymizovaný popis dokladu (dobropis, 5 řádků, 4 popisné, součty v pořádku, ověřeno
  že nejde o řádky § 37a ani zaokrouhlovací),
* **mutační důkaz**: rozšíření V43b na každé nulové množství shodí 3 testy (mimo jiné
  `testZeroQuantityWithPriceStaysAFinding`), vypnutí V43d shodí
  `testDocumentMadeOnlyOfTextLinesIsAFinding`. Řádek, který tvrdí cenu bez množství,
  dál neprojde.

**2. Tabulka známých divergencí** (sekce 14) — analytický skener vs. produkční vynucení,
řádek za řádkem, s uvedením commitu, kde se má srovnat. Slouží jako checklist pro commit
s doménovým validátorem. Pravidlo: každá další změna v analytické vrstvě musí do tabulky
přibýt spolu s mutačním důkazem.

**3. A3 DOLOŽENO** (sekce 15) — `purchase_invoices.import_batch_id`:
`VARCHAR(32) NULL`, **bez FK a bez UNIQUE**, tabulka dávek v DB neexistuje. Původ **čistý
upstream** (commit `9120ffe1`, 23. 7. 2026, `git diff upstream/master HEAD` prázdný).
Hodnotu generuje **frontend** (UUIDv4 bez pomlček), plní ji výhradně `setImportBatchId()`
UPDATEm, nikdy INSERT.

**ROZHODNUTÍ: nesdílet, přidat vlastní `purchase_import_batch_id` s cizím klíčem.**
Tři důvody: (a) sloupec má jinou sémantiku — upstream ho definuje jako označení dávky
**AI** importu; (b) sdílení by rozbilo náš vlastní `classifySource()`, který neprázdnou
hodnotu mapuje na zdroj `ai_pdf` — a to je právě report, podle kterého se rozhoduje
o vynucení validace; (c) poškodilo by to upstreamovou funkci uživateli — dropdown
„dohledat import" ukazuje jen datum a počet, takže dva druhy dávek by v něm byly
nerozlišitelné, a limit 20 by naše dávky vytlačovaly jeho AI dávky.

**Bezpečnostní úklid:** ověřeno, že žádná záloha není dosažitelná přes web — nginx
blokuje `/storage`, `/private`, `/db`, `/log` i `*.sql` (`docker/nginx.conf:104,107`)
a z webrootu nevede symlink do `/data`.

**⚠️ INCIDENT:** při aplikaci retence na nahromaděné zálohy v `/root` (39 souborů,
228 MB, čtyři týdny kompletních kopií účetnictví) selhalo v shellu porovnání jmen —
seznam k ponechání obsahoval nové řádky, takže se `case` nikdy netrefil a **shred smazal
všech 39 souborů včetně čtyř, které měly zůstat**. Nevratné. Produkční databáze i datové
volume jsou nedotčené (ověřeno: 62 přijatých faktur, 13 vydaných, 14 klientů, `/data`
56,8 MB, aplikace HTTP 200) — ztratily se body obnovy z předchozích session, ne data.
Okamžitě vytvořena nová záloha
`/root/backup-myinvoice-db-2026-07-29-1905-pred-commit7.sql` (1,6 MB, 93 tabulek,
33 INSERTů, `chmod 600`, integrita ověřena).

**Poučení:** hromadné nevratné operace nad soubory nedělat porovnáváním řetězců
v shellu. Použít `find -newer` / explicitní seznam po jednom, nebo napřed `--dry-run`
výpis a teprve po kontrole mazat.


---

## 2026-07-29 — Dávkový import: opravy telemetrie + delta katalogu V43

**Charakter: FORK — oprava bezpečnosti telemetrie, uzavření mezery v měření
a přepis pravidla V43 podle zjištění z historie.**

### 1. Do logu telemetrie nesmí téct obsah dokladu
Záznam nesl číslo dokladu a datum vystavení. Logy se rotují, kopírují a někdy posílají do
agregátorů — jiná bezpečnostní zóna než databáze. Nově jen metadata: identifikátor
pravidla (`items.3.quantity` → `items.*.quantity`), severity, typ dokladu, zdroj, tenant
a **id dokladu pro dohledání**. Texty hlášek vypuštěny. Kvůli id se záznam přesunul za
zápis. Hlídá `testLogNeverContainsDocumentContent` (zapíše doklad s nezaměnitelnými
hodnotami a ověří, že se ani jedna neobjeví v serializovaném kontextu).

### 2. Mezera: doklady, které zápisem neprošly, telemetrii nezanechávaly
Posun záznamu za zápis vyřadil z měření právě ty nejzajímavější případy. Nově se
zaznamenávají i ony — `purchase_invoice_id: null`, `write_failed: true`, a když je
k dispozici, i `import_batch_id` jako jediná stopa k dohledání.

### 3. Odkud se nálezy počítají
**Nad DTO PŘED zápisem**, ne nad tím, co se přečte z databáze zpět. Jsou to dvě různá
měření (to druhé by chytalo i zaokrouhlení a normalizaci při ukládání). Zapsáno
natvrdo do `SHADOW-VALIDATION.md`, ať to za rok nikdo nezamění.

### 4. Runtime kontrola offline režimu
Ke statické analýze zdrojáku přibyl `NetworkBlockingStreamWrapper` — skener běží
s odstřiženými `http`/`https` streamy. Statickou kontrolu obejde refaktor, který volání
schová do helperu; tohle projde skutečným během. Test nejdřív ověří, že je zámek
ozbrojený, jinak by nic nedokazoval. Přiznané omezení: streamy nechytí ext-curl, proto
obě kontroly vedle sebe.

### 5. DELTA KATALOGU — V43 přepsáno (V43, V43b–V43e)
Retrospektivní měření našlo jediný `real_mismatch`: zaplacený dobropis se **správnými
součty**, jehož část řádků je popisná. Ověřeno, že to nejsou systémové řádky § 37a ani
zaokrouhlovací řádek. Chyba nebyla v datech, ale v pravidle — původní V43 („quantity > 0")
byla příliš hrubá. Rozhodující není nulové množství, ale **jestli řádek tvrdí, že něco
stojí**. Nové znění v `docs/batch-import/PLAN.md`, sekce 13, včetně dopadu na prompt.

`HistoricalValidationScanner` už podle V43b klasifikuje (analýza), produkční
`InvoiceAmountPolicy` se **nemění** — jeho přepis je změna chování ruční cesty a patří
ke commitu s doménovým validátorem.

**Nové měření: 96,8 % prošlo, `real_mismatch` = 0**, zbývají dva prázdné popisy položek
(`legacy_gap`) a 4 popisné řádky uznané jako legitimní.

**Vynucení se ještě nezapíná** — ne kvůli číslům, ale protože 62 dokladů neukázalo dost
různých režimů selhání. Rozhodnutí patří až za dokončený doménový validátor.

**Hygiena:** dump produkční DB po vytvoření klonu smazán (`shred`), klon po doměření
zahozen. `/root` má 0700, ale leží tam 27 dumpů z dřívějších session (1,6 MB) — úklid
na uživateli, nejsou z této práce.

**Testy:** 2 170 → **2 177 zelených**, asercí 7 443 → **7 463**.

---

## 2026-07-29 — Dávkový import, Commit 6b: retrospektivní stínová validace nad historií

**Charakter: FORK ANALYTICKÝ NÁSTROJ — výhradně čtení.** Výsledek měření:
`docs/batch-import/SHADOW-BASELINE.md`.

**Proč:** stínový režim měří jen NOVĚ zakládané doklady, takže na rozhodnutí „vynutit
validaci i na importní cesty?" by se čekalo týdny a rozhodovalo by se na hrstce dokladů.
Historie je přitom v databázi celá.

**`api/src/Service/Validation/HistoricalValidationScanner.php`** + tenké CLI
`api/bin/shadow-validate-existing.php`: rekonstruuje DTO z uložených dokladů a položek,
pustí nad ním tutéž `PurchaseInvoiceValidation::invoice()` a agreguje nálezy podle
pravidla, zdroje zápisu, roku a typu dokladu. Výstup tabulkou nebo `--json`.

**Tvrdá pravidla, všechna otestovaná:**
* **nulový zápis** — ani do databáze, ani do provozní telemetrie (historická dávka by
  jinak zašuměla měření nových importů). Test porovnává otisk databáze (počty řádků,
  `MAX(updated_at)`, součet částek) před a po běhu;
* **offline** — statický test nad zdrojákem *bez komentářů* hlídá, že tam není ARES,
  VIES, CRPDPH, ČNB, curl ani write service. (Kontrola nad celým souborem napoprvé
  spadla na vlastním docblocku, kde ta slova stojí v popisu pravidla.);
* **jen proti klonu** — `TestDatabaseGuard::DEFAULT_PATTERN` rozšířen o segment `clone`
  (rozšíření V83), skript proti ostré databázi odmítne start.

**Dvě kategorie nálezů,** protože znamenají něco úplně jiného: `legacy_gap` (údaj se
tehdy nesbíral — úklid historie) vs. `real_mismatch` (uložené hodnoty si protiřečí —
tohle rozhoduje o vynucení). Neznámé pravidlo spadne konzervativně do `real_mismatch`.

**VÝSLEDEK (62 dokladů produkce):** 95,2 % prošlo, 3 nálezy, z toho **jediný
`real_mismatch`** — zaplacený dobropis se správnými součty, jehož část řádků je popisná
(nulové množství i cena). Ověřeno, že to **nejsou** systémové řádky § 37a ani
zaokrouhlovací řádek. Vynucení pravidla „množství ≠ 0" by tedy dnes odmítlo účetně
bezvadný doklad — to je konkrétní věc k rozhodnutí, ne obecné riziko.

**Testy:** 2 148 → **2 170 zelených**, asercí 7 394 → **7 443**.

---

## 2026-07-29 — Dávkový import, Commit 6: stínová validace importních cest (V76)

**Charakter: FORK — přidaná telemetrie, nulový vliv na zápis.** Provozní návod:
`docs/batch-import/SHADOW-VALIDATION.md`.

**Co to dělá:** `PurchaseInvoiceWriteService` nově spouští
`PurchaseInvoiceValidation::invoice()` v režimu **„jen zaznamenat"** — nic neodmítne,
jen při nálezu zapíše `WARNING` s prefixem `shadow-validation:` do aplikačního logu.
Týká se všech cest, které přes službu zapisují: **ruční pořízení, AI import,
ISDOC/scan-inbox**.

**Proč:** validace dosud hlídala jen ruční pořízení. Vynutit ji na importy naslepo by
mohlo zablokovat běžný provoz. Nejdřív potřebujeme vědět, **kolik dokladů a proč** by
neprošlo — teprve pak se dá rozhodnout, a klidně pro každou cestu jinak.

**Bez migrace a bez zásahu do auditu.** Zvažoval jsem vlastní tabulku (lepší agregace)
a `activity_log` (dohledatelnost u dokladu), ale první znamená migraci navíc a druhá
zašumí audit provozní telemetrií. Log je odinstalovatelný tím, že se přestane číst;
`docs/batch-import/SHADOW-VALIDATION.md` má hotové příkazy na rozpad podle cesty
i podle toho, které pole neprošlo, a upozornění, že jmenovatel je potřeba vzít z DB.

**Zdroj zápisu** se propisuje do nálezu (`manual` / `ai_pdf` / `isdoc`) — bez toho by
nešlo rozhodnout per cestu. Je to volitelný čtvrtý argument `createWithItems()`
s defaultem `unknown`, takže případný nový volající telemetrii nerozbije, jen se
projeví jako neoznačený.

**Selhání záznamu zápis neshodí** — telemetrie není důležitější než data; zaloguje se
jako `ERROR` se stejným prefixem.

**Zapojení loggeru bez rozbití testů:** `IsdocToPurchaseInvoiceMapper` dostal
`?LoggerInterface $logger = null` jako **poslední, volitelný** parametr — poziční
konstrukce v charakterizačních testech (5 argumentů) tím zůstala funkční a kontejner
si logger doplní autowiringem. `AiPdfExtractor` už vlastní logger měl.

**Nové testy:** `tests/Support/CollectingLogger.php` (PSR-3 do paměti) +
`tests/Integration/PurchaseInvoice/ShadowValidationTest.php` (5 testů): vadná data
projdou a zanechají nález, čistá data nezanechají nic, zdroj se propíše, **táž data
ruční cestou skončí 400 a v DB nevznikne nic** (to je jádro V76 — rozdíl mezi
„zaznamenat" a „vynutit" na stejném vstupu), a reálná ISDOC cesta nález skutečně vyvolá.

**Testy:** 2 141 → **2 146 zelených**, asercí 7 359 → **7 382**, skipped beze změny (31).
Charakterizační testy prošly opět beze změny.

---

## 2026-07-29 — Dávkový import, Commit 5: konvergence importních cest (V77)

**Charakter: FORK REFAKTORING — bez změny chování** (kromě toho, že převedené cesty
nově zapisují atomicky). Charakterizační testy prošly **beze změny testů**.

**Převedeno na `PurchaseInvoiceWriteService::createWithItems()`:**
* `Service/Import/AiPdfExtractor:713` — AI import PDF,
* `Service/Import/IsdocToPurchaseInvoiceMapper:129` — ISDOC/Pohoda, a tím i **scan-inbox
  a bundle import**, které na mapper delegují (vlastní kopii nikdy neměly).

U obou byl payload klíč `items` totožný s polem předávaným do `replaceItems`, takže
sekvence je krok za krokem shodná; přibyla jen transakce. Klíč `vat_overrides` payload
neobsahuje, takže krok 3 je no-op — rekapitulaci § 73 jim dodává `PurchaseVatRecapSeeder`
až po zápisu, jako dosud.

**Proč se služba konstruuje inline, ne přes konstruktor:** `AiPdfExtractor` má
14 parametrů a obě třídy se konstruují **pozičně i v testech**; patnáctý argument by
volání rozbil a vynutil úpravu charakterizačních testů, které mají zůstat nedotčené.
Služba je bezstavová a skládá se výhradně ze závislostí, které obě třídy už drží
(`Connection`, `PurchaseInvoiceRepository`, `PurchaseInvoiceCalculator`), takže je to
kompozice, ne skrytá závislost. Zdůvodnění je v docblocku obou metod `writer()`.

**Nový `api/tests/Architecture/PurchaseInvoiceCreationPathsTest.php` — ROHATKA (V77).**
Drží seznam dosud nepřevedených cest a vyžaduje, aby seděl PŘESNĚ: spadne, když přibude
nová cesta zakládající doklad mimo službu, když se převedená cesta vrátí k přímému zápisu
(typicky merge upstreamu), i když se cesta převede, ale zapomene vyškrtnout ze seznamu.
Seznam se smí jen zkracovat. Detekce filtruje na `PurchaseInvoiceRepository` v souboru
a na jméno property, aby nechytala vydané faktury (iDoklad i Fakturoid mají vedle sebe
`$this->invoices->createDraft()` pro vydanou stranu).

**ZBÝVAJÍ tři cesty** (v seznamu `NOT_YET_CONVERGED`) a **žádná z nich není mechanický
přesun** — ověřeno čtením, ne odhadem:

| cesta | překážka |
|---|---|
| `Action/Bank/BankStatementAction` | payload do `createDraft` **nemá klíč `items`** — položka se staví až po něm, protože potřebuje id nulové sazby z dotazu do `vat_rates`. `createWithItems()` by zapsal doklad bez položek. Převod = restrukturalizace. |
| `Service/Import/IdokladImportService` (2 místa) | vnitřní funkce dělá `createDraft` + **podmíněný** `replaceItems` a **přepočet nedělá** — ten volá až volající smyčka (`:547`) po nastavení `idoklad_id`. Převod by přepočet přidal dovnitř (běžel by dvakrát) a musel by se ve stejném kroku odebrat z volajícího. |
| `Service/Import/FakturoidImportService` | táž překážka, přepočet ve volajícím (`:389`). |

Plus `UpdatePurchaseInvoiceAction`, která doklad nezakládá, ale kroky 2–4 opisuje.

**Vědomá priorita:** jsou to dva jednorázové migrační importéry a generátor záskoku
z bankovního výpisu — ne cesty, kudy tečou běžné doklady. Ty (ruční pořízení, AI import,
ISDOC/scan-inbox) převedené **jsou**. Rohatka drží linii; převod zbytku patří k okamžiku,
kdy do těch importérů bude někdo sahat, a vždy s charakterizací napřed.

**Testy:** 2 138 → **2 141 zelených**, asercí 7 350 → **7 359**, skipped beze změny (31).
Ověřeno, že rohatka i test pořadí sekvence na simulovaných regresích skutečně padají.

---

## 2026-07-29 — Dávkový import, Commit 4: transakce nad zapisovací sekvencí (V75)

**Charakter: FORK — JEDINÁ ZMĚNA CHOVÁNÍ celého refaktoringu**, proto samostatný commit
(aby šla případná regrese najít bisectem).

**Co se změnilo:** `PurchaseInvoiceWriteService::createWithItems()` běží celý v jedné
transakci. Dřív jel každý krok v autocommitu a pád uprostřed nechal v databázi hlavičku
s nulovými součty a osiřelé položky bez přepočtu. Účetní doklad je buď celý, nebo žádný.

**Transakce je RE-ENTRANTNÍ** (`$started = !$pdo->inTransaction()`) — stejný vzor, jaký
už v repu má `PurchaseSettlementService` a `FinalFromProformaCreator`. Je to nutné:
`PurchaseSettlementService` (párování záloh, § 37a) volá `setVatOverrides` i `recompute`
uvnitř své transakce a vlastní `beginTransaction()` by ji rozbil. Vnořené volání proto
necommituje ani nerollbackuje — rozhodnutí nechává vlastníkovi transakce.

**Co transakce NEKRYJE:** překlopení `clients.is_vendor` na 1 dělá akce ještě před voláním
služby. Vědomé — `is_vendor` je vlastnost karty dodavatele, ne dokladu, a jeho překlopení
není škodlivé. Hlídá to test, kdyby se to někdy vtáhlo dovnitř.

**Nový test** `api/tests/Integration/PurchaseInvoice/PurchaseInvoiceWriteServiceTransactionTest.php`
(8 testů): chyba injektovaná do **každého** ze čtyř kroků zvlášť (dvojník deleguje na
skutečnou implementaci a shodí jen zvolený krok) + re-entrance (vnořený zápis se řídí
commitem/rollbackem volajícího) + kontrola, že po pádu nezůstane otevřená transakce.
Ověřeno, že bez transakce testy skutečně padají — u tří kroků, které stihnou zapsat.

**Charakterizační testy částečného zápisu zůstaly zelené beze změny** a je to správně:
míří o vrstvu níž, na holý repozitář, který transakci nemá a mít nebude. Takhle přímo do
něj zapisuje **šest ze sedmi** cest, takže dokud se nepřevedou, je to jejich reálné
chování. Skupina přejmenována `pre-transaction` → **`unconverged-write-path`**, ať název
neslibuje něco jiného.

**Testy:** 2 130 → **2 138 zelených**, asercí 7 323 → **7 350**, skipped beze změny (31).

**Jak ověřit po merge:** `vendor/bin/phpunit --filter PurchaseInvoiceWriteServiceTransaction`
(8 testů). Kdyby někdo `PurchaseSettlementService` přepsal tak, že přestane transakci
držet sám, spadnou re-entrance testy.

---

## 2026-07-29 — Dávkový import, Commit 3: PurchaseInvoiceWriteService (čistý přesun)

**Charakter: FORK REFAKTORING — bez změny chování.** Třetí commit featury „AI import přes
předplatné". Testy z Commitu 2 prošly **beze změny**, celá suita dala shodná čísla
(2 126 / 7 304 / 31 skipped) jako před zásahem.

**Co se změnilo:**
1. **`api/src/Service/Invoice/PurchaseInvoiceWriteService.php`** (nový) — sekvence
   `createDraft → replaceItems → setVatOverrides → recompute` na jednom místě, včetně
   komentáře, proč je pořadí významové (§ 73 ZDPH musí být PŘED přepočtem, aby ho
   kalkulátor zapekl do řádkových totálů). **Není v transakci** — to je vědomé, přijde
   samostatným commitem, aby šla regrese najít bisectem.
2. **`api/src/Action/PurchaseInvoice/CreatePurchaseInvoiceAction.php`** (−11/+7 řádků) —
   deleguje na službu; v konstruktoru nahrazen `PurchaseInvoiceCalculator`
   (byl tam už jen kvůli `recompute`) za `PurchaseInvoiceWriteService`.

**Vědomě přijatý rozdíl (odhalen adversariální kontrolou):** delegací se **rozšířil rozsah
`try/catch`** z prvního kroku na celou sekvenci. Dosud platilo „HTTP 400 `integrity_violation`
⇒ v DB nevzniklo nic", protože všechny `InvalidArgumentException` v `createDraft` letí PŘED
INSERTem. Dnes je rozšíření prokazatelně bez dopadu — `replaceItems` ani `setVatOverrides`
žádnou `InvalidArgumentException` nevyhazují, `recompute` hází `RuntimeException` (kterou
nechytá ani jedna větev) a jejich `PDOException` neprojde heuristikou na varsymbol, protože
`purchase_invoice_items` nemá unikát se slovem „varsymbol". **Až přibude transakce, invariant
se obnoví pro celou sekvenci sám.** Do té doby to hlídá komentář v obou souborech.

**Co se ZÁMĚRNĚ nezměnilo:** `UpdatePurchaseInvoiceAction` má tutéž čtyřkrokovou sekvenci,
ale nemá charakterizační test — přesouvat ji bez důkazu by porušilo vlastní postup.
Importní cesty taky zůstávají. Ověřeno, že jejich payload klíč `vat_overrides`
**neobsahuje**, takže až se převedou, bude krok 3 no-op a nepřinese změnu chování
(rekapitulaci § 73 jim dodává `PurchaseVatRecapSeeder` až po zápisu).

**KOPIÍ SEKVENCE JE SEDM, ne čtyři** (zjištěno až kontrolou po commitu — mění rozsah
budoucí konvergence a pravidla V77):

| místo | stav |
|---|---|
| `Action/PurchaseInvoice/CreatePurchaseInvoiceAction` | **převedeno** |
| `Action/PurchaseInvoice/UpdatePurchaseInvoiceAction` | kroky 2–4 + `setRounding`, `reprefixVarsymbol` |
| `Action/Bank/BankStatementAction:1296` | doklad z bankovního výpisu — v zadání nefiguroval |
| `Service/Import/AiPdfExtractor:713` | |
| `Service/Import/IsdocToPurchaseInvoiceMapper:129` | používá ji i scan-inbox a bundle import |
| `Service/Import/IdokladImportService:666` a `:864` | v zadání nefigurovalo |
| `Service/Import/FakturoidImportService:464` | v zadání nefigurovalo |

`PurchaseInvoiceInboxScanner` vlastní kopii **nemá** — deleguje na mapper.

**Past pro převádění:** iDoklad i Fakturoid volají `replaceItems` podmíněně
(`if (!empty($items))`), sdílená služba bezpodmínečně. Na zakládání je to jedno, ale na
úpravě dokladu by prázdné `items` tiše smazalo všechny položky. Zapsáno v docblocku služby.

**Follow-up po adversariální kontrole (bez změny logiky):** metoda přejmenována na
`createWithItems()`, aby nekolidovala jménem s `PurchaseInvoiceRepository::createDraft()`
(ta zapisuje jen hlavičku); FORK komentář přesunut nad konstruktor, ať nezvětšuje
konfliktní plochu; docblock opraven podle skutečného soupisu výše. Přibyl
**`api/tests/Architecture/PurchaseInvoiceWritePathTest.php`** — upstream sáhl do
přesouvaného bloku v 6 z 11 commitů, které ten soubor kdy měnily, a nebezpečný je tichý
merge: pátý krok přilepený ZA delegaci by běžel až za přepočtem a rekapitulace § 73 by
se nezapekla do řádků. Test hlídá, že akce nevolá žádný krok přímo, že je služba má
všechny, že `setVatOverrides` předchází `recompute` a že se sekvence nezdvojila.
Ověřeno, že test na simulovaném špatném merge skutečně spadne.

**Testy:** beze změny — 2 126 zelených, 7 304 asercí, 31 skipped. Charakterizační suita
(27 testů) prošla bez jediné úpravy, což je vlastní důkaz čistoty přesunu.

**Jak ověřit po merge:** `vendor/bin/phpunit --filter Characterization` musí projít beze
změny testů. Konstruktor akce má nově `PurchaseInvoiceWriteService` — pokud upstream
konstruktor přepíše, zkontroluj, že delegace zůstala.

---

## 2026-07-29 — Dávkový import, Commit 2: charakterizační testy zapisovacích cest

**Charakter: FORK TESTY** — druhý commit featury „AI import přes předplatné".
**Žádná produkční změna**, přibyly výhradně testy. Účel: zafixovat DNEŠNÍ chování všech
čtyř cest, které zakládají přijatou fakturu, aby šlo dokázat, že plánovaný refaktoring
(sdílená write service → transakce → převedení importních cest) nic nezměnil.

**Nové soubory:** `api/tests/Support/PurchaseInvoiceSnapshot.php` (normalizovaný obraz
dokladu se zástupkami za volatilní hodnoty), `api/tests/Support/PurchaseInvoiceCharacterizationCase.php`
(společná základna) a čtyři testy v `api/tests/Integration/PurchaseInvoice/Characterization/`.
**27 testů, 131 asercí.** Žádná síť: `AnthropicClient` i `ClientResolver` jsou testové
dvojníky, měna vždy CZK (žádný dotaz na ČNB), archiv PDF v dočasném adresáři.

**Co se zafixovalo — rozdíly mezi cestami, které dosud nikde nebyly popsané:**

| vlastnost | ruční `POST` | AI import | ISDOC / scan-inbox |
|---|---|---|---|
| `received_at` | datum **vystavení** | **dnešek** | **dnešek** |
| `exchange_rate_source` | `cnb` | `manual` | `manual` |
| `own_snapshot` | **neplní se** | neplní se | neplní se |
| `activity_log` | zapisuje se, ale **`supplier_id` = NULL** | nezapisuje (loguje až akce) | nezapisuje |
| validace | `PurchaseInvoiceValidation` | vlastní `validateAiData` | jen cross-tenant guard |

**Další zafixovaná zjištění:**
* **Nic není v transakci.** Pád na druhé položce (FK `fk_pii_vat`) nechá v DB hlavičku
  s nulovými součty i osiřelou první položku, `recompute()` neproběhne a audit se nezapíše.
  Překlopení `clients.is_vendor = 1` se navíc děje PŘED insertem hlavičky, takže po
  neúspěšném založení zůstane. Testy s tímhle chováním jsou ve skupině **`pre-transaction`**
  a commit se zavedením transakcí je vědomě změní — na rozdíl od ostatních, které musí
  zůstat zelené beze změny.
* **`scan-inbox` hlásí `created` i pro duplicitu.** Dedup scanneru stojí na `pdf_hash`,
  který u samotného `.isdoc` nevzniká; duplicitu zachytí až mapper přes
  `findIdByVendorInvoice` a vrátí id existujícího dokladu. Řádek nepřibude, ale hlášení
  říká `created: 1`. Data jsou v pořádku, čísla v hlášení zavádějící.
* **Samotný `.isdoc` se nearchivuje** — `source_*` ani `pdf_*` sloupce se neplní
  (archivuje se jen ISDOC vytažený z PDF/A-3 a obsah `.isdocx`).
* **`PurchaseSettlementService` už transakce používá** s re-entrant vzorem
  `$started = !$pdo->inTransaction()` a volá uvnitř nich `setVatOverrides` + `recompute`.
  Až se tyhle metody obalí vlastní transakcí, **musí zůstat vnořitelné**, jinak se
  vyúčtování záloh (§ 37a) rozbije.

**Testy:** 2 099 → **2 126 zelených**, asercí 7 173 → **7 304**, skipped beze změny (31).
Dva po sobě jdoucí běhy dají bit-shodný výsledek.

**Jak ověřit po merge:**
`MYINVOICE_DB_NAME=myinvoice_test_<ucel> vendor/bin/phpunit --filter Characterization`
(27 testů). Když spadne cokoli mimo skupinu `pre-transaction`, je to regrese chování,
ne chyba testu.

---

## 2026-07-29 — Dávkový import, Commit 1: izolace testovací DB a zelený baseline

**Charakter: FORK TEST-INFRA** — první commit featury „AI import přes předplatné"
(větev `feat/batch-import-subscription`). Žádná produkční logika, jen testovací prostředí.
Kontext a plán: `docs/batch-import/PLAN.md`, návod `docs/batch-import/TESTING.md`.

**Proč:** integrační testy zakládají a **mažou** reálné řádky. Na nativní instalaci je
`cfg.php` v kořeni repa zároveň produkční konfigurací, takže `vendor/bin/phpunit` tam
dosud zapisoval rovnou do ostré databáze a nic tomu nebránilo. Druhý problém: sdílenou
`myinvoice_ci` rozbíjejí dvě souběžné session.

**Co se změnilo:**
1. **`api/tests/Support/TestDatabaseGuard.php`** (nový) — pojistka V83. Tři vrstvy:
   značka ostrého provozu (`prod`/`production`/`ostra`/`live`, **nepřebitelná**), vzor
   povolených jmen (`test`/`tests`/`testing`/`ci`/`qa`/`sandbox` + volitelné číslo),
   denylist generických jmen (`ci`, `test`, `myinvoice_ci`…, mimo CI). Guard čte jen
   konfiguraci, spojení neotevírá; při zablokování končí kódem **78** (`EX_CONFIG`).
   CI se pozná podle `GITHUB_ACTIONS`/`GITLAB_CI`/`BUILDKITE`/`CIRCLECI` — **holé `CI`
   záměrně ne**, jinak by jediná zděděná proměnná ochranu vypnula.
2. **`api/tests/bootstrap.php`** (upstream soubor, +7 řádků) — volání guardu.
   **MUSÍ zůstat ZA `DG\BypassFinals::enable()`**: guard sahá na `Config` a co se načte
   dřív než BypassFinals, si ponechá `final` → 123 unit testů spadne na
   `ClassIsFinalException`. Ověřeno experimentálně.
3. **`api/bin/test-db-prepare.php`** (nový) — jeden chráněný příkaz místo tří ručních:
   guard → `migrate.php --no-backfills` → `ci-seed.php` → `test-seed-clients.php`.
   Existuje proto, že skripty v `api/bin/` guard nevolají (`reset.php` umí `TRUNCATE`).
4. **`api/bin/test-seed-clients.php`** (nový) — fork fixture: 3 syntetičtí klienti per
   tenant + IČO tenantů (mod 11). `ci-seed.php` (upstream) klienty nezakládá, kvůli čemuž
   se **103 testů nikdy nespustilo**. Needitujeme upstream skript, doplňujeme vedle něj.
5. **`api/tests/Integration/Codebook/VatRateLabelsUniqueTest.php`** — doplněn chybějící
   guard na `cfg.php` + try/catch. Bez cfg.php končil ERRORem místo skipu (jediný takový
   soubor v suitě; je fork-only, přidán v `db8dc163`).
6. **Testy guardu** — `tests/Unit/Support/TestDatabaseGuardTest.php` (tabulkové nad čistou
   `decide()`), `tests/Unit/Support/TestDatabaseGuardProcessTest.php` (11 scénářů
   v samostatném procesu — dokazují, že guard běh reálně zastaví, včetně návratového kódu),
   `tests/Architecture/TestDatabaseGuardWiringTest.php` (detektor tiché ztráty hooku při
   merge + kontrola pořadí vůči BypassFinals).

**Vědomé rozhodnutí:** guard blokuje **celý** běh, ne jen Integration suitu. Důvod:
6 „Unit" testů (`tests/Unit/Service/Auth/*`) sahá na ostrou DB a `AtomicAuthTransitionTest`
si tam zakládá uživatele — scopování na Integration by nechalo `--testsuite Unit` projít
proti produkci.

**Známé omezení:** guard hlídá jen jméno schématu, ne `db.host` — testovací jméno na
produkčním hostu projde. Hláška proto vždy vypisuje `host:port/dbname`.

**Testy:** 2 015 → **2 099 zelených**, asercí 6 661 → **7 173**, skipped **134 → 31**
(zbytek: 26× nedostupný `dev.myinvoice.cz`, 4× chybějící fixture faktur, 1× obranná logika).
Baseline běžel proti `myinvoice_test_batchimport`.

**Jak ověřit po merge:**
`MYINVOICE_DB_NAME=myinvoice_test_<ucel> vendor/bin/phpunit --filter TestDatabaseGuard`
(musí projít 84 testů) a ověřit, že `tests/bootstrap.php` pořád volá `assertOrExit`
**až za** `BypassFinals::enable()` — hlídá to `TestDatabaseGuardWiringTest`, takže při
tiché ztrátě hooku spadne Architecture suita.

---

## 2026-07-28 (3. dávka) — DDKPZ: popisky sazeb, zaokrouhlovací řádek, exporty pro KH

**Charakter: FORK BUGFIX** — dokončení DDKPZ po nasazení v4.52.0. Právní i technické podklady: `docs/dph-zalohy.md`.

**Co se změnilo:**
1. **Popisek sazby DPH z číselníku** (nový sdílený helper `web/src/utils/vatRate.ts`): dvě 0% sazby („Osvobozeno" CZ-0 a „Mimo DPH" CZ-NA) se v selectu i na dokladu vykreslovaly shodně jako „0 % (osvob.)". Popisek teď bere `label_cs`/`label_en` — v obou editorech, na detailu přijaté i vydané faktury (položky i rozpis DPH), v opakovaných fakturách a v obou PDF šablonách. Rozpis DPH (`buildVatBreakdown` na obou stranách) nově nese `vat_code`/`vat_label_*`; při míchání kódů v jednom pásmu se popisek zahodí. Test `VatRateLabelsUniqueTest` hlídá, že žádné dvě aktivní sazby nemají shodný popisek.
2. **„Mimo DPH" nikdy ve výkazech:** položky se sazbou CZ-NA dostávají explicitní klasifikaci **`NA`** (migrace **0909**, dphdp3_line i kh_section NULL) — dřív měly NULL a spadly na klasifikaci HLAVIČKY (COALESCE ve `VatLedgerService`) → hrozil ř. 40 / KH B.2. Navíc pojistka přímo v ledgeru (vyloučení sazby CZ-NA) a test `testOutOfScopeRateNeverEntersReports`.
3. **DUZP se v seznamu nedopočítává** z data vystavení (zálohy mají „—"); editor u zálohy DUZP nepředvyplňuje (jinak by ho uložení vrátilo zpět).
4. **Zaokrouhlovací řádek § 37a** (migrace **0910**): řádky dokladu se po párování vracejí PŘESNĚ na hodnoty dodavatele (dřív se do nich rozpouštěl haléř — 443 999,99 místo 444 000,00), odpočty zůstávají doslova dle DDKPZ a rozdíl nese jeden viditelný řádek „Zaokrouhlení § 37a". Součet položek = rekapitulace = hlavička. Řádek je v sazbě rozdílu (ne „mimo DPH") — jinak by v sazbě zůstal rozdíl 0,01/−0,01 a doklad by hlásil rozpor znamének.
5. **Popis odpočtu** nese i zálohovou fakturu: „Odpočet zálohy — daňový doklad ZD915260089 (ZF815260087)".
6. **Přepočet je dávkový, ne líný:** ověřeno, že GET detailu je read-only (nezapisuje); nový CLI **`api/bin/recompute-purchase-invoices.php`** (dry-run default, `--apply`, `--supplier=`, `--from=`) srovná hlavičkové součty s položkami napříč DB a vypíše doklady vyžadující přepárování. Idempotentní.
7. **Exporty pro účetní (P1):** Pohoda u přijatých dostávala NAŠE interní číslo v `symVar` (rozbité párování plateb) a **neposílala DIČ ani evidenční číslo dokladu dodavatele** → doklad by v KH spadl do B.3 místo B.2. Nově: `symVar` = platební VS, `inv:originalDocument` = číslo dokladu dodavatele, a chybějící DIČ/IČO ve `vendor_snapshot` se doplní z karty klienta. ISDOC: řádky se sazbou CZ-NA jdou jako `VATApplicable=false`.
8. **AI import:** rozpor „doklad nese DPH × dodavatel neplátce" je nově **skutečně blokující** (migrace **0911**, sloupec `extraction_blocking`) — přechod z konceptu vrací 409, dokud uživatel rozpor nevyřeší nebo upozornění vědomě nezavře; dřív se hláška při přechodu tiše mazala. Duplicitní a ISDOC větve importu vracejí `document_kind` (select v dávce už nepadá na „Faktura"); `integrations.ts` má správný typ.

**Testy:** +3 (zaokrouhlovací řádek s řádky dle PDF, „mimo DPH" mimo výkazy, unikátnost popisků sazeb) + doplněné SQLite fixtury o `vat_rates`. Suita **2015 zelených**, type-check OK.

**Jak ověřit po merge:** `vendor/bin/phpunit --filter 'VatRateLabels|OutOfScopeRate|SettlementKeepsVendorRows'`; v editoru mají 0% sazby rozdílné popisky; seznam přijatých ukazuje u záloh DUZP „—"; export Pohoda u DDKPZ obsahuje `typ:dic`, `inv:originalDocument` a platební `symVar`.

## 2026-07-28 (2. dávka) — DDKPZ: § 37a na úrovni součtů, invariant znamének, záloha mimo DPH

**Charakter: FORK FEATURE/BUGFIX** — navazuje na DDKPZ z téhož dne (commit 6506ca72). Právní opora: `docs/dph-zalohy.md`.

**Co se změnilo:**
1. **§ 37a haléřové zaokrouhlení (bug na PF2607001):** rozdíl se počítá z HRUBÉHO rozdílu per sazba (dosavadní hrubá hodnota sazby − hrubá hodnota DDKPZ), základ a daň se z něj odvodí koeficientem § 37 (`PurchaseSettlementService::applyGrossTargets`). Dřív se odečítaly zvlášť základy a zvlášť daně dvou nezávisle zaokrouhlených řad → základ +0,01 / daň −0,01. Plná záloha teď dá 0,00/0,00/0,00.
2. **Odpočtové řádky doslova dle DDKPZ** (`PurchaseInvoiceRepository::pinSettlementRowTotals`): kalkulátor by daň řádku spočetl ze sazby (16 528,93 × 21 % = 3 471,08), doklad ale nese daň shora z úplaty (20 000 × 21/121 = 3 471,07). Řádek se přišpendlí a haléř se přesune na nejsilnější NEodpočtový řádek téže sazby — součet dokladu (a tím DP3/KH) zůstává nedotčený. Přišpendlení se obnovuje po KAŽDÉM přepočtu (`pinAllSettlementRows`) — druhé párování dřív rozhodilo první.
3. **Invariant znamének** `vat_sign_mismatch` (`PurchaseInvoiceValidation::hasVatSignMismatch` + flag v `find()` + banner v detailu): u nenulové sazby nesmí mít základ a daň opačné znaménko → jinak „doklad ke kontrole". Platí pro všechny přijaté doklady.
4. **UI:** prázdný panel „Vyúčtování zálohy — Není propojeno" se nezobrazuje, je-li doklad vyúčtován přes § 37a nebo jde-li o DDKPZ.
5. **Záloha (advance) — sazba a DUZP:** nová položka číselníku **CZ-NA „Mimo DPH"** (migrace **0906**) místo „0 % osvobozeno" (osvobozené plnění se vykazuje v přiznání, mimo DPH ne); položka se sazbou CZ-NA nikdy nedostane klasifikační kód (`replaceItems`) → nikdy nespadne do DP3/KH. DUZP se u typu `advance` v editoru skrývá, při přetypování se čistí (`updateDocumentKind`, `create`/`updateDraft`).
6. **Náklady:** záloha se z nákladových agregací vylučuje **vždy** (dashboard, /purchase-stats, CRM service i procedura 0906, karta klienta, seznam klientů) — náklad nese DDKPZ nebo konečná faktura. Cash princip daňové evidence zůstává v `TaxProfileRepository::monthExpenses` (zaplacená záloha = výdaj, DDKPZ se tam naopak nikdy nesčítá).
7. **Prefix `ZA`** pro zálohové faktury (dřív `NU` dle daňového uplatnění); nápovědy v editoru i v nastavení číselné řady vyjmenovávají všechny prefixy (PF/PN, KU/KN, NU/NN, DZ, ZA).
8. **Cizí migrace 0905 (koš) — oprava FK:** `deleted_document_snapshots.supplier_id` byl `TINYINT UNSIGNED` proti `supplier.id INT UNSIGNED` → `ALTER` selhal s errno 150 a migrace by shodila nasazení. Opraveno na `INT UNSIGNED`.

**Exporty (ověřeno na izolovaném klonu, XSD EPO i ISDOC prošly) — viz kapitola „Exporty a výkazy" v `docs/dph-zalohy.md`:**
- KH: DDKPZ v B.2 (DIČ dodavatele, ev. číslo dokladu dodavatele, DPPD = den přijetí úplaty), konečná faktura s nulovým rozdílem se **neuvádí**, zálohy nikde (ověřeno i ve stavu `received`). DP3 ř. 40 sedí v obou měsících záloh.
- ISDOC: DDKPZ `DocumentType 5` + `VATApplicable true`; **nově se generuje `<TaxedDeposits>` + `AlreadyClaimed*`/`Difference*`** (dřív odešla konečná faktura jako doklad se samými nulami a dvěma záhadnými minusovými řádky). Auto-odpočtové řádky se do `InvoiceLines` nevypisují. Zálohová faktura (typ 4) má nulovou rekapitulaci DPH a **žádné `TaxPointDate`** (dřív se DUZP dopočítalo z data vystavení — `PurchaseInvoiceExportService`).
- Pohoda: DDKPZ = `receivedInvoice` (v `invoiceTypeType` typ pro přijatý daňový doklad k záloze neexistuje), zálohy `receivedAdvanceInvoice`.
- `unlink()` vrací **přesně** předchozí rekapitulaci — snapshot `settlement_recap_backup` (migrace **0907**); z hrubé částky ji zrekonstruovat nelze (475 992,00 → vždy 393 381,82/82 610,18, i když doklad nesl …,83/…,17).
- `buildVatBreakdown` zaokrouhluje (dřív 0,00 vycházelo jako 5.8e-11 v JSON API).

**Testy:** +10 v `KhDphTaxScenariosTest` (§ 37a: plná záloha na nulu vč. slevy v mínusu, doplatek, přeplatek se sazbou zálohy, dvě sazby se zálohou jen k jedné, invariant znamének, zaplacená záloha mimo DPH/KH/DzP, 15denní lhůty ve 4 scénářích). `PurchaseAdvanceLinkTest` srovnán na novou sémantiku nákladů. Suita **1975 zelených**, type-check OK. Pozn.: testy vyžadují v `cfg.php` sekci `varsymbol.templates` (jinak 6 chyb v `RecurringGeneratorTest`).

**Jak ověřit po merge:** `vendor/bin/phpunit --filter 'Settlement37a|VatSignMismatch|TaxDocumentDeadline|PaidAdvanceNever'`; v editoru přijaté faktury má typ „Záloha" skryté DUZP a položky sazbu „Mimo DPH"; detail konečné faktury s DDKPZ nezobrazuje prázdný panel zálohy.

## 2026-07-28 — DAŇOVÝ DOKLAD K PŘIJATÉ ZÁLOZE (DDKPZ) v modulu přijatých faktur (přímo na custom)

**Charakter: FORK FEATURE — kandidát pro upstream po ověření v provozu.** Právní rešerše s odkazy: `docs/dph-zalohy.md` (§ 20a, § 28/8, § 37a, § 72/73 ZDPH + Metodická informace GFŘ ke KH; vč. rozhodovacího stromu).

**Co se změnilo:**
1. **Nový typ přijatého dokladu `tax_document`** (daňový doklad k přijaté záloze, § 28/1/d) — migrace **0904**: rozšíření ENUM `purchase_invoices.document_kind`, `settled_by_purchase_invoice_id` (N DDKPZ → 1 konečná faktura), `purchase_invoice_items.settlement_source_purchase_invoice_id` (auto-odpočtové řádky § 37a). Zrcadlí `invoices.invoice_type='tax_document'` z vydané strany. Interní číslo s prefixem **DZ** (`varsymbolPrefix` výjimka per typ). Labely/i18n/PDF titulek/ISDOC DocumentType 5 (export i import — 5 se dřív degradovala na zálohu).
2. **Chování:** DDKPZ vstupuje do DPH/KH (B.2/B.3 dle limitu, DPPD = den přijetí úplaty) a nese náklad za zálohu; `advance` dál nevstupuje nikam; konečná faktura se eviduje s minusovými odpočtovými řádky → do DPH/KH i nákladů jde **jen rozdíl dle § 37a** (limit KH z |rozdílu| — FAQ FS VI/2). Vazby: záloha ←`advance_purchase_invoice_id`— DDKPZ —`settled_by_purchase_invoice_id`→ konečná; záloha spárovaná s DDKPZ automaticky vypadá z nákladových agregací (stávající NOT EXISTS predikáty beze změn).
3. **`PurchaseSettlementService`** (nový): link/unlink DDKPZ ↔ konečná faktura v transakci s atomickým claimem; volitelné doplnění odpočtových řádků (per sazba+klasifikace+majetek) + `vat_overrides` = rekapitulace − DDKPZ (haléřová přesnost vůči dokladu, reziduum přišpendlí InvoiceMath); unlink řádky odebere a settlementové overrides odstraní. Guardy: draft/storno/dobropis/RC/bez nároku/`advance_paid_amount`≠0 (dvojí odečet), potvrzení visícího AI návrhu vazby na zálohu. Nové endpointy: GET settlement-doc-candidates, GET final-candidates, POST/DELETE link-settlement-doc (+ openapi).
4. **Ochrany integrity:** storno/smazání/změna typu dokladu s vazbami vrací 409 (`has_settlement_links`); editor nesmí smazat flagované odpočtové řádky (`settlement_rows_locked`); `settlement_source` z klientského payloadu prochází whitelistem; guard změny typu i v `updateDraft`. UI warningy: `settlement_deduction_mismatch` (§ 37a nesedí/chybí), `tax_document_late` (> 15 dnů od úplaty, § 28/8), `advance_missing_warning` (zaplacená záloha bez spárovaného DD/faktury po 15 dnech). Cash výdaje (`TaxProfileRepository::monthExpenses`): DDKPZ se nikdy nesčítá; zaplacená záloha vypadává jen ukazuje-li na ni ne-DDKPZ doklad.
5. **AI extrakce:** prompt zná `tax_document` (rozpoznání „daňový doklad k přijaté záloze", den přijetí platby → tax_date, `advance_reference` → návrh párování přes VS). **Konflikt „doklad s DPH × dodavatel neplátce": sazby se už NEPŘEPISUJÍ na 0 %** — zůstanou dle dokladu, odpočet konzervativně 'none', blokující varování „ověř DIČ". Kontrola součtu: rozdíl „K úhradě" z PDF vs. uloženo ≥ 1 Kč → warning (dřív se tiše zahodil). Jediný whitelist typů: `PurchaseInvoiceValidation::ALLOWED_DOC_KINDS` (BE 4 duplicity sjednoceny) + `web/src/constants/purchaseDocumentKinds.ts` (FE 4 selecty vč. AI dávkového — doplněn i `advance`).
6. **ARES skupinová registrace DPH (kořen chyby „Direct auto Praha = neplátce"):** `AresClient` čte `stavZdrojeSkDph`+`dicSkDph` → člen DPH skupiny je plátce s DIČ skupiny CZ699*; `VendorVatPayerResolver` vrací DIČ z registru a doplní ho na kartu (jen bylo-li prázdné); `ClientResolver`/ClientForm/Setup preferují `dic_sk_dph` jako fallback.

**Soubory:** db/migrations/0904; api/src: Service/Invoice/PurchaseSettlementService (nový), Repository/PurchaseInvoiceRepository (vazby, kandidáti, whitelist, DZ prefix, payload flagy), Validation/PurchaseInvoiceValidation, Action/PurchaseInvoice/{LinkSettlementDoc,UnlinkSettlementDoc,SettlementDocCandidates,FinalCandidates} (nové) + {Delete,Update,Transition,SetDocumentKind}, Service/Import/{AiPdfExtractor,AnthropicClient,ClientResolver,IsdocParser,IsdocToPurchaseInvoiceMapper}, Service/Ares/{AresClient,VendorVatPayerResolver}, Repository/{ClientRepository,TaxProfileRepository}, Export/PurchaseInvoiceExportService, Pdf/PurchaseInvoicePdfRenderer, Routes, openapi; web/src: constants/purchaseDocumentKinds.ts (nový), api/{purchaseInvoices,clients}.ts, pages purchase-invoices/{InvoiceDetail,InvoiceEditor,InvoiceList}, admin/Integrations, clients/ClientForm, Setup, i18n cs/en.

**Testy:** KhDphTaxScenariosTest +4 (DDKPZ B.2 nad limit + ř. 40, B.3 do limitu, konečná jen rozdílem, PurchaseSettlementService haléřová přesnost + unlink restore), AiPdfExtractorUnitTest +6 (documentShowsVat, normalizeDocumentKind), PurchaseImportBatchAndKindTest aktualizován na novou sémantiku guardů. Suita 1952 zelených; adversarial review (20 agentů) — 14 potvrzených nálezů opraveno.

**Jak ověřit po merge:** `vendor/bin/phpunit --filter 'KhDphTaxScenarios|PurchaseImportBatchAndKind|AiPdfExtractorUnit'` zelené; editor přijaté faktury nabízí 5 typů; AI import ukáže select se všemi typy; detail konečné faktury umí „Spárovat s daň. dokladem k záloze" a po spárování ukazuje minusové řádky; ARES lookup IČO 25114719 vrací plátce + DIČ CZ699003841.

## 2026-07-28 — UPDATE z upstreamu: v4.51.0 → **v4.52.0** (přijetí našeho PR #245)

Merge tagu v4.52.0 do `custom` (commit 2bc372b1). Release obsahuje **mergnutý náš PR #245**
(opravy výkazů DPH, zámek dokladu, EPO identifikace, CZ-NACE) — včetně oprav z autorovy revize,
které nám v produkci dosud chyběly, hlavně **CZ-NACE kanonizace proti číselníku ČINNOSTI**
místo slepého paddingu (produkce dosud uměla vygenerovat kód mimo číselník → EPO chyba 30).
VERSION 4.51.1 → 4.52.0 (fork-only bump 4.51.1 tím zaniká).

**FÁZE 2 nahrazena upstreamem.** Autor po zavření PR #247 vydal vlastní implementaci: tabulka
`user_suppliers` (migrace 0148) se schématem sdíleným s MyÚčto.cz + **per-firmu override role**,
resoluce v `Service/Tenant/SupplierAccessResolver`. Naše `user_supplier_access`,
`Service/Auth/UserSupplierAccess` a její test odstraněny; **migrace 0908** přenese případná
přiřazení do `user_suppliers` a starou tabulku zahodí (v naší produkci bylo 0 řádků).
Autor převzal i náš postřeh, že `FOREIGN_KEY_CHECKS = 0` při mazání dodavatele obchází
`ON DELETE CASCADE` — ve své verzi uklízí membership ručně.

**Konflikty (43 bloků / 13 souborů):** fork funkce zachovány (koš 0905, DDKPZ 0904/0906/0907,
pokladna, interní poznámka, redesign); u sporných bloků měl přednost upstream. Ručně dořešeno:
`PurchaseInvoiceValidation::hasVatSignMismatch` vrácena (volá ji DDKPZ kód, autor ji nahradil
vlastní `hasMixedSignItems`), `dic_sk_dph` zpět do `AresLookupResult`, odstraněn duplicitní
`FORMS` v `DphPriznaniBuilder` a zbytek staré FÁZE 2 v `listSuppliers`.
V upstream testu `SupplierMembershipTest` opraven fiktivní bcrypt hash (61 → 60 znaků — padal
na `CHAR(60)` ve strict módu; **nahlásit autorovi**).

**Ověřeno:** suita 2009 zelených (čerstvá DB, migrace 0001–0908), `pnpm type-check` + `build`
čisté, migrace 0907 i 0908 aplikované, HTTP 200, log čistý, běžící kód = HEAD (md5 shoda).
Zálohy: `/root/backup-myinvoice-{db,data}-2026-07-28-2000-pred-vat-merge.*`

## 2026-07-28 — KOŠ + TVRDÉ MAZÁNÍ DOKLADŮ (vydané i přijaté faktury) — větev feature/document-trash

**Charakter: FEATURE — KANDIDÁT PRO UPSTREAM** (vzor iDoklad/Vyfakturuj; uživatel 2026-07-28 potvrdil, že funkci chce nabídnout autorovi do oficiální větve — stejným postupem jako `pr/vat-report-fixes`, tj. issue + PR z forku). Migrace **0905**.

**Před odesláním upstreamu vyřešit** (funkce vznikla nad forkem, upstream tyhle věci nemá):
1. **Odstranit fork-only vazby z `DocumentTrashPolicy`** — blokace „vyúčtování zálohy" se opírá o DDKPZ sloupce z migrace 0904 (`settled_by_purchase_invoice_id`, `purchase_invoice_items.settlement_source_purchase_invoice_id`), které v upstreamu neexistují. Buď detekovat sloupce za běhu (vzor `supportsInternalNote`), nebo tuhle část blokace do PR nedávat.
2. **BREAKING API**: `DELETE /api/v1/(purchase-)invoices/{id}` nově vyžaduje `{reason}`. Pro upstream navrhnout zpětně kompatibilní variantu (bez `reason` = dosavadní chování draft-only), jinak to autor odmítne — mění to veřejný kontrakt.
3. **Migrace přečíslovat** z fork rozsahu 0905 do upstream řady (dnes 0147+) a ověřit, že nekoliduje.
4. Dle `CONTRIBUTING.md`: PR musí nést i `openapi.yaml` (hotovo), kapitolu manuálu (hotovo — 9.7 + 17.9) a **`php tools/generateManualHtml.php`**, a projít `phpunit` + `pnpm type-check` + `build` (vše zelené).
5. Zvážit, jestli do PR přidat i sweep `deleted_at IS NULL` v celém rozsahu (~150 podmínek) — je to velká plocha; alternativa je nabídnout ho jako druhý, menší PR po přijetí prvního.

Tři oddělené operace: **storno/dobropis** (beze změny, primární cesta) → **Do koše** (soft delete, vratné, admin i účetní, povinný důvod ≥ 10 znaků) → **Smazat trvale** (jen admin, jen z koše, opsání čísla dokladu, snapshot). Dřívější mazání (draft-only / force=1) je nahrazeno.

1. **DB (0905_document_trash.sql):** `invoices` + `purchase_invoices` dostaly `deleted_at/deleted_by/delete_reason` + index; nová tabulka `deleted_document_snapshots` (kompletní JSON otisk při hard delete); `supplier.doc_trash_enabled` (default 1) + `doc_trash_retention_days` (default 30, 0 = neomezeně).
2. **Backend:** `Service/Invoice/DocumentTrashPolicy` (blokace: uzavřené DPH období dle tax_submissions — NIKDY nepřebít; vazby úhrady/párování/záloha/dobropis/pokladní doklad/platební příkaz — NIKDY nepřebít; odesláno+veřejný odkaz / export iDoklad+Fakturoid — admin smí přebít `override`), `Service/Invoice/DocumentTrashService` (trash/restore/forceDelete vč. snapshotu, úklidu PDF, uvolnění čítače), `Http/TrashGuard` (doklad v koši je read-only). Akce: DELETE `/{id}` = do koše (tělo `{reason, override}`), POST `/{id}/restore`, DELETE `/{id}/force` (`{reason, confirm_number, override}`), POST `/trash-preflight` (`{ids}` — read-only blokace pro dialogy), POST `/trash/empty` (admin; blokované přeskočí) — pro invoices i purchase-invoices. Chyby: 403 forbidden_role, 409 blocked_vat_period/blocked_linked_documents/blocked_sent/blocked_exported/not_in_trash/already_in_trash/in_trash, 422 reason_required/confirm_number_mismatch.
3. **Číselné řady:** hard delete POSLEDNÍHO čísla řady vrací counter (vydané: existující `VarsymbolGenerator::releaseIfLatest`; přijaté: nová `PurchaseInvoiceRepository::releasePurchaseVarsymbolIfLatest`), jinak mezera → audit `numbering_gap`. Ruční čísla counter nemění.
4. **Sweep `deleted_at IS NULL`** (~150 podmínek ve 33 souborech): VatLedgerService (Kniha DPH/KH/SHV/DP3), DphPriznaniBuilder, IncomeTaxBuilder, OssLedgerService, oba dashboardy, CRM agregace, StatsRecomputer (client/project_revenue_cache), listy+search, exporty (CSV/ZIP/monthly/purchase), bankovní párování (StatementMatcher + BankStatementAction + platební příkazy), kandidáti záloh/vyúčtování, upomínky (service i cron). ZÁMĚRNĚ bez filtru: find() podle id, kontroly obsazenosti čísel, dedup importů, referenční kontroly mazání entit, idempotence generátorů — číslo dokladu v koši je pořád obsazené.
5. **TrashGuard ve 24 mutačních akcích** (update/issue/cancel/platby/odeslání/párování/QR/PDF upload…): doklad v koši vrací 409 `in_trash`.
6. **Cron:** `cron-cleanup.php` po retenci tvrdě maže z koše (audit `*.trash_autopurged`); blokované doklady nechává. `cron-send-reminders` doklady v koši vynechává.
7. **Frontend:** tab **Koš** v seznamu vydaných (TabsNav preset, `?trash=1`), toggle **Koš** v seznamu přijatých; sloupce kdo/kdy/důvod; řádkové akce Obnovit / Smazat trvale; hromadné Do koše / Obnovit / Smazat trvale / Vysypat koš (admin). Sdílený dialog `components/invoices/DocumentTrashModal.vue`: červený varovný panel, identifikace dokladu, výpis blokací (hromadně per doklad, blokované se přeskočí), checkbox „Vím, co dělám" (jen admin, jen přebitelné), povinný důvod, u hard delete opsání čísla; destruktivní tlačítko je sekundární červené VLEVO, primární „Zrušit" vpravo; žádný native confirm(). Detaily: banner „v koši" + akce jen Obnovit/Smazat trvale; menu „…" → Pokročilé má „Do koše" jako poslední položku se separátorem (ActionBar nový prop `dividerBefore`). Editor vydané: mazání konceptu přes stejný dialog. Nastavení → sekce „Koš pro doklady" (přepínač + retence). i18n: namespace `doc_trash` (49 klíčů) + `settings.doc_trash_*` v cs i en.
8. **API kontrakt:** DELETE `/api/v1/(purchase-)invoices/{id}` nově vyžaduje `{reason}` (BREAKING pro API-token klienty) — openapi.yaml aktualizováno vč. nových endpointů.

**Které soubory:** db/migrations/0905_document_trash.sql; api/src/Service/Invoice/DocumentTrashPolicy+DocumentTrashService; api/src/Http/TrashGuard.php; api/src/Action/Invoice/{Delete,Restore,ForceDelete,TrashPreflight,EmptyInvoiceTrash}…Action; api/src/Action/PurchaseInvoice/{Delete,Restore,ForceDelete,PurchaseTrashPreflight,EmptyPurchaseInvoiceTrash}…Action; Routes.php; SettingsAction; cron-cleanup + cron-send-reminders; sweep 33 souborů (viz git log); web: DocumentTrashModal.vue, ActionBar.vue, oba InvoiceList/InvoiceDetail, invoices/InvoiceEditor, admin/Settings.vue, api/{invoices,purchaseInvoices,settings}.ts, i18n cs+en; manual 09+17; api/openapi.yaml.

**Testy:** `tests/Integration/Invoice/DocumentTrashTest.php` + `tests/Integration/PurchaseInvoice/PurchaseDocumentTrashTest.php` (role 403, DPH blokace i pro admina, counter release poslední vs. prostřední, koš mimo list+VatLedger, obnova se stejným číslem, snapshot+audit, vysypání přeskočí blokované).

**Po merge s DDKPZ (audit 2026-07-28, commity 4507aae8 + 92b15562):** párovací cesta § 37a vznikala paralelně, takže ji sweep koše minul — doplněno: `PurchaseSettlementService::link()/unlink()` a `PurchaseInvoiceRepository::linkAdvance()` odmítají doklad v koši (kontrola OBOU stran — protistrana přichází z těla požadavku, kam TrashGuard v akci nedosáhne), `TrashGuard` v Link/UnlinkSettlementDoc a obou Dismiss akcích, `AND pi.deleted_at IS NULL` v settlementDocCandidates/finalCandidates i v obou EXISTS flagech ve `find()`, a v purchase detailu `canMutate = canWrite && !inTrash` pro všechny mutační prvky. Bez toho šlo DDKPZ v koši napárovat na živou fakturu → odpočet § 37a bez protistrany ve výkazech. Zároveň srovnán měsíční mezisoučet v seznamu přijatých (`listGroupedByMonth`) se sémantikou migrace 0906 — dřív ukazoval nad týmiž doklady 23 000, zatímco dashboard/CRM/karta klienta 20 000.

**Jak ověřit po merge:** phpunit suita zelená; v UI: vydané → tab Koš; přijaté → tlačítko Koš; smazat testovací koncept (vyžaduje důvod), obnovit, smazat trvale (opsání čísla); doklad v koši nesmí být v Přehledu/Tržbách/Knize DPH; Nastavení ukazuje sekci Koš pro doklady; `php api/bin/cron-cleanup.php` proběhne bez chyby a reportuje `doc_trash_autopurged`.

## 2026-07-28 — FORK RELEASE 4.51.1 + BUG 7: CZ-NACE (EPO chyba 30) a warning zaokrouhlení (chyba 49)

**Charakter: BUGFIX — ✅ PŘIJATO UPSTREAMEM** (PR #245 mergnut, vydáno ve **v4.52.0**; od merge tagu je to už upstream kód, ne fork úprava — při dalších updatech se nekontroluje). Fork VERSION bump 4.51.1 zanikl s přechodem na 4.52.0.

1. **CZ-NACE / c_okec:** ukládání normalizuje na 6místný kód číselníku MFČR (73.11/7311 → 731100, 62020 → 620200); pod 4 číslice (oddíl z ARES, např. „74") → 422 a neuloží se. ARES prefill bere NEJDELŠÍ kód z czNace, u pouhého oddílu nechává pole prázdné + `cz_nace_note` pro UI (Settings toast). Build (normalizeOkec) neúplný kód VYNECHÁ (c_okec optional — dřív by šel ven a EPO hlásilo propustnou chybu 30). UI: placeholder 731100, nový hint, inline validace, normalizace na blur. EpoIdentityValidator u DP3 varuje i na neúplný kód s odkazem na chybu 30. **Data: PROPSOL cz_nace_code opraveno „74" → „731100".**
2. **Propustná chyba 49:** DP3 preview porovnává součet daně z dokladů na ř. 40/41 s round(zaokrouhlený základ × sazba) a rozdíl hlásí warningem („…neupravuj ji" — hodnota odpovídá KH B.2/B.3). XML se nikdy nepřepisuje, generování se neblokuje. Ověřeno na Q1/2026: rozdíl 1 Kč na ř. 40 (5227 vs 5228).
3. Testy: CzNaceNormalizationTest, CzNaceAndRoundingTest (nové), AresNormalizeNaceTest přepsán na novou sémantiku (nejdelší kód; oddíl → prázdno+note). Manuál kap. 29 (tabulka c_okec, troubleshooting chyb 30/49). Suita 1942 zelených.

## 2026-07-27 — ZÁMEK DOKLADU V UI + EPO IDENTIFIKACE + VIES CZ699 (přímo na custom, 6 commitů 9c90c80f..e1395c0e)

**Charakter: BUGFIX — ✅ PŘIJATO UPSTREAMEM** (PR #245 mergnut, vydáno ve **v4.52.0**; upstream kód, při updatech se nekontroluje). Podklady zůstávají v `/root/vat-fix-snapshots/`.

1. **BUG 5 — zámek stavu bez cesty ven z UI:** oba editory (vydané i přijaté) zobrazují u uzamčeného dokladu výstražný pruh + admin tlačítko **„Odemknout k editaci"** → modal s výslovnými následky a povinným checkboxem; formulář je do odemčení `fieldset[disabled]`; příznak nepřežije reload a `?force=1` z URL se ignoruje. Backend: bez force 409 s návodem, force bez admin 403; audit **`invoice.force_edit` / `purchase_invoice.force_edit`** s diffem polí + starým/novým snapshotem (dřív jen `force_updated` bez detailu). Nový **POST `/api/invoices/{id}/rebuild-snapshots`** („Obnovit údaje klienta", admin) — přepíše jen snapshoty z live dat i u zaplacené faktury, audit `invoice.rebuild_snapshots`.
2. **BUG 6 — EPO XML bez povinné identifikace:** nový **`EpoIdentityValidator`** (povinné: kód FÚ, **ÚzP/c_pracufo**, DIČ, typ poplatníka, e-mail; u PO opr_*; doporučené: telefon, CZ-NACE u DP3). KH/DP3/SHV preview i download vrací **422 `epo_identity_incomplete`** s `missing[]` + `settings_url`; report stránky to kreslí jako blok s výčtem a odkazem na `/admin/settings#epo`; Settings mají kotvu #epo, badge „Nekompletní — EPO podání selže", červené hinty a nápovědu ÚzP. PUT suppliers vrací `epo_ready`+`missing` (informativně). **POZOR: BEKRON (supplier 1) nemá ÚzP ani oprávněnou osobu → jeho výkazy vrací 422, dokud se pole nedoplní** (PROPSOL je kompletní).
3. **Bonus — VIES vs. skupinová registrace:** CZ DIČ s kmenem 699* se ověřuje v registru plátců DPH (CrpDphClient), ne ve VIES (falešné „není platné"); `VendorVatPayerResolver` u CZ699 nikdy nepersistuje neplátce z VIES.

Testy: ForceEditUnlockTest, EpoIdentityGuardTest, ViesClientCzRoutingTest (+2). Bez migrací. openapi + manuál kap. 9/17/29 aktualizovány, HTML regenerováno. Ověřit po merge: suita zelená; editor vydané faktury ukazuje zámek+odemčení; KH preview u nekompletního tenanta vrací výčet chybějících polí.

## 2026-07-27 — OPRAVA DPH VÝKAZŮ: dobropisy, zahraniční RC, forma podání, termíny, konzistence RC (větev fix/vat-credit-note-sign)

**Charakter: BUGFIX — ✅ PŘIJATO UPSTREAMEM** (PR #245 mergnut, vydáno ve **v4.52.0**; upstream kód, při updatech se nekontroluje). Podklady zůstávají v `/root/vat-fix-snapshots/`.

**Co se změnilo (5 chyb v4.51.0):**
1. **BUG 1 — dobropisy se přičítaly:** `VatLedgerService::fetchPurchases` nově normalizuje přijaté dobropisy (`document_kind='credit_note'`) přes **-ABS()** na záporné částky (base/vat/inv_total) — v DB žijí obě znaménkové konvence (ruční/AI import záporně, část importů kladně — reálně PF2602004). Propíše se do DPHDP3/DPHKH1/DPHSHV/Knihy DPH. Vydané dobropisy (v DB záporné) beze změny. KH: dobropis nad 10 000 Kč jde přes `abs()` práh jako **samostatný záporný řádek B.2/A.4**. `IncomeTaxBuilder` náklady taktéž -ABS().
2. **BUG 2 — zahraniční RC dostával tuzemský kód 5:** nákupní fallback klasifikace nově dle země (EU → `24e`, 3. země → `24`, tuzemsko → `5`) + příznak `code_estimated` → **adresný warning v KH preview** (zboží 23/25 nutno zvolit ručně).
3. **BUG 3 — forma podání:** query param `form` — KH `B/O/N/E`, DP3 `B/O/D/E` + `d_zjist` (DD.MM.YYYY; povinné u N/E resp. D/E — `ReportFormParams`). UI: selectbox „Forma podání" + date picker v obou stránkách výkazů.
4. **BUG 4 — termíny vs. víkend/svátek:** nový **`CzechWorkingDays`** (§ 33/4 DŘ, pevné svátky + Velikonoce) — `submission_deadline` v KH/DP3/SHV/income-tax i CRM dashboard termínech se posouvá na následující pracovní den (25.07.2026 sobota → 27.07.).
5. **BUG 5 — rozpor hlavičky a klasifikace (PF2602010):** RC kód na položce vynucuje `reverse_charge=1` — hrdlo v `PurchaseInvoiceRepository::replaceItems` (kryje UI/AI/ISDOC/iDoklad importy) + Create/Update akce s warning toastem; **`api/bin/backfill-reverse-charge-consistency.php`** (dry-run default) dorovná historii; banner odemčené editace v purchase editoru, tlačítko „Odemknout k editaci".

**Soubory:** api/src/Service/Report/{VatLedgerService, KontrolniHlaseniBuilder, DphPriznaniBuilder, SouhrnneHlaseniBuilder, IncomeTaxBuilder, VatClassificationDefaulter, **CzechWorkingDays** (nový), **ReportFormParams** (nový)}, Crm/CrmAggregationService, Action/Report/{KontrolniHlaseni,DphPriznani}Action, Action/PurchaseInvoice/{Create,Update}, Repository/PurchaseInvoiceRepository, bin/backfill-reverse-charge-consistency.php (nový); web: reports.ts, KontrolniHlaseniReport.vue, DphPriznaniReport.vue, purchase InvoiceEditor.vue, i18n cs/en. Testy: **VatLedgerServiceCreditNoteTest, CzechWorkingDaysTest, ReportFormParamsTest** (nové), KhDphTaxScenariosTest (+5 scénářů, SHV deadline 27.07.2099), MeActionTest (oprava po FÁZI 2 — 7. parametr).

**Jak ověřit po merge:** `php vendor/bin/phpunit --filter 'CreditNote|CzechWorkingDays|ReportFormParams|KhDphTaxScenarios'` zelené; KH 02/2026 B.3 = 6 573,26/1 380,39; DP3 Q1/2026 Veta6 `dano_da="484"` (ne `dano_no`); KH 06/2026 termín 27.07.; stažení KH s formou N vyžaduje datum zjištění. Ostatní měsíce 2026 bit-identické (viz /root/vat-fix-snapshots/DIFF-REPORT.md).

## 2026-07-27 — REDESIGN Fáze 8: PDF šablona faktury (větev feature/redesign)

**Co se změnilo (mPDF+Twig pipeline zachována; `invoice.twig`, `styles/invoice.css`, `InvoicePdfRenderer.php`, migrace **0903**):**
1. **8a opravy:** „Vaše číslo" jen když ≠ název zakázky; **Kč** pro CZK (hlavičky sloupců `(Kč)`, sumace, platební pás; ISO jen v řádku Měna a CZK přepočtu; EN drží ISO); logo max-height 22 mm; **vypnutelná attribution patička** (`supplier.pdf_attribution_enabled`, default zapnuto) — pozor: Twig `|default()` přepisuje i `false`, používá se `?? true`.
2. **8b obsah:** **Rekapitulace DPH** (Sazba|Základ|Výše DPH|Celkem + součtový řádek) ve spodním bloku — per-sazbové řádky ze sumace ODSTRANĚNY (duplikovaly by se; sumace = jen Celkem + zálohy); u neplátce/proformy skryta. **Razítko a podpis** vlevo dole (`supplier.signature_path` — mrtvý sloupec z 0001 oživen; upload `POST/DELETE /api/settings/signature`, SafeLogoPath vzor `sup-{N}-signature.png`, GD konverze, alfa flatten při renderu; `branding_profiles.signature_path` + overlay připraveny, per-profil upload UI zatím není). **Právní věta** (`pdf_legal_text`, max 1000 zn.). Kontakt dodavatele (e-mail·telefon·web) + spisová značka v bloku DODAVATEL — spodní patička dokladu ODSTRANĚNA (redundantní, drží 1 stranu). **Strana X z Y** v patičce každé strany. Volitelný **Code128 VS** (`pdf_barcode_enabled`, mPDF `<barcode>`). Štítek **ZAPLACENO + datum** u čísla dokladu; částečná úhrada „Uhrazeno · zbývá" v platebním pásu (řádky v sumaci jen bez pásu). „Místo dodání" NEIMPLEMENTOVÁNO — datový model dodací adresy v aplikaci neexistuje (vyžadovalo by DB+editor, mimo pravidlo nefunkčních změn).
3. **8c sazba:** okraje 14/16/18/16; `thead` repeat na dalších stranách; `tr`/sumace/spodní blok `page-break-inside: avoid`; oddělovače 0.5 pt #E9EEF5; sloupec „#" u >3 položek; hlavičky položek sentence case (mPDF neumí span text-transform override). Kompaktnější sazba → **faktura do ~8 položek = 1 strana** (testy: 10/12 scénářů 1 str.).
4. **8d platba:** pás výraznější (#E6DFFB, radius 8 px, K úhradě 18 pt), **QR SPAYD DT fix** (due_date se generátoru dřív nepředával → DT bylo „dnes"), po splatnosti červené datum, QR 24 mm v bílém boxu.
5. **8e:** akce **Náhled** na detailu faktury (Modal+iframe `?inline` + Tisk + Stáhnout); **Nastavení → Vzhled dokladu** (`DocumentAppearanceSettings.vue`) s přepínači, právní větou, uploadem razítka a **živým PDF náhledem na ukázkových datech** (`GET /api/settings/document-preview.pdf` — snapshot-injection, bez zápisů; parametry přepisují neuložené hodnoty; podporuje brandingové profily + EN).
6. **8f testy:** 12 scénářů rendrováno bez DB (snapshot-injection, skript v commitu není — viz /root/redesign-pdf-nahledy/): 1 položka ✓1str, 40 položek ✓4str+thead repeat, sazby 21/12/0 ✓, neplátce ✓, RC ✓, EUR+CZK přepočet ✓1str, EN ✓, dlouhé texty ✓2str zalomené, zaplacená ✓ (štítek+zelený pás+vypnutá patička), po splatnosti ✓ červeně, s logem (2str — logo +12 mm), částečná úhrada ✓1str. **ISDOC embed ověřen** (`pdfdetach -list` = invoice.isdoc).

**Jak ověřit po merge:** migrace 0903 aplikovaná; vystavit PDF → Kč, rekapitulace, Strana X z Y; Nastavení → Vzhled dokladu funguje vč. náhledu; QR obsahuje DT (načíst bankovní appkou). Pozor při upstream merge: invoice.twig/invoice.css nesou rozsáhlé FORK F8 bloky.

## 2026-07-27 — REDESIGN Fáze 7: dark mode, a11y, motion (větev feature/redesign)

**Co se změnilo:**
1. **Dark mode remap čistě přes tokeny** (`redesign.css` .dark): `--color-surface #2F334D` (dle zadání; přebíjí custom-theme #171F33), `--surface-muted #292C43` (z F1), spodní neutrály dorovnané k novému surface (50 `#292C43`, 100 hover `#383D59` — v dark zesvětluje, 200 bordery `#434965`, 300 `#525878`); text 400–900 beze změny (AA drží). `--app-bg-dark #0B0F31` z F1. Žádný hardcode v komponentách. `useTheme.ts` chart barvy dark sladěny (border/grid/tooltipBg).
2. **A11y**: globální `:focus-visible` outline 2 px `--primary` s offsetem 2 px (a, button, role=button/option/tab/radio, summary); `prefers-reduced-motion: reduce` vypíná přechody i animace; Modal zavírací tlačítko `aria-label` z `common.close` (dřív anglicky natvrdo). IconButton/ovládací prvky mají aria-labely od F3.
3. **Motion**: globální přechody sjednoceny na `.15s ease-out` (custom-theme), nic přes 250 ms (drawer 200 ms, dropdowny 75–100 ms).
4. **Responzivita**: off-canvas sidebar s hamburgerem je z F2; stránky F4–F6 mají vlastní mobilní karty. Pro zbývající výpisy přidán opt-in nástroj `.ui-table--stack` (mobilní „label: hodnota" stack přes `data-label` atributy) — konverze ostatních stránek postupně.
5. Oprava z F3: `Badge` barvy danger/warning/accent odkazovaly na neexistující `-700` tokeny → `-600`.

**Poznámka ke kontrastu:** bílý text na `--accent-cta #16A34A` má ~3,1:1 — pro 14px semibold pod AA (4,5:1). Zadání barvu fixuje; případné ztmavení na `#15803D` je jednořádková změna tokenu.

**Jak ověřit po merge:** build ✓ testy 50/50 ✓; dark: panel #2F334D, muted #292C43, viditelné bordery/hover; Tab ukazuje indigo focus ring; OS „omezit pohyb" vypne animace.

## 2026-07-27 — REDESIGN Fáze 6: Přehled (větev feature/redesign)

**Co se změnilo:**
1. **`Dashboard.vue`**: KPI dlaždice — label 13 muted / hodnota 32/700 / kontext 12, na `--surface-muted` bez borderů; „Po splatnosti" (a přijaté po splatnosti / dnes splatné) s jemným červeným/oranžovým podbarvením místo borderu, jen při nenulové hodnotě. Grid 12 sloupců s gap 24 (`--grid-gap`), mobil 1 sloupec — nahrazuje dřívější `kpiGridCols` mapping i `singleCurrency` layout. Sekční hlavičky = h2 (Nunito 20) místo barevných uppercase pilulek. Tabulky (po splatnosti / nezaplacené / top klienti) → `.ui-table`; cash-flow, koláč a draft karty na muted kartách; pilulková tlačítka.
2. **Nový graf obratu** `components/charts/RevenueBarsChart.vue`: osy, gridlines, tooltip, sloupce zaoblené nahoře (radius 6) s vertikálním gradientem primary; barvy z `useChartColors` (dark mode ready). **SegmentedControl Měsíce/Kvartály/Roky** — agregace client-side z existujícího 12M `revenue_by_month` datasetu (`revenueSeries()`), **API beze změny**. Graf per měna s nenulovými daty; revenue KPI dlaždice už nemá sparkline (graf ho nahrazuje).
3. **`ActionItemsWidget.vue`** („Akce pro tebe"): místo list řádků karty v gridu s ikonou v kruhu dle závažnosti (výstraha/hodiny/info); dismiss menu (den/týden/historické/navždy) i restore zachovány 1:1.
4. i18n: + `dashboard.revenue_chart_title`, `dashboard.period_months/quarters/years` (cs+en).

**Proč:** Fáze 6 redesignu. **Jak ověřit po merge:** build ✓ testy 50/50 ✓; Přehled: dlaždice 32/700, červená „Po splatnosti" bez borderu, plný graf obratu s přepínačem období (Kvartály = součty Q), Akce pro tebe jako karty. Deep-linky dlaždic (year/currency/overdue/unpaid query) beze změny.

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

**Charakter: FEATURE — ODESLÁNO UPSTREAMU 2026-07-28: issue [#246](https://github.com/radekhulan/myinvoice/issues/246) + PR [#247](https://github.com/radekhulan/myinvoice/pull/247)** (větev `pr/user-supplier-access` na forku, postavená na v4.51.0, migrace přečíslovaná 0900 → 0148; podklady v `/root/usa-pr-snapshots/`). Issue navazuje na **#184**, kde tentýž požadavek padl jako bod 3 („pozvat externího uživatele jen do jedné firmy") a zůstal nenaplněný — autor issue zavřel s odpovědí na body 1–2 (přepínání firem). Po přijetí autorem blok z evidence odpadá. Ze všech fork funkcí je na PR nejlépe připravená: **žádná breaking změna** (žádný záznam = uživatel vidí vše, takže se stávajících instalací nedotkne), **žádné fork-only závislosti** (pracuje jen s upstream tabulkami `users` a `supplier`), integrační test i kapitola manuálu 36.2.3 existují, openapi se nemění.

**Před odesláním upstreamu vyřešit:**
1. **Přečíslovat migraci** `0900_user_supplier_access.sql` do upstream řady (upstream je k 2026-07-28 na 0147 → dát 0148+) a smazat komentář o fork rozsahu 0900.
2. **Rebasovat na aktuální upstream/master** a hlídat `SupplierScopeMiddleware.php` — nejrizikovější soubor, upstream ho mění (webauthn/mfa/session early-bypass); náš enforcement musí zůstat AŽ ZA ním.
3. Dle `CONTRIBUTING.md`: `php tools/generateManualHtml.php` po úpravě manuálu; projít `phpunit` + `pnpm type-check` + `build`.
4. V PR zdůraznit případ užití (externí účetní / klient vidí jen svoji firmu) a zpětnou kompatibilitu — to je hlavní argument pro přijetí.

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
