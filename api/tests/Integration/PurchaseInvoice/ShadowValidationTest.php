<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper;
use MyInvoice\Service\Import\PurchaseInvoiceCnbApplier;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseInvoiceWriteService;
use MyInvoice\Tests\Support\CollectingLogger;
use MyInvoice\Tests\Support\PurchaseInvoiceCharacterizationCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Response as Psr7Response;

/**
 * FORK (beevee85) — pravidlo V76: stínová validace importních cest.
 *
 * `PurchaseInvoiceValidation::invoice()` dnes hlídá jen ruční pořízení. Importní cesty
 * ji nikdy nevolaly a mají vlastní, slabší kontroly — proto v datech vznikaly doklady,
 * které by ručním editorem neprošly.
 *
 * Než se validace na importy VYNUTÍ, potřebujeme vědět, kolik dnešních dokladů by
 * neprošlo a proč. `PurchaseInvoiceWriteService` ji proto spouští v režimu „jen
 * zaznamenat": nic neodmítne, jen zapíše nález do logu.
 *
 * Tenhle test ověřuje obě poloviny kontraktu — že se zaznamenává, a že se NEODMÍTÁ.
 */
#[Group('integration')]
final class ShadowValidationTest extends PurchaseInvoiceCharacterizationCase
{
    private CollectingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new CollectingLogger();
    }

    private function writer(): PurchaseInvoiceWriteService
    {
        return new PurchaseInvoiceWriteService(
            $this->container->get(Connection::class),
            $this->container->get(PurchaseInvoiceRepository::class),
            $this->container->get(PurchaseInvoiceCalculator::class),
            $this->logger,
        );
    }

    /**
     * Payload, který sdílená validace odmítne, ale databáze ho spolkne:
     * nulové množství a prázdný popis položky.
     *
     * @return array<string,mixed>
     */
    private function invalidPayload(string $number): array
    {
        return [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => $number,
            'issue_date'            => self::YEAR . '-10-10',
            'due_date'              => self::YEAR . '-11-10',
            'currency_id'           => $this->currencyId,
            'items' => [[
                'description'            => '',      // „Popis je povinný"
                'quantity'               => 0,       // „Množství nesmí být 0."
                'unit'                   => 'ks',
                'unit_price_without_vat' => 100,
                'vat_rate_id'            => $this->vatRateId('CZ-21'),
                'order_index'            => 0,
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function validPayload(string $number): array
    {
        $payload = $this->invalidPayload($number);
        $payload['items'][0]['description'] = 'Platná položka';
        $payload['items'][0]['quantity']    = 1;

        return $payload;
    }

    /** @return list<array<string,mixed>> kontexty stínových nálezů */
    private function findings(): array
    {
        return array_map(
            static fn (array $r): array => $r['context'],
            $this->logger->matching(PurchaseInvoiceWriteService::SHADOW_LOG_PREFIX),
        );
    }

    /** Vadná data projdou (zápis se NEODMÍTNE) — a zároveň zanechají nález. */
    public function testInvalidDataIsRecordedButNotBlocked(): void
    {
        $id = $this->trackInvoice(
            $this->writer()->createWithItems($this->invalidPayload('SHADOW-001'), $this->userId, $this->supplierId, 'isdoc')
        );

        // NEODMÍTNUTO — doklad v databázi je.
        self::assertGreaterThan(0, $id);
        self::assertNotSame([], $this->snapshot($id)['header'], 'Stínová validace zápis zablokovala — má jen zaznamenávat.');
        self::assertCount(1, $this->snapshot($id)['items']);

        // ZAZNAMENÁNO — s dostatkem kontextu na vyhodnocení.
        $findings = $this->findings();
        self::assertCount(1, $findings, 'Stínová validace nic nezaznamenala.');

        $context = $findings[0];
        self::assertSame('isdoc', $context['source']);
        self::assertSame($this->supplierId, $context['supplier_id']);
        self::assertSame('SHADOW-001', $context['vendor_invoice_number']);
        self::assertContains('items.0.description', $context['fields']);
        self::assertContains('items.0.quantity', $context['fields']);
    }

    /** Čistá data nesmí do logu zapsat nic — jinak by se v šumu nálezy ztratily. */
    public function testValidDataProducesNoFinding(): void
    {
        $this->trackInvoice(
            $this->writer()->createWithItems($this->validPayload('SHADOW-OK-001'), $this->userId, $this->supplierId, 'isdoc')
        );

        self::assertSame([], $this->findings(), 'Bezvadný doklad zbytečně zaznamenán.');
    }

    /** Zdroj zápisu se do nálezu propíše — bez něj by nešlo rozhodnout per cestu. */
    public function testSourceLabelIsRecorded(): void
    {
        foreach (['manual', 'ai_pdf', 'isdoc'] as $index => $source) {
            $this->logger->reset();
            $this->trackInvoice(
                $this->writer()->createWithItems(
                    $this->invalidPayload('SHADOW-SRC-' . $index), $this->userId, $this->supplierId, $source,
                )
            );

            $findings = $this->findings();
            self::assertCount(1, $findings);
            self::assertSame($source, $findings[0]['source']);
        }
    }

    /**
     * KONTRAST: táž data ruční cestou skončí odmítnutím a v databázi nevznikne nic.
     * Tohle je jádro V76 — rozdíl mezi „zaznamenat" a „vynutit" na stejném vstupu.
     */
    public function testSameDataIsRejectedByManualPath(): void
    {
        $before = $this->countInvoices();

        $action   = $this->container->get(CreatePurchaseInvoiceAction::class);
        $response = ($action)(
            $this->request('POST', '/api/purchase-invoices', $this->invalidPayload('SHADOW-MANUAL-001')),
            new Psr7Response(),
        );

        self::assertSame(400, $response->getStatusCode(), 'Ruční cesta vadná data propustila.');
        self::assertSame('validation_failed', self::json($response)['error']['code'] ?? null);
        self::assertSame($before, $this->countInvoices(), 'Odmítnutý doklad přesto vznikl.');
    }

    /**
     * Skutečná importní cesta (ISDOC) — doklad s nulovým množstvím projde a zanechá nález.
     * Ověřuje, že je stínový režim zapojený i tam, kde na něm záleží, ne jen ve službě.
     */
    public function testIsdocImportPathRecordsFinding(): void
    {
        $resolver = $this->createStub(ClientResolver::class);
        $resolver->method('resolveVendor')->willReturn([
            'id' => $this->vendorId, 'created' => false, 'role_added' => false, 'is_vat_payer' => true,
        ]);

        $mapper = new IsdocToPurchaseInvoiceMapper(
            $this->container->get(Connection::class),
            $this->container->get(PurchaseInvoiceRepository::class),
            $this->container->get(PurchaseInvoiceCalculator::class),
            $resolver,
            $this->container->get(PurchaseInvoiceCnbApplier::class),
            $this->logger,
        );

        $parsed = $this->container->get(IsdocParser::class)->parse($this->isdocWithZeroQuantity());
        self::assertNotEmpty($parsed['invoices']);

        $result = $mapper->map($parsed['invoices'][0], $this->supplierId, $this->userId);
        $this->trackInvoice((int) $result['purchase_invoice_id']);

        $findings = $this->findings();
        self::assertNotSame([], $findings, 'ISDOC import stínovou validaci nespustil.');
        self::assertSame('isdoc', $findings[0]['source']);
        self::assertContains('items.0.quantity', $findings[0]['fields']);
    }

    private function isdocWithZeroQuantity(): string
    {
        $tenantIc = $this->tenantIc();
        $date     = self::YEAR . '-10-04';

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Invoice xmlns="http://isdoc.cz/namespace/2013">
          <DocumentType>1</DocumentType>
          <ID>SHADOW-ISDOC-001</ID>
          <IssueDate>{$date}</IssueDate>
          <TaxPointDate>{$date}</TaxPointDate>
          <LocalCurrencyCode>CZK</LocalCurrencyCode>
          <AccountingSupplierParty><Party>
            <PartyIdentification><ID>88888886</ID></PartyIdentification>
            <PartyName><Name>Charakterizace dodavatel s.r.o.</Name></PartyName>
          </Party></AccountingSupplierParty>
          <AccountingCustomerParty><Party>
            <PartyIdentification><ID>{$tenantIc}</ID></PartyIdentification>
            <PartyName><Name>Tenant</Name></PartyName>
          </Party></AccountingCustomerParty>
          <InvoiceLines><InvoiceLine>
            <InvoicedQuantity unitCode="ks">0</InvoicedQuantity>
            <LineExtensionAmount>0.00</LineExtensionAmount>
            <UnitPrice>100.00</UnitPrice>
            <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
            <Item><Description>Položka s nulovým množstvím</Description></Item>
          </InvoiceLine></InvoiceLines>
        </Invoice>
        XML;
    }

    private function countInvoices(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = {$this->supplierId} AND YEAR(issue_date) = " . self::YEAR
        )->fetchColumn();
    }
}
