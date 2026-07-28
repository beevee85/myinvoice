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
 * POST /api/purchase-invoices/{id}/link-settlement-doc
 * body: { tax_document_id: number, apply_deduction?: boolean (default true) }
 *
 * Napáruje daňový doklad k přijaté záloze (DDKPZ) na konečnou (vyúčtovací)
 * fakturu {id}. S apply_deduction (výchozí) doplní na konečnou fakturu záporné
 * odpočtové řádky § 37a a sladí rekapitulaci DPH tak, aby do přiznání/KH
 * i nákladů vstoupil jen rozdíl. apply_deduction=false použij, když doklad
 * dodavatele odpočet záloh už obsahuje ve vlastních řádcích (např. přepsaný
 * z PDF při AI importu).
 *
 * Vrací aktualizovaný payload konečné faktury (vč. settlement_documents).
 */
final class LinkSettlementDocPurchaseInvoiceAction
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
        // Doklad v koši je read-only (soft delete, 0905). Protistranu (tax_document_id
        // z těla) kontroluje PurchaseSettlementService::link() — sem nedosáhne.
        if (($blocked = TrashGuard::blockIfTrashed($existing, $response)) !== null) {
            return $blocked;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $taxDocId = (int) ($body['tax_document_id'] ?? 0);
        if ($taxDocId <= 0) {
            return Json::error($response, 'invalid_tax_document', 'Chybí tax_document_id.', 400);
        }
        $applyDeduction = !array_key_exists('apply_deduction', $body) || !empty($body['apply_deduction']);

        try {
            $this->settlement->link($id, $taxDocId, $supplierId, $applyDeduction);
        } catch (\Throwable $e) {
            return Json::error($response, 'link_failed', $e->getMessage(), 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.settlement_doc_linked', $user['id'] ?? null, 'purchase_invoice', $id, [
            'tax_document_id' => $taxDocId,
            'apply_deduction' => $applyDeduction,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
