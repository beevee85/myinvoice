<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Pdf\KbStatementPdfParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Parser PDF výpisu KB (Komerční banka). Testuje se přes seamy parseHeaderFromText()/
 * parseTransactionsFromText() na VYMYŠLENÉM textu (žádná reálná zákaznická data dle
 * pravidla feedback_test_data_local_only — účty, jména, VS i částky jsou syntetické).
 * KB má „vertikální" layout — každé pole transakce na vlastním fyzickém řádku, běžný
 * zůstatek se u řádků NEuvádí (jen sloupec Připsáno/Odepsáno = částka se znaménkem).
 */
final class KbStatementPdfParserTest extends TestCase
{
    private function parser(): KbStatementPdfParser
    {
        return new KbStatementPdfParser(new NullLogger());
    }

    /** Obalí syntetické řádky transakcí do plné struktury výpisu (hlavička + rekapitulace). */
    private function statement(string $prev, string $curr, string $credit, string $debit, string $rows, string $tail = ''): string
    {
        return "Datum výpisu: 30.06.2026\n"
            . "Číslo výpisu:\t6\n"
            . "Za období: 01.06. - 30.06.2026\n"
            . "VÝPIS PERIODICKÝ\n"
            . "k účtu:12-3456789/0100\n"
            . "IBAN:CZ0001000000123456789\n"
            . "typ: PROFI ÚČET\n"
            . "měna:CZK\n"
            . "BIC / SWIFT kód: KOMBCZPPXXX\n"
            . "Počáteční zůstatek   {$prev}\n"
            . "Konečný zůstatek   {$curr}\n"
            . "POČÁTEČNÍ ZŮSTATEK   {$prev}\n"
            . "Datum\nzúčtování\nDatum\ntransakce\nPopis transakce\nIdentifikace transakce\n"
            . "Název protiúčtu / Číslo a typ karty\nProtiúčet a kód banky / Obchodní místo\nVS\nKS\nSS\nPřipsáno\nOdepsáno\n"
            . $rows . "\n"
            . "KONEČNÝ ZŮSTATEK   {$curr}\n"
            . "Rekapitulace transakcí na účtu\tPřipsáno\tOdepsáno\n"
            . "Obraty na účtu   {$credit}   -{$debit}\n"
            . "Zůstatek podle data\n"
            . $tail
            . "Vklad na tomto účtu je pojištěn.\n";
    }

    public function testParsesHeader(): void
    {
        $text = $this->statement('100 000,00', '109 999,00', '15 000,00', '5 001,00', '');
        $header = $this->parser()->parseHeaderFromText($text);

        self::assertSame('12-3456789', $header['account_number']);
        self::assertSame('2026-06-30', $header['statement_date']);
        self::assertSame('6', $header['statement_number']);
        self::assertSame('CZK', $header['currency']);
        self::assertSame(100000.0, $header['prev_balance']);
        self::assertSame(109999.0, $header['curr_balance']);
        self::assertSame(15000.0, $header['credit_total']);
        self::assertSame(5001.0, $header['debit_total']);
    }

