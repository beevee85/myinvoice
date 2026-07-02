<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IdokladImportService;
use PHPUnit\Framework\TestCase;

/**
 * Mapování hlavičky iDoklad ReceivedReceipt (přijatá účtenka) → purchase_invoices.
 *
 * Účtenky se od přijatých faktur (ReceivedInvoices) liší a tahle čistá funkce drží
 * právě ty rozdíly:
 *   • Partner je vnořený objekt a může chybět (hotovostní nákup) → partner_id=0; caller pak doklad
 *     naváže na sběrného systémového dodavatele (viz createReceiptFromIdoklad).
 *   • Nemají splatnost (DateOfMaturity) ani DUZP (DateOfTaxing) → issue/tax/due == DateOfIssue.
 *   • Číslo dokladu dodavatele je ExternalDocumentNumber (fallback DocumentNumber), může chybět.
 */
final class IdokladReceiptHeaderTest extends TestCase
{
    private const TODAY = '2026-06-30';

    public function testRealReceiptShapeMapsAllDatesFromIssue(): void
    {
        $i = [
            'Id'                     => 4242,
            'DateOfIssue'            => '2026-05-12',
            'ExternalDocumentNumber' => 'UCT-2026-0007',
            'DocumentNumber'         => 'PR250042',
            'Partner'                => ['Id' => 99, 'CompanyName' => 'Benzina'],
        ];

        $hdr = IdokladImportService::idokladReceiptHeader($i, self::TODAY);

        self::assertSame(99, $hdr['partner_id']);
        self::assertSame('UCT-2026-0007', $hdr['vendor_invoice_number']);
        // Splatnost ani DUZP účtenka nemá → vše z DateOfIssue.
        self::assertSame('2026-05-12', $hdr['issue_date']);
        self::assertSame('2026-05-12', $hdr['tax_date']);
        self::assertSame('2026-05-12', $hdr['due_date']);
    }

    public function testNullPartnerYieldsZeroForCollectiveVendorRouting(): void
    {
        // Hotovostní účtenka bez kontaktu — Partner == null → partner_id=0 (caller ho navěsí
        // na sběrného systémového dodavatele „Hotovostní nákup").
        $i = [
            'Id'          => 1,
            'DateOfIssue' => '2026-05-12',
            'Partner'     => null,
        ];

        self::assertSame(0, IdokladImportService::idokladReceiptHeader($i, self::TODAY)['partner_id']);
    }

    public function testFlatPartnerIdFallbackIsAccepted(): void
    {
        // Defenzivně: kdyby list endpoint vrátil plochý PartnerId místo vnořeného Partner.
        $i = ['Id' => 1, 'DateOfIssue' => '2026-05-12', 'PartnerId' => 77];

        self::assertSame(77, IdokladImportService::idokladReceiptHeader($i, self::TODAY)['partner_id']);
    }

    public function testFallsBackToDocumentNumberWhenExternalMissing(): void
    {
        $i = [
            'Id'             => 1,
            'DateOfIssue'    => '2026-05-12',
            'DocumentNumber' => 'PR250042',
            'Partner'        => ['Id' => 5],
        ];

        self::assertSame('PR250042', IdokladImportService::idokladReceiptHeader($i, self::TODAY)['vendor_invoice_number']);
    }

    public function testMissingNumberIsEmptyNotError(): void
    {
        // Účtenka/paragon nemusí mít žádné číslo dokladu — prázdné je u receipt OK.
        $i = ['Id' => 1, 'DateOfIssue' => '2026-05-12', 'Partner' => ['Id' => 5]];

        self::assertSame('', IdokladImportService::idokladReceiptHeader($i, self::TODAY)['vendor_invoice_number']);
    }

    public function testMissingIssueDateFallsBackToToday(): void
    {
        $i = ['Id' => 1, 'Partner' => ['Id' => 5]];

        $hdr = IdokladImportService::idokladReceiptHeader($i, self::TODAY);
        self::assertSame(self::TODAY, $hdr['issue_date']);
        self::assertSame(self::TODAY, $hdr['tax_date']);
        self::assertSame(self::TODAY, $hdr['due_date']);
    }

    public function testEmptyPayloadIsZeroPartnerAndTodayDates(): void
    {
        $hdr = IdokladImportService::idokladReceiptHeader([], self::TODAY);

        self::assertSame(0, $hdr['partner_id']);
        self::assertSame('', $hdr['vendor_invoice_number']);
        self::assertSame(self::TODAY, $hdr['issue_date']);
    }
}
