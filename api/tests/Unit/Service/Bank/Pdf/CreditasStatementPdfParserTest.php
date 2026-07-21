<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Pdf\CreditasStatementPdfParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Parser PDF výpisu Banky CREDITAS. Testuje se přes seamy parseHeaderFromText()/
 * parseTransactionsFromText() na VYMYŠLENÉM textu (žádná reálná zákaznická data dle
 * pravidla feedback_test_data_local_only — účty, jména i částky jsou syntetické),
 * jehož struktura odpovídá tomu, co Smalot\PdfParser::getText() reálně vrací
 * (tabulátory mezi sloupci, prázdná „buňka" jako oddělovač transakcí, sloupce
 * VS/KS/SS a částka občas slepené na stejný fyzický řádek).
 */
final class CreditasStatementPdfParserTest extends TestCase
{
    private function parser(): CreditasStatementPdfParser
    {
        return new CreditasStatementPdfParser(new NullLogger());
    }

    private function header(string $prev, string $curr, string $credit, string $debit): string
    {
        return <<<TXT
        VÝPIS Z BĚŽNÉHO ÚČTU
        Testovací s.r.o.
        Testovací 1/2
        11000 Praha
        Česká republika

        Číslo účtu: 1234567890/2250\tNázev produktu: Běžný účet PO

        Období výpisu: 1.6.2026 - 30.6.2026\tMěna:\tCZK

        Číslo výpisu: 6 / 2026\tFrekvence:\tMěsíčně

        IBAN:\tCZ00 2250 0000 0012 3456 7890 BIC:\tCTASCZ22



        Počáteční zůstatek:{$prev}\tPřipsáno:\t{$credit}

        Konečný zůstatek:{$curr}\tOdepsáno:\t{$debit}


         Zaúčtování
         Provedení
        Typ transakce
        Číslo transakce
        Číslo účtu / karty
        Název
        Detaily

        Částka v CZK



        TXT;
    }

    public function testParsesHeader(): void
    {
        $text = $this->header('10 000,00', '15 000,00', '10 000,00', '-5 000,00');
        $header = $this->parser()->parseHeaderFromText($text);

        self::assertSame('1234567890', $header['account_number']);
        self::assertSame('2026-06-30', $header['statement_date']);
        self::assertSame('6', $header['statement_number']);
        self::assertSame('CZK', $header['currency']);
        self::assertSame(10000.0, $header['prev_balance']);
        self::assertSame(15000.0, $header['curr_balance']);
        self::assertSame(10000.0, $header['credit_total']);
        // debit_total je uložen jako KLADNÁ magnituda (konzistentně s GpcParser/UI konvencí),
        // i když na PDF je "Odepsáno:" tištěno se záporným znaménkem.
        self::assertSame(5000.0, $header['debit_total']);
    }