    public function testParsesVerticalMultilineTransaction(): void
    {
        // Vertikální layout: datum zúčtování / datum transakce / popis / identifikace /
        // název protiúčtu / protiúčet / VS / KS / částka (Připsáno, bez znaménka).
        $rows = "01.06.2026\n"
              . "01.06.2026\n"
              . "PŘÍCHOZÍ ÚHRADA\n"
              . "120-20260601 PR00000000001\n"
              . "NOVAK JAN\n"
              . "1111111111/0300\n"
              . "5550045\n"
              . "308\n"
              . "             3 500,00";
        $text = $this->statement('100 000,00', '103 500,00', '3 500,00', '0,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame('2026-06-01', $rowsOut[0]['posted_at']);
        self::assertSame(3500.0, $rowsOut[0]['amount']);
        self::assertSame('NOVAK JAN', $rowsOut[0]['counterparty_name']);
        self::assertSame('1111111111', $rowsOut[0]['counterparty_account']);
        self::assertSame('0300', $rowsOut[0]['counterparty_bank']);
        self::assertSame('5550045', $rowsOut[0]['variable_symbol']);
        self::assertSame('308', $rowsOut[0]['constant_symbol']);
    }

    public function testParsesCompactRowWithTypeGluedToDateAndDebitSign(): void
    {
        // Kompaktní layout: popis je nalepený za datem („05.06.2026OKAMŽITÁ ODCHOZÍ …"),
        // protiúčet + VS + částka na jednom řádku, debet nese znaménko „-".
        $rows = "05.06.2026OKAMŽITÁ ODCHOZÍ ÚHRADA\n"
              . "OI00000A00A\n"
              . "362-05062026 1602 000000 000000\n"
              . "Zpráva pro příjemce:\n"
              . "Testovaci zprava\n"
              . "670100-1234567/6210\t5550070            -5 500,00";
        $text = $this->statement('100 000,00', '94 500,00', '0,00', '5 500,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame('2026-06-05', $rowsOut[0]['posted_at']);
        self::assertSame(-5500.0, $rowsOut[0]['amount']);
        self::assertSame('670100-1234567', $rowsOut[0]['counterparty_account']);
        self::assertSame('6210', $rowsOut[0]['counterparty_bank']);
        self::assertSame('5550070', $rowsOut[0]['variable_symbol']);
    }

    public function testAmountGluedToVsOnSingleLine(): void
    {
        // VS a částka slepené na jednom řádku za protiúčtem („5550099                12,00").
        $rows = "10.06.2026\n"
              . "10.06.2026\n"
              . "PŘÍCHOZÍ ÚHRADA\n"
              . "120-20260610 PR00000000002\n"
              . "DRUHA FIRMA\n"
              . "2222222222/0800\n"
              . "5550099                12,00";
        $text = $this->statement('100 000,00', '100 012,00', '12,00', '0,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame(12.0, $rowsOut[0]['amount']);
        self::assertSame('5550099', $rowsOut[0]['variable_symbol']);
        self::assertSame('2222222222', $rowsOut[0]['counterparty_account']);
    }

    public function testBalanceRecapDatesAfterEndMarkerAreNotParsedAsTransactions(): void
    {
        // „Zůstatek podle data" za koncovým markerem obsahuje řádky s datem a zůstatkem
        // („01.06.2026   103 000,00") — NESMÍ se rozparsovat jako transakce. Koncový
        // marker (KONEČNÝ ZŮSTATEK / Rekapitulace / Zůstatek podle data) tabulku ukončí.
        $rows = "01.06.2026\n"
              . "01.06.2026\n"
              . "PŘÍCHOZÍ ÚHRADA\n"
              . "120-20260601 PR00000000003\n"
              . "NOVAK JAN\n"
              . "1111111111/0300\n"
              . "5550045\n"
              . "             3 000,00";
        $tail = "01.06.2026                 103 000,00\n"
              . "05.06.2026                  99 000,00\n";
        $text = $this->statement('100 000,00', '103 000,00', '3 000,00', '0,00', $rows, $tail);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame(3000.0, $rowsOut[0]['amount']);
    }

    public function testEmptyMonthWithZeroTransactionsIsValid(): void
    {
        // Dormantní účet (např. spořicí) — nula transakcí, zůstatky 0,00. Self-check 0==0.
        $text = $this->statement('0,00', '0,00', '0,00', '0,00', '');
        $result = $this->parser()->parse('%PDF-fake', $text);
        self::assertSame([], $result['transactions']);
        self::assertSame(0.0, $result['header']['prev_balance']);
    }

    public function testSelfCheckRejectsWhenSumDoesNotMatchBalanceDelta(): void
    {
        // Součet částek (+3 000) nesedí na pohyb zůstatku dle hlavičky (+9 999) → zamítnout.
        $rows = "01.06.2026\n"
              . "01.06.2026\n"
              . "PŘÍCHOZÍ ÚHRADA\n"
              . "120-20260601 PR00000000004\n"
              . "NOVAK JAN\n"
              . "1111111111/0300\n"
              . "5550045\n"
              . "             3 000,00";
        $text = $this->statement('100 000,00', '109 999,00', '3 000,00', '0,00', $rows);

        $this->expectException(\RuntimeException::class);
        $this->parser()->parse('%PDF-fake', $text);
    }

    public function testSupportsDetectsKb(): void
    {
        $parser = $this->parser();
        self::assertTrue($parser->supports("VÝPIS PERIODICKÝ\nk účtu:12-3456789/0100\nBIC / SWIFT kód: KOMBCZPPXXX\n"));
        self::assertTrue($parser->supports("Komerční banka, a.s.\nVÝPIS PERIODICKÝ\n"));
        self::assertFalse($parser->supports("Nějaký jiný bankovní výpis\n"));
        self::assertFalse($parser->supports("VÝPIS Z ÚČTU\nwww.csob.cz\n"));
    }
}
