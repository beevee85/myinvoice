<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

/**
 * FORK (beevee85) — Commit 11d: orchestrátor doménové validace.
 *
 * Spojuje `StrictJson` (V80), `IdentityRules` (V15–V55), `AmountRules` (V43–V81b)
 * a `QrCheck` (V33–V35) a přidává to, co jde ověřit jen proti dávce: párování
 * na manifest.
 *
 * TOHLE JE MÍSTO, KDE PLATÍ „SERVERU SE NEVĚŘÍ ANI JEDNO ČÍSLO". `results.json`
 * neprošel naší validací a přišel zvenčí, takže se neověřuje jen jeho tvar, ale
 * i to, ŽE MLUVÍ O TÉ DÁVCE, KTERÁ SE POSÍLALA:
 *
 *   V4  každý `sha256` v odpovědi musí být v manifestu — jinak jde o doklad,
 *       který se nikdy nenahrál
 *   V5  žádný `sha256` dvakrát — jinak by jeden soubor vyrobil dva doklady
 *   V6  žádný soubor z manifestu nesmí chybět — jinak by se dávka tvářila jako
 *       hotová a část dokladů by se tiše zahodila
 *
 * Bez V6 by šlo poslat odpověď o jednom dokladu na dávku o padesáti a systém by
 * to přijal jako úspěch. To je ta nejtišší varianta selhání, jaká tu může nastat.
 *
 * VÝSLEDEK JE DETERMINISTICKÝ (V85): nálezy jsou seřazené podle `sortKey()`,
 * takže shodný vstup dá bajtově shodný seznam. Bez toho by se nedalo porovnat,
 * jestli se mezi dvěma běhy něco změnilo.
 */
final class ResultsValidator
{
    private const EXPECTED_SCHEMA = 'myinvoice.purchase-import-batch.results/1';

    /**
     * Vzory, které v textu z cizího dokladu nemají co dělat. Ne proto, že by
     * PHP ohrožovaly — do DB jde vše přes prepared statements — ale protože
     * text končí v UI, v PDF a v e-mailu, a tam nebezpečné jsou (V62).
     */
    private const SUSPICIOUS_PATTERNS = [
        '/<script\b/i'                => 'script',
        '/javascript:/i'              => 'javascript:',
        '/\bon(?:error|load|click)\s*=/i' => 'inline handler',
        '/^[=+\-@\t\r]/'              => 'formule (CSV injection)',
        '/\x00/'                      => 'NUL',
    ];

    public function __construct(
        private readonly StrictJson $json,
        private readonly IdentityRules $identity,
        private readonly AmountRules $amounts,
        private readonly QrCheck $qr,
    ) {}

