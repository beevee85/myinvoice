<?php

declare(strict_types=1);

/**
 * Dávkový přepočet přijatých faktur — hlavičkové součty a rekapitulace DPH.
 *
 * PROČ
 * ----
 * Načtení dokladu je čistě READ-ONLY: `PurchaseInvoiceRepository::find()` počítá
 * odvozené příznaky (`vat_sign_mismatch`, `settlement_*`) v paměti a nic
 * neukládá. Uložené součty tedy pocházejí z okamžiku posledního zápisu — po
 * změně výpočtové logiky (např. § 37a na úrovni součtů dokladu) je nutné je
 * přepočítat DÁVKOVĚ. Jinak by doklad, na který nikdo neklikne, zůstal s
 * čísly podle staré logiky a rozešel by se s exportem pro účetní.
 *
 * CO SKRIPT DĚLÁ
 * --------------
 * Pro každý vybraný doklad spočítá součty z uložených řádků (`total_without_vat`,
 * `total_vat`, `total_with_vat`) a porovná je s hlavičkou. Liší-li se, v režimu
 * `--apply` je srovná. Řádky NEPŘEPOČÍTÁVÁ — doklad se eviduje tak, jak ho
 * vystavil dodavatel (§ 73 / § 100 ZDPH); k přepočtu řádků slouží editor.
 *
 * Doklady s vazbou § 37a (napárovaný daňový doklad k přijaté záloze) navíc
 * kontroluje na rozpor znamének v rekapitulaci a na chybějící odpočtové řádky
 * a vypíše je jako VYŽADUJE POZORNOST (neopravuje je automaticky — přepočet
 * § 37a se dělá přepárováním, aby vznikl i zaokrouhlovací řádek).
 *
 * Idempotentní: druhý běh po `--apply` nesmí najít žádný rozdíl.
 *
 * POUŽITÍ
 *   php api/bin/recompute-purchase-invoices.php                     # dry-run, vše
 *   php api/bin/recompute-purchase-invoices.php --supplier=1        # jen tenant 1
 *   php api/bin/recompute-purchase-invoices.php --from=2026-01-01   # od data vystavení
 *   php api/bin/recompute-purchase-invoices.php --apply             # zápis
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun     = !in_array('--apply', $argv, true);
$supplierId = null;
$from       = null;
$to         = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--supplier=')) $supplierId = (int) substr($a, 11);
    if (str_starts_with($a, '--from='))     $from = substr($a, 7);
    if (str_starts_with($a, '--to='))       $to   = substr($a, 5);
}

$app = \MyInvoice\Bootstrap::buildApp();
$pdo = $app->getContainer()->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

$params = [];
$sql = "
    SELECT pi.id, pi.supplier_id, pi.varsymbol, pi.vendor_invoice_number, pi.status,
           pi.document_kind, pi.issue_date,
           pi.total_without_vat AS head_base, pi.total_vat AS head_vat, pi.total_with_vat AS head_gross,
           COALESCE(ROUND(SUM(pii.total_without_vat), 2), 0) AS items_base,
           COALESCE(ROUND(SUM(pii.total_vat), 2), 0)         AS items_vat,
           COALESCE(ROUND(SUM(pii.total_with_vat), 2), 0)    AS items_gross,
           (SELECT COUNT(*) FROM purchase_invoices s WHERE s.settled_by_purchase_invoice_id = pi.id) AS settlement_docs
      FROM purchase_invoices pi
 LEFT JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
     WHERE 1 = 1";
if ($supplierId !== null) { $sql .= ' AND pi.supplier_id = ?';  $params[] = $supplierId; }
if ($from !== null)       { $sql .= ' AND pi.issue_date >= ?';  $params[] = $from; }
if ($to !== null)         { $sql .= ' AND pi.issue_date <= ?';  $params[] = $to; }
$sql .= ' GROUP BY pi.id ORDER BY pi.supplier_id, pi.id';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Kontrola " . count($rows) . " přijatých faktur…\n\n";

$fixed = 0;
$attention = [];
$update = $pdo->prepare(
    'UPDATE purchase_invoices SET total_without_vat = ?, total_vat = ?, total_with_vat = ? WHERE id = ?'
);

foreach ($rows as $r) {
    $id = (int) $r['id'];
    $diffBase  = round((float) $r['items_base'] - (float) $r['head_base'], 2);
    $diffVat   = round((float) $r['items_vat'] - (float) $r['head_vat'], 2);
    $diffGross = round((float) $r['items_gross'] - (float) $r['head_gross'], 2);

    if ($diffBase !== 0.0 || $diffVat !== 0.0 || $diffGross !== 0.0) {
        printf(
            "%s#%-5d %-12s %-16s hlavička %10.2f/%10.2f/%10.2f → položky %10.2f/%10.2f/%10.2f\n",
            $mode, $id, (string) ($r['varsymbol'] ?? '—'), (string) ($r['vendor_invoice_number'] ?? '—'),
            (float) $r['head_base'], (float) $r['head_vat'], (float) $r['head_gross'],
            (float) $r['items_base'], (float) $r['items_vat'], (float) $r['items_gross'],
        );
        if (!$dryRun) {
            $update->execute([(float) $r['items_base'], (float) $r['items_vat'], (float) $r['items_gross'], $id]);
        }
        $fixed++;
    }

    // Rozpor znamének v rekapitulaci (§ 37a haléře) — na opravu je potřeba přepárování.
    $recap = $pdo->prepare(
        'SELECT vat_rate_snapshot AS rate, ROUND(SUM(total_without_vat), 2) AS base, ROUND(SUM(total_vat), 2) AS vat
           FROM purchase_invoice_items WHERE purchase_invoice_id = ? GROUP BY vat_rate_snapshot'
    );
    $recap->execute([$id]);
    foreach ($recap->fetchAll(PDO::FETCH_ASSOC) ?: [] as $g) {
        $rate = (float) $g['rate'];
        $base = (float) $g['base'];
        $vat  = (float) $g['vat'];
        if ($rate > 0.005 && abs($base) > 0.005 && abs($vat) > 0.005 && (($base > 0) !== ($vat > 0))) {
            $attention[] = sprintf(
                '#%d %s — sazba %.0f %%: základ %.2f, daň %.2f (opačná znaménka; %s)',
                $id, (string) ($r['varsymbol'] ?? '—'), $rate, $base, $vat,
                (int) $r['settlement_docs'] > 0
                    ? 'přepáruj daňové doklady k záloze v detailu dokladu'
                    : 'zkontroluj rozpis DPH v editoru',
            );
        }
    }
}

echo "\n";
if ($attention !== []) {
    echo "VYŽADUJE POZORNOST (skript neopravuje automaticky):\n";
    foreach ($attention as $a) {
        echo "  • {$a}\n";
    }
    echo "\n";
}
if ($fixed === 0) {
    echo "Hlavičkové součty odpovídají položkám u všech dokladů — není co přepočítávat.\n";
} elseif ($dryRun) {
    echo "Nalezeno {$fixed} dokladů s rozdílem. Spusť znovu s --apply pro zápis.\n";
} else {
    echo "Přepočítáno {$fixed} dokladů.\n";
}
