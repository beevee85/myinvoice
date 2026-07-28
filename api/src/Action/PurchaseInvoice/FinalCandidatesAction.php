<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices/{id}/final-candidates
 *
 * Kandidáti konečných (vyúčtovacích) faktur pro párování z detailu daňového
 * dokladu k přijaté záloze (§ 37a) — zrcadlí validaci PurchaseSettlementService::link
 * (bez záloh, DDKPZ a dobropisů; vazba na zálohu párování nebrání).
 */
final class FinalCandidatesAction
{
    public function __construct(private readonly PurchaseInvoiceRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        return Json::ok($response, ['candidates' => $this->repo->finalCandidates($id, $supplierId)]);
    }
}
