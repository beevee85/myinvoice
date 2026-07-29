<?php

declare(strict_types=1);

/**
 * Stahovani bankovnich pohybu z Fio API (fork 0921).
 *
 * Bezi jen pro ucty, ktere maji ulozeny token a zapnute stahovani. Dodavatele
 * bere z konfigurace stahovani, ne z globalniho nastaveni skenu — token je vzdy
 * navazany na konkretniho dodavatele a jeho ucet.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankApiCredentialRepository;
use MyInvoice\Service\Bank\Api\FioApiClient;
use MyInvoice\Service\Bank\Api\FioTransactionImporter;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);
$run     = CronRun::start($conn->pdo(), 'cron-fio-bank');

$container   = Bootstrap::buildApp()->getContainer();
$importer    = $container->get(FioTransactionImporter::class);
$credentials = $container->get(BankApiCredentialRepository::class);

$started = microtime(true);
$summary = [
    'suppliers' => 0,
    'accounts'  => 0,
    'created'   => 0,
    'skipped'   => 0,
    'matched'   => 0,
    'errors'    => 0,
    'details'   => [],
];

$supplierIds = $credentials->suppliersWithEnabled();
if ($supplierIds === []) {
    echo '[' . date('Y-m-d H:i:s') . "] fio-bank: žádný účet se zapnutým stahováním.\n";
    $run->finish('ok', $summary, null);
    exit(0);
}

foreach ($supplierIds as $sid) {
    fwrite(STDOUT, '[' . date('H:i:s') . "] supplier {$sid} — Fio API\n");
    try {
        $result = $importer->importForSupplier($sid);
        $summary['suppliers']++;
        foreach (['accounts', 'created', 'skipped', 'matched'] as $key) {
            $summary[$key] += (int) ($result[$key] ?? 0);
        }
        foreach ($result['errors'] as $message) {
            $summary['errors']++;
            $summary['details'][] = ['supplier_id' => $sid, 'error' => $message];
        }
    } catch (\Throwable $e) {
        $summary['errors']++;
        $summary['details'][] = ['supplier_id' => $sid, 'error' => $e->getMessage()];
        fwrite(STDERR, "[fio-bank] supplier {$sid}: " . $e->getMessage() . "\n");
    }

    // Fio pousti nejvys jeden dotaz za 30 s na token; mezi dodavateli (a tedy
    // jinymi tokeny) cekat nemusime, ale drobna pauza chrani pred soubehem.
    if (count($supplierIds) > 1) {
        sleep(1);
    }
}

$summary['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
echo '[' . date('Y-m-d H:i:s') . '] fio-bank: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";

$conn->pdo()->prepare(
    "INSERT INTO activity_log (action, payload) VALUES ('cron.fio_bank', ?)"
)->execute([json_encode($summary, JSON_UNESCAPED_UNICODE)]);

$run->finish($summary['errors'] > 0 ? 'error' : 'ok', $summary, $summary['errors'] > 0 ? 'Některá stahování selhala.' : null);
