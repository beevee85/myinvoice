<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseInvoiceWriteService;
use MyInvoice\Service\Validation\HistoricalValidationScanner;
use MyInvoice\Tests\Support\CollectingLogger;
use MyInvoice\Tests\Support\PurchaseInvoiceCharacterizationCase;
use PHPUnit\Framework\Attributes\Group;
use PDO;

/**
 * FORK (beevee85) — pravidlo **V64: tenant scoping**.
 *
 * Doteď bylo krytí jen nepřímé — přes charakterizační test, který kontroluje odmítnutí
 * cizího dodavatele mimochodem. Při přejmenování nebo přepsání toho testu by ochrana
 * zmizela a nikdo by si toho nevšiml. Tenhle soubor pravidlo pojmenovává a testuje přímo.
 *
 * Pokrývá tři vrstvy, protože únik na kterékoli z nich je únik:
 *   zápis (nelze založit doklad na cizího dodavatele),
 *   čtení (repozitář nevrátí cizí doklad),
 *   analýza (skener nesmí míchat tenanty do jednoho reportu).
 */
#[Group('integration')]
final class PurchaseInvoiceTenantScopingTest extends PurchaseInvoiceCharacterizationCase
{
    private PurchaseInvoiceRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->container->get(PurchaseInvoiceRepository::class);
    }

    private function writer(): PurchaseInvoiceWriteService
    {
        return new PurchaseInvoiceWriteService(
            $this->container->get(Connection::class),
            $this->repo,
            $this->container->get(PurchaseInvoiceCalculator::class),
            new CollectingLogger(),
        );
    }

    /** Druhý tenant a jeho dodavatel; přeskočí, když fixture druhého tenanta nemá. */
    private function foreignSupplierAndVendor(): array
    {
        $pdo = $this->db->pdo();
        $foreignSupplier = (int) ($pdo->query(
            "SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($foreignSupplier === 0) {
            self::markTestSkipped('V DB není druhý tenant — cross-tenant test nelze provést.');
        }
        $foreignVendor = (int) ($pdo->query(
            "SELECT id FROM clients WHERE supplier_id = {$foreignSupplier} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($foreignVendor === 0) {
            self::markTestSkipped('Druhý tenant nemá klienty — spusť api/bin/test-seed-clients.php.');
        }

        return [$foreignSupplier, $foreignVendor];
    }

    /** @return array<string,mixed> */
    private function payload(string $number, int $vendorId): array
    {
        return [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $number,
            'issue_date'            => self::YEAR . '-07-10',
            'due_date'              => self::YEAR . '-08-10',
            'currency_id'           => $this->currencyId,
            'items' => [[
                'description' => 'Položka', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => 100,
                'vat_rate_id' => $this->vatRateId('CZ-21'), 'order_index' => 0,
            ]],
        ];
    }

    // ------------------------------------------------------------------- zápis

    /** V64: doklad nelze založit na dodavatele jiného tenanta. */
    public function testCannotCreateInvoiceForForeignTenantsVendor(): void
    {
        [, $foreignVendor] = $this->foreignSupplierAndVendor();
        $before = $this->countInvoices($this->supplierId);

        try {
            $this->writer()->createWithItems(
                $this->payload('V64-CIZI-001', $foreignVendor), $this->userId, $this->supplierId, 'isdoc',
            );
            self::fail('Doklad na cizího dodavatele se založil — únik mezi tenanty.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nepatří', mb_strtolower($e->getMessage()) === $e->getMessage() ? $e->getMessage() : $e->getMessage());
        }

        self::assertSame($before, $this->countInvoices($this->supplierId), 'Odmítnutý zápis přesto něco vytvořil.');
    }

    // ------------------------------------------------------------------- čtení

    /** V64: repozitář nevrátí doklad jiného tenanta ani při znalosti jeho id. */
    public function testRepositoryDoesNotReturnForeignTenantsInvoice(): void
    {
        [$foreignSupplier] = $this->foreignSupplierAndVendor();

        $id = $this->trackInvoice(
            $this->writer()->createWithItems($this->payload('V64-CTENI-001', $this->vendorId), $this->userId, $this->supplierId, 'isdoc')
        );

        self::assertNotNull($this->repo->find($id, $this->supplierId), 'Vlastní doklad musí být čitelný.');
        self::assertNull(
            $this->repo->find($id, $foreignSupplier),
            'Repozitář vrátil doklad cizího tenanta — chybí scope na supplier_id.',
        );
    }

    /** V64: ani zápis položek nesmí projít pod cizím tenantem. */
    public function testVatOverridesAreScopedToTenant(): void
    {
        [$foreignSupplier] = $this->foreignSupplierAndVendor();

        $id = $this->trackInvoice(
            $this->writer()->createWithItems($this->payload('V64-OVERRIDE-001', $this->vendorId), $this->userId, $this->supplierId, 'isdoc')
        );

        // Pokus zapsat rekapitulaci pod cizím tenantem musí být bez efektu.
        $this->repo->setVatOverrides($id, $foreignSupplier, [['rate' => 21.0, 'base' => 1.0, 'vat' => 0.21]]);

        $stmt = $this->db->pdo()->prepare('SELECT vat_overrides FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        self::assertNull(
            $stmt->fetchColumn() ?: null,
            'Zápis pod cizím supplier_id se projevil — chybí scope v UPDATE.',
        );
    }

    // ------------------------------------------------------------------ analýza

    /** V64: retrospektivní skener omezený na tenanta nesmí načíst doklady jiného. */
    public function testHistoricalScannerRespectsTenantScope(): void
    {
        [$foreignSupplier] = $this->foreignSupplierAndVendor();
        $pdo = $this->db->pdo();

        $mine    = (int) $pdo->query("SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = {$this->supplierId} AND deleted_at IS NULL")->fetchColumn();
        $foreign = (int) $pdo->query("SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = {$foreignSupplier} AND deleted_at IS NULL")->fetchColumn();

        $scanner = new HistoricalValidationScanner($this->container->get(Connection::class));

        self::assertSame($mine, $scanner->scan($this->supplierId)['scanned'],
            'Skener s tenant scope načetl jiný počet dokladů, než tenant má.');
        self::assertSame($foreign, $scanner->scan($foreignSupplier)['scanned'],
            'Skener nerespektuje omezení na druhého tenanta.');
        self::assertSame($mine + $foreign, $scanner->scan(null)['scanned'],
            'Skener bez omezení nevrátil součet obou tenantů.');
    }

    private function countInvoices(int $supplierId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = {$supplierId} AND YEAR(issue_date) = " . self::YEAR
        )->fetchColumn();
    }
}
