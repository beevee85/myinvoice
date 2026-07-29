<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankApiCredentialRepository;
use MyInvoice\Service\Bank\Api\FioApiClient;
use MyInvoice\Service\Bank\Api\FioTransactionImporter;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Bank\StatementMatcher;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FORK 0921: import pohybů z Fio API.
 *
 * Hlídá tři věci, na kterých integrace stojí:
 *   - deduplikaci podle ID pohybu (DB unikát na (source, source_ref) záměrně není,
 *     takže opakované stažení téhož období nesmí založit druhou transakci),
 *   - syntetický měsíční výpis (bank_transactions.statement_id je NOT NULL),
 *   - pořadí autority zdrojů: oficiální výpis > Fio API > e-mailové avízo,
 *     jinak by tentýž pohyb zaplatil fakturu dvakrát.
 *
 * Data jsou syntetická; API se nevolá, importér dostane připravené pohyby.
 */
#[Group('integration')]
final class FioTransactionImporterTest extends TestCase
{
    private const VS = '29210001';
    private const MARKER = 'FIO-API-TEST';
    /** Účet existuje jen po dobu testu — izolace od reálných dat. */
    private const TEST_ACCOUNT = '9990000123';

    private Connection $db;
    private FioTransactionImporter $importer;
    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $credentialId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->importer = new FioTransactionImporter(
                $this->db,
                $c->get(FioApiClient::class),
                $c->get(BankApiCredentialRepository::class),
                $c->get(StatementMatcher::class),
                $c->get(EmailNoticeReconciler::class),
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }

