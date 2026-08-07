<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Action\Invoice\ForceDeleteInvoiceAction;
use MyInvoice\Action\Invoice\RestoreInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Koš + trvalé mazání dokladů (0905), vydané faktury — spec §10:
 *   - readonly ani účetní neprojdou hard delete (403 forbidden_role),
 *   - DUZP v období s podáním v Archivu podání nejde smazat ani adminem (409),
 *   - smazání POSLEDNÍHO čísla v řadě vrátí counter, prostředního ne (numbering_gap),
 *   - doklad v koši nefiguruje v listu ani v podkladech DPH (VatLedgerService),
 *   - obnovený doklad se vrátí se stejným číslem,
 *   - trash/force zanechají audit záznam; force i snapshot v deleted_document_snapshots.
 *
 * Izolace v roce 2098 (ForceEditUnlockTest používá 2099) pod existujícím supplierem.
 */
#[Group('integration')]
final class DocumentTrashTest extends TestCase
{
    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private DeleteInvoiceAction $delete;
    private RestoreInvoiceAction $restore;
    private ForceDeleteInvoiceAction $force;
    private InvoiceRepository $invoices;
    private VatLedgerService $ledger;
    private \MyInvoice\Service\Invoice\VarsymbolGenerator $varsymbol;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    /** @var int[] */
    private array $created = [];
    /** Dočasně přepsaná číselná šablona supplieru (CI seed ji nemusí mít). */
    private bool $numberFormatOverridden = false;
    private string $originalNumberFormat = '';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->container = $c;
            $this->db       = $c->get(Connection::class);
            $this->delete   = $c->get(DeleteInvoiceAction::class);
            $this->restore  = $c->get(RestoreInvoiceAction::class);
            $this->force    = $c->get(ForceDeleteInvoiceAction::class);
            $this->invoices = $c->get(InvoiceRepository::class);
            $this->ledger   = $c->get(VatLedgerService::class);
            $this->varsymbol = $c->get(\MyInvoice\Service\Invoice\VarsymbolGenerator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/CZK/vat_rate/admin user.');
        }
        if (!$this->hasTrashColumns()) {
            $this->markTestSkipped('Migrace 0905 (deleted_at) není aplikovaná.');
        }
        $this->cleanup();
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($czId === 0) {
            $this->markTestSkipped('Chybí země CZ v číselníku.');
        }
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, dic, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([$this->supplierId, 'Koš test klient s.r.o.', 'CZ11122233', 'Testovací 2', 'Praha', '11000', $czId, 'doc-trash@example.com', 'cs', $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
        // Koš musí být pro testy zapnutý (default z 0905).
        $pdo->prepare('UPDATE supplier SET doc_trash_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function hasTrashColumns(): bool
    {
        return (bool) $this->db->pdo()->query("SHOW COLUMNS FROM invoices LIKE 'deleted_at'")->fetch();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM activity_log WHERE entity_type = ? AND entity_id = ?')->execute(['invoice', $id]);
            $pdo->prepare('DELETE FROM deleted_document_snapshots WHERE entity_type = ? AND entity_id = ?')->execute(['invoice', $id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        if ($this->numberFormatOverridden) {
            $pdo->prepare('UPDATE supplier SET invoice_number_format = ? WHERE id = ?')
                ->execute([$this->originalNumberFormat !== '' ? $this->originalNumberFormat : null, $this->supplierId]);
            $this->numberFormatOverridden = false;
        }
        // Testovací counter období 209806 (rok 2098) — úklid, ať se testy dají opakovat.
        $pdo->prepare("DELETE FROM invoice_counters WHERE supplier_id = ? AND period = '209806'")
            ->execute([$this->supplierId]);
        $pdo->prepare("DELETE FROM tax_submissions WHERE supplier_id = ? AND period_year = 2098")
            ->execute([$this->supplierId]);
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
            $this->clientId = 0;
        }
    }

    private function insertInvoice(string $status, string $varsymbol, string $taxDate = '2098-06-15'): int
    {
        $pdo = $this->db->pdo();
        $snapshot = json_encode(['company_name' => 'Koš test klient s.r.o.', 'ic' => '12345678'], JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_vat, total_with_vat,
                 client_snapshot, supplier_snapshot, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?, 100, 21, 121, ?, ?, ?)"
        )->execute([
            $varsymbol !== '' ? $varsymbol : null, $this->clientId, $this->supplierId,
            $taxDate, $taxDate, $taxDate, $this->currencyId, $status, $snapshot, $snapshot, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->created[] = $id;
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, ?, 100, ?, 21, 100, 21, 121, 0)'
        )->execute([$id, 'Test položka', 'ks', $this->vatRateId]);
        return $id;
    }

    private function request(string $method, string $path, string $role, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private static function json(Psr7Response $resp): array
    {
        $resp->getBody()->rewind();
        return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function trash(int $id, string $role = 'admin', string $reason = 'Testovací smazání dokladu (integční test)'): Psr7Response
    {
        return ($this->delete)(
            $this->request('DELETE', "/api/invoices/{$id}", $role, ['reason' => $reason]),
            new Psr7Response(),
            ['id' => (string) $id],
        );
    }

    private function forceDelete(int $id, string $role, string $confirm, string $reason = 'Testovací trvalé smazání (integrační test)'): Psr7Response
    {
        return ($this->force)(
            $this->request('DELETE', "/api/invoices/{$id}/force", $role, ['reason' => $reason, 'confirm_number' => $confirm]),
            new Psr7Response(),
            ['id' => (string) $id],
        );
    }

    // ------------------------------------------------------------------

    public function testReadonlyAndAccountantCannotForceDelete(): void
    {
        $id = $this->insertInvoice('issued', 'TR2098-403');
        $this->trash($id); // do koše (admin)

        foreach (['readonly', 'accountant'] as $role) {
            $resp = $this->forceDelete($id, $role, 'TR2098-403');
            self::assertSame(403, $resp->getStatusCode(), "Role {$role} nesmí projít hard delete.");
            self::assertSame('forbidden_role', self::json($resp)['error']['code']);
        }
    }

    public function testTrashRequiresReason(): void
    {
        $id = $this->insertInvoice('issued', 'TR2098-422');
        $resp = ($this->delete)(
            $this->request('DELETE', "/api/invoices/{$id}", 'admin', ['reason' => 'krátké']),
            new Psr7Response(),
            ['id' => (string) $id],
        );
        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('reason_required', self::json($resp)['error']['code']);
    }

    public function testVatPeriodSubmissionBlocksDeleteEvenForAdmin(): void
    {
        $id = $this->insertInvoice('issued', 'TR2098-DPH', '2098-06-15');
        // Podání DPH za 6/2098 v archivu → doklad s DUZP v tomto období nejde smazat.
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, xml_content, xml_size_bytes, xml_sha256)
             VALUES (?, 'dphdp3', 2098, 6, '<x/>', 4, ?)"
        )->execute([$this->supplierId, str_repeat('a', 64)]);

        $resp = $this->trash($id);
        self::assertSame(409, $resp->getStatusCode());
        self::assertSame('blocked_vat_period', self::json($resp)['error']['code']);

        // Ani hard delete (admin, override) neprojde — DPH blokace je nepřebitelná.
        $this->db->pdo()->prepare('UPDATE invoices SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
        $resp2 = ($this->force)(
            $this->request('DELETE', "/api/invoices/{$id}/force", 'admin',
                ['reason' => 'Pokus o smazání přes DPH blokaci', 'confirm_number' => 'TR2098-DPH', 'override' => true]),
            new Psr7Response(),
            ['id' => (string) $id],
        );
        self::assertSame(409, $resp2->getStatusCode());
        self::assertSame('blocked_vat_period', self::json($resp2)['error']['code']);
    }

    public function testForceDeleteLastNumberReleasesCounterMiddleDoesNot(): void
    {
        $pdo = $this->db->pdo();
        // Šablona řady s měsíčním counterem. Nemá-li ji supplier nastavenou (CI seed),
        // dočasně ji nastavíme a v tearDown vrátíme — jinak by se klíčové pokrytí
        // číselných řad tiše přeskakovalo.
        // Audit 2026-08-07: šablonu VŽDY přepíšeme na měsíční — jinak by při
        // produkční šabloně bez {MM}/{YY} periodKey spadl na 'ALL' (globální
        // produkční counter) a test by ho ON DUPLICATE přepsal a v cleanupu smazal.
        // Měsíční tvar drží test v izolovaném období 209806.
        $this->originalNumberFormat = (string) ($pdo->query("SELECT invoice_number_format FROM supplier WHERE id = {$this->supplierId}")->fetchColumn() ?: '');
        $this->numberFormatOverridden = true;
        $tpl = 'TR{YY}{MM}{CCC}';
        $pdo->prepare('UPDATE supplier SET invoice_number_format = ? WHERE id = ?')
            ->execute([$tpl, $this->supplierId]);
        $date = new \DateTimeImmutable('2098-06-15');
        $vs1 = $this->varsymbol->render($tpl, $date, 1);
        $vs2 = $this->varsymbol->render($tpl, $date, 2);
        $id1 = $this->insertInvoice('issued', $vs1);
        $id2 = $this->insertInvoice('issued', $vs2);
        // Counter řady na 2 (poslední použité číslo). Vždy měsíční → 209806.
        $periodKey = '209806';
        $pdo->prepare(
            'INSERT INTO invoice_counters (supplier_id, client_id, invoice_type, period, last_number)
             VALUES (?, 0, \'invoice\', ?, 2)
             ON DUPLICATE KEY UPDATE last_number = 2'
        )->execute([$this->supplierId, $periodKey]);

        // Smazání PROSTŘEDNÍHO čísla (vs1, counter=1 ≠ last 2) → mezera, counter drží.
        $this->trash($id1);
        $resp1 = $this->forceDelete($id1, 'admin', $vs1);
        self::assertSame(200, $resp1->getStatusCode(), (string) $resp1->getBody());
        $body1 = self::json($resp1);
        self::assertFalse($body1['counter_released']);
        self::assertSame($vs1, $body1['numbering_gap'], 'Mezera v řadě se hlásí do audit logu.');
        $last = (int) $pdo->query(
            "SELECT last_number FROM invoice_counters WHERE supplier_id = {$this->supplierId} AND client_id = 0 AND invoice_type = 'invoice' AND period = '{$periodKey}'"
        )->fetchColumn();
        self::assertSame(2, $last, 'Counter se u prostředního čísla NEsnižuje.');

        // Smazání POSLEDNÍHO čísla (vs2 = counter 2) → counter klesne na 1.
        $this->trash($id2);
        $resp2 = $this->forceDelete($id2, 'admin', $vs2);
        self::assertSame(200, $resp2->getStatusCode(), (string) $resp2->getBody());
        $body2 = self::json($resp2);
        self::assertTrue($body2['counter_released'], 'Poslední číslo v řadě musí counter uvolnit.');
        self::assertNull($body2['numbering_gap']);
        $last2 = (int) $pdo->query(
            "SELECT last_number FROM invoice_counters WHERE supplier_id = {$this->supplierId} AND client_id = 0 AND invoice_type = 'invoice' AND period = '{$periodKey}'"
        )->fetchColumn();
        self::assertSame(1, $last2);
    }

    public function testTrashedInvoiceExcludedFromListAndVatLedger(): void
    {
        $id = $this->insertInvoice('issued', 'TR2098-VAT', '2098-06-15');

        $inList = fn (): bool => (bool) array_filter(
            array_merge(...array_map(
                static fn (array $g): array => $g['invoices'],
                $this->invoices->listGroupedByMonth(['supplier_id' => $this->supplierId, 'year' => 2098])['data'],
            )),
            static fn (array $i): bool => (int) $i['id'] === $id,
        );
        $inLedger = fn (): bool => (bool) array_filter(
            $this->ledger->rows($this->supplierId, '2098-06-01', '2098-06-30'),
            static fn (array $r): bool => (int) ($r['invoice_id'] ?? 0) === $id && ($r['source'] ?? '') !== 'purchase',
        );

        self::assertTrue($inList(), 'Aktivní doklad musí být v listu.');
        self::assertTrue($inLedger(), 'Aktivní doklad musí být v podkladech DPH.');

        $resp = $this->trash($id);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());

        self::assertFalse($inList(), 'Doklad v koši nesmí být v listu.');
        self::assertFalse($inLedger(), 'Doklad v koši nesmí vstupovat do Knihy DPH / KH / přiznání.');

        // Koš ho naopak ukazuje.
        $trashRows = array_merge(...array_map(
            static fn (array $g): array => $g['invoices'],
            $this->invoices->listGroupedByMonth(['supplier_id' => $this->supplierId, 'year' => 2098, 'trash' => true])['data'],
        ));
        self::assertNotEmpty(array_filter($trashRows, static fn (array $i): bool => (int) $i['id'] === $id));
    }

    public function testRestoreReturnsSameNumberAndAudits(): void
    {
        $id = $this->insertInvoice('issued', 'TR2098-RST');
        $this->trash($id);

        $resp = ($this->restore)(
            $this->request('POST', "/api/invoices/{$id}/restore", 'accountant'),
            new Psr7Response(),
            ['id' => (string) $id],
        );
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());

        $row = $this->db->pdo()->query("SELECT varsymbol, deleted_at, delete_reason FROM invoices WHERE id = {$id}")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame('TR2098-RST', $row['varsymbol'], 'Obnovený doklad má stejné číslo.');
        self::assertNull($row['deleted_at']);
        self::assertNull($row['delete_reason']);

        foreach (['invoice.trashed', 'invoice.restored'] as $action) {
            $n = (int) $this->db->pdo()->query(
                "SELECT COUNT(*) FROM activity_log WHERE action = '{$action}' AND entity_type = 'invoice' AND entity_id = {$id}"
            )->fetchColumn();
            self::assertSame(1, $n, "Audit {$action} musí existovat.");
        }
    }

    public function testForceDeleteStoresSnapshotAndConfirmNumberGuard(): void
    {
        $id = $this->insertInvoice('issued', 'TR2098-SNAP');
        $this->trash($id);

        // Špatně opsané číslo → 422, nic se nemaže.
        $bad = $this->forceDelete($id, 'admin', 'SPATNE-CISLO');
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame('confirm_number_mismatch', self::json($bad)['error']['code']);

        $ok = $this->forceDelete($id, 'admin', 'TR2098-SNAP');
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $snapshotId = (int) self::json($ok)['snapshot_id'];
        self::assertGreaterThan(0, $snapshotId);

        $snap = $this->db->pdo()->query(
            "SELECT payload, reason FROM deleted_document_snapshots WHERE id = {$snapshotId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($snap, 'Hard delete musí uložit snapshot.');
        $payload = json_decode((string) $snap['payload'], true);
        self::assertSame('TR2098-SNAP', $payload['header']['varsymbol'] ?? null);
        self::assertNotEmpty($payload['items'], 'Snapshot nese i položky dokladu.');

        self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM invoices WHERE id = {$id}")->fetchColumn());

        $log = $this->db->pdo()->query(
            "SELECT payload FROM activity_log WHERE action = 'invoice.force_deleted' AND entity_id = {$id} ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertNotFalse($log, 'Audit invoice.force_deleted musí existovat.');
        $logPayload = json_decode((string) $log, true);
        self::assertSame($snapshotId, (int) ($logPayload['snapshot_id'] ?? 0));
    }

    /**
     * Audit 2026-08-07: výkaz práce jde kaskádou (fk_wr_invoice ON DELETE
     * CASCADE) — snapshot ho MUSÍ zachytit, jinak se nenávratně ztratí podklad
     * hodinové fakturace. Dřív ve snapshotu nebyl.
     */
    public function testForceDeleteSnapshotsWorkReport(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->insertInvoice('issued', 'TR2098-WR');
        $this->trash($id);

        // Projekt (FK) + výkaz práce + jeho položka.
        $pdo->prepare(
            "INSERT INTO projects (client_id, name, currency_id, status)
             VALUES (?, 'Koš test projekt', ?, 'active')"
        )->execute([$this->clientId, $this->currencyId]);
        $projectId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO work_reports (invoice_id, project_id, title, total_hours, total_amount)
             VALUES (?, ?, 'Výkaz práce červen', 8.00, 8000.00)"
        )->execute([$id, $projectId]);
        $wrId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO work_report_items (work_report_id, description, work_date, hours, rate, total_amount, order_index)
             VALUES (?, 'Analýza', '2098-06-10', 8.00, 1000.00, 8000.00, 0)"
        )->execute([$wrId]);

        try {
            $ok = $this->forceDelete($id, 'admin', 'TR2098-WR');
            self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
            $snapshotId = (int) self::json($ok)['snapshot_id'];

            $payload = json_decode((string) $pdo->query(
                "SELECT payload FROM deleted_document_snapshots WHERE id = {$snapshotId}"
            )->fetchColumn(), true);

            self::assertNotEmpty($payload['work_reports'] ?? [],
                'Snapshot musí nést výkaz práce (kaskádou by jinak zmizel bez otisku).');
            self::assertSame('Výkaz práce červen', $payload['work_reports'][0]['title'] ?? null);
            self::assertNotEmpty($payload['work_report_items'] ?? [],
                'Snapshot musí nést i položky výkazu (hodiny, sazby).');
            self::assertEqualsWithDelta(8.0, (float) ($payload['work_report_items'][0]['hours'] ?? 0), 0.005);
        } finally {
            // Projekt uklidit (invoice + work_report smazal force delete kaskádou).
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$projectId]);
        }
    }

    public function testForceDeleteRequiresTrashFirstWhenTrashEnabled(): void
    {
        $id = $this->insertInvoice('issued', 'TR2098-NIT');
        $resp = $this->forceDelete($id, 'admin', 'TR2098-NIT');
        self::assertSame(409, $resp->getStatusCode());
        self::assertSame('not_in_trash', self::json($resp)['error']['code']);
    }

    // ------------------------------------------------------------------
    // Audit 2026-08-07: doklad v koši = mimo evidenci i MIMO HTTP vrstvu.
    // TrashGuard kryje jen akce; tyhle testy tvrdí sémantiku v repozitářích
    // a službách, kudy vedou cron a veřejné endpointy.
    // ------------------------------------------------------------------

    /**
     * Recurring cron NESMÍ najít trashed koncept jako fakturu období — jinak
     * ho vystavil, odeslal klientovi, a doklad s deleted_at unikl DPH evidenci
     * i párování plateb (nález auditu, HIGH).
     */
    public function testTrashedDraftIsInvisibleToRecurringPeriodLookup(): void
    {
        $pdo = $this->db->pdo();
        // FK vyžaduje skutečnou šablonu — minimální řádek, úklid v tearDown přes DELETE níže.
        $pdo->prepare(
            "INSERT INTO recurring_invoice_templates
                (supplier_id, client_id, name, frequency, anchor_date, next_run_date, currency_id, created_by)
             VALUES (?, ?, 'Koš test šablona', 'monthly', '2098-06-01', '2098-07-01', ?, ?)"
        )->execute([$this->supplierId, $this->clientId, $this->currencyId, $this->userId]);
        $tplId = (int) $pdo->lastInsertId();

        $id = $this->insertInvoice('draft', '');
        $pdo->prepare('UPDATE invoices SET recurring_template_id = ?, issue_date = ? WHERE id = ?')
            ->execute([$tplId, '2098-06-15', $id]);

        $repo = $this->container->get(\MyInvoice\Repository\RecurringTemplateRepository::class);
        self::assertNotNull($repo->findPeriodInvoice($tplId, '2098-06-15'),
            'predpoklad: nesmazany koncept se najde');

        $pdo->prepare('UPDATE invoices SET deleted_at = current_timestamp() WHERE id = ?')->execute([$id]);

        self::assertNull($repo->findPeriodInvoice($tplId, '2098-06-15'),
            'koncept v koši nesmí být fakturou období');

        // Úklid šablony hned tady — invoices řádek maže tearDown, FK je SET NULL.
        $pdo->prepare('UPDATE invoices SET recurring_template_id = NULL WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM recurring_invoice_templates WHERE id = ?')->execute([$tplId]);
    }

    /** Vystavovací služba doklad v koši odmítne — i když ji zavolá cesta bez TrashGuardu. */
    public function testAutoIssueRefusesTrashedInvoice(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->insertInvoice('draft', '');
        $pdo->prepare('UPDATE invoices SET deleted_at = current_timestamp() WHERE id = ?')->execute([$id]);

        $svc = $this->container->get(\MyInvoice\Service\Invoice\AutoIssueAndSendService::class);

        $this->expectException(\DomainException::class);
        $svc->run($id, $this->userId, '127.0.0.1', 'phpunit');
    }

    /** Veřejný odkaz (web faktura) se dokladu v koši chová, jako by neexistoval. */
    public function testPublicTokenDoesNotServeTrashedInvoice(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->insertInvoice('issued', 'TR2098-PUB');
        $token = bin2hex(random_bytes(24));
        $pdo->prepare('UPDATE invoices SET public_token = ? WHERE id = ?')->execute([$token, $id]);

        $repo = $this->container->get(\MyInvoice\Repository\InvoiceRepository::class);
        self::assertNotNull($repo->publicInvoiceRefByToken($token), 'predpoklad: bez koše se najde');

        $pdo->prepare('UPDATE invoices SET deleted_at = current_timestamp() WHERE id = ?')->execute([$id]);

        self::assertNull($repo->publicInvoiceRefByToken($token),
            'doklad v koši nesmí být veřejně dostupný');
    }

    /** Na doklad v koši se nesmí navázat záloha — protistrana jde z těla požadavku. */
    public function testLinkAdvanceRefusesTrashedCounterpart(): void
    {
        $pdo = $this->db->pdo();
        $finalId = $this->insertInvoice('draft', '');
        $advId   = $this->insertInvoice('issued', 'TR2098-ADV');
        $pdo->prepare("UPDATE invoices SET invoice_type = 'proforma', deleted_at = current_timestamp() WHERE id = ?")
            ->execute([$advId]);

        $repo = $this->container->get(\MyInvoice\Repository\InvoiceRepository::class);

        try {
            $repo->linkAdvance($finalId, $advId, $this->supplierId);
            self::fail('vazba na proformu v koši musí být odmítnuta');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('koši', $e->getMessage());
        }
    }
}
