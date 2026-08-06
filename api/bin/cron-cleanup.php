<?php

declare(strict_types=1);

/**
 * Denní cleanup — login_attempts (>24h), expirované a staré odvolané sessions,
 * použité password_resets, login_otps + trusted_devices, WebAuthn flow/proofy
 * a log files >90 dní.
 *
 * POZN: PDF se NEMAŽE. Aktivní cache může pominout (renderer ji znovu vytvoří),
 * ale archivovaná historie (storage/invoices/sup-N/_archive) obsahuje verze
 * skutečně odeslané klientovi a je důkazem fakturace.
 *
 * Použití (Windows Task Scheduler):
 *   php api/bin/cron-cleanup.php
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$pdo     = (new Connection($config))->pdo();

$run = CronRun::start($pdo, 'cron-cleanup');
$startedAt = microtime(true);
$report = [];

// 1) login_attempts — drop záznamy starší 24 hodin
$n = $pdo->exec("DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 24 HOUR");
$report['login_attempts'] = (int) $n;

// 2) sessions — TIMESTAMP expires_at porovnáváme s CURRENT_TIMESTAMP, aby
// MariaDB správně zohlednila session timezone. UTC DATETIME revoked_at naopak
// porovnáváme s UTC_TIMESTAMP. Nahrazené generace bez revoked_at držíme jako
// logout tombstones až do jejich absolutní expirace.
$n = $pdo->exec('DELETE FROM sessions WHERE expires_at < CURRENT_TIMESTAMP(6)');
$report['expired_sessions'] = (int) $n;
$n = $pdo->exec(
    'DELETE FROM sessions
      WHERE revoked_at < UTC_TIMESTAMP(6) - INTERVAL 7 DAY'
);
$report['revoked_sessions'] = (int) $n;

// 2b) WebAuthn ceremonies a MFA proofy — po expiraci/spotřebování drž nejvýše 1 den.
$n = $pdo->exec(
    'DELETE FROM webauthn_ceremonies
      WHERE expires_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY
         OR used_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY'
);
$report['webauthn_ceremonies'] = (int) $n;
$n = $pdo->exec(
    'DELETE FROM mfa_step_up_proofs
      WHERE expires_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY
         OR used_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY'
);
$report['mfa_step_up_proofs'] = (int) $n;

// 3) password_resets — použité nebo expirované >7 dní
$n = $pdo->exec("DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < NOW() - INTERVAL 7 DAY");
$report['password_resets'] = (int) $n;

// 3b) login_otps (e-mailové 2FA kódy) — použité/expirované >1 den
$n = $pdo->exec("DELETE FROM login_otps WHERE used_at < NOW() - INTERVAL 1 DAY OR expires_at < NOW() - INTERVAL 1 DAY");
$report['login_otps'] = (int) $n;

// 3c) trusted_devices — expirovaná „zapamatovaná zařízení"
$n = $pdo->exec("DELETE FROM trusted_devices WHERE expires_at < NOW()");
$report['trusted_devices'] = (int) $n;

// 4) ARES/VIES cache — starší 30 dní
$n = $pdo->exec("DELETE FROM ares_cache WHERE fetched_at < NOW() - INTERVAL 30 DAY");
$report['ares_cache'] = (int) $n;
$n = $pdo->exec("DELETE FROM vies_cache WHERE fetched_at < NOW() - INTERVAL 30 DAY");
$report['vies_cache'] = (int) $n;

// 5) Log files — Monolog rotuje, ale když je config zapnutý max_files, držíme se ho
$logDir = (string) $config->get('logging.path', $rootDir . '/log/app.log');
$logDir = dirname($logDir);
$maxFiles = (int) $config->get('logging.max_files', 90);
$logDeleted = 0;
if (is_dir($logDir)) {
    $files = glob($logDir . '/*.log') ?: [];
    if (count($files) > $maxFiles) {
        usort($files, fn ($a, $b) => filemtime($a) - filemtime($b));
        $toDel = array_slice($files, 0, count($files) - $maxFiles);
        foreach ($toDel as $f) if (@unlink($f)) $logDeleted++;
    }
}
$report['log_files'] = $logDeleted;

// 6) Měsíční exporty — smaž dokončené/neúspěšné/zrušené joby starší 7 dní
//    vč. jejich ZIP souboru (retence: do ručního smazání, jinak reaper po 7 dnech).
$exportBase = ($config->dataDir() ?? $rootDir) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'monthly-exports';
$stmt = $pdo->query(
    "SELECT id, result_path FROM import_jobs
      WHERE source = 'monthly_export'
        AND status IN ('completed', 'failed', 'cancelled')
        AND COALESCE(finished_at, created_at) < NOW() - INTERVAL 7 DAY"
);
$exportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$exportFilesDeleted = 0;
$exportIds = [];
foreach ($exportRows as $r) {
    $exportIds[] = (int) $r['id'];
    $rel = (string) ($r['result_path'] ?? '');
    if ($rel === '') continue;
    $abs = realpath($exportBase . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));
    $baseReal = realpath($exportBase);
    // Path-traversal guard — maž jen v rámci storage/monthly-exports.
    if ($abs !== false && $baseReal !== false && is_file($abs)
        && str_starts_with(strtolower($abs), strtolower($baseReal) . DIRECTORY_SEPARATOR)) {
        if (@unlink($abs)) $exportFilesDeleted++;
    }
}
if ($exportIds !== []) {
    $in = implode(',', array_fill(0, count($exportIds), '?'));
    $pdo->prepare("DELETE FROM import_jobs WHERE id IN ($in)")->execute($exportIds);
}
$report['monthly_export_jobs']  = count($exportIds);
$report['monthly_export_files'] = $exportFilesDeleted;

// FORK 0905: automatické vysypání koše dokladů po retenci.
// Per supplier: doc_trash_enabled=1 a doc_trash_retention_days>0 → doklady
// (vydané i přijaté) s deleted_at starším než retence se TVRDĚ smažou přes
// DocumentTrashService (snapshot + soubory + čítač + audit *.trash_autopurged).
// Doklady s aktivní blokací (DPH podání za jejich období, vazby) se přeskočí
// a v koši zůstanou — bezpečnost má přednost před úklidem.
$autopurged = 0;
$autopurgeSkipped = 0;
$candidates = $pdo->query(
    "SELECT 'invoice' AS entity, i.id, i.supplier_id
       FROM invoices i
       JOIN supplier s ON s.id = i.supplier_id
      WHERE s.doc_trash_enabled = 1 AND s.doc_trash_retention_days > 0
        AND i.deleted_at IS NOT NULL
        AND i.deleted_at < NOW() - INTERVAL s.doc_trash_retention_days DAY
      UNION ALL
     SELECT 'purchase_invoice', pi.id, pi.supplier_id
       FROM purchase_invoices pi
       JOIN supplier s ON s.id = pi.supplier_id
      WHERE s.doc_trash_enabled = 1 AND s.doc_trash_retention_days > 0
        AND pi.deleted_at IS NOT NULL
        AND pi.deleted_at < NOW() - INTERVAL s.doc_trash_retention_days DAY"
)->fetchAll(PDO::FETCH_ASSOC);
if ($candidates !== []) {
    // Kontejner stavíme lazy až tady — běžný noční běh bez kandidátů zůstává levný.
    $container = Bootstrap::buildApp()->getContainer();
    if ($container !== null) {
        $trash  = $container->get(\MyInvoice\Service\Invoice\DocumentTrashService::class);
        $policy = $container->get(\MyInvoice\Service\Invoice\DocumentTrashPolicy::class);
        $invRepo = $container->get(\MyInvoice\Repository\InvoiceRepository::class);
        $piRepo  = $container->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);
        foreach ($candidates as $cand) {
            if ($cand['entity'] === 'invoice') {
                $row = $invRepo->find((int) $cand['id']);
                $blockers = $row !== null ? $policy->blockersForInvoice($row) : [['skip']];
                if ($row === null || $blockers !== []) { $autopurgeSkipped++; continue; }
                $trash->forceDeleteInvoice($row, null, 'Automatické vysypání koše po retenci (cron-cleanup)', null, 'cron-cleanup', 'invoice.trash_autopurged');
            } else {
                $row = $piRepo->find((int) $cand['id'], (int) $cand['supplier_id']);
                $blockers = $row !== null ? $policy->blockersForPurchaseInvoice($row) : [['skip']];
                if ($row === null || $blockers !== []) { $autopurgeSkipped++; continue; }
                $trash->forceDeletePurchaseInvoice($row, null, 'Automatické vysypání koše po retenci (cron-cleanup)', null, 'cron-cleanup', 'purchase_invoice.trash_autopurged');
            }
            $autopurged++;
        }
    }
}
$report['doc_trash_autopurged'] = $autopurged;
$report['doc_trash_autopurge_skipped'] = $autopurgeSkipped;

// FORK batch-import: retence dávek (V79b, cesta B). Sweeper opuštěných dávek
// (jen stavy čekající na externí nástroj) + purge normalized_json po retenci.
// cfg.sample.php tyhle klíče deklaroval od začátku — ČETL je až tenhle blok;
// do review 6. 8. 2026 sweeper žádného volajícího neměl.
$biSwept = 0; $biRawPurged = 0; $biFilesPurged = 0; $biNormPurged = 0;
if ((bool) $config->get('purchase_invoice.batch_import.enabled', false)) {
    $biContainer = Bootstrap::buildApp()->getContainer();
    if ($biContainer !== null) {
        $biIntake = $biContainer->get(\MyInvoice\Service\PurchaseBatchImport\ResultsIntake::class);
        $biRepo   = $biContainer->get(\MyInvoice\Repository\PurchaseImportBatchRepository::class);
        $biHours  = (int) $config->get('purchase_invoice.batch_import.abandoned_batch_hours', 24);
        $biDays   = (int) $config->get('purchase_invoice.batch_import.normalized_json_retention_days', 90);
        foreach ($pdo->query('SELECT id FROM supplier')->fetchAll(PDO::FETCH_COLUMN) as $biSid) {
            $r = $biIntake->sweepAbandoned((int) $biSid, $biHours);
            $biSwept      += $r['swept'];
            $biRawPurged  += $r['purged'];
            $biFilesPurged += $r['files'];
            $biNormPurged += $biRepo->purgeNormalizedJsonOlderThan((int) $biSid, $biDays);
        }
    }
}
// Do reportu jde ROZSAH A POČET, nikdy obsah (V82).
$report['batch_import_swept'] = $biSwept;
$report['batch_import_raw_purged'] = $biRawPurged;
$report['batch_import_files_purged'] = $biFilesPurged;
$report['batch_import_normalized_purged'] = $biNormPurged;

// Pročisti cron_runs — drž max 500 posledních záznamů na skript.
$report['cron_runs_purged'] = CronRun::purgeOld($pdo, 500);

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "[" . date('Y-m-d H:i:s') . "] cron-cleanup ({$ms} ms): " . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";

// Audit do activity_log
$pdo->prepare(
    "INSERT INTO activity_log (action, payload) VALUES ('cron.cleanup', ?)"
)->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);

$run->finish('ok', $report);
