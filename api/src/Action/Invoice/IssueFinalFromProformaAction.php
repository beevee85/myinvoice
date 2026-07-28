<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TrashGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Vystaví finální daňový doklad k zaplacené proformě.
 * Vytvoří DRAFT typu `invoice` s:
 *   - parent_invoice_id = id proformy
 *   - kopie všech položek z proformy
 *   - advance_paid_amount = (custom nebo proforma.total_with_vat)
 *   - amount_to_pay = total - advance (typicky 0)
 *
 * User pak otevře editor, zkontroluje a zavolá /issue.
 */
final class IssueFinalFromProformaAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly FinalFromProformaCreator $creator,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $proformaId = (int) ($args['id'] ?? 0);
        $proforma = $this->repo->find($proformaId);
        if (!SupplierGuard::owns($request, $proforma)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        // Doklad v koši je read-only (soft delete, 0905).
        if (($blocked = TrashGuard::blockIfTrashed($proforma, $response)) !== null) {
            return $blocked;
        }
        if ($proforma['invoice_type'] !== 'proforma') {
            return Json::error($response, 'not_proforma', 'Lze pouze ze zálohové faktury (proforma).', 409);
        }
        // Vyúčtovat lze zaplacenou i ČÁSTEČNĚ uhrazenou proformu (#89) — plnění může
        // proběhnout dřív, než dorazí celá záloha; odpočet pokryje jen přijaté platby
        // a zbytek zůstane na finálním dokladu k úhradě.
        if ($proforma['status'] !== 'paid' && (float) ($proforma['paid_total'] ?? 0) <= 0) {
            return Json::error($response, 'not_paid', 'Proforma musí mít alespoň částečnou úhradu.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $taxDate = isset($body['tax_date']) && $body['tax_date'] !== '' ? (string) $body['tax_date'] : null;
        $dueDate = isset($body['due_date']) && $body['due_date'] !== '' ? (string) $body['due_date'] : null;
        $advance = isset($body['advance_paid_amount']) && $body['advance_paid_amount'] !== null && $body['advance_paid_amount'] !== ''
            ? (float) $body['advance_paid_amount']
            : null;

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        try {
            $finalId = $this->creator->create($proformaId, $userId, $taxDate, $dueDate, $advance);
        } catch (\Throwable $e) {
            return Json::error($response, 'create_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('proforma.final_issued', $userId, 'invoice', $proformaId, [
            'final_invoice_id' => $finalId,
            'trigger'          => 'manual',
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'final_invoice_id' => $finalId,
            'edit_url'         => "/invoices/$finalId/edit",
            'invoice'          => $this->repo->find($finalId),
        ], 201);
    }
}
