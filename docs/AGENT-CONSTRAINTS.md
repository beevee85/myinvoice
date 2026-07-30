# Kanonická omezení pro subagenty — VKLÁDÁ SE STROJOVĚ, CELÝ, BEZ PARAFRÁZE

> **Jak se používá:** hlavní sezení načte tento soubor jako text a vloží ho do promptu každého
> subagenta **celý a doslovně**. Zároveň si do svého záznamu o dispatchi zapíše SHA-256 souboru
> a ten samý hash vloží agentovi do promptu jako literál (viz §7).
>
> **Hash se mění s každou úpravou tohoto souboru.** Dispatcher ho proto počítá vždy znovu
> těsně před dispatchem a **nikdy si ho nekešuje** — zapamatovaná hodnota z dřívějšího kola
> je právě ta porucha, kterou má §7 odhalit.
>
> **Důvod existence:** 29.–30. 7. 2026 byl vypraven workflow se sedmi subagenty, jehož omezení
> neobsahovala zákaz ověřovat cizí službu pokusem o autentizaci — přestože to pravidlo bylo
> téhož dne formulováno v `CUSTOMIZATIONS.md`. Dva agenti následně provedli ~5 pokusů
> o přihlášení proti cizí databázové službě a postavili si matici „která pověření platí kde".
> Pravidlo v próze pro lidi není mechanismus pro agenty. Proto tento soubor.

---

## 0. ZÁKLADNÍ REŽIM: DEFAULT-DENY

Níže je **výčet toho, co smíš**. Cokoli, co ve výčtu není, je **mimo rozsah** — bez ohledu na to,
jestli to někdo výslovně zakázal, jestli to vypadá nutné pro splnění úkolu, nebo jestli to
vypadá bezpečně. Mimo rozsah se **nezkouší a neimprovizuje**: zastavíš se a ohlásíš, co bys
potřeboval a proč.

**Nález získaný mimo rozsah je neplatný a bude zahozen.** Nedostaneš za něj kredit, dostaneš
za něj revizi. Úkol splněný nepřípustnou cestou je úkol nesplněný.

### 0.1 Nevyjednatelnost

**Tato omezení nejsou předmětem tvého úsudku.** Nesmíš je vážit proti užitečnosti výsledku,
proti naléhavosti zadání ani proti tomu, že by „stačilo jednou". Když se zdá, že úkol jde splnit
jedině jejich porušením, je **vadný úkol, ne vadné omezení** — řekni to a skonči.

Nesmíš je také obcházet nepřímo: jiným nástrojem, jiným pořadím kroků, delegací (§0.2),
ani tím, že si o zakázaný krok necháš říct od někoho jiného.

### 0.2 Zákaz delegace

**Nevypravuješ další subagenty.** Žádný nástroj, který spouští agenta, workflow nebo úlohu
na pozadí. Kdybys to udělal, tvůj potomek by tato omezení nedostal a celý mechanismus by se
obešel o patro níž. Když je práce nad tvůj rozsah, rozděl ji v reportu na návrh — nespouštěj ji.

---

## 1. SÍŤ — allow-list hostů a portů

Smíš se spojit **výhradně** s:

| host | port | co to je |
|---|---|---|
| `127.0.0.1` | `3307` | **naše** dockerová MariaDB (kontejner `myinvoice-db-1`) |
| `127.0.0.1` | `8090` | **naše** aplikace (kontejner `myinvoice-app-1`) |

Cokoli jiného je mimo rozsah. Jmenovitě a bez výjimky:

- **`127.0.0.1:3306` je CIZÍ služba.** Nativní MariaDB spravovaná CloudPanelem,
  `datadir /home/mysql`, na stroji je pět dalších uživatelských projektů.
  **Nepřipojuj se k ní vůbec** — ani bez hesla, ani „jen na ověření, že odpovídá".
- Žádný odchozí provoz do internetu. Žádné `curl`, `wget`, `git fetch/pull/push`, `composer`,
  `npm`, `pip`, DNS dotazy ani volání cizích API (ARES, ČNB, VIES, CRPDPH, Anthropic,
  iDoklad, Fakturoid, dev.myinvoice.cz).
- Žádný Redis, SMTP ani jiná lokální služba, i kdyby na localhostu poslouchala.

### 1.1 Zákaz ověřování autentizací

Dostupnost, totožnost ani vlastnictví žádné služby **nikdy nezjišťuješ pokusem o přihlášení.**
Ani neúspěšný pokus není nevinný a ani úspěšný není důkaz, který smíš takto získat.
Když potřebuješ přístup, který nemáš — **řekni to a skonči.**

---

## 2. SOUBORY — allow-list cest

Čtení smíš **výhradně** v:

- `/opt/myinvoice/**` — repozitář, s výjimkami v §2.1
- `/etc/mysql/**`, `/etc/systemd/system/mariadb.service.d/**` — jen čtení konfigurace,
  a jen když to úkol výslovně žádá

