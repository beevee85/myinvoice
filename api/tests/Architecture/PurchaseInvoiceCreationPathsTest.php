<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — pravidlo V77: přijatou fakturu zakládá jen `PurchaseInvoiceWriteService`.
 *
 * Sekvence `createDraft → replaceItems → vat_overrides → recompute` existovala v repu
 * v sedmi kopiích a rozešly se — každá plnila jiná pole a žádná nebyla v transakci.
 * Konvergence je postupná, takže tenhle test funguje jako **rohatka**: drží seznam
 * dosud nepřevedených cest a vyžaduje, aby seděl PŘESNĚ.
 *
 *   - přibude nová cesta, která zakládá doklad mimo službu → test spadne,
 *   - převede se cesta, ale zapomene se vyškrtnout ze seznamu → test spadne,
 *   - převedená cesta se vrátí k přímému zápisu (typicky merge upstreamu) → test spadne.
 *
 * Test je statický (čte zdrojáky), takže nepotřebuje databázi.
 */
final class PurchaseInvoiceCreationPathsTest extends TestCase
{
    /** Jediné místo, které smí přijatou fakturu založit. */
    private const WRITE_SERVICE = 'src/Service/Invoice/PurchaseInvoiceWriteService.php';

    /**
     * Cesty, které zakládají doklad ještě po svém. Seznam se smí jen ZKRACOVAT.
     * Až bude prázdný, je pravidlo V77 splněné v celém repu.
     *
     * @var list<string>
     */
    private const NOT_YET_CONVERGED = [
        // Přijatá faktura vzniklá z bankovního výpisu (nespárovaná platba).
        'src/Action/Bank/BankStatementAction.php',
        // Import z iDokladu — dvě místa (běžný doklad a doklad z dávky).
        'src/Service/Import/IdokladImportService.php',
        // Import z Fakturoidu.
        'src/Service/Import/FakturoidImportService.php',
    ];

    /**
     * Volání `createDraft` na repozitáři PŘIJATÝCH faktur.
     *
     * Filtr na `PurchaseInvoiceRepository` v souboru odděluje přijatou stranu od vydané;
     * jmenný filtr na property (`repo` / `purchaseRepo` / `purchaseInvoices`) pak odděluje
     * i uvnitř souborů, které pracují s oběma (iDoklad a Fakturoid mají navíc
     * `$this->invoices->createDraft()` pro vydané faktury — ten se sem plést nesmí).
     */
    private const CREATE_CALL = '/\$this->(repo|purchaseRepo|purchaseInvoices)->createDraft\(/';

    /** @return list<string> cesty relativně k api/ */
    private static function filesCreatingPurchaseInvoices(): array
    {
        $apiDir = dirname(__DIR__, 2);
        $found  = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($apiDir . '/src', \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, 'PurchaseInvoiceRepository')) {
                continue;
            }
            if (preg_match(self::CREATE_CALL, $source) !== 1) {
                continue;
            }
            $found[] = str_replace($apiDir . '/', '', $file->getPathname());
        }

        sort($found);

        return $found;
    }

    /**
     * Rohatka: skutečnost musí přesně odpovídat seznamu „write service + dosud nepřevedené".
     */
    public function testOnlyKnownPathsCreatePurchaseInvoices(): void
    {
        $expected = array_merge([self::WRITE_SERVICE], self::NOT_YET_CONVERGED);
        sort($expected);

        $actual = self::filesCreatingPurchaseInvoices();

        $newOnes  = array_values(array_diff($actual, $expected));
        $goneOnes = array_values(array_diff($expected, $actual));

        self::assertSame([], $newOnes, implode("\n", [
            'Nová cesta zakládá přijatou fakturu mimo PurchaseInvoiceWriteService:',
            '  ' . implode("\n  ", $newOnes),
            'Buď ji převeď na $writer->createWithItems(), nebo — když to nejde —',
            'doplň ji do NOT_YET_CONVERGED i s důvodem.',
        ]));

        self::assertSame([], $goneOnes, implode("\n", [
            'Tyhle cesty už doklad přímo nezakládají, ale pořád jsou v NOT_YET_CONVERGED:',
            '  ' . implode("\n  ", $goneOnes),
            'Vyškrtni je ze seznamu — rohatka se smí jen utahovat.',
        ]));
    }

    /** Převedené cesty se nesmějí vrátit k přímému zápisu (typicky merge upstreamu). */
    public function testConvergedPathsDelegateToWriteService(): void
    {
        $apiDir = dirname(__DIR__, 2);

        $converged = [
            'src/Action/PurchaseInvoice/CreatePurchaseInvoiceAction.php',
            'src/Service/Import/AiPdfExtractor.php',
            'src/Service/Import/IsdocToPurchaseInvoiceMapper.php',
        ];

        foreach ($converged as $relative) {
            $source = (string) file_get_contents($apiDir . '/' . $relative);

            self::assertSame(
                0,
                preg_match(self::CREATE_CALL, $source),
                "{$relative} zase zakládá doklad přímo přes repozitář — nejspíš to vrátil merge upstreamu.",
            );
            self::assertStringContainsString(
                '->createWithItems(',
                $source,
                "{$relative} už nedeleguje na PurchaseInvoiceWriteService::createWithItems().",
            );
        }
    }

    /** `PurchaseInvoiceInboxScanner` vlastní kopii nikdy neměl — jen deleguje na mapper. */
    public function testInboxScannerHasNoOwnWriteSequence(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Service/Import/PurchaseInvoiceInboxScanner.php'
        );

        self::assertSame(
            0,
            preg_match(self::CREATE_CALL, $source),
            'Scanner si pořídil vlastní zapisovací sekvenci — má delegovat na IsdocToPurchaseInvoiceMapper.',
        );
    }
}
