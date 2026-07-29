<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Validation\HistoricalValidationScanner;
use MyInvoice\Service\Invoice\PurchaseInvoiceWriteService;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Tests\Support\CollectingLogger;
use MyInvoice\Tests\Support\PurchaseInvoiceCharacterizationCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PDO;

/**
 * FORK (beevee85) — retrospektivní stínová validace nad historií (V76).
 *
 * Ověřuje to, na čem u analytického nástroje nad ostrými daty záleží nejvíc:
 * že opravdu NIC nezapisuje, že rekonstruuje DTO z uložených dat správně
 * a že rozlišuje „údaj se tehdy nesbíral" od „hodnoty si protiřečí".
 */
#[Group('integration')]
final class HistoricalValidationScannerTest extends PurchaseInvoiceCharacterizationCase
{
    private HistoricalValidationScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new HistoricalValidationScanner($this->container->get(Connection::class));
    }

    private function writeInvoice(string $number, array $items, array $extra = []): int
    {
        $writer = new PurchaseInvoiceWriteService(
            $this->container->get(Connection::class),
            $this->container->get(PurchaseInvoiceRepository::class),
            $this->container->get(PurchaseInvoiceCalculator::class),
            new CollectingLogger(),
        );

        return $this->trackInvoice($writer->createWithItems(array_merge([
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => $number,
            'issue_date'            => self::YEAR . '-04-10',
            'due_date'              => self::YEAR . '-05-10',
            'currency_id'           => $this->currencyId,
            'items'                 => $items,
        ], $extra), $this->userId, $this->supplierId, 'isdoc'));
    }

    /** @return array<string,mixed> */
    private function validItem(): array
    {
        return [
            'description'            => 'Položka',
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 100,
            'vat_rate_id'            => $this->vatRateId('CZ-21'),
            'order_index'            => 0,
        ];
    }

    // --------------------------------------------------------------- nulový zápis

    /**
     * NEJDŮLEŽITĚJŠÍ TEST: nástroj se pouští nad kopií ostrých dat, takže nesmí
     * zapsat vůbec nic — ani do databáze, ani do provozní telemetrie.
     */
    public function testScannerWritesNothing(): void
    {
        $this->writeInvoice('HIST-RO-001', [$this->validItem()]);

        $pdo    = $this->db->pdo();
        $before = $this->databaseFingerprint($pdo);

        $this->scanner->scan($this->supplierId);

        self::assertSame($before, $this->databaseFingerprint($pdo), 'Skener změnil obsah databáze.');
    }

    /**
     * Otisk stavu: počty řádků i nejnovější `updated_at` — samotné počty by neodhalily
     * UPDATE, který nic nepřidává.
     *
     * @return array<string,mixed>
     */
    private function databaseFingerprint(PDO $pdo): array
    {
        $out = [];
        foreach (['purchase_invoices', 'purchase_invoice_items', 'activity_log', 'clients'] as $table) {
            $out[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }
        $out['pi_updated_max'] = (string) $pdo->query('SELECT COALESCE(MAX(updated_at), "") FROM purchase_invoices')->fetchColumn();
        $out['pi_totals']      = (string) $pdo->query(
            'SELECT COALESCE(SUM(total_with_vat), 0) FROM purchase_invoices'
        )->fetchColumn();

        return $out;
    }

    /**
     * Skener nesmí sahat na síť ani zapisovat — ověřeno staticky nad zdrojákem.
     *
     * Kontroluje se KÓD BEZ KOMENTÁŘŮ: docblock ta slova legitimně obsahuje
     * („žádný INSERT ani UPDATE") a hlídat text dokumentace nemá smysl.
     */
    public function testScannerIsOfflineByConstruction(): void
    {
        $code = self::codeWithoutComments(
            dirname(__DIR__, 3) . '/src/Service/Validation/HistoricalValidationScanner.php'
        );

        foreach (['AresClient', 'ViesClient', 'CrpDphClient', 'CnbExchangeRateClient', 'Guzzle', 'curl_', 'fopen', 'fsockopen'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                "Skener sahá na {$forbidden} — má být offline a bez vedlejších efektů.",
            );
        }
        foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REPLACE INTO'] as $write) {
            self::assertStringNotContainsString($write, $code, "Skener obsahuje {$write} — má jen číst.");
        }
        self::assertStringNotContainsString(
            'PurchaseInvoiceWriteService',
            $code,
            'Skener by přes write service zašuměl provozní telemetrii historickou dávkou.',
        );
    }

    /** Zdrojový kód bez komentářů — hlídáme, co se provádí, ne co je v dokumentaci. */
    private static function codeWithoutComments(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    // ------------------------------------------------------- rekonstrukce a nálezy

    public function testReconstructedDtoMatchesStoredInvoice(): void
    {
        $id = $this->writeInvoice('HIST-DTO-001', [$this->validItem()], ['tax_date' => self::YEAR . '-04-10']);

        $pdo   = $this->db->pdo();
        $stmt  = $pdo->prepare('SELECT * FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row   = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare('SELECT * FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index');
        $stmt->execute([$id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dto = $this->scanner->reconstructDto($row, $items);

        self::assertSame('HIST-DTO-001', $dto['vendor_invoice_number']);
        self::assertSame(self::YEAR . '-04-10', $dto['issue_date']);
        self::assertSame(self::YEAR . '-05-10', $dto['due_date']);
        self::assertSame($this->vendorId, $dto['vendor_id']);
        self::assertCount(1, $dto['items']);
        self::assertSame('Položka', $dto['items'][0]['description']);
        self::assertSame(1.0, $dto['items'][0]['quantity']);

        // Rekonstruované DTO musí projít toutéž validací jako při zápisu.
        self::assertSame([], \MyInvoice\Service\Validation\PurchaseInvoiceValidation::invoice(
            $dto,
            [$this->vatRateId('CZ-21') => 21.0],
        ));
    }

    /** Vadný doklad v historii se v reportu projeví — a se správnou kategorií. */
    public function testFindingIsReportedWithCategory(): void
    {
        $this->writeInvoice('HIST-BAD-001', [
            ['description' => '', 'quantity' => 0, 'unit' => 'ks',
             'unit_price_without_vat' => 100, 'vat_rate_id' => $this->vatRateId('CZ-21'), 'order_index' => 0],
        ]);

        $report = $this->scanner->scan($this->supplierId);

        self::assertGreaterThan(0, $report['scanned']);
        self::assertGreaterThan(0, $report['failed'], 'Vadný doklad se v reportu neprojevil.');
        self::assertArrayHasKey('items.*.quantity', $report['by_rule']);
        self::assertArrayHasKey('items.*.description', $report['by_rule']);
        // Prázdný popis je úklid historie, nulové množství je skutečný rozpor.
        self::assertGreaterThan(0, $report['by_category'][HistoricalValidationScanner::LEGACY_GAP]);
        self::assertGreaterThan(0, $report['by_category'][HistoricalValidationScanner::REAL_MISMATCH]);
    }

    /** Bezvadná historie nesmí v reportu nic vyrobit. */
    public function testCleanInvoiceProducesNoFinding(): void
    {
        $this->writeInvoice('HIST-OK-001', [$this->validItem()]);

        $report = $this->scanner->scan($this->supplierId);

        self::assertSame(0, $report['failed'], 'Bezvadná historie vyrobila nález.');
        self::assertSame([], $report['by_rule']);
    }

    // ------------------------------------------------------------- klasifikace

    /** @return iterable<string, array{string, string}> */
    public static function ruleCategories(): iterable
    {
        yield 'chybějící číslo dokladu' => ['vendor_invoice_number', HistoricalValidationScanner::LEGACY_GAP];
        yield 'prázdný popis položky'   => ['items.*.description',   HistoricalValidationScanner::LEGACY_GAP];
        yield 'chybějící datum'         => ['issue_date',            HistoricalValidationScanner::LEGACY_GAP];
        yield 'nulové množství'         => ['items.*.quantity',      HistoricalValidationScanner::REAL_MISMATCH];
        yield 'neznámá sazba'           => ['items.*.vat_rate_id',   HistoricalValidationScanner::REAL_MISMATCH];
        yield 'kurz mimo rozsah'        => ['exchange_rate',         HistoricalValidationScanner::REAL_MISMATCH];
        // Neznámé pravidlo raději jako rozpor — ať se nový typ nálezu neztratí v úklidu.
        yield 'neznámé pravidlo'        => ['neco.noveho',           HistoricalValidationScanner::REAL_MISMATCH];
    }

    #[DataProvider('ruleCategories')]
    public function testRuleCategory(string $rule, string $expected): void
    {
        self::assertSame($expected, HistoricalValidationScanner::categoryOf($rule));
    }

    /** @return iterable<string, array{array<string,mixed>, string}> */
    public static function sourceHeuristics(): iterable
    {
        yield 'idoklad'   => [['idoklad_id' => 5], 'idoklad'];
        yield 'fakturoid' => [['fakturoid_id' => 7], 'fakturoid'];
        yield 'isdoc'     => [['source_format' => 'isdoc'], 'isdoc'];
        yield 'ai dávka'  => [['import_batch_id' => 'abc'], 'ai_pdf'];
        yield 'banka'     => [['vendor_invoice_number' => 'BANK-42'], 'banka'];
        yield 's pdf'     => [['pdf_path' => 'sup-1/ab/cd.pdf'], 'ai_pdf_nebo_rucni_s_pdf'];
        yield 'ruční'     => [[], 'rucni'];
    }

    /** @param array<string,mixed> $invoice */
    #[DataProvider('sourceHeuristics')]
    public function testSourceClassification(array $invoice, string $expected): void
    {
        self::assertSame($expected, HistoricalValidationScanner::classifySource($invoice));
    }

    /** Nevyhodnocená pravidla se hlásí výslovně — mlčení by se dalo číst jako „prošlo". */
    public function testSkippedRuleGroupsAreReported(): void
    {
        $report  = $this->scanner->scan($this->supplierId);
        $reasons = array_column($report['skipped_rule_groups'], 'reason');

        self::assertContains('no_source_document', $reasons);
        self::assertContains('needs_network', $reasons);
    }
}
