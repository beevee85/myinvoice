<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Config\Config;

/**
 * Klient veřejného REST API Fio banky (https://www.fio.cz/docs/cz/API_Bankovnictvi.pdf).
 *
 * Záměrně používá JEN endpoint `/periods/` (pohyby za období). Fio nabízí i `/last/`
 * se serverovou zarážkou, ta se ale posouvá už ve chvíli, kdy banka odpoví — ne až
 * po našem uložení. Pád mezi odpovědí a commitem by znamenal pohyby, které `/last/`
 * už nikdy nevrátí, a to je u účetních dat nepřijatelné. `/periods/` je idempotentní
 * a opakovatelné, deduplikace běží u nás podle ID pohybu.
 *
 * Token je součástí URL (tak to Fio má), takže se NIKDY nesmí dostat do logu ani do
 * chybové hlášky — všechny výjimky nesou jen zamaskovanou podobu.
 */
final class FioApiClient
{
    public const BASE_URL = 'https://fioapi.fio.cz/v1/rest';

    /** Fio odmítá dotazy častěji než 1× za 30 s na token (HTTP 409). */
    public const RATE_LIMIT_SECONDS = 30;

    /** Historie přes API sahá 90 dní zpět; delší rozsah vrací 422. */
    public const MAX_HISTORY_DAYS = 89;

    public function __construct(private readonly Config $config) {}

    /**
     * Stáhne pohyby za období a vrátí je normalizované.
     *
     * @return list<array<string,mixed>> pohyby seřazené tak, jak je vrátila banka
     *
     * @throws FioApiException při chybě komunikace nebo odmítnutí bankou
     */
    public function fetchPeriod(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $url = sprintf(
            '%s/periods/%s/%s/%s/transactions.json',
            self::BASE_URL,
            rawurlencode($token),
            $from->format('Y-m-d'),
            $to->format('Y-m-d')
        );

        $timeout = (int) $this->config->get('bank.fio.timeout', 20);

        try {
            $client = new Client(['timeout' => $timeout, 'connect_timeout' => $timeout]);
            $res    = $client->request('GET', $url, [
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            // Zpráva Guzzle obsahuje celé URL včetně tokenu — nepropouštět dál.
            throw new FioApiException('Spojení s Fio API selhalo (' . $e->getCode() . ').', 0, $e);
        }

        $status = $res->getStatusCode();
        if ($status !== 200) {
            throw new FioApiException(self::describeStatus($status), $status);
        }

        $body = (string) $res->getBody();
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new FioApiException('Fio API vrátilo odpověď, které nerozumíme (neplatný JSON).');
        }

        return self::normalizeMovements($json);
    }

    /**
     * Fio popisuje chybové stavy v sekci 8 dokumentace. HTTP 500 NEznamená výpadek
     * banky, ale neexistující nebo neaktivní token — token platí max. 180 dní.
     */
    private static function describeStatus(int $status): string
    {
        return match ($status) {
            409 => 'Fio API odmítlo dotaz — mezi dvěma staženími musí být alespoň 30 sekund.',
            413 => 'Fio API odmítlo dotaz — požadované období obsahuje příliš mnoho pohybů.',
            422 => 'Fio API odmítlo rozsah dat — historie přes API sahá jen 90 dní zpět.',
            404 => 'Fio API nezná tento dotaz (zkontroluj tvar tokenu).',
            500 => 'Fio API odmítlo token — je neplatný, nebo mu skončila platnost (max. 180 dní).',
            default => 'Fio API vrátilo neočekávaný stav HTTP ' . $status . '.',
        };
    }

    /**
     * Rozbalí strukturu `accountStatement.transactionList.transaction[]`, kde každý
     * pohyb je mapa `columnNN => {value, name, id}`. Význam sloupců dle dokumentace:
     * 22 = ID pohybu (unikátní, dedup), 0 = datum, 1 = objem, 14 = měna, 2 = protiúčet,
     * 3 = kód banky, 5 = VS, 4 = KS, 6 = SS, 10 = název protistrany, 16 = zpráva pro
     * příjemce, 25 = komentář, 8 = typ pohybu, 17 = ID pokynu.
     *
     * Veřejné kvůli testovatelnosti — je to čistá funkce nad odpovědí banky
     * (formát dat je historicky nejzrádnější část integrace).
     *
     * @param array<string,mixed> $json
     *
     * @return list<array<string,mixed>>
     */
    public static function normalizeMovements(array $json): array
    {
        $list = $json['accountStatement']['transactionList']['transaction'] ?? null;
        // Prázdné období vrací transactionList = null, ne prázdné pole.
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = self::column($row, 22);
            if ($id === null || $id === '') {
                continue;
            }
            $out[] = [
                'external_id'          => (string) $id,
                'posted_at'            => self::parseDate(self::column($row, 0)),
                'amount'               => (float) self::column($row, 1),
                'currency'             => self::stringOrNull(self::column($row, 14)),
                'counterparty_account' => self::stringOrNull(self::column($row, 2)),
                'counterparty_bank'    => self::stringOrNull(self::column($row, 3)),
                'counterparty_name'    => self::stringOrNull(self::column($row, 10)),
                'variable_symbol'      => self::stringOrNull(self::column($row, 5)),
                'constant_symbol'      => self::stringOrNull(self::column($row, 4)),
                'specific_symbol'      => self::stringOrNull(self::column($row, 6)),
                'description'          => self::stringOrNull(self::column($row, 16))
                    ?? self::stringOrNull(self::column($row, 25))
                    ?? self::stringOrNull(self::column($row, 8)),
            ];
        }

        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function column(array $row, int $index): mixed
    {
        $col = $row['column' . $index] ?? null;

        return is_array($col) ? ($col['value'] ?? null) : null;
    }

    /**
     * Datum je dnes řetězec `2026-03-01+0100`. Příklady v oficiálním PDF pocházejí
     * z roku 2012 a ukazují epoch v milisekundách — parsuj obojí, ať integrace
     * nespadne na formátu, který zrovna banka vrátí.
     */
    private static function parseDate(mixed $raw): ?string
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw) && strlen($raw) > 10)) {
            // Epoch je okamžik v UTC, ale datum zaúčtování je místní — bez převodu
            // do aplikační zóny by půlnoční pohyb spadl o den vedle (a na přelomu
            // měsíce do jiného zdaňovacího období).
            return (new \DateTimeImmutable('@' . intdiv((int) $raw, 1000)))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d');
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $date = substr($raw, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    private static function stringOrNull(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }
}
