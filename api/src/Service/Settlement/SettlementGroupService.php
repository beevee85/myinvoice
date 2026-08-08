<?php

declare(strict_types=1);

namespace MyInvoice\Service\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * FORK 0922 — vyúčtovací skupiny (SettlementGroup, Dokument 1 bod B1).
 *
 * Skupina = obchodní případ: řetězec dokladů k jednomu plnění. Nestaví se ručně —
 * odvozuje se z existujících vazeb a PŘESTAVUJE SE NA ČTENÍ (rebuildForSupplier
 * volají groupované seznamy). Tím je samo-opravná: každá změna vazby (link/unlink,
 * koš, storno) se projeví při příštím čtení bez zásahů do zapisovacích cest.
 *
 * Hrany grafu:
 *  - nákup:  lc.advance_purchase_invoice_id → záloha (kdo zálohu čerpá: DDKPZ nebo konečná)
 *            dd.settled_by_purchase_invoice_id → konečná faktura (§ 37a)
 *  - prodej: child.parent_invoice_id → zálohová faktura (konečná i DD k platbě míří na proformu)
 *            invoice_payments.tax_document_invoice_id → DD vzniklý z platby
 *
 * Ručně editovatelné jsou jen label (label_is_manual=1) a external_ref —
 * rebuild je nikdy nepřepisuje, jen doplní, když jsou NULL.
 */
final class SettlementGroupService
{
    /** VIN: 17 znaků bez I/O/Q — auto-návrh external_ref z poznámek dokladu. */
    private const VIN_PATTERN = '/\b([A-HJ-NPR-Z0-9]{17})\b/';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Přestaví skupiny jednoho směru pro tenanta. Vrací počet skupin.
     * Idempotentní; existující skupiny se recyklují podle členů (žádné hromadné
     * mazání a zakládání — id skupin zůstávají stabilní kvůli odkazům z UI).
     */
    public function rebuildForSupplier(int $supplierId, string $direction): int
    {
        $components = $direction === 'purchase'
            ? $this->purchaseComponents($supplierId)
            : $this->saleComponents($supplierId);

        $pdo = $this->db->pdo();
        $table = $direction === 'purchase' ? 'purchase_invoices' : 'invoices';

        $keptGroupIds = [];
        foreach ($components as $component) {
            $keptGroupIds[] = $this->upsertGroup($supplierId, $direction, $component);
        }

        // Doklady, které už v žádné komponentě nejsou → odpojit od skupiny.
        $in = $keptGroupIds === [] ? '0' : implode(',', array_map('intval', $keptGroupIds));
        $memberIds = [];
        foreach ($components as $component) {
            foreach ($component['members'] as $m) {
                $memberIds[] = (int) $m['id'];
            }
        }
        $memberIn = $memberIds === [] ? '0' : implode(',', array_map('intval', $memberIds));
        $pdo->prepare(
            "UPDATE {$table}
                SET settlement_group_id = NULL, settlement_role = NULL
              WHERE supplier_id = ? AND settlement_group_id IS NOT NULL
                AND id NOT IN ({$memberIn})"
        )->execute([$supplierId]);

        // Skupiny bez členů smazat (FK na dokladech je SET NULL, ale my jsme
        // členy právě přepsali — orphan skupiny by se v UI hromadily).
        $pdo->prepare(
            "DELETE FROM settlement_groups
              WHERE supplier_id = ? AND direction = ? AND id NOT IN ({$in})"
        )->execute([$supplierId, $direction]);

        return count($keptGroupIds);
    }

    /**
     * Řetězec (kroky steppera D1) pro daný doklad: členové jeho skupiny
     * seřazení pro vodorovnou osu. Prázdné pole = doklad není v žádné skupině.
     *
     * @return list<array<string,mixed>>
     */
    public function chainForDocument(int $supplierId, string $direction, int $documentId): array
    {
        $this->rebuildForSupplier($supplierId, $direction);
        $table = $direction === 'purchase' ? 'purchase_invoices' : 'invoices';
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare("SELECT settlement_group_id FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$documentId, $supplierId]);
        $groupId = (int) ($stmt->fetchColumn() ?: 0);
        if ($groupId === 0) {
            return [];
        }
        return $this->membersOf($groupId, $direction, $supplierId);
    }

    /**
     * Skupiny + členové pro groupovaný seznam („Podle vyúčtování", C2/C3).
     *
     * @return list<array<string,mixed>>
     */
    public function listGroups(int $supplierId, string $direction): array
    {
        $this->rebuildForSupplier($supplierId, $direction);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT sg.id, sg.label, sg.external_ref, sg.counterparty_id, sg.final_document_id,
                    sg.status, sg.total_amount, sg.currency,
                    c.company_name AS counterparty_name
               FROM settlement_groups sg
          LEFT JOIN clients c ON c.id = sg.counterparty_id
              WHERE sg.supplier_id = ? AND sg.direction = ?'
        );
        $stmt->execute([$supplierId, $direction]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $g) {
            $g['id'] = (int) $g['id'];
            $g['counterparty_id'] = $g['counterparty_id'] !== null ? (int) $g['counterparty_id'] : null;
            $g['final_document_id'] = $g['final_document_id'] !== null ? (int) $g['final_document_id'] : null;
            $g['total_amount'] = (float) $g['total_amount'];
            $g['members'] = $this->membersOf($g['id'], $direction, $supplierId);
            $groups[] = $g;
        }
        return $groups;
    }

