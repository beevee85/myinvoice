# Jak pouštět testy (izolovaná databáze) — fork beevee85

Pravidlo **V83** ze zadání dávkového importu: *Integration testy odmítnou běžet, pokud
jméno DB neodpovídá test patternu (žádné sdílené `myinvoice_ci`).* Tenhle dokument
popisuje, proč to tak je a jak si testovací prostředí připravit.

---

## 1. Proč vlastní databáze

Dva důvody, oba reálné:

1. **Ochrana produkce.** Integrační testy zakládají a **mažou** řádky (faktury, klienty,
   dodavatele). Na nativní instalaci (IIS/Apache) je `cfg.php` v kořeni repa **zároveň
   produkční konfigurací** — `vendor/bin/phpunit` tam dosud zapisoval rovnou do ostré
   účetní databáze a nic tomu nebránilo.
2. **Hygiena souběžných session.** O generické jméno (`myinvoice_ci`) soupeří paralelní
   běhy: jeden test smaže data, o která se opírá druhý, a suita padá na cizí data.

Proto `api/tests/bootstrap.php` volá `TestDatabaseGuard::assertOrExit()` ještě předtím,
než se otevře jakékoli spojení. Guard čte **jen konfiguraci**, na databázi nesahá.

---

## 2. Příprava vlastní testovací databáze

```bash
docker exec myinvoice-db-1 sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e \
  "CREATE DATABASE IF NOT EXISTS myinvoice_test_<ucel> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
```

Zbytek udělá **jeden chráněný příkaz**:

```bash
cd /opt/myinvoice && docker run --rm --network host \
  -e MYINVOICE_DB_NAME=myinvoice_test_<ucel> \
  -v /opt/myinvoice:/work -w /work myinvoice:latest php api/bin/test-db-prepare.php
```

`test-db-prepare.php` nejdřív ověří cíl guardem a teprve pak spustí tři kroky:

| krok | co udělá |
|---|---|
| `migrate.php --no-backfills` | schéma + globální číselníky (`countries`, `vat_rates`, `units`, `vat_classifications`). `--no-backfills` je záměr: auto-backfilly zapisují a volají ČNB po síti |
| `ci-seed.php` | 2 tenanti, `currencies` CZK+EUR per tenant (CZK s mod-11 účtem), 1 admin — **upstream skript, needitovat** |
| `test-seed-clients.php` | **fork-only**: 3 syntetičtí klienti per tenant (odběratel / dodavatel / obojí) + IČO tenantů |

Celý wrapper je idempotentní — opakované spuštění nic nerozbije.

> **Nespouštěj `api/bin/*.php` ručně.** Skripty v `api/bin/` guard nevolají (viz sekce 4)
> a `reset.php` umí `TRUNCATE` celé databáze. Postup přes wrapper existuje právě proto,
> aby se nedalo zapomenout na `MYINVOICE_DB_NAME` u jednoho ze tří příkazů.

---

## 3. Spuštění testů

```bash
docker run --rm --network host -e MYINVOICE_DB_NAME=myinvoice_test_<ucel> \
  -v /opt/myinvoice:/work -w /work/api myinvoice:latest vendor/bin/phpunit
```

Host PHP 8.4 nestačí (`composer.json` vyžaduje `^8.5`), proto docker image.
`--network host` je nutný kvůli `127.0.0.1:3307`.

**Proč `MYINVOICE_DB_NAME` a ne editace `cfg.php`:** `cfg.php` je sdílený se souběžnými
session a je gitignored. ENV override je bezpečný — ověřeno: `ConfigEnvOverridesTest`
asertuje výhradně `db.host` a `db.port` přes aliasy `MYSQL_*`, `db.name` se nedotýká.
(Upstream CI se ENV proměnným vyhýbá záměrně a generuje si `cfg.php` souborem —
`.github/workflows/ci.yml:72-102`.)

Po běhu ukliď stopy:

```bash
rm -f /opt/myinvoice/api-20??-??-??            # log, který testy píší do kořene repa
rm -rf /opt/myinvoice/storage/purchase-invoices/sources/supplier-*   # viz sekce 6
```

---

## 4. Co guard dělá

Zdroj: `api/tests/Support/TestDatabaseGuard.php`. Rozhoduje se ve třech vrstvách,
v tomhle pořadí:

**1) Značka ostrého provozu — nedá se přebít ničím.**

```
/(^|[_\-])(prod(uction)?|ostr[aá]|live|ostry)([_\-]|$)/i
```

