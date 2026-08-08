<?php

declare(strict_types=1);

namespace MyInvoice\Action\CashDocument;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\CashBookPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FORK 0924 — pokladny a pokladní kniha (Doplněk H4).
 *
 *   GET  /api/cash-registers                  — seznam pokladen tenanta
 *   POST /api/cash-registers                  — založení pokladny
 *   GET  /api/cash-registers/{id}/book        — pokladní kniha (?year=&month=)
 *   GET  /api/cash-registers/{id}/book/pdf    — tisk knihy
 *   POST /api/cash-registers/{id}/inventory   — inventarizace (zjištěný stav,
 *        rozdíl, volitelně vypořádací PPD/VPD na přebytek/manko)
 *
 * Kniha: počáteční zůstatek (Σ před obdobím), chronologické pohyby s průběžným
 * zůstatkem, konečný zůstatek. Zůstatek NIKDY nejde editovat přímo (Dokument 6,
 * VW10 zákaz) — je vždy jen součtem PPD a VPD; rozdíl řeší vypořádací doklad.
 */
final class CashRegisterAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly CashBookPdfRenderer $pdf,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $sid = $this->sid($request);
        $stmt = $this->db->pdo()->prepare(
            "SELECT cr.id, cr.name, cr.currency, cr.is_default, cr.is_archived,
                    COALESCE(SUM(CASE WHEN cd.kind = 'income' THEN cd.amount
                                      WHEN cd.kind = 'expense' THEN -cd.amount END), 0) AS balance
               FROM cash_registers cr
          LEFT JOIN cash_documents cd ON cd.cash_register_id = cr.id
              WHERE cr.supplier_id = ?
           GROUP BY cr.id
           ORDER BY cr.is_default DESC, cr.name"
        );
        $stmt->execute([$sid]);
        $rows = array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['is_default'] = (bool) $r['is_default'];
            $r['is_archived'] = (bool) $r['is_archived'];
            $r['balance'] = round((float) $r['balance'], 2);
            return $r;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
        return Json::ok($response, $rows);
    }

    public function create(Request $request, Response $response): Response
    {
        $sid = $this->sid($request);
        $b = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            return Json::error($response, 'validation_failed', 'Název pokladny je povinný (max 120 znaků).', 400);
        }
        $currency = strtoupper(trim((string) ($b['currency'] ?? 'CZK')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return Json::error($response, 'validation_failed', 'Neplatná měna.', 400);
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO cash_registers (supplier_id, name, currency, is_default) VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$sid, $name, $currency]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->log($request, 'cash_register.created', $id, ['name' => $name, 'currency' => $currency]);
        return Json::ok($response, ['id' => $id, 'name' => $name, 'currency' => $currency], 201);
    }

    public function book(Request $request, Response $response, array $args): Response
    {
        $sid = $this->sid($request);
        $register = $this->register($sid, (int) ($args['id'] ?? 0));
        if ($register === null) return Json::error($response, 'not_found', 'Pokladna nenalezena.', 404);

        [$from, $to] = $this->period($request);
        return Json::ok($response, $this->buildBook($register, $from, $to));
    }

    public function bookPdf(Request $request, Response $response, array $args): Response
    {
        $sid = $this->sid($request);
        $register = $this->register($sid, (int) ($args['id'] ?? 0));
        if ($register === null) return Json::error($response, 'not_found', 'Pokladna nenalezena.', 404);

        [$from, $to] = $this->period($request);
        $book = $this->buildBook($register, $from, $to);

        $supplier = $this->db->pdo()->prepare(
            'SELECT company_name, street, city, zip, ic, dic FROM supplier WHERE id = ?'
        );
        $supplier->execute([$sid]);

        $bytes = $this->pdf->render($book, (array) $supplier->fetch(\PDO::FETCH_ASSOC));
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="pokladni-kniha-' . $from . '-' . $to . '.pdf"');
    }

    public function inventory(Request $request, Response $response, array $args): Response
    {
        $sid = $this->sid($request);
        $register = $this->register($sid, (int) ($args['id'] ?? 0));
        if ($register === null) return Json::error($response, 'not_found', 'Pokladna nenalezena.', 404);

        $b = (array) ($request->getParsedBody() ?? []);
        $date = (string) ($b['inventory_date'] ?? (new \DateTimeImmutable('today'))->format('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Json::error($response, 'validation_failed', 'Neplatné datum inventarizace.', 400);
        }
        if (!is_numeric($b['actual_amount'] ?? null)) {
            return Json::error($response, 'validation_failed', 'Zjištěný stav je povinný.', 400);
        }
        $actual = round((float) $b['actual_amount'], 2);
        $expected = $this->balanceAsOf((int) $register['id'], $date);
        $difference = round($actual - $expected, 2);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $settlementId = null;
            // Vypořádání ROZDÍLU výhradně dokladem (zůstatek nelze „srovnat" přímo):
            // přebytek → PPD (výnos), manko → VPD (typicky předpis k úhradě).
            if (!empty($b['create_settlement']) && abs($difference) >= 0.01) {
                $kind = $difference > 0 ? 'income' : 'expense';
                $number = $this->nextNumber($sid, (int) $register['id'], $kind, (int) substr($date, 0, 4));
                $desc = ($difference > 0
                        ? 'Inventarizační přebytek pokladny k '
                        : 'Inventarizační manko pokladny k ') . $date;
                $stmt = $pdo->prepare(
                    'INSERT INTO cash_documents
                        (supplier_id, cash_register_id, kind, number, issue_date, accounting_date,
                         amount, currency, counterparty, description, issued_by, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $sid, (int) $register['id'], $kind, $number, $date, $date,
                    abs($difference), (string) $register['currency'],
                    (string) ($user['name'] ?? ''), $desc,
                    (string) ($user['name'] ?? ''), (int) ($user['id'] ?? 0),
                ]);
                $settlementId = (int) $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare(
                'INSERT INTO cash_register_inventories
                    (supplier_id, cash_register_id, inventory_date, expected_amount, actual_amount,
                     difference, settlement_document_id, note, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $sid, (int) $register['id'], $date, $expected, $actual, $difference,
                $settlementId, trim((string) ($b['note'] ?? '')) ?: null, (int) ($user['id'] ?? 0),
            ]);
            $inventoryId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->log($request, 'cash_register.inventory', (int) $register['id'], [
            'inventory_id' => $inventoryId, 'expected' => $expected, 'actual' => $actual,
            'difference' => $difference, 'settlement_document_id' => $settlementId,
        ]);
        return Json::ok($response, [
            'id' => $inventoryId,
            'inventory_date' => $date,
            'expected_amount' => $expected,
            'actual_amount' => $actual,
            'difference' => $difference,
            'settlement_document_id' => $settlementId,
        ], 201);
    }

    // ── interní ────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function register(int $sid, int $id): ?array
    {
        if ($id <= 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, currency FROM cash_registers WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array{0:string,1:string} [from, to] včetně */
    private function period(Request $request): array
    {
        $q = $request->getQueryParams();
        $year = ctype_digit((string) ($q['year'] ?? '')) ? (int) $q['year'] : (int) date('Y');
        $month = ctype_digit((string) ($q['month'] ?? '')) ? max(1, min(12, (int) $q['month'])) : null;
        if ($month !== null) {
            $from = sprintf('%04d-%02d-01', $year, $month);
            $to = (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
        } else {
            $from = sprintf('%04d-01-01', $year);
            $to = sprintf('%04d-12-31', $year);
        }
        return [$from, $to];
    }

    /** @param array<string,mixed> $register */
    private function buildBook(array $register, string $from, string $to): array
    {
        $registerId = (int) $register['id'];
        $opening = $this->balanceBefore($registerId, $from);

        $stmt = $this->db->pdo()->prepare(
            "SELECT cd.id, cd.kind, cd.number, cd.issue_date, COALESCE(cd.accounting_date, cd.issue_date) AS accounting_date,
                    cd.amount, cd.currency, cd.counterparty, cd.description, cd.status, cd.storno_of_id,
                    i.varsymbol AS invoice_varsymbol, pi.varsymbol AS purchase_varsymbol
               FROM cash_documents cd
          LEFT JOIN invoices i ON i.id = cd.invoice_id
          LEFT JOIN purchase_invoices pi ON pi.id = cd.purchase_invoice_id
              WHERE cd.cash_register_id = ?
                AND COALESCE(cd.accounting_date, cd.issue_date) BETWEEN ? AND ?
           ORDER BY COALESCE(cd.accounting_date, cd.issue_date), cd.id"
        );
        $stmt->execute([$registerId, $from, $to]);

        $running = $opening;
        $incomeTotal = 0.0;
        $expenseTotal = 0.0;
        $entries = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $signed = $r['kind'] === 'income' ? (float) $r['amount'] : -(float) $r['amount'];
            $running = round($running + $signed, 2);
            if ($signed >= 0) $incomeTotal += $signed;
            else $expenseTotal += -$signed;
            $r['id'] = (int) $r['id'];
            $r['amount'] = (float) $r['amount'];
            $r['signed_amount'] = round($signed, 2);
            $r['running_balance'] = $running;
            $r['storno_of_id'] = $r['storno_of_id'] !== null ? (int) $r['storno_of_id'] : null;
            $entries[] = $r;
        }

        return [
            'register'        => ['id' => $registerId, 'name' => $register['name'], 'currency' => $register['currency']],
            'from'            => $from,
            'to'              => $to,
            'opening_balance' => $opening,
            'income_total'    => round($incomeTotal, 2),
            'expense_total'   => round($expenseTotal, 2),
            'closing_balance' => $running,
            'entries'         => $entries,
        ];
    }

    private function balanceBefore(int $registerId, string $date): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN kind = 'income' THEN amount ELSE -amount END), 0)
               FROM cash_documents
              WHERE cash_register_id = ? AND COALESCE(accounting_date, issue_date) < ?"
        );
        $stmt->execute([$registerId, $date]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function balanceAsOf(int $registerId, string $date): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN kind = 'income' THEN amount ELSE -amount END), 0)
               FROM cash_documents
              WHERE cash_register_id = ? AND COALESCE(accounting_date, issue_date) <= ?"
        );
        $stmt->execute([$registerId, $date]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** Shodná řada jako CashDocumentAction::nextNumber (per pokladna, druh, rok). */
    private function nextNumber(int $sid, int $registerId, string $kind, int $year): string
    {
        $prefix = ($kind === 'income' ? 'PPD' : 'VPD') . '-' . $year . '-';
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(number, '-', -1) AS UNSIGNED))
               FROM cash_documents
              WHERE supplier_id = ? AND cash_register_id = ? AND kind = ? AND number LIKE ?
                FOR UPDATE"
        );
        $stmt->execute([$sid, $registerId, $kind, $prefix . '%']);
        $next = (int) $stmt->fetchColumn() + 1;
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function sid(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0), 'cash_register', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
