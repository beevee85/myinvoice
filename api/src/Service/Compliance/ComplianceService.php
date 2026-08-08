<?php

declare(strict_types=1);

namespace MyInvoice\Service\Compliance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ComplianceFlagRepository;
use MyInvoice\Service\Legal\LegalConstants;
use PDO;

/**
 * FORK 0925 — detekce hotovostních rizik (Dokumenty 4, 5, 6, 9).
 *
 * Pravidla:
 *  - VB3a (BLOK): hotovostní úhrada s DNEŠNÍM/BUDOUCÍM datem, která by se
 *    stejnou protistranou v témž kalendářním dni překročila limit ZOPH
 *    (§ 4 odst. 1 a 4; limit z LegalConstants K DATU PLATBY — Dokument 9).
 *    Zákaz dopadá i na příjemce (§ 4 odst. 2).
 *  - VB3b (WARN + POTVRZENÍ): táž situace se ZPĚTNÝM datem — záznam minulé
 *    skutečnosti se nikdy neblokuje (Dokument 5, pravidlo 2); vyžaduje volbu
 *    uživatele a nechává trvalý příznak HOTOVOST_NAD_LIMIT.
 *  - VW-AML1 (WARN + POTVRZENÍ): strukturování — součet hotovosti s toutéž
 *    protistranou v okně AML_STRUCTURING_WINDOW_DAYS překračuje limit, ač
 *    žádná jednotlivá platba limit nepřekročila; NEBO součet hotovostních
 *    úhrad TÉHOŽ dokladu překračuje limit (§ 6 odst. 1 písm. b) AML zákona).
 *
 * ABSOLUTNÍ ZÁKAZ (Dokument 4 §2, potvrzeno Dok. 5/6): služba nikdy nevrací
 * ani nepočítá „kolik ještě lze přijmout do limitu" — jen detekuje a hlásí.
 *
 * Flow vynuceného rozhodnutí: checkCashPayment() vrátí požadavky; akce bez
 * `compliance_ack` vrací 409 `compliance_ack_required` s podklady pro modal;
 * s platným ack se operace provede a příznaky se založí s volbou uživatele.
 */
