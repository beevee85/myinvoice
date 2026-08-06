<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — registrace rout dávkového importu za příznakem.
 *
 * VYPNUTÝ PŘÍZNAK MUSÍ ZNAMENAT 404, NE 403. Kdyby endpoint vracel 403,
 * prozradil by, že featura existuje a je jen vypnutá — a to je informace,
 * kterou nepřihlášený nemá dostat. „Neregistrovat vůbec" je silnější než
 * „registrovat a odmítat".
 *
 * Test čte routovací tabulku, ne HTTP odpověď: plnohodnotné e2e přes
 * $app->handle() v tomhle repu neexistuje (PLAN.md O-7) a stavět ho kvůli
 * jedné kontrole by bylo dražší než užitečnější.
 */
#[Group('integration')]
final class BatchImportRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje konfiguraci.');
        }
    }

    /** @return list<string> „METODA cesta" pro všechny registrované routy */
    private function routes(): array
    {
        try {
            $app = Bootstrap::buildApp();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $out = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $out[] = $method . ' ' . $route->getPattern();
            }
        }

        return $out;
    }

    private function batchImportRoutes(): array
    {
        return array_values(array_filter(
            $this->routes(),
            static fn (string $r) => str_contains($r, '/batch-import'),
        ));
    }

    /**
     * Vrátí routy, nebo test přeskočí, když je příznak vypnutý.
     *
     * Bez tohohle procházely dva testy s NULOVÝM počtem asercí (cyklus přes
     * prázdné pole) a PHPUnit je hlásil jako „risky". Zelený test, který nic
     * netvrdí, je horší než přeskočený: přeskočení je vidět, prázdný průchod ne.
     *
     * @return list<string>
     */
    private function batchImportRoutesOrSkip(): array
    {
        $routes = $this->batchImportRoutes();
        if ($routes === []) {
            $this->markTestSkipped('Příznak batch_import je vypnutý — routy neexistují, není co kontrolovat.');
        }

        return $routes;
    }

    public function testRoutesFollowTheFlag(): void
    {
        $enabled = (bool) Bootstrap::buildApp()->getContainer()
            ->get(Config::class)->get('purchase_invoice.batch_import.enabled', false);

        $routes = $this->batchImportRoutes();

        if ($enabled) {
            self::assertNotSame([], $routes, 'se zapnutým příznakem musí routy existovat');
            // VŠECHNY routy featury, ne vzorek. Review našlo, že test hlídal jen
            // dvě z šesti — smazaná registrace apply by prošla zeleně, protože
            // OpenAPI pojistka kontroluje jen směr routy→spec (zmizelá routa
            // ze sbírky zmizí i z kontroly).
            $expected = [
                'GET /api/purchase-invoices/batch-import',
                'POST /api/purchase-invoices/batch-import',
                'GET /api/purchase-invoices/batch-import/{id:[0-9]+}',
                'GET /api/purchase-invoices/batch-import/{id:[0-9]+}/package',
                'POST /api/purchase-invoices/batch-import/{id:[0-9]+}/results',
                'POST /api/purchase-invoices/batch-import/{id:[0-9]+}/results/{resultId:[0-9]+}/apply',
            ];
            sort($expected);
            $actual = $routes;
            sort($actual);
            self::assertSame($expected, $actual,
                'množina rout featury musí sedět PŘESNĚ — nová routa sem patří v témže commitu');
        } else {
            self::assertSame([], $routes,
                'vypnutý příznak nesmí routy registrovat vůbec — jinak 403 prozradí, že featura existuje');
        }
    }

    /**
     * Upstream má `/api/purchase-invoices/import-batches` pro JINÝ koncept
     * (dohledání dávky AI importu, #232). Naše cesta se s ním nesmí potkat —
     * je to táž chyba, kterou A3 zamítlo na úrovni databáze.
     */
    public function testDoesNotCollideWithUpstreamImportBatches(): void
    {
        foreach ($this->batchImportRoutesOrSkip() as $r) {
            self::assertStringNotContainsString('/import-batches', $r,
                'dávkový import nesmí sdílet cestu s upstreamovým konceptem');
        }
    }

    /**
     * Zůstáváme pod `/api/purchase-invoices/`, takže RoleMiddleware,
     * SupplierScopeMiddleware i ApiScopeMiddleware fungují beze změny (A1, O-2).
     * Kdyby cesta vypadla z toho prefixu, ApiScopeMiddleware by ji odmítl
     * a musel by se editovat upstream soubor.
     */
    public function testStaysUnderAlreadyAllowedPrefix(): void
    {
        foreach ($this->batchImportRoutesOrSkip() as $r) {
            self::assertMatchesRegularExpression(
                '#^[A-Z]+ /api/purchase-invoices/#', $r,
                'cesta mimo tenhle prefix by si vyžádala zásah do middlewaru',
            );
        }
    }
}
