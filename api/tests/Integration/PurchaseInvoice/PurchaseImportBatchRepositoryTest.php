<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseImportBatchRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — datová vrstva dávkového importu (migrace 0913).
 *
 * Těžiště testu není „metoda vrátí, co má". Těžiště je, že se NIKDY nedostane
 * k cizímu tenantovi a že úklid osobních údajů nesahá za rozsah své dávky.
 * Obojí jsou chyby, které v produkci nikdo nezpozoruje, dokud nezpůsobí škodu.
 *
 * Testuje pod DVĚMA suppliery, protože jednotenantní fixture tuhle třídu chyb
 * z principu neodhalí — přesně tak vznikla baseline smíchaná přes dvě firmy.
 */
#[Group('integration')]
final class PurchaseImportBatchRepositoryTest extends TestCase
{
    private Connection $db;
    private PurchaseImportBatchRepository $repo;

    private int $supplierA = 0;
    private int $supplierB = 0;

    /** @var int[] */
    private array $batchIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container  = Bootstrap::buildApp()->getContainer();
            $this->db   = $container->get(Connection::class);
            $this->repo = $container->get(PurchaseImportBatchRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $ids = $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 2')
            ->fetchAll(\PDO::FETCH_COLUMN);
        if (count($ids) < 2) {
            $this->markTestSkipped('Test vyžaduje dva tenanty — jednotenantní fixture cross-tenant chyby neodhalí.');
        }
        $this->supplierA = (int) $ids[0];
        $this->supplierB = (int) $ids[1];
    }

    protected function tearDown(): void
    {
        if ($this->batchIds === []) {
            return;
        }
        // FK jsou ON DELETE CASCADE, takže smazání hlavičky uklidí soubory i výsledky.
        $in = implode(',', array_fill(0, count($this->batchIds), '?'));
        $this->db->pdo()
            ->prepare("DELETE FROM purchase_import_batches WHERE id IN ({$in})")
            ->execute($this->batchIds);
        $this->batchIds = [];
    }

    private function makeBatch(int $supplierId, string $tokenSeed = 'seed'): int
    {
        $id = $this->repo->create(
            $supplierId,
            null,
            hash('sha256', $tokenSeed . '|' . $supplierId . '|' . microtime(true)),
            date('Y-m-d H:i:s', time() + 3600),
        );
        $this->batchIds[] = $id;

        return $id;
    }

    // -----------------------------------------------------------------------
    // Tenant scoping
    // -----------------------------------------------------------------------

    public function testFindDoesNotReachAcrossTenants(): void
    {
        $id = $this->makeBatch($this->supplierA);

        self::assertNotNull($this->repo->find($id, $this->supplierA), 'vlastní tenant dávku vidět musí');
        self::assertNull($this->repo->find($id, $this->supplierB), 'cizí tenant nesmí dávku vidět');
    }

    public function testSetStatusDoesNothingForForeignTenant(): void
    {
        $id = $this->makeBatch($this->supplierA);

        self::assertSame(0, $this->repo->setStatus($id, $this->supplierB, 'done'),
            'zápis pod cizím tenantem nesmí zasáhnout ani jeden řádek');

        $row = $this->repo->find($id, $this->supplierA);
        self::assertSame(PurchaseImportBatchRepository::STATUS_PENDING, $row['status'],
            'stav dávky se cizím zápisem nesmí změnit');
    }

    public function testHeartbeatDoesNothingForForeignTenant(): void
    {
        $id = $this->makeBatch($this->supplierA);

        self::assertSame(0, $this->repo->heartbeat($id, $this->supplierB));
        self::assertNull($this->repo->find($id, $this->supplierA)['heartbeat_at']);

        self::assertSame(1, $this->repo->heartbeat($id, $this->supplierA));
        self::assertNotNull($this->repo->find($id, $this->supplierA)['heartbeat_at']);
    }

    public function testListForTenantReturnsOnlyOwnBatches(): void
    {
        $a = $this->makeBatch($this->supplierA);
        $b = $this->makeBatch($this->supplierB);

        $idsA = array_column($this->repo->listForTenant($this->supplierA, 100), 'id');
        self::assertContains($a, $idsA);
        self::assertNotContains($b, $idsA, 'výpis nesmí obsahovat dávku jiné firmy');
    }

    public function testTokenLookupIsScopedToTenant(): void
    {
        $token = hash('sha256', 'token-scope-test-' . microtime(true));
        $id = $this->repo->create($this->supplierA, null, $token, date('Y-m-d H:i:s', time() + 3600));
        $this->batchIds[] = $id;

        self::assertNotNull($this->repo->findByToken($token, $this->supplierA));
        self::assertNull($this->repo->findByToken($token, $this->supplierB),
            'shoda hashe sama nesmí stačit — tenant se ověřuje spolu s tokenem');
    }