        // VLASTNÍ testovací účet — test nesmí sáhnout na reálné účty ani na
        // reálné výpisy „Fio API YYYY-MM" (integrační suita běží proti DB z cfg.php).
        $this->account  = self::TEST_ACCOUNT;
        $this->bankCode = '2010';
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, account_number, bank_code)
             VALUES (?, 'CZK', 'CZK test', 'Kč', 'Testovací účet', 'Test account', ?, ?)"
        )->execute([$this->supplierId, $this->account, $this->bankCode]);
        $this->currencyId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_api_credentials (supplier_id, currency_id, provider, token_enc, enabled)
             VALUES (?, ?, 'fio', 'enc:test', 1)"
        )->execute([$this->supplierId, $this->currencyId]);
        $this->credentialId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    /**
     * Maže VÝHRADNĚ data navázaná na testovací účet — nikdy ne podle názvu výpisu
     * („Fio API %" je produkční pojmenování a smazání výpisu kaskádně bere
     * i spárované transakce a payment_matches).
     */
    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        if ($this->currencyId > 0) {
            $pdo->prepare('DELETE FROM bank_api_credentials WHERE currency_id = ?')->execute([$this->currencyId]);
        }
        // Výpisy testovacího účtu (vč. kaskády na jeho transakce).
        $pdo->prepare('DELETE FROM bank_statements WHERE account_number = ?')->execute([self::TEST_ACCOUNT]);
        if ($this->currencyId > 0) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$this->currencyId]);
            $this->currencyId = 0;
        }
    }

    /** @return array<string,mixed> credential row s tokenem, jak ho čeká importAccount() */
    private function credential(?string $lastFetchedOn = null): array
    {
        return [
            'id'               => $this->credentialId,
            'supplier_id'      => $this->supplierId,
            'currency_id'      => $this->currencyId,
            'token'            => 'test-token',
            'last_fetched_on'  => $lastFetchedOn,
            'last_external_id' => null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function movements(string $externalId = '900001', float $amount = 1210.00): array
    {
        return [[
            'external_id'          => $externalId,
            'posted_at'            => '2026-03-10',
            'amount'               => $amount,
            'currency'             => 'CZK',
            'counterparty_account' => '1000000005',
            'counterparty_bank'    => '0100',
            'counterparty_name'    => 'Testovací odběratel',
            'variable_symbol'      => self::VS,
            'constant_symbol'      => null,
            'specific_symbol'      => null,
            'description'          => 'Test Fio API',
        ]];
    }

    /**
     * Importér s podvrženým klientem — API se v testu nevolá.
     *
     * @param list<array<string,mixed>> $movements
     */
    private function importWith(array $movements, ?array $credential = null): array
    {
        $c = Bootstrap::buildApp()->getContainer();
        $api = new class($movements) extends FioApiClient {
            /** @param list<array<string,mixed>> $movements */
            public function __construct(private readonly array $movements)
            {
                parent::__construct(new \MyInvoice\Infrastructure\Config\Config([]));
            }

            public function fetchPeriod(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return $this->movements;
            }
        };
        $importer = new FioTransactionImporter(
            $this->db,
            $api,
            $c->get(BankApiCredentialRepository::class),
            $c->get(StatementMatcher::class),
            $c->get(EmailNoticeReconciler::class),
        );

        return $importer->importAccount($credential ?? $this->credential(), new \DateTimeImmutable('2026-03-15'));
    }

    private function txCount(): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM bank_transactions WHERE source = 'fio' AND variable_symbol = ?");
        $stmt->execute([self::VS]);

        return (int) $stmt->fetchColumn();
    }

    public function testUlozPohybAZalozSyntetickyVypis(): void
    {
        $res = $this->importWith($this->movements());

        self::assertSame(1, $res['created']);
        self::assertSame(1, $this->txCount());

        $row = $this->db->pdo()->query(
            "SELECT bt.posted_at, bt.amount, bt.currency, bt.bank_ref, bs.source AS stmt_source, bs.file_name
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.source = 'fio' AND bt.variable_symbol = '" . self::VS . "'"
        )->fetch(PDO::FETCH_ASSOC);

        self::assertSame('2026-03-10', $row['posted_at']);
        self::assertSame('1210.00', $row['amount']);
        self::assertSame('CZK', $row['currency'], 'Měna se bere z účtu, ne z pohybu (viz #109).');
        self::assertSame('900001', $row['bank_ref']);
        self::assertSame('fio', $row['stmt_source']);
        self::assertSame('Fio API 2026-03', $row['file_name']);
    }

    public function testOpakovaneStazeniNezaloziDuplicitu(): void
    {
        $this->importWith($this->movements());
        $res = $this->importWith($this->movements());

        self::assertSame(0, $res['created']);
        self::assertSame(1, $res['skipped']);
        self::assertSame(1, $this->txCount(), 'Překryv okna nesmí vyrobit druhou transakci.');
    }

    public function testViceMesicuDostaneVlastniVypis(): void
    {
        $movements = $this->movements();
        $movements[] = ['external_id' => '900002', 'posted_at' => '2026-02-20', 'amount' => 500.0,
            'currency' => 'CZK', 'counterparty_account' => null, 'counterparty_bank' => null,
            'counterparty_name' => null, 'variable_symbol' => self::VS, 'constant_symbol' => null,
            'specific_symbol' => null, 'description' => null];

        $this->importWith($movements);

        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(DISTINCT statement_id) FROM bank_transactions WHERE source = 'fio' AND variable_symbol = ?"
        );
        $stmt->execute([self::VS]);
        self::assertSame(2, (int) $stmt->fetchColumn(), 'Každý měsíc má vlastní syntetický výpis.');
    }

    public function testPohybBezDataNeboSNulovouCastkouSePreskoci(): void
    {
        $movements = [
            ['external_id' => '900003', 'posted_at' => null, 'amount' => 100.0, 'currency' => 'CZK',
             'counterparty_account' => null, 'counterparty_bank' => null, 'counterparty_name' => null,
             'variable_symbol' => self::VS, 'constant_symbol' => null, 'specific_symbol' => null, 'description' => null],
            ['external_id' => '900004', 'posted_at' => '2026-03-10', 'amount' => 0.0, 'currency' => 'CZK',
             'counterparty_account' => null, 'counterparty_bank' => null, 'counterparty_name' => null,
             'variable_symbol' => self::VS, 'constant_symbol' => null, 'specific_symbol' => null, 'description' => null],
        ];

        $res = $this->importWith($movements);
        self::assertSame(0, $res['created']);
        // Nerozpoznaný pohyb se počítá zvlášť („dropped"), ne mezi deduplikované —
        // jinak by tichá ztráta plateb vypadala jako úspěšný běh.
        self::assertSame(2, $res['dropped']);
        self::assertSame(0, $res['skipped']);
        self::assertSame(0, $this->txCount());
    }

    public function testKurzorSeUloziPodleNejnovejsihoPohybu(): void
    {
        $this->importWith($this->movements());

        $row = $this->db->pdo()->query(
            "SELECT last_fetched_on, last_external_id, last_fetch_status FROM bank_api_credentials WHERE id = {$this->credentialId}"
        )->fetch(PDO::FETCH_ASSOC);

        self::assertSame('2026-03-10', $row['last_fetched_on']);
        self::assertSame('900001', $row['last_external_id']);
        self::assertSame('ok', $row['last_fetch_status']);
    }

    public function testPohybUstoupiOficialnimuVypisu(): void
    {
        // Tentýž pohyb už v systému je z GPC výpisu a je spárovaný — Fio API záznam
        // se musí označit jako ignorovaný, jinak by faktura dostala druhou úhradu.
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_statements (source, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES ('gpc', ?, ?, ?, ?, 'CZK', '2026-03-10')"
        )->execute([self::MARKER . '.gpc', hash('sha256', self::MARKER), $this->account, $this->bankCode]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency, variable_symbol, match_status)
             VALUES (?, 'statement', '2026-03-10', 1210.00, 'CZK', ?, 'manual')"
        )->execute([$statementId, self::VS]);
        $gpcTxId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE bank_transactions SET matched_invoice_id = NULL WHERE id = ?')->execute([$gpcTxId]);

        $this->importWith($this->movements());

        $status = $pdo->query(
            "SELECT match_status FROM bank_transactions WHERE source = 'fio' AND variable_symbol = '" . self::VS . "'"
        )->fetchColumn();

        // Bez spárované faktury reconciler ignorovat nemusí; podstatné je, že se
        // Fio pohyb nespároval podruhé na tutéž fakturu.
        self::assertContains((string) $status, ['ignored', 'unmatched'],
            'Fio pohyb nesmí vedle oficiálního výpisu vytvořit druhé párování.');
    }

    public function testAvizoPoFioApiNezaplatiFakturuDvakrat(): void
    {
        // Nejnebezpečnější souběh: cron Fio API stáhne pohyb a o pár minut později
        // dorazí e-mailové avízo TÉHOŽ pohybu. Bez cross-source kontroly by avízo
        // fakturu uhradilo podruhé.
        $this->importWith($this->movements());
        $pdo = $this->db->pdo();

        $fioTx = (int) $pdo->query(
            "SELECT id FROM bank_transactions WHERE source = 'fio' AND variable_symbol = '" . self::VS . "'"
        )->fetchColumn();
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$fioTx]);
        $pdo->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id, created_at)
             SELECT ?, id, '2026-03-10', 1210.00, 'CZK', 'bank', ?, NOW() FROM invoices WHERE supplier_id = ? ORDER BY id LIMIT 1"
        )->execute([$this->supplierId, $fioTx, $this->supplierId]);

        // Avízo téhož pohybu na tomtéž účtu.
        $pdo->prepare(
            "INSERT INTO bank_statements (source, source_ref, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES ('email_notice', ?, ?, ?, ?, ?, 'CZK', '2026-03-10')"
        )->execute(['imap-test:1', self::MARKER . '-avizo', hash('sha256', self::MARKER . '-avizo'), $this->account, $this->bankCode]);
        $stmtId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, source, source_ref, posted_at, amount, currency, variable_symbol)
             VALUES (?, 'email_notice', 'imap-test:1', '2026-03-10', 1210.00, 'CZK', ?)"
        )->execute([$stmtId, self::VS]);
        $avizoTx = (int) $pdo->lastInsertId();

        $reconciler = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Service\Bank\EmailNoticeReconciler::class);
        $twin = $reconciler->ignoreSecondaryWhenAuthoritativeTwinExists($avizoTx);

        self::assertSame($fioTx, $twin, 'Avízo musí poznat, že pohyb už máme z Fio API.');
        $status = $pdo->query("SELECT match_status FROM bank_transactions WHERE id = {$avizoTx}")->fetchColumn();
        self::assertSame('ignored', (string) $status, 'Avízo se označí jako ignorované, nepáruje se znovu.');
    }
}
