<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice\BatchImport;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\PurchaseImportBatchRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FORK (beevee85) — GET /api/purchase-invoices/batch-import
 *
 * Seznam dávek tenanta. Kromě seznamu slouží frontendové navigaci jako
 * DETEKCE PŘÍZNAKU: routa existuje jen se zapnutým `batch_import.enabled`,
 * takže 200 = featura zapnutá, 404 = jako by neexistovala. Frontend tak
 * nepotřebuje žádný nový konfigurační kanál — a vypnutá featura zůstává
 * neviditelná i v odpovědích, přesně jako u ostatních rout za příznakem.
 *
 * `token_sha256` tu není ani omylem — listForTenant ho vůbec nevybírá.
 */
final class ListBatchesAction
{
    public function __construct(
        private readonly PurchaseImportBatchRepository $repo,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $limit = (int) (($request->getQueryParams()['limit'] ?? 20));

        return Json::ok($response, [
            'batches' => $this->repo->listForTenant($supplierId, $limit > 0 ? $limit : 20),
        ]);
    }
}
