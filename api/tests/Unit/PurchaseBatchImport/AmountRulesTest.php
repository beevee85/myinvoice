<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\PurchaseBatchImport;

use MyInvoice\Service\PurchaseBatchImport\AmountRules;
use MyInvoice\Service\PurchaseBatchImport\Finding;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — pravidla částek a součtů (V43–V43e, V81b).
 *
 * MOTIVUJÍCÍ DOKLAD JE REÁLNÝ, FIXTURE JE SYNTETICKÁ. V historii existuje
 * zaplacený dobropis s pěti řádky, z toho čtyřmi popisnými (qty=0 i cena=0,
 * popis 35–49 znaků), jehož součty sedí. Vynucení původní V43 („quantity > 0")
 * by ho odmítlo — chyba nebyla v datech, ale v pravidle. Ta struktura je tady
 * přepsaná na kulaté částky; reálný doklad se do repa nedostane.
 */
final class AmountRulesTest extends TestCase
{
    private function rules(): AmountRules
    {
        return new AmountRules();
    }

    /** @param list<Finding> $findings @return list<string> */
    private function ruleIds(array $findings): array
    {
        return array_map(static fn (Finding $f) => $f->ruleId, $findings);
    }

    /** @param list<Finding> $findings */
    private function failIds(array $findings): array
    {
        return array_values(array_map(
            static fn (Finding $f) => $f->ruleId,
            array_filter($findings, static fn (Finding $f) => $f->severity === Finding::FAIL),
        ));
    }

    private function moneyLine(string $desc, string $qty, string $unit, ?string $base = null): array
    {
        $l = ['description' => $desc, 'quantity' => $qty, 'unit_price_without_vat' => $unit];
        if ($base !== null) {
            $l['line_base'] = $base;
        }
        return $l;
    }

    private function textLine(string $desc): array
    {
        return ['description' => $desc, 'quantity' => '0', 'unit_price_without_vat' => '0'];
    }

    private function doc(array $items, string $base, string $vat, string $total, string $kind = 'invoice'): array
    {
        return [
            'document_kind' => $kind,
            'items'         => $items,
            'totals'        => ['base' => $base, 'vat' => $vat, 'total' => $total],
        ];
    }

    // -----------------------------------------------------------------------
    // V43b — popisné řádky jsou legitimní (srovnání divergence ze §14)
    // -----------------------------------------------------------------------

    /**
     * Struktura motivujícího dobropisu: 4 popisné řádky + 1 peněžní, součty sedí.
     * Nesmí padnout ani jeden FAIL — přesně tenhle doklad by původní V43 odmítla.
     */
    public function testDocumentWithFourTextLinesAndOneMoneyLinePasses(): void
    {
        $items = [
            $this->textLine('Dobropis k faktuře za servisní práce provedené v květnu'),
            $this->textLine('Původní doklad byl uhrazen v plné výši dne 15. 5.'),
            $this->textLine('Důvod dobropisu: reklamace uznána v plném rozsahu'),
            $this->textLine('Částka bude vrácena na účet uvedený v hlavičce'),
            $this->moneyLine('Vrácení uhrazené částky', '1', '-10000.00', '-10000.00'),
        ];

        $f = $this->rules()->validate($this->doc($items, '-10000.00', '-2100.00', '-12100.00', 'credit_note'), '/documents/0');

        self::assertSame([], $this->failIds($f), 'účetně bezvadný dobropis nesmí mít FAIL');
        self::assertContains('V43b', $this->ruleIds($f), 'popisné řádky se hlásí jako INFO text_line');
    }

    public function testTextLineIsReportedAsInfoNotFailure(): void
    {
        $f = $this->rules()->validate($this->doc([
            $this->textLine('Poznámka k dodávce'),
            $this->moneyLine('Zboží', '1', '100.00', '100.00'),
        ], '100.00', '21.00', '121.00'), '/documents/0');

        $info = array_values(array_filter($f, static fn (Finding $x) => $x->severity === Finding::INFO));
        self::assertCount(1, $info);
        self::assertSame('V43b', $info[0]->ruleId);
    }

    /** Nulový řádek BEZ popisu je ztracená extrakce, ne popis. */
    public function testZeroLineWithEmptyDescriptionIsAFailure(): void
    {
        $f = $this->rules()->validate($this->doc([
            ['description' => '', 'quantity' => '0', 'unit_price_without_vat' => '0'],
            $this->moneyLine('Zboží', '1', '100.00', '100.00'),
        ], '100.00', '21.00', '121.00'), '/documents/0');

        self::assertContains('V43b', $this->failIds($f));
    }

    // -----------------------------------------------------------------------
    // V43c — cena bez množství
    // -----------------------------------------------------------------------

    public function testZeroQuantityWithPriceStaysAFinding(): void
    {
        $f = $this->rules()->validate($this->doc([
            ['description' => 'Podezřelý řádek', 'quantity' => '0', 'unit_price_without_vat' => '500.00'],
            $this->moneyLine('Zboží', '1', '100.00', '100.00'),
        ], '100.00', '21.00', '121.00'), '/documents/0');

        self::assertContains('V43c', $this->failIds($f),
            'řádek, který tvrdí cenu bez množství, musí zůstat nálezem');
    }

    public function testLineBaseNotMatchingQuantityTimesPriceIsAFailure(): void
    {
        $f = $this->rules()->validate($this->doc([
            $this->moneyLine('Zboží', '3', '100.00', '250.00'),   // má být 300.00
        ], '250.00', '52.50', '302.50'), '/documents/0');

        self::assertContains('V43c', $this->failIds($f));
    }

    public function testLineBaseWithinOneHellerToleranceIsAccepted(): void
    {
        $f = $this->rules()->validate($this->doc([
            $this->moneyLine('Zboží', '3', '33.33', '99.98'),     // přesně 99.99, odchylka 1 haléř
        ], '99.98', '21.00', '120.98'), '/documents/0');

        self::assertNotContains('V43c', $this->failIds($f), 'jeden haléř je v toleranci');
    }

    // -----------------------------------------------------------------------
    // V43d — aspoň jeden peněžní řádek
    // -----------------------------------------------------------------------

    public function testDocumentMadeOnlyOfTextLinesIsAFinding(): void
    {
        $f = $this->rules()->validate($this->doc([
            $this->textLine('První poznámka'),
            $this->textLine('Druhá poznámka'),
        ], '0.00', '0.00', '0.00'), '/documents/0');

        self::assertContains('V43d', $this->failIds($f),
            'doklad složený jen z popisů nemá co zaúčtovat');
    }

    public function testDocumentWithoutItemsIsAFinding(): void
    {
        $f = $this->rules()->validate(['document_kind' => 'invoice'], '/documents/0');
        self::assertContains('V43d', $this->failIds($f));
    }

    // -----------------------------------------------------------------------
    // V43e — znaménka u dobropisu
    // -----------------------------------------------------------------------

    public function testCreditNoteWithMixedSignsIsAFailure(): void
    {
        $f = $this->rules()->validate($this->doc([
            $this->moneyLine('Vrácení A', '1', '-500.00', '-500.00'),
            $this->moneyLine('Přirážka B', '1', '200.00', '200.00'),
        ], '-300.00', '-63.00', '-363.00', 'credit_note'), '/documents/0');

        self::assertContains('V43e', $this->failIds($f));
    }

    public function testCreditNoteWithConsistentSignsPasses(): void
    {
        $f = $this->rules()->validate($this->doc([
            $this->moneyLine('Vrácení A', '1', '-500.00', '-500.00'),
            $this->moneyLine('Vrácení B', '1', '-200.00', '-200.00'),
        ], '-700.00', '-147.00', '-847.00', 'credit_note'), '/documents/0');

        self::assertNotContains('V43e', $this->failIds($f));
    }

    /** Mix znamének na běžné faktuře (sleva) je legitimní — V43e platí jen na dobropis. */
    public function testMixedSignsOnRegularInvoiceAreAllowed(): void
    {
        $f = $this->rules()->validate($this->doc([
            $this->moneyLine('Zboží', '1', '1000.00', '1000.00'),
            $this->moneyLine('Sleva 10 %', '1', '-100.00', '-100.00'),
        ], '900.00', '189.00', '1089.00'), '/documents/0');

        self::assertNotContains('V43e', $this->failIds($f));
    }

    // -----------------------------------------------------------------------
    // V81b — hraniční kontrola součtů, tolerance nula
    // -----------------------------------------------------------------------

    public function testPerRateTotalsMustMatchExactly(): void
    {
        $f = $this->rules()->validate($this->doc([
            $this->moneyLine('Zboží', '1', '100.00', '100.00'),
        ], '100.00', '21.00', '121.01'), '/documents/0');   // o haléř vedle

        self::assertContains('V81b', $this->failIds($f),
            'základ + DPH se musí rovnat celkem PŘESNĚ, jeden haléř je chyba');
    }

    public function testLineSumMustEqualBase(): void
    {
        $f = $this->rules()->validate($this->doc([
            $this->moneyLine('A', '1', '100.00', '100.00'),
            $this->moneyLine('B', '1', '50.00', '50.00'),
        ], '160.00', '33.60', '193.60'), '/documents/0');    // řádky dávají 150

        self::assertContains('V81b', $this->failIds($f));
    }

    /** Rozdíl ze zaokrouhlení smí projít JEN jako řádek s is_settlement_rounding. */
    public function testRoundingDifferenceOnlyViaSettlementLine(): void
    {
        $withFlag = $this->rules()->validate($this->doc([
            $this->moneyLine('Zboží', '1', '99.99', '99.99'),
            ['description' => 'Zaokrouhlení', 'quantity' => '1',
             'unit_price_without_vat' => '0.01', 'line_base' => '0.01',
             'is_settlement_rounding' => true],
        ], '100.00', '21.00', '121.00'), '/documents/0');

        self::assertNotContains('V81b', $this->failIds($withFlag),
            'zaokrouhlovací řádek se do součtu započítá a doklad projde');

        $withoutFlag = $this->rules()->validate($this->doc([
            $this->moneyLine('Zboží', '1', '99.99', '99.99'),
        ], '100.00', '21.00', '121.00'), '/documents/0');

        self::assertContains('V81b', $this->failIds($withoutFlag),
            'bez zaokrouhlovacího řádku je rozdíl chyba, ne zaokrouhlení');
    }

    /** Struktura konečné faktury na nulu — existuje v historii dvakrát. */
    public function testFinalInvoiceNettedToZeroPasses(): void
    {
        $f = $this->rules()->validate($this->doc([
            $this->moneyLine('Vozidlo', '1', '500000.00', '500000.00'),
            $this->moneyLine('Odpočet zálohy 1', '1', '-50000.00', '-50000.00'),
            $this->moneyLine('Odpočet zálohy 2', '1', '-450000.00', '-450000.00'),
        ], '0.00', '0.00', '0.00'), '/documents/0');

        self::assertSame([], $this->failIds($f),
            'konečná faktura vynulovaná odpočty § 37a musí projít — peněžní řádky má');
    }

    // -----------------------------------------------------------------------

    public function testAmountInWrongFormatIsReportedWithoutEchoingIt(): void
    {
        $f = $this->rules()->validate($this->doc([
            $this->moneyLine('Zboží', '1', '1 234,56'),
        ], '1234.56', '259.26', '1493.82'), '/documents/0');

        self::assertContains('V43', $this->failIds($f));
        foreach ($f as $finding) {
            self::assertStringNotContainsString('1 234,56', $finding->message,
                'částka se do zprávy nevypisuje');
        }
    }

    public function testEveryFindingHasPointerIntoTheDocument(): void
    {
        $f = $this->rules()->validate($this->doc([
            ['description' => '', 'quantity' => '0', 'unit_price_without_vat' => '0'],
        ], '5.00', '1.05', '6.05'), '/documents/3');

        self::assertNotSame([], $f);
        foreach ($f as $finding) {
            self::assertStringStartsWith('/documents/3', $finding->pointer,
                'nález musí ukazovat do svého dokladu');
        }
    }
}
