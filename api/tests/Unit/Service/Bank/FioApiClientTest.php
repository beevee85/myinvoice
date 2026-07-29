<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\Api\FioApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Normalizace odpovědi Fio API. Formát je nejzrádnější část integrace:
 * oficiální PDF ukazuje příklady z roku 2012 s datem jako epoch v milisekundách,
 * reálné API ale vrací řetězec „2026-03-01+0100". Parser musí zvládnout obojí,
 * jinak spadne na první odpovědi.
 *
 * Data jsou syntetická (účet 1000000005/0100 projde mod-11 validací).
 */
final class FioApiClientTest extends TestCase
{
    /** @param array<string,mixed> $values */
    private static function movement(array $values): array
    {
        $row = [];
        foreach ($values as $column => $value) {
            $row[$column] = ['value' => $value, 'name' => $column, 'id' => 0];
        }

        return $row;
    }

    /** @param list<array<string,mixed>> $transactions */
    private static function response(array $transactions): array
    {
        return ['accountStatement' => ['transactionList' => ['transaction' => $transactions]]];
    }

    public function testMapujeSloupceNaPojmenovanaPole(): void
    {
        $json = self::response([self::movement([
            'column22' => 26123456789,
            'column0'  => '2026-03-01+0100',
            'column1'  => 12100.50,
            'column14' => 'CZK',
            'column2'  => '1000000005',
            'column3'  => '0100',
            'column10' => 'Odběratel s.r.o.',
            'column5'  => '26030001',
            'column4'  => '0308',
            'column6'  => '123',
            'column16' => 'Platba faktury',
        ])]);

        $out = FioApiClient::normalizeMovements($json);

        self::assertCount(1, $out);
        self::assertSame('26123456789', $out[0]['external_id']);
        self::assertSame('2026-03-01', $out[0]['posted_at']);
        self::assertSame(12100.50, $out[0]['amount']);
        self::assertSame('CZK', $out[0]['currency']);
        self::assertSame('1000000005', $out[0]['counterparty_account']);
        self::assertSame('0100', $out[0]['counterparty_bank']);
        self::assertSame('Odběratel s.r.o.', $out[0]['counterparty_name']);
        self::assertSame('26030001', $out[0]['variable_symbol']);
        self::assertSame('0308', $out[0]['constant_symbol']);
        self::assertSame('123', $out[0]['specific_symbol']);
        self::assertSame('Platba faktury', $out[0]['description']);
    }

    public function testZvladneDatumJakoEpochVMilisekundach(): void
    {
        // Formát z příkladů v oficiálním PDF (2012). Hodnota je půlnoc 1. 3. 2026
        // v Praze (= 28. 2. 23:00 UTC) — kdyby se epoch četl v UTC, vyšel by
        // předchozí den a pohyb by spadl do jiného měsíce.
        $json = self::response([self::movement(['column22' => 1, 'column0' => 1772319600000, 'column1' => 100.0])]);

        $out = FioApiClient::normalizeMovements($json);
        self::assertSame('2026-03-01', $out[0]['posted_at']);
    }

    public function testZapornaCastkaZustavaZaporna(): void
    {
        $json = self::response([self::movement(['column22' => 2, 'column0' => '2026-03-01+0100', 'column1' => -5000.0])]);

        self::assertSame(-5000.0, FioApiClient::normalizeMovements($json)[0]['amount']);
    }

    public function testPrazdneObdobiVraciPrazdnePole(): void
    {
        // Fio u období bez pohybů posílá transactionList = null, ne prázdné pole.
        $json = ['accountStatement' => ['transactionList' => null]];

        self::assertSame([], FioApiClient::normalizeMovements($json));
        self::assertSame([], FioApiClient::normalizeMovements([]));
    }

    public function testPohybBezIdSePreskoci(): void
    {
        // Bez ID pohybu nelze deduplikovat — takový záznam se nesmí uložit.
        $json = self::response([
            self::movement(['column0' => '2026-03-01+0100', 'column1' => 100.0]),
            self::movement(['column22' => 3, 'column0' => '2026-03-01+0100', 'column1' => 200.0]),
        ]);

        $out = FioApiClient::normalizeMovements($json);
        self::assertCount(1, $out);
        self::assertSame('3', $out[0]['external_id']);
    }

    public function testPopisSpadneNaKomentarNeboTypPohybu(): void
    {
        $json = self::response([self::movement([
            'column22' => 4, 'column0' => '2026-03-01+0100', 'column1' => 50.0,
            'column25' => 'Poplatek za vedení účtu',
        ])]);
        self::assertSame('Poplatek za vedení účtu', FioApiClient::normalizeMovements($json)[0]['description']);

        $json = self::response([self::movement([
            'column22' => 5, 'column0' => '2026-03-01+0100', 'column1' => 50.0,
            'column8' => 'Platba kartou',
        ])]);
        self::assertSame('Platba kartou', FioApiClient::normalizeMovements($json)[0]['description']);
    }

    public function testNesmyslneDatumNeprojde(): void
    {
        $json = self::response([self::movement(['column22' => 6, 'column0' => 'není datum', 'column1' => 10.0])]);

        self::assertNull(FioApiClient::normalizeMovements($json)[0]['posted_at']);
    }

    public function testPrazdneRetezceJsouNull(): void
    {
        $json = self::response([self::movement([
            'column22' => 7, 'column0' => '2026-03-01+0100', 'column1' => 10.0,
            'column5' => '   ', 'column10' => '',
        ])]);

        $out = FioApiClient::normalizeMovements($json);
        self::assertNull($out[0]['variable_symbol']);
        self::assertNull($out[0]['counterparty_name']);
    }
}
