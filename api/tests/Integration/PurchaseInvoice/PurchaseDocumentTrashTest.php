<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\DeletePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\EmptyPurchaseInvoiceTrashAction;
use MyInvoice\Action\PurchaseInvoice\ForceDeletePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\RestorePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Koš + trvalé mazání dokladů (0905), přijaté faktury — spec §10:
 *   - stornovaný (dřív neodstranitelný) doklad jde do koše a z koše natvrdo pryč,
 *   - uvolnění purchase counteru jen u posledního čísla řady,
 *   - vysypání koše se smíšeným obsahem smaže povolené a blokované přeskočí,
 *   - doklad v koši je mimo listGroupedByMonth (a tedy mimo Náklady/DPH podklady).
 *
 * Izolace v roce 2098; úklid v tearDown.
 */
#[Group('integration')]
final class PurchaseDocumentTrashTest extends TestCase
{
    private Connection $db;
    private DeletePurchaseInvoiceAction $delete;
    private RestorePurchaseInvoiceAction $restore;
    private ForceDeletePurchaseInvoiceAction $force;
    private EmptyPurchaseInvoiceTrashAction $empty;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    /** @var int[] */
    private array $created = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db      = $c->get(Connection::class);
            $this->delete  = $c->get(DeletePurchaseInvoiceAction::class);
            $this->restore = $c->get(RestorePurchaseInvoiceAction::class);
            $this->force   = $c->get(ForceDeletePurchaseInvoiceAction::class);
            $this->empty   = $c->get(EmptyPurchaseInvoiceTrashAction::class);
            $this->repo    = $c->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/CZK/admin user.');
        }
        if (!(bool) $pdo->query("SHOW COLUMNS FROM purchase_invoices LIKE 'deleted_at'")->fetch()) {
            $this->markTestSkipped('Migrace 0905 (deleted_at) není aplikovaná.');
        }
        $this->cleanup();
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1)'
        )->execute([$this->supplierId, 'Koš test dodavatel s.r.o.', 'Testovací 3', 'Brno', '60200', $czId, 'trash-vendor@example.com', 'cs', $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET doc_trash_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM activity_log WHERE entity_type = ? AND entity_id = ?')->execute(['purchase_invoice', $id]);
            $pdo->prepare('DELETE FROM deleted_document_snapshots WHERE entity_type = ? AND entity_id = ?')->execute(['purchase_invoice', $id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        $pdo->prepare("DELETE FROM purchase_invoice_counters WHERE supplier_id = ? AND period IN ('209807', '2098', 'ALL')")
            ->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM tax_submissions WHERE supplier_id = ? AND period_year = 2098')->execute([$this->supplierId]);
        if ($this->vendorId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->vendorId]);
            $this->vendorId = 0;
        }
    }

    private function insertPurchase(string $status, string $varsymbol, string $taxDate = '2098-07-10'): int
    {
        $pdo = $this->db->pdo();
        $snapshot = json_encode(['company_name' => 'Koš test dodavatel s.r.o.', 'ic' => '87654321'], JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_vat, total_with_vat, amount_to_pay, vendor_snapshot, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 100, 21, 121, 121, ?, ?)"
        )->execute([
            $this->supplierId, $this->vendorId, $varsymbol, 'VD-' . $varsymbol,
            $taxDate, $taxDate, $taxDate, $this->currencyId, $status, $snapshot, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->created[] = $id;
        return $id;
    }

    private function request(string $method, string $path, string $role, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private static function json(Psr7Response $resp): array
    {
        $resp->getBody()->rewind();
        return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function trash(int $id, string $role = 'admin'): Psr7Response
    {
        return ($this->delete)(
            $this->request('DELETE', "/api/purchase-invoices/{$id}", $role,
                ['reason' => 'Testovací smazání přijatého dokladu']),
            new Psr7Response(),
            ['id' => (string) $id],
        );
    }

    // ------------------------------------------------------------------

    public function testCancelledDocumentCanBeTrashedAndForceDeleted(): void
    {
        // Přesně scénář BEKRON/Tichý: stornovaný doklad dřív nešel smazat vůbec.
        $id = $this->insertPurchase('cancelled', 'TRPF2098001');
        $resp = $this->trash($id);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());

        $force = ($this->force)(
            $this->request('DELETE', "/api/purchase-invoices/{$id}/force", 'admin',
                ['reason' => 'Testovací trvalé smazání storna', 'confirm_number' => 'TRPF2098001']),
            new Psr7Response(),
            ['id' => (string) $id],
        );
        self::assertSame(200, $force->getStatusCode(), (string) $force->getBody());
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM purchase_invoices WHERE id = {$id}"
        )->fetchColumn());
        // Snapshot + audit purchase_invoice.force_deleted
        $n = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM deleted_document_snapshots WHERE entity_type = 'purchase_invoice' AND entity_id = {$id}"
        )->fetchColumn();
        self::assertSame(1, $n);
    }

    public function testPurchaseCounterReleasedOnlyForLastNumber(): void
    {
        $pdo = $this->db->pdo();
        // Výchozí šablona {PP}{YY}{MM}{CCC} → PF2807… pro období 7/2098? Pozor: {YY}=98.
        $tpl = PurchaseInvoiceRepository::PURCHASE_DEFAULT_TEMPLATE;
        $sup = (string) ($pdo->query("SELECT purchase_invoice_number_format FROM supplier WHERE id = {$this->supplierId}")->fetchColumn() ?: '');
        if ($sup !== '' && $sup !== $tpl) {
            $this->markTestSkipped('Supplier má vlastní šablonu přijaté řady — test počítá s defaultem.');
        }
        $vs1 = 'PF9807001';
        $vs2 = 'PF9807002';
        $id1 = $this->insertPurchase('received', $vs1);
        $id2 = $this->insertPurchase('received', $vs2);
        $pdo->prepare(
            "INSERT INTO purchase_invoice_counters (supplier_id, period, last_number) VALUES (?, '209807', 2)
             ON DUPLICATE KEY UPDATE last_number = 2"
        )->execute([$this->supplierId]);

        // Prostřední číslo → mezera, counter drží.
        $this->trash($id1);
        $r1 = ($this->force)(
            $this->request('DELETE', "/api/purchase-invoices/{$id1}/force", 'admin',
                ['reason' => 'Test mezery v řadě přijatých', 'confirm_number' => $vs1]),
            new Psr7Response(), ['id' => (string) $id1],
        );
        self::assertSame(200, $r1->getStatusCode(), (string) $r1->getBody());
        self::assertFalse(self::json($r1)['counter_released']);
        self::assertSame($vs1, self::json($r1)['numbering_gap']);
        self::assertSame(2, (int) $pdo->query(
            "SELECT last_number FROM purchase_invoice_counters WHERE supplier_id = {$this->supplierId} AND period = '209807'"
        )->fetchColumn());

        // Poslední číslo → counter klesne.
        $this->trash($id2);
        $r2 = ($this->force)(
            $this->request('DELETE', "/api/purchase-invoices/{$id2}/force", 'admin',
                ['reason' => 'Test uvolnění čísla přijatých', 'confirm_number' => $vs2]),
            new Psr7Response(), ['id' => (string) $id2],
        );
        self::assertSame(200, $r2->getStatusCode(), (string) $r2->getBody());
        self::assertTrue(self::json($r2)['counter_released']);
        self::assertSame(1, (int) $pdo->query(
            "SELECT last_number FROM purchase_invoice_counters WHERE supplier_id = {$this->supplierId} AND period = '209807'"
        )->fetchColumn());
    }

    public function testEmptyTrashSkipsBlockedDocuments(): void
    {
        // POJISTKA: vysypání koše je supplier-wide — nad DB s reálnými doklady
        // v koši test přeskočíme, jinak by je nevratně smazal.
        $pre = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = {$this->supplierId} AND deleted_at IS NOT NULL"
        )->fetchColumn();
        if ($pre > 0) {
            $this->markTestSkipped('V koši jsou reálné doklady — vysypání nad nimi netestujeme.');
        }

        $freeId    = $this->insertPurchase('received', 'TRPF2098EMP', '2098-07-10');
        $blockedId = $this->insertPurchase('booked', 'TRPF2098BLK', '2098-08-20');
        $this->trash($freeId);
        $this->trash($blockedId);
        // Blokaci vytvoř AŽ PO přesunu do koše: podání KH za 8/2098.
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, xml_content, xml_size_bytes, xml_sha256)
             VALUES (?, 'dphkh1', 2098, 8, '<x/>', 4, ?)"
        )->execute([$this->supplierId, str_repeat('b', 64)]);

        $resp = ($this->empty)(
            $this->request('POST', '/api/purchase-invoices/trash/empty', 'admin',
                ['reason' => 'Testovací vysypání koše přijatých']),
            new Psr7Response(),
        );
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $body = self::json($resp);

        $deletedIds = array_column($body['deleted'], 'id');
        $skippedIds = array_column($body['skipped'], 'id');
        self::assertContains($freeId, $deletedIds, 'Volný doklad se při vysypání smaže.');
        self::assertContains($blockedId, $skippedIds, 'Blokovaný doklad se přeskočí, dávka nespadne.');
        self::assertSame('blocked_vat_period', $body['skipped'][array_search($blockedId, $skippedIds, true)]['blockers'][0]['code']);

        // Blokovaný zůstal v koši.
        $row = $this->db->pdo()->query("SELECT deleted_at FROM purchase_invoices WHERE id = {$blockedId}")->fetch(PDO::FETCH_ASSOC);
        self::assertNotNull($row['deleted_at']);
    }

    public function testTrashedPurchaseExcludedFromList(): void
    {
        $id = $this->insertPurchase('received', 'TRPF2098LST');
        $inList = fn (bool $trash): bool => (bool) array_filter(
            array_merge(...array_map(
                static fn (array $g): array => $g['invoices'],
                $this->repo->listGroupedByMonth(['supplier_id' => $this->supplierId, 'year' => 2098, 'trash' => $trash])['data'],
            )),
            static fn (array $i): bool => (int) $i['id'] === $id,
        );
        self::assertTrue($inList(false));
        $this->trash($id);
        self::assertFalse($inList(false), 'Doklad v koši nesmí být v běžném listu (Náklady, přehledy).');
        self::assertTrue($inList(true), 'Koš doklad ukazuje.');

        // Obnova účetním — vrátí se se stejným číslem.
        $resp = ($this->restore)(
            $this->request('POST', "/api/purchase-invoices/{$id}/restore", 'accountant'),
            new Psr7Response(), ['id' => (string) $id],
        );
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $vs = (string) $this->db->pdo()->query("SELECT varsymbol FROM purchase_invoices WHERE id = {$id}")->fetchColumn();
        self::assertSame('TRPF2098LST', $vs);
    }
}
