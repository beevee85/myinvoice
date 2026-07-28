<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Support;

use MyInvoice\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — důkaz, že guard běh SKUTEČNĚ zastaví.
 *
 * `TestDatabaseGuard::assertOrExit()` volá `exit()`, takže se v rámci procesu PHPUnitu
 * testovat nedá. Spouštíme ho proto v samostatném PHP procesu nad dočasným kořenem
 * s vlastním `cfg.php` a kontrolovaným prostředím (rodičovské ENV se NEDĚDÍ, jinak by
 * výsledek ovlivnila proměnná zděděná ze shellu).
 *
 * Bez tohohle testu by v repu nebylo nic, co dokazuje, že hook funguje — pokrytá by
 * byla jen čistá rozhodovací funkce.
 */
final class TestDatabaseGuardProcessTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/myinvoice-guard-' . bin2hex(random_bytes(6));
        if (!mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            self::markTestSkipped('Nelze vytvořit dočasný adresář.');
        }
    }

    protected function tearDown(): void
    {
        foreach (['cfg.php', 'runner.php'] as $file) {
            @unlink($this->tmpDir . '/' . $file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** @return iterable<string, array{?string, array<string,string>, int, string}> */
    public static function scenarios(): iterable
    {
        // [db.name v cfg.php (null = cfg.php vůbec nevytvářet), ENV, očekávaný exit, očekávaný text ve STDERR]
        yield 'ostrá databáze zastaví běh' => [
            'myinvoice', [], TestDatabaseGuard::EXIT_CODE, 'nevypadá jako testovací',
        ];
        yield 'značka produkce zastaví běh' => [
            'myinvoice_test_prod', [], TestDatabaseGuard::EXIT_CODE, 'OSTRÁ',
        ];
        yield 'sdílené jméno mimo CI zastaví běh' => [
            'myinvoice_ci', [], TestDatabaseGuard::EXIT_CODE, 'SDÍLENÉ',
        ];
        yield 'prázdné db.name zastaví běh' => [
            '', [], TestDatabaseGuard::EXIT_CODE, 'db.name',
        ];
        yield 'rozbitý vzor zastaví běh' => [
            'myinvoice_test_ok', ['MYINVOICE_TEST_DB_PATTERN' => '/(nezavreno'], TestDatabaseGuard::EXIT_CODE, 'regulární výraz',
        ];

        yield 'vlastní klon projde' => [
            'myinvoice_test_probe', [], 0, '',
        ];
        yield 'sdílené jméno v CI projde' => [
            'myinvoice_ci', ['GITHUB_ACTIONS' => 'true'], 0, '',
        ];
        yield 'sdílené jméno s povolením projde' => [
            'myinvoice_ci', ['MYINVOICE_TEST_DB_ALLOW_SHARED' => 'TRUE'], 0, '',
        ];
        yield 'chybějící cfg.php propustí' => [
            null, [], 0, '',
        ];
        // Doporučený postup „přesměruj testy jinam přes MYINVOICE_DB_NAME" musí guard vidět.
        yield 'ENV override jména se uplatní' => [
            'myinvoice', ['MYINVOICE_DB_NAME' => 'myinvoice_test_override'], 0, '',
        ];
        // A naopak: override na ostrou databázi guard chytí, i když cfg.php je v pořádku.
        yield 'ENV override na produkci zastaví běh' => [
            'myinvoice_test_probe', ['MYINVOICE_DB_NAME' => 'myinvoice'], TestDatabaseGuard::EXIT_CODE, 'nevypadá jako testovací',
        ];
    }

    /**
     * @param array<string,string> $env
     */
    #[DataProvider('scenarios')]
    public function testGuardBehaviourInSeparateProcess(?string $dbName, array $env, int $expectedExit, string $expectedStderr): void
    {
        $apiDir  = dirname(__DIR__, 3);
        $autoload = $apiDir . '/vendor/autoload.php';
        $guard    = $apiDir . '/tests/Support/TestDatabaseGuard.php';
        if (!is_file($autoload) || !is_file($guard)) {
            self::markTestSkipped('Chybí autoload nebo guard — test vyžaduje vývojový strom.');
        }

        if ($dbName !== null) {
            file_put_contents($this->tmpDir . '/cfg.php', sprintf(
                "<?php return ['db' => ['host' => '127.0.0.1', 'port' => 3307, 'name' => %s, 'user' => 'u', 'pass' => 'p']];",
                var_export($dbName, true),
            ));
        }

        file_put_contents($this->tmpDir . '/runner.php', sprintf(
            "<?php require %s; require %s; \\MyInvoice\\Tests\\Support\\TestDatabaseGuard::assertOrExit(%s); echo 'PROSLO';",
            var_export($autoload, true),
            var_export($guard, true),
            var_export($this->tmpDir, true),
        ));

        [$exitCode, $stdout, $stderr] = $this->runGuardProcess($this->tmpDir . '/runner.php', $env);

        self::assertSame($expectedExit, $exitCode, "STDERR:\n{$stderr}");
        if ($expectedStderr !== '') {
            self::assertStringContainsString($expectedStderr, $stderr);
            self::assertStringNotContainsString('PROSLO', $stdout, 'Guard měl běh zastavit před pokračováním.');
        } else {
            self::assertStringContainsString('PROSLO', $stdout);
            self::assertStringNotContainsString('TESTY ZASTAVENY', $stderr);
        }
    }

    /**
     * @param array<string,string> $env
     * @return array{int, string, string}
     */
    private function runGuardProcess(string $script, array $env): array
    {
        // Prostředí NEdědíme — jinak by zděděné GITHUB_ACTIONS/MYINVOICE_* zkreslilo verdikt.
        $baseEnv = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'];

        $process = proc_open(
            [PHP_BINARY, $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $baseEnv + $env,
        );
        if (!is_resource($process)) {
            self::markTestSkipped('proc_open není k dispozici.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