    // ── komponenty grafu ─────────────────────────────────────────────────

    /** @return list<array{members: list<array<string,mixed>>}> */
    private function purchaseComponents(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        // Všechny doklady s alespoň jednou vazbou (mimo koš; storno člen zůstává,
        // ať je případ vidět celý — role credit_note/cancelled řeší UI).
        $stmt = $pdo->prepare(
            "SELECT pi.id, pi.document_kind, pi.vendor_id AS counterparty_id, pi.varsymbol,
                    pi.issue_date, pi.total_with_vat, pi.rounding, pi.status, pi.currency_id,
                    cur.code AS currency,
                    pi.advance_purchase_invoice_id AS adv_link,
                    pi.settled_by_purchase_invoice_id AS settled_by,
                    pi.note_above_items
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ? AND pi.deleted_at IS NULL
                AND (pi.advance_purchase_invoice_id IS NOT NULL
                     OR pi.settled_by_purchase_invoice_id IS NOT NULL
                     OR EXISTS (SELECT 1 FROM purchase_invoices x
                                 WHERE x.supplier_id = pi.supplier_id AND x.deleted_at IS NULL
                                   AND (x.advance_purchase_invoice_id = pi.id
                                        OR x.settled_by_purchase_invoice_id = pi.id))
                     OR EXISTS (SELECT 1 FROM purchase_invoice_items pii
                                 WHERE pii.purchase_invoice_id = pi.id
                                   AND pii.settlement_source_purchase_invoice_id IS NOT NULL))"
        );
        $stmt->execute([$supplierId]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $edges = [];
        foreach ($docs as $d) {
            if ($d['adv_link'] !== null) {
                $edges[] = [(int) $d['id'], (int) $d['adv_link']];
            }
            if ($d['settled_by'] !== null) {
                $edges[] = [(int) $d['id'], (int) $d['settled_by']];
            }
        }
        // Odpočtové řádky § 37a: konečná ← DDKPZ (settlement_source na položkách).
        $stmt = $pdo->prepare(
            'SELECT DISTINCT pii.purchase_invoice_id AS final_id,
                    pii.settlement_source_purchase_invoice_id AS dd_id
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pi.supplier_id = ? AND pii.settlement_source_purchase_invoice_id IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
            $edges[] = [(int) $e['final_id'], (int) $e['dd_id']];
        }

        return $this->componentsFromEdges($docs, $edges, 'purchase');
    }

    /** @return list<array{members: list<array<string,mixed>>}> */
    private function saleComponents(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT i.id, i.invoice_type, i.client_id AS counterparty_id, i.varsymbol,
                    i.issue_date, i.total_with_vat, i.status, i.parent_invoice_id,
                    cur.code AS currency, i.note_above_items
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ? AND i.deleted_at IS NULL
                AND i.invoice_type <> 'cancellation'
                AND (i.parent_invoice_id IS NOT NULL
                     OR EXISTS (SELECT 1 FROM invoices ch
                                 WHERE ch.supplier_id = i.supplier_id AND ch.deleted_at IS NULL
                                   AND ch.parent_invoice_id = i.id AND ch.invoice_type <> 'cancellation')
                     OR EXISTS (SELECT 1 FROM invoice_payments ip
                                 WHERE ip.tax_document_invoice_id = i.id))"
        );
        $stmt->execute([$supplierId]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byId = [];
        foreach ($docs as $d) {
            $byId[(int) $d['id']] = $d;
        }
        $edges = [];
        foreach ($docs as $d) {
            // Hrana jen mezi doklady案 v sadě (parent mimo sadu = např. storno vazba).
            $p = $d['parent_invoice_id'] !== null ? (int) $d['parent_invoice_id'] : 0;
            if ($p > 0 && isset($byId[$p])) {
                $edges[] = [(int) $d['id'], $p];
            }
        }
        // DD vzniklý z platby: platba na proformě → tax_document.
        $stmt = $pdo->prepare(
            'SELECT DISTINCT ip.invoice_id AS proforma_id, ip.tax_document_invoice_id AS dd_id
               FROM invoice_payments ip
              WHERE ip.supplier_id = ? AND ip.tax_document_invoice_id IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
            if (isset($byId[(int) $e['proforma_id']], $byId[(int) $e['dd_id']])) {
                $edges[] = [(int) $e['proforma_id'], (int) $e['dd_id']];
            }
        }

        return $this->componentsFromEdges($docs, $edges, 'sale');
    }

    /**
     * Union-find nad hranami; komponenty s < 2 doklady se zahazují
     * (samostatný doklad není případ).
     *
     * @param list<array<string,mixed>> $docs
     * @param list<array{0:int,1:int}> $edges
     * @return list<array{members: list<array<string,mixed>>}>
     */
    private function componentsFromEdges(array $docs, array $edges, string $direction): array
    {
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            $parent[$x] ??= $x;
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }
            return $parent[$x];
        };
        foreach ($edges as [$a, $b]) {
            $parent[$find($a)] = $find($b);
        }

