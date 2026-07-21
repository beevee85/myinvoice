<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IdokladImportService;
use PHPUnit\Framework\TestCase;

/**
 * #197 — incremental filtr iDoklad v3 musí mít tvar `column~operator~value`
 * (separátor `~`), jinak API vrací HTTP 400 „Incorrect filter format".
 * #196 — varsymbol importovaného dokladu = číslo dokladu (unikátní), ne
 * platební VariableSymbol (opakuje se u paušálů → kolize na UNIQUE indexu).
 */
final class IdokladIncrementalFilterTest extends TestCase
{
    public function testIncrementalFilterUsesTildeGteFormat(): void
    {
        self::assertSame(
            ['filter' => 'DateLastChange~gte~2026-07-09'],
            IdokladImportService::incrementalFilter('2026-07-09'),
        );
    }

    public function testIncrementalFilterEmptyWhenNoBookmark(): void
    {
        self::assertSame([], IdokladImportService::incrementalFilter(null));
    }

    /** Nikdy negenerujeme starý tvar `>=`, který API odmítalo. */
    public function testIncrementalFilterNeverUsesBareComparison(): void
    {
        $filter = IdokladImportService::incrementalFilter('2026-01-01')['filter'] ?? '';
        self::assertStringNotContainsString('>=', $filter);
        self::assertStringContainsString('~gte~', $filter);
    }

    // ── #196 varsymbol = DocumentNumber, ne VariableSymbol ──────────────────────

    public function testDocNumberPrefersDocumentNumberOverVariableSymbol(): void
    {
        // Paušál: stejný VS na více fakturách, ale DocumentNumber je unikátní.
        $i = ['DocumentNumber' => '20260123', 'VariableSymbol' => '1234567890'];
        self::assertSame('20260123', IdokladImportService::idokladDocNumber($i));
    }

    public function testDocNumberFallsBackToVariableSymbol(): void
    {
        $i = ['DocumentNumber' => '', 'VariableSymbol' => '4242'];
        self::assertSame('4242', IdokladImportService::idokladDocNumber($i));
    }

    public function testDocNumberEmptyWhenNeitherPresent(): void
    {
        self::assertSame('', IdokladImportService::idokladDocNumber([]));
    }
}
