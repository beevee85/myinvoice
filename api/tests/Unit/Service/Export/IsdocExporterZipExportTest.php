<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use PHPUnit\Framework\TestCase;

/**
 * Multi-invoice větev IsdocExporter::export() (2+ faktur → ZIP s .isdoc
 * soubory) nad syntetickými fakturami — deterministický protějšek integračního
 * IsdocExportXsdValidationTest::testMultiInvoiceExportProducesZipWithValidIsdocEntries,
 * který se bez DB dat skipne.
 *
 * Co kryje:
 *  - reálný průchod ZipArchive smyčkou (jednofakturové testy berou early-return),
 *  - kontrolu návratových hodnot zip->close() / file_get_contents — 0-bajtový
 *    ZIP (plný temp filesystem) dřív prošel jako HTTP 200 s prázdným obsahem,
 *  - temp hygienu z opravy tempnam úniků: isdoc-* placeholder i .zip se po exportu mažou.
 *
 * Faktury nesou jen snapshoty (žádné supplier_id/client_id > 0), takže
 * resolve* na DB nesáhne — stub Connection není nikdy zavolaný.
 */
final class IsdocExporterZipExportTest extends TestCase
{
    private const XSD = __DIR__ . '/../../../../xsd/isdoc-invoice-6.0.2.xsd';

    protected function setUp(): void
    {
        if (!is_file(self::XSD)) {
            self::markTestSkipped('ISDOC XSD chybí — spusť cmd/download-xsd.sh isdoc.');
        }
    }

    public function testTwoInvoicesProduceZipWithValidEntriesAndNoTempOrphans(): void
    {
        $repo = $this->createStub(InvoiceRepository::class);
        $repo->method('find')->willReturnCallback(fn (int $id): ?array => match ($id) {
            1 => $this->invoice(1, '2026001'),
            2 => $this->invoice(2, '2026002'),
            default => null,
        });
        $exporter = new IsdocExporter($repo, $this->createStub(Connection::class));

        $before = glob(sys_get_temp_dir() . '/isdoc-*') ?: [];
        $out = $exporter->export([1, 2], '2026-01');
        $after = glob(sys_get_temp_dir() . '/isdoc-*') ?: [];

        self::assertSame('application/zip', $out['mime']);
        self::assertSame('isdoc-2026-01.zip', $out['filename']);
        self::assertSame(
            [],
            array_values(array_diff($after, $before)),
            'export nechal v temp adresáři isdoc-* soubory (placeholder nebo .zip)',
        );

        // Obsah je validní ZIP se 2 pojmenovanými .isdoc entry, každá validní
        // proti ISDOC XSD. Prefix schválně nekoliduje s isdoc-* globem výše.
        $tmpZip = tempnam(sys_get_temp_dir(), 'miztest_');
        self::assertNotFalse($tmpZip);
        try {
            file_put_contents($tmpZip, $out['content']);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($tmpZip), 'obsah exportu není čitelný ZIP archiv');
            try {
                self::assertSame(2, $zip->numFiles);
                self::assertSame(
                    ['Faktura-2026001.isdoc', 'Faktura-2026002.isdoc'],
                    [$zip->getNameIndex(0), $zip->getNameIndex(1)],
                );
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $this->assertValidIsdoc((string) $zip->getFromIndex($i));
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmpZip);
        }
    }

    /** Minimum, které buildXml() potřebuje — snapshoty, žádné DB resolve. */
    private function invoice(int $id, string $varsymbol): array
    {
        return [
            'id'                  => $id,
            'invoice_type'        => 'invoice',
            'varsymbol'           => $varsymbol,
            'issue_date'          => '2026-05-04',
            'tax_date'            => '2026-05-04',
            'due_date'            => '2026-05-18',
            'currency'            => 'CZK',
            'exchange_rate'       => null,
            'reverse_charge'      => false,
            'advance_paid_amount' => 0.0,
            'amount_to_pay'       => 3049.2,
            'supplier_snapshot'   => [
                'ic'           => '01698401',
                'dic'          => 'CZ01698401',
                'company_name' => 'Dodavatel s.r.o.',
                'street'       => 'Kardinála Berana 1104/36',
                'city'         => 'Plzeň',
                'zip'          => '30100',
                'country_iso2' => 'CZ',
            ],
            'client_snapshot'     => [
                'ic'           => '27140130',
                'company_name' => 'Odběratel a.s.',
                'street'       => 'Václavské náměstí 1',
                'city'         => 'Praha 1',
                'zip'          => '11000',
                'country_iso2' => 'CZ',
            ],
            'bank_snapshot'       => [
                'account_number' => '1000000005',
                'bank_code'      => '0100',
                'bank_name'      => 'Komerční banka',
            ],
            'items'               => [[
                'description'            => 'Vývoj systému',
                'quantity'               => 1.0,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 2520.0,
                'vat_rate_snapshot'      => 21.0,
                'total_without_vat'      => 2520.0,
                'total_vat'              => 529.2,
                'total_with_vat'         => 3049.2,
            ]],
            'vat_breakdown'       => [['rate' => 21.0, 'base' => 2520.0, 'vat' => 529.2]],
            'totals'              => ['without_vat' => 2520.0, 'with_vat' => 3049.2, 'rounding' => 0.0],
        ];
    }

    private function assertValidIsdoc(string $xml): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'ZIP entry není well-formed XML.');

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $dom->schemaValidate(self::XSD);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            $lines = array_map(
                static fn (\LibXMLError $e): string => sprintf('  [ř. %d] %s', $e->line, trim($e->message)),
                $errors,
            );
            self::fail("ZIP entry není validní vůči isdoc-invoice-6.0.2.xsd:\n" . implode("\n", $lines));
        }

        self::assertTrue($ok);
    }
}
