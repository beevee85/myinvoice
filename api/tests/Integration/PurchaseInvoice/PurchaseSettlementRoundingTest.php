<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseSettlementService;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 37a — zdanitelné řádky konečné faktury musejí zůstat DLE DOKLADU dodavatele
 * (§ 73 / § 100 ZDPH) i po napárování více daňových dokladů k přijaté záloze;
 * haléřový rozdíl nese výhradně řádek „Zaokrouhlení § 37a".
 *
 * Regrese: pinSettlementRowTotals() dřív rozdíl kompenzoval na NEJSILNĚJŠÍM zdanitelném
 * řádku sazby, čímž zrušil efekt restoreItemTotals(). Projevilo se to, když se
 * dopočtená hodnota odpočtového řádku lišila od hodnoty na DDKPZ (typicky když
 * dodavatel počítá daň SHORA z brutto, kdežto kalkulátor ZDOLA ze základu):
 * rekapitulace faktury se pak rozešla s dokladem o 0,01 Kč a při opakovaném
 * unlink/link se haléře KUMULOVALY.
 *
 * Scénář (reálný případ Autosalon Gama a.s. / nákup vozu, 2026):
 *   faktura   111 000,00 = základ 91 735,54 + DPH 19 264,46
 *   DDKPZ #1   50 000,00 = základ  8 264,46 + DPH  1 735,54
 *   DDKPZ #2  101 000,00 = základ 83 471,07 + DPH 17 528,93  (shora, zdola by vyšlo 17 528,92)
 *   → rozdíl § 37a: základ +0,01 / daň −0,01, HRUBÝ rozdíl 0,00
 *
 * Izolováno v roce 2097 pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip, pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class PurchaseSettlementRoundingTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private PurchaseInvoiceCalculator $calc;
    private PurchaseSettlementService $settlement;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRate21 = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $piIds = [];
    /** @var int[] */
    private array $vendorIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db         = $c->get(Connection::class);
            $this->repo       = $c->get(PurchaseInvoiceRepository::class);
            $this->calc       = $c->get(PurchaseInvoiceCalculator::class);
            $this->settlement = $c->get(PurchaseSettlementService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRate21  = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code='CZ-21' LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRate21 === 0
            || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        // nejdřív uvolnit vazby § 37a, ať nevadí FK
        foreach ($this->piIds as $id) {
            $pdo->prepare('UPDATE purchase_invoices SET settled_by_purchase_invoice_id = NULL,
                                  advance_purchase_invoice_id = NULL WHERE id = ?')->execute([$id]);
        }
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testTaxableRowsStayPerDocumentAfterLinkingTwoTaxDocuments(): void
    {
        [$final, $doc1, $doc2] = $this->scenario('CZ20970001');

        // výchozí stav = doklad dodavatele
        $s = $this->sums($final);
        self::assertEqualsWithDelta(91735.54, $s['taxable_base'], 0.005);
        self::assertEqualsWithDelta(19264.46, $s['taxable_vat'], 0.005);

        $this->settlement->link($final, $doc1, $this->supplierId, true);
        $this->settlement->link($final, $doc2, $this->supplierId, true);

        $s = $this->sums($final);

        self::assertEqualsWithDelta(91735.54, $s['taxable_base'], 0.005,
            'Základ zdanitelných řádků musí zůstat dle dokladu dodavatele (§ 73).');
        self::assertEqualsWithDelta(19264.46, $s['taxable_vat'], 0.005,
            'DPH zdanitelných řádků musí zůstat dle dokladu dodavatele — haléř nesmí skončit na nich.');

        self::assertEqualsWithDelta(-91735.53, $s['deduction_base'], 0.005,
            'Odpočtové řádky doslova dle DDKPZ.');
        self::assertEqualsWithDelta(-19264.47, $s['deduction_vat'], 0.005,
            'Odpočtové řádky doslova dle DDKPZ.');

        self::assertEqualsWithDelta(-0.01, $s['rounding_base'], 0.005,
            'Haléřový rozdíl § 37a patří na zaokrouhlovací řádek.');
        self::assertEqualsWithDelta(0.01, $s['rounding_vat'], 0.005,
            'Haléřový rozdíl § 37a patří na zaokrouhlovací řádek.');

        self::assertEqualsWithDelta(0.0, $s['total_base'], 0.005,
            'Hrubý rozdíl § 37a je nulový → doklad musí vyjít na nulu.');
        self::assertEqualsWithDelta(0.0, $s['total_vat'], 0.005);

        $header = $this->repo->find($final, $this->supplierId);
        self::assertEqualsWithDelta(0.0, (float) $header['total_with_vat'], 0.005);
        self::assertEqualsWithDelta(0.0, (float) $header['amount_to_pay'], 0.005);
    }

    /**
     * R5 (Dokument 3, 8/2026): zaokrouhlovací řádek § 37a nesmí do přiznání/KH
     * přinést žádnou dodatečnou daň. Invariant: DPH konečné faktury v Knize DPH
     * + Σ DPH ze všech DDKPZ = daň rozhodná dle § 37a (u nulového hrubého rozdílu
     * přesně Σ daní přiznaných ze záloh), bez haléřové odchylky. Čistý vliv
     * konečné faktury na ledger (základ i daň) musí být přesně nula.
     */
    public function testLedgerNetVatMatchesAdvanceTaxExactly(): void
    {
        [$final, $doc1, $doc2] = $this->scenario('CZ20970003');

        $this->settlement->link($final, $doc1, $this->supplierId, true);
        $this->settlement->link($final, $doc2, $this->supplierId, true);

        // Ledger bere jen doklady mimo koncept.
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status='received' WHERE id=?")
            ->execute([$final]);

        $ledger = Bootstrap::buildApp()->getContainer()->get(VatLedgerService::class);
        $rows = $ledger->rows($this->supplierId, self::YEAR . '-06-01', self::YEAR . '-06-30');

        $sum = static function (array $rows, int $invoiceId): array {
            $base = 0.0; $vat = 0.0; $found = false;
            foreach ($rows as $r) {
                if (($r['source'] ?? '') === 'purchase' && (int) ($r['invoice_id'] ?? 0) === $invoiceId) {
                    $found = true;
                    $base += (float) ($r['base_czk'] ?? 0);
                    $vat  += (float) ($r['vat_czk'] ?? 0);
                }
            }
            self::assertTrue($found, "Doklad #{$invoiceId} musí mít v Knize DPH aspoň jeden řádek.");
            return [round($base, 2), round($vat, 2)];
        };

        [$finalBase, $finalVat] = $sum($rows, $final);
        [, $doc1Vat] = $sum($rows, $doc1);
        [, $doc2Vat] = $sum($rows, $doc2);

        self::assertEqualsWithDelta(0.0, $finalBase, 0.001,
            'Čistý vliv konečné faktury (vč. zaokrouhlovacího řádku § 37a) na základ v Knize DPH musí být přesně nula.');
        self::assertEqualsWithDelta(0.0, $finalVat, 0.001,
            'Čistý vliv konečné faktury na daň v Knize DPH musí být přesně nula — zaokrouhlovací řádek nesmí generovat daň.');

        self::assertEqualsWithDelta(1735.54, $doc1Vat, 0.001, 'DDKPZ #1 dle dokladu.');
        self::assertEqualsWithDelta(17528.93, $doc2Vat, 0.001, 'DDKPZ #2 dle dokladu (shora).');

        // Invariant § 37a: celkový uplatněný odpočet případu = přesně Σ daní ze záloh.
        self::assertEqualsWithDelta(19264.47, round($finalVat + $doc1Vat + $doc2Vat, 2), 0.001,
            'DPH konečné faktury + Σ DPH DDKPZ musí přesně odpovídat dani přiznané ze záloh (§ 37a), bez haléřové odchylky.');
    }

    public function testRepeatedUnlinkAndLinkDoesNotAccumulateRounding(): void
    {
        [$final, $doc1, $doc2] = $this->scenario('CZ20970002');

        $this->settlement->link($final, $doc1, $this->supplierId, true);
        $this->settlement->link($final, $doc2, $this->supplierId, true);
        $first = $this->sums($final);

        // dva plné cykly odpojení + napárování
        for ($i = 0; $i < 2; $i++) {
            $this->settlement->unlink($final, $doc2, $this->supplierId);
            $this->settlement->unlink($final, $doc1, $this->supplierId);

            $afterUnlink = $this->sums($final);
            self::assertEqualsWithDelta(91735.54, $afterUnlink['taxable_base'], 0.005,
                "Cyklus {$i}: po odpojení musí zdanitelné řádky pořád sedět na doklad.");
            self::assertEqualsWithDelta(19264.46, $afterUnlink['taxable_vat'], 0.005,
                "Cyklus {$i}: po odpojení musí zdanitelné řádky pořád sedět na doklad.");

            $this->settlement->link($final, $doc1, $this->supplierId, true);
            $this->settlement->link($final, $doc2, $this->supplierId, true);

            $again = $this->sums($final);
            self::assertEqualsWithDelta($first['taxable_base'], $again['taxable_base'], 0.005,
                "Cyklus {$i}: haléře se nesmějí kumulovat.");
            self::assertEqualsWithDelta($first['taxable_vat'], $again['taxable_vat'], 0.005,
                "Cyklus {$i}: haléře se nesmějí kumulovat.");
            self::assertEqualsWithDelta(0.0, $again['total_base'], 0.005);
            self::assertEqualsWithDelta(0.0, $again['total_vat'], 0.005);
        }
    }

    /**
     * Postaví scénář: konečná faktura (2 zdanitelné řádky dle dokladu) + 2 DDKPZ.
     *
     * @return array{0:int,1:int,2:int} [finalId, taxDoc1Id, taxDoc2Id]
     */
    private function scenario(string $dic): array
    {
        $vendor = $this->vendor('Dodavatel § 37a ' . $dic, $dic);

        // Konečná faktura — dvojice řádků dá přesně rekapitulaci dokladu
        // (80 000,00 + 46 776,86 = 91 735,54; 16 800,00 + 9 823,14 = 19 264,46).
        $final = $this->draft($vendor, 'invoice', 'FA-' . $dic, [
            ['description' => 'Vůz', 'unit_price_without_vat' => 80000.00],
            ['description' => 'Výbava', 'unit_price_without_vat' => 11735.54],
        ]);

        // DDKPZ #1 — dopočet zdola i shora dá shodně 1 735,54, override netřeba.
        $doc1 = $this->draft($vendor, 'tax_document', 'ZD-1-' . $dic, [
            ['description' => 'Záloha 1', 'unit_price_without_vat' => 8264.46],
        ]);

        // DDKPZ #2 — doklad uvádí 17 528,93 (shora z 101 000), kdežto dopočet zdola
        // dá 17 528,92. Rekapitulace dle dokladu se drží přes vat_overrides (§ 73),
        // stejně jako to dělá PurchaseVatRecapSeeder při importu.
        $doc2 = $this->draft($vendor, 'tax_document', 'ZD-2-' . $dic, [
            ['description' => 'Záloha 2', 'unit_price_without_vat' => 83471.07],
        ]);
        $this->repo->setVatOverrides($doc2, $this->supplierId, [
            ['rate' => 21.0, 'base' => 83471.07, 'vat' => 17528.93],
        ]);
        $this->calc->recompute($doc2);

        // DDKPZ musí být mimo koncept, jinak je link() odmítne.
        foreach ([$doc1, $doc2] as $d) {
            $this->db->pdo()->prepare("UPDATE purchase_invoices SET status='received' WHERE id=?")->execute([$d]);
        }

        return [$final, $doc1, $doc2];
    }

    /**
     * @param list<array{description:string, unit_price_without_vat:float}> $items
     */
    private function draft(int $vendorId, string $kind, string $number, array $items): int
    {
        $date = self::YEAR . '-06-30';
        $payload = [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $number,
            'document_kind'         => $kind,
            'issue_date'            => $date,
            'tax_date'              => $date,
            'due_date'              => $date,
            'received_at'           => $date,
            'currency_id'           => $this->currencyId,
            'items'                 => [],
        ];
        $id = $this->repo->createDraft($payload, $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        $rows = [];
        foreach ($items as $i => $it) {
            $rows[] = [
                'description'            => $it['description'],
                'quantity'               => 1.0,
                'unit'                   => 'ks',
                'unit_price_without_vat' => $it['unit_price_without_vat'],
                'vat_rate_id'            => $this->vatRate21,
                'vat_classification_code' => '40',
                'order_index'            => $i,
            ];
        }
        $this->repo->replaceItems($id, $rows);
        $this->calc->recompute($id);

        return $id;
    }

    private function vendor(string $name, string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    /**
     * Součty řádků rozdělené na zdanitelné / odpočtové (§ 37a) / zaokrouhlovací.
     *
     * @return array{taxable_base:float, taxable_vat:float, deduction_base:float,
     *               deduction_vat:float, rounding_base:float, rounding_vat:float,
     *               total_base:float, total_vat:float}
     */
    private function sums(int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT settlement_source_purchase_invoice_id AS src, is_settlement_rounding AS rnd,
                    total_without_vat AS base, total_vat AS vat
               FROM purchase_invoice_items WHERE purchase_invoice_id = ?'
        );
        $stmt->execute([$purchaseInvoiceId]);

        $out = ['taxable_base' => 0.0, 'taxable_vat' => 0.0, 'deduction_base' => 0.0,
                'deduction_vat' => 0.0, 'rounding_base' => 0.0, 'rounding_vat' => 0.0,
                'total_base' => 0.0, 'total_vat' => 0.0];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $base = (float) $r['base'];
            $vat  = (float) $r['vat'];
            $key  = !empty($r['rnd']) ? 'rounding' : (!empty($r['src']) ? 'deduction' : 'taxable');
            $out[$key . '_base'] += $base;
            $out[$key . '_vat']  += $vat;
            $out['total_base']   += $base;
            $out['total_vat']    += $vat;
        }
        return array_map(static fn (float $v): float => round($v, 2), $out);
    }

    /**
     * Audit 2026-08-07 (nález [19]): koeficient § 37 v applyGrossTargets.
     *
     * Rekapitulace § 37a se počítá SHORA z hrubé částky: vat = gross·rate/(100+rate).
     * Mutace na gross·rate/100 (nebo obrácené znaménko) přežila celou sadu, protože
     * ostatní testy fixují jen stavy s nulovým hrubým rozdílem — split base/vat tam
     * na výsledek nesahá. Tady se čte přímo `vat_overrides` a tvrdí invariant:
     * pro každý řádek rekapitulace musí `vat` sedět na koeficient shora. To je
     * vlastnost, kterou mutovaný koeficient poruší.
     */
    public function testSettlementRecapUsesTopDownVatCoefficient(): void
    {
        // ČÁSTEČNÉ napárování ZÁMĚRNĚ: linkne se jen DDKPZ #1 (hrubě 10 000),
        // takže na sazbě 21 % zbývá nevyrovnaný základ (111 000 − 10 000 = 101 000).
        // applyGrossTargets ten NETTO zbytek rozdělí koeficientem shora — a právě
        // tady mutace /100 mění výsledek (17 528,93 → 21 210,00). Testy s PLNÝM
        // vyrovnáním (netto ≈ 0) koeficient neexponují, proto mutace přežívala.
        [$final, $doc1] = $this->scenario('CZ20970003');

        $this->settlement->link($final, $doc1, $this->supplierId, true);

        $header    = $this->repo->find($final, $this->supplierId);
        $overrides = $header['vat_overrides'] ?? null;
        self::assertIsArray($overrides);
        self::assertNotSame([], $overrides, 'link musí zapsat rekapitulaci § 73/§ 37a');

        $checked = 0;
        foreach ($overrides as $o) {
            $rate = (float) ($o['rate'] ?? 0);
            $base = (float) ($o['base'] ?? 0);
            $vat  = (float) ($o['vat'] ?? 0);
            if ($rate <= 0.0 || round($base + $vat, 2) < 1.0) {
                continue;   // nulové/haléřové sazby koeficient neexponují
            }
            $gross = round($base + $vat, 2);
            $expectedVat = round($gross * $rate / (100 + $rate), 2);
            self::assertEqualsWithDelta($expectedVat, $vat, 0.01,
                "Sazba {$rate} %: DPH v rekapitulaci musí být spočtena SHORA "
                . "(gross·rate/(100+rate)); mutace koeficientu tenhle test shodí.");
            $checked++;
        }
        self::assertGreaterThan(0, $checked,
            'aspoň jedna nenulová sazba musí být ověřena — jinak test běží naprázdno');
    }
}
