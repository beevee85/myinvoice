<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Compliance;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ComplianceFlagRepository;
use MyInvoice\Service\Compliance\ComplianceService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FORK 0925 — hotovostní detekce (Dokumenty 4/5/6/9):
 *  - VB3a: dopředná hotovost nad limit = BLOK,
 *  - VB3b: zpětný záznam nad limit = volba uživatele + trvalý příznak,
 *  - VW-AML1: strukturování do navazujících dnů (každá platba pod limitem,
 *    součet nad) = volba + příznak + AmlCase; „nejde o podezřelý obchod"
 *    vyžaduje zdůvodnění ≥ 50 znaků,
 *  - limit se čte k ROZHODNÉMU datu (Dokument 9: 2013 → 350 000, žádný příznak).
 *
 * Akceptační scénář Dokumentu 4 §9: úhrady 250 000 + 253 100 Kč od téže
 * protistrany ve dvou navazujících dnech vyvolají silné varování a bez volby
 * nelze druhou úhradu uložit (zde: checks ≠ [] ⇒ akce vrací 409 ack_required).
 */
#[Group('integration')]
final class ComplianceCashTest extends TestCase
{
    private Connection $db;
    private ComplianceService $service;
    private ComplianceFlagRepository $flags;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $invoiceId = 0;

