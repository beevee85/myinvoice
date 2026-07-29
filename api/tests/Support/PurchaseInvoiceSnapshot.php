<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;

/**
 * FORK (beevee85) — pomůcka pro CHARAKTERIZAČNÍ (golden-master) testy přijatých faktur.
 *
 * Zafixuje, co konkrétní zapisovací cesta reálně uložila do databáze. Slouží jako důkaz,
 * že pozdější refaktoring (vytažení sdílené write service, obalení transakcí, převedení
 * importních cest) NEZMĚNIL chování — ne jako test „správnosti". Když se snapshot změní,
 * je to buď regrese, nebo vědomá změna, kterou je potřeba popsat v commit message.
 *
 * Volatilní hodnoty se nahrazují zástupkami, ať test neselže kvůli autoinkrementu,
 * času běhu nebo id fixture řádků:
 *   <ID> <VENDOR_ID> <SUPPLIER_ID> <USER_ID> <CURRENCY_ID> <VAT_RATE_ID:sazba>
 *   <TODAY> <TIMESTAMP> <SHA256> <JSON>
 *
 * Sloupce, které dnes žádná zapisovací cesta neplní, se ze snapshotu vypouštějí
 * (`nonNull()`), aby byl čitelný; zajímá nás rozdíl, ne výpis 80 NULL polí.
 */
final class PurchaseInvoiceSnapshot
{
    /** Sloupce, které se nikdy neporovnávají doslova. */
    private const VOLATILE = [
        'id'          => '<ID>',
        'supplier_id' => '<SUPPLIER_ID>',
        'vendor_id'   => '<VENDOR_ID>',
        'currency_id' => '<CURRENCY_ID>',
        'created_by'  => '<USER_ID>',
    ];

    /** Sloupce s časovým razítkem — normalizují se na <TIMESTAMP>, pokud nejsou NULL. */
    private const TIMESTAMPS = [
        'created_at', 'updated_at', 'booked_at', 'paid_at', 'cancelled_at',
        'pdf_uploaded_at', 'source_uploaded_at', 'payment_account_checked_at',
        'payment_ordered_at', 'deleted_at',
    ];

