<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\PurchaseBatchImport;

use MyInvoice\Service\PurchaseBatchImport\Money;
use MyInvoice\Service\PurchaseBatchImport\MoneyFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — peníze bez `float` (V81a).
 *
 * Fixtury jsou vzaté z reálného tvaru dokladů, ale jsou syntetické: řetězec
 * záloha → DDKPZ → konečná faktura na nulu, který v historii existuje dvakrát
 * (dva nákupy auta), přepsaný na kulaté částky.
 */
final class MoneyTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function rejectedFormats(): array
    {
        return [
            'carka jako oddelovac'   => ['1234,56'],
            'oddelovac tisicu'       => ['1 234.56'],
            'oddelovac tisicu carka' => ['1,234.56'],
            'tri desetinna mista'    => ['1.234'],
            'vedecka notace'         => ['1.0e3'],
            'prazdny retezec'        => [''],
            'plus na zacatku'        => ['+1.00'],
            'vedouci nula'           => ['01.00'],
            'jen tecka'              => ['.5'],
            'tecka na konci'         => ['5.'],
            'text'                   => ['abc'],
            'mena v hodnote'         => ['1234.56 Kč'],
            'dve tecky'              => ['1.2.3'],
            'mezera'                 => [' 1.00'],
        ];
    }

    #[DataProvider('rejectedFormats')]
    public function testThreeDecimalAndOtherNonCanonicalFormsAreRejected(string $raw): void
    {
        $this->expectException(MoneyFormatException::class);
        Money::parse($raw);
    }

    /** @return array<string, array{0: string}> */
    public static function canonicalForms(): array
    {
        return [
            'cele cislo'      => ['500600'],
            'dve desetinna'   => ['413719.01'],
            'jedno desetinne' => ['0.5'],
            'nula'            => ['0'],
            'zaporne'         => ['-450600.00'],
            'halere'          => ['0.01'],
        ];
    }

    #[DataProvider('canonicalForms')]
    public function testCanonicalFormsAreAccepted(string $raw): void
    {
        self::assertInstanceOf(Money::class, Money::parse($raw));
    }

    /**
     * Round-trip musí být přesný. `0.5` se serializuje na `0.50` — táž hodnota
     * v kanonickém tvaru, což je to, co po round-tripu chceme.
     */
    public function testMoneyRoundTripIsExact(): void
    {
        foreach (['413719.01', '500600.00', '-450600.00', '0.00', '0.01'] as $v) {
            self::assertSame($v, (string) Money::parse($v), "round-trip {$v}");
        }
        self::assertSame('0.50', (string) Money::parse('0.5'), 'doplní se na kanonický tvar');
    }

    /**
     * Klasická past plovoucí čárky: 0.1 + 0.2 !== 0.3. V celých haléřích
     * to vyjít musí.
     *
     * POZOR NA VÝKLAD: tenhle test dokládá jen SPRÁVNÝ VÝSLEDEK u těchto hodnot,
     * NE nepřítomnost `float` v implementaci. Ověřeno mutací — přepis `add()`
     * na výpočet přes `float` tímhle testem prošel. Nepřítomnost `float` hlídá
     * `testMoneyImplementationContainsNoFloatArithmetic` níž; chování ji doložit
     * neumí, protože float dá u malých částek stejný výsledek.
     */
    public function testSmallSumsAreExact(): void
    {
        self::assertNotSame(0.3, 0.1 + 0.2, 'předpoklad testu: float tuhle sumu netrefí');

        $sum = Money::parse('0.10')->add(Money::parse('0.20'));
        self::assertSame('0.30', (string) $sum);
        self::assertTrue($sum->equals(Money::parse('0.30')));
    }

    /**
     * V81a — VLASTNÍ VYNUCENÍ: v implementaci peněz se nesmí objevit konstrukce,
     * která vyrobí `float`. Statická kontrola nad zdrojákem, protože chováním
     * to doložit nejde (viz poznámka u testSmallSumsAreExact).
     *
     * Dělení je zakázané celé: `/` vrací v PHP `float` i pro dva `int`, když
     * dělení nevyjde beze zbytku. Pro celočíselné dělení je `intdiv()`.
     */
    public function testMoneyImplementationContainsNoFloatArithmetic(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/Service/PurchaseBatchImport/Money.php');
        self::assertIsString($src, 'zdrojový soubor musí jít přečíst');

        // Komentáře a docblocky pryč — mluví se v nich o `float` záměrně.
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $src) ?? '';

        $forbidden = [
            '(float)'  => 'přetypování na float',
            'floatval' => 'floatval()',
            'round('   => 'round() vrací float',
            'fdiv('    => 'fdiv()',
            'number_format' => 'number_format() vrací formátovaný float',
        ];
        foreach ($forbidden as $needle => $why) {
            self::assertStringNotContainsString($needle, $code,
                "Money nesmí obsahovat {$why} — peníze se počítají v celých haléřích (V81a)");
        }

        // Dělení: povolené je jen `intdiv()`. Hledáme `/` jako operátor, ne
        // v řetězcích a ne jako součást `//`.
        $withoutStrings = preg_replace('#\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"#s', "''", $code) ?? '';
        self::assertDoesNotMatchRegularExpression('#[^/*]/[^/*=]#', $withoutStrings,
            'dělení operátorem `/` vrací float — pro celočíselné dělení je intdiv() (V81a)');
    }

    /** Součet mnoha haléřů — kde by se float rozešel s realitou. */
    public function testLongSummationStaysExact(): void
    {
        $sum = Money::zero();
        for ($i = 0; $i < 10_000; $i++) {
            $sum = $sum->add(Money::parse('0.01'));
        }
        self::assertSame('100.00', (string) $sum);
    }

    public function testSubtractionToZero(): void
    {
        $final = Money::parse('500600.00')
            ->sub(Money::parse('50000.00'))
            ->sub(Money::parse('450600.00'));

        self::assertTrue($final->isZero(), 'konečná faktura po odečtení záloh vychází na nulu');
        self::assertSame('0.00', (string) $final);
    }

    public function testQuantityMultiplicationWithThreeDecimals(): void
    {
        // 1.5 × 100.00 = 150.00
        self::assertSame('150.00', (string) Money::parse('100.00')->multiplyByQuantity('1.5'));
        // 3 × 413719.01 = 1241157.03
        self::assertSame('1241157.03', (string) Money::parse('413719.01')->multiplyByQuantity('3'));
        // 0.333 × 100.00 = 33.30 (half-up na haléře)
        self::assertSame('33.30', (string) Money::parse('100.00')->multiplyByQuantity('0.333'));
    }

    public function testHalfUpRoundingOnQuantity(): void
    {
        // 0.005 × 1.00 = 0.005 → 0.01 při half-up
        self::assertSame('0.01', (string) Money::parse('1.00')->multiplyByQuantity('0.005'));
    }

    public function testNegativeQuantityFlipsSign(): void
    {
        $r = Money::parse('100.00')->multiplyByQuantity('-2');
        self::assertSame('-200.00', (string) $r);
        self::assertTrue($r->isNegative());
    }

    public function testOutOfRangeAmountIsRejected(): void
    {
        $this->expectException(MoneyFormatException::class);
        Money::parse(str_repeat('9', 16) . '.99');
    }

    public function testExceptionNeverEchoesTheAmount(): void
    {
        try {
            Money::parse('123456789,99');
            self::fail('očekával jsem odmítnutí');
        } catch (MoneyFormatException $e) {
            self::assertStringNotContainsString('123456789', $e->getMessage(),
                'částka z cizího dokladu nesmí do zprávy, která končí v logu');
        }
    }

    public function testDiffInCentsForTolerance(): void
    {
        self::assertSame(1, Money::parse('100.00')->diffCents(Money::parse('100.01')));
        self::assertSame(0, Money::parse('100.00')->diffCents(Money::parse('100.00')));
    }

    /**
     * Audit 2026-08-07: přemrštěné množství NESMÍ přetéct do floatu a shodit
     * intdiv() TypeErrorem (500). Musí dát MoneyFormatException, kterou
     * validace zachytí jako nález (V43) — ne pád serveru.
     */
    public function testOversizedQuantityIsRejectedNotOverflowed(): void
    {
        // 17 číslic → milli přeteče int64
        $this->expectException(MoneyFormatException::class);
        Money::parse('1.00')->multiplyByQuantity('10000000000000000');
    }

    public function testQuantityTimesPriceOverflowIsRejected(): void
    {
        // OCR slepí číslo účtu do quantity: cents × milli přeteče i s int milli
        $this->expectException(MoneyFormatException::class);
        Money::parse('999999999999.99')->multiplyByQuantity('2301234567');
    }

    public function testRealisticLargeButValidQuantityStillWorks(): void
    {
        // 12ciferné množství × rozumná cena je pořád v rozsahu
        self::assertSame('1000000000.00',
            (string) Money::parse('1.00')->multiplyByQuantity('1000000000'));
    }
}
