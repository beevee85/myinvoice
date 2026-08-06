<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

/**
 * FORK (beevee85) — Commit 11a: peníze bez `float` (V81a).
 *
 * Částka se drží jako CELÉ ČÍSLO HALÉŘŮ. Parsuje se ze stringu, počítá se
 * v celých číslech, zpátky se serializuje jako string. `float` se v téhle cestě
 * neobjeví ani na okamžik — jednou ztracená přesnost už se nevrátí a u dokladu,
 * který jde do účetnictví, se to projeví až při kontrole, tedy pozdě.
 *
 * PROČ VLASTNÍ TŘÍDA A NE `bcmath`: chceme, aby nepřijatelný vstup skončil
 * výjimkou s důvodem, ne tichým zaokrouhlením. `bcadd('1,5', '1')` vrátí `1`
 * bez varování — přesně ten druh selhání, který se pozná až v rozvaze.
 *
 * ROZSAH: `PHP_INT_MAX` haléřů je řádově 9·10^16, tedy ~92 biliard korun.
 * Doklad, který to překročí, je chyba čtení, ne obchodní případ.
 */
final class Money
{
    private function __construct(public readonly int $cents) {}

    /**
     * Přijímá jen kanonický tvar: volitelné `-`, číslice, volitelně tečka
     * a nejvýš dvě desetinná místa.
     *
     * ODMÍTÁ vědomě: čárku jako oddělovač, mezery a oddělovače tisíců, vědeckou
     * notaci, tři a víc desetinných míst, prázdný řetězec, `+` na začátku.
     * Nic z toho se „nedopravuje" — u peněz je hádání horší než odmítnutí.
     */
    public static function parse(string $raw): self
    {
        if (!preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/', $raw)) {
            throw new MoneyFormatException($raw);
        }

        $neg   = str_starts_with($raw, '-');
        $body  = $neg ? substr($raw, 1) : $raw;
        $parts = explode('.', $body, 2);

        $whole = $parts[0];
        $frac  = str_pad($parts[1] ?? '', 2, '0');

        // Kontrola rozsahu PŘED přetypováním — jinak by PHP tiše přeteklo do floatu.
        if (strlen($whole) > 15) {
            throw new MoneyFormatException($raw);
        }

        $cents = ((int) $whole) * 100 + (int) $frac;

        return new self($neg ? -$cents : $cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function sub(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    /**
     * Násobení množstvím. Množství smí mít víc desetinných míst než peníze
     * (kusovník běžně vede tři), takže se počítá v tisícinách a AŽ VÝSLEDEK
     * se zaokrouhluje — half-up, protože tak to dělá zbytek aplikace.
     */
    public function multiplyByQuantity(string $quantity): self
    {
        if (!preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]{1,3})?$/', $quantity)) {
            throw new MoneyFormatException($quantity);
        }

        $neg   = str_starts_with($quantity, '-');
        $body  = $neg ? substr($quantity, 1) : $quantity;
        $parts = explode('.', $body, 2);
        $milli = ((int) $parts[0]) * 1000 + (int) str_pad($parts[1] ?? '', 3, '0');

        $product = $this->cents * $milli;                 // haléře × tisíciny
        $rounded = intdiv(abs($product) + 500, 1000);      // half-up
        $sign    = ($product < 0) !== $neg ? -1 : 1;

        return new self($product === 0 ? 0 : $sign * $rounded);
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    /** Absolutní rozdíl v haléřích — pro tolerance. */
    public function diffCents(self $other): int
    {
        return abs($this->cents - $other->cents);
    }

    /**
     * Round-trip musí být znak po znaku identický se vstupem v kanonickém tvaru
     * — na tom stojí `testMoneyRoundTripIsExact`.
     */
    public function __toString(): string
    {
        $neg   = $this->cents < 0;
        $abs   = abs($this->cents);
        $whole = intdiv($abs, 100);
        $frac  = $abs % 100;

        return ($neg ? '-' : '') . $whole . '.' . str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
    }
}
