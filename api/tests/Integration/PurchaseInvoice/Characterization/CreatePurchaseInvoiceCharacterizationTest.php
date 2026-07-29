<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice\Characterization;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Tests\Support\PurchaseInvoiceCharacterizationCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Response as Psr7Response;

/**
 * FORK (beevee85) — CHARAKTERIZACE ruční cesty `POST /api/purchase-invoices`.
 *
 * Zafixuje, co dnes `CreatePurchaseInvoiceAction` zapíše do databáze a v jakém pořadí.
 * Slouží jako důkaz, že vytažení zapisovací sekvence do sdílené služby (a její pozdější
 * obalení transakcí) chování nezměnilo.
 *
 * NENÍ to test správnosti — když se snapshot změní, je to buď regrese, nebo vědomá
 * změna, kterou je nutné popsat v commit message.
 */
#[Group('integration')]
#[Group('characterization')]
final class CreatePurchaseInvoiceCharacterizationTest extends PurchaseInvoiceCharacterizationCase
{
    private CreatePurchaseInvoiceAction $create;

    protected function setUp(): void
    {
        parent::setUp();
        $this->create = $this->container->get(CreatePurchaseInvoiceAction::class);
    }

    /** @param array<string,mixed> $body @return array{0: Psr7Response, 1: array<string,mixed>} */
    private function post(array $body): array
    {
        $response = ($this->create)($this->request('POST', '/api/purchase-invoices', $body), new Psr7Response());
        $payload  = self::json($response);
        if (isset($payload['id'])) {
            $this->trackInvoice((int) $payload['id']);
        }
        return [$response, $payload];
    }

    /** Nejmenší doklad, který projde validací — bez položek. */
    public function testMinimalInvoiceWithoutItems(): void
    {
        [$response, $payload] = $this->post([
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'CHAR-MIN-001',
            'issue_date'            => self::YEAR . '-06-10',
            'due_date'              => self::YEAR . '-06-24',
            'currency_id'           => $this->currencyId,
        ]);

        self::assertSame(201, $response->getStatusCode());
        $snapshot = $this->snapshot((int) $payload['id']);

        self::assertSame([
            'advance_paid_amount'    => '0.00',
            'amount_to_pay'          => '0.00',
            'created_at'             => '<TIMESTAMP>',
            'created_by'             => '<USER_ID>',
            'currency_id'            => '<CURRENCY_ID>',
            'document_kind'          => 'invoice',
            'due_date'               => self::YEAR . '-06-24',
            'exchange_rate_source'   => 'cnb',
            'extraction_blocking'    => 0,
            'id'                     => '<ID>',
            'is_fixed_asset'         => 0,
            'issue_date'             => self::YEAR . '-06-10',
            'language'               => 'cs',
            'prices_include_vat'     => 0,
            // POZOR: datum přijetí padá na datum VYSTAVENÍ, ne na dnešek (na rozdíl
            // od importních cest, které plní date('Y-m-d')).
            'received_at'            => self::YEAR . '-06-10',
            'reverse_charge'         => 0,
            'rounding'               => '0.00',
            'status'                 => 'draft',
            'supplier_id'            => '<SUPPLIER_ID>',
            'tax_deductible'         => 1,
            'total_vat'              => '0.00',
            'total_with_vat'         => '0.00',
            'total_without_vat'      => '0.00',
            'updated_at'             => '<TIMESTAMP>',
            'vat_deduction'          => 'full',
            'vat_deduction_percent'  => '100.00',
            'vendor_id'              => '<VENDOR_ID>',
            'vendor_invoice_number'  => 'CHAR-MIN-001',
            'vendor_is_vat_payer'    => 1,
            'vendor_snapshot'        => ['company_name' => 'Charakterizace dodavatel s.r.o.', 'ic' => '88888886', 'dic' => 'CZ88888886'],
        ], $snapshot['header'], 'Hlavička minimálního dokladu se změnila.');

        self::assertSame([], $snapshot['items']);
    }

    /**
     * Ruční cesta NEPLNÍ `own_snapshot` (snapshot našich údajů k datu pořízení), zatímco
     * ho tabulka má a jiné cesty by ho plnit mohly. Zafixováno záměrně: kdyby ho
     * refaktoring začal plnit, je to změna chování, o které chci vědět.
     */
    public function testOwnSnapshotIsNotWrittenByManualPath(): void
    {
        [, $payload] = $this->post([
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'CHAR-OWNSNAP-001',
            'issue_date'            => self::YEAR . '-06-10',
            'due_date'              => self::YEAR . '-07-10',
            'currency_id'           => $this->currencyId,
        ]);

        $raw = $this->db->pdo()->prepare('SELECT own_snapshot FROM purchase_invoices WHERE id = ?');
        $raw->execute([(int) $payload['id']]);

        self::assertNull($raw->fetchColumn() ?: null, 'own_snapshot se nově plní — ověř dopad na PDF a exporty.');
    }

