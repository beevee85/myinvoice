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
 * FORK (beevee85): pokladní doklady (migrace 0901 + 0924) — číslování řad
 * PPD/VPD per pokladna, supplier scope, validace, H7 storno (mazání zakázané),
 * H3 pravidla daňového dokladu (VB2/VB4) a VB8 záporný zůstatek s časovým
 * pravidlem Dokumentu 5 (blok jen dopředně).
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
        // Nejdřív storno protidoklady (FK storno_of_id je ON DELETE RESTRICT),
        // pak originály — jedna DELETE by mohla smazat rodiče před potomkem.
        $this->db->pdo()->prepare('DELETE FROM cash_documents WHERE description LIKE ? AND storno_of_id IS NOT NULL')
            ->execute(['%' . self::MARKER . '%']);
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

    public function testDeleteIsForbiddenStornoCreatesCounterDocument(): void
    {
        $a = $this->create('income', 100.00);
        $b = $this->create('income', 200.00);

        // H7: mazání je zakázané VŽDY — i u posledního dokladu řady.
        foreach ([$a, $b] as $doc) {
            $del = $this->action->delete(
                $this->request('DELETE', '/api/cash-documents/' . $doc['data']['id']),
                new Psr7Response(200),
                ['id' => (string) $doc['data']['id']],
            );
            self::assertSame(409, $del->getStatusCode(), 'Pokladní doklad nelze smazat, jen stornovat.');
        }

        // Storno vytvoří protidoklad se zápornou částkou ve stejné řadě.
        $storno = $this->decode($this->action->storno(
            $this->request('POST', '/api/cash-documents/' . $a['data']['id'] . '/storno',
                ['reason' => 'chybná částka ' . self::MARKER]),
            new Psr7Response(200),
            ['id' => (string) $a['data']['id']],
        ));
        self::assertSame(201, $storno['status']);
        self::assertSame('storno', $storno['data']['original']['status'], 'Originál zůstává viditelný jako stornovaný.');
        self::assertSame(-100.00, (float) $storno['data']['storno']['amount'], 'Protidoklad nese zápornou částku.');
        self::assertSame($a['data']['id'], $storno['data']['storno']['storno_of_id']);
        self::assertStringStartsWith('PPD-', (string) $storno['data']['storno']['number'], 'Storno jede v téže řadě.');

        // Storno storna ani opakované storno nejde.
        $again = $this->action->storno(
            $this->request('POST', '/api/cash-documents/' . $a['data']['id'] . '/storno',
                ['reason' => 'znovu ' . self::MARKER]),
            new Psr7Response(200),
            ['id' => (string) $a['data']['id']],
        );
        self::assertSame(409, $again->getStatusCode());
    }

    public function testTaxDocumentRules(): void
    {
        // VB2: doklad hradící fakturu nesmí nést rozpis DPH.
        $invoiceId = (int) $this->db->pdo()->query('SELECT MIN(id) FROM invoices WHERE supplier_id = ' . $this->supplierId)->fetchColumn();
        if ($invoiceId > 0) {
            $r = $this->decode($this->action->create($this->request('POST', '/api/cash-documents', [
                'kind' => 'income', 'issue_date' => self::YEAR . '-06-15', 'amount' => 121,
                'description' => self::MARKER, 'invoice_id' => $invoiceId,
                'is_tax_document' => true,
                'vat_breakdown' => [['rate' => 21, 'base' => 100, 'vat' => 21]],
            ]), new Psr7Response(200)));
            self::assertSame(409, $r['status'], 'VB2 — rozpis DPH na dokladu k faktuře = dvojí vykázání daně.');
        }

        // VB4: zjednodušený daňový doklad nad limit § 30a (10 000 vč. daně).
        $r = $this->decode($this->action->create($this->request('POST', '/api/cash-documents', [
            'kind' => 'income', 'issue_date' => self::YEAR . '-06-15', 'amount' => 12100,
            'description' => self::MARKER, 'is_tax_document' => true,
            'vat_breakdown' => [['rate' => 21, 'base' => 10000, 'vat' => 2100]],
        ]), new Psr7Response(200)));
        self::assertSame(409, $r['status'], 'VB4 — nad limit nelze zjednodušený daňový doklad.');

        // Platný zjednodušený daňový doklad do limitu projde a nese rozpad.
        $r = $this->decode($this->action->create($this->request('POST', '/api/cash-documents', [
            'kind' => 'income', 'issue_date' => self::YEAR . '-06-15', 'amount' => 1210,
            'description' => self::MARKER, 'is_tax_document' => true,
            'vat_breakdown' => [['rate' => 21, 'base' => 1000, 'vat' => 210]],
        ]), new Psr7Response(200)));
        self::assertSame(201, $r['status']);
        self::assertTrue($r['data']['is_tax_document']);
        self::assertSame(21.0, (float) $r['data']['vat_breakdown'][0]['rate']);
    }

    public function testNegativeBalanceBlocksForwardAllowsBackdated(): void
    {
        $this->create('income', 100.00); // zůstatek řady = 100 (rok 2098 je „budoucí")

        // Dopředný výdaj nad zůstatek (datum 2098 >= dnešek) → BLOK (VB8).
        $r = $this->decode($this->action->create($this->request('POST', '/api/cash-documents', [
            'kind' => 'expense', 'issue_date' => self::YEAR . '-06-16', 'amount' => 500,
            'description' => self::MARKER,
        ]), new Psr7Response(200)));
        self::assertSame(409, $r['status'], 'Dopředný výdaj do záporu se blokuje.');

        // Zpětný záznam (minulé datum) PROJDE s varováním — účetnictví zobrazuje skutečnost.
        $r = $this->decode($this->action->create($this->request('POST', '/api/cash-documents', [
            'kind' => 'expense', 'issue_date' => '2020-01-10', 'accounting_date' => '2020-01-10',
            'amount' => 500, 'description' => self::MARKER,
        ]), new Psr7Response(200)));
        self::assertSame(201, $r['status'], 'Zpětný záznam nesmí být blokován (Dokument 5, pravidlo 2).');
        self::assertSame('negative_balance_backdated', $r['data']['warnings'][0]['code'] ?? null);
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