final class ComplianceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ComplianceFlagRepository $flags,
        private readonly LegalConstants $legal,
    ) {}

    /**
     * Vyhodnotí hotovostní úhradu PŘED uložením.
     *
     * @param array{subject_type:string, subject_id:int, doc_number:?string,
     *              project_id?:?int} $subject
     * @return array{block: ?array, checks: list<array<string,mixed>>}
     *         block  = tvrdá blokace (VB3a) — akce vrací 409 bez možnosti ack,
     *         checks = rizika vyžadující volbu uživatele (VB3b / VW-AML1).
     */
    public function checkCashPayment(int $supplierId, ?int $clientId, string $paidOn, float $amount, array $subject): array
    {
        if (!$this->cashChecksEnabled($supplierId)) {
            return ['block' => null, 'checks' => []];
        }
        $limitInfo = $this->legal->cashPaymentLimit(new \DateTimeImmutable($paidOn));
        // Limit v EUR (po AMLR) nebo neurčený (staré doklady) — bez CZK limitu
        // se kontroly neprovádí (E9; EUR přepočet doplní pozdější iterace).
        if ($limitInfo['currency'] !== 'CZK') {
            return ['block' => null, 'checks' => []];
        }
        $limit = (float) $limitInfo['amount'];
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $dayTotal = $clientId !== null
            ? $this->cashSumForCounterparty($supplierId, $clientId, $paidOn, $paidOn) + $amount
            : $amount;

        // ── VB3a / VB3b: součet za kalendářní den ───────────────────────────
        if ($dayTotal > $limit) {
            if ($paidOn >= $today) {
                return ['block' => [
                    'code'    => 'cash_over_limit',
                    'message' => sprintf(
                        'Tuto úhradu nelze provést v hotovosti: součet hotovostních plateb s toutéž protistranou '
                        . 'za den %s by činil %s Kč a přesáhl limit %s Kč (§ 4 odst. 1 a 4 zákona č. 254/2004 Sb.). '
                        . 'Zákaz dopadá i na příjemce platby (§ 4 odst. 2) — pokuta pro právnickou osobu je až %s Kč. '
                        . 'Použijte bankovní převod.',
                        $paidOn,
                        self::czk($dayTotal),
                        self::czk($limit),
                        self::czk((float) $this->legal->value('CASH_LIMIT_FINE_COMPANY_CZK'))
                    ),
                ], 'checks' => []];
            }
            return ['block' => null, 'checks' => [[
                'type'     => 'HOTOVOST_NAD_LIMIT',
                'rule'     => 'VB3b',
                'title'    => 'Hotovostní platba nad zákonný limit',
                'message'  => sprintf(
                    'Zaznamenáváte skutečnost, která už nastala: hotovost %s Kč dne %s — součet s toutéž '
                    . 'protistranou v tomto dni činí %s Kč a přesahuje limit %s Kč. Aplikace zápis nebrání '
                    . '(zápis nepravdivého údaje by byl horší), skutečnost ale zůstane trvale označená.',
                    self::czk($amount), $paidOn, self::czk($dayTotal), self::czk($limit)
                ),
                'legal_reference' => '§ 4 odst. 1, 2 a 4 zákona č. 254/2004 Sb.',
                'choices' => [
                    ['value' => 'acknowledge', 'note_required' => false],
                    ['value' => 'accept_risk', 'note_required' => true, 'note_min' => 10],
                ],
                'context' => ['amount' => $amount, 'day_total' => round($dayTotal, 2), 'limit' => $limit, 'date' => $paidOn],
            ]]];
        }

        // ── VW-AML1: strukturování ──────────────────────────────────────────
        $checks = [];
        $structuring = $this->detectStructuring($supplierId, $clientId, $paidOn, $amount, $subject, $limit);
        if ($structuring !== null) {
            $checks[] = $structuring;
        }
        return ['block' => null, 'checks' => $checks];
    }

    /**
     * Zpracuje volbu uživatele (compliance_ack z requestu) proti požadovaným
     * checks: validuje, založí trvalé příznaky s odbavením a případný AmlCase.
     *
     * @param list<array<string,mixed>> $checks výstup checkCashPayment()['checks']
     * @param array<string, array{choice?:string, note?:string}> $ack klíč = type
     * @return ?string chybová hláška (volba chybí/neplatná), null = OK
     */
    public function applyAcknowledgements(
        int $supplierId,
        array $checks,
        array $ack,
        array $subject,
        ?int $clientId,
        int $userId,
        string $userRole,
    ): ?string {
        foreach ($checks as $check) {
            $type = (string) $check['type'];
            $userChoice = $ack[$type] ?? null;
            $choice = (string) ($userChoice['choice'] ?? '');
            $note = trim((string) ($userChoice['note'] ?? ''));
            $allowed = array_column($check['choices'], null, 'value');
            if (!isset($allowed[$choice])) {
                return 'U rizika ' . $type . ' je nutné zvolit, jak pokračovat.';
            }
            $noteMin = (int) ($allowed[$choice]['note_min'] ?? 0);
            if (!empty($allowed[$choice]['note_required']) && mb_strlen($note) < max(1, $noteMin)) {
                return sprintf('Volba u rizika %s vyžaduje zdůvodnění (alespoň %d znaků).', $type, max(1, $noteMin));
            }

            $flagId = $this->flags->create($supplierId, [
                'type'         => $type,
                'subject_type' => (string) $subject['subject_type'],
                'subject_id'   => (int) $subject['subject_id'],
                'project_id'   => $subject['project_id'] ?? null,
                'client_id'    => $clientId,
                'message'      => (string) $check['message'],
                'context'      => (array) ($check['context'] ?? []),
                'legal_reference' => $check['legal_reference'] ?? null,
            ]);
            // Odbavení volbou přímo při uložení (modal) — příznak zůstává trvale,
            // jen nese kdo/kdy/jak (Dokument 5: odklik příznak neskrývá).
            $status = $choice === 'not_suspicious' ? 'explained' : 'acknowledged';
            $this->flags->acknowledge($supplierId, $flagId, $userId, $userRole, $choice, $note !== '' ? $note : null, $status);

            if ($type === 'AML_STRUKTUROVANI') {
                $this->createAmlCase($supplierId, $clientId, $check, $choice, $note, $userId);
            }
        }
        return null;
    }

    // ── interní ────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null VW-AML1 check payload */
    private function detectStructuring(int $supplierId, ?int $clientId, string $paidOn, float $amount, array $subject, float $limit): ?array
    {
        $windowDays = (int) ($this->legal->valueAt('AML_STRUCTURING_WINDOW_DAYS', new \DateTimeImmutable($paidOn)) ?? 3);
        $from = (new \DateTimeImmutable($paidOn))->modify('-' . max(0, $windowDays - 1) . ' days')->format('Y-m-d');

        $windowPayments = $clientId !== null
            ? $this->cashPaymentsForCounterparty($supplierId, $clientId, $from, $paidOn)
            : [];
        $windowSum = array_sum(array_column($windowPayments, 'amount')) + $amount;
        $maxSingle = max($amount, ...array_merge([0.0], array_column($windowPayments, 'amount')));

        // Nezávisle: součet hotovostních úhrad TÉHOŽ dokladu (bez časového okna).
        $docSum = $amount;
        if ($subject['subject_type'] === 'invoice') {
            $stmt = $this->db->pdo()->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM invoice_payments
                  WHERE invoice_id = ? AND payment_method = 'cash'"
            );
            $stmt->execute([(int) $subject['subject_id']]);
            $docSum += (float) $stmt->fetchColumn();
        }

        $triggered = ($windowSum > $limit && $maxSingle <= $limit && count($windowPayments) > 0)
            || ($docSum > $limit && $amount <= $limit);
        if (!$triggered) {
            return null;
        }

        $lines = array_map(static fn (array $p): string =>
            sprintf('%s   %s Kč', $p['date'], self::czk($p['amount'])), $windowPayments);
        $lines[] = sprintf('%s   %s Kč (tato úhrada)', $paidOn, self::czk($amount));

        return [
            'type'  => 'AML_STRUKTUROVANI',
            'rule'  => 'VW-AML1',
            'title' => 'Rozdělená hotovostní platba – zvýšené riziko',
            'message' => sprintf(
                'Hotovostní úhrady od téže protistrany v %d dnech: %s — celkem %s Kč. Jednotlivé platby limit '
                . '%s Kč nepřekračují, součet ano. Rozdělení úhrady do plateb v bezprostředně následujících dnech '
                . 'uvádí § 6 odst. 1 písm. b) zákona č. 253/2008 Sb. jako znak podezřelého obchodu; jako obchodník '
                . 's vozidly jste povinnou osobou (§ 2) s oznamovací povinností podle § 18 odst. 1. Aplikace '
                . 'nehodnotí, zda podezřelý obchod nastal — jen zajišťuje, aby skutečnost nezůstala nezaznamenaná.',
                $windowDays,
                implode('; ', $lines),
                self::czk(max($windowSum, $docSum)),
                self::czk($limit)
            ),
            'legal_reference' => '§ 6 odst. 1 písm. b) zák. č. 253/2008 Sb.',
            'choices' => [
                ['value' => 'acknowledge',    'note_required' => false],
                ['value' => 'not_suspicious', 'note_required' => true, 'note_min' => 50],
                ['value' => 'escalate',       'note_required' => false],
            ],
            'context' => [
                'window_days' => $windowDays,
                'payments'    => array_merge($windowPayments, [['source' => 'new', 'date' => $paidOn, 'amount' => $amount]]),
                'window_sum'  => round($windowSum, 2),
                'doc_sum'     => round($docSum, 2),
                'limit'       => $limit,
            ],
        ];
    }

    /**
     * Hotovostní platby s protistranou v rozmezí dat (evidence plateb + hlavičky
     * přijatých + samostatné pokladní doklady bez vazby na fakturu — ty s vazbou
     * by se počítaly dvakrát).
     *
     * @return list<array{source:string, id:int, date:string, amount:float}>
     */
    private function cashPaymentsForCounterparty(int $supplierId, int $clientId, string $from, string $to): array
    {
        $pdo = $this->db->pdo();
        $out = [];
        $stmt = $pdo->prepare(
            "SELECT p.id, p.paid_on AS date, p.amount
               FROM invoice_payments p
               JOIN invoices i ON i.id = p.invoice_id
              WHERE p.supplier_id = ? AND i.client_id = ? AND p.payment_method = 'cash'
                AND p.paid_on BETWEEN ? AND ?"
        );
        $stmt->execute([$supplierId, $clientId, $from, $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['source' => 'invoice_payment', 'id' => (int) $r['id'], 'date' => (string) $r['date'], 'amount' => (float) $r['amount']];
        }

        $stmt = $pdo->prepare(
            "SELECT id, paid_at AS date, (amount_to_pay + COALESCE(rounding, 0)) AS amount
               FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ? AND payment_method = 'cash'
                AND deleted_at IS NULL AND paid_at BETWEEN ? AND ?"
        );
        $stmt->execute([$supplierId, $clientId, $from, $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['source' => 'purchase_invoice', 'id' => (int) $r['id'], 'date' => (string) $r['date'], 'amount' => (float) $r['amount']];
        }

        $stmt = $pdo->prepare(
            "SELECT id, COALESCE(accounting_date, issue_date) AS date, ABS(amount) AS amount
               FROM cash_documents
              WHERE supplier_id = ? AND counterparty_client_id = ? AND status = 'active'
                AND invoice_id IS NULL AND purchase_invoice_id IS NULL
                AND COALESCE(accounting_date, issue_date) BETWEEN ? AND ?"
        );
        $stmt->execute([$supplierId, $clientId, $from, $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['source' => 'cash_document', 'id' => (int) $r['id'], 'date' => (string) $r['date'], 'amount' => (float) $r['amount']];
        }
        usort($out, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        return $out;
    }

    private function cashSumForCounterparty(int $supplierId, int $clientId, string $from, string $to): float
    {
        return round(array_sum(array_column(
            $this->cashPaymentsForCounterparty($supplierId, $clientId, $from, $to), 'amount')), 2);
    }

    private function createAmlCase(int $supplierId, ?int $clientId, array $check, string $choice, string $note, int $userId): void
    {
        $status = match ($choice) {
            'not_suspicious' => 'assessed_not_suspicious',
            default          => 'open',
        };
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO aml_cases
                (supplier_id, trigger_type, counterparty_id, related_payments, total_cash_amount,
                 status, assessed_by, assessed_at, reasoning)
             VALUES (?, "structuring", ?, ?, ?, ?, ?, ?, ?)'
        );
        $context = (array) ($check['context'] ?? []);
        $assessed = $status === 'assessed_not_suspicious';
        $stmt->execute([
            $supplierId,
            $clientId,
            json_encode($context['payments'] ?? [], JSON_UNESCAPED_UNICODE),
            (float) max($context['window_sum'] ?? 0, $context['doc_sum'] ?? 0),
            $status,
            $assessed ? $userId : null,
            $assessed ? (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s') : null,
            $note !== '' ? $note : null,
        ]);
    }

    private function cashChecksEnabled(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT accepts_cash_payments FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $v = $stmt->fetchColumn();
        // Vypnutí = firma hotovost vůbec nepřijímá → celý blok kontrol se skryje
        // (Dokument 5 §4.3). Default zapnuto.
        return $v === false || (bool) $v;
    }

    private static function czk(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }
}