    /**
     * Kompletní obraz jednoho dokladu: hlavička + položky + rozpis DPH.
     *
     * @param array<int,float> $vatRateLabels mapa vat_rate_id → sazba, aby se v snapshotu
     *                                        objevila SAZBA (stabilní) místo id (volatilní)
     * @return array{header: array<string,mixed>, items: list<array<string,mixed>>}
     */
    public static function capture(PDO $pdo, int $invoiceId, array $vatRateLabels = []): array
    {
        $stmt = $pdo->prepare('SELECT * FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($header === false) {
            return ['header' => [], 'items' => []];
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$invoiceId]);
        $rawItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rawItems as $row) {
            unset($row['id'], $row['purchase_invoice_id']);
            if (isset($row['vat_rate_id'])) {
                $row['vat_rate_id'] = self::vatRatePlaceholder((int) $row['vat_rate_id'], $vatRateLabels);
            }
            if (isset($row['settlement_source_purchase_invoice_id']) && $row['settlement_source_purchase_invoice_id'] !== null) {
                $row['settlement_source_purchase_invoice_id'] = '<ID>';
            }
            $items[] = self::nonNull($row);
        }

        return [
            'header' => self::normalizeHeader($header),
            'items'  => $items,
        ];
    }

    /**
     * Záznamy auditu k dokladu — bez id, času a IP, jen akce a payload.
     *
     * @return list<array<string,mixed>>
     */
    public static function captureActivity(PDO $pdo, int $invoiceId): array
    {
        $stmt = $pdo->prepare(
            "SELECT action, entity_type, payload
               FROM activity_log
              WHERE entity_type = 'purchase_invoice' AND entity_id = ?
              ORDER BY id"
        );
        $stmt->execute([$invoiceId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            if (is_array($payload)) {
                // vendor_id je volatilní; zbytek payloadu je součást charakterizace.
                if (array_key_exists('vendor_id', $payload)) {
                    $payload['vendor_id'] = '<VENDOR_ID>';
                }
                ksort($payload);
            }
            $row['payload'] = $payload;
            $out[] = $row;
        }
        return $out;
    }

    /** @param array<string,mixed> $header @return array<string,mixed> */
    private static function normalizeHeader(array $header): array
    {
        foreach (self::VOLATILE as $column => $placeholder) {
            if (array_key_exists($column, $header)) {
                $header[$column] = $placeholder;
            }
        }
        foreach (self::TIMESTAMPS as $column) {
            if (!empty($header[$column])) {
                $header[$column] = '<TIMESTAMP>';
            }
        }

        // Vazby na jiné doklady — existence je podstatná, konkrétní id ne.
        foreach ([
            'advance_purchase_invoice_id',
            'advance_link_suggested_id',
            'settled_by_purchase_invoice_id',
            'payment_currency_id',
            'deleted_by',
        ] as $column) {
            if (!empty($header[$column])) {
                $header[$column] = '<ID>';
            }
        }

        // Datum přijetí je u importních cest date('Y-m-d') — stabilní jen v rámci dne.
        if (!empty($header['received_at']) && $header['received_at'] === date('Y-m-d')) {
            $header['received_at'] = '<TODAY>';
        }
        if (!empty($header['pdf_hash'])) {
            $header['pdf_hash'] = '<SHA256>';
        }
        if (!empty($header['source_hash'])) {
            $header['source_hash'] = '<SHA256>';
        }
        // Cesty k archivovaným souborům obsahují id a datum — zajímá nás jen, že vznikly.
        foreach (['pdf_path', 'source_path'] as $column) {
            if (!empty($header[$column])) {
                $header[$column] = '<PATH>';
            }
        }

        // Snapshoty protistran jsou JSON; porovnáváme vybrané klíče, ne pořadí a adresu.
        $header['vendor_snapshot'] = self::snapshotDigest($header['vendor_snapshot'] ?? null, ['company_name', 'ic', 'dic']);
        $header['own_snapshot']    = self::snapshotDigest($header['own_snapshot'] ?? null, ['company_name', 'ic', 'dic']);

        // Ruční rekapitulace DPH (§ 73) je jádro charakterizace — dekódovat a seřadit.
        if (!empty($header['vat_overrides'])) {
            $decoded = json_decode((string) $header['vat_overrides'], true);
            if (is_array($decoded)) {
                usort($decoded, static fn ($a, $b) => (float) ($a['rate'] ?? 0) <=> (float) ($b['rate'] ?? 0));
                $header['vat_overrides'] = $decoded;
            }
        }
        if (!empty($header['settlement_recap_backup'])) {
            $header['settlement_recap_backup'] = '<JSON>';
        }

        return self::nonNull($header);
    }

    /**
     * Z JSON snapshotu protistrany vytáhne jen podstatné klíče. Vrací null, když snapshot
     * chybí — ať je ve výsledku vidět rozdíl „nezapsáno" vs. „zapsáno prázdné".
     *
     * @param list<string> $keys
     * @return array<string,mixed>|null
     */
    private static function snapshotDigest(mixed $json, array $keys): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $decoded[$key] ?? null;
        }
        return $out;
    }

    /** @param array<int,float> $vatRateLabels */
    private static function vatRatePlaceholder(int $vatRateId, array $vatRateLabels): string
    {
        $rate = $vatRateLabels[$vatRateId] ?? null;
        return $rate === null ? '<VAT_RATE_ID>' : sprintf('<VAT_RATE_ID:%s>', rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'));
    }

    /**
     * Vyhodí NULL sloupce — snapshot má být čitelný diff, ne výpis prázdných polí.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function nonNull(array $row): array
    {
        $out = array_filter($row, static fn ($v) => $v !== null);
        ksort($out);
        return $out;
    }

    /** Mapa vat_rate_id → sazba, pro čitelné zástupky v položkách. @return array<int,float> */
    public static function vatRateLabels(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['id']] = (float) $row['rate_percent'];
        }
        return $out;
    }
}
