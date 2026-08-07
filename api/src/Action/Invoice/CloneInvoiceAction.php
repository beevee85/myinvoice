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
 * POST /api/invoices/{id}/clone
 * Body (volitelně):
 *   { "increment_month_in_descriptions": true, "issue_date": "YYYY-MM-DD" }
 *
 * Vrací: { draft_id: int }
 */
final class CloneInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly BulkReissueAction $bulk,
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
        // FORK audit 2026-08-07: doklad v koši je read-only (0905).
        if (($blocked = TrashGuard::blockIfTrashed($invoice, $response)) !== null) {
            return $blocked;
        }
        // Daňový doklad k přijaté platbě je vázaný na konkrétní platbu — kopie nedává
        // smysl (vzniká vždy automaticky z platby zálohové faktury).
        if (($invoice['invoice_type'] ?? '') === 'tax_document') {
            return Json::error($response, 'invalid_type', 'Daňový doklad k přijaté platbě nelze klonovat.', 409);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $incrementMonth = (bool) ($body['increment_month_in_descriptions'] ?? false);
        $issueDate = !empty($body['issue_date']) ? (string) $body['issue_date'] : date('Y-m-d');

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        try {
            $newId = $this->bulk->cloneOne($id, $issueDate, $incrementMonth, $userId);
        } catch (\Throwable $e) {
            return Json::error($response, 'clone_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.cloned', $userId, 'invoice', $id, [
            'new_draft_id' => $newId, 'increment_month' => $incrementMonth,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['draft_id' => $newId], 201);
    }
}
