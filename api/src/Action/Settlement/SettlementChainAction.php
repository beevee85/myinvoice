<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settlement;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\Settlement\SettlementGroupService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FORK 0922 — GET /api/purchase-invoices/{id}/settlement-chain
 *             GET /api/invoices/{id}/settlement-chain
 *
 * Kroky vodorovného steppera řetězce (Dokument 1, D1) pro detail dokladu:
 * členové vyúčtovací skupiny dokladu, seřazení dle data. Prázdný seznam =
 * doklad není součástí žádného případu (stepper se nezobrazí).
 */
final class SettlementChainAction
{
    public function __construct(private readonly SettlementGroupService $service)
    {
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $direction = (string) ($args['direction'] ?? 'purchase');
        if (!in_array($direction, ['purchase', 'sale'], true)) {
            return Json::error($response, 'invalid_direction', 'Neplatný směr.', 400);
        }
        $id = (int) ($args['id'] ?? 0);
        $supplierId = SupplierGuard::currentId($request);

        return Json::ok($response, [
            'steps' => $this->service->chainForDocument($supplierId, $direction, $id),
        ]);
    }
}
