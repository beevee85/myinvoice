<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;

/**
 * Vyúčtování daňových dokladů k přijaté záloze (DDKPZ, document_kind='tax_document')
 * na konečné (vyúčtovací) faktuře podle § 37a ZDPH — zrcadlo FinalFromProformaCreator
 * z vydané strany, ale pro PŘIJATÉ doklady: nic negenerujeme, jen evidujeme doklad
 * dodavatele tak, aby do DPH/KH i nákladů vstoupil pouze rozdílem.
 *
 * Princip: vazba N DDKPZ → 1 konečná faktura přes settled_by_purchase_invoice_id.
 * Volitelně (výchozí ano) se na konečnou fakturu doplní záporné odpočtové řádky —
 * jeden per (sazba, klasifikace, majetek) zdrojového dokladu — označené
 * settlement_source_purchase_invoice_id. Aby výsledné částky seděly haléřově
 * s dokladem dodavatele (základ i daň DDKPZ se odečítají přesně, ne přepočtem
 * sazbou — § 37a pracuje se skutečně přiznanými hodnotami), nastaví se zároveň
 * vat_overrides (§ 73 rekapitulace dle dokladu): cílová hodnota per sazba =
 * dosavadní rekapitulace − hodnoty DDKPZ. InvoiceMath pak reziduum přišpendlí
 * na nejsilnější řádek sazby, takže per-item součty (které čtou DPH výkazy)
 * sedí přesně.
 *
 * Všechny zápisy běží v transakci (link i unlink jsou multi-statement sekvence)
 * a vazba se zabírá atomicky (claimSettledBy) — souběžné párování téhož DDKPZ
 * na dvě faktury nemůže projít dvakrát.
 */
