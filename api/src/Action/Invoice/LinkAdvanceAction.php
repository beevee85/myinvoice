<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TrashGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/link-advance  body: {advance_id}
 *
 * Propojí daňový doklad se zálohovou fakturou (proforma) — uloží parent_invoice_id
 * a doplní advance_paid_amount, pokud na faktuře žádná záloha ještě není. Zaplacení
 * (status) se nemění. Vrací aktualizovaný invoice payload.
 */
final class LinkAdvanceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        // Doklad v koši je read-only (soft delete, 0905).
        if (($blocked = TrashGuard::blockIfTrashed($invoice, $response)) !== null) {
            return $blocked;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $advanceId = (int) ($body['advance_id'] ?? 0);
        if ($advanceId <= 0) {
            return Json::error($response, 'invalid_advance', 'Chybí advance_id.', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        try {
            $this->repo->linkAdvance($id, $advanceId, $supplierId);
        } catch (\Throwable $e) {
            return Json::error($response, 'link_failed', $e->getMessage(), 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.advance_linked', $user['id'] ?? null, 'invoice', $id, [
            'advance_id' => $advanceId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }
}
