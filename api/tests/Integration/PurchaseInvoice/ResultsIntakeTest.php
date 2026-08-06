<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseImportBatchRepository;
use MyInvoice\Service\PurchaseBatchImport\BatchLimitException;
use MyInvoice\Service\PurchaseBatchImport\ResultsIntake;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — příjem results.json (Commit 13).
 *
 * Těžiště: token je DRUHÝ FAKTOR, ne autentizace, a manifest se čte
 * z databáze, ne z požadavku. Kdyby si odesílatel mohl manifest určit sám,
 * kontroly V4 a V6 by neměly proti čemu porovnávat.
 */
#[Group('integration')]
final class ResultsIntakeTest extends TestCase
{
    private Connection $db;
    private PurchaseImportBatchRepository $repo;
    private ResultsIntake $intake;

    private int $supplierA = 0;
    private int $supplierB = 0;

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
    }

    protected function tearDown(): void
    {
        if ($this->batchIds === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($this->batchIds), '?'));
        $this->db->pdo()->prepare("DELETE FROM purchase_import_batches WHERE id IN ({$in})")
            ->execute($this->batchIds);
        $this->batchIds = [];
    }

    /** @return array{0: int, 1: string} id dávky a plaintext tokenu */
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

    private function tenantOf(int $supplierId): array
    {
        $s = $this->db->pdo()->prepare('SELECT ic, dic FROM supplier WHERE id = ?');
        $s->execute([$supplierId]);
        $r = $s->fetch(\PDO::FETCH_ASSOC) ?: [];

        return ['ic' => (string) ($r['ic'] ?? ''), 'dic' => (string) ($r['dic'] ?? '')];
    }

    private function payload(array $shas): string
    {
        $docs = [];
        foreach ($shas as $sha) {
            $docs[] = [
                'sha256'        => $sha,
                'document_kind' => 'invoice',
                'vendor'        => ['company_name' => 'Dodavatel s.r.o.', 'ic' => '00000019'],
                'customer_matches_tenant' => true,
                'numbers'       => ['vendor_invoice_number' => '2026001'],
                'dates'         => ['issue_date' => '2026-07-01', 'due_date' => '2026-07-15'],
                'items'         => [[
                    'description' => 'Zboží', 'quantity' => '1',
                    'unit_price_without_vat' => '100.00', 'line_base' => '100.00',
                ]],
                'totals'        => ['base' => '100.00', 'vat' => '21.00', 'total' => '121.00'],
            ];
        }

        return json_encode(
            ['schema' => 'myinvoice.purchase-import-batch.results/1', 'documents' => $docs],
            JSON_THROW_ON_ERROR,
        );
    }

    private function accept(int $id, int $supplierId, string $token, string $raw): array
    {
        return $this->intake->accept(
            $id, $supplierId, hash('sha256', $token), $raw,
            $this->tenantOf($supplierId), '2026-08-06',
        );
    }

    private function statusOf(int $id, int $supplierId): string
    {
        return (string) ($this->repo->find($id, $supplierId)['status'] ?? '');
    }

    // -----------------------------------------------------------------------
    // Token jako druhý faktor
    // -----------------------------------------------------------------------

    public function testAcceptsValidResults(): void
    {
        [$id, $token] = $this->makeBatch($this->supplierA);
        $r = $this->accept($id, $this->supplierA, $token, $this->payload([self::SHA_A]));

        self::assertTrue($r['ok'], json_encode($r['findings'], JSON_UNESCAPED_UNICODE));
        self::assertSame('validating', $this->statusOf($id, $this->supplierA));
    }

    /** Shoda hashe sama nesmí stačit — tenant se ověřuje spolu s tokenem. */
    public function testTokenFromForeignTenantIsRejected(): void
    {
        [$id, $token] = $this->makeBatch($this->supplierA);

        try {
            $this->accept($id, $this->supplierB, $token, $this->payload([self::SHA_A]));
            self::fail('cizí tenant nesmí dávku přijmout ani se správným tokenem');
        } catch (BatchLimitException $e) {
            self::assertSame('invalid_batch_token', $e->reasonCode());
        }
    }

    public function testWrongTokenIsRejected(): void
    {
        [$id] = $this->makeBatch($this->supplierA);

        try {
            $this->accept($id, $this->supplierA, 'jiny-token', $this->payload([self::SHA_A]));
            self::fail('špatný token musí být odmítnut');
        } catch (BatchLimitException $e) {
            self::assertSame('invalid_batch_token', $e->reasonCode());
        }
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = bin2hex(random_bytes(16));
        $id = $this->repo->create(
            $this->supplierA, null, hash('sha256', $token), date('Y-m-d H:i:s', time() - 60),
        );
        $this->batchIds[] = $id;
        $this->repo->addFile($id, $this->supplierA, 'f.pdf', 'x.pdf', 10, self::SHA_A, 'application/pdf');

        try {
            $this->accept($id, $this->supplierA, $token, $this->payload([self::SHA_A]));
            self::fail('expirovaný token musí být odmítnut');
        } catch (BatchLimitException $e) {
            self::assertSame('invalid_batch_token', $e->reasonCode());
        }
    }

    /** Druhé odeslání by přepsalo výsledek prvního. */
    public function testAlreadyProcessedBatchDoesNotAcceptResultsAgain(): void
    {
        [$id, $token] = $this->makeBatch($this->supplierA);
        $this->accept($id, $this->supplierA, $token, $this->payload([self::SHA_A]));

        try {
            $this->accept($id, $this->supplierA, $token, $this->payload([self::SHA_A]));
            self::fail('dávka po zpracování už výsledky přijímat nesmí');
        } catch (BatchLimitException $e) {
            self::assertSame('batch_not_accepting', $e->reasonCode());
        }
    }

    // -----------------------------------------------------------------------
    // Manifest se čte z databáze, ne z požadavku
    // -----------------------------------------------------------------------

    /**
     * Odesílatel nesmí manifest ovlivnit. Když pošle doklad, který v dávce
     * není, musí to spadnout na V4 — bez ohledu na to, co v payloadu tvrdí.
     */
    public function testResultsForFileNotInBatchAreRejected(): void
    {
        [$id, $token] = $this->makeBatch($this->supplierA, [self::SHA_A]);
        $r = $this->accept($id, $this->supplierA, $token, $this->payload([self::SHA_B]));

        self::assertFalse($r['ok']);
        self::assertContains('V4', array_column($r['findings'], 'rule'));
        self::assertSame('failed', $this->statusOf($id, $this->supplierA));
    }

    public function testIncompleteResultsMarkBatchFailed(): void
    {
        [$id, $token] = $this->makeBatch($this->supplierA, [self::SHA_A, self::SHA_B]);
        $r = $this->accept($id, $this->supplierA, $token, $this->payload([self::SHA_A]));

        self::assertFalse($r['ok']);
        self::assertContains('V6', array_column($r['findings'], 'rule'));
    }

    // -----------------------------------------------------------------------
    // Retence a stopa
    // -----------------------------------------------------------------------

    /** V79b, cesta A — terminální stav znamená, že raw_json jde pryč. */
    public function testFailedBatchPurgesRawJson(): void
    {
        [$id, $token] = $this->makeBatch($this->supplierA, [self::SHA_A]);
        $this->accept($id, $this->supplierA, $token, $this->payload([self::SHA_B]));

        $stmt = $this->db->pdo()->prepare(
            'SELECT raw_json, raw_purged_at FROM purchase_import_batch_results
              WHERE purchase_import_batch_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        self::assertNull($row['raw_json'], 'obsah cizích dokladů se po selhání nedrží');
        self::assertNotNull($row['raw_purged_at'], 'úklid musí nechat stopu');
    }

    /** I neúspěšná validace se uloží — jinak uživatel nevidí PROČ. */
    public function testFindingsArePersistedEvenWhenValidationFails(): void
    {
        [$id, $token] = $this->makeBatch($this->supplierA, [self::SHA_A]);
        $this->accept($id, $this->supplierA, $token, $this->payload([self::SHA_B]));

        $stmt = $this->db->pdo()->prepare(
            'SELECT status, findings_json FROM purchase_import_batch_results
              WHERE purchase_import_batch_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        self::assertSame('rejected', $row['status']);
        self::assertIsString($row['findings_json']);
    }

    /**
     * Řádek souboru nesmí tvrdit `validated`, když selhala DÁVKA — i když sám
     * žádný nález nemá.
     *
     * Odhaleno pádem testu výš: nálezy V4 (soubor, který v dávce není) a V6
     * (chybějící soubor) se k žádnému ZNÁMÉMU souboru nepřiřadí, takže řádek
     * zůstal bez nálezů a hlásil `validated` u dávky, která neprošla. Vypadal
     * by hotově — což je horší než chyba, protože se na něj dá spolehnout.
     */
    public function testNoFileRowClaimsValidatedWhenBatchFailed(): void
    {
        [$id, $token] = $this->makeBatch($this->supplierA, [self::SHA_A]);
        $r = $this->accept($id, $this->supplierA, $token, $this->payload([self::SHA_B]));

        self::assertFalse($r['ok'], 'předpoklad testu: dávka musí selhat');

        $stmt = $this->db->pdo()->prepare(
            'SELECT status FROM purchase_import_batch_results WHERE purchase_import_batch_id = ?'
        );
        $stmt->execute([$id]);

        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $status) {
            self::assertNotSame('validated', $status,
                'u neúspěšné dávky nesmí žádný řádek vypadat hotově');
        }
    }

    public function testAcceptWritesHeartbeat(): void
    {
        [$id, $token] = $this->makeBatch($this->supplierA);
        self::assertNull($this->repo->find($id, $this->supplierA)['heartbeat_at']);

        $this->accept($id, $this->supplierA, $token, $this->payload([self::SHA_A]));

        self::assertNotNull($this->repo->find($id, $this->supplierA)['heartbeat_at'],
            'od příjmu výsledků sweeper ví, že se na dávce pracuje');
    }

    // -----------------------------------------------------------------------
    // Sweeper opuštěných dávek (V79b, cesta B)
    // -----------------------------------------------------------------------

    public function testSweepMarksAbandonedBatchFailedAndPurges(): void
    {
        [$id] = $this->makeBatch($this->supplierA);
        $this->db->pdo()->prepare(
            'UPDATE purchase_import_batches SET heartbeat_at = (current_timestamp() - INTERVAL 48 HOUR) WHERE id = ?'
        )->execute([$id]);

        $r = $this->intake->sweepAbandoned($this->supplierA, 24);

        self::assertGreaterThanOrEqual(1, $r['swept']);
        self::assertSame('failed', $this->statusOf($id, $this->supplierA));
    }

    public function testSweepIsTenantScoped(): void
    {
        [$id] = $this->makeBatch($this->supplierA);
        $this->db->pdo()->prepare(
            'UPDATE purchase_import_batches SET heartbeat_at = (current_timestamp() - INTERVAL 48 HOUR) WHERE id = ?'
        )->execute([$id]);

        $this->intake->sweepAbandoned($this->supplierB, 24);

        self::assertNotSame('failed', $this->statusOf($id, $this->supplierA),
            'sweeper jednoho tenanta nesmí sáhnout na dávku druhého');
    }

    public function testLiveLongRunningBatchIsNotSwept(): void
    {
        [$id] = $this->makeBatch($this->supplierA);
        $this->repo->heartbeat($id, $this->supplierA);

        $this->intake->sweepAbandoned($this->supplierA, 24);

        self::assertNotSame('failed', $this->statusOf($id, $this->supplierA),
            'běžící dlouhá dávka se známkou života se smazat nesmí');
    }
}
