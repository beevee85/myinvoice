<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Service\Logbook\FuelingExportService;
use MyInvoice\Service\Logbook\FuelingOdometerEstimator;
use PHPUnit\Framework\TestCase;

/**
 * XLSX export tankování — happy path přes reálný PhpSpreadsheet writer.
 *
 * Kryje temp-soubor hygienu z opravy tempnam úniků: xlsx() jde přes tempnam() placeholder
 * (fuexp_*) + soubor s příponou .xlsx a v finally maže OBA. Test proto vedle
 * obsahu (XLSX = ZIP kontejner, magic 'PK') hlídá, že v sys_get_temp_dir()
 * po exportu nepřibyl žádný fuexp_* sirotek.
 *
 * FuelingOdometerEstimator je reálný — všechna tankování nesou vlastní
 * odometer, takže annotate() končí early-returnem a na DB nesáhne.
 */
final class FuelingExportServiceXlsxTest extends TestCase
{
    public function testXlsxExportReturnsWorkbookAndLeavesNoTempOrphans(): void
    {
        $fuelings = $this->createStub(FuelingRepository::class);
        $fuelings->method('listForTenant')->willReturn([
            $this->fueling('2026-01-10', 45.2, 1652.30, 1998.28),
            $this->fueling('2026-01-03', 38.0, 1389.10, 1680.81),
        ]);

        $db = $this->connection('Test s.r.o.');
        $service = new FuelingExportService($fuelings, $db, new FuelingOdometerEstimator($db));

        $before = glob(sys_get_temp_dir() . '/fuexp_*') ?: [];
        $out = $service->export(1, 'xlsx', []);
        $after = glob(sys_get_temp_dir() . '/fuexp_*') ?: [];

        self::assertSame('tankovani.xlsx', $out['filename']);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $out['mime'],
        );
        // XLSX je ZIP kontejner — validní výstup začíná magic byty 'PK'.
        self::assertStringStartsWith('PK', $out['bytes']);

        self::assertSame(
            [],
            array_values(array_diff($after, $before)),
            'export nechal v temp adresáři fuexp_* soubory (placeholder nebo .xlsx)',
        );
    }

    /** @return array<string,mixed> */
    private function fueling(string $date, float $qty, float $base, float $gross): array
    {
        return [
            'car_id'             => 1,
            'car_registration'   => '1AB 1234',
            'fueled_date'        => $date,
            'fueled_time'        => '08:30',
            'fuel_type'          => 'Natural 95',
            'unit'               => 'l',
            'quantity'           => $qty,
            'unit_price'         => round($gross / $qty, 2),
            'amount_without_vat' => $base,
            'amount_with_vat'    => $gross,
            'currency'           => 'CZK',
            'odometer'           => 123456, // vlastní stav → estimator nic nedopočítává
            'station'            => 'Benzina Plzeň',
            'vendor_name'        => null,
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
