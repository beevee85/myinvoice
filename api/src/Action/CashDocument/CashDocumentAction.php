<?php

declare(strict_types=1);

namespace MyInvoice\Action\CashDocument;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Legal\LegalConstants;
use MyInvoice\Service\Pdf\CashDocumentPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FORK (beevee85): pokladní doklady (PPD/VPD) — v2 (0924, Doplněk H3/H7 + Dokumenty 5/6).
 *
 *   GET    /api/cash-documents                — list (filtr year, kind, register, status)
 *   POST   /api/cash-documents                — create (číslo přidělí server per pokladna+druh+rok)
 *   GET    /api/cash-documents/{id}           — detail
 *   PUT    /api/cash-documents/{id}           — update (číslo, druh, částka a datum se nemění)
 *   POST   /api/cash-documents/{id}/storno    — storno protidokladem (H7)
 *   DELETE /api/cash-documents/{id}           — VŽDY 409 (H7: mazání se nahrazuje stornem)
 *   GET    /api/cash-documents/{id}/pdf       — tisk PDF
 *
 * Pravidla H3 (rozhodnutí is_tax_document):
 *  - doklad hradící fakturu NENÍ daňový doklad a NESMÍ nést rozpis DPH (VB2 —
 *    dvojí vykázání daně; daň už přiznala faktura),
 *  - PPD za hotovostní prodej bez faktury smí být zjednodušený daňový doklad
 *    (§ 30a ZDPH) jen do limitu vč. daně (VB4, limit z LegalConstants k datu).
 *
 * Záporný zůstatek (VB8 + časové pravidlo Dokumentu 5): NOVÝ výdaj s dnešním či
 * budoucím datem, který by pokladnu dostal pod nulu, se BLOKUJE; zpětný záznam
 * projde vždy (účetnictví zobrazuje skutečnost) a vrací warning — trvalý příznak
 * doplní compliance sprint (ComplianceFlag).
 */
