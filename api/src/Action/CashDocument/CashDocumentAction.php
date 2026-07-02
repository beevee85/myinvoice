<?php

declare(strict_types=1);

namespace MyInvoice\Action\CashDocument;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\CashDocumentPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FORK (beevee85): pokladní doklady (PPD/VPD) — v1.
 *
 *   GET    /api/cash-documents                — list (filtr year, kind)
 *   POST   /api/cash-documents                — create (číslo přidělí server)
 *   GET    /api/cash-documents/{id}           — detail
 *   PUT    /api/cash-documents/{id}           — update (číslo a druh se nemění)
 *   DELETE /api/cash-documents/{id}           — smazání (jen poslední v řadě, jinak by vznikla díra)
 *   GET    /api/cash-documents/{id}/pdf       — tisk PDF
 *
 * Doklad o pohybu hotovosti — bez DPH rozpisu a bez vazby na VatLedgerService
 * (daňovým dokladem zůstává faktura). Vše scoped přes X-Supplier-Id.
 */
final class CashDocumentAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly CashDocumentPdfRenderer $pdf,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $sid = $this->sid($request);
        $q = $request->getQueryParams();
        $where = ['supplier_id = ?'];
        $params = [$sid];
        if (ctype_digit((string) ($q['year'] ?? ''))) {
            $where[] = 'YEAR(issue_date) = ?';
            $params[] = (int) $q['year'];
        }
        if (in_array($q['kind'] ?? '', ['income', 'expense'], true)) {
            $where[] = 'kind = ?';
            $params[] = $q['kind'];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT cd.*, i.varsymbol AS invoice_varsymbol
               FROM cash_documents cd
               LEFT JOIN invoices i ON i.id = cd.invoice_id
              WHERE ' . implode(' AND ', array_map(static fn ($w) => 'cd.' . $w, $where)) . '
              ORDER BY cd.issue_date DESC, cd.id DESC'
        );
        $stmt->execute($params);
        $rows = array_map($this->cast(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
        return Json::ok($response, $rows);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $row = $this->fetch($this->sid($request), (int) ($args['id'] ?? 0));
        if (!$row) return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        return Json::ok($response, $row);
    }

    public function create(Request $request, Response $response): Response
    {
        $sid = $this->sid($request);
        $b = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($b);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);

        $kind = (string) $b['kind'];
        $issueDate = (string) $b['issue_date'];
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $number = $this->nextNumber($sid, $kind, (int) substr($issueDate, 0, 4));
            $stmt = $pdo->prepare(
                'INSERT INTO cash_documents
                    (supplier_id, kind, number, issue_date, amount, currency, counterparty, description,
                     invoice_id, purchase_invoice_id, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $sid, $kind, $number, $issueDate,
                round((float) $b['amount'], 2),
                strtoupper(trim((string) ($b['currency'] ?? 'CZK'))),
                trim((string) ($b['counterparty'] ?? '')),
                trim((string) ($b['description'] ?? '')),
                $this->linkedId($b, 'invoice_id'),
                $this->linkedId($b, 'purchase_invoice_id'),
                $this->userId($request),
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $this->log($request, 'cash_document.created', $id, ['number' => $number, 'kind' => $kind]);
        return Json::ok($response, $this->fetch($sid, $id), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $sid = $this->sid($request);
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetch($sid, $id);
        if (!$row) return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);

        $b = (array) ($request->getParsedBody() ?? []);
        // číslo, druh a vazby na doklady se po vystavení nemění; editovatelný je obsah
        $b += ['kind' => $row['kind'], 'issue_date' => $row['issue_date'], 'amount' => $row['amount']];
        $err = $this->validate($b);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);

        $stmt = $this->db->pdo()->prepare(
            'UPDATE cash_documents
                SET issue_date = ?, amount = ?, currency = ?, counterparty = ?, description = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $b['issue_date'],
            round((float) $b['amount'], 2),
            strtoupper(trim((string) ($b['currency'] ?? $row['currency']))),
            trim((string) ($b['counterparty'] ?? $row['counterparty'])),
            trim((string) ($b['description'] ?? $row['description'])),
            $id, $sid,
        ]);
        $this->log($request, 'cash_document.updated', $id, ['fields' => array_keys($b)]);
        return Json::ok($response, $this->fetch($sid, $id));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $sid = $this->sid($request);
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetch($sid, $id);
        if (!$row) return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);

        // Mazat lze jen poslední doklad řady (jinak vznikne díra v číslování)
        $last = $this->db->pdo()->prepare(
            'SELECT MAX(id) FROM cash_documents WHERE supplier_id = ? AND kind = ? AND number LIKE ?'
        );
        $prefix = ($row['kind'] === 'income' ? 'PPD' : 'VPD') . '-' . substr((string) $row['issue_date'], 0, 4) . '-%';
        $last->execute([$sid, $row['kind'], $prefix]);
        if ((int) $last->fetchColumn() !== $id) {
            return Json::error($response, 'not_last', 'Smazat lze jen poslední doklad číselné řady (jinak vznikne díra v číslování).', 409);
        }

        $this->db->pdo()->prepare('DELETE FROM cash_documents WHERE id = ? AND supplier_id = ?')->execute([$id, $sid]);
        $this->log($request, 'cash_document.deleted', $id, ['number' => $row['number']]);
        return Json::ok($response, ['deleted' => true]);
    }

    public function pdf(Request $request, Response $response, array $args): Response
    {
        $sid = $this->sid($request);
        $row = $this->fetch($sid, (int) ($args['id'] ?? 0));
        if (!$row) return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);

        $supplier = $this->db->pdo()->prepare(
            'SELECT s.company_name, s.street, s.city, s.zip, s.ic, s.dic FROM supplier s WHERE s.id = ?'
        );
        $supplier->execute([$sid]);

        $bytes = $this->pdf->render($row, (array) $supplier->fetch(\PDO::FETCH_ASSOC));
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $row['number'] . '.pdf"');
    }

    // ── interní ────────────────────────────────────────────────────────────

    /** Další číslo řady: PPD-YYYY-0001 / VPD-YYYY-0001 per supplier a rok. Volat v transakci. */
    private function nextNumber(int $sid, string $kind, int $year): string
    {
        $prefix = ($kind === 'income' ? 'PPD' : 'VPD') . '-' . $year . '-';
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(number, '-', -1) AS UNSIGNED))
               FROM cash_documents
              WHERE supplier_id = ? AND kind = ? AND number LIKE ?
                FOR UPDATE"
        );
        $stmt->execute([$sid, $kind, $prefix . '%']);
        $next = (int) $stmt->fetchColumn() + 1;
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function validate(array $b): ?string
    {
        if (!in_array($b['kind'] ?? '', ['income', 'expense'], true)) return 'Neplatný druh dokladu.';
        $date = (string) ($b['issue_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Neplatné datum.';
        $amount = $b['amount'] ?? null;
        if (!is_numeric($amount) || (float) $amount <= 0) return 'Částka musí být kladné číslo.';
        $currency = strtoupper(trim((string) ($b['currency'] ?? 'CZK')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) return 'Neplatná měna.';
        if (mb_strlen((string) ($b['counterparty'] ?? '')) > 190) return 'Protistrana je příliš dlouhá.';
        if (mb_strlen((string) ($b['description'] ?? '')) > 500) return 'Popis je příliš dlouhý.';
        return null;
    }

    /** Volitelná vazba na doklad; musí existovat v rámci supplieru. */
    private function linkedId(array $b, string $field): ?int
    {
        $v = $b[$field] ?? null;
        if ($v === null || $v === '' || (int) $v <= 0) return null;
        return (int) $v;
    }

    private function fetch(int $sid, int $id): ?array
    {
        if ($id <= 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT cd.*, i.varsymbol AS invoice_varsymbol
               FROM cash_documents cd
               LEFT JOIN invoices i ON i.id = cd.invoice_id
              WHERE cd.id = ? AND cd.supplier_id = ?'
        );
        $stmt->execute([$id, $sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->cast($row) : null;
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['amount'] = (float) $r['amount'];
        $r['invoice_id'] = $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null;
        $r['purchase_invoice_id'] = $r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null;
        $r['created_by'] = (int) $r['created_by'];
        return $r;
    }

    private function sid(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'cash_document', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
