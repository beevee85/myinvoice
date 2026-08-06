<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

use RuntimeException;

/**
 * FORK (beevee85) — odmítnutí dávky s STROJOVĚ ČITELNÝM kódem důvodu.
 *
 * Kód je stabilní identifikátor pro UI a testy; zpráva je pro člověka a smí se
 * měnit. Testy se vážou na `code`, nikdy na text — jinak by je rozbila oprava
 * překlepu.
 *
 * Zpráva NIKDY neobsahuje obsah dokladu ani cestu na disku (V82) — jen pořadové
 * číslo souboru, jeho velikost a limit, proti kterému se to měřilo.
 */
final class BatchLimitException extends RuntimeException
{
    public function __construct(
        private readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
