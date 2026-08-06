<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Integrace test: ISDOC export reálných faktur (supplier_id=1) MUSÍ projít XSD
 * validation oficiálního schématu (api/xsd/isdoc-invoice-6.0.2.xsd).
 *
 * Analogie k EpoXsdValidationTest (DPH/KH/SH). Na rozdíl od unit
 * IsdocExporterSchemaTest (syntetická pole) jde o **reálná DB data** přes plný
 * export pipeline (repo->find se snapshoty, live supplier/client/bank resolution,
 * ARES adresy, speciální znaky) — chytá to, co umělá data nemají.
 *
 * **Soft skip** pokud chybí cfg.php (CI runner) nebo ISDOC XSD.
 */
final class IsdocExportXsdValidationTest extends TestCase
{
    private const SUPPLIER_ID = 1;
    private const SAMPLE_LIMIT = 30;

    private IsdocExporter $exporter;
    private XmlSchemaValidator $validator;
    private ?Connection $conn = null;

    protected function tearDown(): void
    {
        $this->conn?->close();
    }

    protected function setUp(): void
    {
        // Oba checky PŘED Bootstrap::buildApp() — jinak fatal (viz EpoXsdValidationTest).
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        if (!is_file($rootDir . '/api/xsd/isdoc-invoice-6.0.2.xsd')) {
            $this->markTestSkipped('Chybí api/xsd/isdoc-invoice-6.0.2.xsd — spusť `bash cmd/download-xsd.sh isdoc`.');
        }

        $container = Bootstrap::buildApp()->getContainer();
        $this->exporter = $container->get(IsdocExporter::class);
        $this->validator = $container->get(XmlSchemaValidator::class);
        $this->conn = $container->get(Connection::class);
    }

    public function testIssuedInvoicesSamplePassesIsdocXsd(): void
    {
        $rows = $this->invoiceSample();
        if ($rows === []) {
            $this->markTestSkipped('Žádné faktury pro supplier_id=' . self::SUPPLIER_ID . '.');
        }

        $failures = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $xml = $this->exporter->export([$id])['content'];
            $validation = $this->validator->validate($xml, 'isdoc');
            if ($validation['status'] !== 'passed') {
                $failures[] = sprintf(
                    "faktura #%d (%s, %s):\n    - %s",
                    $id,
                    $row['varsymbol'] ?? '?',
                    $row['currency'] ?? '?',
                    implode("\n    - ", $validation['errors'] ?: ['status=' . $validation['status']]),
                );
            }
        }

        $this->assertSame(
            [],
            $failures,
            count($failures) . ' z ' . count($rows) . " faktur neprošlo ISDOC XSD:\n" . implode("\n", $failures),
        );
    }

    /**
     * Cílený test cizoměnové faktury — exercise *Curr / přepočtu kurzem na reálných
     * datech. Skip, pokud supplier nemá žádnou non-CZK fakturu.
     */
    public function testForeignCurrencyInvoicePassesIsdocXsd(): void
    {
        $row = $this->conn?->pdo()->query(
            'SELECT i.id, i.varsymbol, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ' . self::SUPPLIER_ID . " AND cur.code <> 'CZK'
              ORDER BY i.id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC) ?: null;

        if ($row === null) {
            $this->markTestSkipped('Žádná cizoměnová faktura pro supplier_id=' . self::SUPPLIER_ID . '.');
        }

        $xml = $this->exporter->export([(int) $row['id']])['content'];

        // Cizoměnový doklad musí nést ForeignCurrencyCode i *Curr hodnoty.
        $this->assertStringContainsString('<ForeignCurrencyCode>', $xml);
        $this->assertStringContainsString('LineExtensionAmountCurr', $xml);

        $validation = $this->validator->validate($xml, 'isdoc');
        $this->assertSame(
            'passed',
            $validation['status'],
            sprintf("Faktura #%d (%s) neprošla ISDOC XSD:\n  - %s",
                $row['id'], $row['currency'], implode("\n  - ", $validation['errors'])),
        );
    }

    /**
     * Multi-invoice větev exportu (2+ faktur → ZIP s .isdoc soubory). Jediná
     * cesta, kterou jednofakturové testy výše neberou (early-return před ZIP
     * smyčkou). Kryje zároveň temp hygienu z opravy tempnam úniků (isdoc-* placeholder
     * i .zip se mažou) a kontrolu návratových hodnot zip->close() /
     * file_get_contents (0-bajtový ZIP by dřív prošel jako úspěch).
     */
    public function testMultiInvoiceExportProducesZipWithValidIsdocEntries(): void
    {
        $rows = $this->invoiceSample();
        if (count($rows) < 2) {
            $this->markTestSkipped('Potřeba aspoň 2 faktury pro supplier_id=' . self::SUPPLIER_ID . '.');
        }
        $ids = [(int) $rows[0]['id'], (int) $rows[1]['id']];

        $before = glob(sys_get_temp_dir() . '/isdoc-*') ?: [];
        $out = $this->exporter->export($ids, '2026-01');
        $after = glob(sys_get_temp_dir() . '/isdoc-*') ?: [];

        $this->assertSame('application/zip', $out['mime']);
        $this->assertSame('isdoc-2026-01.zip', $out['filename']);
        $this->assertSame(
            [],
            array_values(array_diff($after, $before)),
            'export nechal v temp adresáři isdoc-* soubory (placeholder nebo .zip)',
        );

        // Obsah je validní ZIP se 2 .isdoc entry, každá validní proti ISDOC XSD.
        // Prefix schválně nekoliduje s isdoc-* globem výše.
        $tmpZip = tempnam(sys_get_temp_dir(), 'miztest_');
        $this->assertNotFalse($tmpZip);
        try {
            file_put_contents($tmpZip, $out['content']);
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($tmpZip), 'obsah exportu není čitelný ZIP archiv');
            try {
                $this->assertSame(2, $zip->numFiles);
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    $this->assertStringEndsWith('.isdoc', $name);
                    $validation = $this->validator->validate((string) $zip->getFromIndex($i), 'isdoc');
                    $this->assertSame(
                        'passed',
                        $validation['status'],
                        sprintf("ZIP entry %s neprošla ISDOC XSD:\n  - %s",
                            $name, implode("\n  - ", $validation['errors'] ?: ['status=' . $validation['status']])),
                    );
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmpZip);
        }
    }

    /**
     * @return list<array{id:int, varsymbol:?string, currency:?string}>
     */
    private function invoiceSample(): array
    {
        // Mix měn: nejdřív případné cizoměnové (vzácnější), pak doplnit nejnovějšími.
        $stmt = $this->conn?->pdo()->query(
            'SELECT i.id, i.varsymbol, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ' . self::SUPPLIER_ID . "
              ORDER BY (cur.code <> 'CZK') DESC, i.id DESC
              LIMIT " . self::SAMPLE_LIMIT
        );
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }
}