final class CashDocumentAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly CashDocumentPdfRenderer $pdf,
        private readonly LegalConstants $legal,
        private readonly \MyInvoice\Service\Compliance\ComplianceService $compliance,
        private readonly \MyInvoice\Repository\ComplianceFlagRepository $flags,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $sid = $this->sid($request);
        $q = $request->getQueryParams();
        $where = ['cd.supplier_id = ?'];
        $params = [$sid];
        if (ctype_digit((string) ($q['year'] ?? ''))) {
            $where[] = 'YEAR(cd.issue_date) = ?';
            $params[] = (int) $q['year'];
        }
        if (in_array($q['kind'] ?? '', ['income', 'expense'], true)) {
            $where[] = 'cd.kind = ?';
            $params[] = $q['kind'];
        }
        if (ctype_digit((string) ($q['register'] ?? ''))) {
            $where[] = 'cd.cash_register_id = ?';
            $params[] = (int) $q['register'];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT cd.*, i.varsymbol AS invoice_varsymbol, pi.varsymbol AS purchase_varsymbol,
                    cr.name AS register_name, sd.number AS storno_of_number
               FROM cash_documents cd
               LEFT JOIN invoices i ON i.id = cd.invoice_id
               LEFT JOIN purchase_invoices pi ON pi.id = cd.purchase_invoice_id
               LEFT JOIN cash_registers cr ON cr.id = cd.cash_register_id
               LEFT JOIN cash_documents sd ON sd.id = cd.storno_of_id
              WHERE ' . implode(' AND ', $where) . '
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
        $accountingDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['accounting_date'] ?? ''))
            ? (string) $b['accounting_date'] : $issueDate;

        $register = $this->resolveRegister($sid, $b['cash_register_id'] ?? null);
        if ($register === null) {
            return Json::error($response, 'register_not_found', 'Pokladna nenalezena.', 400);
        }

        // H3/VB2 — doklad hradící fakturu nesmí nést rozpis DPH (dvojí vykázání daně).
        $isTaxDocument = !empty($b['is_tax_document']);
        $linkedInvoice = $this->linkedId($b, 'invoice_id');
        $linkedPurchase = $this->linkedId($b, 'purchase_invoice_id');
        if ($isTaxDocument && ($linkedInvoice !== null || $linkedPurchase !== null)) {
            return Json::error($response, 'vat_on_payment_doc',
                'Doklad označený jako úhrada faktury není daňovým dokladem a nesmí obsahovat rozpis DPH — '
                . 'daň z plnění už byla přiznána na faktuře (§ 20a, § 28 ZDPH). '
                . 'Uveďte jen hrazenou částku a odkaz na fakturu.', 409);
        }
        $vatBreakdown = null;
        if ($isTaxDocument) {
            if ($kind !== 'income') {
                return Json::error($response, 'vat_on_expense',
                    'Zjednodušeným daňovým dokladem může být jen příjmový doklad (prodej za hotové).', 409);
            }
            $vatBreakdown = $this->normalizeBreakdown($b['vat_breakdown'] ?? null, (float) $b['amount']);
            if (is_string($vatBreakdown)) {
                return Json::error($response, 'invalid_vat_breakdown', $vatBreakdown, 400);
            }
            // VB4 — § 30 odst. 1 ZDPH: zjednodušený daňový doklad jen do limitu vč. daně.
            // Limit se čte k ROZHODNÉMU datu (accounting_date), ne k dnešku (Dokument 9).
            $limit = $this->legal->valueAt('SIMPLIFIED_TAX_DOC_LIMIT_CZK', new \DateTimeImmutable($accountingDate));
            if ($limit !== null && (float) $b['amount'] > (float) $limit) {
                return Json::error($response, 'simplified_doc_over_limit', sprintf(
                    'Zjednodušený daňový doklad nelze použít: částka %s Kč přesahuje %s Kč včetně daně (§ 30 odst. 1 ZDPH). '
                    . 'Vystavte běžný daňový doklad (fakturu) a pokladní doklad jen jako doklad o platbě.',
                    number_format((float) $b['amount'], 2, ',', ' '),
                    number_format((float) $limit, 0, ',', ' ')
                ), 409);
            }
        }

        // VB8 + časové pravidlo (Dokument 5): záporný zůstatek blokuje jen DOPŘEDNÝ výdaj.
        $warnings = [];
        if ($kind === 'expense') {
            $balance = $this->balanceAsOf($register['id'], $accountingDate);
            $after = round($balance - (float) $b['amount'], 2);
            if ($after < 0) {
                $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
                if ($accountingDate >= $today) {
                    return Json::error($response, 'negative_balance', sprintf(
                        'Výdaj přesahuje zůstatek pokladny „%s": zůstatek %s Kč, výdaj %s Kč. '
                        . 'Z pokladny nelze vydat více, než v ní je — záporný zůstatek je při kontrole '
                        . 'považován za důkaz neúplné evidence příjmů. Zkontrolujte, zda nechybí příjmový doklad.',
                        $register['name'],
                        number_format($balance, 2, ',', ' '),
                        number_format((float) $b['amount'], 2, ',', ' ')
                    ), 409);
                }
                // Zpětný záznam: skutečnost se zaznamená, riziko se zviditelní.
                $warnings[] = [
                    'code' => 'negative_balance_backdated',
                    'message' => sprintf(
                        'Zpětně zaevidovaný výdaj dostal pokladnu „%s" k %s do záporu (%s Kč). '
                        . 'Pravděpodobně chybí příjmový pokladní doklad — prověřte doklady k tomuto datu.',
                        $register['name'], $accountingDate, number_format($after, 2, ',', ' ')
                    ),
                ];
            }
        }

        $counterparty = $this->resolveCounterparty($sid, $b);
        $user = $this->user($request);

        // FORK 0925 — hotovost: limit ZOPH + strukturování i pro samostatné
        // pokladní doklady (doklad s vazbou na fakturu řeší platební akce —
        // dvojí kontrola by tutéž platbu počítala dvakrát).
        if ($counterparty['client_id'] !== null && $linkedInvoice === null && $linkedPurchase === null) {
            $check = $this->compliance->checkCashPayment($sid, $counterparty['client_id'], $accountingDate,
                round((float) $b['amount'], 2),
                ['subject_type' => 'cash_document', 'subject_id' => 0, 'doc_number' => null]);
            if ($check['block'] !== null) {
                return Json::error($response, $check['block']['code'], $check['block']['message'], 409);
            }
            if ($check['checks'] !== []) {
                $ack = (array) ($b['compliance_ack'] ?? []);
                if ($ack === []) {
                    return Json::error($response, 'compliance_ack_required',
                        'Doklad vyžaduje rozhodnutí — viz zjištěná rizika.', 409,
                        ['checks' => $check['checks']]);
                }
                // subject_id doplníme po INSERTu (viz níže) — ack se validuje teď.
                $ackChecks = $check['checks'];
                $ackData = $ack;
            }
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $number = $this->nextNumber($sid, (int) $register['id'], $kind, (int) substr($issueDate, 0, 4));
            $stmt = $pdo->prepare(
                'INSERT INTO cash_documents
                    (supplier_id, cash_register_id, kind, number, issue_date, accounting_date, amount, currency,
                     counterparty, counterparty_client_id, counterparty_ico, counterparty_address,
                     description, is_tax_document, vat_breakdown,
                     issued_by, received_by, approved_by, note,
                     invoice_id, purchase_invoice_id, project_id, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $sid, (int) $register['id'], $kind, $number, $issueDate, $accountingDate,
                round((float) $b['amount'], 2),
                strtoupper(trim((string) ($b['currency'] ?? $register['currency']))),
                $counterparty['name'],
                $counterparty['client_id'],
                $counterparty['ico'],
                $counterparty['address'],
                trim((string) ($b['description'] ?? '')),
                $isTaxDocument ? 1 : 0,
                $vatBreakdown !== null ? json_encode($vatBreakdown, JSON_UNESCAPED_UNICODE) : null,
                trim((string) ($b['issued_by'] ?? $user['name'])) ?: $user['name'],
                $this->nullableStr($b['received_by'] ?? null),
                $this->nullableStr($b['approved_by'] ?? null),
                $this->nullableStr($b['note'] ?? null),
                $linkedInvoice,
                $linkedPurchase,
                $this->linkedId($b, 'project_id'),
                (int) $user['id'],
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        // FORK 0925 — trvalé příznaky s volbou uživatele (subject_id až po INSERTu).
        if (isset($ackChecks, $ackData)) {
            $err = $this->compliance->applyAcknowledgements(
                $sid, $ackChecks, $ackData,
                ['subject_type' => 'cash_document', 'subject_id' => $id, 'doc_number' => $number],
                $counterparty['client_id'], $user['id'], (string) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['role'] ?? ''));
            if ($err !== null) {
                // Ack nevalidní — doklad už existuje (minulá skutečnost se nemaže);
                // založí se OTEVŘENÝ příznak a chyba se vrátí ve warnings.
                foreach ($ackChecks as $c) {
                    $this->flags->create($sid, [
                        'type' => (string) $c['type'],
                        'subject_type' => 'cash_document', 'subject_id' => $id,
                        'client_id' => $counterparty['client_id'],
                        'message' => (string) $c['message'],
                        'context' => (array) ($c['context'] ?? []),
                    ]);
                }
                $warnings[] = ['code' => 'compliance_ack_invalid', 'message' => $err];
            }
        }

        $this->log($request, 'cash_document.created', $id, ['number' => $number, 'kind' => $kind]);
        $out = $this->fetch($sid, $id);
        if ($warnings !== []) {
            $out['warnings'] = $warnings;
        }
        return Json::ok($response, $out, 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $sid = $this->sid($request);
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetch($sid, $id);
        if (!$row) return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        // H7: stornovaný doklad ani storno protidoklad se nemění.
        if (($row['status'] ?? 'active') !== 'active' || $row['storno_of_id'] !== null) {
            return Json::error($response, 'immutable', 'Stornovaný doklad nelze upravovat.', 409);
        }

        $b = (array) ($request->getParsedBody() ?? []);
        // číslo, druh, částka, data a vazby se po vystavení nemění; editovatelný je popisný obsah
        $b += ['kind' => $row['kind'], 'issue_date' => $row['issue_date'], 'amount' => $row['amount']];
        $err = $this->validate($b);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);

        $counterparty = $this->resolveCounterparty($sid, $b + [
            'counterparty' => $b['counterparty'] ?? $row['counterparty'],
            'counterparty_client_id' => $b['counterparty_client_id'] ?? $row['counterparty_client_id'],
        ]);

        $stmt = $this->db->pdo()->prepare(
            'UPDATE cash_documents
                SET counterparty = ?, counterparty_client_id = ?, counterparty_ico = ?, counterparty_address = ?,
                    description = ?, received_by = ?, approved_by = ?, note = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            $counterparty['name'],
            $counterparty['client_id'],
            $counterparty['ico'],
            $counterparty['address'],
            trim((string) ($b['description'] ?? $row['description'])),
            $this->nullableStr($b['received_by'] ?? $row['received_by']),
            $this->nullableStr($b['approved_by'] ?? $row['approved_by']),
            $this->nullableStr($b['note'] ?? $row['note']),
            $id, $sid,
        ]);
        $this->log($request, 'cash_document.updated', $id, ['fields' => array_keys($b)]);
        return Json::ok($response, $this->fetch($sid, $id));
    }

    /**
     * H7 — storno protidokladem: nový doklad TÉŽE řady se zápornou částkou
     * a vazbou storno_of_id; originál zůstává viditelný se stavem `storno`.
     * Číselná řada zůstává souvislá, nic se nemaže.
     */
    public function storno(Request $request, Response $response, array $args): Response
    {
        $sid = $this->sid($request);
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetch($sid, $id);
        if (!$row) return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        if (($row['status'] ?? 'active') !== 'active') {
            return Json::error($response, 'already_storno', 'Doklad už je stornovaný.', 409);
        }
        if ($row['storno_of_id'] !== null || (float) $row['amount'] < 0) {
            return Json::error($response, 'storno_of_storno', 'Storno protidoklad nelze stornovat.', 409);
        }
        $b = (array) ($request->getParsedBody() ?? []);
        $reason = trim((string) ($b['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            return Json::error($response, 'reason_required', 'Uveďte důvod storna (alespoň 5 znaků).', 400);
        }

        $user = $this->user($request);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $number = $this->nextNumber($sid, (int) $row['cash_register_id'], (string) $row['kind'], (int) substr($today, 0, 4));
            $stmt = $pdo->prepare(
                'INSERT INTO cash_documents
                    (supplier_id, cash_register_id, kind, number, issue_date, accounting_date, amount, currency,
                     counterparty, counterparty_client_id, counterparty_ico, counterparty_address,
                     description, status, storno_of_id, issued_by,
                     invoice_id, purchase_invoice_id, project_id, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,"active",?,?,?,?,?,?)'
            );
            $stmt->execute([
                $sid, (int) $row['cash_register_id'], (string) $row['kind'], $number, $today, $today,
                round(-(float) $row['amount'], 2),
                (string) $row['currency'],
                (string) $row['counterparty'],
                $row['counterparty_client_id'],
                $row['counterparty_ico'],
                $row['counterparty_address'],
                'Storno dokladu ' . $row['number'] . ': ' . $reason,
                $id,
                $user['name'],
                $row['invoice_id'],
                $row['purchase_invoice_id'],
                $row['project_id'] ?? null,
                (int) $user['id'],
            ]);
            $stornoId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE cash_documents SET status = "storno" WHERE id = ? AND supplier_id = ?')
                ->execute([$id, $sid]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $this->log($request, 'cash_document.storno', $id, [
            'number' => $row['number'], 'storno_document_id' => $stornoId, 'reason' => $reason,
        ]);
        return Json::ok($response, [
            'original' => $this->fetch($sid, $id),
            'storno'   => $this->fetch($sid, $stornoId),
        ], 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        // H7 — pokladní doklad se NIKDY nemaže: číselná řada musí zůstat
        // nepřerušená a chronologická (§ 35/3 ZoÚ). Jediná cesta je storno.
        return Json::error($response, 'delete_forbidden',
            'Pokladní doklad nelze smazat — číselná řada musí zůstat souvislá. Použijte storno (vytvoří protidoklad).', 409);
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

    /** Další číslo řady: PPD-YYYY-0001 / VPD-YYYY-0001 per pokladna, druh a rok. Volat v transakci. */
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

    /** @return array{id:int,name:string,currency:string}|null */
    private function resolveRegister(int $sid, mixed $raw): ?array
    {
        $pdo = $this->db->pdo();
        if ($raw !== null && (int) $raw > 0) {
            $stmt = $pdo->prepare(
                'SELECT id, name, currency FROM cash_registers WHERE id = ? AND supplier_id = ? AND is_archived = 0'
            );
            $stmt->execute([(int) $raw, $sid]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, name, currency FROM cash_registers WHERE supplier_id = ? AND is_archived = 0
                  ORDER BY is_default DESC, id LIMIT 1'
            );
            $stmt->execute([$sid]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'currency' => (string) $row['currency']] : null;
    }

    /** Zůstatek pokladny k datu včetně (Σ příjmů − Σ výdajů; storno nese záporné částky samo). */
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

    /**
     * Protistrana: preferuje vazbu na klienta (autofill název/IČO/adresa z karty),
     * fallback volný text (jednorázové osoby).
     *
     * @return array{name:string, client_id:?int, ico:?string, address:?string}
     */
    private function resolveCounterparty(int $sid, array $b): array
    {
        $clientId = $this->linkedId($b, 'counterparty_client_id');
        if ($clientId !== null) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT company_name, ic, street, city, zip FROM clients WHERE id = ? AND supplier_id = ?'
            );
            $stmt->execute([$clientId, $sid]);
            $c = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($c) {
                $address = trim(implode(', ', array_filter([
                    (string) ($c['street'] ?? ''),
                    trim(((string) ($c['zip'] ?? '')) . ' ' . ((string) ($c['city'] ?? ''))),
                ])));
                return [
                    'name'      => (string) $c['company_name'],
                    'client_id' => $clientId,
                    'ico'       => $this->nullableStr($c['ic'] ?? null),
                    'address'   => $address !== '' ? $address : null,
                ];
            }
        }
        return [
            'name'      => trim((string) ($b['counterparty'] ?? '')),
            'client_id' => null,
            'ico'       => $this->nullableStr($b['counterparty_ico'] ?? null),
            'address'   => $this->nullableStr($b['counterparty_address'] ?? null),
        ];
    }

    /**
     * Rozpad DPH {rate, base, vat}[] — kontrola sazeb proti § 47 k datu neprobíhá
     * zde (řeší editor výběrem), ale součet base+vat musí sedět na částku dokladu.
     *
     * @return list<array{rate: float, base: float, vat: float}>|string
     */
    private function normalizeBreakdown(mixed $raw, float $amount): array|string
    {
        if (!is_array($raw) || $raw === []) {
            return 'Daňový doklad musí obsahovat rozpad DPH (sazba, základ, daň).';
        }
        $out = [];
        $sum = 0.0;
        foreach ($raw as $row) {
            if (!is_array($row) || !isset($row['rate'], $row['base'], $row['vat'])) {
                return 'Neplatný řádek rozpadu DPH.';
            }
            $entry = [
                'rate' => round((float) $row['rate'], 2),
                'base' => round((float) $row['base'], 2),
                'vat'  => round((float) $row['vat'], 2),
            ];
            $sum += $entry['base'] + $entry['vat'];
            $out[] = $entry;
        }
        if (abs(round($sum, 2) - round($amount, 2)) > 0.01) {
            return 'Součet základů a daní rozpadu DPH neodpovídá částce dokladu.';
        }
        return $out;
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
        if (mb_strlen((string) ($b['note'] ?? '')) > 2000) return 'Poznámka je příliš dlouhá.';
        return null;
    }

    /** Volitelná vazba na doklad; musí existovat v rámci supplieru. */
    private function linkedId(array $b, string $field): ?int
    {
        $v = $b[$field] ?? null;
        if ($v === null || $v === '' || (int) $v <= 0) return null;
        return (int) $v;
    }

    private function nullableStr(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : $s;
    }

    private function fetch(int $sid, int $id): ?array
    {
        if ($id <= 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT cd.*, i.varsymbol AS invoice_varsymbol, pi.varsymbol AS purchase_varsymbol,
                    cr.name AS register_name, sd.number AS storno_of_number,
                    (SELECT s2.number FROM cash_documents s2 WHERE s2.storno_of_id = cd.id LIMIT 1) AS storno_by_number
               FROM cash_documents cd
               LEFT JOIN invoices i ON i.id = cd.invoice_id
               LEFT JOIN purchase_invoices pi ON pi.id = cd.purchase_invoice_id
               LEFT JOIN cash_registers cr ON cr.id = cd.cash_register_id
               LEFT JOIN cash_documents sd ON sd.id = cd.storno_of_id
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
        $r['cash_register_id'] = $r['cash_register_id'] !== null ? (int) $r['cash_register_id'] : null;
        $r['amount'] = (float) $r['amount'];
        $r['invoice_id'] = $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null;
        $r['purchase_invoice_id'] = $r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null;
        $r['counterparty_client_id'] = isset($r['counterparty_client_id']) && $r['counterparty_client_id'] !== null
            ? (int) $r['counterparty_client_id'] : null;
        $r['storno_of_id'] = isset($r['storno_of_id']) && $r['storno_of_id'] !== null ? (int) $r['storno_of_id'] : null;
        $r['project_id'] = isset($r['project_id']) && $r['project_id'] !== null ? (int) $r['project_id'] : null;
        $r['is_tax_document'] = !empty($r['is_tax_document']);
        if (array_key_exists('vat_breakdown', $r)) {
            $decoded = is_string($r['vat_breakdown']) && $r['vat_breakdown'] !== ''
                ? json_decode($r['vat_breakdown'], true) : null;
            $r['vat_breakdown'] = is_array($decoded) ? $decoded : null;
        }
        $r['created_by'] = (int) $r['created_by'];
        return $r;
    }

    private function sid(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    /** @return array{id:int, name:string} */
    private function user(Request $request): array
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ['id' => (int) ($user['id'] ?? 0), 'name' => (string) ($user['name'] ?? '')];
    }

    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->user($request)['id'], 'cash_document', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
