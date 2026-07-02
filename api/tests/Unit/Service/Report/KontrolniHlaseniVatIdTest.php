<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Normalizace EU VAT ID a kódu státu pro KH oddíl A.2 (issue #164).
 *
 * vatid_dod je alfanumerické (na rozdíl od českého číselného DIČ) — musí zachovat
 * písmena a odříznout jen prefix země; k_stat respektuje DPH kód EU (Řecko EL ≠ ISO GR).
 */
final class KontrolniHlaseniVatIdTest extends TestCase
{
    /** @return list<array{0:?string,1:?string,2:string}> */
    public static function vatIdProvider(): array
    {
        return [
            // [raw VAT ID, country ISO2, expected vatid_dod]
            'IE s písmeny (issue #164)'      => ['IE3668997OH', 'IE', '3668997OH'],
            'IE bez prefixu'                 => ['3668997OH',   'IE', '3668997OH'],
            'AT začíná U'                    => ['ATU12345678', 'AT', 'U12345678'],
            'DE jen číslice'                 => ['DE123456789', 'DE', '123456789'],
            'NL alfanumerické'               => ['NL123456789B01', 'NL', '123456789B01'],
            'Řecko ISO GR, prefix EL'        => ['EL123456789', 'GR', '123456789'],
            'mezery a oddělovače'            => ['IE 366 8997 OH', 'IE', '3668997OH'],
            '3. země bez VAT ID'             => [null, 'US', ''],
            'prázdné'                        => ['', 'IE', ''],
            'bez země neořezává'             => ['IE3668997OH', null, 'IE3668997OH'],
        ];
    }

    #[DataProvider('vatIdProvider')]
    public function testCleanEuVatId(?string $raw, ?string $country, string $expected): void
    {
        $this->assertSame($expected, KontrolniHlaseniBuilder::cleanEuVatId($raw, $country));
    }

    public function testKhCountryCodeMapsGreece(): void
    {
        $this->assertSame('EL', KontrolniHlaseniBuilder::khCountryCode('GR'));
        $this->assertSame('EL', KontrolniHlaseniBuilder::khCountryCode('gr'));
        $this->assertSame('IE', KontrolniHlaseniBuilder::khCountryCode('ie'));
        $this->assertSame('', KontrolniHlaseniBuilder::khCountryCode(null));
    }

    public function testCleanDicStaysNumericForCzech(): void
    {
        // Regrese: české DIČ pořád jen číslice (oddíly A.1/A.4/B.1/B.2).
        $this->assertSame('12345678', KontrolniHlaseniBuilder::cleanDic('CZ12345678'));
        $this->assertSame('12345678', KontrolniHlaseniBuilder::cleanDic('CZ 1234 5678'));
    }
}
