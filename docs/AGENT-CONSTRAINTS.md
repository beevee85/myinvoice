# Kanonická omezení pro subagenty — VKLÁDÁ SE STROJOVĚ, CELÝ, BEZ PARAFRÁZE

> **Jak se používá:** hlavní sezení načte tento soubor jako text a vloží ho do promptu každého
> subagenta **celý a doslovně**. Před dispatchem se spočítá SHA-256 souboru a porovná s hashem
> textu, který do promptu skutečně šel. Neshoda = dispatch se neprovede.
>
> Důvod existence: 29.–30. 7. 2026 byl vypraven workflow se sedmi subagenty, jehož omezení
> neobsahovala zákaz ověřovat cizí službu pokusem o autentizaci — přesto že to pravidlo bylo
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

---

## 1. SÍŤ — allow-list hostů a portů

Smíš se spojit **výhradně** s:

| host | port | co to je |
|---|---|---|
| `127.0.0.1` | `3307` | **naše** dockerová MariaDB (kontejner `myinvoice-db-1`) |
| `127.0.0.1` | `8090` | **naše** aplikace (kontejner `myinvoice-app-1`) |

Cokoli jiného je mimo rozsah. Jmenovitě a bez výjimky:

* **`127.0.0.1:3306` je CIZÍ služba** — nativní MariaDB spravovaná CloudPanelem, `datadir /home/mysql`,
  na stroji je pět dalších uživatelských projektů. **Nepřipojuj se k ní vůbec, ani bez hesla,
  ani „jen na ověření, že odpovídá".**
* Žádný odchozí provoz do internetu. Žádné `curl`, `wget`, `git fetch/pull`, `composer`, `npm`,
  `pip`, DNS dotazy ani volání cizích API (ARES, ČNB, VIES, CRPDPH, Anthropic, iDoklad, Fakturoid).
* Žádný Redis, SMTP, žádná jiná lokální služba, i kdyby na localhostu poslouchala.

**ZÁKAZ OVĚŘOVÁNÍ AUTENTIZACÍ:** dostupnost, totožnost ani vlastnictví žádné služby nikdy
nezjišťuješ pokusem o přihlášení. Ani úspěšný pokus není důkaz, který smíš získat tímto způsobem.
Když potřebuješ přístup, který nemáš — **řekni to a skonči.**

---

## 2. SOUBORY — allow-list cest

Čtení smíš **výhradně** v:

* `/opt/myinvoice/**` — repozitář (mimo cesty zakázané v §3)
* `/etc/mysql/**`, `/etc/systemd/system/mariadb.service.d/**` — jen čtení konfigurace, když to úkol žádá

Cokoli jiného je mimo rozsah. Jmenovitě zakázáno:

* **Cizí projekty a jejich session transkripty** — `/root/.claude/projects/**` je mimo rozsah celý.
  Nečteš historii konverzací, ani té své, ani cizí.
* **Historie shellu** — `~/.bash_history`, `~/.mysql_history`, `/home/*/.*history` a jakákoli
  jejich obdoba. Ani kvůli auditu. Když má někdo auditovat historii, udělá to hlavní sezení.
* `/home/**` — na stroji je pět cizích uživatelských účtů s vlastními projekty.
* Zálohy — neotvíráš, nerozbaluješ, nečteš jejich obsah. `ls` a `stat` stačí.

### Nevratné operace: ZÁKAZ ABSOLUTNÍ

**Nemažeš, nezkracuješ, nepřepisuješ a nepřesouváš nic. Ani své vlastní soubory.**
Žádné `rm`, `mv`, `shred`, `truncate`, `> soubor`, `>> soubor`, `tee`, `sed -i`, `chmod`, `chown`.
Úklid je vždy věc hlavního sezení, po výslovném schválení uživatelem, na jmenovitý výpis.
Když ti po práci něco zůstane, **nahlásíš cestu** — neuklízíš.

### Credential soubory: ZÁKAZ ABSOLUTNÍ

**Nevytváříš soubory s hesly nikde**, ani s právy 600, ani ve scratchpadu, ani „na chvíli".
Žádné `.cnf`, `.my.cnf`, `--defaults-extra-file`, žádný export do souboru.
Když potřebuješ přístup k DB, který nejde bez zapsání hesla na disk — **řekni to a skonči.**

