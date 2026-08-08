<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settlement;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Settlement\SettlementGroupService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FORK 0922 — GET /api/purchase-invoices/settlement-groups
 *             GET /api/invoices/settlement-groups
 *
 * Data pro režim seznamu „Podle vyúčtování" (Dokument 1, C1–C3): vyúčtovací
 * skupiny s členy + samostatné doklady mimo skupiny. Skupiny se před čtením
 * přestaví z vazeb (self-healing, viz SettlementGroupService).
 *
 * Řazení řeší frontend: skupina dle data konečné faktury (bez ní dle
 * nejnovějšího člena, stav Otevřeno), samostatné doklady dle svého data.
 */
final class SettlementGroupsAction
{
    public function __construct(
        private readonly SettlementGroupService $service,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $direction = (string) ($args['direction'] ?? 'purchase');
        if (!in_array($direction, ['purchase', 'sale'], true)) {
            return Json::error($response, 'invalid_direction', 'Neplatný směr.', 400);
        }
        $supplierId = SupplierGuard::currentId($request);

        $groups = $this->service->listGroups($supplierId, $direction);
        foreach ($groups as &$g) {
            // Datum pro řazení (C3): konečná faktura, jinak nejnovější člen.
            $sortDate = null;
            $finalVarsymbol = null;
            $finalAmountToPay = null;
            foreach ($g['members'] as $m) {
                if ($g['final_document_id'] !== null && $m['id'] === $g['final_document_id']) {
                    $sortDate = $m['issue_date'];
                    $finalVarsymbol = $m['varsymbol'] ?? null;
                    $finalAmountToPay = ($m['amount_to_pay'] ?? 0) + ($m['rounding'] ?? 0);
                }
            }
            if ($sortDate === null) {
                foreach ($g['members'] as $m) {
                    if ($sortDate === null || $m['issue_date'] > $sortDate) {
                        $sortDate = $m['issue_date'];
                    }
                }
            }
            $g['sort_date'] = $sortDate;
            $g['final_varsymbol'] = $finalVarsymbol;
            $g['final_amount_to_pay'] = $finalAmountToPay;
        }
        unset($g);

        return Json::ok($response, [
            'groups'     => $groups,
            'standalone' => $this->standaloneDocs($supplierId, $direction),
        ]);
    }

    /**
     * Doklady mimo jakoukoli skupinu — v režimu „Podle vyúčtování" se zobrazují
     * jako samostatné řádky (C2). Kompaktní tvar shodný s members.
     *
     * @return list<array<string,mixed>>
     */
    private function standaloneDocs(int $supplierId, string $direction): array
    {
        $pdo = $this->db->pdo();
        if ($direction === 'purchase') {
            $stmt = $pdo->prepare(
                'SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                        pi.settlement_role, pi.issue_date, pi.tax_date, pi.due_date,
                        pi.total_with_vat, pi.rounding, pi.amount_to_pay, pi.status, pi.paid_at,
                        cur.code AS currency, c.company_name AS counterparty_name
                   FROM purchase_invoices pi
                   JOIN currencies cur ON cur.id = pi.currency_id
                   JOIN clients c ON c.id = pi.vendor_id
                  WHERE pi.supplier_id = ? AND pi.deleted_at IS NULL
                    AND pi.settlement_group_id IS NULL
               ORDER BY pi.issue_date DESC, pi.id DESC'
            );
        } else {
            $stmt = $pdo->prepare(
                "SELECT i.id, i.varsymbol, NULL AS vendor_invoice_number, i.invoice_type AS document_kind,
                        i.settlement_role, i.issue_date, i.tax_date, i.due_date,
                        i.total_with_vat, 0 AS rounding, i.amount_to_pay, i.status, i.paid_at,
                        cur.code AS currency, c.company_name AS counterparty_name
                   FROM invoices i
                   JOIN currencies cur ON cur.id = i.currency_id
                   JOIN clients c ON c.id = i.client_id
                  WHERE i.supplier_id = ? AND i.deleted_at IS NULL
                    AND i.settlement_group_id IS NULL
                    AND i.invoice_type <> 'cancellation'
               ORDER BY i.issue_date DESC, i.id DESC"
            );
        }
        $stmt->execute([$supplierId]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            foreach (['total_with_vat', 'rounding', 'amount_to_pay'] as $f) {
                $r[$f] = $r[$f] !== null ? (float) $r[$f] : 0.0;
            }
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
}