    public function testExpiredTokenIsNotAccepted(): void
    {
        $token = hash('sha256', 'expired-' . microtime(true));
        $id = $this->repo->create($this->supplierA, null, $token, date('Y-m-d H:i:s', time() - 60));
        $this->batchIds[] = $id;

        self::assertNull($this->repo->findByToken($token, $this->supplierA),
            'expirovaný token se nesmí přijmout');
    }

    // -----------------------------------------------------------------------
    // Retence — úklid nesmí sahat za svoji dávku
    // -----------------------------------------------------------------------

    public function testPurgeRawJsonTouchesOnlyItsOwnBatch(): void
    {
        $keep  = $this->makeBatch($this->supplierA, 'keep');
        $purge = $this->makeBatch($this->supplierA, 'purge');

        $this->seedResult($keep,  $this->supplierA, 'RAW-KEEP');
        $this->seedResult($purge, $this->supplierA, 'RAW-PURGE');

        self::assertSame(1, $this->repo->purgeRawJson($purge, $this->supplierA));

        self::assertNull($this->rawJsonOf($purge), 'vlastní dávka se smazat měla');
        self::assertNotNull($this->rawJsonOf($keep), 'druhá dávka musí zůstat nedotčená');
    }

    public function testPurgeRawJsonDoesNothingForForeignTenant(): void
    {
        $id = $this->makeBatch($this->supplierA);
        $this->seedResult($id, $this->supplierA, 'RAW-FOREIGN');

        self::assertSame(0, $this->repo->purgeRawJson($id, $this->supplierB));
        self::assertNotNull($this->rawJsonOf($id), 'cizí tenant nesmí smazat nic');
    }

    public function testPurgeRawJsonIsIdempotent(): void
    {
        $id = $this->makeBatch($this->supplierA);
        $this->seedResult($id, $this->supplierA, 'RAW-IDEM');

        self::assertSame(1, $this->repo->purgeRawJson($id, $this->supplierA));
        self::assertSame(0, $this->repo->purgeRawJson($id, $this->supplierA),
            'druhý běh nemá co mazat — purge musí být idempotentní');
    }

    // -----------------------------------------------------------------------
    // Sweeper opuštěných dávek
    // -----------------------------------------------------------------------

    public function testAbandonedSweepIgnoresLiveBatch(): void
    {
        $live = $this->makeBatch($this->supplierA);
        $this->repo->heartbeat($live, $this->supplierA);

        $ids = array_column($this->repo->findAbandoned($this->supplierA, 24), 'id');
        self::assertNotContains($live, $ids,
            'běžící dávka se známkou života se nesmí označit za opuštěnou');
    }

    public function testAbandonedSweepFindsStaleBatchAndIsTenantScoped(): void
    {
        $stale = $this->makeBatch($this->supplierA);
        $this->db->pdo()->prepare(
            'UPDATE purchase_import_batches
                SET heartbeat_at = (current_timestamp() - INTERVAL 48 HOUR)
              WHERE id = ?'
        )->execute([$stale]);

        $ids = array_column($this->repo->findAbandoned($this->supplierA, 24), 'id');
        self::assertContains($stale, $ids, 'dávka bez známky života 48 h je opuštěná');

        $idsB = array_column($this->repo->findAbandoned($this->supplierB, 24), 'id');
        self::assertNotContains($stale, $idsB, 'sweeper nesmí vidět dávky jiné firmy');
    }

    public function testTerminalBatchIsNeverSweptAsAbandoned(): void
    {
        foreach (PurchaseImportBatchRepository::TERMINAL_STATUSES as $status) {
            $id = $this->makeBatch($this->supplierA, 'terminal-' . $status);
            $this->repo->setStatus($id, $this->supplierA, $status);
            $this->db->pdo()->prepare(
                'UPDATE purchase_import_batches
                    SET heartbeat_at = (current_timestamp() - INTERVAL 48 HOUR)
                  WHERE id = ?'
            )->execute([$id]);

            $ids = array_column($this->repo->findAbandoned($this->supplierA, 24), 'id');
            self::assertNotContains($id, $ids,
                "dávka ve stavu '{$status}' je hotová, ne opuštěná — sweeper ji brát nesmí");
        }
    }

    // -----------------------------------------------------------------------

    private function seedResult(int $batchId, int $supplierId, string $marker): void
    {
        $fileId = $this->repo->addFile(
            $batchId, $supplierId,
            $marker . '.pdf', 'stored-' . $marker . '.pdf',
            1024, hash('sha256', $marker . microtime(true)), 'application/pdf',
        );
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_import_batch_results
                 (purchase_import_batch_id, purchase_import_batch_file_id, supplier_id, raw_json, normalized_json)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$batchId, $fileId, $supplierId, '{"marker":"' . $marker . '"}', '{"n":1}']);
    }

    private function rawJsonOf(int $batchId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT raw_json FROM purchase_import_batch_results WHERE purchase_import_batch_id = ? LIMIT 1'
        );
        $stmt->execute([$batchId]);
        $v = $stmt->fetchColumn();

        return $v === false || $v === null ? null : (string) $v;
    }
}
