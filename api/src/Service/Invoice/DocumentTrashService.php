<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PdfArchiveService;
use MyInvoice\Service\Stats\StatsRecomputer;

/**
 * Koš + trvalé smazání dokladů (vydané i přijaté faktury).
 *
 * Tři operace (viz DocumentTrashPolicy):
 *   trash*()       soft delete — deleted_at/deleted_by/delete_reason, nic jiného
 *                  se neděje (PDF i vazby zůstávají, doklad jen zmizí z přehledů).
 *   restore*()     vrátí doklad z koše (stejné číslo — řádek nikdy nezmizel).
 *   forceDelete*() nevratné smazání: JSON snapshot do deleted_document_snapshots,
 *                  úklid souborů, uvolnění čítače (jen je-li doklad poslední v řadě),
 *                  DELETE (cascade items…), přepočet statistik, audit.
 *
 * Audit akce: {invoice|purchase_invoice}.{trashed|restored|force_deleted|
 * trash_emptied|trash_autopurged} — payload viz buildAuditPayload().
 */
final class DocumentTrashService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchases,
        private readonly InvoicePdfRenderer $pdf,
        private readonly PdfArchiveService $pdfArchive,
        private readonly InvoiceAttachmentRepository $attachments,
        private readonly StatsRecomputer $stats,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly Config $config,
    ) {}

    // ------------------------------------------------------------------
    // Nastavení koše (per supplier)
    // ------------------------------------------------------------------

    /** @return array{enabled:bool,retention_days:int} */
    public function trashSettings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT doc_trash_enabled, doc_trash_retention_days FROM supplier WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'enabled'        => (bool) ($row['doc_trash_enabled'] ?? true),
            'retention_days' => (int) ($row['doc_trash_retention_days'] ?? 30),
        ];
    }

    // ------------------------------------------------------------------
    // Soft delete / restore
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $row */
    public function trashInvoice(array $row, ?int $userId, string $reason, ?string $ip, string $ua): void
    {
        $this->softDelete('invoices', $row, $userId, $reason);
        $this->logger->log('invoice.trashed', $userId, 'invoice', (int) $row['id'],
            $this->buildAuditPayload($row, 'invoice', $reason), $ip, $ua);
        // Doklad zmizel z Tržeb — revenue cache klienta/projektu musí ven z agregátů.
        $this->recomputeInvoiceStats($row);
    }

    /** @param array<string,mixed> $row */
    public function trashPurchaseInvoice(array $row, ?int $userId, string $reason, ?string $ip, string $ua): void
    {
        $this->softDelete('purchase_invoices', $row, $userId, $reason);
        $this->logger->log('purchase_invoice.trashed', $userId, 'purchase_invoice', (int) $row['id'],
            $this->buildAuditPayload($row, 'purchase_invoice', $reason), $ip, $ua);
    }

    /** @param array<string,mixed> $row */
    public function restoreInvoice(array $row, ?int $userId, ?string $ip, string $ua): void
    {
        $this->restoreRow('invoices', (int) $row['id'], (int) $row['supplier_id']);
        $this->logger->log('invoice.restored', $userId, 'invoice', (int) $row['id'],
            $this->buildAuditPayload($row, 'invoice', null), $ip, $ua);
        $this->recomputeInvoiceStats($row);
    }

    /** @param array<string,mixed> $row */
    public function restorePurchaseInvoice(array $row, ?int $userId, ?string $ip, string $ua): void
    {
        $this->restoreRow('purchase_invoices', (int) $row['id'], (int) $row['supplier_id']);
        $this->logger->log('purchase_invoice.restored', $userId, 'purchase_invoice', (int) $row['id'],
            $this->buildAuditPayload($row, 'purchase_invoice', null), $ip, $ua);
    }

    // ------------------------------------------------------------------
    // Hard delete
    // ------------------------------------------------------------------

    /**
     * Nevratné smazání vydané faktury (vzor: dosavadní DeleteInvoiceAction).
     *
     * @param array<string,mixed> $row
     * @return array{snapshot_id:int,cascade_deleted:int,counter_released:bool,numbering_gap:?string,pdf_deleted:bool}
     */
    public function forceDeleteInvoice(array $row, ?int $userId, ?string $reason, ?string $ip, string $ua, string $auditAction = 'invoice.force_deleted'): array
    {
        $id         = (int) $row['id'];
        $supplierId = (int) $row['supplier_id'];
        $pdo        = $this->db->pdo();

        // Děti (storno / dobropis / daňový doklad) — cascade je smaže, ale musíme
        // je zalogovat, uklidit jim PDF a zahrnout je do snapshotu.
        $st = $pdo->prepare('SELECT * FROM invoices WHERE parent_invoice_id = ?');
        $st->execute([$id]);
        $children = $st->fetchAll(\PDO::FETCH_ASSOC);

        $snapshotId = $this->storeSnapshot('invoice', $row, $userId, $reason, [
            'items'       => $this->rowsFor('invoice_items', 'invoice_id', $id),
            'payments'    => $this->rowsFor('invoice_payments', 'invoice_id', $id),
            'bank_matches'=> $this->rowsFor('payment_matches', 'invoice_id', $id),
            'attachments' => $this->rowsFor('invoice_attachments', 'invoice_id', $id),
            'pdf_history' => $this->rowsFor('invoice_pdfs', 'invoice_id', $id),
            'children'    => $children,
        ]);

        // 1. PDF cache + archiv + přílohy (fyzické soubory; DB řádky smaže cascade)
        $this->pdf->invalidate($id, 'invalidate_manual');
        $purged = $this->pdfArchive->purgeFilesForInvoice($id);
        $purged += $this->attachments->purgeFilesForInvoice($supplierId, $id);
        foreach ($children as $child) {
            $cid = (int) $child['id'];
            $this->pdf->invalidate($cid, 'invalidate_manual');
            $this->pdfArchive->purgeFilesForInvoice($cid);
            $this->attachments->purgeFilesForInvoice($supplierId, $cid);
        }

        // 2. Uvolnění čítače, jen když je doklad poslední ve své řadě (jinak mezera).
        $clientId  = isset($row['client_id']) ? (int) $row['client_id'] : 0;
        $released  = false;
        $type      = (string) ($row['invoice_type'] ?? '');
        $vs        = (string) ($row['varsymbol'] ?? '');
        if (($row['status'] ?? '') !== 'draft' && $vs !== ''
            && in_array($type, ['invoice', 'proforma', 'credit_note'], true)) {
            $issueDate = !empty($row['issue_date']) ? new \DateTimeImmutable((string) $row['issue_date']) : null;
            $released  = $this->varsymbol->releaseIfLatest($supplierId, $type, $vs, $issueDate, $clientId);
        }
        foreach ($children as $child) {
            $ctype = (string) ($child['invoice_type'] ?? '');
            $cvs   = (string) ($child['varsymbol'] ?? '');
            if ($cvs === '' || !in_array($ctype, ['invoice', 'proforma', 'credit_note'], true)) {
                continue;
            }
            $cdate = !empty($child['issue_date']) ? new \DateTimeImmutable((string) $child['issue_date']) : null;
            $this->varsymbol->releaseIfLatest($supplierId, $ctype, $cvs, $cdate, $clientId);
        }

        // 3. DELETE (cascade: items, work_reports, child invoices, invoice_pdfs,
        //    invoice_attachments, invoice_payments, payment_matches)
        $this->invoices->delete($id);

        // 4. Statistiky
        $this->recomputeInvoiceStats($row);

        // 5. Audit
        $gap = (!$released && ($row['status'] ?? '') !== 'draft' && $vs !== '') ? $vs : null;
        $payload = $this->buildAuditPayload($row, 'invoice', $reason) + [
            'snapshot_id'      => $snapshotId,
            'pdf_deleted'      => $purged > 0,
            'counter_released' => $released,
            'numbering_gap'    => $gap,
            'cascade_deleted'  => array_map(static fn (array $c): array => [
                'id'        => (int) $c['id'],
                'type'      => $c['invoice_type'] ?? null,
                'varsymbol' => $c['varsymbol'] ?? null,
                'status'    => $c['status'] ?? null,
            ], $children),
        ];
        $this->logger->log($auditAction, $userId, 'invoice', $id, $payload, $ip, $ua);

        return [
            'snapshot_id'      => $snapshotId,
            'cascade_deleted'  => count($children),
            'counter_released' => $released,
            'numbering_gap'    => $gap,
            'pdf_deleted'      => $purged > 0,
        ];
    }

    /**
     * Nevratné smazání přijaté faktury (vzor: dosavadní DeletePurchaseInvoiceAction
     * vč. orphan-aware úklidu PDF s realpath guardem).
     *
     * @param array<string,mixed> $row
     * @return array{snapshot_id:int,counter_released:bool,numbering_gap:?string,pdf_deleted:bool}
     */
    public function forceDeletePurchaseInvoice(array $row, ?int $userId, ?string $reason, ?string $ip, string $ua, string $auditAction = 'purchase_invoice.force_deleted'): array
    {
        $id         = (int) $row['id'];
        $supplierId = (int) $row['supplier_id'];

        $snapshotId = $this->storeSnapshot('purchase_invoice', $row, $userId, $reason, [
            'items'        => $this->rowsFor('purchase_invoice_items', 'purchase_invoice_id', $id),
            'bank_matches' => $this->rowsFor('payment_matches', 'purchase_invoice_id', $id),
        ]);

        $pdfPath = (string) ($row['pdf_path'] ?? '');
        $pdfHash = (string) ($row['pdf_hash'] ?? '');

        // Uvolnění čítače interní řady, jen je-li doklad poslední (jinak mezera).
        $vs       = (string) ($row['varsymbol'] ?? '');
        $released = false;
        if ($vs !== '') {
            $period   = $this->purchasePeriod($row);
            $released = $this->purchases->releasePurchaseVarsymbolIfLatest($supplierId, $vs, $period);
        }

        $this->purchases->delete($id, $supplierId);

        // Orphan PDF cleanup — soubor smaž, jen když už na hash neukazuje jiný doklad.
        $pdfDeleted = false;
        if ($pdfPath !== '' && $pdfHash !== '') {
            $stillUsed = $this->purchases->findIdByPdfHash($supplierId, $pdfHash);
            if ($stillUsed === null) {
                $pdfDeleted = $this->safeUnlinkPurchasePdf($supplierId, $pdfPath);
            }
        }

        $gap = (!$released && $vs !== '') ? $vs : null;
        $payload = $this->buildAuditPayload($row, 'purchase_invoice', $reason) + [
            'snapshot_id'      => $snapshotId,
            'pdf_deleted'      => $pdfDeleted,
            'counter_released' => $released,
            'numbering_gap'    => $gap,
        ];
        $this->logger->log($auditAction, $userId, 'purchase_invoice', $id, $payload, $ip, $ua);

        return [
            'snapshot_id'      => $snapshotId,
            'counter_released' => $released,
            'numbering_gap'    => $gap,
            'pdf_deleted'      => $pdfDeleted,
        ];
    }

    // ------------------------------------------------------------------
    // Interní pomocníci
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function softDelete(string $table, array $row, ?int $userId, string $reason): void
    {
        $this->db->pdo()->prepare(
            "UPDATE $table SET deleted_at = NOW(), deleted_by = ?, delete_reason = ?
              WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL"
        )->execute([$userId, $reason, (int) $row['id'], (int) $row['supplier_id']]);
    }

    private function restoreRow(string $table, int $id, int $supplierId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE $table SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL
              WHERE id = ? AND supplier_id = ? AND deleted_at IS NOT NULL"
        )->execute([$id, $supplierId]);
    }

    /**
     * Kompletní JSON otisk dokladu do deleted_document_snapshots (forenzní dohledání
     * po hard delete). Vrací id snapshotu.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $related
     */
    private function storeSnapshot(string $entityType, array $row, ?int $userId, ?string $reason, array $related): int
    {
        $payload = ['header' => $row] + $related;
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO deleted_document_snapshots
                (supplier_id, entity_type, entity_id, payload, deleted_at, deleted_by, reason)
             VALUES (?, ?, ?, ?, NOW(), ?, ?)'
        )->execute([
            (int) $row['supplier_id'],
            $entityType,
            (int) $row['id'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $userId,
            $reason,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    private function rowsFor(string $table, string $column, int $id): array
    {
        $st = $this->db->pdo()->prepare("SELECT * FROM $table WHERE $column = ?");
        $st->execute([$id]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Společný základ audit payloadu (spec §7): číslo dokladu, protistrana + IČO,
     * celkem s DPH, DUZP, stav před operací, důvod.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildAuditPayload(array $row, string $entityType, ?string $reason): array
    {
        $party = null;
        $ico   = null;
        $snapshotKey = $entityType === 'invoice' ? 'client_snapshot' : 'vendor_snapshot';
        $snap = $row[$snapshotKey] ?? null;
        if (is_string($snap) && $snap !== '') {
            $decoded = json_decode($snap, true);
            if (is_array($decoded)) {
                $party = $decoded['company_name'] ?? $decoded['name'] ?? null;
                $ico   = $decoded['ic'] ?? $decoded['ico'] ?? null;
            }
        }
        return array_filter([
            'varsymbol'     => $row['varsymbol'] ?? null,
            'type'          => $row['invoice_type'] ?? ($row['document_kind'] ?? null),
            'party'         => $party,
            'party_ico'     => $ico,
            'total'         => $row['total_with_vat'] ?? null,
            'tax_date'      => $row['tax_date'] ?? null,
            'status_before' => $row['status'] ?? null,
            'reason'        => $reason,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string,mixed> $row */
    private function recomputeInvoiceStats(array $row): void
    {
        $clientId  = isset($row['client_id']) ? (int) $row['client_id'] : null;
        $projectId = isset($row['project_id']) && $row['project_id'] ? (int) $row['project_id'] : null;
        if ($clientId !== null) {
            $this->stats->recomputeForIds($clientId, $projectId);
        }
    }

    /** Období číselné řady přijatého dokladu (YYYYMM z DUZP, fallback vystavení/dnešek). */
    private function purchasePeriod(array $row): string
    {
        foreach (['tax_date', 'issue_date'] as $key) {
            if (!empty($row[$key])) {
                try {
                    return (new \DateTimeImmutable((string) $row[$key]))->format('Ym');
                } catch (\Exception) {
                    // pokračuj dalším klíčem
                }
            }
        }
        return date('Ym');
    }

    /**
     * Smaže PDF přijaté faktury s realpath checkem vůči archive rootu
     * (path traversal guard) — přeneseno z DeletePurchaseInvoiceAction.
     */
    private function safeUnlinkPurchasePdf(int $supplierId, string $relativePath): bool
    {
        $archiveRoot = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($archiveRoot === '') {
            $storageBase = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $storageBase !== ''
                ? dirname($storageBase) . '/purchase-invoices'
                : \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
        }
        $fullPath = $archiveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $archiveRootReal = realpath($archiveRoot);
        $fullPathReal = realpath($fullPath);
        if ($archiveRootReal === false || $fullPathReal === false || !is_file($fullPathReal)) {
            return false;
        }
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $haystack = ($isWindows ? strtolower($fullPathReal) : $fullPathReal);
        $needle   = ($isWindows ? strtolower($archiveRootReal) : $archiveRootReal) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($haystack, $needle)) {
            return false;
        }
        return @unlink($fullPathReal);
    }
}
