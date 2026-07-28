<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * FORK (beevee85) — pojistka proti spuštění testů proti ostré nebo sdílené databázi.
 *
 * Integrační testy v tomto repu zakládají a mažou reálné řádky (faktury, klienty,
 * dodavatele). Na nativní instalaci (IIS/Apache) je `cfg.php` v kořeni repa ZÁROVEŇ
 * produkční konfigurací — `vendor/bin/phpunit` tam dnes zapisuje rovnou do ostré
 * účetní databáze. Tahle třída to zarazí dřív, než se naváže první spojení.
 *
 * Druhá role: hygiena souběžných session. O generické jméno (`myinvoice_ci`) soupeří
 * paralelní běhy — jeden test smaže data, o která se opírá druhý — takže mimo CI
 * vyžadujeme vlastní klon s vlastním sufixem.
 *
 * Rozhodovací část (`decide()`) je čistá funkce bez ENV, FS a DB, aby šla testovat
 * tabulkově. Práci s prostředím a ukončení běhu dělá až `assertOrExit()`; ten je
 * pokrytý subprocesovým testem, protože je to jediná část, která reálně něco vypíná.
 */
final class TestDatabaseGuard
{
    /** Přebití vzoru povolených jmen (plný PCRE včetně oddělovačů). */
    public const ENV_PATTERN = 'MYINVOICE_TEST_DB_PATTERN';

    /** `1`/`true`/`yes` (case-insensitive) = povol i generickou sdílenou DB mimo CI. */
    public const ENV_ALLOW_SHARED = 'MYINVOICE_TEST_DB_ALLOW_SHARED';

    /** Ukončovací kód při zablokování — `EX_CONFIG`, ať jde odlišit od červených testů. */
    public const EXIT_CODE = 78;

    /**
     * Jméno databáze musí obsahovat celý segment `test`/`tests`/`testing`/`ci`/`qa`/
     * `sandbox`, volitelně s číselným sufixem, ohraničený začátkem/koncem jména nebo `_`/`-`.
     *
     * Projde: myinvoice_ci, myinvoice_ci2, myinvoice_test_batchimport, test01,
     *         myinvoice-test, myinvoice_testing, myinvoice_qa.
     * Neprojde: myinvoice, myinvoice_37a, myinvoice_backup, myinvoice_citace.
     */
    public const DEFAULT_PATTERN = '/(^|[_\-])(tests?|testing|ci|qa|sandbox)\d*([_\-]|$)/i';

    /**
     * Značky ostrého provozu. Blokují VŽDY — i když jméno projde vzorem a i když si
     * uživatel nastavil vlastní `MYINVOICE_TEST_DB_PATTERN`. Bez toho by `ci_prod`,
     * `myinvoice_ci_prod` nebo `fakturace_test_ostra` prolezly jako „testovací".
     */
    public const PRODUCTION_PATTERN = '/(^|[_\-])(prod(uction)?|ostr[aá]|live|ostry)([_\-]|$)/i';

    /**
     * Jména, která si nezávisle vybere víc lidí → z definice sdílená mezi souběžnými
     * běhy. Mimo CI je nechceme; upstream `.github/workflows/ci.yml` zakládá
     * `myinvoice_ci` jako službu (privátní pro daný job), takže v CI povolená být MUSÍ.
     *
     * @var list<string>
     */
    public const SHARED_DB_NAMES = [
        'ci', 'test', 'tests',
        'myinvoice_ci', 'myinvoice_test', 'myinvoice_tests',
    ];

    /**
     * Proměnné, podle kterých poznáme CI. ZÁMĚRNĚ ne holé `CI`: tu exportuje řada
     * nástrojů a agentních harnessů, takže by jediná zděděná proměnná vypnula přesně
     * tu ochranu, kvůli které tenhle guard vznikl. Jenkins/TeamCity → ALLOW_SHARED.
     *
     * @var list<string>
     */
    public const CI_MARKERS = ['GITHUB_ACTIONS', 'GITLAB_CI', 'BUILDKITE', 'CIRCLECI'];

