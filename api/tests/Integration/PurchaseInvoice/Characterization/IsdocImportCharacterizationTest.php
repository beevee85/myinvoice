<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice\Characterization;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PurchaseInvoiceCnbApplier;
use MyInvoice\Service\Import\PurchaseInvoiceInboxScanner;
use MyInvoice\Service\Import\PurchaseInvoicePdfArchiver;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Tests\Support\PurchaseInvoiceCharacterizationCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * FORK (beevee85) — CHARAKTERIZACE deterministických importních cest:
 * `IsdocToPurchaseInvoiceMapper::map()` a `PurchaseInvoiceInboxScanner::scan()`.
 *
 * Obě zapisují do `purchase_invoices` mimo `PurchaseInvoiceValidation` a mimo jakoukoli
 * transakci — stejně jako AI cesta. Tenhle soubor fixuje, co po nich v databázi zůstane.
 *
 * ŽÁDNÁ SÍŤ: `ClientResolver` je testový dvojník (jinak by `VendorVatPayerResolver`
 * poslal dotaz na ARES/VIES), měna je CZK (žádný dotaz na kurzy ČNB) a scanner dostává
 * dvojníka `AiPdfExtractor` jako pojistku, že se nikdy nesáhne na Anthropic API.
 */
#[Group('integration')]
#[Group('characterization')]
final class IsdocImportCharacterizationTest extends PurchaseInvoiceCharacterizationCase
{
    private const VENDOR_IC = '88888886';

