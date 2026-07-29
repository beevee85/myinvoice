<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Api;

/**
 * Chyba komunikace s Fio API. Zpráva je určená uživateli (zobrazuje se v Nastavení
 * i v logu cronu), takže NIKDY nesmí obsahovat token — ten je součástí URL.
 */
final class FioApiException extends \RuntimeException
{
}
