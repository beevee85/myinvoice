<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices/{id}
 *
 * Vrátí detail přijaté faktury vč. items + vat_breakdown + totals.
 * Vrací 404 pokud neexistuje NEBO patří jinému tenantovi (neprozrazujeme existenci).
 */
final class GetPurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ProjectRepository $projects,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $invoice = $this->repo->find($id, SupplierGuard::currentId($request));
        if ($invoice === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        // FORK 0923 (D5): obchodní případ — nákup/prodej/marže zakázky dokladu.
        if (!empty($invoice['project_id'])) {
            $invoice['case_summary'] = $this->projects->caseSummary((int) $invoice['project_id']);
        }

        return Json::ok($response, $invoice);
    }
}
