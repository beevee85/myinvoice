<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IdokladImportService;
use PHPUnit\Framework\TestCase;

/**
 * Výběr archivovatelné přílohy z iDoklad /v3/Attachments payloadu.
 *
 * Pozadí: přílohy účtenek jsou typicky fotky z telefonu (JPG) — dřívější inline logika
 * je zahazovala (jen `.pdf` / `%PDF`). Nově se vybere PDF přednostně, jinak první příloha
 * s dekódovatelnými bajty; o konverzi fotky na PDF se stará caller (ImageToPdfConverter,
 * stejně jako ruční upload / issue #75).
 */
final class IdokladAttachmentPickTest extends TestCase
{
    /** Minimal JPEG-ish bytes (SOI marker) — reálný tvar: FileBytes base64, FileName foto. */
    private static function jpegAttachment(string $name = 'IMG_1234.jpg'): array
    {
        return ['FileName' => $name, 'FileBytes' => base64_encode("\xFF\xD8\xFF\xE0fakejpegbody")];
    }

    private static function pdfAttachment(string $name = 'doklad.pdf', string $body = '%PDF-1.4 fake'): array
    {
        return ['FileName' => $name, 'FileBytes' => base64_encode($body)];
    }

    public function testPrefersPdfByExtension(): void
    {
        $picked = IdokladImportService::pickArchivableAttachment([
            self::jpegAttachment(),
            self::pdfAttachment('faktura.pdf'),
        ]);
        self::assertNotNull($picked);
        self::assertSame('faktura.pdf', $picked[1]);
        self::assertStringStartsWith('%PDF', $picked[0]);
    }

    public function testPrefersPdfByMagicWhenNameMissing(): void
    {
        // PDF bez přípony/jména — pozná se podle magic bajtů, dostane fallback jméno.
        $picked = IdokladImportService::pickArchivableAttachment([
            self::jpegAttachment(),
            ['FileName' => '', 'FileBytes' => base64_encode('%PDF-1.7 bezejmena')],
        ]);
        self::assertNotNull($picked);
        self::assertSame('invoice.pdf', $picked[1]);
    }

    public function testFallsBackToImageWhenNoPdf(): void
    {
        // Reálný tvar z /v3/Attachments/{id}/ReceivedReceipt/false — fotka účtenky.
        $picked = IdokladImportService::pickArchivableAttachment([
            self::jpegAttachment('Jakub Zemek - 2026-05-24 18.18.05.jpg'),
        ]);
        self::assertNotNull($picked);
        self::assertSame('Jakub Zemek - 2026-05-24 18.18.05.jpg', $picked[1]);
        self::assertStringStartsWith("\xFF\xD8\xFF", $picked[0]);
    }

    public function testPdfWinsRegardlessOfOrder(): void
    {
        $a = IdokladImportService::pickArchivableAttachment([self::pdfAttachment(), self::jpegAttachment()]);
        $b = IdokladImportService::pickArchivableAttachment([self::jpegAttachment(), self::pdfAttachment()]);
        self::assertSame('doklad.pdf', $a[1] ?? null);
        self::assertSame('doklad.pdf', $b[1] ?? null);
    }

    public function testSkipsInvalidBase64AndEmptyBytes(): void
    {
        $picked = IdokladImportService::pickArchivableAttachment([
            ['FileName' => 'broken.pdf', 'FileBytes' => '!!!not-base64!!!'],
            ['FileName' => 'empty.pdf', 'FileBytes' => ''],
            ['FileName' => 'missing.pdf'],
            self::jpegAttachment(),
        ]);
        self::assertNotNull($picked);
        self::assertStringEndsWith('.jpg', $picked[1]);
    }

    public function testEmptyListIsNullNotError(): void
    {
        self::assertNull(IdokladImportService::pickArchivableAttachment([]));
    }

    public function testUnnamedImageGetsFallbackName(): void
    {
        $picked = IdokladImportService::pickArchivableAttachment([
            ['FileName' => '', 'FileBytes' => base64_encode("\xFF\xD8\xFF\xE0x")],
        ]);
        self::assertSame('attachment', $picked[1] ?? null);
    }
}
