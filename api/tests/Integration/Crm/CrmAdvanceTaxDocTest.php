<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FORK 0920: připomínka lhůty pro daňový doklad k přijaté záloze (DDKPZ).
 *
 * § 28 odst. 1 písm. d) + odst. 8 ZDPH: plátce vystaví doklad do 15 dnů od přijetí
 * úplaty. Test hlídá, že se připomínka objeví jen tam, kde povinnost skutečně je:
 *   - zaplacená záloha bez daňového dokladu → připomínka s termínem úplata + 15 dnů,
 *   - reverse charge → z úplaty se daň nepřiznává, tedy žádná připomínka,
 *   - neplátce DPH → žádná připomínka,
 *   - vystavená vyúčtovací faktura v 15denní lhůtě (jednodokladový postup) → nic,
 *     ale její pouhý KONCEPT lhůtu neplní, takže připomínka zůstává,
 *   - režim 'none' funkci vypíná.
 * Lhůta je hmotněprávní → počítá se v KALENDÁŘNÍCH dnech (bez posunu na pracovní den).
 *
 * Izolace: vlastní klient + doklady se syntetickými daty, vše se v tearDown maže;
 * nastavení dodavatele se vrací do původního stavu. Soft-skip bez DB.
 */
#[Group('integration')]
final class CrmAdvanceTaxDocTest extends TestCase
{
    private Connection $db;
    private CrmAggregationService $crm;
    private int $supplierId = 0;
    private int $clientId = 0;
    /** @var list<int> */
    private array $invoiceIds = [];
    /** @var array<string,mixed> */
    private array $origSupplier = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->crm = $c->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $cols = $pdo->query("SHOW COLUMNS FROM supplier LIKE 'advance_tax_doc_mode'")->fetchAll();
        if ($cols === []) {
            $this->markTestSkipped('Migrace 0920 neproběhla.');
        }
        $this->origSupplier = $pdo->query(
            "SELECT is_vat_payer, advance_tax_doc_mode FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(\PDO::FETCH_ASSOC) ?: [];

        $currencyId = (int) ($pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($currencyId === 0) {
            $this->markTestSkipped('Chybí měna.');
        }
        $this->currencyId = $currencyId;

        $ins = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  currency_default_id, created_at)
             VALUES (?, ?, ?, ?, ?, (SELECT id FROM countries ORDER BY id LIMIT 1), ?, NOW())'
        );
        $ins->execute([$this->supplierId, 'TEST DDKPZ klient', 'Testovací 1', 'Praha', '11000', $currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_payments WHERE invoice_id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->invoiceIds) as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        if ($this->origSupplier !== []) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, advance_tax_doc_mode = ? WHERE id = ?')
                ->execute([
                    (int) $this->origSupplier['is_vat_payer'],
                    (string) $this->origSupplier['advance_tax_doc_mode'],
                    $this->supplierId,
                ]);
        }
    }

    private int $currencyId = 0;

    private function configure(string $mode, int $vatPayer = 1): void
    {
        $this->db->pdo()
            ->prepare('UPDATE supplier SET is_vat_payer = ?, advance_tax_doc_mode = ? WHERE id = ?')
            ->execute([$vatPayer, $mode, $this->supplierId]);
    }

    /** Zálohová faktura ve stavu `issued`. */
    private function createProforma(string $issueDate, bool $reverseCharge = false): int
    {
        $pdo = $this->db->pdo();
        // amount_to_pay je generovaný sloupec — dopočítá se z total_with_vat a plateb.
        $pdo->prepare(
            "INSERT INTO invoices (invoice_type, supplier_id, client_id, issue_date, due_date, currency_id,
                                   exchange_rate, reverse_charge, prices_include_vat, status, total_with_vat,
                                   created_at)
             VALUES ('proforma', ?, ?, ?, ?, ?, 1, ?, 0, 'issued', 12100.00, NOW())"
        )->execute([$this->supplierId, $this->clientId, $issueDate, $issueDate, $this->currencyId, $reverseCharge ? 1 : 0]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;

        return $id;
    }

    /**
     * Vyúčtovací (finální) faktura navázaná na zálohu.
     * `taxDate` = DUZP; výchozí shodné s datem vystavení, `null` = neuvedeno.
     */
    private function createFinal(
        int $proformaId,
        string $issueDate,
        string $status,
        ?string $taxDate = '',
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices (invoice_type, parent_invoice_id, supplier_id, client_id, issue_date, due_date,
                                   tax_date, currency_id, exchange_rate, reverse_charge, prices_include_vat, status,
                                   total_with_vat, created_at)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, 1, 0, 0, ?, 12100.00, NOW())"
        )->execute([
            $proformaId, $this->supplierId, $this->clientId, $issueDate, $issueDate,
            $taxDate === '' ? $issueDate : $taxDate,
            $this->currencyId, $status,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;

        return $id;
    }

    /** Daňový doklad k přijaté platbě navázaný na (jedinou) platbu zálohy. */
    private function linkTaxDocument(int $proformaId, string $status): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices (invoice_type, parent_invoice_id, supplier_id, client_id, issue_date, due_date,
                                   tax_date, currency_id, exchange_rate, reverse_charge, prices_include_vat, status,
                                   total_with_vat, created_at)
             VALUES ('tax_document', ?, ?, ?, CURDATE(), CURDATE(), CURDATE(), ?, 1, 0, 1, ?, 12100.00, NOW())"
        )->execute([$proformaId, $this->supplierId, $this->clientId, $this->currencyId, $status]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;
        $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = ? WHERE invoice_id = ?')
            ->execute([$id, $proformaId]);

        return $id;
    }

    private function payProforma(int $invoiceId, string $paidOn, float $amount = 12100.00): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, created_at)
             VALUES (?, ?, ?, ?, 'CZK', 'manual', NOW())"
        )->execute([$this->supplierId, $invoiceId, $paidOn, $amount]);
    }

    /** @return array<string,mixed>|null */
    private function item(string $today): ?array
    {
        $res = $this->crm->actionItems($this->supplierId, null, new \DateTimeImmutable($today));
        foreach ($res['items'] as $item) {
            if ($item['type'] === 'advance_tax_doc_due') {
                return $item;
            }
        }

        return null;
    }

    public function testZaplacenaZalohaBezDokladuHlasiLhutu(): void
    {
        $this->configure('offer');
        $this->payProforma($this->createProforma('2026-06-01'), '2026-06-01');

        // 5. 6. → do konce lhůty (16. 6.) zbývá 11 kalendářních dnů.
        $item = $this->item('2026-06-05');
        self::assertNotNull($item, 'Zaplacená záloha bez daňového dokladu musí hlásit lhůtu.');
        self::assertSame(11, $item['days']);
        self::assertSame(1, $item['count']);
        self::assertSame('medium', $item['severity']);
    }

    public function testLhutaSeNeposouvaNaPracovniDen(): void
    {
        $this->configure('offer');
        // Úplata 5. 6. 2026 → lhůta končí 20. 6., což je SOBOTA. Kdyby se termín
        // posouval přes CzechWorkingDays (jako termíny podání), vyšlo by 17 dnů
        // do pondělí 22. 6. — hmotněprávní lhůta § 28/8 se ale neposouvá.
        $this->payProforma($this->createProforma('2026-06-05'), '2026-06-05');
        self::assertSame(15, $this->item('2026-06-05')['days'] ?? null);
    }

    public function testKonceptDdkpzPripominkuNezavre(): void
    {
        $this->configure('offer');
        $proformaId = $this->createProforma('2026-06-01');
        $this->payProforma($proformaId, '2026-06-01');
        // Nejběžnější tok: částečná úhrada z výpisu založí KONCEPT DDKPZ a nalinkuje
        // ho na platbu. Dokud se nevystaví, povinnost trvá — protějšek k testu
        // testPouhyKonceptFinaluPripominkuNezavre.
        $this->linkTaxDocument($proformaId, 'draft');

        $item = $this->item('2026-06-05');
        self::assertNotNull($item, 'Koncept daňového dokladu lhůtu neplní.');
        self::assertStringContainsString('čeká na vystavení', (string) $item['hint']);
    }

    public function testVystavenyDdkpzPripominkuZavre(): void
    {
        $this->configure('offer');
        $proformaId = $this->createProforma('2026-06-01');
        $this->payProforma($proformaId, '2026-06-01');
        $this->linkTaxDocument($proformaId, 'issued');

        self::assertNull($this->item('2026-06-05'), 'Vystavený daňový doklad povinnost splní.');
    }

    public function testFinalVJinemZdanovacimObdobiPripominkuNezavre(): void
    {
        $this->configure('offer');
        // Úplata 29. 6., vyúčtovací faktura vystavená i s DUZP 3. 7. — do 15 dnů,
        // ale v jiném měsíci. Daň z úplaty patří dle § 20a odst. 2 do 06/2026,
        // takže jedním dokladem ji vykázat nelze a povinnost trvá.
        $proformaId = $this->createProforma('2026-06-29');
        $this->payProforma($proformaId, '2026-06-29');
        $this->createFinal($proformaId, '2026-07-03', 'issued', taxDate: '2026-07-03');

        self::assertNotNull($this->item('2026-07-05'), 'Finál v jiném období povinnost nesplní.');
    }

    public function testFinalBezDuzpPripominkuNezavre(): void
    {
        $this->configure('offer');
        $proformaId = $this->createProforma('2026-06-01');
        $this->payProforma($proformaId, '2026-06-01');
        // Bez DUZP nelze doložit, že se plnění do 15 dnů uskutečnilo.
        $this->createFinal($proformaId, '2026-06-10', 'issued', taxDate: null);

        self::assertNotNull($this->item('2026-06-12'));
    }

    public function testPoUplynutiLhutyEskaluje(): void
    {
        $this->configure('offer');
        $this->payProforma($this->createProforma('2026-06-01'), '2026-06-01');

        $item = $this->item('2026-06-20'); // 4 dny po lhůtě
        self::assertNotNull($item);
        self::assertSame(-4, $item['days']);
        self::assertSame('high', $item['severity']);
        self::assertStringContainsString('uplynula', (string) $item['hint']);
    }

    public function testReverseChargeSeNehlida(): void
    {
        $this->configure('offer');
        $this->payProforma($this->createProforma('2026-06-01', reverseCharge: true), '2026-06-01');
        self::assertNull($this->item('2026-06-05'), 'U přenesené daňové povinnosti se z úplaty daň nepřiznává.');
    }

    public function testNeplatceDphSeNehlida(): void
    {
        $this->configure('offer', vatPayer: 0);
        $this->payProforma($this->createProforma('2026-06-01'), '2026-06-01');
        self::assertNull($this->item('2026-06-05'), 'Neplátce DPH daňový doklad k platbě nevystavuje.');
    }

    public function testRezimNoneVypinaPripominku(): void
    {
        $this->configure('none');
        $this->payProforma($this->createProforma('2026-06-01'), '2026-06-01');
        self::assertNull($this->item('2026-06-05'));
    }

    public function testVystavenyFinalDoLhutyPripominkuZavre(): void
    {
        $this->configure('offer');
        $proformaId = $this->createProforma('2026-06-01');
        $this->payProforma($proformaId, '2026-06-01');
        // Jednodokladový postup: plnění vyúčtované fakturou vystavenou do 15 dnů.
        $this->createFinal($proformaId, '2026-06-10', 'issued');

        self::assertNull($this->item('2026-06-12'), 'Vyúčtovací faktura do 15 dnů povinnost splní.');
    }

    public function testPouhyKonceptFinaluPripominkuNezavre(): void
    {
        $this->configure('offer');
        $proformaId = $this->createProforma('2026-06-01');
        $this->payProforma($proformaId, '2026-06-01');
        $this->createFinal($proformaId, '2026-06-10', 'draft');

        self::assertNotNull($this->item('2026-06-12'), 'Koncept se nevystavil — lhůta stále běží.');
    }

    public function testFinalVystavenyPozdePripominkuNezavre(): void
    {
        $this->configure('offer');
        $proformaId = $this->createProforma('2026-06-01');
        $this->payProforma($proformaId, '2026-06-01');
        // Vystaveno až 20. 6., tj. po 15denní lhůtě → povinnost vystavit DDKPZ trvala.
        $this->createFinal($proformaId, '2026-06-20', 'issued');

        self::assertNotNull($this->item('2026-06-22'));
    }

    public function testDismissTypJePovolenyVAllowlistu(): void
    {
        $this->configure('offer');
        $pdo    = $this->db->pdo();
        $userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($userId === 0) {
            self::markTestSkipped('Chybí uživatel pro dismissal.');
        }
        // Regrese: nový typ musí být ve validTypes, jinak „Skrýt" vrátí InvalidArgumentException.
        $this->expectNotToPerformAssertions();
        try {
            $this->crm->dismissActionItem($this->supplierId, $userId, 'advance_tax_doc_due', 'day');
        } catch (\InvalidArgumentException $e) {
            self::fail('Typ advance_tax_doc_due chybí v allowlistu dismissActionItem: ' . $e->getMessage());
        } finally {
            $pdo->prepare("DELETE FROM crm_action_item_dismissals WHERE item_type = 'advance_tax_doc_due'")
                ->execute();
        }
    }
}
