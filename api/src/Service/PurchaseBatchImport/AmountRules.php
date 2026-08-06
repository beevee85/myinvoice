<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

/**
 * FORK (beevee85) — Commit 11b: pravidla částek a součtů (V43–V43e, V81b).
 *
 * SROVNÁNÍ DIVERGENCÍ ZE §14. Analytický skener a produkční `InvoiceAmountPolicy`
 * se dosud lišily; tady se to rozhoduje ve prospěch analytické vrstvy, protože
 * ta stojí na doloženém nálezu z historie:
 *
 *   V43b — popisný řádek (qty=0 ∧ cena=0 ∧ popis≠'') je LEGITIMNÍ, ne chyba.
 *          Produkce ho dnes odmítá („Množství nesmí být 0."). Vynucení původního
 *          pravidla by odmítlo účetně bezvadný dobropis, který v historii je.
 *   V43c — řádek, který tvrdí CENU BEZ MNOŽSTVÍ, je chyba. Produkce ho odmítá
 *          taky, ale z jiného důvodu (obecné qty≠0), takže by ho pustila, kdyby
 *          se V43b rozvolnilo šířeji.
 *   V43d — doklad bez jediného peněžního řádku se dnes NEKONTROLUJE VŮBEC.
 *   V43e — konzistence znamének u dobropisu je dnes jen WARN; tady je FAIL.
 *
 * Tohle NEMĚNÍ chování ruční cesty. `InvoiceAmountPolicy` zůstává, jak je —
 * jeho přepis je změna chování existujícího importu a zaslouží si vlastní commit
 * s charakterizací. Zatím se rozchází jen dokumentovaně (§14).
 *
 * ŽÁDNÝ FLOAT (V81a) — všechno přes `Money`, tedy celé haléře.
 */
final class AmountRules
{
    /** Tolerance na řádku: qty × cena vs. řádkový základ. Katalog V43. */
    private const LINE_TOLERANCE_CENTS = 1;

    /**
     * @param array<string,mixed> $doc  normalizovaný doklad
     * @param string $base              pointer na doklad, např. „/documents/0"
     * @return list<Finding>
     */
    public function validate(array $doc, string $base): array
    {
        $items = $doc['items'] ?? null;
        if (!is_array($items)) {
            return [Finding::fail('V43d', $base . '/items', 'Doklad nemá položky.')];
        }

        $findings   = [];
        $moneyLines = 0;
        $signs      = [];

        foreach ($items as $i => $item) {
            $p = $base . '/items/' . $i;
            if (!is_array($item)) {
                $findings[] = Finding::fail('V43c', $p, 'Položka není objekt.');
                continue;
            }

            $desc = trim((string) ($item['description'] ?? ''));
            $qty  = (string) ($item['quantity'] ?? '');
            $unit = (string) ($item['unit_price_without_vat'] ?? '');

            $qtyZero  = $this->isZeroNumeric($qty);
            $unitZero = $this->isZeroNumeric($unit);

            // --- V43b: popisný řádek -------------------------------------
            if ($qtyZero && $unitZero) {
                if ($desc === '') {
                    // Nulový řádek bez popisu není popis — je to ztracená extrakce.
                    $findings[] = Finding::fail('V43b', $p . '/description',
                        'Nulový řádek musí mít neprázdný popis, jinak jde o ztracený údaj.');
                    continue;
                }
                $findings[] = Finding::info('V43b', $p, 'Popisný řádek, do matematiky nevstupuje.');
                continue;
            }

            // --- V43c: cena bez množství ---------------------------------
            if ($qtyZero && !$unitZero) {
                $findings[] = Finding::fail('V43c', $p . '/quantity',
                    'Řádek uvádí cenu bez množství — přečten chybně.');
                continue;
            }

            // --- V43: peněžní řádek --------------------------------------
            if ($desc === '') {
                $findings[] = Finding::fail('V43', $p . '/description', 'Peněžní řádek musí mít popis.');
            }

            try {
                $unitMoney = Money::parse($unit);
            } catch (MoneyFormatException) {
                $findings[] = Finding::fail('V43', $p . '/unit_price_without_vat',
                    'Jednotková cena není v přijatelném tvaru.')->withValue($unit);
                continue;
            }

            $moneyLines++;
            $signs[] = $this->lineSign($qty, $unitMoney);

            // qty × cena musí sedět na řádkový základ (V43, V43c druhá větev)
            if (array_key_exists('line_base', $item)) {
                try {
                    $declared = Money::parse((string) $item['line_base']);
                    $computed = $unitMoney->multiplyByQuantity($qty);

                    if ($declared->diffCents($computed) > self::LINE_TOLERANCE_CENTS) {
                        $findings[] = Finding::fail('V43c', $p . '/line_base',
                            'Řádkový základ neodpovídá součinu množství a jednotkové ceny.');
                    }
                } catch (MoneyFormatException) {
                    $findings[] = Finding::fail('V43', $p . '/line_base',
                        'Řádkový základ není v přijatelném tvaru.');
                }
            }
        }

        // --- V43d: aspoň jeden peněžní řádek ------------------------------
        if ($moneyLines === 0) {
            $findings[] = Finding::fail('V43d', $base . '/items',
                'Doklad nemá ani jeden peněžní řádek — nesmí být složený jen z popisů.');
        }

        // --- V43e: konzistence znamének u dobropisu -----------------------
        if (($doc['document_kind'] ?? '') === 'credit_note' && $signs !== []) {
            $unique = array_values(array_unique(array_filter($signs, static fn (int $s) => $s !== 0)));
            if (count($unique) > 1) {
                $findings[] = Finding::fail('V43e', $base . '/items',
                    'Dobropis míchá kladné a záporné peněžní řádky.');
            }
        }

        return array_merge($findings, $this->validateTotals($doc, $base));
    }

