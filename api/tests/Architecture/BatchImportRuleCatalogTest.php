<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — POJISTKA NAD KATALOGEM PRAVIDEL.
 *
 * `docs/batch-import/RULE-STATUS.tsv` dosud popisoval kontrolu, kterou nikdo
 * nespustil: měl gramatiku, uzavřenou množinu stavů, vzor ID i očekávaný výstup
 * prvního běhu — ale nebyl kód, který by to ověřil. Popsaná kontrola není
 * kontrola; drift V78–V85 vznikl přesně tím, že dokumentace citovala ID, které
 * nikde neexistovalo, a nikdo to neměl čím zachytit.
 *
 * ČERVENÁ ZNAMENÁ JEDINOU VĚC: skutečnost neodpovídá deklarovanému stavu.
 *
 * PARSER NA NEROZPOZNANÉM ŘÁDKU PADÁ, nikdy ho nepřeskočí. Přeskakující parser
 * je umlčení kontroly o patro níž — táž třída chyby jako allow-all na docs/.
 */
final class BatchImportRuleCatalogTest extends TestCase
{
    /**
     * Kanonická forma ID, ne volný regex (RULE-STATUS.tsv, hlavička).
     * Číslo ≥ 1, písmeno z UZAVŘENÉ množiny a–e, hranice slova na obou stranách.
     * Volný vzor `\bV\d+[a-z]?\b` propouštěl `V0z` z base64 v cizí fixtuře.
     */
    private const ID_PATTERN = '/\bV(?:[1-9][0-9]*)(?:[a-e])?\b/';

    private const MARKER_PATTERN = '/\{\{noref:(V[0-9]+[a-e]?)\}\}/';

    /**
     * Záměrné zmínky neexistujícího ID. Zapsané JAKO MARKERY, ne jako holá ID —
     * kdyby tu stálo holé ID, tenhle soubor by si sám vyrobil nález ve směru 2.
     * Marker sám potřebuje strop, jinak je to nový a tichý způsob, jak umlčet
     * skutečný drift; proto je seznam JMENNÝ, ne jen počet.
     *
     * @var list<string>
     */
    private const EXPECTED_MARKERS = ['{{noref:V81}}'];

    /** Skenované cesty. `api/vendor/` je vyloučeno — cizí kód o katalogu nic netvrdí. */
    private const SCAN_DIRS = ['api/src', 'api/bin', 'api/tests', 'docs'];