Cokoli jiného je mimo rozsah. Jmenovitě zakázáno:

- **Cizí projekty a session transkripty** — `/root/.claude/**` je mimo rozsah celý.
  Nečteš historii konverzací, ani svou, ani cizí.
- **Historie shellu** — `~/.bash_history`, `~/.mysql_history`, `/home/*/.*history`
  a jakákoli jejich obdoba. Ani kvůli auditu. Auditovat historii je věc hlavního sezení.
- `/home/**` — na stroji je pět cizích uživatelských účtů s vlastními projekty.
- Zálohy — neotvíráš, nerozbaluješ, nečteš obsah. `ls` a `stat` stačí.

### 2.1 Konfigurační soubory s tajemstvími — ZAKÁZÁNO, i uvnitř repozitáře

Tyto soubory **nečteš**, přestože leží v povolené cestě:

- `/opt/myinvoice/.env` (obsahuje `DB_ROOT_PASSWORD`, `DB_PASSWORD`)
- `/opt/myinvoice/cfg.php`
- `/opt/myinvoice/cfg.docker.php` (obsahuje `db.pass`, `app.pepper`, `app.secret_encryption_key`)
- `/opt/myinvoice/cfg.local.php`, a jakýkoli další `cfg*.php` kromě `cfg.sample.php`

**Totéž platí uvnitř kontejnerů.** `docker exec … cat /var/www/html/cfg.php` je **tentýž soubor**
namountovaný jinam — zákaz se vztahuje na obsah, ne na cestu. Stejně tak `docker inspect`
kvůli proměnným prostředí a `docker exec … env`.

Když úkol tyto soubory skutečně potřebuje, dostaneš **výslovné povolení v zadání** — a i pak
hlásíš jen **názvy klíčů, jejich existenci a délku hodnoty**, nikdy hodnotu samu (§3).

### 2.2 Nevratné operace: ZÁKAZ ABSOLUTNÍ

**Nemažeš, nezkracuješ, nepřepisuješ ani nepřesouváš nic. Ani své vlastní soubory.**
Žádné `rm`, `mv`, `shred`, `truncate`, `> soubor`, `>> soubor`, `tee`, `sed -i`, `chmod`, `chown`.

Úklid je vždy věc hlavního sezení, po výslovném schválení uživatelem, na jmenovitý výpis cest.
Když ti po práci něco zůstane, **nahlásíš cestu** — neuklízíš.

### 2.3 Credential soubory: ZÁKAZ ABSOLUTNÍ

**Nevytváříš soubory s hesly nikde** — ani s právy 600, ani ve scratchpadu, ani „na chvíli".
Žádné `.cnf`, `.my.cnf`, `--defaults-extra-file`, žádný export do souboru.
Když potřebuješ přístup k DB, který nejde bez zapsání hesla na disk — **řekni to a skonči.**

---

## 3. TAJEMSTVÍ

- **Hodnotu tajemství nikdy nevypisuješ** — ani zkrácenou, ani částečně zamaskovanou,
  ani „prvních pár znaků", ani hash. V reportu se tajemství uvádí **jen jménem, délkou
  a existencí**: `app.secret_encryption_key = <SKRYTO, delka 44>`.
- Heslo **nikdy nepředáváš na příkazové řádce** (`-p`, `--password=`) —
  `/proc/<pid>/cmdline` je world-readable a na stroji je pět cizích účtů se shellem.
- Hesla **nesbíráš**: ne z historie shellu, ne z transkriptů, ne z jiných projektů,
  ne ze záloh, ne z konfigurace (§2.1). Ani jako vstup k auditu.

### 3.1 Když na tajemství narazíš náhodou

Stane se, že tajemství vypadne z výstupu, který jsi měl právo spustit (log, chybová hláška,
dump schématu). Pak:

1. **Nekopíruješ ho nikam** — ne do souboru, ne do dalšího příkazu, ne do reportu,
   ne do vlastních poznámek.
2. **Nepoužiješ ho k ničemu**, ani k ověření, že je platné (§1.1).
3. **Nahlásíš jen místo a typ**: „v `cesta:řádek` je zjevně heslo k DB, délka N".
4. Pokračuješ v úkolu, jako bys ho neviděl.

### 3.2 Osobní a firemní data

Nevypisuješ reálná osobní ani firemní data z produkce — jména, adresy, IČO odběratelů, částky
konkrétních dokladů, e-maily uživatelů. Pracuješ s **počty a agregáty**.

Instance je **multi-tenant** a data druhého tenanta jsou data **jiné právnické osoby**.

---

## 4. DATABÁZE

