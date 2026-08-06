<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\PurchaseBatchImport;

use MyInvoice\Service\PurchaseBatchImport\Finding;
use MyInvoice\Service\PurchaseBatchImport\QrCheck;
use MyInvoice\Service\PurchaseBatchImport\SpaydParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — parser SPAYD a kontrola QR platby (V33–V35, A2).
 *
 * Všechny IBANy jsou syntetické placeholdery.
 */
final class QrCheckTest extends TestCase
{
    private const IBAN_DOC = 'CZ6508000000192000145399';
    private const IBAN_QR  = 'CZ6508000000192000145399';
    private const IBAN_OTHER = 'CZ2201000000199216760237';

    private function parser(): SpaydParser
    {
        return new SpaydParser();
    }

    private function check(): QrCheck
    {
        return new QrCheck($this->parser());
    }

    private function doc(array $over = []): array
    {
        return array_replace_recursive([
            'payment' => ['iban' => self::IBAN_DOC],
            'totals'  => ['total' => '1210.00'],
            'numbers' => ['varsymbol' => '2026001'],
        ], $over);
    }

    private function spayd(string $iban = self::IBAN_QR, string $am = '1210.00', string $vs = '2026001'): string
    {
        return "SPD*1.0*ACC:{$iban}*AM:{$am}*CC:CZK*X-VS:{$vs}";
    }

    /** @param list<Finding> $f */
    private function ids(array $f, string $severity): array
    {
        return array_values(array_map(
            static fn (Finding $x) => $x->ruleId,
            array_filter($f, static fn (Finding $x) => $x->severity === $severity),
        ));
    }

    // -----------------------------------------------------------------------
    // Parser
    // -----------------------------------------------------------------------

    public function testParsesBasicSpayd(): void
    {
        $r = $this->parser()->parse($this->spayd());

        self::assertSame(self::IBAN_QR, $r['account']);
        self::assertSame('1210.00', $r['amount']);
        self::assertSame('CZK', $r['currency']);
        self::assertSame('2026001', $r['vs']);
    }

    public function testBicIsStrippedFromAccount(): void
    {
        $r = $this->parser()->parse('SPD*1.0*ACC:' . self::IBAN_QR . '+RZBCCZPP*AM:100.00');
        self::assertSame(self::IBAN_QR, $r['account'], 'BIC se pro porovnání účtu nepoužívá');
    }

    /**
     * Escapování je místo, kde se u SPAYD chybuje: hodnota smí obsahovat `*`
     * zapsanou jako `%2A`. Kdo dekóduje PŘED dělením, rozseká řetězec špatně.
     */
    public function testEscapedAsteriskInsideValueDoesNotSplitTheString(): void
    {
        $r = $this->parser()->parse('SPD*1.0*ACC:' . self::IBAN_QR . '*MSG:AKCE%2A2026*AM:50.00');

        self::assertSame('AKCE*2026', $r['message'], 'escapovaná hvězdička patří do hodnoty');
        self::assertSame('50.00', $r['amount'], 'a nesmí rozhodit zbytek řetězce');
    }

    public function testEscapedPercentIsDecoded(): void
    {
        $r = $this->parser()->parse('SPD*1.0*ACC:' . self::IBAN_QR . '*MSG:SLEVA%2520');
        self::assertSame('SLEVA%20', $r['message']);
    }

    /**
     * Duplicitní klíč: první vyhrává. „Poslední vyhrává" by dovolilo připojit
     * druhý ACC a přepsat účet — táž třída jako duplicitní klíče v JSON (V80).
     */
    public function testDuplicateAccountKeyDoesNotOverrideTheFirst(): void
    {
        $r = $this->parser()->parse(
            'SPD*1.0*ACC:' . self::IBAN_DOC . '*AM:100.00*ACC:' . self::IBAN_OTHER,
        );

        self::assertSame(self::IBAN_DOC, $r['account'],
            'druhý ACC nesmí přepsat první — jinak jde podstrčit cizí účet');
    }

    /** @return array<string, array{0: string}> */
    public static function malformedSpayd(): array
    {
        return [
            'prazdny'          => [''],
            'bez hlavicky'     => ['ACC:CZ6508000000192000145399'],
            'jina hlavicka'    => ['XXX*1.0*ACC:CZ6508000000192000145399'],
            'bez verze'        => ['SPD*ACC:CZ6508000000192000145399'],
            'bez uctu'         => ['SPD*1.0*AM:100.00*CC:CZK'],
            'pole bez klice'   => ['SPD*1.0*ACC:CZ65*nesmysl'],
            'prazdny klic'     => ['SPD*1.0*:hodnota*ACC:CZ65'],
        ];
    }

    #[DataProvider('malformedSpayd')]
    public function testMalformedSpaydReturnsNullNotAGuess(string $raw): void
    {
        self::assertNull($this->parser()->parse($raw), 'vadný řetězec je null, ne odhad');
    }

    public function testNonCanonicalAmountBecomesNullNotFloat(): void
    {
        foreach (['1 210,00', '1.0e3', '1210.000', 'abc'] as $bad) {
            $r = $this->parser()->parse('SPD*1.0*ACC:' . self::IBAN_QR . '*AM:' . $bad);
            self::assertNull($r['amount'], "částka „{$bad}\" se nesmí přijmout");
        }
    }

