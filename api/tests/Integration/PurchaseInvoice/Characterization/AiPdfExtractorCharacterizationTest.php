<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice\Characterization;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Import\ImageToPdfConverter;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PurchaseInvoicePdfArchiver;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Tests\Support\PurchaseInvoiceCharacterizationCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * FORK (beevee85) — CHARAKTERIZACE cesty AI importu (`AiPdfExtractor`).
 *
 * Zafixuje, co dnes vznikne v databázi, když extrakce vrátí konkrétní data. Právě tahle
 * cesta má vlastní (slabší) validaci a zapisuje mimo `PurchaseInvoiceValidation` —
 * je tedy hlavním kandidátem na sjednocení a zároveň největším rizikem regrese.
 *
 * ŽÁDNÉ VOLÁNÍ ANTHROPIC API: `AnthropicClient` je testový dvojník. Stejně tak
 * `ClientResolver` (jinak by šel dotaz na ARES/VIES) a měna je vždy CZK, aby se
 * nespustil dotaz na kurzy ČNB. Archiv PDF míří do dočasného adresáře.
 */
#[Group('integration')]
#[Group('characterization')]
final class AiPdfExtractorCharacterizationTest extends PurchaseInvoiceCharacterizationCase
{
    private const PDF_BYTES = "%PDF-1.4\n% charakterizacni fixture\n%%EOF\n";

