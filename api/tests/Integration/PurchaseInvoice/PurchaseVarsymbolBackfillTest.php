<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `bin/backfill-purchase-varsymbols.php` nesmí přidělovat interní číslo KONCEPTŮM.
 *
 * Regrese: skript i jeho auto-trigger v `bin/migrate.php` vybíraly
 * `varsymbol IS NULL AND status != 'cancelled'`, takže při KAŽDÉM startu kontejneru
 * (entrypoint pouští migrate.php) dostaly číslo i rozpracované doklady — a spálily
 * si tím číslo z řady. Interní číslo se přiděluje až při draft → received
 * (`TransitionPurchaseInvoiceStatusAction::ensureVarsymbol`).
 *
 * Test spouští skutečný skript (ne jen dotaz), aby hlídal obě místa najednou.
 * Izolováno v roce 2096 pod existujícím supplierem, vše uklizeno v tearDown.
 */
#[Group('integration')]
final class PurchaseVarsymbolBackfillTest extends TestCase
{
    private Connection $db;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $piIds = [];
    /** @var int[] */
    private array $vendorIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        if (!is_file($rootDir . '/api/bin/backfill-purchase-varsymbols.php')) {
            $this->markTestSkipped('Backfill skript nenalezen.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testBackfillSkipsDraftsButNumbersDocumentsThatLeftDraft(): void
    {
        $vendor = $this->vendor('Dodavatel backfill', 'CZ20960001');

        $draft     = $this->purchase($vendor, 'BF-DRAFT', 'draft');
        $received  = $this->purchase($vendor, 'BF-RECEIVED', 'received');
        $cancelled = $this->purchase($vendor, 'BF-CANCELLED', 'cancelled');

        $this->runBackfill();

        self::assertNull($this->varsymbol($draft),
            'Koncept musí zůstat bez interního čísla — přiděluje se až při draft → received.');
        self::assertNotNull($this->varsymbol($received),
            'Doklad, který draft opustil bez čísla, backfill doplnit MÁ (kvůli tomu existuje).');
        self::assertNull($this->varsymbol($cancelled),
            'Stornovaný doklad backfill vynechává (beze změny).');
    }

    public function testBackfillIsIdempotentAndDoesNotRenumber(): void
    {
        $vendor = $this->vendor('Dodavatel backfill 2', 'CZ20960002');
        $received = $this->purchase($vendor, 'BF-IDEM', 'received');

        $this->runBackfill();
        $first = $this->varsymbol($received);
        self::assertNotNull($first);

        $this->runBackfill();
        self::assertSame($first, $this->varsymbol($received),
            'Opakovaný běh nesmí přečíslovat už očíslovaný doklad.');
    }

    /** Spustí skutečný backfill skript (hlídá dotaz ve skriptu, ne jen v testu). */
    private function runBackfill(): void
    {
        $script = dirname(__DIR__, 3) . '/bin/backfill-purchase-varsymbols.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --apply 2>&1';
        exec($cmd, $out, $exitCode);
        self::assertSame(0, $exitCode, "Backfill skončil s chybou:\n" . implode("\n", $out));
    }

    private function varsymbol(int $id): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT varsymbol FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null || $v === '') ? null : (string) $v;
    }

    private function purchase(int $vendorId, string $number, string $status): int
    {
        $date = '2096-05-15';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, is_fixed_asset,
                 vat_deduction, vat_deduction_percent, tax_deductible, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 1000, 0, 1000, ?, 0, "full", 100, 1, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $date, $date, $date, $date,
            $this->currencyId, $status, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->piIds[] = $id;
        return $id;
    }

    private function vendor(string $name, string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }
}
