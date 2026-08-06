<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseImportBatchRepository;
use MyInvoice\Service\PurchaseBatchImport\BatchApply;
use MyInvoice\Service\PurchaseBatchImport\BatchLimitException;
use MyInvoice\Service\PurchaseBatchImport\ResultsIntake;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — apply krok: z ověřeného výsledku koncept.
 *
 * DODAVATEL VE FIXTURÁCH NEMÁ IČO ANI DIČ — jen jméno. Je to záměr, ne lenost:
 * s IČO by ClientResolver šel do ARES a VendorVatPayerResolver do ARES/VIES,
 * a test by stál na síti. Jméno-only jde cestou 1c (match podle company_name)
 * a k síti se nepřiblíží. Jména jsou per-běh unikátní, aby si testy nešlapaly
 * po klientech.
 *
 * Sazba DPH se bere ZE SKUTEČNÉHO číselníku a totály se z ní dopočítávají —
 * fixture s natvrdo zapsanou sazbou by prošla jen na instanci, kde ta sazba
 * náhodou existuje.
 */
#[Group('integration')]
final class BatchApplyTest extends TestCase
{
    private Connection $db;
    private PurchaseImportBatchRepository $repo;
    private ResultsIntake $intake;
    private BatchApply $apply;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;
    private float $vatRate = 0.0;
    private string $vendorName = '';

    /** @var int[] */
    private array $batchIds = [];

    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c            = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->repo   = $c->get(PurchaseImportBatchRepository::class);
            $this->intake = $c->get(ResultsIntake::class);
            $this->apply  = $c->get(BatchApply::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $ids = $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 2')
            ->fetchAll(\PDO::FETCH_COLUMN);
        if (count($ids) < 2) {
            $this->markTestSkipped('Test vyžaduje dva tenanty.');
        }
        $this->supplierA = (int) $ids[0];
        $this->supplierB = (int) $ids[1];

        $this->userId = (int) ($this->db->pdo()
            ->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0) {
            $this->markTestSkipped('Chybí uživatel v DB (created_by je NOT NULL).');
        }

        $rate = $this->db->pdo()
            ->query('SELECT rate_percent FROM vat_rates WHERE rate_percent > 0 ORDER BY id LIMIT 1')
            ->fetchColumn();
        if ($rate === false) {
            $this->markTestSkipped('Číselník vat_rates je prázdný.');
        }
        $this->vatRate = (float) $rate;

        $this->vendorName = 'Dávkový dodavatel ' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->pdo();

        // Koncepty vzniklé z dávek tohoto běhu — položky, faktury, pak dávky.
        if ($this->batchIds !== []) {
            $in = implode(',', array_fill(0, count($this->batchIds), '?'));

            $ids = $pdo->prepare(
                "SELECT id FROM purchase_invoices WHERE purchase_import_batch_id IN ({$in})"
            );
            $ids->execute($this->batchIds);
            $invoiceIds = $ids->fetchAll(\PDO::FETCH_COLUMN);

            if ($invoiceIds !== []) {
                $inInv = implode(',', array_fill(0, count($invoiceIds), '?'));
                $pdo->prepare("DELETE FROM purchase_invoice_items WHERE purchase_invoice_id IN ({$inInv})")
                    ->execute($invoiceIds);
                $pdo->prepare("DELETE FROM purchase_invoices WHERE id IN ({$inInv})")
                    ->execute($invoiceIds);
            }

            $pdo->prepare("DELETE FROM purchase_import_batches WHERE id IN ({$in})")
                ->execute($this->batchIds);
            $this->batchIds = [];
        }

        // Klient vytvořený resolverem — jméno je per-běh unikátní.
        if ($this->vendorName !== '') {
            $pdo->prepare('DELETE FROM clients WHERE company_name = ? AND supplier_id IN (?, ?)')
                ->execute([$this->vendorName, $this->supplierA, $this->supplierB]);
        }
    }

    // -----------------------------------------------------------------------

    /** @return array{0: int, 1: string} */
    private function makeBatch(int $supplierId, array $shas = [self::SHA_A]): array
    {
        $token = bin2hex(random_bytes(16));
        $id = $this->repo->create(
            $supplierId, null, hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600),
        );
        $this->batchIds[] = $id;

        foreach ($shas as $sha) {
            $this->repo->addFile($id, $supplierId, 'f.pdf', $sha . '.pdf', 1024, $sha, 'application/pdf');
        }