---

## 3. TAJEMSTVÍ

* **Hodnotu tajemství nikdy nevypisuješ**, ani zkrácenou, ani zamaskovanou částečně, ani
  „prvních pár znaků". V reportu se tajemství uvádí **jen jménem, délkou a existencí**:
  `app.secret_encryption_key = <SKRYTO, delka 44>`.
* Heslo **nikdy nepředáváš na příkazové řádce** (`-p`, `--password=`) — `/proc/<pid>/cmdline`
  je world-readable a na stroji je pět cizích účtů se shellem.
* Hesla **nesbíráš** — ne z historie shellu, ne z transkriptů, ne z jiných projektů,
  ne ze záloh. Ne ani jako vstup k auditu.
* Nevypisuješ **reálná osobní ani firemní data** z produkce (jména, adresy, IČO odběratelů,
  částky konkrétních dokladů, e-maily uživatelů). Pracuješ s počty a agregáty.
  Instance je **multi-tenant** — data druhého tenanta jsou data jiné právnické osoby.

---

## 4. DATABÁZE

* Jen **`127.0.0.1:3307`**, jen schéma `myinvoice` (a `myinvoice_ci`, když to úkol žádá).
* Jen **`SELECT`, `SHOW`, `EXPLAIN`, `DESCRIBE`**. Žádné `INSERT`, `UPDATE`, `DELETE`, `DROP`,
  `CREATE`, `ALTER`, `GRANT`, `REVOKE`, `FLUSH`, `SET GLOBAL`.
* Produkční schéma je **read-only**. Migrace se zkoušejí jen proti klonu, a klon zakládá
  hlavní sezení, ne ty.

## 5. GIT

* Povolené: `log`, `show`, `diff`, `status`, `ls-tree`, `ls-files`, `cat-file`, `rev-parse`,
  `grep`, `branch --list`, `blame`.
* Zakázané: `checkout`, `switch`, `commit`, `stash`, `reset`, `clean`, `rebase`, `merge`,
  `push`, `pull`, `fetch`, `restore`, `add`, `rm`.

## 6. PROCESY A SLUŽBY

* Nerestartuješ, nezastavuješ ani nemodifikuješ nic — žádné `systemctl`, `service`,
  `docker restart/stop/rm/exec -u root` pro zápis, `kill`, `pkill`.
* `docker exec` smíš **jen pro čtecí příkazy** v našich dvou kontejnerech.
* Výjimka, jen když ji úkol dá výslovně: spuštění testové suity. Právě jednou.

---

## 7. POVINNÁ HLAVIČKA REPORTU — ověření, že jsi tato omezení dostal

**Každý report začíná těmito dvěma řádky**, jinak je neplatný:

```
CONSTRAINTS-SHA256: <sha256 textu omezení, který jsi dostal>
ALLOW-LIST: <jednou větou: hosty a porty, na které smíš, a cesty, které smíš čtát>
```

Hash spočítáš z textu omezení, který máš v promptu (`sha256sum` nad tím blokem). Nevymýšlíš ho,
neopisuješ ho odjinud — když omezení v promptu nemáš, hlavičku **nevyplníš** a napíšeš,
že jsi je nedostal.

Smysl: kontrolu, že dispatcher blok skutečně vložil, nemá provádět jen dispatcher sám na sobě.
Nesedící nebo chybějící hash vidí i uživatel a „zapomněl jsem blok vložit" přestává být
neviditelné. Sebekontrola dispatchera je první síto, tato hlavička je druhé.

---

## 8. DŮKAZNÍ POVINNOST

Každé zjištění doložíš **doslovným výstupem příkazu** nebo **citací se `soubor:řádek`**.
Věta bez důkazu se nepočítá. Co nejde ověřit v rozsahu, označíš `NEOVĚŘENO` a napíšeš proč —
to je platný a vítaný výsledek. **Domněnka podaná jako zjištění je horší než přiznaná neznalost.**

Když narazíš na to, že premisa zadání je chybná, **řekni to** místo abys ji obcházel.