    private const STATE_KEYS = [
        'vynuceno-a-otestovano' => ['test'],
        'definovano-nevynuceno' => ['vynuceni', 'blokuje', 'od'],
        'nepouzitelne'          => ['duvod'],
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Kanonický seznam z PLAN.md §8.7. Pojistka čte odsud a odnikud jinud —
     * druhý zdroj pravdy by znamenal, že se dva seznamy můžou rozejít.
     *
     * @return list<string>
     */
    private static function canonical(): array
    {
        $plan = (string) file_get_contents(self::root() . '/docs/batch-import/PLAN.md');

        $anchor = strpos($plan, '### 8.7');
        self::assertNotFalse($anchor, 'PLAN.md §8.7 (kanonický seznam) neexistuje.');

        $open = strpos($plan, "```", $anchor);
        self::assertNotFalse($open, '§8.7 nemá blok se seznamem.');
        $start = strpos($plan, "\n", $open) + 1;
        $end   = strpos($plan, "```", $start);
        self::assertNotFalse($end, '§8.7 má neuzavřený blok.');

        preg_match_all(self::ID_PATTERN, substr($plan, $start, $end - $start), $m);

        $ids = array_values(array_unique($m[0]));
        self::assertNotSame([], $ids, '§8.7 nevydal ani jedno ID — vzor nebo blok se rozešly.');

        return $ids;
    }

    /**
     * @return array<string,array{status:string, keys:array<string,list<string>>, line:int}>
     */
    private static function records(): array
    {
        $path  = self::root() . '/docs/batch-import/RULE-STATUS.tsv';
        $lines = explode("\n", (string) file_get_contents($path));

        $out = [];
        foreach ($lines as $i => $line) {
            $no = $i + 1;
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $cols = explode("\t", $line);
            self::assertGreaterThanOrEqual(2, count($cols),
                "RULE-STATUS.tsv:{$no} — záznam musí mít aspoň ID a stav.");

            [$id, $status] = [$cols[0], $cols[1]];

            self::assertMatchesRegularExpression('/^V(?:[1-9][0-9]*)(?:[a-e])?$/', $id,
                "RULE-STATUS.tsv:{$no} — ID '{$id}' není v kanonické formě.");
            self::assertArrayHasKey($status, self::STATE_KEYS,
                "RULE-STATUS.tsv:{$no} — stav '{$status}' není v uzavřené množině.");
            self::assertArrayNotHasKey($id, $out,
                "RULE-STATUS.tsv:{$no} — {$id} má dva záznamy; jeden by tiše přebil druhý.");

            $keys = [];
            foreach (array_slice($cols, 2) as $cell) {
                if ($cell === '') {
                    continue;
                }
                // ŽÁDNÝ VOLNÝ TEXT — každá hodnota za stavem je klíč:hodnota.
                self::assertStringContainsString(':', $cell,
                    "RULE-STATUS.tsv:{$no} — '{$cell}' není klíč:hodnota.");
                [$k, $v] = explode(':', $cell, 2);
                $keys[$k][] = $v;
            }

            foreach (self::STATE_KEYS[$status] as $required) {
                self::assertArrayHasKey($required, $keys,
                    "RULE-STATUS.tsv:{$no} — stav '{$status}' vyžaduje klíč '{$required}:'.");
            }

            $out[$id] = ['status' => $status, 'keys' => $keys, 'line' => $no];
        }

        return $out;
    }

    /**
     * Odkazy na ID napříč skenovaným rozsahem. Zmínky s markerem se nezapočítají,
     * ale vrací se zvlášť — přehlédnutelné být nesmějí.
     *
     * @return array{refs:array<string,list<string>>, markers:array<string,list<string>>}
     */
    private static function scan(): array
    {
        $refs = $markers = [];

        foreach (self::SCAN_DIRS as $dir) {
            $abs = self::root() . '/' . $dir;
            if (!is_dir($abs)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $rel  = substr($file->getPathname(), strlen(self::root()) + 1);
                $text = (string) file_get_contents($file->getPathname());

                if (!str_contains($text, 'V')) {
                    continue;
                }

                foreach (explode("\n", $text) as $i => $line) {
                    $where = $rel . ':' . ($i + 1);

                    // Markery se z řádku VYJMOU dřív, než se hledají holá ID.
                    // Jinak by `{{noref:V81}}` spadlo do obou množin naráz.
                    if (preg_match_all(self::MARKER_PATTERN, $line, $mm)) {
                        foreach ($mm[1] as $id) {
                            $markers[$id][] = $where;
                        }
                        $line = preg_replace(self::MARKER_PATTERN, '', $line) ?? $line;
                    }

                    if (preg_match_all(self::ID_PATTERN, $line, $m)) {
                        foreach ($m[0] as $id) {
                            $refs[$id][] = $where;
                        }
                    }
                }
            }
        }

        return ['refs' => $refs, 'markers' => $markers];
    }

    /** [1] Kanonické ID musí mít záznam ve stavu. */
    public function testEveryCanonicalIdHasAStatusRecord(): void
    {
        $missing = array_values(array_diff(self::canonical(), array_keys(self::records())));

        self::assertSame([], $missing,
            'ID v kanonickém seznamu bez záznamu ve stavu: ' . implode(', ', $missing));
    }

    /** [3] Záznam ve stavu mimo kanonický seznam. */
    public function testNoStatusRecordOutsideTheCanonicalList(): void
    {
        $extra = array_values(array_diff(array_keys(self::records()), self::canonical()));

        self::assertSame([], $extra,
            'záznam ve stavu pro ID mimo kanonický seznam: ' . implode(', ', $extra));
    }

    /** [2] Cokoli odkazovaného v kódu nebo dokumentaci musí být v kanonickém seznamu. */
    public function testEveryReferencedIdIsCanonical(): void
    {
        $canonical = self::canonical();
        $scan      = self::scan();

        $unknown = [];
        foreach ($scan['refs'] as $id => $places) {
            if (!in_array($id, $canonical, true)) {
                $unknown[] = $id . ' (' . $places[0] . ($places[1] ?? null ? ', +' . (count($places) - 1) : '') . ')';
            }
        }

        self::assertSame([], $unknown,
            "ID odkazované mimo kanonický seznam:\n  " . implode("\n  ", $unknown));
    }

    /**
     * Markery mají STROP a JMENNÝ SEZNAM, ne jen počet. Marker je legitimní
     * způsob, jak v próze zmínit neexistující ID — a zároveň nejtišší způsob,
     * jak umlčet skutečný drift, kdyby se rozmnožil bez povšimnutí.
     */
    public function testIntentionalMentionsStayWithinTheApprovedList(): void
    {
        $expected = [];
        foreach (self::EXPECTED_MARKERS as $marker) {
            preg_match(self::MARKER_PATTERN, $marker, $m);
            $expected[] = $m[1];
        }

        $found = array_keys(self::scan()['markers']);
        sort($found);
        sort($expected);

        self::assertSame($expected, $found,
            'množina záměrných zmínek se změnila — to je schvalovaná změna, ne vedlejší efekt úpravy prózy');
    }

    /** [4] `vynuceno-a-otestovano` → jmenovaný test musí v repu existovat. */
    public function testEveryEnforcedRuleNamesATestThatExists(): void
    {
        $missing = [];

        foreach (self::records() as $id => $rec) {
            if ($rec['status'] !== 'vynuceno-a-otestovano') {
                continue;
            }
            foreach ($rec['keys']['test'] as $t) {
                if (!is_file(self::root() . '/api/tests/' . $t . '.php')) {
                    $missing[] = "{$id} (RULE-STATUS.tsv:{$rec['line']}) → {$t}";
                }
            }
        }

        // „Nebo neprojde" nechává na sobě sada: jmenovaný test je v repu, takže
        // ho běh spouští. Pouštět ho odsud znovu by znamenalo tvrdit výsledek,
        // který tenhle test sám neměří.
        self::assertSame([], $missing,
            "vynuceno-a-otestovano, ale jmenovaný test v repu není:\n  " . implode("\n  ", $missing));
    }

    /**
     * ROZŠÍŘENÍ NAD PSANOU SPECIFIKACI (hlavička TSV zná pět červených podmínek,
     * tyhle tři mezi nimi nejsou).
     *
     * Katalog vznikl proti driftu, kdy dokumentace předbíhala kód. Tenhle blok
     * hlídá drift OPAČNÝM směrem: kód předběhl dokumentaci a záznam pořád tvrdí
     * „není implementováno". Bez toho smí katalog tvrdit nepravdu libovolně dlouho
     * po tom, co implementace vznikla — a nikdo si toho nevšimne, protože všechny
     * ostatní kontroly jsou zelené.
     *
     * PRVNÍ POKUS BYL SLABÝ a stojí za to to říct: hlídal doslovný řetězec
     * „kód neexistuje" a zachytil 2 záznamy z 23. Dalších dvanáct říkalo
     * „implementace neexistuje" a devět „nezapojeno do dávkové cesty" — táž
     * nepravda, jiný pravopis. Strážce, který hlídá jednu formulaci tvrzení,
     * dává falešné bezpečí: zelená znamená jen to, že se nikdo netrefil do jeho
     * slovníku. Proto default-deny níž.
     *
     * @var array<string,string> hodnota `blokuje:` → třída tvrzení
     */
    private const BLOCKER_CLAIMS = [
        'implementace neexistuje' => 'no-code',
        'kód neexistuje'          => 'no-code',
        'jádro neexistuje'        => 'no-code',
        'tabulky neexistují'      => 'no-code',
        'nezapojeno'              => 'not-wired',  // prefix
        'chybí dekodér QR'        => 'other',      // prefix; V33–V35: zapojeno, běží unavailable (A2)
        'test neexistuje'         => 'other',
        'není implementace'       => 'no-code',    // prefix
        'jediný výskyt v repu'    => 'other',      // prefix
        'RULE-PATH-MATRIX.md'     => 'other',
    ];

    private static function claimOf(string $blocker): ?string
    {
        foreach (self::BLOCKER_CLAIMS as $needle => $class) {
            if (str_starts_with($blocker, $needle)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * DEFAULT-DENY NAD SLOVNÍKEM DŮVODŮ.
     *
     * Neznámá formulace `blokuje:` je červená, ne „projde, protože jí nerozumím".
     * Je to totéž pravidlo, které katalog vyžaduje od svého parseru — na
     * nerozpoznaném řádku padat, nikdy ho nepřeskočit. Beze změny tohohle testu
     * nejde přidat nový důvod, což je záměr: důvod, který nikdo nezařadil, není
     * hlídaný a tiše vyroste v další drift.
     */
    public function testBlockerVocabularyStaysClosed(): void
    {
        $unknown = [];
        foreach (self::records() as $id => $rec) {
            foreach ($rec['keys']['blokuje'] ?? [] as $why) {
                if (self::claimOf($why) === null) {
                    $unknown[] = "{$id} (RULE-STATUS.tsv:{$rec['line']}) — '{$why}'";
                }
            }
        }

        self::assertSame([], $unknown,
            "nezařazená formulace důvodu — zařaď ji, nebo přepiš na existující:\n  "
            . implode("\n  ", $unknown));
    }

    /**
     * Tvrzení „kód neexistuje" musí platit. Sken je úzký ZÁMĚRNĚ: jen náš jmenný
     * prostor a jen ID v uvozovkách, tedy tvar, kterým se nález skutečně vydává.
     * Zmínka v komentáři ani v próze implementaci nedokládá.
     */
    public function testNoRecordClaimsMissingCodeWhileTheRuleAlreadyEmitsFindings(): void
    {
        $emitted = self::emitters();

        $lying = [];
        foreach (self::records() as $id => $rec) {
            if (!isset($emitted[$id])) {
                continue;
            }
            foreach ($rec['keys']['blokuje'] ?? [] as $why) {
                if (self::claimOf($why) === 'no-code') {
                    $lying[] = "{$id} (RULE-STATUS.tsv:{$rec['line']}) — vydává nález v "
                        . implode(', ', $emitted[$id]);
                }
            }
        }

        self::assertSame([], $lying,
            "záznam tvrdí, že kód neexistuje, ale pravidlo už vydává nález:\n  "
            . implode("\n  ", $lying));
    }

    /**
     * Tvrzení „nezapojeno do dávkové cesty" musí platit.
     *
     * PŘIZNANÝ DOSAH: kontrola jde JEDEN SKOK. Ověří, že třída, která nález vydává,
     * není injektovaná do `ResultsValidator` — orchestrátoru, přes který dávková
     * cesta vede. Zapojení schované hlouběji v řetězci tenhle test nenajde, takže
     * chytá PODMNOŽINU nepravdivých tvrzení, ne všechna. Říct to nahlas je levnější
     * než později zjistit, že se zelená četla jako důkaz.
     *
     * Právě tímhle rozlišením zůstává V33–V35 (`QrCheck`) poctivě „nevynuceno":
     * kód existuje a je otestovaný, ale `ResultsValidator` si ho neinjektuje,
     * takže na dávkové cestě nikdy nedoběhne.
     */
    public function testNoRecordClaimsNotWiredWhileItIsWiredIntoTheValidator(): void
    {
        $validator = (string) file_get_contents(
            self::root() . '/api/src/Service/PurchaseBatchImport/ResultsValidator.php'
        );

        // Konstruktorové property promotion: `private readonly Xxx $yyy`.
        //
        // `readonly` JE VOLITELNÉ A MUSÍ BÝT — v testech ho totiž ve zdrojáku
        // nevidíme. `tests/bootstrap.php` volá `\DG\BypassFinals::enable()`, což
        // registruje vlastní stream wrapper na `file://` a přepisuje PHP zdrojáky
        // za běhu. `file_get_contents()` tedy uvnitř sady NEVRACÍ obsah disku:
        // změřeno na tomhle souboru — 7830 B na disku, 7801 B v testu,
        // `final` 1→0, `readonly` 3→0.
        //
        // Vzor, který `readonly` vyžadoval, proto nenašel nic, `$wired` bylo
        // prázdné a test procházel NAPRÁZDNO — zelený, a přitom neporovnával nic.
        // Platí pro všech 7 architekturních testů, které čtou zdrojáky: na
        // `final` ani `readonly` se v nich nedá stavět.
        preg_match_all('/private\s+(?:readonly\s+)?([A-Za-z_][A-Za-z0-9_]*)\s+\$/', $validator, $m);
        $wired = array_flip($m[1]);

        $emitted = self::emitters();

        // BEZ TOHOHLE BY TEST PROŠEL NAPRÁZDNO. Kdyby se změnil zápis konstruktoru
        // a vzor přestal chytat, `$wired` by bylo prázdné, `isset()` vždy false
        // a zelená by znamenala jen „nic jsem nenašel". Prázdná množina musí být
        // červená, ne tichý souhlas — to je táž chyba jako prázdný test.
        self::assertNotSame([], $wired, 'nenačetly se závislosti ResultsValidatoru — kontrola by neměla co porovnávat'
            . ' [cesta=' . self::root() . '/api/src/Service/PurchaseBatchImport/ResultsValidator.php'
            . ', bajtu=' . strlen($validator) . ']');
        self::assertNotSame([], $emitted, 'nenačetla se žádná pravidla vydávající nález');

        $lying = [];
        foreach (self::records() as $id => $rec) {
            if (!isset($emitted[$id])) {
                continue;
            }
            foreach ($rec['keys']['blokuje'] ?? [] as $why) {
                if (self::claimOf($why) !== 'not-wired') {
                    continue;
                }
                foreach ($emitted[$id] as $class) {
                    if (isset($wired[$class])) {
                        $lying[] = "{$id} (RULE-STATUS.tsv:{$rec['line']}) — {$class} JE injektovaná do ResultsValidator";
                    }
                }
            }
        }

        self::assertSame([], $lying,
            "záznam tvrdí nezapojení, ale třída je na dávkové cestě:\n  " . implode("\n  ", $lying));
    }

    /**
     * ID → třídy, které pro něj vydávají nález.
     *
     * @return array<string,list<string>>
     */
    private static function emitters(): array
    {
        $out = [];

        foreach ([
            'api/src/Service/PurchaseBatchImport',
            'api/src/Action/PurchaseInvoice/BatchImport',
        ] as $dir) {
            $abs = self::root() . '/' . $dir;
            if (!is_dir($abs)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $class = $file->getBasename('.php');
                preg_match_all("/'(V(?:[1-9][0-9]*)(?:[a-e])?)'/",
                    (string) file_get_contents($file->getPathname()), $m);
                foreach (array_unique($m[1]) as $id) {
                    $out[$id][] = $class;
                }
            }
        }

        return $out;
    }
}
