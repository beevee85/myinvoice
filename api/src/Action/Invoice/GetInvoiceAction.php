<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly ProjectRepository $projects,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $invoice = $this->repo->find($id);
        if ($invoice === null || (int) ($invoice['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        // Backfill kurzu pro starší / chybějící exchange_rate (cache → ČNB → last known)
        if (
            (string) ($invoice['currency'] ?? 'CZK') !== 'CZK'
            && empty($invoice['exchange_rate'])
        ) {
            $this->rateApplier->ensureRate($id);
            $invoice = $this->repo->find($id);
        }

        // FORK 0923 (D5): obchodní případ — nákup/prodej/marže zakázky dokladu.
        if (!empty($invoice['project_id'])) {
            $invoice['case_summary'] = $this->projects->caseSummary((int) $invoice['project_id']);
        }

        return Json::ok($response, $invoice);
    }
}
