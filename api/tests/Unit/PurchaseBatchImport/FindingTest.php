<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\PurchaseBatchImport;

use MyInvoice\Service\PurchaseBatchImport\Finding;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — nález doménové validace (V82).
 *
 * Těžiště: redakce musí platit i pro pole, o kterém nikdo nepřemýšlel. Test,
 * který kontroluje jen známá pole, by tuhle vlastnost nezachytil — a právě
 * neznámé pole je ten případ, kdy redakce rozhoduje.
 */
final class FindingTest extends TestCase
{
    public function testUnknownFieldIsRedactedWithoutCodeChange(): void
    {
        $f = Finding::fail('V26', '/documents/0/vendor/bank_account', 'Neplatný údaj.')
            ->withValue('CZ6508000000192000145399');

        self::assertStringNotContainsString('192000145399', $f->message,
            'pole, které nikdo nepřidal na allow-list, se redigovat MUSÍ');
        self::assertStringContainsString('24 znaků', $f->message,
            'místo hodnoty se uvede typ a délka');
    }

    public function testEchoAllowListIsExactlyAsDocumented(): void
    {
        self::assertSame(
            [
                'document_kind', 'currency', 'vat_rate', 'vat_rate_id', 'relation',
                'confidence', 'schema', 'limit_name', 'field_count', 'index',
            ],
            Finding::echoableFields(),
            'změna allow-listu je bezpečnostní rozhodnutí — musí být vidět v diffu a shodit tenhle test',
        );
    }

    public function testAllowedFieldValueIsEchoed(): void
    {
        $f = Finding::fail('V55', '/documents/0/document_kind', 'Neznámý typ dokladu.')
            ->withValue('faktura-danova');

        self::assertStringContainsString('faktura-danova', $f->message,
            'kód z číselníku se vypsat smí — bez něj je hláška nepoužitelná');
    }

    /**
     * Jméno pole se bere z pointeru, ne z parametru. Jinak by šlo cizí pole
     * „prohlásit" za povolené a redakci obejít.
     */
    public function testEchoDecisionFollowsPointerNotCallerIntent(): void
    {
        $sensitive = Finding::fail('V17', '/documents/0/vendor/ic', 'Neplatné IČO.')
            ->withValue('28173309');

        self::assertStringNotContainsString('28173309', $sensitive->message,
            'IČO na allow-listu není, takže se nesmí vypsat ani když si o to volající řekne');
    }

    public function testAmountsAreNeverEchoed(): void
    {
        $f = Finding::fail('V44', '/documents/0/totals/total', 'Součet nesedí.')
            ->withValue('500600.00');

        self::assertStringNotContainsString('500600', $f->message,
            'částka identifikuje obchodní vztah — do zprávy nepatří');
        self::assertStringContainsString('znaků', $f->message);
    }

    public function testPointerIsCarriedVerbatim(): void
    {
        $f = Finding::fail('V43c', '/documents/2/items/7/quantity', 'Řádek uvádí cenu bez množství.');

        self::assertSame('/documents/2/items/7/quantity', $f->pointer);
        self::assertSame('V43c', $f->ruleId);
        self::assertSame(Finding::FAIL, $f->severity);
    }

    public function testPointerEscapesAreDecodedForFieldLookup(): void
    {
        // Pole se jménem obsahujícím '/' se v pointeru escapuje jako ~1.
        $f = Finding::info('V12', '/documents/0/source~1confidence', 'Poznámka.')
            ->withValue('vysoka');

        self::assertStringContainsString('text, 6 znaků', $f->message,
            'escapované jméno pole se na allow-listu nesmí trefit náhodou');
    }

    /** V85 — shodný vstup musí dát bajtově shodné pořadí nálezů. */
    public function testFindingOrderIsDeterministic(): void
    {
        $findings = [
            Finding::info('V43b', '/documents/0/items/1', 'Popisný řádek.'),
            Finding::fail('V44', '/documents/0/totals', 'Součet nesedí.'),
            Finding::warn('V27', '/documents/0/numbers', 'Duplicitní číslo.'),
            Finding::fail('V43c', '/documents/0/items/0', 'Cena bez množství.'),
        ];

        $sorted = $findings;
        usort($sorted, static fn (Finding $a, Finding $b) => strcmp($a->sortKey(), $b->sortKey()));

        $shuffled = [$findings[2], $findings[0], $findings[3], $findings[1]];
        usort($shuffled, static fn (Finding $a, Finding $b) => strcmp($a->sortKey(), $b->sortKey()));

        self::assertSame(
            array_map(static fn (Finding $f) => $f->sortKey(), $sorted),
            array_map(static fn (Finding $f) => $f->sortKey(), $shuffled),
            'pořadí vzniku nálezů nesmí ovlivnit pořadí ve výstupu',
        );

        // FAIL má přednost před WARN a ten před INFO.
        self::assertSame(Finding::FAIL, $sorted[0]->severity);
        self::assertSame(Finding::INFO, $sorted[3]->severity);
    }

    public function testToArrayShape(): void
    {
        $a = Finding::warn('V57', '/documents/1/items', 'Smíšená znaménka.')->toArray();

        self::assertSame(['rule', 'severity', 'pointer', 'message'], array_keys($a));
        self::assertSame('V57', $a['rule']);
        self::assertSame('warn', $a['severity']);
    }

    public function testNullAndStructuredValuesAreDescribedNotDumped(): void
    {
        self::assertStringContainsString('null',
            Finding::fail('V5', '/documents/0/sha256', 'Chybí.')->withValue(null)->message);

        self::assertStringContainsString('pole, 3 prvků',
            Finding::fail('V43d', '/documents/0/items', 'Chybí peněžní řádek.')->withValue([1, 2, 3])->message);
    }
}
