<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice\BatchImport;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseImportBatchRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FORK (beevee85) — GET /api/purchase-invoices/batch-import/{id}
 *
 * Stav dávky a nálezy pro review UI.
 *
 * CO SE VEN NEDOSTANE: `raw_json` ani `normalized_json`. Nesou obsah cizích
 * dokladů a UI je k zobrazení stavu nepotřebuje — potřebuje NÁLEZY. Kdyby je
 * endpoint vracel, vzniklo by druhé místo, kudy osobní údaje odcházejí, a
 * retence V79b by ztratila smysl: co odešlo, se nedá odmazat.
 *
 * Hash tokenu se nevrací také ne — ten je tajemství, ne stav.
 */
final class GetBatchAction
{
    public function __construct(
        private readonly PurchaseImportBatchRepository $repo,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $batchId    = (int) ($args['id'] ?? 0);

        $batch = $this->repo->find($batchId, $supplierId);
        if ($batch === null) {
            // 404 i pro cizí dávku — 403 by prozradilo, že existuje.
            return Json::error($response, 'batch_not_found', 'Dávka nenalezena.', 404);
        }

        unset($batch['token_sha256']);

        return Json::ok($response, [
            'batch'   => $batch,
            'files'   => $this->repo->filesForBatch($batchId, $supplierId),
            'results' => $this->results($batchId, $supplierId),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function results(int $batchId, int $supplierId): array
    {
        // Záměrně BEZ raw_json a normalized_json.
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, purchase_import_batch_file_id, status, findings_json,
                    purchase_invoice_id, raw_purged_at, normalized_purged_at, created_at
               FROM purchase_import_batch_results
              WHERE purchase_import_batch_id = ? AND supplier_id = ?
              ORDER BY id ASC'
        );
        $stmt->execute([$batchId, $supplierId]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $row['id']                            = (int) $row['id'];
            $row['purchase_import_batch_file_id'] = (int) $row['purchase_import_batch_file_id'];
            $row['purchase_invoice_id']           = $row['purchase_invoice_id'] === null
                ? null : (int) $row['purchase_invoice_id'];
            $row['findings'] = json_decode((string) ($row['findings_json'] ?? '[]'), true) ?: [];
            unset($row['findings_json']);
            $out[] = $row;
        }

        return $out;
    }
}