    public function testParsesRegularTransactionWithNameVsKsAndDescription(): void
    {
        $text = <<<TXT
        1.6.2026 Příchozí úhrada
        1000000001
        1111111111/2010
        TESTFIRMA s.r.o.
        VS:2600001
        KS:308
        Proforma-2600001
        10 000,00


        TXT;

        $rows = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rows);
        self::assertSame([
            'posted_at'            => '2026-06-01',
            'amount'               => 10000.0,
            'variable_symbol'      => '2600001',
            'constant_symbol'      => '308',
            'specific_symbol'      => null,
            'counterparty_account' => '1111111111',
            'counterparty_bank'    => '2010',
            'counterparty_name'    => 'TESTFIRMA s.r.o.',
            'description'          => 'Proforma-2600001',
            'bank_ref'             => '1000000001',
        ], $rows[0]);
    }

    public function testParsesCardPaymentWithTwoDatesAndNoVsKs(): void
    {
        // Karetní platba: zaúčtování ≠ provedení (2 data), maskovaná karta + obchodník
        // na stejném řádku, lokalita na dalším — bez VS/KS/SS.
        $text = <<<TXT
        8.6.2026
        5.6.2026
        Odchozí úhrada
        1000000002
        123456******7890 Testshop a.s.
        Prague CZE
        -500,00


        TXT;

        $rows = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rows);
        self::assertSame('2026-06-08', $rows[0]['posted_at']);
        self::assertSame(-500.0, $rows[0]['amount']);
        self::assertNull($rows[0]['counterparty_account']);
        self::assertSame('Testshop a.s.', $rows[0]['counterparty_name']);
        self::assertSame('Prague CZE', $rows[0]['description']);
        self::assertNull($rows[0]['variable_symbol']);
    }

    public function testParsesCollapsedRowWithAccountVsAndAmountOnOneLine(): void
    {
        // Okrajový případ z reálných výpisů: bez jména protistrany a KS se celý zbytek
        // (účet/kód banky + VS + částka) slepí na JEDEN fyzický řádek (tabulátorem).
        $text = <<<TXT
        15.6.2026 Odchozí úhrada
        1000000003
        7700-1234567/0710 VS:99900011\t-2 500,00


        TXT;

        $rows = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rows);
        self::assertSame('7700-1234567', $rows[0]['counterparty_account']);
        self::assertSame('0710', $rows[0]['counterparty_bank']);
        self::assertSame('99900011', $rows[0]['variable_symbol']);
        self::assertSame(-2500.0, $rows[0]['amount']);
        self::assertNull($rows[0]['counterparty_name']);
    }

    public function testInterestTransactionWithBankAsCounterpartyIsNotDroppedAsFooter(): void
    {
        // Regrese: "Banka CREDITAS" je legitimní protistrana u interních transakcí
        // (úrok/kapitalizace vkladu) — dřívější skip-vzor `/^Banka CREDITAS\b/` na patičku
        // omylem smazal i tenhle řádek (a s ním celou částku), transakce zmizela beze
        // stopy. Nalezeno na reálném EUR výpisu, self-check to spolehlivě odhalil
        // (součet transakcí nesouhlasil s hlavičkou). Číslo vkladu níže je syntetické.
        $text = <<<TXT
        31.1.2026 Převod úroků
        1000000007
        Banka CREDITAS\tKapitalizace term. vkladu 900000001\t36,69


        TXT;

        $rows = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rows);
        self::assertSame(36.69, $rows[0]['amount']);
        self::assertSame('Banka CREDITAS', $rows[0]['counterparty_name']);
    }

    public function testZeroSymbolsNormalizeToNull(): void
    {
        // KS:0 / SS:0000000000 — jako u GpcParser se samé nuly normalizují na null.
        $text = <<<TXT
        2.6.2026 Příchozí úhrada
        1000000004
        2222222222/2600
        DRUHA FIRMA, A
        VS:0002600002
        KS:0
        SS:0000000000
        80 000,00


        TXT;

        $rows = $this->parser()->parseTransactionsFromText($text);
        self::assertSame('2600002', $rows[0]['variable_symbol']);
        self::assertNull($rows[0]['constant_symbol']);
        self::assertNull($rows[0]['specific_symbol']);
    }

    public function testSelfCheckRejectsWhenSumDoesNotMatchBalanceDelta(): void
    {
        // Hlavička tvrdí pohyb +5000, ale transakce dá jen +10 000 (nesedí) → parse() musí selhat.
        $text = $this->header('10 000,00', '15 000,00', '10 000,00', '0,00') . <<<TXT
        1.6.2026 Příchozí úhrada
        1000000005
        3333333333/0300
        TRETI FIRMA
        VS:1
        10 000,00


        TXT;

        $parser = $this->parser();
        $this->expectException(\RuntimeException::class);
        $parser->parse('%PDF-fake', $text);
    }

    public function testSelfCheckPassesWhenSumMatchesBalanceDelta(): void
    {
        $text = $this->header('10 000,00', '20 000,00', '10 000,00', '0,00') . <<<TXT
        1.6.2026 Příchozí úhrada
        1000000006
        4444444444/0300
        CTVRTA FIRMA
        VS:1
        10 000,00


        TXT;

        $result = $this->parser()->parse('%PDF-fake', $text);
        self::assertSame('1234567890', $result['header']['account_number']);
        self::assertCount(1, $result['transactions']);
        self::assertSame('CZK', $result['transactions'][0]['currency']);
        self::assertArrayNotHasKey('currency', $result['header']);
    }

    public function testSupportsDetectsCreditasFooter(): void
    {
        $parser = $this->parser();
        self::assertTrue($parser->supports("VÝPIS Z BĚŽNÉHO ÚČTU\nBanka CREDITAS a.s.\n"));
        self::assertFalse($parser->supports("Nějaký jiný bankovní výpis\n"));
    }

    /**
     * Regrese: Creditas má 4 varianty nadpisu — CZ/EN × běžný/spořicí účet (layout
     * je jinak identický). Dřívější supports() znal jen běžný účet (CZ+EN) — spořicí
     * účet (VÝPIS ZE SPOŘICÍHO ÚČTU / SAVINGS ACCOUNT STATEMENT) vůbec nerozpoznal,
     * takže "Upload PDF" spadl na "žádný podporovaný bankovní parser" u KAŽDÉHO
     * spořicího výpisu (odhaleno až na reálných datech — testy s parser->parse()
     * přímo tenhle bug neodhalily, protože supports() obchází).
     */
    public function testSupportsRecognizesAllFourTitleVariants(): void
    {
        $parser = $this->parser();
        foreach ([
            'VÝPIS Z BĚŽNÉHO ÚČTU',
            'CURRENT ACCOUNT STATEMENT',
            'VÝPIS ZE SPOŘICÍHO ÚČTU',
            'SAVINGS ACCOUNT STATEMENT',
        ] as $title) {
            self::assertTrue($parser->supports("$title\nBanka CREDITAS a.s.\n"), "supports() selhalo pro nadpis: $title");
        }
    }

    public function testEmptyMonthWithZeroTransactionsIsValid(): void
    {
        // Regrese: Creditas u dormantního účtu vytiskne výpis s "Během období výpisu
        // nebyly zpracovány žádné transakce." a všemi zůstatky 0,00 — to je legitimní
        // stav (self-check 0==0 projde), NE chyba k zamítnutí.
        $text = $this->header('0,00', '0,00', '0,00', '0,00') . <<<TXT
        Během období výpisu nebyly zpracovány žádné transakce.

        TXT;

        $result = $this->parser()->parse('%PDF-fake', $text);
        self::assertSame([], $result['transactions']);
    }

    public function testAmountIsNotMergedWithPrecedingReferenceNumberAcrossTabBoundary(): void
    {
        // Regrese: u interních transakcí (úrok/kapitalizace vkladu) následuje za
        // referenčním číslem vkladu TAB a pak částka na stejném fyzickém řádku
        // ("Banka CREDITAS\tKapitalizace vkladu 900000001\t36,69"). Tab je hranice
        // SLOUPCE, ne oddělovač tisíců uvnitř částky — dřívější regex `[\t ]?\d{3}`
        // to spolykal a spojil referenční číslo s částkou do jednoho čísla
        // (900000001 + 36,69 → 90000000136,69).
        $text = <<<TXT
        31.1.2026 Převod úroků
        1000000008
        Banka CREDITAS\tKapitalizace term. vkladu 900000001\t36,69


        TXT;

        $rows = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rows);
        self::assertSame(36.69, $rows[0]['amount']);
    }

    public function testParsesEnglishHeaderLabels(): void
    {
        // Regrese: Creditas umí vygenerovat výpis anglicky (uživatelův preferovaný
        // jazyk internetbankingu) — nadpis i labely hlavičky se přeloží
        // ("CURRENT ACCOUNT STATEMENT", "Account number:", "Starting balance:",
        // "Final Balance:", "Attributed:", "Written of:" — doslovný překlep z
        // Creditas generátoru, ne naše chyba), patička zůstává vždy česky.
        $text = <<<TXT
        CURRENT ACCOUNT STATEMENT
        Testovací s.r.o.

        Account number: 1234567890/2250\tProduct name: Běžný účet PO

        Statement period:1.6.2026 - 30.6.2026\tCurrency:\tCZK

        Statement number:6 / 2026\tFrequency:\tMěsíčně

        IBAN:\tCZ00 2250 0000 0012 3456 7890 BIC:\tCTASCZ22


        Starting balance:10 000,00\tAttributed: 5 000,00

        Final Balance: 15 000,00\tWritten of: 0,00


         Posting
        realizationed
        Transaction type
        Transaction code
        Account number / Payment
        Card Number
        Account Type Name
        Details

        Amount on
        CZK



        1.6.2026 Příchozí úhrada
        1000000009
        5555555555/0300
        PATA FIRMA
        VS:1
        5 000,00


        Banka CREDITAS a.s., Sokolovská 675/9, Karlín, 186 00 Praha 8
        TXT;

        self::assertTrue($this->parser()->supports($text));
        $header = $this->parser()->parseHeaderFromText($text);
        self::assertSame('1234567890', $header['account_number']);
        self::assertSame('CZK', $header['currency']);
        self::assertSame(10000.0, $header['prev_balance']);
        self::assertSame(15000.0, $header['curr_balance']);
        self::assertSame(5000.0, $header['credit_total']);
        self::assertSame(0.0, $header['debit_total']);

        $result = $this->parser()->parse('%PDF-fake', $text);
        self::assertCount(1, $result['transactions']);
    }
}