    /**
     * @param string $raw                     nedůvěryhodný `results.json`
     * @param list<array<string,mixed>> $manifestFiles  soubory dávky z databáze
     * @param array{ic: string, dic: string} $tenant
     * @param string $today                   „YYYY-MM-DD", injektovaný (V84)
     * @param array<string,array{spayd: ?string, independence: string}> $qrBySha
     *        dekódované QR per soubor (klíč = sha256). QR data NEJSOU součástí
     *        `results.json` — to je rozhodnutí A2: kdyby SPAYD posílala extrakce,
     *        kontrola by si ověřovala sama sebe. Plní je server z vlastního
     *        dekodéru; bez dekodéru je mapa prázdná a každý doklad dostane
     *        INFO „kontrola neproběhla" — poctivější než ticho.
     * @return array{ok: bool, findings: list<array<string,string>>}
     */
    public function validate(string $raw, array $manifestFiles, array $tenant, string $today, array $qrBySha = []): array
    {
        // 1. Tvar. Když neprojde, dál se nepokračuje — nemá co validovat.
        try {
            $data = $this->json->decode($raw);
        } catch (StrictJsonException $e) {
            return $this->result([
                Finding::fail('V80', $e->pointer(), $e->getMessage()),
            ]);
        }

        $findings = [];

        if (($data['schema'] ?? null) !== self::EXPECTED_SCHEMA) {
            $findings[] = Finding::fail('V9', '/schema', 'Neznámá verze schématu odpovědi.')
                ->withValue($data['schema'] ?? null);

            // Bez známého schématu nemá smysl interpretovat obsah.
            return $this->result($findings);
        }

        $documents = $data['documents'] ?? null;
        if (!is_array($documents) || !array_is_list($documents)) {
            return $this->result([
                Finding::fail('V9', '/documents', 'Pole `documents` chybí nebo není seznam.'),
            ]);
        }

        // 2. Párování na manifest — dřív než cokoli obsahového.
        $expected = [];
        foreach ($manifestFiles as $f) {
            $expected[(string) $f['sha256']] = true;
        }

        $seen = [];
        foreach ($documents as $i => $doc) {
            $p = '/documents/' . $i;

            if (!is_array($doc)) {
                $findings[] = Finding::fail('V9', $p, 'Doklad není objekt.');
                continue;
            }

            $sha = (string) ($doc['sha256'] ?? '');

            if ($sha === '' || !preg_match('/^[0-9a-f]{64}$/', $sha)) {
                $findings[] = Finding::fail('V4', $p . '/sha256',
                    'Doklad neuvádí platný sha256 souboru z dávky.');
                continue;
            }
            // V4 — soubor, který se nikdy nenahrál
            if (!isset($expected[$sha])) {
                $findings[] = Finding::fail('V4', $p . '/sha256',
                    'Odpověď mluví o souboru, který v dávce není.');
                continue;
            }
            // V5 — týž soubor dvakrát
            if (isset($seen[$sha])) {
                $findings[] = Finding::fail('V5', $p . '/sha256',
                    'Týž soubor je v odpovědi uvedený vícekrát.');
                continue;
            }
            $seen[$sha] = true;

            // 3. Obsah dokladu.
            $qrInput = $qrBySha[$sha]
                ?? ['spayd' => null, 'independence' => QrCheck::INDEPENDENCE_UNAVAILABLE];

            $findings = array_merge(
                $findings,
                $this->identity->validate($doc, $tenant, $today, $p),
                $this->amounts->validate($doc, $p),
                $this->qr->verify($qrInput['spayd'], $qrInput['independence'], $doc, $p),
                $this->suspiciousContent($doc, $p),
            );
        }

        // V6 — chybějící soubor. Až po průchodu, ať víme, co dorazilo.
        foreach (array_keys($expected) as $sha) {
            if (!isset($seen[$sha])) {
                $findings[] = Finding::fail('V6', '/documents',
                    'Pro některý soubor z dávky odpověď nepřišla — dávka není hotová.');
                break;   // stačí jeden nález, počet by prozradil velikost dávky
            }
        }

        return $this->result($findings);
    }

    // -----------------------------------------------------------------------

    /**
     * V62 — podezřelý obsah v textových polích. Prochází se REKURZIVNĚ, protože
     * text může přijít i v poli, které dnes neznáme; kontrola jen známých polí
     * by novou verzi schématu nepokryla.
     *
     * @param array<string,mixed> $node
     * @return list<Finding>
     */
    private function suspiciousContent(array $node, string $pointer, int $depth = 0): array
    {
        if ($depth > 10) {
            return [];
        }

        $findings = [];
        foreach ($node as $key => $value) {
            $p = $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key);

            if (is_array($value)) {
                $findings = array_merge($findings, $this->suspiciousContent($value, $p, $depth + 1));
                continue;
            }
            if (!is_string($value) || $value === '') {
                continue;
            }
            foreach (self::SUSPICIOUS_PATTERNS as $pattern => $label) {
                if (preg_match($pattern, $value)) {
                    $findings[] = Finding::fail('V62', $p,
                        sprintf('Text obsahuje podezřelou konstrukci (%s).', $label));
                    break;   // jeden nález na pole stačí
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<Finding> $findings
     * @return array{ok: bool, findings: list<array<string,string>>}
     */
    private function result(array $findings): array
    {
        usort($findings, static fn (Finding $a, Finding $b) => strcmp($a->sortKey(), $b->sortKey()));

        $hasFail = false;
        foreach ($findings as $f) {
            if ($f->severity === Finding::FAIL) {
                $hasFail = true;
                break;
            }
        }

        return [
            'ok'       => !$hasFail,
            'findings' => array_map(static fn (Finding $f) => $f->toArray(), $findings),
        ];
    }
}
