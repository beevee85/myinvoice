<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

/**
 * FORK (beevee85) — Commit 9: brána na nepřátelský JSON (V80).
 *
 * `results.json` je NEDŮVĚRYHODNÝ VSTUP. Neprošel naší validací, přišel zvenčí
 * a serveru se nevěří ani jedno číslo. Tahle třída je první síto.
 *
 * ROZSAH JE ODVOZENÝ Z MĚŘENÍ, NE Z KATALOGU. `json_decode()` v PHP 8.5 sám
 * odmítne `NaN`, `Infinity`, vedoucí nulu, `+1`, hex literál, jednoduché
 * uvozovky, koncovou čárku, komentář, BOM, `NUL` v řetězci i osamocený
 * surrogát — ověřeno spuštěním, ne přečtením dokumentace. Psát to znovu by
 * znamenalo udržovat druhou, horší implementaci téhož.
 *
 * ZBÝVAJÍ ČTYŘI DÍRY, které `json_decode()` propustí, a ty řeší tahle třída:
 *
 *   1. DUPLICITNÍ KLÍČE — `{"a":1,"a":2}` se tiše scvrkne na `a=2`. To není
 *      kosmetika: útočník tím může do jednoho dokladu vložit dvě různé částky
 *      a nechat na implementaci, která vyhraje. Musí se hledat v SYROVÉM textu,
 *      protože po dekódování už je pozdě.
 *   2. `__proto__`, `constructor`, `prototype` — v PHP nejsou nebezpečné jako
 *      v JS, ale `results.json` projde i frontendem a tam nebezpečné jsou.
 *   3. VELKÁ ČÍSLA — `123456789012345678901234567890` PHP tiše převede na float
 *      a ztratí přesnost. U peněz je to nepřijatelné (V81a).
 *   4. NEOBJEKTOVÝ KOŘEN — pole nebo skalár na nejvyšší úrovni.
 *
 * K tomu limity V79 (hloubka, velikost, počty), aby se vstupem nešlo vyčerpat
 * paměť dřív, než se k validaci vůbec dostaneme.
 */
final class StrictJson
{
    /** Klíče, které nesmí projít ani v PHP — `results.json` čte i frontend. */
    private const FORBIDDEN_KEYS = ['__proto__', 'constructor', 'prototype'];

    public function __construct(
        private readonly int $maxBytes = 2 * 1024 * 1024,
        private readonly int $maxDepth = 20,
        private readonly int $maxArrayItems = 1000,
        private readonly int $maxObjectKeys = 200,
        private readonly int $maxStringLength = 4096,
    ) {}

    /**
     * @return array<string,mixed>
     * @throws StrictJsonException
     */
    public function decode(string $raw): array
    {
        if ($raw === '') {
            throw new StrictJsonException('empty_input', 'Prázdný vstup.', '');
        }
        if (strlen($raw) > $this->maxBytes) {
            throw new StrictJsonException(
                'max_bytes',
                sprintf('Vstup má %d B, limit je %d B.', strlen($raw), $this->maxBytes),
                '',
            );
        }

        // 1. Duplicitní klíče — POUZE ze syrového textu. Po json_decode() už
        //    informace neexistuje, takže tenhle krok nejde přeskočit ani přesunout.
        $this->assertNoDuplicateKeys($raw);

        // 2. Vlastní parser PHP. JSON_BIGINT_AS_STRING drží velká celá čísla jako
        //    string místo tichého převodu na float.
        try {
            $data = json_decode($raw, true, $this->maxDepth, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new StrictJsonException('malformed_json', $e->getMessage(), '');
        }

        // 3. Kořen musí být objekt.
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new StrictJsonException('root_not_object', 'Kořen dokumentu musí být objekt.', '');
        }

        // 4. Průchod stromem — zakázané klíče, limity, přesnost čísel.
        $this->walk($data, '', 0);

        return $data;
    }

    // -----------------------------------------------------------------------

