<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Project;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FORK 0923 (B2) — zakázka napříč nákupem a prodejem:
 *  - zakázku lze založit BEZ klienta (supplier_id z akce), find() ji vrátí
 *    se správným tenant scope (LEFT JOIN, COALESCE),
 *  - přijatý doklad jde přiřadit k zakázce, zálohy do nákladu nevstupují,
 *  - caseSummary počítá nákup/prodej/marži (D5),
 *  - participants se odvodí z přiřazených dokladů (vendor z nákupu).
 *
 * Izolováno v roce 2095, uklizeno v tearDown.
 */
#[Group('integration')]
final class ProjectCrossSideTest extends TestCase
{
    private const YEAR = 2095;

    private Connection $db;
    private ProjectRepository $projects;
    private PurchaseInvoiceRepository $purchases;
    private PurchaseInvoiceCalculator $calc;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRate21 = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $piIds = [];
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $clientIds = [];
    /** @var int[] */
    private array $projectIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db        = $c->get(Connection::class);
            $this->projects  = $c->get(ProjectRepository::class);
            $this->purchases = $c->get(PurchaseInvoiceRepository::class);
            $this->calc      = $c->get(PurchaseInvoiceCalculator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRate21  = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code='CZ-21' LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (!$this->supplierId || !$this->currencyId || !$this->vatRate21 || !$this->userId || !$this->czId) {
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
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->projectIds as $id) {
            $pdo->prepare('DELETE FROM project_participants WHERE project_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testProjectWithoutClientCarriesBothSidesAndMargin(): void
    {
        // Zakázka BEZ klienta — tenant explicitně (nová cesta B2).
        $projectId = $this->projects->create([
            'supplier_id' => $this->supplierId,
            'name'        => 'Obchodní případ B2 ' . self::YEAR,
            'currency_id' => $this->currencyId,
        ]);
        $this->projectIds[] = $projectId;

        $found = $this->projects->find($projectId);
        self::assertNotNull($found, 'find() musí zakázku bez klienta vrátit (LEFT JOIN).');
        self::assertSame($this->supplierId, (int) $found['supplier_id'],
            'Tenant scope nese projects.supplier_id.');
        self::assertNull($found['client_id']);

        // Nákupní strana: DDKPZ 100 000 + záloha 100 000 (záloha do nákladu nepatří).
        $vendor = $this->client('Dodavatel B2', 'CZ20950001', isVendor: true);
        $dd = $this->purchaseDoc($vendor, 'tax_document', 'ZD-B2', 82644.63, $projectId);
        $this->purchaseDoc($vendor, 'advance', 'ZA-B2', 82644.63, $projectId);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE purchase_invoices SET status='received' WHERE id IN (?, ?)")
            ->execute([$dd, $this->piIds[count($this->piIds) - 1]]);

        // Prodejní strana: vydaná faktura 103 000 vč. DPH.
        $customer = $this->client('Odběratel B2', 'CZ20950002', isVendor: false);
        $inv = $this->saleInvoice($customer, $projectId, 85123.97, 17876.03);

        $summary = $this->projects->caseSummary($projectId);
        self::assertEqualsWithDelta(100000.00, $summary['purchase_with_vat'], 0.02,
            'Náklad = jen DDKPZ (100 000); záloha se nezapočítává.');
        self::assertEqualsWithDelta(103000.00, $summary['sale_with_vat'], 0.02);
        self::assertEqualsWithDelta(3000.00, $summary['margin_with_vat'], 0.03);
        self::assertSame(1, $summary['purchase_count']);
        self::assertSame(1, $summary['sale_count']);

        // Participants: vendor z nákupu + customer z prodeje (živá derivace).
        $participants = $this->projects->participantsFor($projectId);
        $roles = [];
        foreach ($participants as $p) {
            $roles[$p['role']][] = $p['client_id'];
        }
        self::assertContains($vendor, $roles['vendor'] ?? [], 'Dodavatel z přijatého dokladu.');
        self::assertContains($customer, $roles['customer'] ?? [], 'Odběratel z vydané faktury.');

        // Cizí zakázka se k dokladu přiřadit nesmí.
        $this->expectException(\InvalidArgumentException::class);
        $this->purchaseDoc($vendor, 'invoice', 'FA-B2-BAD', 100.0, 999999999);
    }

    private function client(string $name, string $dic, bool $isVendor): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "b2@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId,
            $isVendor ? 0 : 1, $isVendor ? 1 : 0]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    private function purchaseDoc(int $vendorId, string $kind, string $number, float $net, int $projectId): int
    {
        $date = self::YEAR . '-06-15';
        $id = $this->purchases->createDraft([
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $number,
            'document_kind'         => $kind,
            'issue_date'            => $date,
            'tax_date'              => $date,
            'due_date'              => $date,
            'received_at'           => $date,
            'currency_id'           => $this->currencyId,
            'project_id'            => $projectId,
            'items'                 => [],
        ], $this->userId, $this->supplierId);
        $this->piIds[] = $id;
        $this->purchases->replaceItems($id, [[
            'description'             => 'Položka ' . $number,
            'quantity'                => 1.0,
            'unit'                    => 'ks',
            'unit_price_without_vat'  => $net,
            'vat_rate_id'             => $this->vatRate21,
            'vat_classification_code' => '40',
            'order_index'             => 0,
        ]]);
        $this->calc->recompute($id);
        return $id;
    }

    private function saleInvoice(int $clientId, int $projectId, float $net, float $vat): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-06-20';
        $pdo->prepare(
            "INSERT INTO invoices (supplier_id, client_id, project_id, invoice_type, status,
                                   issue_date, tax_date, due_date, currency_id,
                                   total_without_vat, total_vat, total_with_vat, varsymbol)
             VALUES (?, ?, ?, 'invoice', 'issued', ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $this->supplierId, $clientId, $projectId, $date, $date, $date, $this->currencyId,
            $net, $vat, round($net + $vat, 2), 'B2' . self::YEAR,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;
        return $id;
    }
}
