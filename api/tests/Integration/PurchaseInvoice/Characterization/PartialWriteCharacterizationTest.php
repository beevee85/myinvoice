<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice\Characterization;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Tests\Support\PurchaseInvoiceCharacterizationCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Response as Psr7Response;

/**
 * FORK (beevee85) — CHARAKTERIZACE chování při CHYBĚ UPROSTŘED zápisu.
 *
 * Dnes není zakládání přijaté faktury v žádné transakci: `createDraft`, `replaceItems`,
 * `setVatOverrides` i `recompute` jedou v autocommitu, každý `execute()` commituje sám.
 * Pád uprostřed proto nechá v databázi trvale rozpracovaný doklad.
 *
 * ⚠️ TENHLE SOUBOR VĚDOMĚ FIXUJE VADU, NE ŽÁDOUCÍ STAV. Commit „transakce" (pravidlo V75)
 * ho ZÁMĚRNĚ změní: po obalení transakcí nesmí zůstat nic. Až tyhle testy spadnou při
 * zavádění transakcí, je to očekávané a jejich úprava je součástí toho commitu — na rozdíl
 * od ostatních charakterizačních testů, které musí zůstat zelené beze změny.
 */
#[Group('integration')]
#[Group('characterization')]
#[Group('pre-transaction')]
final class PartialWriteCharacterizationTest extends PurchaseInvoiceCharacterizationCase
{
    private PurchaseInvoiceRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->container->get(PurchaseInvoiceRepository::class);
    }

    /** Id sazby, které v číselníku neexistuje — spolehlivě shodí FK fk_pii_vat. */
    private function missingVatRateId(): int
    {
        return (int) $this->db->pdo()->query('SELECT MAX(id) + 1000 FROM vat_rates')->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function payload(string $number): array
    {
        return [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => $number,
            'issue_date'            => self::YEAR . '-06-10',
            'due_date'              => self::YEAR . '-07-10',
            'received_at'           => self::YEAR . '-06-10',
            'currency_id'           => $this->currencyId,
        ];
    }

    /**
     * Selhání na DRUHÉ položce nechá v DB hlavičku i první položku. Přesně tohle
     * má transakce v pozdějším commitu odstranit.
     */
    public function testFkFailureOnSecondItemLeavesPartialDocument(): void
    {
        $id = $this->trackInvoice($this->repo->createDraft($this->payload('CHAR-PARTIAL-001'), $this->userId, $this->supplierId));

        $items = [
            ['description' => 'Projde', 'quantity' => 1, 'unit' => 'ks',
             'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->vatRateId('CZ-21'), 'order_index' => 0],
            ['description' => 'Spadne na FK', 'quantity' => 1, 'unit' => 'ks',
             'unit_price_without_vat' => 500, 'vat_rate_id' => $this->missingVatRateId(), 'order_index' => 1],
        ];

        $thrown = null;
        try {
            $this->repo->replaceItems($id, $items);
        } catch (\PDOException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'Neexistující vat_rate_id už nespadne — FK fk_pii_vat zmizel?');
        self::assertSame('23000', $thrown->getCode(), 'Změnil se typ chyby při porušení FK.');

        $snapshot = $this->snapshot($id);

        // DNEŠNÍ STAV: hlavička zůstala…
        self::assertNotSame([], $snapshot['header'], 'Hlavička po pádu zmizela — zavedena transakce?');
        self::assertSame('draft', $snapshot['header']['status']);
        // …součty jsou nulové, protože recompute() se vůbec nespustil…
        self::assertSame('0.00', $snapshot['header']['total_without_vat']);
        self::assertSame('0.00', $snapshot['header']['total_vat']);
        self::assertSame('0.00', $snapshot['header']['total_with_vat']);
        // …a v položkách zůstal osiřelý první řádek s nedopočítanými totály.
        self::assertCount(1, $snapshot['items'], 'Počet přeživších položek se změnil.');
        self::assertSame('Projde', $snapshot['items'][0]['description']);
        self::assertSame('0.00', $snapshot['items'][0]['total_without_vat'], 'Řádkové totály už nejsou nulové.');

        // Audit se nezapsal — loguje se až za recompute.
        self::assertSame([], $this->activity($id));
    }

    /**
     * Opakované `replaceItems` po neúspěchu smaže i osiřelý řádek — doklad se dá
     * zachránit opravou dat. (DELETE běží na začátku replaceItems.)
     */
    public function testRetryAfterFailureCleansOrphanedItems(): void
    {
        $id = $this->trackInvoice($this->repo->createDraft($this->payload('CHAR-PARTIAL-002'), $this->userId, $this->supplierId));

        try {
            $this->repo->replaceItems($id, [
                ['description' => 'Projde', 'quantity' => 1, 'unit' => 'ks',
                 'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->vatRateId('CZ-21'), 'order_index' => 0],
                ['description' => 'Spadne', 'quantity' => 1, 'unit' => 'ks',
                 'unit_price_without_vat' => 500, 'vat_rate_id' => $this->missingVatRateId(), 'order_index' => 1],
            ]);
            self::fail('Očekával jsem PDOException.');
        } catch (\PDOException) {
            // očekáváno
        }

        $this->repo->replaceItems($id, [
            ['description' => 'Oprava', 'quantity' => 1, 'unit' => 'ks',
             'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->vatRateId('CZ-21'), 'order_index' => 0],
        ]);
        $this->container->get(\MyInvoice\Service\Invoice\PurchaseInvoiceCalculator::class)->recompute($id);

        $snapshot = $this->snapshot($id);
        self::assertCount(1, $snapshot['items']);
        self::assertSame('Oprava', $snapshot['items'][0]['description']);
        self::assertSame('1000.00', $snapshot['header']['total_without_vat']);
        self::assertSame('1210.00', $snapshot['header']['total_with_vat']);
    }

    /**
     * Překlopení `clients.is_vendor` na 1 se děje PŘED insertem hlavičky, takže
     * po neúspěšném založení zůstane — dnešní „leak" mimo jakoukoli transakci.
     */
    public function testVendorFlagFlipSurvivesFailedHeaderInsert(): void
    {
        $pdo = $this->db->pdo();

        // Čerstvý klient, který zatím dodavatelem NENÍ.
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor, is_vat_payer)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, 1)'
        )->execute([
            $this->supplierId, 'Charakterizace odběratel s.r.o.', '77777775', 'Charakterizační 2',
            'Praha', '11000', $this->countryId, 'characterization-customer@example.invalid', 'cs', $this->currencyId,
        ]);
        $customerId = (int) $pdo->lastInsertId();

        try {
            // Obsadíme varsymbol, aby druhé založení spadlo na uq_pi_supplier_varsymbol.
            $blocking = $this->trackInvoice($this->repo->createDraft(
                $this->payload('CHAR-VS-BLOCK') + ['varsymbol' => 'CHARVS0001'],
                $this->userId,
                $this->supplierId,
            ));
            self::assertGreaterThan(0, $blocking);

            $create   = $this->container->get(CreatePurchaseInvoiceAction::class);
            $response = ($create)(
                $this->request('POST', '/api/purchase-invoices', [
                    'vendor_id'             => $customerId,
                    'vendor_invoice_number' => 'CHAR-VS-DUP',
                    'issue_date'            => self::YEAR . '-06-10',
                    'due_date'              => self::YEAR . '-07-10',
                    'currency_id'           => $this->currencyId,
                    'varsymbol'             => 'CHARVS0001',
                ]),
                new Psr7Response(),
            );

            self::assertSame(409, $response->getStatusCode(), 'Duplicitní varsymbol už nevrací 409.');
            self::assertSame('varsymbol_duplicate', self::json($response)['error']['code'] ?? null);

            $stmt = $pdo->prepare('SELECT is_vendor FROM clients WHERE id = ?');
            $stmt->execute([$customerId]);
            self::assertSame(1, (int) $stmt->fetchColumn(),
                'is_vendor se po neúspěšném založení už nepřeklápí — zavedena transakce?');
        } finally {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$customerId]);
        }
    }
}
