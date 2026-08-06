<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — každá naše routa musí být ve specifikaci.
 *
 * Nedokumentovaný endpoint je endpoint, který integrátor mine, a zároveň endpoint,
 * o kterém se za půl roku nikdo nedozví, co vlastně slibuje. `cmd/check-openapi-coverage.php`
 * drift sice hlásí, ale vlastní hlavičkou přiznává „exit 1 = CI warning, ne fail" —
 * tedy nevynucuje nic. Hlášení, které nikoho nezastaví, je popis stavu, ne pojistka.
 *
 * ROZSAH JE ZÁMĚRNĚ NAŠE FEATURA, NE CELÝ REPOZITÁŘ. Nedokumentovaných rout je
 * dnes 110 a jsou zděděné; udělat z nich červenou by znamenalo zablokovat sadu
 * kvůli cizímu dluhu, nebo — hůř — ten dluh potichu „opravit" hromadným zásahem
 * do cizí dokumentace. Hlídá se proto to, co tenhle fork přidal.
 */
final class BatchImportOpenApiCoverageTest extends TestCase
{
    /**
     * Slim vzor → cesta ve specifikaci.
     *
     * Stejný převod, jaký dělá `cmd/check-openapi-coverage.php`: zahodit typovou
     * část placeholderu (`{id:[0-9]+}` → `{id}`) a doplnit `/v1`, který spec
     * používá a routovací tabulka ne.
     */
    private static function toSpecPath(string $pattern): string
    {
        $clean = preg_replace('/:\[[^\]]+\][^}]*/', '', $pattern) ?? $pattern;

        return '/api/v1' . substr($clean, strlen('/api'));
    }

    /** @return list<string> vzory registrovaných rout dávkového importu */
    private function batchImportPatterns(): array
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje konfiguraci.');
        }

        try {
            $app = Bootstrap::buildApp();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        // KLÍČEM JE „METODA cesta", ne jen cesta. Review našlo, že dedup podle
        // vzoru zploštil GET a POST /batch-import na jeden záznam a kontrola
        // byla k metodě slepá: smazaný blok `post:` ve specifikaci prošel,
        // protože klíč cesty držel `get:`.
        $out = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            if (!str_contains($route->getPattern(), '/batch-import')) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $out[$method . ' ' . $route->getPattern()] = true;
            }
        }

        $patterns = array_keys($out);
        if ($patterns === []) {
            // Zelená s prázdným seznamem by znamenala „nic jsem nenašel", ne „vše
            // je zdokumentované". Vypnutý příznak je legitimní stav, ale musí být
            // vidět jako přeskočení, ne jako průchod.
            $this->markTestSkipped('Příznak batch_import je vypnutý — routy neexistují, není co dokumentovat.');
        }

        return $patterns;
    }

    /**
     * Mapa cesta → seznam metod ze specifikace. Parsuje se řádkově: klíč cesty
     * na dvou mezerách, operace na čtyřech; `components:` sekci paths ukončí,
     * aby se nic pod ní nepřipsalo poslední cestě.
     *
     * @return array<string,list<string>>
     */
    private function specOperations(): array
    {
        $lines = explode("\n", (string) file_get_contents(dirname(__DIR__, 2) . '/openapi.yaml'));

        $ops = [];
        $current = null;
        foreach ($lines as $line) {
            if (preg_match('/^components:/', $line)) {
                $current = null;
                continue;
            }
            if (preg_match('/^  (\/api\/v1\/[^:]+):/', $line, $m)) {
                $current = $m[1];
                $ops[$current] ??= [];
                continue;
            }
            if ($current !== null && preg_match('/^    (get|post|put|patch|delete):/', $line, $m)) {
                $ops[$current][] = strtoupper($m[1]);
            }
        }

        return $ops;
    }

    public function testEveryRegisteredBatchImportRouteHasASpecEntry(): void
    {
        $ops = $this->specOperations();
        self::assertNotSame([], $ops, 'openapi.yaml nevydal žádné cesty — parser by neměl co porovnávat.');

        $missing = [];
        foreach ($this->batchImportPatterns() as $methodPattern) {
            [$method, $pattern] = explode(' ', $methodPattern, 2);
            $path = self::toSpecPath($pattern);

            if (!in_array($method, $ops[$path] ?? [], true)) {
                $missing[] = "{$methodPattern} → očekávána operace " . strtolower($method) . ": pod '{$path}:'";
            }
        }

        self::assertSame([], $missing,
            "routa bez operace ve specifikaci:\n  " . implode("\n  ", $missing));
    }

    /**
     * Popis nesmí být prázdná slupka — a to PER OPERACE, ne per cesta.
     *
     * Bez tohohle by šlo pojistku uspokojit holým klíčem bez jediného slova.
     * Review našlo starší slabinu: summary GETu uspokojilo i POST na téže
     * cestě. Teď se `summary:` hledá uvnitř bloku konkrétní operace.
     */
    public function testEverySpecOperationForOurRoutesCarriesASummary(): void
    {
        $lines = explode("\n", (string) file_get_contents(dirname(__DIR__, 2) . '/openapi.yaml'));

        $wanted = [];
        foreach ($this->batchImportPatterns() as $methodPattern) {
            [$method, $pattern] = explode(' ', $methodPattern, 2);
            $wanted[self::toSpecPath($pattern) . ' ' . strtolower($method)] = false;
        }

        $currentPath = null;
        $currentOp   = null;
        foreach ($lines as $line) {
            if (preg_match('/^components:/', $line)) {
                $currentPath = $currentOp = null;
                continue;
            }
            if (preg_match('/^  (\/api\/v1\/[^:]+):/', $line, $m)) {
                $currentPath = $m[1];
                $currentOp   = null;
                continue;
            }
            if ($currentPath !== null && preg_match('/^    (get|post|put|patch|delete):/', $line, $m)) {
                $currentOp = $m[1];
                continue;
            }
            if ($currentPath !== null && $currentOp !== null
                && preg_match('/^\s+summary:\s*\S/', $line)) {
                $key = $currentPath . ' ' . $currentOp;
                if (array_key_exists($key, $wanted)) {
                    $wanted[$key] = true;
                }
            }
        }

        $withoutSummary = array_keys(array_filter($wanted, static fn (bool $ok) => !$ok));

        self::assertSame([], $withoutSummary,
            'operace ve specifikaci bez `summary:` — klíč sám o sobě nic nedokumentuje: '
            . implode(', ', $withoutSummary));
    }
}
