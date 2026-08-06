<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

use RuntimeException;

/**
 * FORK (beevee85) — odmítnutí nedůvěryhodného JSON, se strojově čitelným kódem
 * a RFC 6901 pointerem na vadný uzel (V82).
 *
 * ZPRÁVA NIKDY NEOBSAHUJE HODNOTU. Uvádí se typ, délka a limit — nikdy obsah,
 * protože `results.json` nese údaje z cizích dokladů a tahle zpráva skončí
 * v reportu i v logu.
 */
final class StrictJsonException extends RuntimeException
{
    public function __construct(
        private readonly string $reasonCode,
        string $message,
        private readonly string $pointer,
    ) {
        parent::__construct($message);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    /** RFC 6901 pointer na vadný uzel; prázdný řetězec = úroveň dokumentu. */
    public function pointer(): string
    {
        return $this->pointer;
    }
}