    // -----------------------------------------------------------------------
    // Tři stavy nezávislosti (A2) — jádro celého rozhodnutí
    // -----------------------------------------------------------------------

    public function testMismatchIsFailWhenRasterIsOurOwn(): void
    {
        $f = $this->check()->verify(
            $this->spayd(self::IBAN_OTHER),
            QrCheck::INDEPENDENCE_FULL,
            $this->doc(), '/documents/0',
        );

        self::assertContains('V33', $this->ids($f, Finding::FAIL),
            'rastr z původního PDF je nezávislý zdroj — neshoda je FAIL');
    }

    public function testMismatchIsOnlyWarnWhenRasterCameFromExtraction(): void
    {
        $f = $this->check()->verify(
            $this->spayd(self::IBAN_OTHER),
            QrCheck::INDEPENDENCE_PARTIAL,
            $this->doc(), '/documents/0',
        );

        self::assertContains('V33', $this->ids($f, Finding::WARN));
        self::assertSame([], $this->ids($f, Finding::FAIL),
            'rastr od extrakce není nezávislý — mohl vzniknout z téhož chybného čtení');
    }

    public function testUnavailableDecoderIsInfoNotFailure(): void
    {
        $f = $this->check()->verify(null, QrCheck::INDEPENDENCE_UNAVAILABLE, $this->doc(), '/documents/0');

        self::assertSame([], $this->ids($f, Finding::FAIL));
        self::assertContains('V33', $this->ids($f, Finding::INFO),
            'chybějící dekodér je chybějící kontrola, ne chyba dokladu');
    }

    public function testUnreadableQrIsInfoNotFailure(): void
    {
        $f = $this->check()->verify('rozbity obsah', QrCheck::INDEPENDENCE_FULL, $this->doc(), '/documents/0');

        self::assertSame([], $this->ids($f, Finding::FAIL));
        self::assertContains('V33', $this->ids($f, Finding::INFO));
    }

    // -----------------------------------------------------------------------
    // QR nikdy nepřepisuje
    // -----------------------------------------------------------------------

    /**
     * A2 — celý smysl kontroly. Kdyby QR směl hodnotu přepsat, útočník
     * s podvrženým QR by rovnou určil, kam se pošlou peníze.
     */
    public function testQrNeverOverwritesDocumentValues(): void
    {
        $doc = $this->doc();
        $before = $doc;

        $this->check()->verify(
            $this->spayd(self::IBAN_OTHER, '999999.00', '9999999999'),
            QrCheck::INDEPENDENCE_FULL,
            $doc, '/documents/0',
        );

        self::assertSame($before, $doc, 'doklad se kontrolou nesmí změnit');
    }

    // -----------------------------------------------------------------------
    // Jednotlivá pravidla
    // -----------------------------------------------------------------------

    public function testMatchingQrProducesInfoOnly(): void
    {
        $f = $this->check()->verify($this->spayd(), QrCheck::INDEPENDENCE_FULL, $this->doc(), '/documents/0');

        self::assertSame([], $this->ids($f, Finding::FAIL));
        self::assertSame([], $this->ids($f, Finding::WARN));
        self::assertContains('V33', $this->ids($f, Finding::INFO));
    }

    public function testAmountMismatchIsReported(): void
    {
        $f = $this->check()->verify(
            $this->spayd(self::IBAN_QR, '999.00'),
            QrCheck::INDEPENDENCE_FULL, $this->doc(), '/documents/0',
        );

        self::assertContains('V34', $this->ids($f, Finding::FAIL));
    }

    public function testVarsymbolMismatchIsReported(): void
    {
        $f = $this->check()->verify(
            $this->spayd(self::IBAN_QR, '1210.00', '7777777'),
            QrCheck::INDEPENDENCE_FULL, $this->doc(), '/documents/0',
        );

        self::assertContains('V35', $this->ids($f, Finding::FAIL));
    }

    /** Vedoucí nuly u variabilního symbolu jsou kosmetika, ne neshoda. */
    public function testLeadingZerosInVarsymbolAreNotAMismatch(): void
    {
        $f = $this->check()->verify(
            $this->spayd(self::IBAN_QR, '1210.00', '0002026001'),
            QrCheck::INDEPENDENCE_FULL, $this->doc(), '/documents/0',
        );

        self::assertSame([], $this->ids($f, Finding::FAIL));
    }

    /** Chybějící údaj na jedné straně není neshoda — je to chybějící údaj. */
    public function testMissingValueOnEitherSideIsNotAMismatch(): void
    {
        $f = $this->check()->verify(
            'SPD*1.0*ACC:' . self::IBAN_QR,
            QrCheck::INDEPENDENCE_FULL,
            $this->doc(['numbers' => ['varsymbol' => '']]), '/documents/0',
        );

        self::assertSame([], $this->ids($f, Finding::FAIL));
    }

    public function testAccountComparisonIgnoresWhitespaceAndCase(): void
    {
        $f = $this->check()->verify(
            'SPD*1.0*ACC:' . strtolower(self::IBAN_QR) . '*AM:1210.00*X-VS:2026001',
            QrCheck::INDEPENDENCE_FULL, $this->doc(), '/documents/0',
        );

        self::assertSame([], $this->ids($f, Finding::FAIL));
    }
}
