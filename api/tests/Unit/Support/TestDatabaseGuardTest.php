<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Support;

use MyInvoice\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — pravidlo V83: testy nesmějí běžet proti ostré ani sdílené databázi.
 *
 * `decide()` je čistá rozhodovací funkce a testuje se tabulkově. `assertOrExit()`
 * (jediná část, která reálně vypíná běh) se ověřuje v samostatném procesu, protože
 * volá `exit()` — viz TestDatabaseGuardProcessTest.
 */
final class TestDatabaseGuardTest extends TestCase
{
    private const P = TestDatabaseGuard::DEFAULT_PATTERN;

    /** @return iterable<string, array{string, string}> */
    public static function databaseNames(): iterable
    {
        // Jméno databáze => verdikt mimo CI, bez povolení sdílené.

        // --- ostrý provoz -----------------------------------------------------
        yield 'produkce'            => ['myinvoice',            TestDatabaseGuard::NOT_A_TEST_DB];
        yield 'produkce velkými'    => ['MyInvoice',            TestDatabaseGuard::NOT_A_TEST_DB];
        yield 'klon bez test/ci'    => ['myinvoice_37a',        TestDatabaseGuard::NOT_A_TEST_DB];
        yield 'záloha'              => ['myinvoice_backup',     TestDatabaseGuard::NOT_A_TEST_DB];

        // Segment, ne podřetězec — jinak by „citace" prošlo kvůli „ci".
        yield 'podřetězec testovaci' => ['myinvoice_testovaci', TestDatabaseGuard::NOT_A_TEST_DB];
        yield 'podřetězec citace'    => ['myinvoice_citace',    TestDatabaseGuard::NOT_A_TEST_DB];
        yield 'podřetězec cirkus'    => ['myinvoice_cirkus',    TestDatabaseGuard::NOT_A_TEST_DB];

        // --- značka ostrého provozu přebije i „testovací" segment --------------
        yield 'ci_prod'             => ['ci_prod',              TestDatabaseGuard::PRODUCTION_MARKER];
        yield 'myinvoice_ci_prod'   => ['myinvoice_ci_prod',    TestDatabaseGuard::PRODUCTION_MARKER];
        yield 'test_ostra'          => ['fakturace_test_ostra', TestDatabaseGuard::PRODUCTION_MARKER];
        yield 'production'          => ['myinvoice_production', TestDatabaseGuard::PRODUCTION_MARKER];
        yield 'live'                => ['myinvoice_test_live',  TestDatabaseGuard::PRODUCTION_MARKER];

        // --- generická (sdílená) jména ----------------------------------------
        yield 'holé ci'             => ['ci',                   TestDatabaseGuard::SHARED_DB];
        yield 'holé test'           => ['test',                 TestDatabaseGuard::SHARED_DB];
        yield 'sdílená CI databáze' => ['myinvoice_ci',         TestDatabaseGuard::SHARED_DB];
        yield 'sdílená velkými'     => ['MYINVOICE_CI',         TestDatabaseGuard::SHARED_DB];
        yield 'generické test'      => ['myinvoice_test',       TestDatabaseGuard::SHARED_DB];
        yield 'generické tests'     => ['myinvoice_tests',      TestDatabaseGuard::SHARED_DB];

        // --- prázdné ----------------------------------------------------------
        yield 'prázdné jméno'       => ['',                     TestDatabaseGuard::EMPTY_NAME];
        yield 'jen mezery'          => ['   ',                  TestDatabaseGuard::EMPTY_NAME];

        // --- vlastní klony (musí projít) --------------------------------------
        yield 'vlastní klon'        => ['myinvoice_test_batchimport', TestDatabaseGuard::OK];
        yield 'odvozená CI'         => ['myinvoice_ci_ddkpz',   TestDatabaseGuard::OK];
        // Číslovaný klon je nejpřirozenější způsob, jak si oddělit souběžnou session.
        yield 'číslovaný ci'        => ['myinvoice_ci2',        TestDatabaseGuard::OK];
        yield 'číslovaný test'      => ['myinvoice_test2',      TestDatabaseGuard::OK];
        yield 'holý test01'         => ['test01',               TestDatabaseGuard::OK];
        yield 'testing'             => ['myinvoice_testing',    TestDatabaseGuard::OK];
        yield 'qa'                  => ['myinvoice_qa',         TestDatabaseGuard::OK];
        yield 'sandbox'             => ['sandbox_myinvoice',    TestDatabaseGuard::OK];
        yield 'test na začátku'     => ['test_myinvoice',       TestDatabaseGuard::OK];
        // Pomlčka je stejně platná hranice segmentu jako podtržítko.
        yield 'pomlčky'             => ['mi-test-batch',        TestDatabaseGuard::OK];
        yield 'pomlčka na konci'    => ['myinvoice-test',       TestDatabaseGuard::OK];
    }

