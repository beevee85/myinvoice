<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseInvoiceWriteService;
use MyInvoice\Tests\Support\PurchaseInvoiceCharacterizationCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * FORK (beevee85) — pravidlo V75: zápis přijaté faktury je atomický.
 *
 * Účetní doklad je buď celý, nebo žádný. Dřív jel každý krok v autocommitu, takže pád
 * uprostřed nechal v databázi hlavičku s nulovými součty a osiřelé položky bez přepočtu
 * (zafixováno v `Characterization/PartialWriteCharacterizationTest` na úrovni repozitáře).
 * `PurchaseInvoiceWriteService` to nově drží v jedné transakci.
 *
 * Chybu injektujeme do KAŽDÉHO kroku zvlášť — dvojník repozitáře i kalkulátoru deleguje
 * na skutečnou implementaci a shodí jen ten krok, o který v daném běhu jde. Jinak by se
 * testovala jen jedna větev a rollback zbylých kroků by nikdo neověřil.
 */
#[Group('integration')]
final class PurchaseInvoiceWriteServiceTransactionTest extends PurchaseInvoiceCharacterizationCase
{
    /**
     * Služba, která spadne na zvoleném kroku. `null` = nespadne nikde.
     *
     * @param 'createDraft'|'replaceItems'|'setVatOverrides'|'recompute'|null $failAt
     */
    private function serviceFailingAt(?string $failAt): PurchaseInvoiceWriteService
    {
        $realRepo = $this->container->get(PurchaseInvoiceRepository::class);
        $realCalc = $this->container->get(PurchaseInvoiceCalculator::class);

        $boom = static function (string $step) use ($failAt): void {
            if ($failAt === $step) {
                throw new \RuntimeException("injektovaná chyba v kroku {$step}");
            }
        };

        $repo = $this->createStub(PurchaseInvoiceRepository::class);
        $repo->method('createDraft')->willReturnCallback(
            static function (array $data, int $userId, int $supplierId) use ($realRepo, $boom): int {
                $boom('createDraft');
                return $realRepo->createDraft($data, $userId, $supplierId);
            },
        );
        $repo->method('replaceItems')->willReturnCallback(
            static function (int $id, array $items) use ($realRepo, $boom): void {
                $realRepo->replaceItems($id, $items);
                // Až PO zápisu — ať je co vracet zpět.
                $boom('replaceItems');
            },
        );
        $repo->method('setVatOverrides')->willReturnCallback(
            static function (int $id, int $supplierId, ?array $overrides) use ($realRepo, $boom): void {
                $realRepo->setVatOverrides($id, $supplierId, $overrides);
                $boom('setVatOverrides');
            },
        );

        $calc = $this->createStub(PurchaseInvoiceCalculator::class);
        $calc->method('recompute')->willReturnCallback(
            static function (int $id) use ($realCalc, $boom): array {
                $result = $realCalc->recompute($id);
                $boom('recompute');
                return $result;
            },
        );

        return new PurchaseInvoiceWriteService(
            $this->container->get(Connection::class),
            $repo,
            $calc,
            new \Psr\Log\NullLogger(),
        );
    }

