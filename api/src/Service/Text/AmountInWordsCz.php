<?php

declare(strict_types=1);

namespace MyInvoice\Service\Text;

/**
 * FORK (beevee85): částka slovy česky pro pokladní doklady.
 *
 * Pokrývá 0 až miliardy, haléře jako zlomek „xx/100". Měny CZK/EUR mají
 * skloňované názvy, ostatní kódy se přikládají za číslovku beze změny.
 */
final class AmountInWordsCz
{
    private const ONES = ['', 'jedna', 'dvě', 'tři', 'čtyři', 'pět', 'šest', 'sedm', 'osm', 'devět',
        'deset', 'jedenáct', 'dvanáct', 'třináct', 'čtrnáct', 'patnáct', 'šestnáct', 'sedmnáct', 'osmnáct', 'devatenáct'];
    private const TENS = ['', '', 'dvacet', 'třicet', 'čtyřicet', 'padesát', 'šedesát', 'sedmdesát', 'osmdesát', 'devadesát'];
    private const HUNDREDS = ['', 'sto', 'dvě stě', 'tři sta', 'čtyři sta', 'pět set', 'šest set', 'sedm set', 'osm set', 'devět set'];

    /** [jednotné, 2–4, 5+] pro skupiny řádů */
    private const GROUPS = [
        1 => ['tisíc', 'tisíce', 'tisíc'],
        2 => ['milion', 'miliony', 'milionů'],
        3 => ['miliarda', 'miliardy', 'miliard'],
    ];

    private const CURRENCY = [
        'CZK' => ['koruna česká', 'koruny české', 'korun českých'],
        'EUR' => ['euro', 'eura', 'eur'],
    ];

    public function convert(float $amount, string $currency = 'CZK'): string
    {
        $whole = (int) floor(abs($amount));
        $cents = (int) round((abs($amount) - $whole) * 100);
        if ($cents === 100) { // zaokrouhlení 0.999...
            $whole++;
            $cents = 0;
        }

        $words = $whole === 0 ? 'nula' : trim($this->number($whole));
        $words .= ' ' . $this->currencyWord($whole, strtoupper($currency));
        if ($cents > 0) {
            $words .= ' ' . $cents . '/100';
        }
        return ($amount < 0 ? 'minus ' : '') . $words;
    }

    private function number(int $n): string
    {
        if ($n === 0) return '';
        $groups = [];
        while ($n > 0) {
            $groups[] = $n % 1000;
            $n = intdiv($n, 1000);
        }
        $parts = [];
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            $g = $groups[$i];
            if ($g === 0) continue;
            $text = $this->belowThousand($g, $i > 0);
            if ($i > 0) {
                $text .= ' ' . $this->plural($g, self::GROUPS[$i]);
            }
            $parts[] = trim($text);
        }
        return implode(' ', $parts);
    }

    private function belowThousand(int $n, bool $groupContext): string
    {
        $out = [];
        if ($n >= 100) {
            $out[] = self::HUNDREDS[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n >= 20) {
            $t = self::TENS[intdiv($n, 10)];
            $o = $n % 10;
            $out[] = $o > 0 ? $t . ' ' . $this->one($o, $groupContext) : $t;
        } elseif ($n >= 10) {
            $out[] = self::ONES[$n];
        } elseif ($n > 0) {
            $out[] = $this->one($n, $groupContext);
        }
        return implode(' ', $out);
    }

    /** „jeden tisíc / jedna koruna", „dva miliony / dvě koruny" — v řádové skupině mužský rod. */
    private function one(int $n, bool $groupContext): string
    {
        if ($groupContext) {
            if ($n === 1) return 'jeden';
            if ($n === 2) return 'dva';
        }
        return self::ONES[$n];
    }

    /** @param array{0:string,1:string,2:string} $forms */
    private function plural(int $n, array $forms): string
    {
        $last = $n % 100;
        if ($last === 1) return $forms[0];
        if ($last >= 2 && $last <= 4) return $forms[1];
        return $forms[2];
    }

    private function currencyWord(int $whole, string $currency): string
    {
        if (!isset(self::CURRENCY[$currency])) {
            return $currency;
        }
        return $this->plural($whole === 0 ? 5 : $whole, self::CURRENCY[$currency]);
    }
}
