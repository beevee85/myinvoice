<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\PurchaseBatchImport;

use MyInvoice\Service\PurchaseBatchImport\StrictJson;
use MyInvoice\Service\PurchaseBatchImport\StrictJsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — brána na nepřátelský JSON (V80).
 *
 * Testy jsou rozdělené na dvě baterie, protože odpovídají na dvě různé otázky:
 *
 *   1. „Odmítne se to?" — nezajímá nás KDO, jestli PHP nebo naše brána.
 *      Kdyby PHP v budoucí verzi něco přestalo odmítat, tahle baterie to
 *      zachytí a my se dozvíme, že si to musíme ohlídat sami.
 *   2. „Odmítne to NAŠE brána?" — čtyři případy, o kterých je změřené, že
 *      json_decode() je propustí. Tam se testuje i konkrétní reasonCode.
 *
 * Bez čistě jednotkové části by se to nedalo spustit bez databáze.
 */
final class StrictJsonTest extends TestCase
{
    private function json(): StrictJson
    {
        return new StrictJson(maxBytes: 4096, maxDepth: 5, maxArrayItems: 10, maxObjectKeys: 10, maxStringLength: 64);
    }

    // -----------------------------------------------------------------------
    // Baterie 1 — musí to spadnout, ať to odmítne kdokoli
    // -----------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function hostileInputs(): array
    {
        return [
            'NaN'                  => ['{"a":NaN}'],
            'Infinity'             => ['{"a":Infinity}'],
            'vedouci nula'         => ['{"a":01}'],
            'plus jednicka'        => ['{"a":+1}'],
            'hex literal'          => ['{"a":0x10}'],
            'jednoduche uvozovky'  => ["{'a':1}"],
            'klic bez uvozovek'    => ['{a:1}'],
            'koncova carka'        => ['{"a":1,}'],
            'komentar'             => ['{"a":1/*x*/}'],
            'BOM'                  => ["\xEF\xBB\xBF{\"a\":1}"],
            'NUL v retezci'        => ["{\"a\":\"x\x00y\"}"],
            'osamoceny surrogat'   => ['{"a":"\ud800"}'],
            'neuzavreny objekt'    => ['{"a":1'],
            'prazdny vstup'        => [''],
            'holy skalar'          => ['42'],
            'pole na top level'    => ['[1,2]'],
        ];
    }

    #[DataProvider('hostileInputs')]
    public function testHostileJsonIsRejected(string $raw): void
    {
        $this->expectException(StrictJsonException::class);
        $this->json()->decode($raw);
    }

    // -----------------------------------------------------------------------
    // Baterie 2 — díry, které json_decode() propustí (změřeno)
    // -----------------------------------------------------------------------

    /**
     * Nejzávažnější z celé V80: `{"a":1,"a":2}` PHP tiše scvrkne na `a=2`.
     * Útočník tím vloží do jednoho dokladu dvě částky a nechá na implementaci,
     * která vyhraje.
     */
    public function testDuplicateKeysAreRejectedNotLastWins(): void
    {
        self::assertSame(['a' => 2], json_decode('{"a":1,"a":2}', true),
            'předpoklad testu: PHP samo duplicitu tiše přijme');

        try {
            $this->json()->decode('{"a":1,"a":2}');
            self::fail('duplicitní klíč musí být odmítnut');
        } catch (StrictJsonException $e) {
            self::assertSame('duplicate_key', $e->reasonCode());
        }
    }

    public function testDuplicateKeyIsDetectedInNestedObject(): void
    {
        $this->expectExceptionMessageMatches('/dvakrát/');
        $this->json()->decode('{"doklad":{"castka":1,"castka":2}}');
    }

    /** Stejný klíč v RŮZNÝCH objektech je legitimní a projít musí. */
    public function testSameKeyInDifferentObjectsIsFine(): void
    {
        $out = $this->json()->decode('{"a":{"x":1},"b":{"x":2}}');
        self::assertSame(1, $out['a']['x']);
        self::assertSame(2, $out['b']['x']);
    }

    /**
     * Escapovaná uvozovka uvnitř hodnoty NESMÍ rozhodit hranice řetězce.
     *
     * `{"a":"\"","a":2}` obsahuje SKUTEČNOU duplicitu klíče `a`. Skener, který
     * neumí escapy, si na `\"` rozsynchronizuje hranice řetězců, další uvozovku
     * považuje za začátek nového a duplicitu PŘEHLÉDNE — je to falešně negativní
     * výsledek, tedy ta horší varianta.
     *
     * Předchozí znění tohoto testu bylo prázdné: fixture `{"a":"\"a\": 1","b":2}`
     * projde stejně s escapy i bez nich. Odhaleno mutací, ne review.
     */
    public function testEscapedQuoteInsideValueDoesNotHideDuplicateKey(): void
    {
        try {
            $this->json()->decode('{"a":"\"","a":2}');
            self::fail('duplicita schovaná za escapovanou uvozovkou musí být odhalena');
        } catch (StrictJsonException $e) {
            self::assertSame('duplicate_key', $e->reasonCode());
        }
    }