Kdyby tahle vrstva nebyla, prošla by jména jako `ci_prod`, `myinvoice_ci_prod` nebo
`fakturace_test_ostra` — mají testovací segment, ale jsou to ostré databáze. Kontrola
běží i tehdy, když si uživatel nastaví vlastní vzor.

**2) Vzor povolených jmen** (přebitelný přes `MYINVOICE_TEST_DB_PATTERN`):

```
/(^|[_\-])(tests?|testing|ci|qa|sandbox)\d*([_\-]|$)/i
```

Jméno musí obsahovat **celý segment** `test`/`tests`/`testing`/`ci`/`qa`/`sandbox`,
volitelně s číselným sufixem, ohraničený začátkem/koncem jména nebo `_`/`-`.

**3) Denylist generických jmen** — `ci`, `test`, `tests`, `myinvoice_ci`,
`myinvoice_test`, `myinvoice_tests`. Ta si vybere každý, takže o ně nutně soupeří
souběžné běhy. Mimo CI se blokují.

| jméno databáze | verdikt | proč |
|---|---|---|
| `myinvoice` | **STOP** | produkce |
| `myinvoice_prod`, `ci_prod`, `fakturace_test_ostra` | **STOP** | značka ostrého provozu |
| `myinvoice_37a`, `myinvoice_backup` | **STOP** | klon/záloha bez testovacího segmentu |
| `myinvoice_testovaci`, `myinvoice_citace` | **STOP** | `test`/`ci` musí být celý segment, ne podřetězec |
| `myinvoice_ci`, `myinvoice_test`, holé `ci`/`test` **mimo CI** | **STOP** | generické jméno |
| `myinvoice_ci` **v CI** | OK | upstream `ci.yml` ji zakládá jako službu — guard cizí pipeline rozbít nesmí |
| `myinvoice_test_batchimport`, `myinvoice_ci2`, `test01`, `mi-test-1`, `myinvoice_qa` | OK | vlastní klon |
| prázdné `db.name` | **STOP** | vždycky chyba konfigurace, guard raději nehádá |

CI se pozná podle `GITHUB_ACTIONS`, `GITLAB_CI`, `BUILDKITE` nebo `CIRCLECI`.
**Holé `CI` záměrně neplatí** — exportuje ho spousta nástrojů a agentních harnessů,
takže by jediná zděděná proměnná vypnula přesně tu ochranu, kvůli které guard vznikl.
Jenkins/TeamCity nad sdílenou databází si musí nastavit `MYINVOICE_TEST_DB_ALLOW_SHARED=1`.

Při zablokování guard končí kódem **78** (`EX_CONFIG`) — odlišitelným od `1`/`2`,
kterými PHPUnit hlásí červené testy.

### Únikové cesty

```bash
MYINVOICE_TEST_DB_PATTERN='/^vlastni_vzor$/i'   # jiný vzor povolených jmen
MYINVOICE_TEST_DB_ALLOW_SHARED=1                # povol generické jméno i mimo CI
```

Obě se parsují case-insensitive (`TRUE`, `Yes`, `on` fungují) a **ani jedna neotevře
cestu k produkci** — vrstva 1 platí vždy. Neplatný vlastní vzor guard hlásí jako chybu,
ne jako „prošlo".

### Co guard NEpokrývá

Guard visí na `tests/bootstrap.php`, takže chytá **každý běh phpunitu** —
`vendor/bin/phpunit`, `cmd/test.sh`, `cmd/test.ps1`, CI i spuštění z IDE. Nechytá ale:

* **Host a port.** Guard validuje jen **jméno schématu**. `MYINVOICE_DB_HOST` mířící na
  produkční stroj se schématem `myinvoice_ci` projde. Hláška proto vždy vypisuje
  `host:port/dbname`, ať je vidět, kam se běh chystal. Rozšíření na host by znamenalo
  hádat allowlist a rozbíjet cizí instalace.
* **CLI skripty v `api/bin/`** — `migrate.php`, `reset.php` (`TRUNCATE`!), `sample.php`,
  `backfill-*.php` jdou proti té databázi, kterou dostanou. Výjimky: `ci-seed.php` má
  vlastní pojistku (odmítne běžet, když už existuje dodavatel nebo uživatel) a fork-only
  `test-seed-clients.php` + `test-db-prepare.php` guard volají.
* **Subprocesy testů** — `tests/Support/AuthConcurrencyWorker.php` si otevírá vlastní
  spojení mimo `tests/bootstrap.php`. Dnes neškodné (rodič guardem prošel), ale každý
  budoucí worker stejného tvaru je mimo ochranu.
