<?php

declare(strict_types=1);

/**
 * FORK (beevee85) — jedním chráněným příkazem připraví testovací databázi.
 *
 *   MYINVOICE_DB_NAME=myinvoice_test_<ucel> php api/bin/test-db-prepare.php
 *
 * PROČ EXISTUJE: `migrate.php`, `ci-seed.php` ani ostatní skripty v `api/bin/`
 * nemají žádnou pojistku proti spuštění nad ostrou databází. Návod, který uživatele
 * posílá spustit je ručně s ENV prefixem, je tím nejrizikovějším krokem celého
 * postupu — stačí zapomenout prefix u jednoho ze tří příkazů. Tenhle wrapper proto
 * nejdřív ověří cíl `TestDatabaseGuard`em a teprve pak pustí kroky za sebou,
 * se zděděným prostředím.
 *
 * Kroky:
 *   1. migrate.php --no-backfills   (schéma; bez auto-backfillů, ty zapisují a volají ČNB)
 *   2. ci-seed.php                  (upstream fixture: 2 tenanti, měny, admin)
 *   3. test-seed-clients.php        (fork fixture: klienti + IČO tenantů)
 *
 * Idempotentní — opakované spuštění nic nerozbije.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

$guardFile = __DIR__ . '/../tests/Support/TestDatabaseGuard.php';
if (!is_file($guardFile)) {
    fwrite(STDERR, "Chybí api/tests/Support/TestDatabaseGuard.php — tenhle skript patří jen do vývojového stromu.\n");
    exit(1);
}
require_once $guardFile;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\PhpCliLocator;
use MyInvoice\Tests\Support\TestDatabaseGuard;

$rootDir = Bootstrap::rootDir();
TestDatabaseGuard::assertOrExit($rootDir);

$php = PhpCliLocator::resolve();
if ($php === null) {
    fwrite(STDERR, "Nenašel jsem PHP CLI (PHP_BINARY=" . PHP_BINARY . ").\n");
    exit(1);
}

$dbName = (string) Config::load($rootDir)->get('db.name', '');
echo "Připravuji testovací databázi \"{$dbName}\".\n\n";

/** @var list<array{label:string, script:string, args:list<string>, tolerate:list<int>}> $steps */
$steps = [
    [
        'label'    => 'Schéma (migrace)',
        'script'   => __DIR__ . '/migrate.php',
        'args'     => ['--no-backfills'],
        'tolerate' => [],
    ],
    [
        'label'  => 'Fixture tenantů (upstream ci-seed)',
        'script' => __DIR__ . '/ci-seed.php',
        'args'   => [],
        // exit 1 = „DB už obsahuje dodavatele nebo uživatele" → při opakovaném běhu OK.
        'tolerate' => [1],
    ],
    [
        'label'    => 'Fixture klientů a IČO tenantů (fork)',
        'script'   => __DIR__ . '/test-seed-clients.php',
        'args'     => [],
        'tolerate' => [],
    ],
];

foreach ($steps as $i => $step) {
    printf("[%d/%d] %s\n", $i + 1, count($steps), $step['label']);

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($step['script']);
    foreach ($step['args'] as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    $exitCode = 0;
    passthru($cmd, $exitCode);

    if ($exitCode !== 0 && !in_array($exitCode, $step['tolerate'], true)) {
        fwrite(STDERR, "\nKrok „{$step['label']}\" selhal (exit {$exitCode}). Končím.\n");
        exit($exitCode);
    }
    echo "\n";
}

echo "✓ Databáze \"{$dbName}\" je připravená. Testy pusť příkazem:\n";
echo "    MYINVOICE_DB_NAME={$dbName} vendor/bin/phpunit\n";
