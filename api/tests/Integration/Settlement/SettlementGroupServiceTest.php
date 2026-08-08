<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settlement;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseSettlementService;
use MyInvoice\Service\Settlement\SettlementGroupService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FORK 0922 — SettlementGroupService (Dokument 1, B1 + akceptační kritérium G:
 * doklady případu se zobrazí jako skupina s celkovou hodnotou plnění, ne nula).
 *
 * Scénář zrcadlí reálný případ A (TUkas): 2 zálohy + 2 DDKPZ + konečná na 0 Kč.
 * Izolováno v roce 2096 pod existujícím supplierem, uklizeno v tearDown.
 */
#[Group('integration')]
final class SettlementGroupServiceTest extends TestCase
{
    private const YEAR = 2096;

    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private PurchaseInvoiceCalculator $calc;
    private PurchaseSettlementService $settlement;
    private SettlementGroupService $groups;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRate21 = 0;
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
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db         = $c->get(Connection::class);
            $this->repo       = $c->get(PurchaseInvoiceRepository::class);
            $this->calc       = $c->get(PurchaseInvoiceCalculator::class);
            $this->settlement = $c->get(PurchaseSettlementService::class);
            $this->groups     = $c->get(SettlementGroupService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRate21  = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code='CZ-21' LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRate21 === 0
            || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('UPDATE purchase_invoices SET settled_by_purchase_invoice_id = NULL,
                                  advance_purchase_invoice_id = NULL, settlement_group_id = NULL WHERE id = ?')->execute([$id]);
        }
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM settlement_groups WHERE counterparty_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testCaseChainBecomesSingleGroupWithGrossValue(): void
    {
        $vendor = $this->vendor('Autosalon Beta a.s.', 'CZ20960001');

        // Zálohy (nedaňové výzvy) — u nás mimo DPH; pro účel skupin stačí kind.
        $adv1 = $this->draft($vendor, 'advance', 'ZA-1', 41322.31, '04-10');
        $adv2 = $this->draft($vendor, 'advance', 'ZA-2', 372396.69, '06-01');

        // DDKPZ čerpají zálohy (advance_purchase_invoice_id), konečná je zúčtuje.
        $dd1 = $this->draft($vendor, 'tax_document', 'ZD-1', 41322.31, '05-05');
        $dd2 = $this->draft($vendor, 'tax_document', 'ZD-2', 372396.69, '06-05');
        $final = $this->draft($vendor, 'invoice', 'FA-1', 413719.01, '06-20');

        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$adv1, $dd1]);
        $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$adv2, $dd2]);
        foreach ([$dd1, $dd2] as $d) {
            $pdo->prepare("UPDATE purchase_invoices SET status='received' WHERE id=?")->execute([$d]);
        }
        $this->settlement->link($final, $dd1, $this->supplierId, true);
        $this->settlement->link($final, $dd2, $this->supplierId, true);

        $this->groups->rebuildForSupplier($this->supplierId, 'purchase');

        $stmt = $pdo->prepare(
            'SELECT id, status, total_amount, final_document_id, counterparty_id
               FROM settlement_groups WHERE supplier_id = ? AND counterparty_id = ?'
        );
        $stmt->execute([$this->supplierId, $vendor]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(1, $rows, 'Celý řetězec (2 zálohy + 2 DDKPZ + konečná) = JEDNA skupina.');
        $g = $rows[0];
        self::assertSame('settled', $g['status'], 'Konečná faktura zúčtovává všechny DDKPZ → settled.');
        self::assertSame($final, (int) $g['final_document_id']);
        // Hlavní řádek nese hodnotu plnění (Σ DDKPZ + zbytek na konečné = 500 600), ne nulu (C2).
        self::assertEqualsWithDelta(500600.00, (float) $g['total_amount'], 0.05,
            'total_amount = celková hodnota plnění vč. DPH, nikdy 0.');

        // Role členů.
        $roles = [];
        $q = $pdo->prepare('SELECT id, settlement_role FROM purchase_invoices WHERE settlement_group_id = ?');
        $q->execute([(int) $g['id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $roles[(int) $r['id']] = $r['settlement_role'];
        }
        self::assertSame('advance', $roles[$adv1] ?? null);
        self::assertSame('advance', $roles[$adv2] ?? null);
        self::assertSame('advance_tax_document', $roles[$dd1] ?? null);
        self::assertSame('advance_tax_document', $roles[$dd2] ?? null);
        self::assertSame('final', $roles[$final] ?? null);

        // Chain pro stepper: 5 kroků seřazených dle data.
        $chain = $this->groups->chainForDocument($this->supplierId, 'purchase', $dd1);
        self::assertCount(5, $chain);
        self::assertSame([$adv1, $dd1, $adv2, $dd2, $final], array_map(
            static fn (array $s): int => (int) $s['id'], $chain));

        // Idempotence: druhý rebuild nesmí založit novou skupinu.
        $this->groups->rebuildForSupplier($this->supplierId, 'purchase');
        $stmt->execute([$this->supplierId, $vendor]);
        self::assertCount(1, $stmt->fetchAll(PDO::FETCH_ASSOC), 'Rebuild je idempotentní.');
    }

    public function testOpenGroupWithoutFinalAndUnlinkRemovesGroup(): void
    {
        $vendor = $this->vendor('Autosalon Gama s.r.o.', 'CZ20960002');
        $adv = $this->draft($vendor, 'advance', 'ZA-9', 16528.93, '05-02');
        $dd  = $this->draft($vendor, 'tax_document', 'ZD-9', 16528.93, '05-06');

        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$adv, $dd]);

        $this->groups->rebuildForSupplier($this->supplierId, 'purchase');
        $stmt = $pdo->prepare('SELECT id, status, final_document_id FROM settlement_groups WHERE supplier_id = ? AND counterparty_id = ?');
        $stmt->execute([$this->supplierId, $vendor]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame('open', $rows[0]['status'], 'Bez konečné faktury je skupina Otevřeno (C3).');
        self::assertNull($rows[0]['final_document_id']);

        // Zrušení vazby → doklady zpět mezi samostatné, skupina zmizí.
        $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = NULL WHERE id = ?')
            ->execute([$dd]);
        $this->groups->rebuildForSupplier($this->supplierId, 'purchase');
        $stmt->execute([$this->supplierId, $vendor]);
        self::assertCount(0, $stmt->fetchAll(PDO::FETCH_ASSOC));
        $role = $pdo->prepare('SELECT settlement_group_id, settlement_role FROM purchase_invoices WHERE id = ?');
        $role->execute([$dd]);
        $r = $role->fetch(PDO::FETCH_ASSOC);
        self::assertNull($r['settlement_group_id']);
        self::assertNull($r['settlement_role']);
    }

    private function draft(int $vendorId, string $kind, string $number, float $net, string $monthDay): int
    {
        $date = self::YEAR . '-' . $monthDay;
        $id = $this->repo->createDraft([
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $number . '-' . $vendorId,
            'document_kind'         => $kind,
            'issue_date'            => $date,
            'tax_date'              => $date,
            'due_date'              => $date,
            'received_at'           => $date,
            'currency_id'           => $this->currencyId,
            'items'                 => [],
        ], $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        $this->repo->replaceItems($id, [[
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
