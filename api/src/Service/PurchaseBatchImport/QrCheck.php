<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

/**
 * FORK (beevee85) — Commit 12: kontrola QR platby (V33–V35, rozhodnutí A2).
 *
 * QR JE KONTROLA, NE ZDROJ. Účet, částka ani variabilní symbol se z QR NIKDY
 * nepřepisují — jen se porovnávají s tím, co vyčetla extrakce. Důvod je ten,
 * kvůli kterému QR kontrolujeme: kdyby QR mohl hodnotu přepsat, útočník
 * s podvrženým QR by rovnou určil, kam se pošlou peníze. Přepis by z obrany
 * udělal vstupní bod.
 *
 * TŘI STAVY NEZÁVISLOSTI. Váha nálezu závisí na tom, ODKUD rastr přišel:
 *
 *   full        rastr jsme si vyrobili sami z původního PDF → QR je NEZÁVISLÝ
 *               zdroj a neshoda je FAIL
 *   partial     rastr dodala extrakce → není nezávislý (mohl vzniknout z téhož
 *               chybného čtení) → jen WARN
 *   unavailable dekodér není → INFO, kontrola se neprovedla
 *
 * Ten rozdíl je celý smysl A2. Kdyby se `partial` hlásil jako FAIL, důvěřovali
 * bychom kontrole, která si ověřuje sama sebe.
 *
 * DEKODÉR JE VOLITELNÁ ZÁVISLOST. Když v systému není, tahle třída ho ani
 * neimportuje a stav je `unavailable` — featura funguje dál, jen bez téhle
 * kontroly. Rozhodnutí, jestli se závislost přidá, je věcí provozovatele.
 */
final class QrCheck
{
    public const INDEPENDENCE_FULL        = 'full';
    public const INDEPENDENCE_PARTIAL     = 'partial';
    public const INDEPENDENCE_UNAVAILABLE = 'unavailable';

    public function __construct(private readonly SpaydParser $parser) {}

    /**
     * @param string|null $spayd       dekódovaný obsah QR, nebo null když dekodér chybí
     * @param array<string,mixed> $doc doklad z extrakce
     * @return list<Finding>
     */
    public function verify(?string $spayd, string $independence, array $doc, string $pointer): array
    {
        if ($independence === self::INDEPENDENCE_UNAVAILABLE || $spayd === null) {
            return [Finding::info('V33', $pointer,
                'QR platba nebyla ověřena — dekodér není k dispozici.')];
        }

        $qr = $this->parser->parse($spayd);
        if ($qr === null) {
            // Nečitelný QR není důkaz o chybě dokladu; je to chybějící kontrola.
            return [Finding::info('V33', $pointer, 'QR kód se nepodařilo přečíst.')];
        }

        $severe = $independence === self::INDEPENDENCE_FULL;
        $mk = static fn (string $rule, string $p, string $msg): Finding
            => $severe ? Finding::fail($rule, $p, $msg) : Finding::warn($rule, $p, $msg);

        $findings = [];

        // --- V33: účet -------------------------------------------------
        // Porovnává se, NEPŘEPISUJE. Účet z QR se do dokladu nedostane
        // za žádných okolností.
        $docAccount = $this->normalizeAccount((string) ($doc['payment']['iban'] ?? ''));
        if ($docAccount !== '' && $qr['account'] !== '' && $docAccount !== $qr['account']) {
            $findings[] = $mk('V33', $pointer . '/payment/iban',
                'Účet v QR kódu se liší od účtu vyčteného z dokladu.');
        }

        // --- V34: částka -----------------------------------------------
        $docTotal = (string) ($doc['totals']['total'] ?? '');
        if ($qr['amount'] !== null && $docTotal !== '') {
            try {
                $a = Money::parse($qr['amount']);
                $b = Money::parse($docTotal);
                if (!$a->equals($b)) {
                    $findings[] = $mk('V34', $pointer . '/totals/total',
                        'Částka v QR kódu se liší od celkové částky dokladu.');
                }
            } catch (MoneyFormatException) {
                $findings[] = Finding::info('V34', $pointer . '/totals/total',
                    'Částku z QR nebo z dokladu nelze porovnat.');
            }
        }

        // --- V35: variabilní symbol ------------------------------------
        $docVs = trim((string) ($doc['numbers']['varsymbol'] ?? ''));
        if ($qr['vs'] !== null && $docVs !== '' && ltrim($qr['vs'], '0') !== ltrim($docVs, '0')) {
            $findings[] = $mk('V35', $pointer . '/numbers/varsymbol',
                'Variabilní symbol v QR kódu se liší od symbolu na dokladu.');
        }

        if ($findings === []) {
            $findings[] = Finding::info('V33', $pointer, 'QR platba souhlasí s dokladem.');
        }

        return $findings;
    }

    private function normalizeAccount(string $acc): string
    {
        return strtoupper(preg_replace('/\s+/', '', $acc) ?? '');
    }
}
