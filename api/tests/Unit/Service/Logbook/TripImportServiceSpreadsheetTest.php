<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\TripCategoryRepository;
use MyInvoice\Repository\TripRepository;
use MyInvoice\Service\Logbook\TripImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * Import knihy jízd z XLSX — cesta přes readSpreadsheet() s reálným
 * PhpSpreadsheet readerem (readCsv() krytí nepotřebuje, temp soubor nepoužívá).
 *
 * Kryje temp-soubor hygienu z opravy tempnam úniků: readSpreadsheet() ukládá obsah přes
 * tempnam() placeholder (logimp_*) + kopii s příponou a v finally maže OBA.
 * Test proto vedle dry-run náhledu hlídá, že v sys_get_temp_dir() po importu
 * nepřibyl žádný logimp_* soubor (placeholder ani kopie .xlsx).
 */
final class TripImportServiceSpreadsheetTest extends TestCase
{
    public function testXlsxDryRunPreviewsRowAndLeavesNoTempOrphans(): void
    {
        $cars = $this->createStub(CarRepository::class);
        $cars->method('findByRegistrationOrName')->willReturn(['id' => 5]);
        $categories = $this->createStub(TripCategoryRepository::class);
        $trips = $this->createStub(TripRepository::class);

        $service = new TripImportService($cars, $categories, $trips);
        $content = $this->workbookBytes([
            ['datum', 'auto', 'km'],
            ['15.01.2026', '1AB 1234', '42'],
        ]);

        $before = glob(sys_get_temp_dir() . '/logimp_*') ?: [];
        $out = $service->import(1, null, $content, 'trips.xlsx', dryRun: true);
        $after = glob(sys_get_temp_dir() . '/logimp_*') ?: [];

        self::assertTrue($out['ok']);
        self::assertTrue($out['dry_run']);
        self::assertSame(1, $out['created']);
        self::assertSame(0, $out['failed']);
        self::assertCount(1, $out['rows']);

        $row = $out['rows'][0];
        self::assertSame('preview', $row['status']);
        self::assertSame(2, $row['line']);
        self::assertSame('2026-01-15', $row['trip_date']);
        self::assertSame(5, $row['car_id']);
        self::assertSame(42.0, $row['distance_km']);

        self::assertSame(
            [],
            array_values(array_diff($after, $before)),
            'import nechal v temp adresáři logimp_* soubory (placeholder nebo kopie s příponou)',
        );
    }

    /** @param list<list<string>> $rows */
    private function workbookBytes(array $rows): string
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray($rows);
        // Vlastní prefix — NESMÍ kolidovat s logimp_*, jinak by si test špinil
        // vlastní glob assertion.
        $tmp = tempnam(sys_get_temp_dir(), 'miimp_');
        self::assertNotFalse($tmp);
        try {
            (new XlsxWriter($ss))->save($tmp);
            $bytes = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
        $ss->disconnectWorksheets();
        self::assertNotSame('', $bytes);
        return $bytes;
    }
}
