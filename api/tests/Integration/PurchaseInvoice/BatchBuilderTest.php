<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseImportBatchRepository;
use MyInvoice\Service\PurchaseBatchImport\BatchBuilder;
use MyInvoice\Service\PurchaseBatchImport\BatchLimitException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — Commit 8: builder dávky a manifest.
 *
 * Testy se vážou na `reasonCode()`, nikdy na text zprávy — jinak by je rozbila
 * oprava překlepu a nikdo by nevěděl, jestli se rozbilo pravidlo nebo věta.
 *
 * Fixtury jsou SYNTETICKÉ PDF (magic bytes + výplň). Reálný doklad se sem
 * nedostane — repo je veřejné a AGENTS.md to zakazuje.
 */
#[Group('integration')]
final class BatchBuilderTest extends TestCase
{
    private Connection $db;
    private BatchBuilder $builder;
    private int $supplierId = 0;
    private string $tmpDir = '';

    /** @var int[] */
    private array $batchIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container     = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->builder = $container->get(BatchBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $this->supplierId = (int) ($this->db->pdo()
            ->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/batchbuilder-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->batchIds !== []) {
            $in = implode(',', array_fill(0, count($this->batchIds), '?'));
            $this->db->pdo()
                ->prepare("DELETE FROM purchase_import_batches WHERE id IN ({$in})")
                ->execute($this->batchIds);
            $this->batchIds = [];
        }
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    /** Syntetické PDF — validní magic bytes, obsah je výplň. */
    private function pdf(string $name, string $filler = 'x'): array
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(6)) . '.bin';
        file_put_contents($path, "%PDF-1.7\n% synteticka fixture\n" . str_repeat($filler, 64) . "\n%%EOF\n");

