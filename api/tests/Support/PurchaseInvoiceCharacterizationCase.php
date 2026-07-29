<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * FORK (beevee85) — společná základna CHARAKTERIZAČNÍCH testů zapisovacích cest
 * přijatých faktur.
 *
 * Charakterizační test nepopisuje, jak by se to chovat MĚLO — zafixuje, jak se to
 * chová DNES, aby šlo dokázat, že refaktoring (sdílená write service, transakce,
 * převedení importních cest) nic nezměnil. Když takový test spadne, je to buď regrese,
 * nebo vědomá změna, kterou je nutné popsat v commit message.
 *
 * Soubor nemá příponu `Test.php`, takže ho PHPUnit nesbírá jako testovací třídu.
 */
abstract class PurchaseInvoiceCharacterizationCase extends TestCase
{
    /** Rok mimo reálná účetní období — testy nesmí zasahovat do skutečných dat. */
    protected const YEAR = 2099;

    protected Connection $db;
    protected \Psr\Container\ContainerInterface $container;

    protected int $supplierId = 0;
    protected int $currencyId = 0;
    protected int $userId     = 0;
    protected int $countryId  = 0;
    protected int $vendorId   = 0;

    /** @var list<int> id dokladů založených testem — mažou se v tearDown */
    protected array $createdInvoices = [];

    protected function setUp(): void
    {
        parent::setUp();

        // tests/Support → tests → api → kořen repa (tam leží cfg.php).
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }

        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db        = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        $this->userId    = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->countryId === 0) {
            self::markTestSkipped('Chybí supplier/CZK currency/admin user/country — spusť api/bin/test-db-prepare.php.');
        }

        $this->cleanup();
        $this->vendorId = $this->createVendor();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
            $this->db->close();
        }
        parent::tearDown();
    }

    /** IČO tenanta — cross-tenant guardy importních cest ho porovnávají s dokladem. */
    protected function tenantIc(): string
    {
        $ic = (string) $this->db->pdo()
            ->query("SELECT COALESCE(ic, '') FROM supplier WHERE id = {$this->supplierId}")
            ->fetchColumn();
        if ($ic === '') {
            self::markTestSkipped('Tenant nemá IČO — spusť api/bin/test-seed-clients.php.');
        }
        return $ic;
    }

    /**
     * Dodavatel používaný testem. IČO je zjevně syntetické, ale s platnou kontrolní
     * číslicí (mod 11) — validátory jinak data odmítnou.
     */
    protected function createVendor(string $name = 'Charakterizace dodavatel s.r.o.', string $ic = '88888886'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor, is_vat_payer)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, 1)'
        )->execute([
            $this->supplierId, $name, $ic, 'CZ' . $ic, 'Charakterizační 1', 'Praha', '11000',
            $this->countryId, 'characterization-vendor@example.invalid', 'cs', $this->currencyId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    protected function trackInvoice(int $id): int
    {
        if ($id > 0 && !in_array($id, $this->createdInvoices, true)) {
            $this->createdInvoices[] = $id;
        }
        return $id;
    }

    /**
     * Obraz zapsaného dokladu se zástupkami za volatilní hodnoty.
     *
     * @return array{header: array<string,mixed>, items: list<array<string,mixed>>}
     */
    protected function snapshot(int $invoiceId): array
    {
        $pdo = $this->db->pdo();
        return PurchaseInvoiceSnapshot::capture($pdo, $invoiceId, PurchaseInvoiceSnapshot::vatRateLabels($pdo));
    }

    /** @return list<array<string,mixed>> */
    protected function activity(int $invoiceId): array
    {
        return PurchaseInvoiceSnapshot::captureActivity($this->db->pdo(), $invoiceId);
    }

    /** id sazby DPH podle kódu číselníku (CZ-21, CZ-12, CZ-0, CZ-RC, CZ-NA). */
    protected function vatRateId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM vat_rates WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            self::markTestSkipped("Číselník nemá sazbu {$code}.");
        }
        return $id;
    }

    /** @param array<string,mixed> $body */
    protected function request(string $method, string $path, array $body = [], string $role = 'admin'): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withHeader('User-Agent', 'phpunit-characterization')
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    protected static function json(Psr7Response $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function cleanup(): void
    {
        $pdo = $this->db->pdo();

        foreach ($this->createdInvoices as $id) {
            $pdo->prepare('DELETE FROM activity_log WHERE entity_type = ? AND entity_id = ?')
                ->execute(['purchase_invoice', $id]);
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        $this->createdInvoices = [];

        // Doklady, které test založil oklikou (importní cesty vracejí id až na konci).
        $pdo->prepare(
            "DELETE FROM purchase_invoice_items
              WHERE purchase_invoice_id IN (
                SELECT id FROM purchase_invoices WHERE supplier_id = ? AND YEAR(issue_date) = ?
              )"
        )->execute([$this->supplierId, self::YEAR]);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ? AND YEAR(issue_date) = ?')
            ->execute([$this->supplierId, self::YEAR]);
        $pdo->prepare('DELETE FROM purchase_invoice_counters WHERE supplier_id = ? AND period LIKE ?')
            ->execute([$this->supplierId, self::YEAR . '%']);

        if ($this->vendorId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->vendorId]);
            $this->vendorId = 0;
        }
    }
}
