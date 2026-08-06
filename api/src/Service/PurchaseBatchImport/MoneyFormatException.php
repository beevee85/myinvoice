<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

use RuntimeException;

/**
 * FORK (beevee85) — nepřijatelný tvar peněžní hodnoty (V81a).
 *
 * Zpráva NEOBSAHUJE odmítnutou hodnotu, jen její délku. Částka z cizího dokladu
 * je údaj o obchodním vztahu a tahle výjimka může skončit v logu.
 */
final class MoneyFormatException extends RuntimeException
{
    public function __construct(string $rejected)
    {
        parent::__construct(sprintf(
            'Peněžní hodnota není v kanonickém tvaru (text, %d znaků). Očekává se '
            . '„-?číslice[.nejvýš dvě desetinná místa]", bez oddělovačů tisíců a bez vědecké notace.',
            mb_strlen($rejected),
        ));
    }

    public function reasonCode(): string
    {
        return 'money_format';
    }
}
