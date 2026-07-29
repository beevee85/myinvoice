<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Codebook;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Codebook\DefaultExpenseCategories;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výchozí číselník kategorií nákladu (migrace 0912 + seeder pro nové tenanty).
 *
 * Kategorie pohánějí rozpad nákladů na dashboardu a v CRM; dokud se číselník
 * nikde nepředvyplňoval, startoval každý tenant s prázdným seznamem a rozpad
 * byl nepoužitelný. Seeder proto musí:
 *   - doplnit obecnou sadu tenantovi, který žádnou kategorii nemá,
 *   - NEsahat na tenanta, který si už vlastní kategorie založil,
 *   - být idempotentní (opakovaný běh nic nepřidá).
 */
#[Group('integration')]
final class DefaultExpenseCategoriesTest extends TestCase
{
    private Connection $db;
    private int $supplierId = 0;
    /** @var int[] */
    private array $createdSuppliers = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()
            ->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->createdSuppliers as $id) {
            $pdo->prepare('DELETE FROM expense_categories WHERE supplier_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testMigrationSeedsTenantsWithoutCategories(): void
    {
        // Pozn.: v CI běží migrace DŘÍV, než seed založí firmy, takže se test nesmí
        // spoléhat na stav po migraci — spustíme její SQL explicitně nad novou firmou.
        $pdo = $this->db->pdo();
        $newId = $this->supplier('Test — migrace 0912');
        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/db/migrations/0912_default_expense_categories.sql');
        $pdo->exec($sql);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE supplier_id = ?');
        $stmt->execute([$newId]);
        self::assertSame(count(DefaultExpenseCategories::DEFAULTS), (int) $stmt->fetchColumn(),
            'Migrace 0912 musí firmě bez kategorií doplnit celou výchozí sadu.');

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM expense_categories WHERE supplier_id = ? AND code = 'zbozi'"
        );
        $stmt->execute([$newId]);
        self::assertSame(1, (int) $stmt->fetchColumn(),
            'Sada musí obsahovat „Zboží k dalšímu prodeji" — bez ní nejde odlišit zásobu od majetku.');

        // idempotence migrace
        $pdo->exec($sql);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE supplier_id = ?');
        $stmt->execute([$newId]);
        self::assertSame(count(DefaultExpenseCategories::DEFAULTS), (int) $stmt->fetchColumn(),
            'Opakované spuštění migrace nesmí nic přidat.');
    }

    public function testSeedFillsEmptyTenantAndIsIdempotent(): void
    {
        $pdo = $this->db->pdo();
        $newId = $this->supplier('Test — prázdný číselník');

        $first = DefaultExpenseCategories::seed($pdo, $newId);
        self::assertSame(count(DefaultExpenseCategories::DEFAULTS), $first,
            'Prázdnému tenantovi se naseeduje celá sada.');

        $second = DefaultExpenseCategories::seed($pdo, $newId);
        self::assertSame(0, $second, 'Opakovaný běh už nic nepřidá.');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE supplier_id = ?');
        $stmt->execute([$newId]);
        self::assertSame(count(DefaultExpenseCategories::DEFAULTS), (int) $stmt->fetchColumn());
    }

    public function testSeedNeverOverwritesOwnCategories(): void
    {
        $pdo = $this->db->pdo();
        $newId = $this->supplier('Test — vlastní číselník');

        $pdo->prepare(
            "INSERT INTO expense_categories (supplier_id, code, label, fixed_or_var, display_order)
             VALUES (?, 'vlastni', 'Moje kategorie', 'variable', 5)"
        )->execute([$newId]);

        self::assertSame(0, DefaultExpenseCategories::seed($pdo, $newId),
            'Tenant s vlastním číselníkem se nesmí přepsat.');

        $stmt = $pdo->prepare('SELECT code FROM expense_categories WHERE supplier_id = ?');
        $stmt->execute([$newId]);
        self::assertSame(['vlastni'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testDefaultsAreConsistent(): void
    {
        $codes = array_column(DefaultExpenseCategories::DEFAULTS, 0);
        self::assertSame($codes, array_unique($codes), 'Kódy musejí být unikátní (uq_expense_categories).');
        foreach (DefaultExpenseCategories::DEFAULTS as [$code, $label, $fixedOrVar, $order]) {
            self::assertNotSame('', $code);
            self::assertLessThanOrEqual(20, strlen($code), 'code je varchar(20)');
            self::assertLessThanOrEqual(100, strlen($label), 'label je varchar(100)');
            self::assertContains($fixedOrVar, ['fixed', 'variable']);
            self::assertGreaterThan(0, $order);
        }
    }

    private function supplier(string $name): int
    {
        $pdo = $this->db->pdo();
        $czId  = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn();
        $curId = (int) $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $vatId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, display_name, street, city, zip, country_id, email,
                                   default_currency_id, default_vat_rate_id)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "test@example.com", ?, ?)'
        )->execute([$name, $name, $czId, $curId, $vatId]);
        $id = (int) $pdo->lastInsertId();
        $this->createdSuppliers[] = $id;
        return $id;
    }
}