    private string $inboxDir   = '';
    private string $archiveDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/myinvoice-char-isdoc-' . bin2hex(random_bytes(6));
        $this->inboxDir   = $base . '/inbox';
        $this->archiveDir = $base . '/archive';
        @mkdir($this->inboxDir, 0700, true);
        @mkdir($this->archiveDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->inboxDir, $this->archiveDir] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                self::removeTree($dir);
                @rmdir(dirname($dir));
            }
        }
        parent::tearDown();
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Nejmenší ISDOC, který projde parserem i mapperem. */
    private function isdocXml(string $number = 'GM-ISDOC-001'): string
    {
        $tenantIc = $this->tenantIc();
        $vendorIc = self::VENDOR_IC;
        $date     = self::YEAR . '-05-04';

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Invoice xmlns="http://isdoc.cz/namespace/2013">
          <DocumentType>1</DocumentType>
          <ID>{$number}</ID>
          <IssueDate>{$date}</IssueDate>
          <TaxPointDate>{$date}</TaxPointDate>
          <LocalCurrencyCode>CZK</LocalCurrencyCode>
          <AccountingSupplierParty><Party>
            <PartyIdentification><ID>{$vendorIc}</ID></PartyIdentification>
            <PartyName><Name>Charakterizace dodavatel s.r.o.</Name></PartyName>
          </Party></AccountingSupplierParty>
          <AccountingCustomerParty><Party>
            <PartyIdentification><ID>{$tenantIc}</ID></PartyIdentification>
            <PartyName><Name>Tenant</Name></PartyName>
          </Party></AccountingCustomerParty>
          <InvoiceLines><InvoiceLine>
            <InvoicedQuantity unitCode="ks">1</InvoicedQuantity>
            <LineExtensionAmount>100.00</LineExtensionAmount>
            <UnitPrice>100.00</UnitPrice>
            <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
            <Item><Description>Charakterizační položka</Description></Item>
          </InvoiceLine></InvoiceLines>
        </Invoice>
        XML;
    }

    /** Mapper s podstrčeným resolverem dodavatele — bez dotazu na ARES/VIES. */
    private function mapper(): IsdocToPurchaseInvoiceMapper
    {
        $resolver = $this->createStub(ClientResolver::class);
        $resolver->method('resolveVendor')->willReturn([
            'id' => $this->vendorId, 'created' => false, 'role_added' => false, 'is_vat_payer' => true,
        ]);

        return new IsdocToPurchaseInvoiceMapper(
            $this->container->get(Connection::class),
            $this->container->get(PurchaseInvoiceRepository::class),
            $this->container->get(PurchaseInvoiceCalculator::class),
            $resolver,
            $this->container->get(PurchaseInvoiceCnbApplier::class),
        );
    }

    // ------------------------------------------------------------------ mapper

    public function testMapperWritesDraftFromIsdoc(): void
    {
        $parsed = $this->container->get(IsdocParser::class)->parse($this->isdocXml());
        self::assertNotEmpty($parsed['invoices'], 'ISDOC fixture se nepodařilo naparsovat.');
        self::assertArrayNotHasKey('__error', $parsed['invoices'][0], (string) ($parsed['invoices'][0]['__error'] ?? ''));

        $result = $this->mapper()->map($parsed['invoices'][0], $this->supplierId, $this->userId);
        $id     = $this->trackInvoice((int) $result['purchase_invoice_id']);

        $snapshot = $this->snapshot($id);
        $header   = $snapshot['header'];

        self::assertSame('draft', $header['status']);
        self::assertSame('invoice', $header['document_kind']);
        self::assertSame('GM-ISDOC-001', $header['vendor_invoice_number']);
        self::assertSame(self::YEAR . '-05-04', $header['issue_date']);
        self::assertSame(self::YEAR . '-05-04', $header['tax_date']);
        // Bez <PaymentMeans> padá splatnost na datum vystavení.
        self::assertSame(self::YEAR . '-05-04', $header['due_date']);
        self::assertSame('100.00', $header['total_without_vat']);
        self::assertSame('21.00', $header['total_vat']);
        self::assertSame('121.00', $header['total_with_vat']);
        self::assertSame('0.00', $header['rounding']);
        // Bez <PaymentMeans> nevznikne žádný platební údaj.
        self::assertArrayNotHasKey('payment_account_source', $header);
        // Bez <TaxTotal> se rekapitulace neseeduje.
        self::assertArrayNotHasKey('vat_overrides', $header);

        self::assertCount(1, $snapshot['items']);
        self::assertSame('Charakterizační položka', $snapshot['items'][0]['description']);
        self::assertSame('40', $snapshot['items'][0]['vat_classification_code']);
        self::assertSame('<VAT_RATE_ID:21>', $snapshot['items'][0]['vat_rate_id']);
    }

    /** Cross-tenant guard: doklad adresovaný jinému plátci se odmítne výjimkou. */
    public function testMapperRejectsForeignBuyer(): void
    {
        $parsed = $this->container->get(IsdocParser::class)->parse(
            str_replace('<ID>' . $this->tenantIc() . '</ID>', '<ID>11111119</ID>', $this->isdocXml('GM-ISDOC-CIZI'))
        );

        $before = $this->countInvoices();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->mapper()->map($parsed['invoices'][0], $this->supplierId, $this->userId);
        } finally {
            self::assertSame($before, $this->countInvoices(), 'Odmítnutý ISDOC přesto něco zapsal.');
        }
    }

    /** Chybějící IČO dodavatele je tvrdá chyba. */
    public function testMapperRequiresVendorIc(): void
    {
        $parsed = $this->container->get(IsdocParser::class)->parse(
            str_replace('<PartyIdentification><ID>' . self::VENDOR_IC . '</ID></PartyIdentification>', '', $this->isdocXml('GM-ISDOC-BEZIC'))
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->mapper()->map($parsed['invoices'][0], $this->supplierId, $this->userId);
    }

    // ----------------------------------------------------------------- scanner

    private function scanner(): PurchaseInvoiceInboxScanner
    {
        $config = new Config([
            'purchase_invoice' => [
                'inbox_dir'       => $this->inboxDir,
                'inbox_recursive' => true,
                // ZÁMĚRNĚ bez 'pdf': PDF bez embedded ISDOC by scanner poslal do AI větve.
                'allowed_exts'    => ['isdoc'],
                'archive_storage' => $this->archiveDir,
            ],
        ]);
        $repo = $this->container->get(PurchaseInvoiceRepository::class);

        return new PurchaseInvoiceInboxScanner(
            $config,
            $this->container->get(Connection::class),
            $repo,
            $this->container->get(ClientRepository::class),
            $this->container->get(PurchaseInvoiceCalculator::class),
            $this->container->get(PdfIsdocExtractor::class),
            $this->container->get(IsdocParser::class),
            $this->mapper(),
            // Pojistka: kdyby scanner přece jen sáhl na AI větev, spadne to na dvojníkovi.
            $this->createStub(AiPdfExtractor::class),
            new PurchaseInvoicePdfArchiver($config, $repo),
        );
    }

    public function testScannerImportsIsdocFile(): void
    {
        file_put_contents($this->inboxDir . '/faktura.isdoc', $this->isdocXml('GM-SCAN-001'));

        $result = $this->scanner()->scan($this->supplierId, $this->userId);

        self::assertSame(1, $result['created'], 'Scanner nezaložil doklad: ' . json_encode($result['details'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $result['failed']);
        self::assertFalse($result['dry_run']);

        $id = (int) $this->db->pdo()->query(
            "SELECT id FROM purchase_invoices WHERE supplier_id = {$this->supplierId} AND vendor_invoice_number = 'GM-SCAN-001'"
        )->fetchColumn();
        self::assertGreaterThan(0, $id);
        $this->trackInvoice($id);

        $snapshot = $this->snapshot($id);
        self::assertSame('121.00', $snapshot['header']['total_with_vat']);
        self::assertSame('<TODAY>', $snapshot['header']['received_at']);
        // Zdroj kurzu je u ISDOC 'manual' (ruční cesta zapisuje 'cnb').
        self::assertSame('manual', $snapshot['header']['exchange_rate_source']);
        // Samotný `.isdoc` soubor se NEARCHIVUJE — `source_*` ani `pdf_*` sloupce se
        // neplní. Archivuje se jen ISDOC vytažený z PDF/A-3 a obsah `.isdocx` balíčku.
        self::assertArrayNotHasKey('source_path', $snapshot['header']);
        self::assertArrayNotHasKey('source_format', $snapshot['header']);
        self::assertArrayNotHasKey('pdf_path', $snapshot['header']);
    }

    /** Dry-run nesmí zapsat nic. */
    public function testScannerDryRunWritesNothing(): void
    {
        file_put_contents($this->inboxDir . '/faktura.isdoc', $this->isdocXml('GM-SCAN-DRY'));

        $before = $this->countInvoices();
        $result = $this->scanner()->scan($this->supplierId, $this->userId, dryRun: true);

        self::assertTrue($result['dry_run']);
        self::assertSame($before, $this->countInvoices(), 'Dry-run zapsal do databáze.');
    }

    /**
     * Druhý běh nad týmž `.isdoc` NEZALOŽÍ nový doklad, ale scanner ho přesto
     * **započítá jako `created`**.
     *
     * Proč: dedup scanneru stojí na `pdf_hash`, který u samotného `.isdoc` nevzniká.
     * Duplicitu proto zachytí až mapper přes `findIdByVendorInvoice` a vrátí id
     * existujícího dokladu — scanner ale rozlišuje jen „mapper vrátil id" vs. „spadlo to",
     * takže hlášení říká `created: 1`, i když v databázi nic nepřibylo.
     *
     * Zafixováno jako dnešní stav: čísla v hlášení jsou zavádějící, data jsou v pořádku.
     */
    public function testSecondScanReportsCreatedButWritesNoNewRow(): void
    {
        file_put_contents($this->inboxDir . '/faktura.isdoc', $this->isdocXml('GM-SCAN-DEDUP'));

        $first = $this->scanner()->scan($this->supplierId, $this->userId);
        self::assertSame(1, $first['created'], json_encode($first['details'], JSON_UNESCAPED_UNICODE));

        $id = (int) $this->db->pdo()->query(
            "SELECT id FROM purchase_invoices WHERE supplier_id = {$this->supplierId} AND vendor_invoice_number = 'GM-SCAN-DEDUP'"
        )->fetchColumn();
        $this->trackInvoice($id);

        $countAfterFirst = $this->countInvoices();
        $second = $this->scanner()->scan($this->supplierId, $this->userId);

        // Hlášení: vypadá to jako další založení…
        self::assertSame(1, $second['created'], 'Změnilo se hlášení druhého běhu.');
        self::assertSame(0, $second['failed']);
        // …ale vrací se TÝŽ doklad a řádek nepřibyl.
        self::assertSame($id, (int) $second['details'][0]['purchase_invoice_id']);
        self::assertSame($countAfterFirst, $this->countInvoices(), 'Vznikl duplicitní doklad!');
    }

    /** Nenakonfigurovaný inbox se hlásí jako `config_missing`, ne jako chyba. */
    public function testScannerWithoutInboxDirReportsConfigMissing(): void
    {
        $repo   = $this->container->get(PurchaseInvoiceRepository::class);
        $config = new Config(['purchase_invoice' => ['inbox_dir' => '']]);

        $scanner = new PurchaseInvoiceInboxScanner(
            $config,
            $this->container->get(Connection::class),
            $repo,
            $this->container->get(ClientRepository::class),
            $this->container->get(PurchaseInvoiceCalculator::class),
            $this->container->get(PdfIsdocExtractor::class),
            $this->container->get(IsdocParser::class),
            $this->mapper(),
            $this->createStub(AiPdfExtractor::class),
            new PurchaseInvoicePdfArchiver($config, $repo),
        );

        $result = $scanner->scan($this->supplierId, $this->userId);

        self::assertSame(0, $result['created']);
        self::assertSame('config_missing', $result['details'][0]['status'] ?? null);
    }

    private function countInvoices(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = {$this->supplierId} AND YEAR(issue_date) = " . self::YEAR
        )->fetchColumn();
    }
}
