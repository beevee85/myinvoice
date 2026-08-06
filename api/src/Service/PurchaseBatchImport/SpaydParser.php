<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

/**
 * FORK (beevee85) — Commit 12: parser SPAYD (český QR platba).
 *
 * Repo umí SPAYD jen VYRÁBĚT (`QrPaymentGenerator` přes `Rikudou\CzQrPayment`).
 * Číst ho neumělo nic — ověřeno grepem. Tohle je ta chybějící polovina.
 *
 * ČISTĚ PHP, BEZ ZÁVISLOSTI. Dekodér obrázku je volitelný (viz `QrCheck`),
 * ale jakmile řetězec máme odkudkoli, rozebrat ho umíme vždycky. Rozdělení je
 * záměrné: kdyby parser záležel na dekodéru, nešel by testovat bez něj.
 *
 * ESCAPOVÁNÍ je to, kde se u tohohle formátu chybuje. Hodnota smí obsahovat
 * `*` i `%`, zapsané jako `%2A` a `%25`. Kdo dekóduje před rozdělením podle
 * `*`, rozseká řetězec na špatných místech a dostane jiné hodnoty — proto se
 * NEJDŘÍV dělí a TEPRVE POTOM dekóduje.
 *
 * Parser NIC NEOPRAVUJE. Vadný řetězec je `null`, ne odhad.
 */
final class SpaydParser
{
    /**
     * @return array{account: string, amount: ?string, currency: ?string, vs: ?string, message: ?string}|null
     */
    public function parse(string $raw): ?array
    {
        $raw = trim($raw);

        // Hlavička: `SPD*<verze>*` — bez ní to není SPAYD.
        if (!preg_match('/^SPD\*(\d+\.\d+)\*/', $raw, $m)) {
            return null;
        }

        // POŘADÍ JE PODSTATNÉ: nejdřív rozdělit, teprve pak dekódovat.
        $parts = explode('*', $raw);
        array_shift($parts);   // "SPD"
        array_shift($parts);   // verze

        $fields = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $pos = strpos($part, ':');
            if ($pos === false || $pos === 0) {
                return null;   // pole bez klíče — řetězec je poškozený
            }
            $key = strtoupper(substr($part, 0, $pos));
            $val = $this->decodeValue(substr($part, $pos + 1));

            // Duplicitní klíč: první vyhrává. „Poslední vyhrává" by dovolilo
            // připojit druhý ACC a přepsat účet — táž třída jako duplicitní
            // klíče v JSON (V80).
            if (!array_key_exists($key, $fields)) {
                $fields[$key] = $val;
            }
        }

        $account = $fields['ACC'] ?? '';
        if ($account === '') {
            return null;   // platba bez účtu nedává smysl
        }

        return [
            'account'  => $this->normalizeAccount($account),
            'amount'   => $this->normalizeAmount($fields['AM'] ?? null),
            'currency' => isset($fields['CC']) ? strtoupper($fields['CC']) : null,
            'vs'       => $fields['X-VS'] ?? null,
            'message'  => $fields['MSG'] ?? null,
        ];
    }

    // -----------------------------------------------------------------------

    /** `%2A` → `*`, `%25` → `%`. Jiné `%XX` se nechává být — nejsme URL dekodér. */
    private function decodeValue(string $v): string
    {
        return str_replace(['%2A', '%2a', '%25'], ['*', '*', '%'], $v);
    }

    /**
     * ACC je `IBAN` nebo `IBAN+BIC`. BIC zahazujeme — pro porovnání účtu je
     * podstatný IBAN, a BIC se na dokladech uvádí nekonzistentně.
     */
    private function normalizeAccount(string $acc): string
    {
        $iban = explode('+', $acc, 2)[0];

        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    /**
     * Částka se vrací jako STRING v kanonickém tvaru pro `Money`, nebo `null`,
     * když neodpovídá. Žádné přetypování na float — u peněz platí V81a i tady.
     */
    private function normalizeAmount(?string $am): ?string
    {
        if ($am === null || $am === '') {
            return null;
        }
        if (!preg_match('/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/', $am)) {
            return null;
        }

        return $am;
    }
}
