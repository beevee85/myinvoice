<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Blokující pravidla pro koš / trvalé smazání dokladů (vydané i přijaté faktury).
 *
 * Tři oddělené operace (storno ≠ koš ≠ hard delete) — tahle služba rozhoduje,
 * jestli doklad SMÍ do koše / z koše ven definitivně. Kontroluje se na backendu
 * při každé operaci (soft i force), UI jen zrcadlí výsledek.
 *
 * Vrací seznam blokací; každá má:
 *   - code        strojový kód (jde 1:1 do API chyby 409)
 *   - overridable admin ji smí přebít checkboxem „Vím, co dělám".
 *                 DPH období a vazby na jiné doklady přebít NEJDOU nikdy.
 *   - message     lidský důvod pro dialog (čeština; frontend nepřekládá,
 *                 zobrazuje co přijde — konzistentní s Json::error zprávami)
 */
final class DocumentTrashPolicy
{
    /** Formuláře, jejichž podání v archivu zamyká období DUZP proti mazání dokladů s DPH dopadem. */
    private const VAT_FORM_CODES = ['dphdp3', 'dphkh1', 'dphshv'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $row řádek z invoices (repo find)
     * @return list<array{code:string,overridable:bool,message:string}>
     */
    public function blockersForInvoice(array $row): array
    {
        $id         = (int) $row['id'];
        $supplierId = (int) $row['supplier_id'];
        $blockers   = [];
        $pdo        = $this->db->pdo();

        // 1. Uzavřené DPH období (nikdy nepřebít). Drafty do DPH nevstupují.
        if (($row['status'] ?? '') !== 'draft'
            && $this->vatPeriodSubmitted($supplierId, (string) ($row['tax_date'] ?? $row['issue_date'] ?? ''))) {
            $blockers[] = [
                'code'        => 'blocked_vat_period',
                'overridable' => false,
                'message'     => 'DUZP dokladu spadá do období, ke kterému už existuje podání v Archivu podání (DPH/KH/SHV). Použijte storno nebo dobropis.',
            ];
        }

        // 2. Vazby na jiné doklady a úhrady (nikdy nepřebít).
        $linked = [];
        $st = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE parent_invoice_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            $linked[] = 'navázaný dobropis / storno / daňový doklad';
        }
        if (!empty($row['parent_invoice_id'])) {
            $linked[] = 'doklad je propojen s nadřazeným dokladem (záloha / vyúčtování)';
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            $linked[] = 'evidované úhrady';
        }
        $st = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM payment_matches WHERE invoice_id = ?)
                  + (SELECT COUNT(*) FROM bank_transactions WHERE matched_invoice_id = ?)'
        );
        $st->execute([$id, $id]);
        if ((int) $st->fetchColumn() > 0) {
            $linked[] = 'párování s bankovní transakcí';
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM cash_documents WHERE invoice_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            $linked[] = 'pokladní doklad';
        }
        if ($linked !== []) {
            $blockers[] = [
                'code'        => 'blocked_linked_documents',
                'overridable' => false,
                'message'     => 'Doklad má vazby: ' . implode(', ', $linked) . '. Nejdřív vazby zrušte, nebo použijte storno.',
            ];
        }

        // 3. Odesláno klientovi / aktivní veřejný odkaz (admin smí přebít).
        $sent = [];
        if (!empty($row['sent_at'])) {
            $sent[] = 'faktura byla odeslána klientovi';
        }
        if (!empty($row['public_token'])) {
            $sent[] = 'faktura má aktivní veřejný odkaz (web faktura)';
        }
        if ($sent !== []) {
            $blockers[] = [
                'code'        => 'blocked_sent',
                'overridable' => true,
                'message'     => ucfirst(implode(' a ', $sent)) . '. Protistrana doklad mohla vidět — doporučeno je storno.',
            ];
        }

        // 4. Export do externího systému (admin smí přebít).
        if (!empty($row['idoklad_id']) || !empty($row['fakturoid_id'])) {
            $blockers[] = [
                'code'        => 'blocked_exported',
                'overridable' => true,
                'message'     => 'Doklad byl exportován do externího účetnictví (iDoklad / Fakturoid). Smazáním zde vznikne nesoulad.',
            ];
        }

        return $blockers;
    }

    /**
     * @param array<string,mixed> $row řádek z purchase_invoices (repo find)
     * @return list<array{code:string,overridable:bool,message:string}>
     */
    public function blockersForPurchaseInvoice(array $row): array
    {
        $id         = (int) $row['id'];
        $supplierId = (int) $row['supplier_id'];
        $blockers   = [];
        $pdo        = $this->db->pdo();

        // 1. Uzavřené DPH období (nikdy nepřebít). Drafty do DPH nevstupují.
        if (($row['status'] ?? '') !== 'draft'
            && $this->vatPeriodSubmitted($supplierId, (string) ($row['tax_date'] ?? $row['issue_date'] ?? ''))) {
            $blockers[] = [
                'code'        => 'blocked_vat_period',
                'overridable' => false,
                'message'     => 'DUZP dokladu spadá do období, ke kterému už existuje podání v Archivu podání (DPH/KH/SHV). Použijte storno.',
            ];
        }

        // 2. Vazby na jiné doklady a úhrady (nikdy nepřebít).
        // Vazby vyúčtování záloh (DDKPZ, migrace 0904): záloha ↔ daňový doklad ↔ konečná
        // faktura, vč. položkových odpočtových řádků § 37a — pokrývá advance_purchase_invoice_id
        // i settled_by_purchase_invoice_id oběma směry (zrcadlí PurchaseInvoiceRepository::hasSettlementLinks).
        $linked = [];
        $st = $pdo->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM purchase_invoices p
                 WHERE p.id = ? AND (p.advance_purchase_invoice_id IS NOT NULL
                                     OR p.settled_by_purchase_invoice_id IS NOT NULL)
             ) OR EXISTS (
                SELECT 1 FROM purchase_invoices r
                 WHERE r.advance_purchase_invoice_id = ? OR r.settled_by_purchase_invoice_id = ?
             ) OR EXISTS (
                SELECT 1 FROM purchase_invoice_items i
                 WHERE i.settlement_source_purchase_invoice_id = ?
                    OR (i.purchase_invoice_id = ? AND i.settlement_source_purchase_invoice_id IS NOT NULL)
             )'
        );
        $st->execute([$id, $id, $id, $id, $id]);
        if ((bool) $st->fetchColumn()) {
            $linked[] = 'doklad je zapojený do vyúčtování zálohy (záloha / daňový doklad / konečná faktura) — nejdřív zrušte propojení';
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            $linked[] = 'párování s bankovní transakcí';
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM cash_documents WHERE purchase_invoice_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            $linked[] = 'pokladní doklad';
        }
        if (!empty($row['payment_ordered_at'])) {
            $linked[] = 'doklad je v platebním příkazu';
        }
        if ($linked !== []) {
            $blockers[] = [
                'code'        => 'blocked_linked_documents',
                'overridable' => false,
                'message'     => 'Doklad má vazby: ' . implode(', ', $linked) . '. Nejdřív vazby zrušte, nebo použijte storno.',
            ];
        }

        // 3. Export (admin smí přebít). Přijaté doklady se protistraně neodesílají,
        // takže blocked_sent tu neexistuje.
        if (!empty($row['idoklad_id']) || !empty($row['fakturoid_id'])) {
            $blockers[] = [
                'code'        => 'blocked_exported',
                'overridable' => true,
                'message'     => 'Doklad byl exportován do externího účetnictví (iDoklad / Fakturoid). Smazáním zde vznikne nesoulad.',
            ];
        }

        return $blockers;
    }

    /**
     * Zredukuje blokace o ty, které admin přebil („Vím, co dělám").
     * Nepřebitelné blokace projdou vždy.
     *
     * @param list<array{code:string,overridable:bool,message:string}> $blockers
     * @return list<array{code:string,overridable:bool,message:string}>
     */
    public function withoutOverridden(array $blockers, bool $override, bool $isAdmin): array
    {
        if (!$override || !$isAdmin) {
            return $blockers;
        }
        return array_values(array_filter($blockers, static fn (array $b): bool => !$b['overridable']));
    }

    /**
     * Existuje v archivu podání DPH výkaz (DP3/KH/SHV) pokrývající měsíc DUZP?
     * Měsíční podání porovnává rok+měsíc, kvartální rok+kvartál; podání bez
     * měsíce i kvartálu (nemělo by u DPH nastat) bere jako celoroční — bezpečnější
     * je mazání zablokovat než pustit.
     */
    private function vatPeriodSubmitted(int $supplierId, string $taxDate): bool
    {
        if ($taxDate === '' || $supplierId <= 0) {
            return false;
        }
        try {
            $d = new \DateTimeImmutable($taxDate);
        } catch (\Exception) {
            return false;
        }
        $year    = (int) $d->format('Y');
        $month   = (int) $d->format('n');
        $quarter = intdiv($month - 1, 3) + 1;

        $placeholders = implode(',', array_fill(0, count(self::VAT_FORM_CODES), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM tax_submissions
              WHERE supplier_id = ?
                AND form_code IN ($placeholders)
                AND period_year = ?
                AND (period_month = ? OR period_quarter = ?
                     OR (period_month IS NULL AND period_quarter IS NULL))"
        );
        $stmt->execute([$supplierId, ...self::VAT_FORM_CODES, $year, $month, $quarter]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
