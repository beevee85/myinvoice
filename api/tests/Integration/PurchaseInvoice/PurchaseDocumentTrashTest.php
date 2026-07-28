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

    private function insertPurchase(string $status, string $varsymbol, string $taxDate = '2098-07-10', string $kind = 'invoice'): int
    {
        $pdo = $this->db->pdo();
        $snapshot = json_encode(['company_name' => 'Koš test dodavatel s.r.o.', 'ic' => '87654321'], JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            // amount_to_pay je STORED generated (total_with_vat − advance_paid_amount) — nevkládá se.
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, status, total_without_vat, total_vat, total_with_vat,
                 vendor_snapshot, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 100, 21, 121, ?, ?)"
        )->execute([
            $this->supplierId, $this->vendorId, $varsymbol, 'VD-' . $varsymbol, $kind,
            $taxDate, $taxDate, $taxDate, $taxDate, $this->currencyId, $status, $snapshot, $this->userId,
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

    /**
     * Regrese po merge s DDKPZ (audit 2026-07-28): párovací cesta § 37a musí koš
     * respektovat obousměrně — DDKPZ v koši nesmí jít napárovat na živou konečnou
     * fakturu ani naopak, jinak by odpočet § 37a snížil DPH/náklady bez protistrany
     * ve výkazech (doklad v koši je z nich vyloučený).
     */
    public function testSettlementLinkRejectsTrashedDocumentsBothDirections(): void
    {
        $c = Bootstrap::buildApp()->getContainer();
        $settlement = $c->get(\MyInvoice\Service\Invoice\PurchaseSettlementService::class);

        // (1) DDKPZ v koši → živá konečná faktura
        $taxDoc = $this->insertPurchase('received', 'TRPF2098DD1', '2098-07-10', 'tax_document');
        $final  = $this->insertPurchase('received', 'TRPF2098FN1', '2098-07-10');
        $this->trash($taxDoc);

        try {
            $settlement->link($final, $taxDoc, $this->supplierId, false);
            self::fail('Párování s DDKPZ v koši musí selhat.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('koši', $e->getMessage());
        }
        self::assertNull(
            $this->db->pdo()->query("SELECT settled_by_purchase_invoice_id FROM purchase_invoices WHERE id = {$taxDoc}")->fetchColumn() ?: null,
            'Doklad v koši nesmí dostat vazbu (mutace read-only řádku).',
        );

        // (2) živý DDKPZ → konečná faktura v koši
        $taxDoc2 = $this->insertPurchase('received', 'TRPF2098DD2', '2098-07-10', 'tax_document');
        $final2  = $this->insertPurchase('received', 'TRPF2098FN2', '2098-07-10');
        $this->trash($final2);

        try {
            $settlement->link($final2, $taxDoc2, $this->supplierId, false);
            self::fail('Párování na konečnou fakturu v koši musí selhat.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('koši', $e->getMessage());
        }
    }

    /** Mutační akce nad dokladem v koši vrací 409 in_trash (TrashGuard). */
    public function testSettlementActionsBlockedForTrashedInvoice(): void
    {
        $c = Bootstrap::buildApp()->getContainer();
        $link = $c->get(\MyInvoice\Action\PurchaseInvoice\LinkSettlementDocPurchaseInvoiceAction::class);
        $unlink = $c->get(\MyInvoice\Action\PurchaseInvoice\UnlinkSettlementDocPurchaseInvoiceAction::class);

        $final  = $this->insertPurchase('received', 'TRPF2098GRD', '2098-07-10');
        $taxDoc = $this->insertPurchase('received', 'TRPF2098GRT', '2098-07-10', 'tax_document');
        $this->trash($final);

        $resp = ($link)(
            $this->request('POST', "/api/purchase-invoices/{$final}/link-settlement-doc", 'admin',
                ['tax_document_id' => $taxDoc]),
            new Psr7Response(), ['id' => (string) $final],
        );
        self::assertSame(409, $resp->getStatusCode());
        self::assertSame('in_trash', self::json($resp)['error']['code']);

        $resp2 = ($unlink)(
            $this->request('DELETE', "/api/purchase-invoices/{$final}/link-settlement-doc", 'admin',
                ['tax_document_id' => $taxDoc]),
            new Psr7Response(), ['id' => (string) $final],
        );
        self::assertSame(409, $resp2->getStatusCode());
        self::assertSame('in_trash', self::json($resp2)['error']['code']);
    }

    /** Kandidátské dotazy párování nesmí nabízet doklady z koše. */
    public function testSettlementCandidatesExcludeTrashed(): void
    {
        $final  = $this->insertPurchase('received', 'TRPF2098CN1', '2098-07-10');
        $taxDoc = $this->insertPurchase('received', 'TRPF2098CN2', '2098-07-10', 'tax_document');

        $ids = fn (array $rows): array => array_map(static fn (array $r): int => (int) $r['id'], $rows);
        self::assertContains($taxDoc, $ids($this->repo->settlementDocCandidates($final, $this->supplierId)));
        self::assertContains($final, $ids($this->repo->finalCandidates($taxDoc, $this->supplierId)));

        $this->trash($taxDoc);
        self::assertNotContains($taxDoc, $ids($this->repo->settlementDocCandidates($final, $this->supplierId)),
            'DDKPZ v koši se nesmí nabízet k párování.');

        $this->trash($final);
        self::assertNotContains($final, $ids($this->repo->finalCandidates($taxDoc, $this->supplierId)),
            'Konečná faktura v koši se nesmí nabízet k párování.');
    }

    /** Propojení se zálohou (i z AI návrhu) musí koš respektovat. */
    public function testLinkAdvanceRejectsTrashedAdvance(): void
    {
        $advance = $this->insertPurchase('received', 'TRPF2098ADV', '2098-07-10', 'advance');
        $final   = $this->insertPurchase('received', 'TRPF2098FIN', '2098-07-10');
        $this->trash($advance);

        try {
            $this->repo->linkAdvance($final, $advance, $this->supplierId);
            self::fail('Propojení se zálohou v koši musí selhat.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('koši', $e->getMessage());
        }
        self::assertNull(
            $this->db->pdo()->query("SELECT advance_purchase_invoice_id FROM purchase_invoices WHERE id = {$final}")->fetchColumn() ?: null,
        );
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
