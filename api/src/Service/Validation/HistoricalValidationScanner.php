<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * FORK (beevee85) — retrospektivní stínová validace (V76 nad historií).
 *
 * Stínový režim ve `PurchaseInvoiceWriteService` měří jen NOVÉ doklady, takže na
 * rozhodnutí „vynutit validaci i na importní cesty?" by se čekalo týdny — a rozhodovalo
 * by se na hrstce dokladů. Historie je přitom v databázi celá. Tenhle skener nad ní pustí
 * TOTÉŽ pravidlo a dá distribuci nálezů hned.
 *
 * TVRDÁ PRAVIDLA:
 *   - VÝHRADNĚ ČTENÍ. Žádný INSERT ani UPDATE, a záměrně ani zápis do provozní
 *     telemetrie — historická dávka by jinak zašuměla měření nových importů.
 *   - ŽÁDNÁ SÍŤ. Nevolá ARES, registr plátců ani ČNB (V86); pravidla, která je potřebují,
 *     se hlásí jako přeskočená, ne jako splněná.
 *   - Rekonstruuje DTO z uložených dat, tedy validuje přesně to, co v databázi JE.
 *
 * Nálezy se dělí na dvě kategorie, protože znamenají něco úplně jiného:
 *   `legacy_gap`    — údaj se tehdy prostě nesbíral (prázdný popis, chybějící číslo).
 *                     Úklid historie, ne důvod neblokovat budoucí importy.
 *   `real_mismatch` — uložené hodnoty si numericky protiřečí (nulové množství, neznámá
 *                     sazba, kurz mimo rozsah). Tohle je to, co rozhoduje o vynucení.
 */
final class HistoricalValidationScanner
{
    /** Kolik dokladů načíst najednou — ať se nepotká velký objem s pamětí. */
    private const CHUNK = 500;

    public const LEGACY_GAP    = 'legacy_gap';
    public const REAL_MISMATCH = 'real_mismatch';

    /**
     * Zařazení pravidla (normalizovaná cesta k poli) do kategorie.
     *
     * Co není v mapě, spadne do `real_mismatch` — konzervativně, ať se nový typ nálezu
     * neztratí v „to je jen úklid".
     *
     * @var array<string,string>
     */
    private const RULE_CATEGORY = [
        // Údaj, který se tehdy nesbíral / smí být prázdný v starých datech.
        'vendor_invoice_number'   => self::LEGACY_GAP,
        'issue_date'              => self::LEGACY_GAP,
        'due_date'                => self::LEGACY_GAP,
        'tax_date'                => self::LEGACY_GAP,
        'received_at'             => self::LEGACY_GAP,
        'varsymbol'               => self::LEGACY_GAP,
        'document_kind'           => self::LEGACY_GAP,
        'note_above_items'        => self::LEGACY_GAP,
        'note_below_items'        => self::LEGACY_GAP,
        'items.*.description'     => self::LEGACY_GAP,

        // Uložené hodnoty si protiřečí — tohle rozhoduje o vynucení.
        'vendor_id'                    => self::REAL_MISMATCH,
        'currency_id'                  => self::REAL_MISMATCH,
        'items'                        => self::REAL_MISMATCH,
        'items.*.quantity'             => self::REAL_MISMATCH,
        'items.*.unit_price_without_vat' => self::REAL_MISMATCH,
        'items.*.vat_rate_id'          => self::REAL_MISMATCH,
        'exchange_rate'                => self::REAL_MISMATCH,
        'payment_exchange_rate'        => self::REAL_MISMATCH,
        'payment_currency_id'          => self::REAL_MISMATCH,
        'advance_paid_amount'          => self::REAL_MISMATCH,
    ];