    /** @return array<string,mixed> */
    private function payload(string $number): array
    {
        return [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => $number,
            'issue_date'            => self::YEAR . '-08-10',
            'due_date'              => self::YEAR . '-09-10',
            'currency_id'           => $this->currencyId,
            'items' => [
                ['description' => 'Položka', 'quantity' => 1, 'unit' => 'ks',
                 'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->vatRateId('CZ-21'), 'order_index' => 0],
            ],
            'vat_overrides' => [['rate' => 21.0, 'base' => 1000.0, 'vat' => 210.0]],
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function writeSteps(): iterable
    {
        yield 'hlavička'         => ['createDraft'];
        yield 'položky'          => ['replaceItems'];
        yield 'rekapitulace §73' => ['setVatOverrides'];
        yield 'přepočet'         => ['recompute'];
    }

    /** V75: pád v kterémkoli kroku nesmí nechat v databázi vůbec nic. */
    #[DataProvider('writeSteps')]
    public function testFailureInAnyStepLeavesNothingBehind(string $step): void
    {
        $invoicesBefore = $this->countInvoices();
        $itemsBefore    = $this->countItems();

        try {
            $this->serviceFailingAt($step)->createWithItems(
                $this->payload('TX-FAIL-' . strtoupper($step)),
                $this->userId,
                $this->supplierId,
            );
            self::fail("Krok {$step} měl selhat.");
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('injektovaná chyba', $e->getMessage());
        }

        self::assertSame($invoicesBefore, $this->countInvoices(),
            "Po pádu v kroku {$step} zůstala v databázi hlavička.");
        self::assertSame($itemsBefore, $this->countItems(),
            "Po pádu v kroku {$step} zůstaly v databázi položky.");
        self::assertFalse($this->db->pdo()->inTransaction(),
            'Transakce zůstala otevřená — spojení je po pádu v nekonzistentním stavu.');
    }

    /** Úspěšný zápis se naopak commitne a je vidět i mimo transakci. */
    public function testSuccessfulWriteIsCommitted(): void
    {
        $id = $this->trackInvoice(
            $this->serviceFailingAt(null)->createWithItems($this->payload('TX-OK-001'), $this->userId, $this->supplierId)
        );

        self::assertFalse($this->db->pdo()->inTransaction(), 'Transakce zůstala otevřená po úspěchu.');

        $snapshot = $this->snapshot($id);
        self::assertSame('1000.00', $snapshot['header']['total_without_vat']);
        self::assertSame('210.00', $snapshot['header']['total_vat']);
        self::assertCount(1, $snapshot['items']);
    }

    /**
     * RE-ENTRANCE: když transakci drží volající (typicky `PurchaseSettlementService`
     * při párování § 37a), služba si vlastní nezakládá ani necommituje. Kdyby ano,
     * potvrdila by cizí rozdělanou práci.
     */
    public function testNestedCallDoesNotCommitOuterTransaction(): void
    {
        $pdo = $this->db->pdo();
        $invoicesBefore = $this->countInvoices();

        $pdo->beginTransaction();
        $id = $this->serviceFailingAt(null)->createWithItems(
            $this->payload('TX-NESTED-ROLLBACK'), $this->userId, $this->supplierId,
        );

        // Uvnitř transakce doklad existuje…
        self::assertGreaterThan(0, $id);
        self::assertTrue($pdo->inTransaction(), 'Služba ukončila transakci, kterou nezaložila.');
        self::assertSame($invoicesBefore + 1, $this->countInvoices());

        // …ale rozhodnutí patří vlastníkovi transakce.
        $pdo->rollBack();

        self::assertSame($invoicesBefore, $this->countInvoices(),
            'Vnitřní commit potvrdil doklad navzdory rollbacku volajícího.');
    }

    /** A naopak: commit volajícího vnořený zápis potvrdí. */
    public function testNestedCallIsCommittedWithOuterTransaction(): void
    {
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        $id = $this->serviceFailingAt(null)->createWithItems(
            $this->payload('TX-NESTED-COMMIT'), $this->userId, $this->supplierId,
        );
        $pdo->commit();

        $this->trackInvoice($id);
        self::assertNotSame([], $this->snapshot($id)['header'], 'Vnořený zápis se po commitu neprojevil.');
    }

    /**
     * Pád ve vnořeném volání nesmí transakci volajícího zavřít — ten si musí sám
     * rozhodnout, jestli rollbackne všechno, nebo jen část své práce zahodí.
     */
    public function testNestedFailureLeavesOuterTransactionToTheCaller(): void
    {
        $pdo = $this->db->pdo();
        $invoicesBefore = $this->countInvoices();

        $pdo->beginTransaction();
        try {
            $this->serviceFailingAt('replaceItems')->createWithItems(
                $this->payload('TX-NESTED-FAIL'), $this->userId, $this->supplierId,
            );
            self::fail('Očekával jsem injektovanou chybu.');
        } catch (\RuntimeException) {
            self::assertTrue($pdo->inTransaction(),
                'Vnořené volání zavřelo transakci, kterou nezaložilo — volající ztratil kontrolu.');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame($invoicesBefore, $this->countInvoices());
    }

    private function countInvoices(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = {$this->supplierId} AND YEAR(issue_date) = " . self::YEAR
        )->fetchColumn();
    }

    private function countItems(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pi.supplier_id = {$this->supplierId} AND YEAR(pi.issue_date) = " . self::YEAR
        )->fetchColumn();
    }
}
