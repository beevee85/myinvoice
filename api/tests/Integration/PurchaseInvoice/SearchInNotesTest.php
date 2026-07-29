<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hledání musí najít doklad i podle textu v POZNÁMCE — na obou stranách.
 *
 * Use case: do poznámky se dá volný klíč (např. VIN vozu) na všechny doklady k jednomu
 * případu — přijaté (zálohy, daňové doklady k záloze, konečná faktura) i vydanou fakturu
 * při prodeji. Jedno hledání pak vrátí celý případ a jde z něj spočítat marže.
 *
 * Dřív `searchQuick()` i filtr v seznamu matchovaly jen číslo dokladu a jméno protistrany,
 * takže klíč v poznámce byl fakticky nedohledatelný.
 *
 * Izolováno v roce 2095, vše uklizeno v tearDown.
 */
#[Group('integration')]
final class SearchInNotesTest extends TestCase
{
    private const KEY = 'KLIC-TEST-2095-ABCDEF';

    private Connection $db;
    private PurchaseInvoiceRepository $purchases;
    private InvoiceRepository $invoices;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $piIds = [];
    /** @var int[] */
    private array $invIds = [];
    /** @var int[] */
    private array $clientIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db        = $c->get(Connection::class);
            $this->purchases = $c->get(PurchaseInvoiceRepository::class);
            $this->invoices  = $c->get(InvoiceRepository::class);
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
        foreach ($this->invIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testPurchaseInvoiceIsFoundByKeyInNote(): void
    {
        $vendor = $this->client('Dodavatel poznámka', 'CZ20950001');
        $withKey    = $this->purchase($vendor, 'NOTE-A', 'Nákup — ' . self::KEY . ' — k dalšímu prodeji');
        $withoutKey = $this->purchase($vendor, 'NOTE-B', 'Jiný nákup bez klíče');

        $hits = array_column($this->purchases->searchQuick(self::KEY, $this->supplierId), 'id');
        self::assertContains($withKey, $hits, 'Doklad s klíčem v poznámce musí hledání najít.');
        self::assertNotContains($withoutKey, $hits, 'Doklad bez klíče se vracet nesmí.');
    }

    public function testPurchaseListFilterMatchesNote(): void
    {
        $vendor = $this->client('Dodavatel filtr', 'CZ20950002');
        $withKey = $this->purchase($vendor, 'NOTE-C', 'Poznámka obsahuje ' . self::KEY);

        $rows = $this->purchases->listGroupedByMonth(['supplier_id' => $this->supplierId, 'q' => self::KEY]);
        $ids = [];
        array_walk_recursive($rows, static function ($v, $k) use (&$ids) {
            if ($k === 'id') $ids[] = (int) $v;
        });
        self::assertContains($withKey, $ids, 'Filtr v seznamu přijatých musí poznámku pokrýt.');
    }

    public function testIssuedInvoiceIsFoundByKeyInNote(): void
    {
        $client  = $this->client('Odběratel poznámka', 'CZ20950003');
        $withKey = $this->issued($client, 'Prodej — ' . self::KEY);

        $hits = array_column($this->invoices->searchQuick(self::KEY, $this->supplierId), 'id');
        self::assertContains($withKey, $hits,
            'Vydaná faktura s klíčem v poznámce musí být dohledatelná — jinak nejde spárovat s nákupem.');
    }

    private function purchase(int $vendorId, string $number, string $note): int
    {
        $date = '2095-03-10';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot, note_above_items,
                 total_without_vat, total_vat, total_with_vat, status, is_fixed_asset,
                 vat_deduction, vat_deduction_percent, tax_deductible, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, 1000, 0, 1000, "draft", 0, "full", 100, 1, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $date, $date, $date, $date,
            $this->currencyId, $note, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->piIds[] = $id;
        return $id;
    }

    private function issued(int $clientId, string $note): int
    {
        $date = '2095-03-12';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, note_above_items,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, 0, ?, 1000, 0, 1000, "draft", ?)'
        );
        $stmt->execute([
            $this->supplierId, $clientId, 'T2095001', $date, $date, $date,
            $this->currencyId, $note, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invIds[] = $id;
        return $id;
    }

    private function client(string $name, string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }
}
