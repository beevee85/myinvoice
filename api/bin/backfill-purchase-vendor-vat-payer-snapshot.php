<?php

declare(strict_types=1);

/**
 * Backfill SNAPSHOTU plátcovství dodavatele na historických přijatých fakturách
 * (`purchase_invoices.vendor_is_vat_payer`, migrace 0133).
 *
 * NENÍ to totéž co `backfill-vendor-vat-payer.php` — ten re-resolvuje DNEŠNÍ stav z ARES/VIES,
 * přepisuje `clients.is_vat_payer` a mutuje DPH na fakturách. Tenhle skript naopak jen ZMRAZÍ
 * historicky správný stav na doklad a NIC jiného nemění (žádné vat_deduction, žádné sazby,
 * žádný živý flag klienta).
 *
 * Proč: dokud je snapshot NULL, čtecí cesty fallbackují na živý `clients.is_vat_payer`. U
 * dodavatele, který v době plnění plátce BYL, ale dnes už není, je živý flag dnes 0 →
 * historická faktura by se tvářila jako od neplátce (riziko ztráty nároku na odpočet).
 *
 * Heuristika (daňově podložená, ne dohad):
 *   - Doklad nese českou DPH (total_vat > 0) A NENÍ reverse charge → dodavatel BYL plátce (1).
 *     (Neplátce českou DPH nikdy nefakturuje; u RC daň vyměřuje příjemce, ne dodavatel.)
 *   - Jinak → fallback na dnešní `clients.is_vat_payer` (nejlepší dostupný odhad; uživatel
 *     může kdykoli ručně opravit checkboxem v editoru a přeuložit).
 *
 * Zpracují se JEN řádky s vendor_is_vat_payer IS NULL (idempotentní — opakovaný běh = no-op).
 * Stornované doklady se přeskakují.
 *
 * Použití:
 *   php api/bin/backfill-purchase-vendor-vat-payer-snapshot.php           # dry-run (jen náhled)
 *   php api/bin/backfill-purchase-vendor-vat-payer-snapshot.php --apply    # skutečně zapíše
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

$rows = $pdo->query(
    "SELECT pi.id, pi.supplier_id, pi.issue_date, pi.total_vat, pi.reverse_charge,
            c.company_name AS vendor_name,
            CASE WHEN pi.total_vat > 0 AND pi.reverse_charge = 0
                 THEN 1
                 ELSE COALESCE(c.is_vat_payer, 1)
            END AS resolved
       FROM purchase_invoices pi
       JOIN clients c ON c.id = pi.vendor_id
      WHERE pi.vendor_is_vat_payer IS NULL
        AND pi.status <> 'cancelled'
      ORDER BY pi.supplier_id, pi.issue_date, pi.id"
)->fetchAll(\PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "Žádné přijaté faktury bez snapshotu plátcovství — nic k doplnění.\n";
    exit(0);
}

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Nalezeno " . count($rows) . " přijatých faktur bez snapshotu plátcovství:\n\n";

$update = $pdo->prepare(
    'UPDATE purchase_invoices SET vendor_is_vat_payer = ? WHERE id = ? AND vendor_is_vat_payer IS NULL'
);

$ok = 0;
$byVat = 0;
$byLive = 0;
foreach ($rows as $r) {
    $resolved = (int) $r['resolved'];
    $fromVat = ((float) $r['total_vat'] > 0 && (int) $r['reverse_charge'] === 0);
    $fromVat ? $byVat++ : $byLive++;

    $line = sprintf(
        "  #%-6d tenant=%-2d  %s  DPH=%-10s RC=%d  %-22s → %-8s (%s)",
        $r['id'],
        $r['supplier_id'],
        $r['issue_date'],
        number_format((float) $r['total_vat'], 2, '.', ''),
        (int) $r['reverse_charge'],
        mb_substr((string) ($r['vendor_name'] ?? ''), 0, 22),
        $resolved ? 'plátce' : 'neplátce',
        $fromVat ? 'nese DPH → plátce' : 'fallback živý flag'
    );

    if ($dryRun) {
        echo $line . "\n";
        continue;
    }
    $update->execute([$resolved, (int) $r['id']]);
    echo $line . "\n";
    $ok++;
}

echo "\nZdroj rozhodnutí: DPH na dokladu = {$byVat}, dnešní flag klienta = {$byLive}\n";
if ($dryRun) {
    echo "\n[DRY-RUN] Nic nezapsáno. Spusť s --apply pro skutečný zápis.\n";
} else {
    echo "\nHotovo. Zapsáno {$ok} snapshotů. Deduction ani sazby DPH se neměnily.\n";
}
