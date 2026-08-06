<?php

declare(strict_types=1);

/**
 * FORK (beevee85) — retrospektivní stínová validace přijatých faktur (V76 nad historií).
 *
 *   MYINVOICE_DB_NAME=myinvoice_clone_<ucel> php api/bin/shadow-validate-existing.php
 *   … --supplier=1        jen jeden tenant
 *   … --json              strojově čitelný výstup místo tabulky
 *
 * PROČ: stínový režim ve `PurchaseInvoiceWriteService` měří jen NOVĚ zakládané doklady,
 * takže na rozhodnutí „vynutit validaci i na importní cesty?" by se čekalo týdny a
 * rozhodovalo by se na hrstce dokladů. Historie je přitom v databázi celá.
 *
 * VÝHRADNĚ ČTENÍ. Skript nezapisuje do databáze ani do provozní telemetrie — historická
 * dávka by jinak zašuměla měření nových importů. Nevolá ARES, registr plátců ani ČNB;
 * pravidla, která je potřebují, hlásí jako přeskočená.
 *
 * Odmítne start, pokud jméno databáze nevypadá na testovací klon (rozšíření V83).
 * Pouštět ho proti ostré databázi není potřeba — klon má tatáž data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

$guardFile = __DIR__ . '/../tests/Support/TestDatabaseGuard.php';
if (!is_file($guardFile)) {
    fwrite(STDERR, "Chybí api/tests/Support/TestDatabaseGuard.php — skript patří jen do vývojového stromu.\n");
    exit(1);
}
require_once $guardFile;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Validation\HistoricalValidationScanner;
use MyInvoice\Tests\Support\TestDatabaseGuard;

$rootDir = Bootstrap::rootDir();
TestDatabaseGuard::assertOrExit($rootDir);

$argvList   = $argv ?? [];
$asJson     = in_array('--json', $argvList, true);
$supplierId = null;
foreach ($argvList as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $supplierId = (int) substr($arg, 11);
    }
}

$dbName  = (string) Config::load($rootDir)->get('db.name', '');
$scanner = new HistoricalValidationScanner(
    Bootstrap::buildApp()->getContainer()->get(Connection::class)
);

$started = microtime(true);
$report  = $scanner->scan($supplierId);
$report['database']    = $dbName;
$report['supplier_id'] = $supplierId;
$report['duration_s']  = round(microtime(true) - $started, 2);

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$pct = static fn (int $part, int $whole): string => $whole > 0
    ? sprintf('%5.1f %%', $part / $whole * 100)
    : '    — ';

echo "\n";
echo "Retrospektivní stínová validace — databáze \"{$dbName}\"";
echo $supplierId !== null ? " (tenant #{$supplierId})" : '';
echo "\n" . str_repeat('=', 78) . "\n\n";

printf("Dokladů prověřeno : %d\n", $report['scanned']);
printf("Prošlo            : %d  (%s)\n", $report['passed'], $pct($report['passed'], $report['scanned']));
printf("Nálezy            : %d  (%s)\n", $report["failed"], $pct($report["failed"], $report["scanned"]));
printf("Popisných řádků   : %d  (legitimní dle V43b, do matematiky nevstupují)\n\n", $report["text_lines"] ?? 0);

printf("  z toho legacy_gap    (údaj se tehdy nesbíral) : %d\n", $report['by_category'][HistoricalValidationScanner::LEGACY_GAP]);
printf("  z toho real_mismatch (hodnoty si protiřečí)   : %d   <<< tohle rozhoduje\n\n",
    $report['by_category'][HistoricalValidationScanner::REAL_MISMATCH]);

if ($report['by_rule'] !== []) {
    echo "Podle pravidla\n" . str_repeat('-', 78) . "\n";
    printf("  %-38s %8s  %s\n", 'pravidlo', 'nálezů', 'kategorie');
    foreach ($report['by_rule'] as $rule => $count) {
        printf("  %-38s %8d  %s\n", $rule, $count, HistoricalValidationScanner::categoryOf($rule));
    }
    echo "\n";
}

$section = static function (string $title, array $rows) use ($pct): void {
    echo "{$title}\n" . str_repeat('-', 78) . "\n";
    printf("  %-28s %8s %8s  %s\n", '', 'celkem', 'nálezů', 'podíl');
    foreach ($rows as $key => $row) {
        $total  = (int) ($row['total'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);
        printf("  %-28s %8d %8d  %s\n", (string) $key, $total, $failed, $pct($failed, $total));
    }
    echo "\n";
};

$section('Podle zdroje zápisu (heuristika, viz SHADOW-VALIDATION.md)', $report['by_source']);
$section('Podle roku vystavení', $report['by_year']);
$section('Podle typu dokladu', $report['by_document_kind']);

echo "Nevyhodnocená pravidla katalogu\n" . str_repeat('-', 78) . "\n";
foreach ($report['skipped_rule_groups'] as $group) {
    printf("  %-14s %-22s %s\n", $group['rules'], $group['reason'], $group['note']);
}

printf("\nHotovo za %.2f s. Zápis do databáze: žádný.\n", $report['duration_s']);