    /**
     * Minimální skener syrového JSON. Nezajímá ho hodnota, jen jestli se
     * v jednom objektu neopakuje klíč. Řetězce se přeskakují včetně escapů,
     * jinak by `{"a\":1,\"a":2}` skener zmátlo.
     */
    private function assertNoDuplicateKeys(string $raw): void
    {
        $len   = strlen($raw);
        $stack = [];        // zásobník objektů; každý drží množinu svých klíčů
        $inObj = [];        // pro každou úroveň: jsme v objektu (true) nebo v poli
        $expectKey = false;
        $i = 0;

        while ($i < $len) {
            $ch = $raw[$i];

            if ($ch === '"') {
                [$str, $i] = $this->scanString($raw, $i);
                if ($expectKey && ($inObj[count($inObj) - 1] ?? false)) {
                    $top = count($stack) - 1;
                    if (isset($stack[$top][$str])) {
                        throw new StrictJsonException(
                            'duplicate_key',
                            sprintf('Klíč „%s" je v jednom objektu uvedený dvakrát.', $str),
                            '',
                        );
                    }
                    $stack[$top][$str] = true;
                    $expectKey = false;
                }
                continue;
            }

            if ($ch === '{') {
                $stack[] = [];
                $inObj[] = true;
                $expectKey = true;
            } elseif ($ch === '[') {
                $inObj[] = false;
                $expectKey = false;
            } elseif ($ch === '}') {
                array_pop($stack);
                array_pop($inObj);
                $expectKey = false;
            } elseif ($ch === ']') {
                array_pop($inObj);
                $expectKey = false;
            } elseif ($ch === ',') {
                $expectKey = (bool) ($inObj[count($inObj) - 1] ?? false);
            }

            $i++;
        }
    }

    /** @return array{0: string, 1: int} dekódovaný obsah řetězce a index ZA ním */
    private function scanString(string $raw, int $start): array
    {
        $len = strlen($raw);
        $i   = $start + 1;
        $out = '';

        while ($i < $len) {
            $c = $raw[$i];
            if ($c === '\\') {
                $out .= $c . ($raw[$i + 1] ?? '');
                $i += 2;
                continue;
            }
            if ($c === '"') {
                return [$out, $i + 1];
            }
            $out .= $c;
            $i++;
        }

        // Neuzavřený řetězec — json_decode to stejně odmítne, tady jen nezacyklit.
        return [$out, $len];
    }

    /** @param array<mixed> $node */
    private function walk(array $node, string $pointer, int $depth): void
    {
        if ($depth > $this->maxDepth) {
            throw new StrictJsonException('max_depth', 'Překročena povolená hloubka zanoření.', $pointer);
        }

        $isList = $node !== [] && array_is_list($node);

        if ($isList && count($node) > $this->maxArrayItems) {
            throw new StrictJsonException(
                'max_array_items',
                sprintf('Pole má %d prvků, limit je %d.', count($node), $this->maxArrayItems),
                $pointer,
            );
        }
        if (!$isList && count($node) > $this->maxObjectKeys) {
            throw new StrictJsonException(
                'max_object_keys',
                sprintf('Objekt má %d klíčů, limit je %d.', count($node), $this->maxObjectKeys),
                $pointer,
            );
        }

        foreach ($node as $key => $value) {
            $childPointer = $pointer . '/' . $this->escapePointer((string) $key);

            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                throw new StrictJsonException(
                    'forbidden_key',
                    sprintf('Klíč „%s" není povolený.', $key),
                    $childPointer,
                );
            }

            if (is_array($value)) {
                $this->walk($value, $childPointer, $depth + 1);
                continue;
            }
            if (is_string($value)) {
                if (strlen($value) > $this->maxStringLength) {
                    throw new StrictJsonException(
                        'max_string_length',
                        sprintf('Řetězec má %d znaků, limit je %d.', strlen($value), $this->maxStringLength),
                        $childPointer,
                    );
                }
                continue;
            }
            if (is_float($value)) {
                // Float v datech znamená, že PHP už přesnost ztratilo — u peněz
                // je to neopravitelné, takže se to musí odmítnout tady, ne později.
                if (!is_finite($value) || abs($value) > 9007199254740991.0) {
                    throw new StrictJsonException(
                        'number_precision_lost',
                        'Číslo je mimo rozsah, ve kterém lze zaručit přesnost.',
                        $childPointer,
                    );
                }
            }
        }
    }

    /** RFC 6901 — `~` a `/` se v pointeru escapují. */
    private function escapePointer(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