    /** Dvě sazby DPH — pokrývá replaceItems + recompute + auto-klasifikaci. */
    public function testTwoVatRatesAreRecomputedAndClassified(): void
    {
        [, $payload] = $this->post([
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'CHAR-2SAZBY-001',
            'issue_date'            => self::YEAR . '-06-10',
            'due_date'              => self::YEAR . '-07-10',
            'tax_date'              => self::YEAR . '-06-10',
            'currency_id'           => $this->currencyId,
            'items' => [
                ['description' => 'Zboží 21 %', 'quantity' => 2, 'unit' => 'ks',
                 'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->vatRateId('CZ-21')],
                ['description' => 'Zboží 12 %', 'quantity' => 1, 'unit' => 'ks',
                 'unit_price_without_vat' => 500, 'vat_rate_id' => $this->vatRateId('CZ-12')],
            ],
        ]);

        $snapshot = $this->snapshot((int) $payload['id']);

        // Součty: 2×1000 = 2000 (DPH 420) + 500 (DPH 60) = 2500 základ, 480 DPH, 2980 celkem.
        self::assertSame('2500.00', $snapshot['header']['total_without_vat']);
        self::assertSame('480.00', $snapshot['header']['total_vat']);
        self::assertSame('2980.00', $snapshot['header']['total_with_vat']);
        self::assertSame('40', $snapshot['header']['vat_classification_code'], 'Auto-klasifikace hlavičky se změnila.');

        self::assertSame([
            [
                'description'             => 'Zboží 21 %',
                'is_fixed_asset'          => 0,
                'is_settlement_rounding'  => 0,
                'order_index'             => 0,
                'quantity'                => '2.000',
                'total_vat'               => '420.00',
                'total_with_vat'          => '2420.00',
                'total_without_vat'       => '2000.00',
                'unit'                    => 'ks',
                'unit_price_without_vat'  => '1000.00',
                'vat_classification_code' => '40',
                'vat_rate_id'             => '<VAT_RATE_ID:21>',
                'vat_rate_snapshot'       => '21.00',
            ],
            [
                'description'             => 'Zboží 12 %',
                'is_fixed_asset'          => 0,
                'is_settlement_rounding'  => 0,
                'order_index'             => 1,
                'quantity'                => '1.000',
                'total_vat'               => '60.00',
                'total_with_vat'          => '560.00',
                'total_without_vat'       => '500.00',
                'unit'                    => 'ks',
                'unit_price_without_vat'  => '500.00',
                'vat_classification_code' => '41',
                'vat_rate_id'             => '<VAT_RATE_ID:12>',
                'vat_rate_snapshot'       => '12.00',
            ],
        ], $snapshot['items'], 'Rozpad položek nebo auto-klasifikace se změnily.');
    }

    /**
     * Ruční rekapitulace DPH dle dokladu (§ 73 ZDPH) má přednost před dopočtem z řádků.
     * Klíčová vlastnost: `vat_overrides` se ukládají PŘED `recompute()`, aby je kalkulátor
     * zapekl do řádkových totálů — jinak by se doklad rozešel s papírem o haléře.
     */
    public function testVatOverridesWinOverComputedTotals(): void
    {
        [, $payload] = $this->post([
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'CHAR-37A-001',
            'issue_date'            => self::YEAR . '-06-10',
            'due_date'              => self::YEAR . '-07-10',
            'currency_id'           => $this->currencyId,
            'items' => [
                ['description' => 'Vůz', 'quantity' => 1, 'unit' => 'ks',
                 'unit_price_without_vat' => 372396.69, 'vat_rate_id' => $this->vatRateId('CZ-21')],
            ],
            // Dodavatel počítá DPH shora z brutto: 450 600 / 1,21 = 372 396,69; daň 78 203,31.
            // Dopočet zdola by dal 78 203,30 — o haléř míň. Přednost má doklad.
            'vat_overrides' => [
                ['rate' => 21.0, 'base' => 372396.69, 'vat' => 78203.31],
            ],
        ]);

        $snapshot = $this->snapshot((int) $payload['id']);

        self::assertSame('372396.69', $snapshot['header']['total_without_vat']);
        self::assertSame('78203.31', $snapshot['header']['total_vat'], 'Rekapitulace dokladu (§ 73) se přestala respektovat.');
        self::assertSame('450600.00', $snapshot['header']['total_with_vat']);
        self::assertSame(
            [['rate' => 21, 'base' => 372396.69, 'vat' => 78203.31]],
            $snapshot['header']['vat_overrides'],
        );
        self::assertSame('78203.31', $snapshot['items'][0]['total_vat'], 'Override se nezapekl do řádku.');
    }

    /** Dobropis se založí, ale kladný součet dostane neblokující varování. */
    public function testCreditNoteWithPositiveTotalIsWarnedNotRejected(): void
    {
        [$response, $payload] = $this->post([
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'CHAR-DOBROPIS-001',
            'document_kind'         => 'credit_note',
            'issue_date'            => self::YEAR . '-06-10',
            'due_date'              => self::YEAR . '-07-10',
            'currency_id'           => $this->currencyId,
            'items' => [
                ['description' => 'Vrácení', 'quantity' => 1, 'unit' => 'ks',
                 'unit_price_without_vat' => 100, 'vat_rate_id' => $this->vatRateId('CZ-21')],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['credit_note_positive_total'], $payload['_warnings'] ?? null);
        self::assertSame('credit_note', $this->snapshot((int) $payload['id'])['header']['document_kind']);
    }

    /** Audit: co přesně se o založení zapíše do activity_log. */
    public function testActivityLogEntry(): void
    {
        [, $payload] = $this->post([
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'CHAR-AUDIT-001',
            'issue_date'            => self::YEAR . '-06-10',
            'due_date'              => self::YEAR . '-07-10',
            'currency_id'           => $this->currencyId,
        ]);

        self::assertSame([[
            'action'      => 'purchase_invoice.created',
            'entity_type' => 'purchase_invoice',
            'payload'     => ['document_kind' => 'invoice', 'vendor_id' => '<VENDOR_ID>'],
        ]], $this->activity((int) $payload['id']));

        // Založení NEPŘEDÁVÁ supplier_id do auditu (a `ActivityLogger` si ho pro entitu
        // `purchase_invoice` neumí dohledat), takže sloupec zůstane NULL — na rozdíl od
        // úpravy dokladu, která ho předává explicitně. Zafixováno, aby to refaktoring
        // „neopravil" potichu: je to změna obsahu auditního záznamu.
        $stmt = $this->db->pdo()->prepare(
            "SELECT supplier_id FROM activity_log WHERE entity_type = 'purchase_invoice' AND entity_id = ?"
        );
        $stmt->execute([(int) $payload['id']]);
        self::assertNull($stmt->fetchColumn() ?: null, 'activity_log.supplier_id se nově plní.');
    }

    /** Validace odmítne dřív, než se cokoli zapíše. */
    public function testValidationFailureWritesNothing(): void
    {
        $before = $this->countInvoices();

        [$response, $payload] = $this->post([
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => '',            // povinné
            'issue_date'            => self::YEAR . '-06-10',
            'due_date'              => self::YEAR . '-07-10',
            'currency_id'           => $this->currencyId,
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $payload['error']['code'] ?? null);
        self::assertArrayHasKey('vendor_invoice_number', $payload['error']['fields'] ?? []);
        self::assertSame($before, $this->countInvoices(), 'Neúspěšná validace něco zapsala.');
    }

    /** Cizí dodavatel (jiný tenant) je odmítnut a nic nevznikne. */
    public function testForeignVendorIsRejected(): void
    {
        $pdo = $this->db->pdo();
        $otherSupplier = (int) ($pdo->query(
            "SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($otherSupplier === 0) {
            self::markTestSkipped('Druhý tenant v DB není — cross-tenant test nelze provést.');
        }
        $foreignVendor = (int) ($pdo->query(
            "SELECT id FROM clients WHERE supplier_id = {$otherSupplier} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($foreignVendor === 0) {
            self::markTestSkipped('Druhý tenant nemá klienty.');
        }

        $before = $this->countInvoices();

        [$response, $payload] = $this->post([
            'vendor_id'             => $foreignVendor,
            'vendor_invoice_number' => 'CHAR-CIZI-001',
            'issue_date'            => self::YEAR . '-06-10',
            'due_date'              => self::YEAR . '-07-10',
            'currency_id'           => $this->currencyId,
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('vendor_not_found', $payload['error']['code'] ?? null);
        self::assertSame($before, $this->countInvoices());
    }

    private function countInvoices(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = {$this->supplierId} AND YEAR(issue_date) = " . self::YEAR
        )->fetchColumn();
    }
}
