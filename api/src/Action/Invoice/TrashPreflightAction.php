<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\DocumentTrashPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/trash-preflight  body: { ids: number[] }
 *
 * Vyhodnotí blokující pravidla pro dávku dokladů PŘED zobrazením potvrzovacího
 * dialogu (jednotlivého i hromadného). Nic nemění — jen čte. Dialog z výsledku
 * vypíše čísla dotčených dokladů a u každého případný blokující důvod;
 * blokované se při hromadné operaci přeskočí.
 */
final class TrashPreflightAction
{
    private const MAX_BATCH = 200;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly DocumentTrashPolicy $policy,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
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
            $row = $this->repo->find($id);
            if (!SupplierGuard::owns($request, $row)) {
                $result[] = ['id' => $id, 'found' => false];
                continue;
            }
            $result[] = [
                'id'        => $id,
                'found'     => true,
                'varsymbol' => $row['varsymbol'] ?? null,
                'status'    => $row['status'] ?? null,
                'in_trash'  => !empty($row['deleted_at']),
                'blockers'  => $this->policy->blockersForInvoice($row),
            ];
        }
        return Json::ok($response, ['documents' => $result]);
    }
}