    /** Klíč uvnitř hodnoty řetězce se za klíč považovat nesmí. */
    public function testKeyLikeTextInsideStringValueIsNotAKey(): void
    {
        $out = $this->json()->decode('{"a":"\"b\": 1","b":2}');
        self::assertSame(2, $out['b'], 'text uvnitř hodnoty není klíč');
    }

    public function testPrototypePollutionKeysAreRejected(): void
    {
        foreach (['__proto__', 'constructor', 'prototype'] as $key) {
            try {
                $this->json()->decode('{"' . $key . '":1}');
                self::fail("klíč {$key} musí být odmítnut");
            } catch (StrictJsonException $e) {
                self::assertSame('forbidden_key', $e->reasonCode(), "u klíče {$key}");
            }
        }
    }

    public function testNumberBeyondSafeIntegerRangeIsRejected(): void
    {
        try {
            $this->json()->decode('{"castka":1.2345678901234568e29}');
            self::fail('číslo mimo bezpečný rozsah musí být odmítnuto');
        } catch (StrictJsonException $e) {
            self::assertSame('number_precision_lost', $e->reasonCode());
        }
    }

    /** Velké CELÉ číslo drží JSON_BIGINT_AS_STRING jako string — přesnost zůstane. */
    public function testLargeIntegerKeepsPrecisionAsString(): void
    {
        $out = $this->json()->decode('{"castka":123456789012345678901234567890}');
        self::assertIsString($out['castka']);
        self::assertSame('123456789012345678901234567890', $out['castka']);
    }

    // -----------------------------------------------------------------------
    // Limity (V79)
    // -----------------------------------------------------------------------

    public function testOversizeInputIsRejected(): void
    {
        $big = '{"a":"' . str_repeat('x', 5000) . '"}';
        try {
            $this->json()->decode($big);
            self::fail('vstup nad limit musí být odmítnut');
        } catch (StrictJsonException $e) {
            self::assertSame('max_bytes', $e->reasonCode());
        }
    }

    public function testTooManyArrayItemsIsRejected(): void
    {
        try {
            $this->json()->decode('{"polozky":[' . implode(',', array_fill(0, 11, '1')) . ']}');
            self::fail('pole nad limit musí být odmítnuto');
        } catch (StrictJsonException $e) {
            self::assertSame('max_array_items', $e->reasonCode());
        }
    }

    public function testTooLongStringIsRejected(): void
    {
        try {
            $this->json()->decode('{"popis":"' . str_repeat('a', 100) . '"}');
            self::fail('řetězec nad limit musí být odmítnut');
        } catch (StrictJsonException $e) {
            self::assertSame('max_string_length', $e->reasonCode());
        }
    }

    // -----------------------------------------------------------------------
    // Pointer a to, co zpráva NESMÍ obsahovat (V82)
    // -----------------------------------------------------------------------

    public function testFindingCarriesResolvablePointer(): void
    {
        try {
            $this->json()->decode('{"doklady":[{"popis":"' . str_repeat('a', 100) . '"}]}');
            self::fail('očekával jsem odmítnutí');
        } catch (StrictJsonException $e) {
            self::assertSame('/doklady/0/popis', $e->pointer());
        }
    }

    public function testMessageNeverEchoesTheOffendingValue(): void
    {
        $secret = str_repeat('TAJNY-OBSAH-DOKLADU', 10);
        try {
            $this->json()->decode('{"popis":"' . $secret . '"}');
            self::fail('očekával jsem odmítnutí');
        } catch (StrictJsonException $e) {
            self::assertStringNotContainsString('TAJNY-OBSAH-DOKLADU', $e->getMessage(),
                'zpráva nesmí vypsat hodnotu — skončí v reportu i v logu');
        }
    }

    // -----------------------------------------------------------------------

    public function testValidDocumentPasses(): void
    {
        $out = $this->json()->decode('{"schema":"x/1","doklady":[{"cislo":"2026001","castka":"1234.56"}]}');
        self::assertSame('x/1', $out['schema']);
        self::assertSame('1234.56', $out['doklady'][0]['castka']);
    }
}
