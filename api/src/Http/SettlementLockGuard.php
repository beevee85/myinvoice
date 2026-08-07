<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * FORK — zámek odpočtových řádků § 37a proti smazání editací.
 *
 * Auto-odpočtové řádky (settlement_source_purchase_invoice_id) nelze smazat,
 * dokud párování DDKPZ trvá: smazáním by na dokladu navždy zůstaly SNÍŽENÉ
 * `vat_overrides` bez řádků, které je zdůvodňují — rekapitulace by se rozešla
 * s realitou a odpočet za období by se posunul o celou zálohu. Správná cesta
 * je „Zrušit propojení" (unlink řádky odebere I vrátí rekapitulaci).
 *
 * Audit 2026-08-07: logika žila jen v UpdatePurchaseInvoiceAction — SetItems
 * (PUT /items) ji NEMĚL a tou dírou šlo odpočtové řádky smazat. Vytaženo sem,
 * ať platí pro každou cestu, která přepisuje položky spárovaného dokladu.
 */
final class SettlementLockGuard
{
    /**
     * Vrátí chybovou odpověď (409), pokud nová sada položek maže odpočtový
     * řádek stále aktivního DDKPZ; jinak null.
     *
     * @param array<string,mixed> $existing  doklad z repo->find (se `settlement_documents` a `items`)
     * @param array<mixed>        $newItems  položky z těla požadavku
     */
    public static function blockIfDroppingLinkedRows(array $existing, array $newItems, Response $response): ?Response
    {
        $linkedSources = [];
        foreach ((array) ($existing['settlement_documents'] ?? []) as $sd) {
            if (($sd['status'] ?? '') !== 'cancelled') {
                $linkedSources[(int) $sd['id']] = true;
            }
        }
        if ($linkedSources === []) {
            return null;
        }

        $keptSources = [];
        foreach ($newItems as $it) {
            $src = (int) (is_array($it) ? ($it['settlement_source_purchase_invoice_id'] ?? 0) : 0);
            if ($src > 0) {
                $keptSources[$src] = true;
            }
        }

        foreach ((array) ($existing['items'] ?? []) as $it) {
            $src = (int) ($it['settlement_source_purchase_invoice_id'] ?? 0);
            if ($src > 0 && isset($linkedSources[$src]) && !isset($keptSources[$src])) {
                return Json::error(
                    $response,
                    'settlement_rows_locked',
                    'Odpočtové řádky daňového dokladu k záloze nelze smazat editací — nejdřív zrušte párování v detailu dokladu („Zrušit propojení").',
                    409,
                );
            }
        }

        return null;
    }
}