    public const OK               = 'ok';
    public const NOT_A_TEST_DB    = 'not_a_test_db';
    public const PRODUCTION_MARKER = 'production_marker';
    public const SHARED_DB        = 'shared_db';
    public const EMPTY_NAME       = 'empty_name';
    public const INVALID_PATTERN  = 'invalid_pattern';

    /**
     * Čisté rozhodnutí bez vedlejších efektů.
     *
     * Pořadí kontrol je významné: značka produkce se vyhodnocuje PŘED vzorem, aby ji
     * vlastní `MYINVOICE_TEST_DB_PATTERN` nemohl přebít.
     *
     * @return self::OK|self::EMPTY_NAME|self::PRODUCTION_MARKER|self::INVALID_PATTERN|self::NOT_A_TEST_DB|self::SHARED_DB
     */
    public static function decide(string $dbName, string $pattern, bool $inCi, bool $allowShared): string
    {
        if (trim($dbName) === '') {
            return self::EMPTY_NAME;
        }
        if (preg_match(self::PRODUCTION_PATTERN, $dbName) === 1) {
            return self::PRODUCTION_MARKER;
        }
        if (@preg_match($pattern, '') === false) {
            return self::INVALID_PATTERN;
        }
        if (preg_match($pattern, $dbName) !== 1) {
            return self::NOT_A_TEST_DB;
        }
        if (!$inCi && !$allowShared && in_array(strtolower($dbName), self::SHARED_DB_NAMES, true)) {
            return self::SHARED_DB;
        }
        return self::OK;
    }

    /**
     * Běžíme pod známým CI runnerem?
     *
     * @param array<string,string|false> $env
     */
    public static function isCiEnvironment(array $env): bool
    {
        foreach (self::CI_MARKERS as $name) {
            if (self::isTruthy($env[$name] ?? false)) {
                return true;
            }
        }
        return false;
    }