    #[DataProvider('databaseNames')]
    public function testVerdictOutsideCi(string $dbName, string $expected): void
    {
        self::assertSame(
            $expected,
            TestDatabaseGuard::decide($dbName, self::P, inCi: false, allowShared: false),
        );
    }

    public function testSharedDatabaseIsAllowedInsideCi(): void
    {
        // Upstream .github/workflows/ci.yml zakládá službu `myinvoice_ci` — guard ji
        // v CI blokovat NESMÍ, jinak rozbijeme cizí pipeline.
        self::assertSame(
            TestDatabaseGuard::OK,
            TestDatabaseGuard::decide('myinvoice_ci', self::P, inCi: true, allowShared: false),
        );
    }

    public function testSharedDatabaseCanBeAllowedExplicitly(): void
    {
        self::assertSame(
            TestDatabaseGuard::OK,
            TestDatabaseGuard::decide('myinvoice_ci', self::P, inCi: false, allowShared: true),
        );
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function escapeHatches(): iterable
    {
        yield 'produkce + allowShared' => ['myinvoice',      false, true];
        yield 'produkce v CI'          => ['myinvoice',      true,  false];
        yield 'prod marker + oboje'    => ['myinvoice_prod', true,  true];
    }

    /** Žádný únikový ventil nesmí otevřít cestu k ostré databázi. */
    #[DataProvider('escapeHatches')]
    public function testNoEscapeHatchOpensProduction(string $dbName, bool $inCi, bool $allowShared): void
    {
        self::assertNotSame(
            TestDatabaseGuard::OK,
            TestDatabaseGuard::decide($dbName, self::P, $inCi, $allowShared),
        );
    }

    public function testCustomPatternCannotOverrideProductionMarker(): void
    {
        // `/./` propustí cokoli — značka ostrého provozu ho musí přebít.
        self::assertSame(
            TestDatabaseGuard::PRODUCTION_MARKER,
            TestDatabaseGuard::decide('myinvoice_prod', '/./', inCi: false, allowShared: true),
        );
    }

    public function testInvalidPatternIsReportedInsteadOfPassing(): void
    {
        // Rozbitý přebíjecí vzor nesmí guard „propustit" tichým preg_match false,
        // ani ho hlásit jako „databáze nevypadá jako testovací" (matoucí hláška).
        self::assertSame(
            TestDatabaseGuard::INVALID_PATTERN,
            TestDatabaseGuard::decide('myinvoice_ci2', '/(nezavreno', inCi: false, allowShared: false),
        );
    }

    public function testCustomPatternIsHonoured(): void
    {
        self::assertSame(
            TestDatabaseGuard::OK,
            TestDatabaseGuard::decide('firemni_pisek', '/^firemni_/', inCi: false, allowShared: false),
        );
    }

    /** @return iterable<string, array{string|false, string}> */
    public static function patternOverrides(): iterable
    {
        yield 'nenastaveno'  => [false,              TestDatabaseGuard::DEFAULT_PATTERN];
        yield 'prázdné'      => ['',                 TestDatabaseGuard::DEFAULT_PATTERN];
        yield 'mezery'       => ['   ',              TestDatabaseGuard::DEFAULT_PATTERN];
        // `0` je falsy — `?:` by ho tiše spolklo a uživatel by netušil, proč vzor neplatí.
        yield 'falsy nula'   => ['0',                '0'];
        yield 'vlastní vzor' => ['/^sandbox_/',      '/^sandbox_/'];
    }

    #[DataProvider('patternOverrides')]
    public function testResolvePattern(string|false $envValue, string $expected): void
    {
        self::assertSame($expected, TestDatabaseGuard::resolvePattern($envValue));
    }

    /** @return iterable<string, array{array<string,string|false>, bool}> */
    public static function ciEnvironments(): iterable
    {
        yield 'github actions'  => [['GITHUB_ACTIONS' => 'true'], true];
        yield 'gitlab'          => [['GITLAB_CI' => '1'],         true];
        yield 'buildkite'       => [['BUILDKITE' => 'true'],      true];
        yield 'nic nenastaveno' => [[],                           false];
        yield 'prázdné'         => [['GITHUB_ACTIONS' => ''],     false];
        yield 'false'           => [['GITHUB_ACTIONS' => 'false'], false];
        yield 'nula'            => [['GITHUB_ACTIONS' => '0'],    false];
        // Holé CI ZÁMĚRNĚ neplatí: exportuje ho spousta nástrojů a jediná zděděná
        // proměnná by vypnula přesně tu ochranu, kvůli které guard vznikl.
        yield 'holé CI neplatí' => [['CI' => 'true'],             false];
    }

    #[DataProvider('ciEnvironments')]
    public function testCiDetection(array $env, bool $expected): void
    {
        self::assertSame($expected, TestDatabaseGuard::isCiEnvironment($env));
    }

    /** @return iterable<string, array{string|false, bool}> */
    public static function truthyValues(): iterable
    {
        yield '1'     => ['1', true];
        yield 'true'  => ['true', true];
        // Case-insensitive: uživatel v panice napíše TRUE nebo Yes.
        yield 'TRUE'  => ['TRUE', true];
        yield 'Yes'   => ['Yes', true];
        yield 'on'    => ['on', true];
        yield 's mezerami' => ['  yes  ', true];
        yield '0'     => ['0', false];
        yield 'false' => ['false', false];
        yield 'no'    => ['no', false];
        yield 'prázdné' => ['', false];
        yield 'nenastaveno' => [false, false];
    }

    #[DataProvider('truthyValues')]
    public function testIsTruthy(string|false $value, bool $expected): void
    {
        self::assertSame($expected, TestDatabaseGuard::isTruthy($value));
    }

    public function testExitCodeIsDistinguishableFromFailingTests(): void
    {
        // PHPUnit vrací 1/2 pro červené testy; 78 (EX_CONFIG) říká „guard zastavil běh".
        self::assertSame(78, TestDatabaseGuard::EXIT_CODE);
        self::assertNotContains(TestDatabaseGuard::EXIT_CODE, [0, 1, 2]);
    }

    public function testMessageExplainsHowToRecover(): void
    {
        $message = TestDatabaseGuard::message(
            TestDatabaseGuard::NOT_A_TEST_DB,
            'myinvoice',
            self::P,
            '/opt/myinvoice/cfg.php',
            '127.0.0.1:3307/myinvoice',
        );

        self::assertStringContainsString('myinvoice', $message);
        self::assertStringContainsString('MYINVOICE_DB_NAME', $message);
        self::assertStringContainsString('test-db-prepare.php', $message);
        self::assertStringContainsString('docs/batch-import/TESTING.md', $message);
        // Cíl spojení musí být vidět — guard hlídá jméno, ale uživatel musí poznat, kam mířil.
        self::assertStringContainsString('127.0.0.1:3307/myinvoice', $message);
    }

    public function testMessageNeverTeachesUnguardedMigrate(): void
    {
        // Dřívější verze hlášky posílala uživatele na `php api/bin/migrate.php` bez
        // --no-backfills (zápisy + volání ČNB) a bez guardu. Teď smí nabízet jen wrapper.
        foreach ([
            TestDatabaseGuard::NOT_A_TEST_DB,
            TestDatabaseGuard::SHARED_DB,
            TestDatabaseGuard::PRODUCTION_MARKER,
        ] as $verdict) {
            $message = TestDatabaseGuard::message($verdict, 'x', self::P, '/cfg.php');
            self::assertStringNotContainsString('bin/migrate.php', $message);
            self::assertStringNotContainsString('bin/ci-seed.php', $message);
        }
    }

    public function testProductionMessageSaysItCannotBeOverridden(): void
    {
        $message = TestDatabaseGuard::message(
            TestDatabaseGuard::PRODUCTION_MARKER,
            'myinvoice_prod',
            self::P,
            '/cfg.php',
        );

        self::assertStringContainsString('OSTRÁ', $message);
        self::assertStringContainsString('NEDÁ přebít', $message);
    }

    public function testSharedMessageSaysWhy(): void
    {
        $message = TestDatabaseGuard::message(
            TestDatabaseGuard::SHARED_DB,
            'myinvoice_ci',
            self::P,
            '/cfg.php',
        );

        self::assertStringContainsString('SDÍLENÉ', $message);
        self::assertStringContainsString(TestDatabaseGuard::ENV_ALLOW_SHARED, $message);
    }
}
