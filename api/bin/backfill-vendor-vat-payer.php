<?php

declare(strict_types=1);

/**
 * Údržba plátcovství DPH: (A) refresh živého příznaku klientů z ARES/VIES a
 * (B) léčení nesprávně odpočtených přijatých faktur od dodavatelů-NEPLÁTCŮ.
 *
 * ⚠️ POZOR — léčení faktur (B) je nově řízené SNAPSHOTEM `purchase_invoices.vendor_is_vat_payer`
 * (stav dodavatele k datu plnění, migrace 0133), NE dnešním stavem registru. Dřív skript
 * mazal DPH z dokladů podle toho, jestli je dodavatel plátce DNES — čímž rozbíjel historické
 * faktury, kde dodavatel v době plnění plátce BYL a dnes už není. To je opravené:
 *   - fáze B sáhne JEN na doklad, jehož snapshot říká „neplátce" (vendor_is_vat_payer = 0),
 *   - a NIKDY na doklad nesoucí českou DPH (total_vat > 0 a ne reverse charge) — ten provably
 *     pochází od plátce; případný rozpor se snapshotem se jen ohlásí a přeskočí.
 *
 * Pořadí: nejdřív zmraz snapshoty přes `backfill-purchase-vendor-vat-payer-snapshot.php`,
 * teprve pak spusť tenhle healer. Legacy doklady se snapshotem NULL fáze B ZÁMĚRNĚ ignoruje
 * (NULL != 0) — dokud není snapshot zmrazený, není co bezpečně léčit.
 *
 * Fáze A (refresh živého flagu klientů) je bezpečná — jde o AKTUÁLNÍ stav, historii chrání
 * snapshoty na dokladech.
 *
 * Co fáze B u léčeného dokladu dělá:
 *   a) nastaví `vat_deduction='none'` (vyloučení z odpočtu),
 *   b) sazby položek na 0 % a ZACHOVÁ zaúčtovanou částku — celé „s DPH" se stane základem
 *      (DPH = 0, total beze změny), protože od neplátce žádné DPH není,
 *   c) přepíše interní prefix čísla (PF→NN dle uplatnění).
 *
 * Použití:
 *   php api/bin/backfill-vendor-vat-payer.php           # dry-run (jen náhled, nic nezapisuje)
 *   php api/bin/backfill-vendor-vat-payer.php --apply    # provede změny
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$vatPayer = $container->get(\MyInvoice\Service\Ares\VendorVatPayerResolver::class);
$piRepo = $container->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);
$calc = $container->get(\MyInvoice\Service\Invoice\PurchaseInvoiceCalculator::class);

// 0% nereverzní sazba pro vynulování položek neplátce.
$zeroRateId = (int) ($pdo->query(
    "SELECT id FROM vat_rates WHERE rate_percent = 0 AND is_reverse_charge = 0 ORDER BY id LIMIT 1"
)->fetchColumn() ?: 0);

$mode = $dryRun ? '[DRY-RUN] ' : '';

// ── Fáze A: refresh živého příznaku klientů z ARES/VIES (aktuální stav) ─────────────
$clients = $pdo->query(
    "SELECT id, supplier_id, company_name, ic, dic, is_vat_payer, is_customer, is_vendor
       FROM clients
      WHERE archived_at IS NULL AND (ic IS NOT NULL OR dic IS NOT NULL)
      ORDER BY supplier_id, company_name"
)->fetchAll(\PDO::FETCH_ASSOC);

echo "{$mode}Fáze A — refresh živého příznaku klientů: " . count($clients) . " klientů s IČO/DIČ (ARES/VIES, cache 24 h)…\n\n";

$payers = 0;
$nonPayers = 0;
$unknown = 0;
$flippedFlags = 0;

foreach ($clients as $v) {
    $id  = (int) $v['id'];
    $ic  = isset($v['ic'])  ? (string) $v['ic']  : null;
    $dic = isset($v['dic']) ? (string) $v['dic'] : null;
    $isVendor = (int) ($v['is_vendor'] ?? 0) === 1;
    $role = $isVendor ? ((int) ($v['is_customer'] ?? 0) === 1 ? 'K+D' : 'dodav.') : 'zákazn.';

    $res = $dryRun ? $vatPayer->resolve($ic, $dic) : $vatPayer->resolveAndPersist($id, $ic, $dic);
    $isVatPayer = $res['is_vat_payer'];

    if ($isVatPayer === null) {
        $unknown++;
        continue; // ARES/VIES nerozhodly → příznak neměníme
    }

    $flagChanged = (int) ($v['is_vat_payer'] ?? 1) !== (int) $isVatPayer;
    if ($flagChanged) $flippedFlags++;

    if ($isVatPayer === true) {
        $payers++;
        if ($flagChanged) {
            echo sprintf("  PLÁTCE   tenant=%-2d %-7s %-40s  (příznak →plátce, zdroj=%s)\n",
                $v['supplier_id'], $role, mb_substr((string) $v['company_name'], 0, 40), $res['source']);
        }
    } else {
        $nonPayers++;
        if ($flagChanged) {
            echo sprintf("  NEPLÁTCE tenant=%-2d %-7s %-40s  IČO=%-10s (příznak →neplátce, zdroj=%s)\n",
                $v['supplier_id'], $role, mb_substr((string) $v['company_name'], 0, 40), $ic ?? '—', $res['source']);
        }
    }
}

echo sprintf("\nFáze A hotová: plátci=%d, neplátci=%d, nezjištěno=%d, příznak změněn=%d\n\n",
    $payers, $nonPayers, $unknown, $flippedFlags);

// ── Fáze B: léčení dokladů, jejichž SNAPSHOT říká neplátce (vendor_is_vat_payer = 0) ──
$nullSnapshots = (int) $pdo->query(
    "SELECT COUNT(*) FROM purchase_invoices WHERE vendor_is_vat_payer IS NULL AND status <> 'cancelled'"
)->fetchColumn();
if ($nullSnapshots > 0) {
    echo "⚠️  {$nullSnapshots} přijatých faktur má snapshot plátcovství NULL (legacy) — fáze B je ZÁMĚRNĚ přeskočí.\n";
    echo "    Nejdřív spusť: php api/bin/backfill-purchase-vendor-vat-payer-snapshot.php --apply\n\n";
}

// Kandidáti: snapshot = neplátce, doklad ale ještě nese odpočet nebo DPH sazbu na položkách.
$candidates = $pdo->query(
    "SELECT pi.id, pi.supplier_id, pi.vendor_invoice_number, pi.status, pi.vat_deduction,
            pi.total_with_vat, pi.total_vat, pi.reverse_charge, c.company_name AS vendor_name
       FROM purchase_invoices pi
       JOIN clients c ON c.id = pi.vendor_id
      WHERE pi.vendor_is_vat_payer = 0
        AND pi.status <> 'cancelled'
        AND (pi.vat_deduction <> 'none'
             OR EXISTS (SELECT 1 FROM purchase_invoice_items pii
                         WHERE pii.purchase_invoice_id = pi.id AND pii.vat_rate_snapshot > 0))
      ORDER BY pi.supplier_id, pi.id"
)->fetchAll(\PDO::FETCH_ASSOC);

echo "{$mode}Fáze B — léčení dokladů se snapshotem „neplátce\": " . count($candidates) . " kandidátů.\n";

$setStmt = $pdo->prepare("UPDATE purchase_invoices SET vat_deduction = 'none' WHERE id = ?");
// Položky neplátce: cena bez DPH := cena s DPH (gross), sazba 0 %. Recompute pak dá
// základ = celé „s DPH", DPH = 0, total beze změny (od neplátce žádné DPH není).
$zeroItemsStmt = $pdo->prepare(
    "UPDATE purchase_invoice_items
        SET unit_price_without_vat = IF(quantity <> 0, ROUND(total_with_vat / quantity, 2), total_with_vat),
            vat_rate_id = ?, vat_rate_snapshot = 0
      WHERE purchase_invoice_id = ?"
);

$fixedInvoices = 0;
$skippedBearingVat = 0;

foreach ($candidates as $inv) {
    $invId = (int) $inv['id'];

    // HARD GUARD: doklad nesoucí českou DPH (total_vat > 0 a ne RC) provably pochází od
    // plátce → NIKDY na něj nesaháme, i kdyby snapshot říkal opak (rozpor jen ohlásíme).
    if ((float) $inv['total_vat'] > 0 && (int) $inv['reverse_charge'] === 0) {
        $skippedBearingVat++;
        echo sprintf("      pi#%-6d %-9s  č.=%-16s  ⚠ nese DPH %.2f — PŘESKOČENO (rozpor se snapshotem)\n",
            $invId, $inv['status'], $inv['vendor_invoice_number'] ?: '(none)', (float) $inv['total_vat']);
        continue;
    }

    echo sprintf("      pi#%-6d %-9s  č.=%-16s  %-22s  vat_deduction:%s→none, sazby→0%%, total %.2f beze změny\n",
        $invId, $inv['status'], $inv['vendor_invoice_number'] ?: '(none)',
        mb_substr((string) ($inv['vendor_name'] ?? ''), 0, 22), $inv['vat_deduction'], (float) $inv['total_with_vat']);
    $fixedInvoices++;

    if (!$dryRun) {
        $setStmt->execute([$invId]);                          // vat_deduction = 'none'
        $zeroItemsStmt->execute([$zeroRateId, $invId]);       // cena bez DPH := s DPH, sazba 0 %
        $calc->recompute($invId);                             // základ = s DPH, DPH = 0, total beze změny
        $piRepo->reprefixVarsymbol($invId, (int) $inv['supplier_id']);  // PF→NN dle uplatnění
    }
}

echo "\nSouhrn:\n";
echo "  Fáze A — příznak is_vat_payer změněn: {$flippedFlags}\n";
echo "  Fáze B — faktur → vat_deduction='none': {$fixedInvoices}\n";
echo "  Fáze B — přeskočeno (nese DPH, rozpor): {$skippedBearingVat}\n";

if ($dryRun) {
    echo "\nSpusť znovu s --apply pro skutečný zápis.\n";
} else {
    echo "\nHotovo. Po backfill spusť 'Přepočítat' v /crm dashboardu, aby se DPH přiznání aktualizovala.\n";
}