        return ['name' => $name, 'path' => $path];
    }

    private function notPdf(string $name): array
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(6)) . '.bin';
        file_put_contents($path, "PK\x03\x04 tohle je ZIP, ne PDF");

        return ['name' => $name, 'path' => $path];
    }

    private function build(array $uploads): array
    {
        $r = $this->builder->build(
            $this->supplierId, null, $uploads,
            hash('sha256', 'token-' . microtime(true) . random_bytes(4)),
        );
        $this->batchIds[] = $r['batch_id'];

        return $r;
    }

    private function expectReason(string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail("Očekával jsem odmítnutí s kódem '{$code}', ale builder dávku přijal.");
        } catch (BatchLimitException $e) {
            self::assertSame($code, $e->reasonCode());
        }
    }

    // -----------------------------------------------------------------------
    // Přijetí a manifest
    // -----------------------------------------------------------------------

    public function testBuildsBatchAndManifest(): void
    {
        $r = $this->build([$this->pdf('faktura-a.pdf'), $this->pdf('faktura-b.pdf', 'y')]);

        self::assertGreaterThan(0, $r['batch_id']);
        self::assertCount(2, $r['manifest']['files']);
        self::assertSame(64, strlen($r['manifest_sha256']));
        self::assertSame('myinvoice.purchase-import-batch.manifest/1', $r['manifest']['schema']);
    }

    /** V85 — týž vstup musí dát bajtově týž manifest, nezávisle na pořadí uploadu. */
    public function testManifestIsDeterministicRegardlessOfUploadOrder(): void
    {
        $a = $this->pdf('a.pdf', 'a');
        $b = $this->pdf('b.pdf', 'b');

        $first  = $this->build([$a, $b]);
        $second = $this->build([$b, $a]);

        $strip = static function (array $m): array {
            unset($m['batch_id']);   // liší se z definice
            return $m;
        };
        self::assertSame($strip($first['manifest']), $strip($second['manifest']),
            'pořadí uploadu nesmí měnit obsah manifestu');
    }

    public function testUserFilenameNeverReachesDisk(): void
    {
        $r = $this->build([$this->pdf('../../../etc/passwd')]);

        $files = $this->db->pdo()->prepare(
            'SELECT original_name, stored_name FROM purchase_import_batch_files WHERE purchase_import_batch_id = ?'
        );
        $files->execute([$r['batch_id']]);
        $row = $files->fetch(\PDO::FETCH_ASSOC);

        self::assertStringContainsString('passwd', (string) $row['original_name'],
            'původní jméno se drží jako text, aby ho uživatel poznal');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\.pdf$/', (string) $row['stored_name'],
            'na disku smí být jen jméno odvozené z obsahu');
        self::assertStringNotContainsString('..', (string) $row['stored_name']);
    }

    public function testControlCharactersAreStrippedFromLabel(): void
    {
        $r = $this->build([$this->pdf("fak\x00tura\x1F.pdf")]);

        $stmt = $this->db->pdo()->prepare(
            'SELECT original_name FROM purchase_import_batch_files WHERE purchase_import_batch_id = ?'
        );
        $stmt->execute([$r['batch_id']]);
        $name = (string) $stmt->fetchColumn();

        self::assertSame('faktura.pdf', $name, 'řídicí znaky nesmí projít do reportu ani logu');
    }

    // -----------------------------------------------------------------------
    // Odmítnutí
    // -----------------------------------------------------------------------

    /** V1 — rozhoduje obsah, ne přípona. */
    public function testRejectsFileThatIsNotPdfDespiteExtension(): void
    {
        $this->expectReason('not_a_pdf', fn () => $this->build([$this->notPdf('tvari-se-jako.pdf')]));
    }

    public function testRejectsEmptyBatch(): void
    {
        $this->expectReason('empty_batch', fn () => $this->build([]));
    }

    public function testRejectsEmptyFile(): void
    {
        $path = $this->tmpDir . '/prazdny.bin';
        file_put_contents($path, '');
        $this->expectReason('empty_file', fn () => $this->build([['name' => 'prazdny.pdf', 'path' => $path]]));
    }

    /** V69 — dedup uvnitř dávky, se srozumitelnou chybou místo SQLSTATE. */
    public function testRejectsByteIdenticalDuplicateWithinBatch(): void
    {
        $one = $this->pdf('original.pdf', 'z');
        $two = ['name' => 'kopie.pdf', 'path' => $one['path']];

        $this->expectReason('duplicate_in_batch', fn () => $this->build([$one, $two]));
    }

    /** V79 — limit počtu dokladů; ověřuje se PŘED jakýmkoli zápisem. */
    public function testRejectsBatchOverFileCountLimit(): void
    {
        $uploads = [];
        for ($i = 0; $i < 51; $i++) {
            $uploads[] = $this->pdf("f{$i}.pdf", chr(97 + ($i % 26)) . $i);
        }
        $this->expectReason('max_files', fn () => $this->build($uploads));
    }

    /** Poloviční dávka je horší než žádná — při odmítnutí nesmí zůstat nic. */
    public function testRejectedBatchLeavesNothingBehind(): void
    {
        $before = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM purchase_import_batches')->fetchColumn();

        $this->expectReason('not_a_pdf', fn () => $this->build([
            $this->pdf('dobry.pdf'),
            $this->notPdf('spatny.pdf'),
        ]));

        $after = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM purchase_import_batches')->fetchColumn();
        self::assertSame($before, $after, 'odmítnutá dávka nesmí nechat v databázi hlavičku');
    }

    // -----------------------------------------------------------------------
    // Balíček (packageContents)
    // -----------------------------------------------------------------------

    /**
     * Rekonstruovaný manifest musí bajtově sedět na ten z build() — jinak by
     * balíček posílal uživatele vstříc jistému selhání V4 při validaci.
     */
    public function testPackageManifestMatchesTheOneFromBuild(): void
    {
        $r = $this->build([$this->pdf('faktura-a.pdf'), $this->pdf('faktura-b.pdf', 'y')]);

        $pkg = $this->builder->packageContents((int) $r['batch_id'], $this->supplierId);

        self::assertSame($r['manifest_sha256'], hash('sha256', $pkg['manifest_json']),
            'rekonstrukce z DB musí dát týž manifest jako založení');
        self::assertCount(2, $pkg['files']);
        foreach ($pkg['files'] as $f) {
            self::assertFileExists($pkg['dir'] . DIRECTORY_SEPARATOR . $f['stored_name'],
                'soubor z manifestu musí ležet na disku');
        }
    }

    /**
     * Zásah do řádků `files` PO založení = balíček se NEVYDÁ. Proti uloženému
     * hashi se později ověřuje results.json; vydat jiný manifest by znamenalo
     * dvě pravdy o téže dávce.
     */
    public function testTamperedFileRowRefusesPackage(): void
    {
        $r = $this->build([$this->pdf('faktura-a.pdf')]);
        $batchId = (int) $r['batch_id'];

        $this->db->pdo()
            ->prepare('UPDATE purchase_import_batch_files
                          SET original_name = ?
                        WHERE purchase_import_batch_id = ? AND supplier_id = ?')
            ->execute(['podvrzene-jmeno.pdf', $batchId, $this->supplierId]);

        $this->expectReason('manifest_mismatch',
            fn () => $this->builder->packageContents($batchId, $this->supplierId));
    }

    /** Cizí dávka neexistuje — ani pro balíček. */
    public function testForeignBatchHasNoPackage(): void
    {
        $r = $this->build([$this->pdf('faktura-a.pdf')]);

        $this->expectReason('batch_not_found',
            fn () => $this->builder->packageContents((int) $r['batch_id'], $this->supplierId + 999));
    }
}