    /**
     * Skupiny pravidel z katalogu, které nad historií vyhodnotit NELZE. Hlásí se
     * výslovně — mlčení by se dalo číst jako „prošlo".
     *
     * @var list<array{rules:string, reason:string, note:string}>
     */
    public const SKIPPED_RULE_GROUPS = [
        [
            'rules'  => 'V1–V8, V33–V35',
            'reason' => 'no_source_document',
            'note'   => 'Integrita souboru a QR kód se dají ověřit jen nad původním dokladem; '
                      . 'u historických záznamů je k dispozici nanejvýš archivované PDF, ne dávka.',
        ],
        [
            'rules'  => 'V19–V25, V32, V52',
            'reason' => 'needs_network',
            'note'   => 'ARES, registr plátců DPH, VIES a kurzy ČNB se záměrně nevolají — '
                      . 'skener musí být offline a bez vedlejších efektů.',
        ],
        [
            'rules'  => 'V9–V14, V49',
            'reason' => 'not_applicable',
            'note'   => 'Týkají se struktury odpovědi modelu (schema, confidence, self_check), '
                      . 'kterou historické doklady nemají.',
        ],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param int|null $supplierId omezení na jednoho tenanta (null = všichni)
     * @return array<string,mixed> agregovaný report
     */
    public function scan(?int $supplierId = null): array
    {
        $pdo       = $this->db->pdo();
        $vatRates  = $this->vatRateMap($pdo);

        $report = [
            'scanned'             => 0,
            'passed'              => 0,
            'failed'              => 0,
            'by_category'         => [self::LEGACY_GAP => 0, self::REAL_MISMATCH => 0],
            'by_rule'             => [],
            'by_source'           => [],
            'by_year'             => [],
            'by_document_kind'    => [],
            'skipped_rule_groups' => self::SKIPPED_RULE_GROUPS,
        ];

        $lastId = 0;
        while (true) {
            $invoices = $this->fetchChunk($pdo, $supplierId, $lastId);
            if ($invoices === []) {
                break;
            }
            $items = $this->fetchItems($pdo, array_column($invoices, 'id'));

            foreach ($invoices as $invoice) {
                $lastId = (int) $invoice['id'];
                $this->evaluate($invoice, $items[(int) $invoice['id']] ?? [], $vatRates, $report);
            }
        }

        // Stabilní pořadí — report se ukládá do gitu, nemá se přeskupovat mezi běhy.
        arsort($report['by_rule']);
        ksort($report['by_source']);
        ksort($report['by_year']);
        ksort($report['by_document_kind']);

        return $report;
    }

    /**
     * @param array<string,mixed> $invoice
     * @param list<array<string,mixed>> $itemRows
     * @param array<int,float> $vatRates
     * @param array<string,mixed> $report
     */
    private function evaluate(array $invoice, array $itemRows, array $vatRates, array &$report): void
    {
        $dto    = $this->reconstructDto($invoice, $itemRows);
        $errors = PurchaseInvoiceValidation::invoice($dto, $vatRates);

        $source = self::classifySource($invoice);
        $year   = (int) substr((string) ($invoice['issue_date'] ?? '0000'), 0, 4);
        $kind   = (string) ($invoice['document_kind'] ?? 'invoice');

        $report['scanned']++;
        $report['by_source'][$source]['total']        = ($report['by_source'][$source]['total'] ?? 0) + 1;
        $report['by_year'][$year]['total']            = ($report['by_year'][$year]['total'] ?? 0) + 1;
        $report['by_document_kind'][$kind]['total']   = ($report['by_document_kind'][$kind]['total'] ?? 0) + 1;

        if ($errors === []) {
            $report['passed']++;
            return;
        }

        $report['failed']++;
        $report['by_source'][$source]['failed']      = ($report['by_source'][$source]['failed'] ?? 0) + 1;
        $report['by_year'][$year]['failed']          = ($report['by_year'][$year]['failed'] ?? 0) + 1;
        $report['by_document_kind'][$kind]['failed'] = ($report['by_document_kind'][$kind]['failed'] ?? 0) + 1;

        foreach (self::normalizeRules($errors) as $rule) {
            $report['by_rule'][$rule] = ($report['by_rule'][$rule] ?? 0) + 1;
            $report['by_category'][self::categoryOf($rule)]++;
        }
    }

    /**
     * Rekonstrukce DTO z uložených dat — přesně ta pole, na která se validace dívá.
     *
     * @param array<string,mixed> $invoice
     * @param list<array<string,mixed>> $itemRows
     * @return array<string,mixed>
     */
    public function reconstructDto(array $invoice, array $itemRows): array
    {
        $items = [];
        foreach ($itemRows as $row) {
            $items[] = [
                'description'            => (string) ($row['description'] ?? ''),
                'quantity'               => (float) ($row['quantity'] ?? 0),
                'unit'                   => (string) ($row['unit'] ?? 'ks'),
                'unit_price_without_vat' => (float) ($row['unit_price_without_vat'] ?? 0),
                'vat_rate_id'            => (int) ($row['vat_rate_id'] ?? 0),
                'order_index'            => (int) ($row['order_index'] ?? 0),
            ];
        }

        return [
            'vendor_id'             => (int) ($invoice['vendor_id'] ?? 0),
            'vendor_invoice_number' => (string) ($invoice['vendor_invoice_number'] ?? ''),
            'document_kind'         => (string) ($invoice['document_kind'] ?? 'invoice'),
            'currency_id'           => (int) ($invoice['currency_id'] ?? 0),
            'issue_date'            => (string) ($invoice['issue_date'] ?? ''),
            'due_date'              => (string) ($invoice['due_date'] ?? ''),
            'tax_date'              => $invoice['tax_date'] ?? null,
            'received_at'           => $invoice['received_at'] ?? null,
            'varsymbol'             => $invoice['varsymbol'] ?? null,
            'exchange_rate'         => $invoice['exchange_rate'] ?? null,
            'payment_currency_id'   => $invoice['payment_currency_id'] ?? null,
            'payment_exchange_rate' => $invoice['payment_exchange_rate'] ?? null,
            'advance_paid_amount'   => (float) ($invoice['advance_paid_amount'] ?? 0),
            'note_above_items'      => $invoice['note_above_items'] ?? null,
            'note_below_items'      => $invoice['note_below_items'] ?? null,
            'items'                 => $items,
        ];
    }

    /**
     * Odkud doklad pochází. Zdroj se na dokladu neukládá, odvozuje se ze stop, které
     * jednotlivé cesty zanechávají — stejná heuristika jako SQL v SHADOW-VALIDATION.md.
     * Je přibližná: ruční doklad s nahraným PDF je od jednotlivého AI importu k nerozeznání.
     *
     * @param array<string,mixed> $invoice
     */
    public static function classifySource(array $invoice): string
    {
        return match (true) {
            ($invoice['idoklad_id'] ?? null) !== null       => 'idoklad',
            ($invoice['fakturoid_id'] ?? null) !== null     => 'fakturoid',
            ($invoice['source_format'] ?? null) !== null    => 'isdoc',
            ($invoice['import_batch_id'] ?? null) !== null  => 'ai_pdf',
            str_starts_with((string) ($invoice['vendor_invoice_number'] ?? ''), 'BANK-') => 'banka',
            ($invoice['pdf_path'] ?? null) !== null         => 'ai_pdf_nebo_rucni_s_pdf',
            default                                          => 'rucni',
        };
    }

    /**
     * @param array<string,string[]> $errors
     * @return list<string>
     */
    public static function normalizeRules(array $errors): array
    {
        $rules = array_map(
            static fn (string $field): string => (string) preg_replace('/\.\d+\./', '.*.', $field),
            array_keys($errors),
        );

        return array_values(array_unique($rules));
    }

    public static function categoryOf(string $rule): string
    {
        return self::RULE_CATEGORY[$rule] ?? self::REAL_MISMATCH;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchChunk(PDO $pdo, ?int $supplierId, int $afterId): array
    {
        $sql = 'SELECT id, supplier_id, vendor_id, vendor_invoice_number, document_kind,
                       currency_id, issue_date, due_date, tax_date, received_at, varsymbol,
                       exchange_rate, payment_currency_id, payment_exchange_rate,
                       advance_paid_amount, note_above_items, note_below_items,
                       idoklad_id, fakturoid_id, source_format, import_batch_id, pdf_path
                  FROM purchase_invoices
                 WHERE id > :after AND deleted_at IS NULL';
        if ($supplierId !== null) {
            $sql .= ' AND supplier_id = :supplier';
        }
        $sql .= ' ORDER BY id LIMIT ' . self::CHUNK;

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        if ($supplierId !== null) {
            $stmt->bindValue(':supplier', $supplierId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param list<mixed> $invoiceIds
     * @return array<int, list<array<string,mixed>>>
     */
    private function fetchItems(PDO $pdo, array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $ids = implode(',', array_map('intval', $invoiceIds));

        $rows = $pdo->query(
            "SELECT purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, order_index
               FROM purchase_invoice_items
              WHERE purchase_invoice_id IN ({$ids})
              ORDER BY purchase_invoice_id, order_index, id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byInvoice = [];
        foreach ($rows as $row) {
            $byInvoice[(int) $row['purchase_invoice_id']][] = $row;
        }

        return $byInvoice;
    }

    /** @return array<int,float> */
    private function vatRateMap(PDO $pdo): array
    {
        $map = [];
        foreach ($pdo->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['id']] = (float) $row['rate_percent'];
        }

        return $map;
    }
}