    /**
     * V81b — HRANIČNÍ KONTROLA. Nepředěláváme, jak se počítá; ověřujeme, co
     * vyšlo, a to proti PŘESNÝM identitám. Tolerance je nula: rozdíl v součtu
     * není zaokrouhlení, je to chyba čtení. Zaokrouhlení má vlastní řádek.
     *
     * @param array<string,mixed> $doc
     * @return list<Finding>
     */
    private function validateTotals(array $doc, string $base): array
    {
        $totals = $doc['totals'] ?? null;
        if (!is_array($totals)) {
            return [Finding::fail('V81b', $base . '/totals', 'Doklad nemá rekapitulaci.')];
        }

        try {
            $sumBase  = Money::parse((string) ($totals['base'] ?? ''));
            $sumVat   = Money::parse((string) ($totals['vat'] ?? ''));
            $sumTotal = Money::parse((string) ($totals['total'] ?? ''));
        } catch (MoneyFormatException) {
            return [Finding::fail('V81b', $base . '/totals',
                'Rekapitulace obsahuje částku v nepřijatelném tvaru.')];
        }

        $findings = [];

        // základ + DPH = celkem, PŘESNĚ
        if (!$sumBase->add($sumVat)->equals($sumTotal)) {
            $findings[] = Finding::fail('V81b', $base . '/totals/total',
                'Základ plus DPH se nerovná celkové částce.');
        }

        // Součet řádkových základů po sazbách musí sedět na základ, tolerance nula.
        // Rozdíl smí existovat JEN jako řádek se zaokrouhlením.
        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];
        $lineSum   = Money::zero();
        $roundingLine = Money::zero();
        $hasRounding  = false;

        foreach ($items as $item) {
            if (!is_array($item) || !array_key_exists('line_base', $item)) {
                continue;
            }
            try {
                $v = Money::parse((string) $item['line_base']);
            } catch (MoneyFormatException) {
                continue; // nahlášeno výš u řádku
            }
            if (!empty($item['is_settlement_rounding'])) {
                $roundingLine = $roundingLine->add($v);
                $hasRounding  = true;
                continue;
            }
            $lineSum = $lineSum->add($v);
        }

        if ($items !== [] && !$lineSum->add($roundingLine)->equals($sumBase)) {
            $findings[] = Finding::fail('V81b', $base . '/totals/base',
                $hasRounding
                    ? 'Součet řádků včetně zaokrouhlovacího řádku se nerovná základu.'
                    : 'Součet řádkových základů se nerovná základu; rozdíl smí projít jen jako řádek se zaokrouhlením.');
        }

        return $findings;
    }

    // -----------------------------------------------------------------------

    /** Bez `Money`, protože množství smí mít tři desetinná místa. */
    private function isZeroNumeric(string $v): bool
    {
        $v = trim($v);
        if ($v === '') {
            return true;
        }

        return (bool) preg_match('/^-?0(\.0+)?$/', $v);
    }

    /** −1, 0 nebo +1 podle výsledného znaménka řádku. */
    private function lineSign(string $qty, Money $unit): int
    {
        $qtyNeg  = str_starts_with(trim($qty), '-');
        $unitNeg = $unit->isNegative();

        if ($unit->isZero()) {
            return 0;
        }

        return ($qtyNeg !== $unitNeg) ? -1 : 1;
    }
}