* **Ruční `phpunit --bootstrap …` / `--no-configuration`** — kdo si vypne konfiguraci,
  vypne i guard. To je vědomé obejití, ne díra.

### Vědomé rozhodnutí: blokuje se celý běh, ne jen Integration suita

Guard je v bootstrapu, takže na instalaci s produkčním `db.name` nejde spustit ani
`--testsuite Unit`, ani `--testsuite Architecture`. Je to záměr: **6 „Unit" testů
sahá na ostrou DB** (`tests/Unit/Service/Auth/*`) a `AtomicAuthTransitionTest` si tam
dokonce **zakládá uživatele**. Kdyby se guard scopoval jen na Integration suitu,
`--testsuite Unit` by proti produkci prošel a data vytvořil.

Cena je jeden falešný poplach pro toho, kdo chce pustit čistě statické testy na
produkčním `cfg.php`. Řešení je stejné jako pro všechno ostatní: `MYINVOICE_DB_NAME`
na testovací databázi.

**Pozor na pořadí v `tests/bootstrap.php`:** `DG\BypassFinals::enable()` musí zůstat
**před** guardem. `BypassFinals` přepisuje třídy při načtení souboru, takže cokoli
načteného dřív si ponechá `final` a přestane jít mockovat. Guard sahá na `Config` —
při obráceném pořadí spadne 123 unit testů na `ClassIsFinalException`. Hlídá to
`tests/Architecture/TestDatabaseGuardWiringTest.php`, který zároveň odhalí, kdyby
merge upstreamu volání guardu z bootstrapu tiše smazal.

---

## 5. Výchozí stav suity (baseline)

Měřeno na `myinvoice_test_batchimport`:

| stav | testy | asercí | skipped | selhání |
|---|---:|---:|---:|---:|
| jen `ci-seed.php` (výchozí stav před Commitem 1) | 2 015 | 6 661 | **134** | 0 |
| + `test-seed-clients.php` | 2 015 | 7 051 | **31** | 0 |
| + testy guardu a wiring test (Commit 1) | **2 099** | **7 173** | **31** | **0** |

Doplnění klientů a IČO tenantů odemklo **103 dosud nikdy nespuštěných testů**
(+390 asercí) — a to právě v okolí přijatých faktur, dodavatelů a cross-tenant guardů,
kam dávkový import sahá.

### Zbývajících 31 skipů

| počet | důvod | řešitelné? |
|---:|---|---|
| 26 | `Dev server https://dev.myinvoice.cz` nedostupný | ne — upstream testy kontroly verzí, závisí na cizí službě |
| 4 | chybí předem existující faktury (vydaná, cizoměnová, přijatá, bez zakázky) | ano, ale fixture faktur by rozbil testy, které počítají řádky — vědomě neděláme |
| 1 | „Supplier B je reálná firma z DB — mazat ho nebudeme" | ne, obranná logika testu |

---

## 6. Známé stopy po běhu

* `api-YYYY-MM-DD` v kořeni repa — gitignored (`.gitignore:47`), ale roste; mazat.
* `log/php-errors.log`, `log/app-YYYY-MM-DD.log` — píše každý test, který bootuje DI.
* `storage/purchase-invoices/sources/supplier-N/…` — `PurchaseInvoiceSourceArchiveTest`
  jako **jediný** trvale zapisuje do `storage/`; jeho `tearDown()` maže jen DB řádky,
  soubory zůstávají. Uklidit ručně (viz sekce 3).
* `api/.phpunit.cache/` — result cache, gitignored.

## 7. Skryté pasti

* **`trip_categories`** — migrace `0109_logbook.sql` seeduje kategorie jen pro
  dodavatele existující *v době migrace*. Na cestě „prázdná DB → migrate → ci-seed"
  vzniknou tenanti až po migraci, takže kniha jízd zůstane bez kategorií. Dnes to
  žádný test nekontroluje.
* **`varsymbol.templates` v `cfg.php`** — bez této sekce padá 6 testů
  `RecurringGeneratorTest` na „Chybí template pro invoice". Lokální `cfg.php` ji má,
  `cfg.php` generovaný v CI **ne** (tam se ty testy místo toho skipnou).
* **Fixture IČO musí projít mod 11** — validátory jinak data odmítnou. Generátor
  kontrolní číslice je v `api/bin/test-seed-clients.php` (`fixtureIcoFromBase()`).
