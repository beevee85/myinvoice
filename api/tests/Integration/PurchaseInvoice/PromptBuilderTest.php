<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseImportBatchRepository;
use MyInvoice\Service\PurchaseBatchImport\BatchLimitException;
use MyInvoice\Service\PurchaseBatchImport\PromptBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — Commit 10: generátor promptu.
 *
 * Prompt odchází ze systému, takže se testuje i to, co v něm být NESMÍ —
 * ne jen to, co v něm být má. Kontrola „obsahuje očekávané" sama o sobě
 * nezachytí, že tam uniklo něco navíc.
 */
#[Group('integration')]
final class PromptBuilderTest extends TestCase
{
    private Connection $db;
    private PurchaseImportBatchRepository $repo;
    private PromptBuilder $builder;

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
            $c             = Bootstrap::buildApp()->getContainer();
            $this->db      = $c->get(Connection::class);
            $this->repo    = $c->get(PurchaseImportBatchRepository::class);
            $this->builder = $c->get(PromptBuilder::class);
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

    /** @param list<array{0: string, 1: string}> $files [původní název, obsah] */
    private function batchWith(int $supplierId, array $files): int
    {
        $id = $this->repo->create(
            $supplierId, null,
            hash('sha256', 'p-' . microtime(true) . random_bytes(4)),
            date('Y-m-d H:i:s', time() + 3600),
        );
        $this->batchIds[] = $id;

        foreach ($files as [$name, $content]) {
            $sha = hash('sha256', $content);
            $this->repo->addFile($id, $supplierId, $name, $sha . '.pdf', strlen($content), $sha, 'application/pdf');
        }

        return $id;
    }

    // -----------------------------------------------------------------------

    public function testPromptListsEveryFileBySha256(): void
    {
        $id = $this->batchWith($this->supplierA, [['a.pdf', 'AAA'], ['b.pdf', 'BBB']]);
        $out = $this->builder->build($id, $this->supplierA);

        foreach (['AAA', 'BBB'] as $content) {
            self::assertStringContainsString(hash('sha256', $content), $out['prompt'],
                'každý soubor musí být v promptu adresovaný svým sha256');
        }
    }

    /** V85 — týž stav dávky musí dát bajtově týž prompt. */
    public function testPromptIsDeterministic(): void
    {
        $id = $this->batchWith($this->supplierA, [['a.pdf', 'X'], ['b.pdf', 'Y'], ['c.pdf', 'Z']]);

        $first  = $this->builder->build($id, $this->supplierA)['prompt'];
        $second = $this->builder->build($id, $this->supplierA)['prompt'];

        self::assertSame($first, $second, 'dva běhy nad touž dávkou nesmí dát jiný prompt');
    }

    /** Pořadí vložení souborů nesmí prosáknout do promptu — řadí se podle sha256. */
    public function testFileOrderInPromptDoesNotDependOnInsertionOrder(): void
    {
        $a = $this->batchWith($this->supplierA, [['prvni.pdf', 'P'], ['druhy.pdf', 'D']]);
        $b = $this->batchWith($this->supplierA, [['druhy.pdf', 'D'], ['prvni.pdf', 'P']]);

        $extract = function (string $prompt): array {
            preg_match_all('/sha256: ([0-9a-f]{64})/', $prompt, $m);
            return $m[1];
        };

        self::assertSame(
            $extract($this->builder->build($a, $this->supplierA)['prompt']),
            $extract($this->builder->build($b, $this->supplierA)['prompt']),
            'pořadí uploadu nesmí měnit pořadí v promptu',
        );
    }

    public function testPromptCarriesTenantIdentitySoSidesAreNotSwapped(): void
    {
        $id = $this->batchWith($this->supplierA, [['a.pdf', 'A']]);
        $prompt = $this->builder->build($id, $this->supplierA)['prompt'];

        $t = $this->db->pdo()->prepare('SELECT company_name, ic FROM supplier WHERE id = ?');
        $t->execute([$this->supplierA]);
        $tenant = $t->fetch(\PDO::FETCH_ASSOC);

        if (($tenant['ic'] ?? '') !== '') {
            self::assertStringContainsString((string) $tenant['ic'], $prompt,
                'bez IČO odběratele se u faktur s dominantní hlavičkou prohodí strany (V16)');
        }
        self::assertStringContainsString('ODBĚRATEL', $prompt);
    }

    /** Delta V43 ze §13 — pravidlo o textových řádcích musí být v promptu. */
    public function testPromptInstructsAgainstFabricatedZeroValueLines(): void
    {
        $id = $this->batchWith($this->supplierA, [['a.pdf', 'A']]);
        $prompt = $this->builder->build($id, $this->supplierA)['prompt'];

        self::assertStringContainsString('note_above_items', $prompt);
        self::assertStringContainsString('nulovým množstvím', $prompt);
    }

    // -----------------------------------------------------------------------
    // Co v promptu být NESMÍ
    // -----------------------------------------------------------------------

    public function testPromptDoesNotLeakBatchToken(): void
    {
        $token = hash('sha256', 'tajny-token-' . microtime(true));
        $id = $this->repo->create($this->supplierA, null, $token, date('Y-m-d H:i:s', time() + 3600));
        $this->batchIds[] = $id;
        $this->repo->addFile($id, $this->supplierA, 'a.pdf', 'x.pdf', 3, hash('sha256', 'A'), 'application/pdf');

        $prompt = $this->builder->build($id, $this->supplierA)['prompt'];
        self::assertStringNotContainsString($token, $prompt, 'hash tokenu nepatří ven ze systému');
    }

    public function testPromptDoesNotLeakOtherTenantIdentity(): void
    {
        $id = $this->batchWith($this->supplierA, [['a.pdf', 'A']]);
        $prompt = $this->builder->build($id, $this->supplierA)['prompt'];

        $t = $this->db->pdo()->prepare('SELECT company_name, ic FROM supplier WHERE id = ?');
        $t->execute([$this->supplierB]);
        $other = $t->fetch(\PDO::FETCH_ASSOC);

        if (($other['company_name'] ?? '') !== '') {
            self::assertStringNotContainsString((string) $other['company_name'], $prompt,
                'v promptu nesmí být identita jiné firmy');
        }
        if (($other['ic'] ?? '') !== '') {
            self::assertStringNotContainsString((string) $other['ic'], $prompt);
        }
    }

    // -----------------------------------------------------------------------
    // Tenant scoping
    // -----------------------------------------------------------------------

    public function testCannotBuildPromptForForeignBatch(): void
    {
        $id = $this->batchWith($this->supplierA, [['a.pdf', 'A']]);

        try {
            $this->builder->build($id, $this->supplierB);
            self::fail('prompt pro cizí dávku se sestavit nesmí');
        } catch (BatchLimitException $e) {
            self::assertSame('batch_not_found', $e->reasonCode());
        }
    }

    public function testEmptyBatchIsRejected(): void
    {
        $id = $this->repo->create(
            $this->supplierA, null,
            hash('sha256', 'empty-' . microtime(true)),
            date('Y-m-d H:i:s', time() + 3600),
        );
        $this->batchIds[] = $id;

        try {
            $this->builder->build($id, $this->supplierA);
            self::fail('prázdná dávka nemá co extrahovat');
        } catch (BatchLimitException $e) {
            self::assertSame('empty_batch', $e->reasonCode());
        }
    }
}