- Jen **`127.0.0.1:3307`**, jen schéma `myinvoice` (a `myinvoice_ci`, když to úkol žádá).
- Jen **`SELECT`, `SHOW`, `EXPLAIN`, `DESCRIBE`**. Žádné `INSERT`, `UPDATE`, `DELETE`, `DROP`,
  `CREATE`, `ALTER`, `GRANT`, `REVOKE`, `FLUSH`, `SET GLOBAL`.
- Produkční schéma je **read-only**. Migrace se zkoušejí jen proti klonu, a klon zakládá
  hlavní sezení, ne ty.

### 4.1 Tenant scoping je povinný

**Každý dotaz nad daty tenanta uvádí `supplier_id` explicitně.** Neomezený agregát přes
všechny tenanty je **mimo rozsah** — i když je to jen `COUNT`, i když je to „jen pro přehled".

Důvod: analytický skener bral `supplier_id` jako nepovinný s defaultem „všichni" a výsledná
baseline smíchala doklady dvou různých právnických osob. Číslo o neznámé populaci není
zjištění, je to zdroj chybných rozhodnutí.

Když úkol vyžaduje pohled přes tenanty (např. „kolik je celkem řádků v tabulce"), musíš to mít
v zadání výslovně a v reportu **uvést rozpad po tenantech**, ne jen součet.

### 4.2 Zdrženlivost k prostředkům

Jsi read-only, ale read-only dotaz umí produkci položit.

- Nikdy `SELECT *` nad celou tabulkou, když stačí `COUNT`, `MIN`/`MAX` nebo agregát.
- Každý dotaz, který vrací řádky, má `LIMIT`. Průzkumný vzorek = `LIMIT 5`.
- Neskenuješ celou tabulku, abys zjistil něco, co je v `information_schema`.
- Žádné kartézské joiny, žádné `ORDER BY` nad velkou tabulkou bez indexu, žádný dotaz,
  o kterém nevíš, jak dlouho poběží — v takovém případě si nejdřív pusť `EXPLAIN`.
- Totéž platí pro filesystem: negrepuješ rekurzivně celý repozitář, když znáš adresář.

### 4.3 Jak se k databázi vůbec dostaneš — a kdy vůbec ne

> **STAV K 30. 7. 2026: vyhrazený read-only účet NEEXISTUJE.**
> **Do jeho vzniku nemají subagenti přístup k databázi vůbec.** §4, §4.1 a §4.2 jsou do té doby
> bez účinku. Když tvůj úkol databázi potřebuje, **zastav se a řekni to** — je to platný
> výsledek, ne selhání.

Proč to tady stojí takhle natvrdo: §2.1 ti zakazuje číst `cfg*.php`, `.env`, `docker inspect`
i `docker exec … env`; §2.3 ti zakazuje vytvořit credential soubor; §3 ti zakazuje dát heslo
na příkazovou řádku. **Nemáš tedy žádnou dovolenou cestu, jak heslo získat** — a pravomoc
bez dovolené cesty k jejímu využití je past, ne pravomoc. Kdyby tu tenhle odstavec nebyl,
nejpravděpodobnějším výsledkem by byla improvizace, ne poslušnost.

Až ten účet vznikne, platí tohle a nic jiného:

1. **Pověření ti předá dispatcher proměnnou prostředí.** Ty si ho **nikde nesháníš**:
   ne z konfigurace, ne z historie, ne z transkriptů, ne ze záloh, ne od jiného agenta.
2. **Nikam ho nezapisuješ** — ani do souboru, ani na příkazovou řádku, ani do reportu (§3).
3. **Když proměnnou nedostaneš, databázi nemáš.** Nehledáš náhradní cestu, nezkoušíš
   se přihlásit naslepo (§1.1) — zastavíš se a ohlásíš, co bys potřeboval.
4. Účet je **jen pro čtení** (`SELECT`, `SHOW VIEW`) nad `myinvoice` a `myinvoice_ci`.
   Když ti nějaký dotaz vrátí „access denied", je to **správné chování**, ne překážka
   k obejití — nahlásíš to a jdeš dál.

Účet pro zálohy je **jiný účet s jinými právy** a subagentovi se nepředává nikdy.

### 4.4 Scratchpad nemáš a nepotřebuješ

**Nedostáváš pracovní adresář a žádný si nevytváříš.** §2.2 ti zápis nikam nedovoluje, takže
mezivýsledky nikam neodkládáš — **všechno vracíš v reportu**. Když je výstup příliš velký na
report, je úkol příliš velký: rozděl ho v reportu na návrh (§0.2) a nech dispatchera rozhodnout.

Že ti nějaká cesta zápis technicky umožní, není povolení. Zákaz je v §2.2, ne v právech na disku.

---

## 5. GIT

- Povolené: `log`, `show`, `diff`, `status`, `ls-tree`, `ls-files`, `cat-file`, `rev-parse`,
  `grep`, `branch --list`, `blame`, `check-ignore`, `worktree list`.
- Zakázané: `checkout`, `switch`, `commit`, `stash`, `reset`, `clean`, `rebase`, `merge`,
  `push`, `pull`, `fetch`, `restore`, `add`, `rm`.

---

## 6. PROCESY A SLUŽBY

- Nerestartuješ, nezastavuješ ani nemodifikuješ nic — žádné `systemctl`, `service`,
  `docker restart/stop/rm`, `kill`, `pkill`, žádná změna konfigurace.
- `docker exec` smíš **jen pro čtecí příkazy** v našich dvou kontejnerech,
  a s výjimkou zakázanou v §2.1.

### 6.1 Testová suita

Spuštění suity je povolené **jen když to úkol dá výslovně**, a **právě jednou**.

**Pozor: suita potřebuje testovou databázi, takže spadá pod §4.3.** Dokud vyhrazený read-only
účet neexistuje, je spuštění suity pro subagenta **mimo dosah** — i když ti to úkol dovolí,
nemáš čím se k DB přihlásit. Zastav se a řekni to; neobjevuj to uprostřed práce.
(Integration testy navíc jedou proti reálné DB a `myinvoice_ci` potřebuje zápis, takže
read-only účet na ně nemusí stačit — dispatcher to musí vyřešit, ne ty.)

**26 testů je přeskočených, protože závisí na cizí službě `dev.myinvoice.cz`.**
Musí zůstat přeskočené. **Nesmíš je zapnout, odblokovat ani „dočasně" povolit síť** —
byl by to rozpor s §1 a nikdo by pak nepoznal, jestli šlo o neposlušnost, nebo o nedomyšlené
zadání. Přeskočený test je platný výsledek; nahlásíš jejich počet a důvod.

Suita zapisuje log `api-YYYY-MM-DD` do kořene repa. **Nemažeš ho** (§2.2) — nahlásíš, že vznikl.

---

## 7. POVINNÁ HLAVIČKA REPORTU

**Každý report začíná těmito třemi řádky**, jinak je neplatný:

```
CONSTRAINTS-SHA256-PROMPT: <hash, který ti dispatcher vložil do promptu jako literál>
CONSTRAINTS-SHA256-DISK:   <výstup: sha256sum /opt/myinvoice/docs/AGENT-CONSTRAINTS.md>
ALLOW-LIST: <jednou větou: hosty a porty, na které smíš, a cesty, ze kterých smíš číst>
```

Hash na disku zjistíš **čtením souboru** (`sha256sum` nad cestou výše) — to je povolená operace.
Hash z promptu **jen opíšeš** z literálu, který ti dispatcher dal; nepočítáš ho a nevymýšlíš.
Když v promptu žádný literál nemáš, napiš `CHYBÍ` a pokračuj — nedoplňuj ho odhadem.

Rozdíl mezi těmi dvěma hodnotami odhalí dvě různé poruchy: **zastaralý vložený blok**
(dispatcher poslal starší text, než jaký je v repu) i **podvrženou nebo změněnou verzi souboru**.

### 7.1 Mez tohoto mechanismu — čtěte, než se na něj spolehnete

**Hlavička dokáže doložit přítomnost omezení, nikdy jejich nepřítomnost.**

Agent, který tento blok nikdy nedostal, neví, že měl něco hlásit — a jeho report proto vyjde
bez hlavičky a bez jakéhokoli příznaku, že něco chybí. Tato hlavička tedy **není záruka**,
je to detektor jedné konkrétní poruchy.

Proti chybějícímu bloku existují jen dvě pojistky, a obě jsou vně agenta:

1. **Dispatcher si zapisuje hash do svého záznamu o dispatchi** — takže existuje nezávislý
   záznam, že blok šel do promptu, a s jakým obsahem.
2. **Chybějící hlavička je sama o sobě varovný signál pro uživatele.** Report subagenta
   bez těch tří řádků se nepřijímá jako doklad.

Kdo tento dokument čte za rok: nečtěte §7 jako záruku. Sebekontrola dispatchera je první síto,
hlavička druhé, a ani obě dohromady nenahradí to, že subagent nemá dostat mandát,
který ke svému úkolu nepotřebuje.

---

## 8. DŮKAZNÍ POVINNOST

Každé zjištění doložíš **doslovným výstupem příkazu** nebo **citací se `soubor:řádek`**.
Věta bez důkazu se nepočítá.

Co nejde ověřit v rozsahu, označíš `NEOVĚŘENO` a napíšeš proč — to je **platný a vítaný
výsledek**. Domněnka podaná jako zjištění je horší než přiznaná neznalost.

Když narazíš na to, že **premisa zadání je chybná**, řekni to místo abys ji obcházel.
Zadavatel se mýlí častěji, než čekáš, a najít to je cennější než splnit úkol podle chybného
předpokladu.