        return [$id, $token];
    }

    /**
     * Doklad s totály dopočtenými ze skutečné sazby — V81b vyžaduje přesnost.
     *
     * @param array<string,mixed> $over
     */
    private function doc(string $sha, string $number, array $over = []): array
    {
        $base  = '100.00';
        $vat   = number_format(round(100 * $this->vatRate / 100, 2), 2, '.', '');
        $total = number_format(round(100 + 100 * $this->vatRate / 100, 2), 2, '.', '');

        return array_replace([
            'sha256'        => $sha,
            'document_kind' => 'invoice',
            'vendor'        => ['company_name' => $this->vendorName],
            'customer_matches_tenant' => true,
            'numbers'       => ['vendor_invoice_number' => $number, 'varsymbol' => '2026123'],
            'dates'         => ['issue_date' => '2026-07-01', 'due_date' => '2026-07-15'],
            'items'         => [[
                'description' => 'Zboží', 'quantity' => '1',
                'unit_price_without_vat' => $base, 'line_base' => $base,
                'vat_rate' => (string) $this->vatRate,
            ]],
            'totals'        => ['base' => $base, 'vat' => $vat, 'total' => $total, 'currency' => 'CZK'],
        ], $over);
    }

    private function payloadOf(array $docs): string
    {
        return json_encode(
            ['schema' => 'myinvoice.purchase-import-batch.results/1', 'documents' => $docs],
            JSON_THROW_ON_ERROR,
        );
    }

    private function tenantOf(int $supplierId): array
    {
        $s = $this->db->pdo()->prepare('SELECT ic, dic FROM supplier WHERE id = ?');
        $s->execute([$supplierId]);
        $r = $s->fetch(\PDO::FETCH_ASSOC) ?: [];

        return ['ic' => (string) ($r['ic'] ?? ''), 'dic' => (string) ($r['dic'] ?? '')];
    }

    /** Založí dávku, přijme výsledky a vrátí [batchId, resultIds podle sha]. */
    private function validatedBatch(array $docs, array $shas): array
    {
        [$id, $token] = $this->makeBatch($this->supplierA, $shas);
        $r = $this->intake->accept($id, $this->supplierA, hash('sha256', $token),
            $this->payloadOf($docs), $this->tenantOf($this->supplierA), '2026-08-06');
        self::assertTrue($r['ok'], 'předpoklad: dávka musí projít validací — '
            . json_encode($r['findings'], JSON_UNESCAPED_UNICODE));

        $stmt = $this->db->pdo()->prepare(
            'SELECT r.id, f.sha256
               FROM purchase_import_batch_results r
               JOIN purchase_import_batch_files f ON f.id = r.purchase_import_batch_file_id
              WHERE r.purchase_import_batch_id = ?'
        );
        $stmt->execute([$id]);
        $bySha = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $bySha[(string) $row['sha256']] = (int) $row['id'];
        }

        return [$id, $bySha];
    }

    private function expectReason(string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail("Očekával jsem odmítnutí s kódem '{$code}'.");
        } catch (BatchLimitException $e) {
            self::assertSame($code, $e->reasonCode());
        }
    }

    private function invoiceCount(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM purchase_invoices')->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Šťastná cesta
    // -----------------------------------------------------------------------

    public function testApplyCreatesDraftWithCorrectFields(): void
    {
        [$batchId, $bySha] = $this->validatedBatch(
            [$this->doc(self::SHA_A, 'BA-2026-001')], [self::SHA_A]);

        $r = $this->apply->apply($batchId, $bySha[self::SHA_A],
            $this->supplierA, $this->userId, '2026-08-06');

        $inv = $this->db->pdo()->prepare('SELECT * FROM purchase_invoices WHERE id = ?');
        $inv->execute([$r['purchase_invoice_id']]);
        $row = $inv->fetch(\PDO::FETCH_ASSOC);

        self::assertSame('draft', $row['status'], 'VŠECHNO končí jako koncept');
        self::assertNull($row['varsymbol'],
            'interní číslo se generuje až při draft→received — koncept ho mít NESMÍ (lekce z backfillu)');
        self::assertSame('2026123', $row['payment_variable_symbol'],
            'VS dodavatele patří do payment_variable_symbol, ne do varsymbol');
        self::assertSame('BA-2026-001', $row['vendor_invoice_number']);
        self::assertSame($batchId, (int) $row['purchase_import_batch_id'],
            'vazba na dávku jde do forkového sloupce');
        self::assertSame($this->supplierA, (int) $row['supplier_id']);

        // Přepočtené totály sedí na papír → žádné varování.
        self::assertSame([], $r['warnings'], json_encode($r['warnings'], JSON_UNESCAPED_UNICODE));

        // Řádek výsledku: applied + vazba + normalizovaný payload.
        $res = $this->repo->findResult($bySha[self::SHA_A], $batchId, $this->supplierA);
        self::assertSame('applied', $res['status']);
        self::assertSame($r['purchase_invoice_id'], $res['purchase_invoice_id']);

        // Jednodokladová dávka: všechno aplikováno → done + raw_json pryč (V79b).
        self::assertSame('done', $r['batch_status']);
        self::assertNull($this->repo->findResult($bySha[self::SHA_A], $batchId, $this->supplierA)['raw_json']);
    }

    /** Dvoudokladová dávka: první apply = applying, druhý = done. */
    public function testBatchGoesDoneOnlyAfterLastApply(): void
    {
        [$batchId, $bySha] = $this->validatedBatch(
            [$this->doc(self::SHA_A, 'BA-2026-010'), $this->doc(self::SHA_B, 'BA-2026-011')],
            [self::SHA_A, self::SHA_B]);

        $first = $this->apply->apply($batchId, $bySha[self::SHA_A],
            $this->supplierA, $this->userId, '2026-08-06');
        self::assertSame('applying', $first['batch_status'],
            'dávka s neaplikovanými doklady nesmí být done');

        $second = $this->apply->apply($batchId, $bySha[self::SHA_B],
            $this->supplierA, $this->userId, '2026-08-06');
        self::assertSame('done', $second['batch_status']);
    }

    // -----------------------------------------------------------------------
    // Odmítnutí
    // -----------------------------------------------------------------------

    public function testDoubleApplyIsRefusedAndLeavesNothing(): void
    {
        [$batchId, $bySha] = $this->validatedBatch(
            [$this->doc(self::SHA_A, 'BA-2026-020')], [self::SHA_A]);

        $this->apply->apply($batchId, $bySha[self::SHA_A], $this->supplierA, $this->userId, '2026-08-06');
        $before = $this->invoiceCount();

        // Dávka je po jediném dokladu done → brána stavu dávky; kdyby prošla,
        // zastaví to guard `status = validated` na řádku. Obě cesty končí odmítnutím.
        try {
            $this->apply->apply($batchId, $bySha[self::SHA_A], $this->supplierA, $this->userId, '2026-08-06');
            self::fail('druhé schválení téhož řádku musí být odmítnuto');
        } catch (BatchLimitException $e) {
            self::assertContains($e->reasonCode(), ['batch_not_applicable', 'result_not_applicable']);
        }

        self::assertSame($before, $this->invoiceCount(), 'druhý koncept nesmí vzniknout');
    }

    /**
     * Dvojité schválení v dávce, která JEŠTĚ NENÍ done (zbývá druhý doklad) —
     * tady musí zastavit řádková brána, ne stav dávky.
     */
    public function testDoubleApplyInOpenBatchHitsResultGuard(): void
    {
        [$batchId, $bySha] = $this->validatedBatch(
            [$this->doc(self::SHA_A, 'BA-2026-025'), $this->doc(self::SHA_B, 'BA-2026-026')],
            [self::SHA_A, self::SHA_B]);

        $this->apply->apply($batchId, $bySha[self::SHA_A], $this->supplierA, $this->userId, '2026-08-06');
        $before = $this->invoiceCount();

        $this->expectReason('result_not_applicable',
            fn () => $this->apply->apply($batchId, $bySha[self::SHA_A],
                $this->supplierA, $this->userId, '2026-08-06'));

        self::assertSame($before, $this->invoiceCount());
    }

    public function testFailedBatchCannotApply(): void
    {
        [$id, $token] = $this->makeBatch($this->supplierA, [self::SHA_A]);
        // Doklad o souboru, který v dávce není → V4 → failed.
        $this->intake->accept($id, $this->supplierA, hash('sha256', $token),
            $this->payloadOf([$this->doc(self::SHA_B, 'BA-2026-030')]),
            $this->tenantOf($this->supplierA), '2026-08-06');

        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_import_batch_results WHERE purchase_import_batch_id = ?');
        $stmt->execute([$id]);
        $resultId = (int) $stmt->fetchColumn();

        $this->expectReason('batch_not_applicable',
            fn () => $this->apply->apply($id, $resultId, $this->supplierA, $this->userId, '2026-08-06'));
    }

    public function testForeignTenantCannotApply(): void
    {
        [$batchId, $bySha] = $this->validatedBatch(
            [$this->doc(self::SHA_A, 'BA-2026-040')], [self::SHA_A]);

        $this->expectReason('batch_not_found',
            fn () => $this->apply->apply($batchId, $bySha[self::SHA_A],
                $this->supplierB, $this->userId, '2026-08-06'));
    }

    /**
     * Neznámá sazba DPH = CHYBA APPLY, ne tichých 0 %. A protože všechno běží
     * v jedné transakci, po odmítnutí NESMÍ zbýt koncept ani označený řádek.
     */
    public function testUnknownVatRateRefusesApplyAndRollsBack(): void
    {
        $doc = $this->doc(self::SHA_A, 'BA-2026-050');
        $doc['items'][0]['vat_rate'] = '13.37';   // sazba, kterou číselník nezná

        [$batchId, $bySha] = $this->validatedBatch([$doc], [self::SHA_A]);
        $before = $this->invoiceCount();

        $this->expectReason('unknown_vat_rate',
            fn () => $this->apply->apply($batchId, $bySha[self::SHA_A],
                $this->supplierA, $this->userId, '2026-08-06'));

        self::assertSame($before, $this->invoiceCount(), 'transakce musí vrátit i koncept');
        self::assertSame('validated',
            $this->repo->findResult($bySha[self::SHA_A], $batchId, $this->supplierA)['status'],
            'řádek zůstává validated — apply lze zopakovat po doplnění číselníku');
    }

    /** Duplicitní doklad (vendor + číslo + datum) = srozumitelné odmítnutí, ne SQLSTATE 23000. */
    public function testDuplicateInvoiceIsRefused(): void
    {
        [$b1, $s1] = $this->validatedBatch([$this->doc(self::SHA_A, 'BA-2026-060')], [self::SHA_A]);
        $this->apply->apply($b1, $s1[self::SHA_A], $this->supplierA, $this->userId, '2026-08-06');

        // Táž faktura v nové dávce (extrakce běžela dvakrát).
        [$b2, $s2] = $this->validatedBatch([$this->doc(self::SHA_B, 'BA-2026-060')], [self::SHA_B]);

        $this->expectReason('duplicate_invoice',
            fn () => $this->apply->apply($b2, $s2[self::SHA_B], $this->supplierA, $this->userId, '2026-08-06'));
    }

    // -----------------------------------------------------------------------
    // Varování
    // -----------------------------------------------------------------------

    /**
     * Papír s jiným zaokrouhlením DPH: intake projde (V81b kontroluje jen
     * vnitřní konzistenci papíru), ale přepočet z položek dá jinou DPH —
     * rozdíl se PŘIZNÁ varováním na konceptu, nikdy tiše nepřepíše.
     */
    public function testTotalsMismatchIsAdmittedOnTheDraft(): void
    {
        $doc = $this->doc(self::SHA_A, 'BA-2026-070');
        $vat   = round(100 * $this->vatRate / 100, 2) + 0.01;
        $doc['totals']['vat']   = number_format($vat, 2, '.', '');
        $doc['totals']['total'] = number_format(100 + $vat, 2, '.', '');

        [$batchId, $bySha] = $this->validatedBatch([$doc], [self::SHA_A]);

        $r = $this->apply->apply($batchId, $bySha[self::SHA_A],
            $this->supplierA, $this->userId, '2026-08-06');

        self::assertNotSame([], $r['warnings'], 'rozdíl totálů musí být přiznán');

        $inv = $this->db->pdo()->prepare(
            'SELECT extraction_warning FROM purchase_invoices WHERE id = ?');
        $inv->execute([$r['purchase_invoice_id']]);
        self::assertNotEmpty($inv->fetchColumn(), 'varování musí být vidět na konceptu');
    }

    /** Nízká confidence extrakce doputuje až na koncept. */
    public function testLowConfidenceBecomesDraftWarning(): void
    {
        $doc = $this->doc(self::SHA_A, 'BA-2026-080', ['confidence' => 'low']);

        [$batchId, $bySha] = $this->validatedBatch([$doc], [self::SHA_A]);
        $r = $this->apply->apply($batchId, $bySha[self::SHA_A],
            $this->supplierA, $this->userId, '2026-08-06');

        self::assertTrue(
            (bool) array_filter($r['warnings'], static fn (string $w) => str_contains($w, 'confidence')),
            'low confidence musí být mezi varováními: ' . json_encode($r['warnings'], JSON_UNESCAPED_UNICODE),
        );
    }
}