final class PurchaseSettlementService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PurchaseInvoiceCalculator $calculator,
    ) {}

    /**
     * Napáruje DDKPZ na konečnou fakturu; s $applyDeduction doplní odpočtové řádky
     * a sladí rekapitulaci DPH.
     *
     * @throws \RuntimeException při porušení validace (zpráva pro UI)
     */
    public function link(int $finalId, int $taxDocId, int $supplierId, bool $applyDeduction = true): void
    {
        if ($finalId === $taxDocId) {
            throw new \RuntimeException('Nelze propojit doklad sám se sebou.');
        }
        $final = $this->repo->find($finalId, $supplierId);
        $doc   = $this->repo->find($taxDocId, $supplierId);
        if ($final === null || $doc === null) {
            throw new \RuntimeException('Doklad nenalezen.');
        }
        if (($doc['document_kind'] ?? '') !== 'tax_document') {
            throw new \RuntimeException('Párovat lze jen daňový doklad k přijaté záloze.');
        }
        if (in_array((string) ($final['document_kind'] ?? ''), ['advance', 'tax_document'], true)) {
            throw new \RuntimeException('Daňový doklad k záloze lze párovat jen s konečnou (vyúčtovací) fakturou.');
        }
        // Dobropis nemůže být konečnou stranou — VatLedger dobropisy překlápí -ABS()
        // per řádek, záporné odpočtové řádky by se započetly obráceně/dvakrát.
        if (($final['document_kind'] ?? '') === 'credit_note') {
            throw new \RuntimeException('Daňový doklad k záloze nelze párovat s dobropisem.');
        }
        if ((int) $final['vendor_id'] !== (int) $doc['vendor_id']) {
            throw new \RuntimeException('Oba doklady musí být od stejného dodavatele.');
        }
        if ((int) $final['currency_id'] !== (int) $doc['currency_id']) {
            throw new \RuntimeException('Oba doklady musí být ve stejné měně.');
        }
        if (($doc['status'] ?? '') === 'cancelled' || ($final['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Stornovaný doklad nelze párovat.');
        }
        // Draft není daňovým dokladem (stejná doktrína jako FinalFromProformaCreator
        // na vydané straně): do DPH/KH nevstupuje, takže by odpočet na konečné faktuře
        // neměl protistranu a za období by tiše chyběl základ i daň zálohy.
        if (($doc['status'] ?? '') === 'draft') {
            throw new \RuntimeException('Rozpracovaný (draft) daňový doklad nelze párovat — nejdřív ho potvrďte jako přijatý.');
        }
        if (!empty($doc['settled_by_purchase_invoice_id'])) {
            throw new \RuntimeException('Daňový doklad už je napárovaný na jinou fakturu.');
        }

        if ($applyDeduction) {
            // Kombinace se starým mechanismem zálohy: advance_paid_amount snižuje
            // „k úhradě" (amount_to_pay = total_with_vat − advance_paid_amount, STORED
            // generated). Odpočtové řádky snižují total_with_vat — obojí najednou by
            // zálohu odečetlo dvakrát a doklad by vyšel na 0/záporně.
            if (round((float) ($final['advance_paid_amount'] ?? 0), 2) !== 0.0) {
                throw new \RuntimeException(
                    'Konečná faktura má vyplněné pole „záloha" (k úhradě = celkem − záloha) — '
                    . 'odpočet přes daňový doklad by zálohu odečetl podruhé. Nejdřív zálohu na '
                    . 'faktuře vynulujte (příp. zrušte přímou vazbu na zálohovou fakturu), '
                    . 'nebo párujte bez doplnění odpočtových řádků.'
                );
            }
            // Konečná faktura v režimu přenesené daňové povinnosti: InvoiceMath
            // vat_overrides ignoruje a záporné řádky by se záporně samovyměřily.
            if (!empty($final['reverse_charge'])) {
                throw new \RuntimeException(
                    'Konečná faktura je v režimu přenesení daňové povinnosti — odpočtové řádky '
                    . '§ 37a nelze doplnit automaticky. Spárujte bez doplnění řádků.'
                );
            }
            // DDKPZ bez nároku na odpočet do přiznání/KH nevstupuje — odpočet na konečné
            // faktuře by neměl protistranu (mimo RC, ten má vlastní režim).
            if (($doc['vat_deduction'] ?? 'full') === 'none' && empty($doc['reverse_charge'])) {
                throw new \RuntimeException(
                    'Daňový doklad k záloze má nastaveno „bez nároku na odpočet" — do přiznání '
                    . 'nevstupuje, odpočet na konečné faktuře by neměl protistranu. Nejdřív '
                    . 'opravte daňové uplatnění dokladu, nebo párujte bez doplnění řádků.'
                );
            }
        }

        $pdo = $this->db->pdo();
        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }
        try {
            // Atomický zábor vazby — souběžný požadavek na stejný DDKPZ neprojde.
            if (!$this->repo->claimSettledBy($taxDocId, $finalId, $supplierId)) {
                throw new \RuntimeException('Daňový doklad už je napárovaný na jinou fakturu.');
            }

            // Explicitní párování § 37a potvrzuje celý řetězec záloha → DDKPZ → konečná:
            // visící AI návrh vazby na zálohu tím potvrdíme, aby zaplacená záloha vypadla
            // z nákladových agregací (NOT EXISTS na advance_purchase_invoice_id).
            if (empty($doc['advance_purchase_invoice_id']) && !empty($doc['advance_link_suggested_id'])) {
                try {
                    $this->repo->linkAdvance($taxDocId, (int) $doc['advance_link_suggested_id'], $supplierId);
                } catch (\Throwable) {
                    // Návrh neprošel validací — párování § 37a tím nesmí spadnout.
                }
            }

            if ($applyDeduction) {
                $this->applyDeductionRows($final, $doc, $finalId, $taxDocId, $supplierId);
            }

            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Zruší párování DDKPZ ↔ konečná faktura; odebere auto-generované odpočtové řádky
     * a odstraní settlementové položky z vat_overrides — rekapitulace se po smazání
     * řádků spočítá přirozeně z vlastních řádků faktury (zpětné „přičítání" by nechalo
     * na dokladu přišpendlené duchy starých hodnot).
     *
     * @throws \RuntimeException při porušení validace
     */
    public function unlink(int $finalId, int $taxDocId, int $supplierId): void
    {
        $final = $this->repo->find($finalId, $supplierId);
        $doc   = $this->repo->find($taxDocId, $supplierId);
        if ($final === null || $doc === null) {
            throw new \RuntimeException('Doklad nenalezen.');
        }
        if ((int) ($doc['settled_by_purchase_invoice_id'] ?? 0) !== $finalId) {
            throw new \RuntimeException('Doklady nejsou propojené.');
        }

        $hadGeneratedRows = false;
        foreach ($final['items'] ?? [] as $it) {
            if ((int) ($it['settlement_source_purchase_invoice_id'] ?? 0) === $taxDocId) {
                $hadGeneratedRows = true;
                break;
            }
        }

        $pdo = $this->db->pdo();
        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }
        try {
            $this->repo->setSettledBy($taxDocId, null, $supplierId);
            $this->repo->deleteSettlementRows($finalId, $taxDocId);

            if ($hadGeneratedRows) {
                // Odstranit override položky pro sazby DDKPZ — po smazání odpočtových
                // řádků se rekapitulace dopočítá z vlastních řádků faktury. (Případný
                // původní § 73 override z importu tím zanikne; jeho „vrácení" nelze
                // rekonstruovat a přišpendlený settlementový cíl by byl horší.)
                $docByRate = $this->breakdownByRate($doc['items'] ?? []);
                $kept = [];
                foreach ((array) ($final['vat_overrides'] ?? []) as $o) {
                    $key = isset($o['rate']) ? number_format((float) $o['rate'], 2, '.', '') : null;
                    if ($key === null || !isset($docByRate[$key])) {
                        $kept[] = $o;
                    }
                }
                $this->repo->setVatOverrides($finalId, $supplierId, $kept !== [] ? $kept : null);
                $this->calculator->recompute($finalId);
            }

            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Doplní záporné odpočtové řádky (per sazba+klasifikace+majetek zdrojového DDKPZ)
     * a sladí rekapitulaci DPH na cílový rozdíl.
     *
     * @param array<string,mixed> $final
     * @param array<string,mixed> $doc
     */
    private function applyDeductionRows(array $final, array $doc, int $finalId, int $taxDocId, int $supplierId): void
    {
        $docByRate = $this->breakdownByRate($doc['items'] ?? []);
        if ($docByRate === []) {
            return; // DDKPZ bez řádků — nic k odpočtu
        }
        $beforeByRate = $this->breakdownByRate($final['items'] ?? []);

        $docNumber = (string) ($doc['vendor_invoice_number'] ?? $doc['varsymbol'] ?? ('#' . $taxDocId));
        $pricesIncludeVat = !empty($final['prices_include_vat']);

        $rows = [];
        foreach ($this->rowGroups($doc['items'] ?? []) as $g) {
            // Režim řádku musí odpovídat hlavičce konečné faktury: zdola = záporný základ,
            // shora = záporné brutto (InvoiceMath interpretuje cenu podle prices_include_vat).
            $unitPrice = $pricesIncludeVat
                ? -round($g['base'] + $g['vat'], 2)
                : -$g['base'];
            $rows[] = [
                'description'             => 'Odpočet zálohy — daňový doklad ' . $docNumber,
                'unit_price'              => $unitPrice,
                'vat_rate_id'             => $g['vat_rate_id'],
                'rate'                    => $g['rate'],
                'vat_classification_code' => $g['vat_classification_code'],
                'is_fixed_asset'          => $g['is_fixed_asset'],
            ];
        }
        $this->repo->addSettlementRows($finalId, $taxDocId, $rows);

        // Cílová rekapitulace = před zásahem − DDKPZ (přesné hodnoty dle dokladů, § 37a).
        $overrides = $this->mergedOverrides($final['vat_overrides'] ?? null, $docByRate, $beforeByRate);
        $this->repo->setVatOverrides($finalId, $supplierId, $overrides);
        $this->calculator->recompute($finalId);
    }

    /**
     * Seskupí řádky dokladu per sazba: rateKey → base/vat/rate/vat_rate_id.
     * Vynechává skupiny s nulovým základem i daní. Slouží pro vat_overrides
     * (rekapitulace § 73 je per sazba).
     *
     * @param list<array<string,mixed>> $items
     * @return array<string, array{base:float, vat:float, rate:float, vat_rate_id:int}>
     */
    private function breakdownByRate(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $rate = (float) ($it['vat_rate_snapshot'] ?? 0.0);
            $key  = number_format($rate, 2, '.', '');
            if (!isset($out[$key])) {
                $out[$key] = [
                    'base'        => 0.0,
                    'vat'         => 0.0,
                    'rate'        => $rate,
                    'vat_rate_id' => (int) ($it['vat_rate_id'] ?? 0),
                ];
            }
            $out[$key]['base'] += (float) ($it['total_without_vat'] ?? 0.0);
            $out[$key]['vat']  += (float) ($it['total_vat'] ?? 0.0);
        }
        foreach ($out as $key => $g) {
            $out[$key]['base'] = round($g['base'], 2);
            $out[$key]['vat']  = round($g['vat'], 2);
            if ($out[$key]['base'] === 0.0 && $out[$key]['vat'] === 0.0) {
                unset($out[$key]);
            }
        }
        return $out;
    }

    /**
     * Jemnější seskupení pro generované odpočtové řádky: per (sazba, klasifikační kód,
     * majetek) — klasifikace řádků DDKPZ (např. ř. 47 u majetku) se musí odečíst pod
     * stejným kódem, jinak by se DP3/KH řádky nevyrušily.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array{base:float, vat:float, rate:float, vat_rate_id:int,
     *                    vat_classification_code:?string, is_fixed_asset:bool}>
     */
    private function rowGroups(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $rate = (float) ($it['vat_rate_snapshot'] ?? 0.0);
            $code = isset($it['vat_classification_code']) && $it['vat_classification_code'] !== ''
                ? (string) $it['vat_classification_code']
                : null;
            $fixed = !empty($it['is_fixed_asset']);
            $key = number_format($rate, 2, '.', '') . '|' . ($code ?? '·') . '|' . ($fixed ? '1' : '0');
            if (!isset($out[$key])) {
                $out[$key] = [
                    'base'                    => 0.0,
                    'vat'                     => 0.0,
                    'rate'                    => $rate,
                    'vat_rate_id'             => (int) ($it['vat_rate_id'] ?? 0),
                    'vat_classification_code' => $code,
                    'is_fixed_asset'          => $fixed,
                ];
            }
            $out[$key]['base'] += (float) ($it['total_without_vat'] ?? 0.0);
            $out[$key]['vat']  += (float) ($it['total_vat'] ?? 0.0);
        }
        $list = [];
        foreach ($out as $g) {
            $g['base'] = round($g['base'], 2);
            $g['vat']  = round($g['vat'], 2);
            if ($g['base'] !== 0.0 || $g['vat'] !== 0.0) {
                $list[] = $g;
            }
        }
        return $list;
    }

    /**
     * Slije stávající vat_overrides s cílovými hodnotami pro sazby DDKPZ:
     * target(sazba) = aktuální rekapitulace(sazba) − hodnoty DDKPZ(sazba).
     * Overrides ostatních sazeb zůstávají nedotčené.
     *
     * @param mixed $existing  aktuální vat_overrides (list<{rate,base?,vat?}>|null)
     * @param array<string, array{base:float, vat:float, rate:float, vat_rate_id:int}> $docByRate
     * @param array<string, array{base:float, vat:float, rate:float, vat_rate_id:int}> $currentByRate
     * @return list<array{rate:float, base:float, vat:float}>|null
     */
    private function mergedOverrides(mixed $existing, array $docByRate, array $currentByRate): ?array
    {
        $merged = [];
        if (is_array($existing)) {
            foreach ($existing as $o) {
                if (isset($o['rate'])) {
                    $merged[number_format((float) $o['rate'], 2, '.', '')] = $o;
                }
            }
        }
        foreach ($docByRate as $key => $g) {
            $cur = $currentByRate[$key] ?? ['base' => 0.0, 'vat' => 0.0];
            $merged[$key] = [
                'rate' => $g['rate'],
                'base' => round((float) $cur['base'] - $g['base'], 2),
                'vat'  => round((float) $cur['vat'] - $g['vat'], 2),
            ];
        }
        $out = array_values($merged);
        return $out !== [] ? $out : null;
    }
}
