<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — hlídá, že pojistka V83 zůstane zapojená.
 *
 * `TestDatabaseGuard` visí na jediném řádku v `api/tests/bootstrap.php`. Ten soubor je
 * upstreamový, takže merge nové verze ho může přepsat a ochrana by tiše zmizela —
 * bez jediného červeného testu, protože všechno ostatní by dál fungovalo.
 * Tenhle test je proto detektor tiché ztráty hooku; při konfliktu po merge padne on.
 *
 * Hlídá i POŘADÍ: `DG\BypassFinals::enable()` musí zůstat PŘED guardem. Guard sahá na
 * `Config`, a co se načte dřív než BypassFinals, si ponechá `final` a přestane jít
 * mockovat — přesun guardu nahoru shodí ~123 unit testů na `ClassIsFinalException`.
 */
final class TestDatabaseGuardWiringTest extends TestCase
{
    private static function apiDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testGuardClassExists(): void
    {
        self::assertFileExists(
            self::apiDir() . '/tests/Support/TestDatabaseGuard.php',
            'Chybí třída guardu — pravidlo V83 je bez implementace.',
        );
    }

    public function testPhpunitConfigStillUsesOurBootstrap(): void
    {
        $xml = (string) file_get_contents(self::apiDir() . '/phpunit.xml');

        self::assertStringContainsString(
            'bootstrap="tests/bootstrap.php"',
            $xml,
            'phpunit.xml přestal ukazovat na tests/bootstrap.php — guard by se nenačetl.',
        );
    }

    public function testBootstrapCallsTheGuard(): void
    {
        $bootstrap = (string) file_get_contents(self::apiDir() . '/tests/bootstrap.php');

        self::assertStringContainsString(
            'TestDatabaseGuard::assertOrExit',
            $bootstrap,
            'tests/bootstrap.php už guard nevolá — pravděpodobně ho přepsal merge upstreamu. '
            . 'Obnov volání podle docs/batch-import/TESTING.md.',
        );
    }

    public function testBypassFinalsRunsBeforeTheGuard(): void
    {
        $bootstrap = (string) file_get_contents(self::apiDir() . '/tests/bootstrap.php');

        $bypassAt = strpos($bootstrap, 'BypassFinals::enable');
        $guardAt  = strpos($bootstrap, 'TestDatabaseGuard::assertOrExit');

        self::assertIsInt($bypassAt, 'V bootstrapu chybí BypassFinals::enable().');
        self::assertIsInt($guardAt, 'V bootstrapu chybí volání guardu.');
        self::assertLessThan(
            $guardAt,
            $bypassAt,
            'BypassFinals::enable() musí být PŘED guardem. Guard načítá Config; při obráceném '
            . 'pořadí si Config ponechá `final` a ~123 unit testů spadne na ClassIsFinalException.',
        );
    }

    public function testGuardIsLoadedAfterComposerAutoload(): void
    {
        $bootstrap = (string) file_get_contents(self::apiDir() . '/tests/bootstrap.php');

        $autoloadAt = strpos($bootstrap, 'vendor/autoload.php');
        $guardAt    = strpos($bootstrap, 'TestDatabaseGuard::assertOrExit');

        self::assertIsInt($autoloadAt, 'V bootstrapu chybí require vendor/autoload.php.');
        self::assertIsInt($guardAt, 'V bootstrapu chybí volání guardu.');
        self::assertLessThan($guardAt, $autoloadAt, 'Guard se volá dřív, než je načtený autoloader.');
    }
}