    /** @var int[] */
    private array $paymentIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->service = $c->get(ComplianceService::class);
            $this->flags = $c->get(ComplianceFlagRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if (!(bool) $pdo->query("SHOW TABLES LIKE 'compliance_flags'")->fetchColumn()) {
            $this->markTestSkipped('Migrace 0925 neběžela.');
        }
        $this->supplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "AML klient __test__", "Test 1", "Praha", "11000", ?, "aml@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO invoices (supplier_id, client_id, invoice_type, status, issue_date, due_date,
                                   currency_id, total_without_vat, total_vat, total_with_vat, varsymbol)
             VALUES (?, ?, 'invoice', 'issued', '2094-06-01', '2094-06-15', ?, 415785.12, 87314.88, 503100.00, 'AMLTEST94')"
        )->execute([$this->supplierId, $this->clientId, $this->currencyId]);
        $this->invoiceId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->paymentIds as $id) {
            $pdo->prepare('DELETE FROM invoice_payments WHERE id = ?')->execute([$id]);
        }
        // Příznaky/AML případy testu: mazání v aplikaci NEEXISTUJE — testovací
        // izolace je jediná výjimka a jde přímým SQL mimo aplikační vrstvu.
        if ($this->clientId) {
            $pdo->prepare('DELETE FROM compliance_flags WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM aml_cases WHERE counterparty_id = ?')->execute([$this->clientId]);
        }
        if ($this->invoiceId) {
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$this->invoiceId]);
        }
        if ($this->clientId) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    private function subject(): array
    {
        return ['subject_type' => 'invoice', 'subject_id' => $this->invoiceId, 'doc_number' => 'AMLTEST94'];
    }

    private function addCashPayment(string $date, float $amount): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, payment_method)
             VALUES (?, ?, ?, ?, 'CZK', 'manual', 'cash')"
        );
        $stmt->execute([$this->supplierId, $this->invoiceId, $date, $amount]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->paymentIds[] = $id;
        return $id;
    }

    public function testForwardOverLimitBlocks(): void
    {
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $r = $this->service->checkCashPayment($this->supplierId, $this->clientId, $tomorrow, 503100.00, $this->subject());
        self::assertNotNull($r['block'], 'VB3a: dopředná hotovost 503 100 Kč nad limit se blokuje.');
        self::assertStringContainsString('254/2004', $r['block']['message']);
        self::assertStringContainsString('příjemce', $r['block']['message'], 'Text musí uvést dopad na příjemce (§ 4/2).');
    }

    public function testBackdatedOverLimitRequiresChoiceAndLeavesFlag(): void
    {
        $r = $this->service->checkCashPayment($this->supplierId, $this->clientId, '2024-06-10', 503100.00, $this->subject());
        self::assertNull($r['block'], 'VB3b: zpětný záznam se neblokuje.');
        self::assertSame('HOTOVOST_NAD_LIMIT', $r['checks'][0]['type'] ?? null);

        // Bez volby → chyba; s volbou accept_risk bez důvodu → chyba; s důvodem → OK + trvalý příznak.
        $err = $this->service->applyAcknowledgements($this->supplierId, $r['checks'], [], $this->subject(), $this->clientId, $this->userId, 'admin');
        self::assertNotNull($err, 'Bez volby nelze uložit.');
        $err = $this->service->applyAcknowledgements($this->supplierId, $r['checks'],
            ['HOTOVOST_NAD_LIMIT' => ['choice' => 'accept_risk', 'note' => 'krátké']],
            $this->subject(), $this->clientId, $this->userId, 'admin');
        self::assertNotNull($err, 'accept_risk vyžaduje důvod ≥ 10 znaků.');
        $err = $this->service->applyAcknowledgements($this->supplierId, $r['checks'],
            ['HOTOVOST_NAD_LIMIT' => ['choice' => 'accept_risk', 'note' => 'klient trval na hotovosti']],
            $this->subject(), $this->clientId, $this->userId, 'admin');
        self::assertNull($err);

        $flag = $this->flags->findOpen($this->supplierId, 'HOTOVOST_NAD_LIMIT', 'invoice', $this->invoiceId);
        self::assertNotNull($flag, 'Příznak trvale svítí.');
        self::assertSame('acknowledged', $flag['status'], 'Odbavení příznak neskrývá, jen mění stav.');
        self::assertSame('accept_risk', $flag['acknowledgement_choice']);
        self::assertSame('high', $flag['severity']);
    }

    public function testStructuringAcrossDaysCreatesFlagAndAmlCase(): void
    {
        // Akceptační scénář: 250 000 (9. 6.) + 253 100 (10. 6.) — každá pod limitem.
        $this->addCashPayment('2024-06-09', 250000.00);
        $r = $this->service->checkCashPayment($this->supplierId, $this->clientId, '2024-06-10', 253100.00, $this->subject());
        self::assertNull($r['block']);
        $types = array_column($r['checks'], 'type');
        self::assertContains('AML_STRUKTUROVANI', $types, 'Součet 503 100 Kč ve 2 dnech = strukturování.');

        // „Nejde o podezřelý obchod" chce ≥ 50 znaků zdůvodnění.
        $err = $this->service->applyAcknowledgements($this->supplierId, $r['checks'],
            ['AML_STRUKTUROVANI' => ['choice' => 'not_suspicious', 'note' => 'v pořádku']],
            $this->subject(), $this->clientId, $this->userId, 'admin');
        self::assertNotNull($err);

        // Předání AML kontaktní osobě → příznak + OTEVŘENÝ AmlCase.
        $err = $this->service->applyAcknowledgements($this->supplierId, $r['checks'],
            ['AML_STRUKTUROVANI' => ['choice' => 'escalate']],
            $this->subject(), $this->clientId, $this->userId, 'admin');
        self::assertNull($err);

        $flag = $this->flags->findOpen($this->supplierId, 'AML_STRUKTUROVANI', 'invoice', $this->invoiceId);
        self::assertNotNull($flag);
        self::assertSame('§ 6 odst. 1 písm. b) zák. č. 253/2008 Sb.', $flag['legal_reference']);

        $case = $this->db->pdo()->prepare('SELECT * FROM aml_cases WHERE counterparty_id = ? ORDER BY id DESC LIMIT 1');
        $case->execute([$this->clientId]);
        $c = $case->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($c, 'Escalate založí AML případ.');
        self::assertSame('open', $c['status']);
        self::assertEqualsWithDelta(503100.00, (float) $c['total_cash_amount'], 0.01);
    }

    public function testHistoricLimitPerDecisiveDate(): void
    {
        // Dokument 9 (K2): 3. 5. 2013 platil limit 350 000 — platba 300 000 je POD limitem.
        $r = $this->service->checkCashPayment($this->supplierId, $this->clientId, '2013-05-03', 300000.00, $this->subject());
        self::assertNull($r['block']);
        self::assertSame([], $r['checks'], 'Rok 2013: limit 350 000 Kč — žádný příznak.');

        // Táž platba v roce 2015 je NAD limitem 270 000 (zpětný záznam → volba).
        $r = $this->service->checkCashPayment($this->supplierId, $this->clientId, '2015-05-03', 300000.00, $this->subject());
        self::assertSame('HOTOVOST_NAD_LIMIT', $r['checks'][0]['type'] ?? null);
    }
}
