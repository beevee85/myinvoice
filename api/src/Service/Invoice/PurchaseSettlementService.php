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
 * jeden per (sazba, klasifikace, majetek) zdrojového dokladu, s hodnotami DOSLOVA
 * z DDKPZ — označené settlement_source_purchase_invoice_id. Cílová rekapitulace
 * sazby se pak přestaví z HRUBÉHO rozdílu (§ 37a na úrovni součtů dokladu, viz
 * rebuildSettlementOverrides) přes vat_overrides; InvoiceMath reziduum přišpendlí
 * na nejsilnější řádek sazby, takže per-item součty (které čtou DPH výkazy)
 * sedí přesně a základ i daň mají vždy shodné znaménko.
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
        // Doklad v koši je read-only (0905). Kontrola musí být tady, ne jen v akci:
        // TrashGuard v akci vidí jen {id} (konečnou fakturu), protistrana (DDKPZ)
        // přichází z těla požadavku a načítá se až tady. Bez toho by šlo odečíst
        // § 37a proti dokladu, který je z DPH/KH i nákladů vyloučený.
        if (!empty($final['deleted_at']) || !empty($doc['deleted_at'])) {
            throw new \RuntimeException('Doklad je v koši — nelze párovat. Nejdřív ho obnovte z koše.');
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
        // Doklad v koši je read-only (0905) — rušení vazby mění položky i rekapitulaci
        // DPH konečné faktury. Stav „v koši a zároveň propojený" vzniknout nemůže
        // (DocumentTrashPolicy vazby blokuje nepřebitelně), guard je pojistka.
        if (!empty($final['deleted_at']) || !empty($doc['deleted_at'])) {
            throw new \RuntimeException('Doklad je v koši — nelze měnit propojení. Nejdřív ho obnovte z koše.');
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
                // Přestavět cíle z čerstvých řádků: zůstal-li pro sazbu odpočtový řádek
                // jiného DDKPZ, dostane nový hrubý cíl (§ 37a); nezůstal-li žádný,
                // override sazby zanikne a rekapitulace se dopočítá přirozeně z řádků.
                // (Případný původní § 73 override z importu tím zanikne; jeho „vrácení"
                // nelze rekonstruovat a přišpendlený settlementový cíl by byl horší.)
                $touched = [];
                foreach ($this->rowGroups($doc['items'] ?? []) as $g) {
                    $touched[number_format($g['rate'], 2, '.', '')] = true;
                }
                $this->rebuildSettlementOverrides($finalId, $supplierId, $touched);
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
        if ($this->rowGroups($doc['items'] ?? []) === []) {
            return; // DDKPZ bez řádků — nic k odpočtu
        }

        $docNumber = (string) ($doc['vendor_invoice_number'] ?? $doc['varsymbol'] ?? ('#' . $taxDocId));
        $pricesIncludeVat = !empty($final['prices_include_vat']);

        $rows = [];
        $touchedRateKeys = [];
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
            $touchedRateKeys[number_format($g['rate'], 2, '.', '')] = true;
        }
        $this->repo->addSettlementRows($finalId, $taxDocId, $rows);
        $this->rebuildSettlementOverrides($finalId, $supplierId, $touchedRateKeys);
    }

    /**
     * Přestaví settlementové vat_overrides z ČERSTVÝCH řádků konečné faktury.
     *
     * § 37a se počítá na úrovni součtů dokladu, ne rozdílem dvou nezávisle
     * zaokrouhlených řad: pro každou sazbu, která má aspoň jeden odpočtový řádek,
     * je cílem HRUBÝ rozdíl (Σ total_with_vat všech řádků sazby — sčítá se exaktně,
     * bez dalšího zaokrouhlování) a základ + daň se z něj odvodí koeficientem § 37
     * (daň shora). Tím nikdy nevznikne kombinace kladný základ × záporná daň
     * (obě hodnoty vycházejí z téhož čísla — u doplatku kladné, u přeplatku záporné,
     * při plné záloze 0,00/0,00). Sazba skupiny odpovídá § 37a odst. 2: doplatek
     * v sazbě plnění, přeplatek v sazbě zálohy (skupina JE sazba zálohy).
     *
     * $touchedRateKeys = sazby dotčené aktuální operací (link/unlink) — jejich staré
     * override položky se zahodí i v případě, že po unlinků už žádný odpočtový řádek
     * nezbyl (rekapitulace se pak dopočítá přirozeně z řádků). Overrides ostatních
     * sazeb zůstávají nedotčené.
     *
     * @param array<string,bool> $touchedRateKeys
     */
    private function rebuildSettlementOverrides(int $finalId, int $supplierId, array $touchedRateKeys): void
    {
        $fresh = $this->repo->find($finalId, $supplierId);
        if ($fresh === null) {
            return;
        }
        $settlementRates = []; // rateKey => rate (sazby s odpočtovým řádkem)
        $grossByRate = [];     // rateKey => Σ total_with_vat všech řádků sazby
        foreach ($fresh['items'] ?? [] as $it) {
            $rate = (float) ($it['vat_rate_snapshot'] ?? 0.0);
            $key  = number_format($rate, 2, '.', '');
            $grossByRate[$key] = ($grossByRate[$key] ?? 0.0) + (float) ($it['total_with_vat'] ?? 0.0);
            if (!empty($it['settlement_source_purchase_invoice_id'])) {
                $settlementRates[$key] = $rate;
            }
        }

        $merged = [];
        foreach ((array) ($fresh['vat_overrides'] ?? []) as $o) {
            if (!isset($o['rate'])) {
                continue;
            }
            $key = number_format((float) $o['rate'], 2, '.', '');
            if (isset($touchedRateKeys[$key]) || isset($settlementRates[$key])) {
                continue; // starý cíl dotčené sazby — nahradí se, nebo zanikne
            }
            $merged[$key] = $o;
        }
        foreach ($settlementRates as $key => $rate) {
            $gross = round($grossByRate[$key] ?? 0.0, 2);
            $vat   = round($gross * $rate / (100 + $rate), 2);
            $base  = round($gross - $vat, 2);
            $merged[$key] = ['rate' => $rate, 'base' => $base, 'vat' => $vat];
        }

        $out = array_values($merged);
        $this->repo->setVatOverrides($finalId, $supplierId, $out !== [] ? $out : null);
        $this->calculator->recompute($finalId);
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

}