    /** `1`, `true`, `yes`, `on` (case-insensitive) = ano; prázdno, `0`, `false`, `no` = ne. */
    public static function isTruthy(string|false|null $value): bool
    {
        if ($value === false || $value === null) {
            return false;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Vybere vzor: vlastní z ENV, jinak výchozí. Prázdný řetězec i `0` se berou jako
     * „nenastaveno" explicitně — `?:` by `0` tiše spolklo a uživatel by nepoznal proč.
     */
    public static function resolvePattern(string|false|null $envValue): string
    {
        if ($envValue === false || $envValue === null || trim($envValue) === '') {
            return self::DEFAULT_PATTERN;
        }
        return $envValue;
    }

    /**
     * Hlavní vstupní bod pro `tests/bootstrap.php`.
     *
     * Chybějící `cfg.php` NENÍ chyba — testy si ho hlídají samy a skipnou se.
     * Nečteme DB, jen konfiguraci (`Config::load()` spojení neotevírá).
     */
    public static function assertOrExit(string $rootDir): void
    {
        $cfgPath = $rootDir . DIRECTORY_SEPARATOR . 'cfg.php';
        if (!is_file($cfgPath)) {
            return;
        }

        try {
            $config = \MyInvoice\Infrastructure\Config\Config::load($rootDir);
            $dbName = (string) $config->get('db.name', '');
            $dbHost = (string) $config->get('db.host', '');
            $dbPort = (string) $config->get('db.port', '');
        } catch (\Throwable) {
            // Rozbitou konfiguraci nechceme hlásit my — testy mají vlastní hlášky.
            return;
        }

        $env         = self::readEnv(array_merge([self::ENV_PATTERN, self::ENV_ALLOW_SHARED], self::CI_MARKERS));
        $pattern     = self::resolvePattern($env[self::ENV_PATTERN] ?? false);
        $allowShared = self::isTruthy($env[self::ENV_ALLOW_SHARED] ?? false);

        $verdict = self::decide($dbName, $pattern, self::isCiEnvironment($env), $allowShared);
        if ($verdict === self::OK) {
            return;
        }

        $target = ($dbHost !== '' ? $dbHost : '?') . ($dbPort !== '' ? ':' . $dbPort : '') . '/' . ($dbName ?: '?');
        fwrite(STDERR, self::message($verdict, $dbName, $pattern, $cfgPath, $target));
        exit(self::EXIT_CODE);
    }

    /**
     * @param list<string> $names
     * @return array<string,string|false>
     */
    private static function readEnv(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[$name] = getenv($name);
        }
        return $out;
    }

    public static function message(
        string $verdict,
        string $dbName,
        string $pattern,
        string $cfgPath,
        string $target = '',
    ): string {
        $head = match ($verdict) {
            self::PRODUCTION_MARKER => "TESTY ZASTAVENY — \"{$dbName}\" vypadá jako OSTRÁ databáze.",
            self::SHARED_DB         => "TESTY ZASTAVENY — \"{$dbName}\" je SDÍLENÉ jméno testovací databáze.",
            self::EMPTY_NAME        => 'TESTY ZASTAVENY — konfigurace nemá vyplněné `db.name`.',
            self::INVALID_PATTERN   => 'TESTY ZASTAVENY — ' . self::ENV_PATTERN . ' není platný regulární výraz.',
            default                 => "TESTY ZASTAVENY — databáze \"{$dbName}\" nevypadá jako testovací.",
        };

        $why = match ($verdict) {
            self::PRODUCTION_MARKER => <<<TXT
            Jméno obsahuje značku ostrého provozu (prod / production / ostra / live).
            Tahle kontrola se NEDÁ přebít vlastním vzorem — je poslední pojistkou.
            TXT,
            self::SHARED_DB => <<<TXT
            Tohle jméno si zvolí každý, takže o databázi soupeří souběžné běhy — jeden
            test smaže data, o která se opírá druhý, a suita padá na cizí data.
            Mimo CI proto vyžadujeme vlastní klon s vlastním sufixem.
            TXT,
            self::EMPTY_NAME => <<<TXT
            Prázdné jméno databáze vyrobí spojení bez vybraného schématu. To je vždycky
            chyba konfigurace — guard proto raději zastaví, než aby hádal.
            TXT,
            self::INVALID_PATTERN => <<<TXT
            Hodnota: {$pattern}
            Vzor musí být kompletní PCRE včetně oddělovačů, např. '/^myinvoice_test/i'.
            TXT,
            default => <<<TXT
            Integrační testy zakládají a MAŽOU reálné řádky. Spuštění proti ostré
            databázi znamená ztrátu účetních dat.
            TXT,
        };

        $where = $target !== '' ? "Cíl spojení: {$target}\n        " : '';

        return <<<TXT

        ================================================================================
         {$head}
        ================================================================================

        {$why}

        {$where}Konfigurace: {$cfgPath}
        Povolený vzor: {$pattern}

        Jak dál:
          1) Založ si vlastní testovací databázi (jméno ať nese sufix, ne holé "test"):
               CREATE DATABASE myinvoice_test_<ucel>
                 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
          2) Připrav ji jedním chráněným příkazem:
               MYINVOICE_DB_NAME=myinvoice_test_<ucel> php api/bin/test-db-prepare.php
          3) Pusť proti ní testy (bez zásahu do cfg.php):
               MYINVOICE_DB_NAME=myinvoice_test_<ucel> vendor/bin/phpunit

        Únikové cesty (na vlastní riziko, produkci neotevřou):
          MYINVOICE_TEST_DB_PATTERN='/^vlastni_vzor$/i'
          MYINVOICE_TEST_DB_ALLOW_SHARED=1

        POZOR: guard hlídá jen JMÉNO schématu, ne host. Skripty v api/bin/ (reset.php,
        migrate.php, sample.php…) chráněné NEJSOU — spouštěj je přes test-db-prepare.php.

        Podrobný návod: docs/batch-import/TESTING.md

        TXT;
    }
}
