<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

/**
 * FORK (beevee85) — Commit 11a: jeden nález doménové validace (V82).
 *
 * REDAKCE JE VÝCHOZÍ STAV, ne volba volajícího. Hodnota se do zprávy dostane
 * jedině tehdy, když je její pole na `ECHOABLE_FIELDS`. Cokoli jiného se hlásí
 * typem a délkou.
 *
 * Důvod téhle konstrukce: kdyby o redakci rozhodovalo místo volání, muselo by
 * si každé pravidlo pamatovat, co je osobní údaj — a jedno z osmdesáti šesti by
 * se spletlo. Takhle se plete jedině tehdy, když někdo přidá pole na allow-list,
 * což je vidět v diffu a shodí to `testEchoAllowListIsExactlyAsDocumented`.
 *
 * POINTER je RFC 6901 a je INDEXOVÝ, nikdy klíčovaný obsahem. `/documents/0/items/3`
 * ano; `/parties/Nějaká firma s.r.o./ic` ne — tudy by osobní údaj unikl cestou,
 * kterou redakce hodnot nehlídá.
 */
final class Finding
{
    public const FAIL = 'fail';
    public const WARN = 'warn';
    public const INFO = 'info';

    /**
     * Pole, jejichž hodnotu smí zpráva citovat. Uzavřený výčet.
     *
     * Kritérium: hodnota je kód z číselníku nebo strukturní ukazatel, ne údaj
     * o osobě, firmě nebo penězích. Číslo dokladu tady VĚDOMĚ NENÍ — samo sice
     * osobní údaj není, ale ve spojení s dodavatelem identifikuje obchodní vztah.
     */
    private const ECHOABLE_FIELDS = [
        'document_kind',
        'currency',
        'vat_rate',
        'vat_rate_id',
        'relation',
        'confidence',
        'schema',
        'limit_name',
        'field_count',
        'index',
    ];

    /**
     * @param string $ruleId    ID z katalogu, např. „V43c"
     * @param string $severity  self::FAIL | self::WARN | self::INFO
     * @param string $pointer   RFC 6901 pointer na vadný uzel; '' = úroveň dokumentu
     * @param string $message   text pro člověka — BEZ hodnot, ty přidává withValue()
     */
    private function __construct(
        public readonly string $ruleId,
        public readonly string $severity,
        public readonly string $pointer,
        public readonly string $message,
    ) {}

    public static function fail(string $ruleId, string $pointer, string $message): self
    {
        return new self($ruleId, self::FAIL, $pointer, $message);
    }

    public static function warn(string $ruleId, string $pointer, string $message): self
    {
        return new self($ruleId, self::WARN, $pointer, $message);
    }

    public static function info(string $ruleId, string $pointer, string $message): self
    {
        return new self($ruleId, self::INFO, $pointer, $message);
    }

    /**
     * Připojí ke zprávě popis hodnoty. Vypíše ji JEN u polí z allow-listu;
     * u všech ostatních uvede typ a délku.
     *
     * Jméno pole se bere z posledního segmentu pointeru, ne z parametru — aby
     * nešlo „omylem" prohlásit cizí pole za povolené.
     */
    public function withValue(mixed $value): self
    {
        $field = $this->lastPointerSegment();
        $desc  = in_array($field, self::ECHOABLE_FIELDS, true)
            ? $this->plainValue($value)
            : $this->redactedValue($value);

        return new self($this->ruleId, $this->severity, $this->pointer, $this->message . ' (' . $desc . ')');
    }

    /** @return array<string,string> serializovaný nález — tvar pro report i API */
    public function toArray(): array
    {
        return [
            'rule'     => $this->ruleId,
            'severity' => $this->severity,
            'pointer'  => $this->pointer,
            'message'  => $this->message,
        ];
    }

    /**
     * Deterministický řadicí klíč (V85). Bez něj by shodný vstup dával nálezy
     * v jiném pořadí a jejich seznam by nešlo porovnat mezi dvěma běhy.
     */
    public function sortKey(): string
    {
        $rank = match ($this->severity) {
            self::FAIL => '0',
            self::WARN => '1',
            default    => '2',
        };

        return $rank . '|' . $this->pointer . '|' . $this->ruleId;
    }

    // -----------------------------------------------------------------------

    private function lastPointerSegment(): string
    {
        if ($this->pointer === '') {
            return '';
        }
        $parts = explode('/', $this->pointer);
        $last  = (string) end($parts);

        return str_replace(['~1', '~0'], ['/', '~'], $last);
    }

    private function plainValue(mixed $v): string
    {
        return match (true) {
            is_bool($v)            => $v ? 'true' : 'false',
            $v === null            => 'null',
            is_scalar($v)          => (string) $v,
            default                => $this->redactedValue($v),
        };
    }

    private function redactedValue(mixed $v): string
    {
        return match (true) {
            $v === null   => 'null',
            is_bool($v)   => 'boolean',
            is_int($v)    => 'celé číslo',
            is_float($v)  => 'desetinné číslo',
            is_string($v) => sprintf('text, %d znaků', mb_strlen($v)),
            is_array($v)  => sprintf('%s, %d prvků', array_is_list($v) ? 'pole' : 'objekt', count($v)),
            default       => 'neznámý typ',
        };
    }

    /** Pro test, který tvrdí, že allow-list je přesně dokumentovaná množina. */
    public static function echoableFields(): array
    {
        return self::ECHOABLE_FIELDS;
    }
}
