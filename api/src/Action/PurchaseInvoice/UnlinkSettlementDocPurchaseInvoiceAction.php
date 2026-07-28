<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TrashGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PurchaseSettlementService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/purchase-invoices/{id}/link-settlement-doc?tax_document_id=N
 * (tax_document_id lze poslat i v body)
 *
 * Zruší párování DDKPZ ↔ konečná faktura {id}: odebere auto-generované odpočtové
 * řádky § 37a a vrátí rekapitulaci DPH o hodnoty dokladu zpět.
 */
final class UnlinkSettlementDocPurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PurchaseSettlementService $settlement,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }
        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        // Doklad v koši je read-only (soft delete, 0905) — rušení vazby mění položky
        // i rekapitulaci DPH. Protistranu kontroluje PurchaseSettlementService::unlink().
        if (($blocked = TrashGuard::blockIfTrashed($existing, $response)) !== null) {
            return $blocked;
        }

        $query = (array) $request->getQueryParams();
        $body  = (array) ($request->getParsedBody() ?? []);
        $taxDocId = (int) ($query['tax_document_id'] ?? $body['tax_document_id'] ?? 0);
        if ($taxDocId <= 0) {
            return Json::error($response, 'invalid_tax_document', 'Chybí tax_document_id.', 400);
        }

        try {
            $this->settlement->unlink($id, $taxDocId, $supplierId);
        } catch (\Throwable $e) {
            return Json::error($response, 'unlink_failed', $e->getMessage(), 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.settlement_doc_unlinked', $user['id'] ?? null, 'purchase_invoice', $id, [
            'tax_document_id' => $taxDocId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