    private string $archiveDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiveDir = sys_get_temp_dir() . '/myinvoice-char-archive-' . bin2hex(random_bytes(6));
        @mkdir($this->archiveDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->archiveDir !== '' && is_dir($this->archiveDir)) {
            self::removeTree($this->archiveDir);
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

    /**
     * Extraktor se skutečnými závislostmi, ale s podstrčenou AI, resolverem dodavatele
     * a archivem v dočasném adresáři.
     *
     * @param array<string,mixed>|null $aiData null = extrakce selhala
     */
    private function extractor(?array $aiData, bool $aiOk = true): AiPdfExtractor
    {
        $anthropic = $this->createStub(AnthropicClient::class);
        $anthropic->method('extractInvoice')->willReturn(
            $aiOk
                ? ['ok' => true, 'data' => $aiData ?? [], 'model' => 'claude-sonnet-4-6',
                   'usage' => ['input_tokens' => 1000, 'output_tokens' => 200]]
                : ['ok' => false, 'error' => 'AI nedostupná'],
        );

        $resolver = $this->createStub(ClientResolver::class);
        $resolver->method('resolveVendor')->willReturn([
            'id' => $this->vendorId, 'created' => false, 'role_added' => false, 'is_vat_payer' => true,
        ]);

        $repo   = $this->container->get(PurchaseInvoiceRepository::class);
        $config = $this->container->get(Config::class);

        $archiveConfig = $config->all();
        $archiveConfig['purchase_invoice']['archive_storage'] = $this->archiveDir;

        return new AiPdfExtractor(
            $this->container->get(Connection::class),
            $anthropic,
            $resolver,
            $repo,
            $this->container->get(PurchaseInvoiceCalculator::class),
            $this->container->get(PdfIsdocExtractor::class),
            $this->container->get(IsdocParser::class),
            $this->container->get(IsdocToPurchaseInvoiceMapper::class),
            $config,
            $this->container->get(CnbExchangeRateClient::class),
            $this->container->get(ImageToPdfConverter::class),
            $this->container->get(TaxConstantsRepository::class),
            new PurchaseInvoicePdfArchiver(new Config($archiveConfig), $repo),
            new NullLogger(),
        );
    }

    /** @return array<string,mixed> */
    private function aiData(array $overrides = []): array
    {
        return $overrides + [
            'vendor'   => ['company_name' => 'Charakterizace dodavatel s.r.o.', 'ic' => '88888886'],
            'customer' => ['ic' => $this->tenantIc()],
            'vendor_invoice_number' => 'AI-CHAR-0001',
            'issue_date' => self::YEAR . '-03-10',
            'due_date'   => self::YEAR . '-03-24',
            'currency'   => 'CZK',
            'items'      => [[
                'description' => 'Služba',
                'quantity' => 1,
                'unit' => 'ks',
                'unit_price_without_vat' => 100.0,
                'vat_rate' => 21.0,
            ]],
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function extractAndTrack(array $data, string $bytes = self::PDF_BYTES, ?string $batchId = null): array
    {
        $result = $this->extractor($data)->extractAndCreate(
            $this->supplierId, $this->userId, $bytes, null, 'faktura.pdf', $batchId,
        );
        if (isset($result['purchase_invoice_id'])) {
            $this->trackInvoice((int) $result['purchase_invoice_id']);
        }
        return $result;
    }

    /** Běžná faktura — celý obraz zapsaného dokladu. */
    public function testPlainInvoice(): void
    {
        $result = $this->extractAndTrack($this->aiData());

        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        self::assertSame('ai', $result['source']);

        $snapshot = $this->snapshot((int) $result['purchase_invoice_id']);
        $header   = $snapshot['header'];

        // Na rozdíl od ruční cesty plní AI import datum přijetí DNEŠNÍM datem.
        self::assertSame('<TODAY>', $header['received_at']);
        self::assertSame('draft', $header['status']);
        self::assertSame('invoice', $header['document_kind']);
        self::assertSame('AI-CHAR-0001', $header['vendor_invoice_number']);
        self::assertSame('100.00', $header['total_without_vat']);
        self::assertSame('21.00', $header['total_vat']);
        self::assertSame('121.00', $header['total_with_vat']);
        self::assertSame('full', $header['vat_deduction']);
        self::assertSame(0, $header['reverse_charge']);
        // Bez použitelného účtu se `payment_account_source` VŮBEC nezapíše — repozitář
        // ho nastavuje jen tehdy, když je účet nebo IBAN skutečně k dispozici.
        self::assertArrayNotHasKey('payment_account_source', $header);
        // PDF se archivuje a metadata se zapisují.
        self::assertSame('<PATH>', $header['pdf_path']);
        self::assertSame('<SHA256>', $header['pdf_hash']);
        self::assertSame('faktura.pdf', $header['pdf_original_name']);

        self::assertCount(1, $snapshot['items']);
        self::assertSame('Služba', $snapshot['items'][0]['description']);
        self::assertSame('<VAT_RATE_ID:21>', $snapshot['items'][0]['vat_rate_id']);
    }

    /** Dobropis se pozná ze záporných řádků a přebije `document_kind` z AI. */
    public function testCreditNoteIsDetectedFromNegativeItems(): void
    {
        $result = $this->extractAndTrack($this->aiData([
            'vendor_invoice_number' => 'AI-CHAR-DOBROPIS',
            'items' => [[
                'description' => 'Vrácení zboží',
                'quantity' => -1,
                'unit' => 'ks',
                'unit_price_without_vat' => 1000.0,
                'vat_rate' => 21.0,
            ]],
        ]));

        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $snapshot = $this->snapshot((int) $result['purchase_invoice_id']);

        self::assertSame('credit_note', $snapshot['header']['document_kind'],
            'Detekce dobropisu ze záporných řádků se změnila.');
        self::assertSame('-1000.00', $snapshot['header']['total_without_vat']);
        self::assertSame('-1210.00', $snapshot['header']['total_with_vat']);
    }

    /**
     * Rekapitulace DPH z dokladu (§ 73) se naseeduje do `vat_overrides` a přebije
     * dopočet z řádků — haléřový rozdíl zůstane podle papíru dodavatele.
     */
    public function testDocumentVatRecapIsSeededIntoOverrides(): void
    {
        $result = $this->extractAndTrack($this->aiData([
            'vendor_invoice_number' => 'AI-CHAR-37A',
            'items' => [[
                'description' => 'Vůz',
                'quantity' => 1,
                'unit' => 'ks',
                'unit_price_without_vat' => 372396.69,
                'vat_rate' => 21.0,
            ]],
            // Dodavatel počítá shora z brutto: daň 78 203,31 (dopočet zdola by dal 78 203,30).
            'vat_recap' => [['rate' => 21.0, 'base' => 372396.69, 'vat' => 78203.31]],
            'total_without_vat' => 372396.69,
            'total_with_vat'    => 450600.00,
        ]));

        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $header = $this->snapshot((int) $result['purchase_invoice_id'])['header'];

        self::assertSame('78203.31', $header['total_vat'], 'Rekapitulace dokladu (§ 73) se přestala přebírat.');
        self::assertSame('450600.00', $header['total_with_vat']);
        self::assertArrayHasKey('vat_overrides', $header, 'vat_overrides se už neseedují.');
    }

    /** Doklad adresovaný jinému plátci se odmítne a nic nevznikne. */
    public function testForeignCustomerIsRejected(): void
    {
        $before = $this->countInvoices();

        $result = $this->extractAndTrack($this->aiData([
            'vendor_invoice_number' => 'AI-CHAR-CIZI',
            'customer' => ['ic' => '11111119'],   // jiné IČO než tenant
        ]));

        self::assertFalse($result['ok']);
        self::assertSame('wrong_tenant', $result['source']);
        self::assertSame($before, $this->countInvoices(), 'Odmítnutý doklad přesto něco zapsal.');
    }

    /** Dodavatel = tenant bez použitelného odběratele → odmítnutí (AI přehodila hlavičku). */
    public function testVendorEqualToTenantIsRejected(): void
    {
        $before = $this->countInvoices();

        $result = $this->extractAndTrack($this->aiData([
            'vendor_invoice_number' => 'AI-CHAR-SAMSOBE',
            'vendor'   => ['company_name' => 'Fixture Alfa s.r.o.', 'ic' => $this->tenantIc()],
            'customer' => ['ic' => $this->tenantIc()],
        ]));

        self::assertFalse($result['ok']);
        self::assertSame('vendor_is_tenant', $result['source']);
        self::assertSame($before, $this->countInvoices());
    }

    /** Neúspěšná extrakce nezaloží nic. */
    public function testFailedExtractionWritesNothing(): void
    {
        $before = $this->countInvoices();

        $result = $this->extractor(null, aiOk: false)
            ->extractAndCreate($this->supplierId, $this->userId, self::PDF_BYTES, null, 'faktura.pdf');

        self::assertFalse($result['ok']);
        self::assertSame('ai_failed', $result['source']);
        self::assertSame($before, $this->countInvoices());
    }

    /** Data, která neprojdou anti-halucinační validací, nic nezaloží. */
    public function testInvalidAiDataWritesNothing(): void
    {
        $before = $this->countInvoices();

        $result = $this->extractAndTrack(['vendor' => ['company_name' => 'Bez data vystavení s.r.o.'], 'items' => []]);

        self::assertFalse($result['ok']);
        self::assertSame('ai_invalid', $result['source']);
        self::assertSame($before, $this->countInvoices());
    }

    /** Stejné bajty podruhé → dedup přes pdf_hash vrátí původní doklad, nový nevznikne. */
    public function testSameBytesAreDeduplicated(): void
    {
        $first = $this->extractAndTrack($this->aiData(['vendor_invoice_number' => 'AI-CHAR-DEDUP']));
        self::assertTrue($first['ok'], (string) ($first['error'] ?? ''));

        $countAfterFirst = $this->countInvoices();
        $second = $this->extractAndTrack($this->aiData(['vendor_invoice_number' => 'AI-CHAR-DEDUP']));

        self::assertTrue($second['ok']);
        self::assertSame('duplicate', $second['source']);
        self::assertTrue($second['duplicate'] ?? false);
        self::assertSame($first['purchase_invoice_id'], $second['purchase_invoice_id']);
        self::assertSame($countAfterFirst, $this->countInvoices(), 'Dedup přestal fungovat.');
    }

    /** Označení dávky (#232) se propíše do `import_batch_id`. */
    public function testImportBatchIdIsTagged(): void
    {
        $result = $this->extractAndTrack($this->aiData(['vendor_invoice_number' => 'AI-CHAR-BATCH']), self::PDF_BYTES, 'batch-abc123');

        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        self::assertSame('batch-abc123', $this->snapshot((int) $result['purchase_invoice_id'])['header']['import_batch_id']);
    }

    private function countInvoices(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = {$this->supplierId} AND YEAR(issue_date) = " . self::YEAR
        )->fetchColumn();
    }
}
