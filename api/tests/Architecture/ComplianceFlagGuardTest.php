<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * FORK 0925 — strážní testy compliance vrstvy (akceptační kritéria Dokumentů 6 a 7):
 *  - ComplianceFlag NEMÁ mazací operaci na žádné úrovni (UI, API, migrace) — F6/D7 §15.3,
 *  - v UI neexistuje nic, co by navrhovalo rozdělení platby pod limit — Dokument 6 F3
 *    (automatizovaný test hledá zakázané výrazy a při nálezu selže).
 *
 * POZOR (BypassFinals past): každý sken musí asertovat NEPRÁZDNOU množinu
 * souborů — jinak test prochází naprázdno (viz PurchaseInvoiceWritePathTest).
 */
final class ComplianceFlagGuardTest extends TestCase
{
    public function testNoDeleteOnComplianceFlagsAnywhere(): void
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge(
            glob($root . '/api/src/**/*.php') ?: [],
            glob($root . '/api/src/**/**/*.php') ?: [],
            glob($root . '/db/migrations/*.sql') ?: [],
        );
        self::assertNotEmpty($files, 'Sken nesmí běžet naprázdno.');

        $offenders = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if ($src === '') continue;
            if (preg_match('/DELETE\s+FROM\s+compliance_flags/i', $src)
                || preg_match('/DROP\s+TABLE\s+(IF\s+EXISTS\s+)?compliance_flags/i', $src)) {
                $offenders[] = $file;
            }
        }
        self::assertSame([], $offenders,
            'ComplianceFlag se NIKDY nemaže (Dokument 7 §2) — nalezeno v: ' . implode(', ', $offenders));
    }

    public function testRepositoryHasNoDeleteMethod(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/api/src/Repository/ComplianceFlagRepository.php');
        self::assertNotSame('', $src, 'Repository musí existovat.');
        self::assertDoesNotMatchRegularExpression('/function\s+(delete|remove|purge)/i', $src,
            'ComplianceFlagRepository nesmí mít mazací metodu.');
    }

    public function testNoPaymentSplittingHelpersInUi(): void
    {
        $root = dirname(__DIR__, 3) . '/web/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $forbidden = ['rozdělit platbu', 'rozdělit úhradu', 'do limitu zbývá', 'zbývá do 270'];
        $offenders = [];
        $scanned = 0;
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['vue', 'ts', 'json'], true)) {
                continue;
            }
            $scanned++;
            $src = mb_strtolower((string) file_get_contents($file->getPathname()));
            foreach ($forbidden as $phrase) {
                if (str_contains($src, mb_strtolower($phrase))) {
                    $offenders[] = $file->getPathname() . ' („' . $phrase . '")';
                }
            }
        }
        self::assertGreaterThan(50, $scanned, 'Sken nesmí běžet naprázdno.');
        self::assertSame([], $offenders,
            'ZÁKAZ Dokumentu 4 §2: žádná funkce nesmí navrhovat rozdělení platby pod limit — ' . implode(', ', $offenders));
    }
}