        $byRoot = [];
        foreach ($docs as $d) {
            $id = (int) $d['id'];
            if (!isset($parent[$id])) {
                continue; // bez hrany — samostatný doklad
            }
            $byRoot[$find($id)][] = $d;
        }

        $out = [];
        foreach ($byRoot as $members) {
            if (count($members) < 2) {
                continue;
            }
            usort($members, static fn (array $a, array $b): int =>
                [$a['issue_date'], $a['id']] <=> [$b['issue_date'], $b['id']]);
            $out[] = ['members' => $members, 'direction' => $direction];
        }
        return $out;
    }

    // ── upsert skupiny ───────────────────────────────────────────────────

    /** @param array{members: list<array<string,mixed>>} $component */
    private function upsertGroup(int $supplierId, string $direction, array $component): int
    {
        $pdo = $this->db->pdo();
        $members = $component['members'];
        $table = $direction === 'purchase' ? 'purchase_invoices' : 'invoices';

        $final = null;
        foreach ($members as $m) {
            $kind = $direction === 'purchase' ? $m['document_kind'] : $m['invoice_type'];
            if ($kind === 'invoice' && ($m['status'] ?? '') !== 'cancelled') {
                // Poslední konečná vyhrává (starší storno-náhrady apod.).
                $final = $m;
            }
        }

        // Hodnota plnění vč. DPH (C2 — hlavní řádek NIKDY nula):
        //  - s konečnou: hrubá hodnota = |total| konečné + Σ |odpočtů § 37a| se řeší
        //    přes zálohy — jednodušeji a shodně s D2: Σ total_with_vat daňových
        //    dokladů (DD nesou skutečné částky záloh) + zbytek na konečné.
        //  - bez konečné: Σ zálohových faktur (nedaňové výzvy), fallback Σ DD.
        $sumDd = 0.0;
        $sumAdvance = 0.0;
        foreach ($members as $m) {
            $kind = $direction === 'purchase' ? $m['document_kind'] : $m['invoice_type'];
            if (($m['status'] ?? '') === 'cancelled') {
                continue;
            }
            if ($kind === 'tax_document') {
                $sumDd += (float) $m['total_with_vat'];
            }
            if (($direction === 'purchase' && $kind === 'advance')
                || ($direction === 'sale' && $kind === 'proforma')) {
                $sumAdvance += (float) $m['total_with_vat'];
            }
        }
        if ($final !== null) {
            $finalTotal = (float) $final['total_with_vat'] + (float) ($final['rounding'] ?? 0);
            $total = round($sumDd + $finalTotal, 2);
            // Prodejní finál z proformy: zálohy odečtené přes advance_paid_amount
            // drží plný total na finále → nezdvojovat (DD tam pak nejsou).
            if ($direction === 'sale' && $sumDd === 0.0) {
                $total = round((float) $final['total_with_vat'], 2);
            }
        } else {
            $total = round($sumAdvance > 0 ? $sumAdvance : $sumDd, 2);
        }

        $status = 'open';
        if ($final !== null) {
            $status = 'settled';
            if ($direction === 'purchase' && $this->purchaseMismatch((int) $final['id'], $members)) {
                $status = 'mismatch';
            }
        }

        $counterpartyId = (int) ($members[0]['counterparty_id'] ?? 0) ?: null;
        $currency = (string) ($final['currency'] ?? $members[0]['currency'] ?? 'CZK');
        $externalRef = $this->guessVin($members);

        // Recyklace: skupina, do níž patří většina členů (typicky všichni).
        $ids = array_map(static fn (array $m): int => (int) $m['id'], $members);
        $in = implode(',', array_map('intval', $ids));
        $existing = $pdo->query(
            "SELECT settlement_group_id AS gid, COUNT(*) AS n
               FROM {$table} WHERE id IN ({$in}) AND settlement_group_id IS NOT NULL
              GROUP BY settlement_group_id ORDER BY n DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $groupId = $existing ? (int) $existing['gid'] : 0;

        if ($groupId > 0) {
            // label/external_ref jen doplnit, nikdy nepřepsat ruční hodnotu.
            $pdo->prepare(
                'UPDATE settlement_groups
                    SET counterparty_id = ?, final_document_id = ?, status = ?,
                        total_amount = ?, currency = ?,
                        external_ref = COALESCE(external_ref, ?),
                        label = CASE WHEN label_is_manual = 1 THEN label ELSE COALESCE(?, label) END
                  WHERE id = ? AND supplier_id = ?'
            )->execute([
                $counterpartyId, $final !== null ? (int) $final['id'] : null, $status,
                $total, $currency, $externalRef,
                $this->autoLabel($members, $externalRef), $groupId, $supplierId,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO settlement_groups
                    (supplier_id, direction, label, external_ref, counterparty_id,
                     final_document_id, status, total_amount, currency)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $supplierId, $direction, $this->autoLabel($members, $externalRef), $externalRef,
                $counterpartyId, $final !== null ? (int) $final['id'] : null, $status, $total, $currency,
            ]);
            $groupId = (int) $pdo->lastInsertId();
        }

        // Členství + role.
        $roleSql = $pdo->prepare(
            "UPDATE {$table} SET settlement_group_id = ?, settlement_role = ? WHERE id = ? AND supplier_id = ?"
        );
        foreach ($members as $m) {
            $kind = $direction === 'purchase' ? $m['document_kind'] : $m['invoice_type'];
            $role = match ($kind) {
                'advance', 'proforma' => 'advance',
                'tax_document'        => 'advance_tax_document',
                'credit_note'         => 'credit_note',
                default               => 'final',
            };
            $roleSql->execute([$groupId, $role, (int) $m['id'], $supplierId]);
        }

        return $groupId;
    }

    /** § 37a nesoulad na konečné: Σ odpočtových řádků ≠ −Σ DDKPZ (haléřová tolerance). */
    private function purchaseMismatch(int $finalId, array $members): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(total_with_vat), 0)
               FROM purchase_invoice_items
              WHERE purchase_invoice_id = ? AND settlement_source_purchase_invoice_id IS NOT NULL
                AND is_settlement_rounding = 0'
        );
        $stmt->execute([$finalId]);
        $deducted = (float) $stmt->fetchColumn();

        $sumDd = 0.0;
        foreach ($members as $m) {
            if (($m['document_kind'] ?? '') === 'tax_document' && ($m['status'] ?? '') !== 'cancelled') {
                $sumDd += (float) $m['total_with_vat'];
            }
        }
        return abs($deducted + $sumDd) > 0.02;
    }

    /** @return list<array<string,mixed>> */
    private function membersOf(int $groupId, string $direction, int $supplierId): array
    {
        $pdo = $this->db->pdo();
        if ($direction === 'purchase') {
            $stmt = $pdo->prepare(
                'SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                        pi.settlement_role, pi.issue_date, pi.tax_date, pi.due_date,
                        pi.total_with_vat, pi.rounding, pi.amount_to_pay, pi.status, pi.paid_at,
                        cur.code AS currency
                   FROM purchase_invoices pi
                   JOIN currencies cur ON cur.id = pi.currency_id
                  WHERE pi.settlement_group_id = ? AND pi.supplier_id = ?
               ORDER BY pi.issue_date, pi.id'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT i.id, i.varsymbol, NULL AS vendor_invoice_number, i.invoice_type AS document_kind,
                        i.settlement_role, i.issue_date, i.tax_date, i.due_date,
                        i.total_with_vat, 0 AS rounding, i.amount_to_pay, i.status, i.paid_at,
                        cur.code AS currency
                   FROM invoices i
                   JOIN currencies cur ON cur.id = i.currency_id
                  WHERE i.settlement_group_id = ? AND i.supplier_id = ?
               ORDER BY i.issue_date, i.id'
            );
        }
        $stmt->execute([$groupId, $supplierId]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            foreach (['total_with_vat', 'rounding', 'amount_to_pay'] as $f) {
                $r[$f] = $r[$f] !== null ? (float) $r[$f] : 0.0;
            }
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** VIN z poznámek členů (B4/A6: identifikátor případu; plná entita vozidla později). */
    private function guessVin(array $members): ?string
    {
        foreach ($members as $m) {
            $note = (string) ($m['note_above_items'] ?? '');
            if ($note !== '' && preg_match(self::VIN_PATTERN, $note, $match)) {
                return $match[1];
            }
        }
        return null;
    }

    private function autoLabel(array $members, ?string $vin): ?string
    {
        if ($vin !== null) {
            return 'VIN ' . $vin;
        }
        return null;
    }
}
