<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TripRepository;
use MyInvoice\Service\Logbook\TripExportService;
use PHPUnit\Framework\TestCase;

/**
 * XLSX export knihy jízd — happy path přes reálný PhpSpreadsheet writer.
 *
 * Kryje temp-soubor hygienu z opravy tempnam úniků: xlsx() jde přes tempnam() placeholder
 * (kjexp_*) + soubor s příponou .xlsx a v finally maže OBA. Test proto vedle
 * obsahu (XLSX = ZIP kontejner, magic 'PK') hlídá, že v sys_get_temp_dir()
 * po exportu nepřibyl žádný kjexp_* sirotek.
 */
final class TripExportServiceXlsxTest extends TestCase
{
    public function testXlsxExportReturnsWorkbookAndLeavesNoTempOrphans(): void
    {
        $trips = $this->createStub(TripRepository::class);
        $trips->method('listForTenant')->willReturn([
            $this->trip('1AB 1234', '2026-01-05', 120500, 120550, 50.0),
            $this->trip('1AB 1234', '2026-01-07', 120550, 120610, 60.0),
            // Druhé auto — exercise per-car subtotal větve.
            $this->trip('2CD 5678', '2026-01-06', 80000, 80042, 42.0),
        ]);

        $service = new TripExportService($trips, $this->connection('Test s.r.o.'));

        $before = glob(sys_get_temp_dir() . '/kjexp_*') ?: [];
        $out = $service->export(1, 'xlsx', []);
        $after = glob(sys_get_temp_dir() . '/kjexp_*') ?: [];

        self::assertSame('kniha-jizd.xlsx', $out['filename']);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $out['mime'],
        );
        // XLSX je ZIP kontejner — validní výstup začíná magic byty 'PK'.
        self::assertStringStartsWith('PK', $out['bytes']);

        self::assertSame(
            [],
            array_values(array_diff($after, $before)),
            'export nechal v temp adresáři kjexp_* soubory (placeholder nebo .xlsx)',
        );
    }

    /** @return array<string,mixed> */
    private function trip(string $car, string $date, int $odoStart, int $odoEnd, float $km): array
    {
        return [
            'car_registration' => $car,
            'trip_date'        => $date,
            'time_start'       => null,
            'time_end'         => null,
            'origin'           => 'Plzeň',
            'destination'      => 'Praha',
            'purpose'          => 'Jednání s klientem',
            'category_label'   => 'Služební',
            'odometer_start'   => $odoStart,
            'odometer_end'     => $odoEnd,
            'distance_km'      => $km,
        ];
    }

    /** Connection stub: supplierName() čte company_name přes pdo()->prepare(). */
    private function connection(string $companyName): Connection
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn($companyName);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        return $db;
    }
}
