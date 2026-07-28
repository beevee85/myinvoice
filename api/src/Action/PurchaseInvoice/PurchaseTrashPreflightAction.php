<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\DocumentTrashPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/trash-preflight  body: { ids: number[] }
 *
 * Read-only vyhodnocení blokujících pravidel pro potvrzovací dialog
 * (jednotlivý i hromadný). Blokované doklady dialog vypíše s důvodem
 * a hromadná operace je přeskočí.
 */
final class PurchaseTrashPreflightAction
{
    private const MAX_BATCH = 200;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly DocumentTrashPolicy $policy,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) $request->getParsedBody();
        $ids = array_slice(
            array_values(array_unique(array_filter(array_map('intval', (array) ($body['ids'] ?? []))))),
            0,
            self::MAX_BATCH,
        );
        if ($ids === []) {
            return Json::error($response, 'invalid_ids', 'Chybí seznam dokladů.', 400);
        }

        $result = [];
        foreach ($ids as $id) {
            $row = $this->repo->find($id, $supplierId);
            if ($row === null) {
                $result[] = ['id' => $id, 'found' => false];
                continue;
            }
            $result[] = [
                'id'        => $id,
                'found'     => true,
                'varsymbol' => $row['varsymbol'] ?? null,
                'status'    => $row['status'] ?? null,
                'in_trash'  => !empty($row['deleted_at']),
                'blockers'  => $this->policy->blockersForPurchaseInvoice($row),
            ];
        }
        return Json::ok($response, ['documents' => $result]);
    }
}
