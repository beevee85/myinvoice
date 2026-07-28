<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Guard pro doklady v koši (invoices / purchase_invoices, sloupec deleted_at).
 *
 * Doklad v koši je read-only: nelze ho editovat, vystavit, odeslat, párovat,
 * přidávat platby ani měnit stav — jen zobrazit, obnovit, trvale smazat.
 * Mutační akce volají blockIfTrashed() hned po načtení řádku.
 */
final class TrashGuard
{
    /**
     * @param array<string,mixed>|null $row řádek dokladu (repo find)
     * @return Response|null 409 in_trash pokud je doklad v koši, jinak null
     */
    public static function blockIfTrashed(?array $row, Response $response): ?Response
    {
        if ($row !== null && !empty($row['deleted_at'])) {
            return Json::error(
                $response,
                'in_trash',
                'Doklad je v koši — nelze ho upravovat. Nejdřív ho obnovte, nebo trvale smažte.',
                409,
            );
        }
        return null;
    }
}
