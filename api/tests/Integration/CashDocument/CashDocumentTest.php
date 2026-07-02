<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\CashDocument;

use MyInvoice\Action\CashDocument\CashDocumentAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * FORK (beevee85): pokladní doklady (migrace 0901) — číslování řad PPD/VPD,
 * supplier scope, validace a guard mazání (jen poslední doklad řady).
 *
 * Izolace: doklady s marker popisem v roce 2098, tearDown je smaže.
 * Soft-skip bez cfg.php / DB / migrace.
 */
#[Group('integration')]
final class CashDocumentTest extends TestCase
{
    private Connection $db;
    private CashDocumentAction $action;
    private int $supplierId = 0;
    private int $userId = 0;

    private const MARKER = '__cashdoc_test__';
    private const YEAR = 2098;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->action = $c->get(CashDocumentAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if (!(bool) $pdo->query("SHOW TABLES LIKE 'cash_documents'")->fetchColumn()) {
            $this->markTestSkipped('Migrace 0901_cash_documents neběžela.');
        }
        $this->supplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user.');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->db->pdo()->prepare('DELETE FROM cash_documents WHERE description LIKE ?')
            ->execute(['%' . self::MARKER . '%']);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function request(string $method, string $path, ?array $body = null, ?int $sid = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $request;
    }

    private function create(string $kind, float $amount, ?int $sid = null): array
    {
        $response = $this->action->create($this->request('POST', '/api/cash-documents', [
            'kind' => $kind,
            'issue_date' => self::YEAR . '-06-15',
            'amount' => $amount,
            'counterparty' => 'Test s.r.o.',
            'description' => self::MARKER,
        ], $sid), new Psr7Response(200));
        return $this->decode($response);
    }

    private function decode(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true) ?? [];
        return ['status' => $response->getStatusCode(), 'data' => $payload['data'] ?? $payload];
    }

    // ── testy ──────────────────────────────────────────────────────────────

    public function testNumberingIsSequentialPerKindAndYear(): void
    {
        $a = $this->create('income', 100.00);
        $b = $this->create('income', 200.00);
        $c = $this->create('expense', 50.00);

        self::assertSame(201, $a['status']);
        $prefix = 'PPD-' . self::YEAR . '-';
        self::assertSame($prefix . '0001', $a['data']['number']);
        self::assertSame($prefix . '0002', $b['data']['number']);
        self::assertSame('VPD-' . self::YEAR . '-0001', $c['data']['number'], 'Výdajová řada čísluje nezávisle.');
    }

    public function testDeleteOnlyLastInSeries(): void
    {
        $a = $this->create('income', 100.00);
        $b = $this->create('income', 200.00);

        $del = $this->action->delete(
            $this->request('DELETE', '/api/cash-documents/' . $a['data']['id']),
            new Psr7Response(200),
            ['id' => (string) $a['data']['id']],
        );
        self::assertSame(409, $del->getStatusCode(), 'Mazání uprostřed řady musí selhat (díra v číslování).');

        $del = $this->action->delete(
            $this->request('DELETE', '/api/cash-documents/' . $b['data']['id']),
            new Psr7Response(200),
            ['id' => (string) $b['data']['id']],
        );
        self::assertSame(200, $del->getStatusCode(), 'Poslední doklad řady smazat lze.');
    }

    public function testForeignSupplierScopeIs404(): void
    {
        $a = $this->create('income', 100.00);
        $get = $this->action->get(
            $this->request('GET', '/api/cash-documents/' . $a['data']['id'], null, 999999999),
            new Psr7Response(200),
            ['id' => (string) $a['data']['id']],
        );
        self::assertSame(404, $get->getStatusCode(), 'Cizí supplier scope doklad nevidí.');
    }

    public function testValidationRejectsBadInput(): void
    {
        foreach ([
            ['kind' => 'income', 'issue_date' => 'blbost', 'amount' => 10],
            ['kind' => 'income', 'issue_date' => self::YEAR . '-06-15', 'amount' => -5],
            ['kind' => 'jinak', 'issue_date' => self::YEAR . '-06-15', 'amount' => 10],
        ] as $body) {
            $body['description'] = self::MARKER;
            $response = $this->action->create(
                $this->request('POST', '/api/cash-documents', $body),
                new Psr7Response(200),
            );
            self::assertSame(400, $response->getStatusCode(), json_encode($body, JSON_UNESCAPED_UNICODE));
        }
    }
}
