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
                // Snapshot rekapitulace před PRVNÍM párováním — odpojení posledního
                // dokladu pak vrátí přesně původní rozklad základ/daň (z hrubé částky
                // ho zrekonstruovat nelze, viz migrace 0907).
                if (($final['settlement_documents'] ?? []) === []
                    && ($final['settlement_recap_backup'] ?? null) === null) {
                    $this->repo->setSettlementRecapBackup($finalId, $supplierId, $final['vat_overrides'] ?? null);
                }
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
            $this->repo->deleteSettlementRoundingRows($finalId);

            if ($hadGeneratedRows) {
                // Symetrie k link(): hrubou hodnotu odpojeného DDKPZ vrátíme do cíle
                // sazby zpět (stav přesně před párováním — vč. rekapitulace dle dokladu
                // dodavatele, kterou by přirozený dopočet z řádků mohl minout o haléř).
                $beforeByRate = $this->breakdownByRate($final['items'] ?? []);
                $docByRate    = $this->breakdownByRate($doc['items'] ?? []);
                $remaining = array_filter(
                    $final['settlement_documents'] ?? [],
                    static fn (array $d) => (int) $d['id'] !== $taxDocId
                );
                $backup = $final['settlement_recap_backup'] ?? null;
                if ($remaining === [] && is_array($backup) && array_key_exists('overrides', $backup)) {
                    // Poslední doklad odpojen → vrátit přesně stav před párováním
                    // (vč. rekapitulace § 73 dle dokladu dodavatele) a snapshot zahodit.
                    $restored = is_array($backup['overrides']) ? $backup['overrides'] : null;
                    $this->repo->setVatOverrides($finalId, $supplierId, $restored);
                    $this->repo->setSettlementRecapBackup($finalId, $supplierId, false);
                    $this->calculator->recompute($finalId);
                } else {
                    $targets = [];
                    foreach ($docByRate as $key => $g) {
                        $before = $beforeByRate[$key] ?? ['base' => 0.0, 'vat' => 0.0];
                        $targets[$key] = [
                            'rate'  => $g['rate'],
                            'gross' => round(
                                round((float) $before['base'] + (float) $before['vat'], 2)
                                + round($g['base'] + $g['vat'], 2),
                                2
                            ),
                        ];
                    }
                    // Symetrie s link(): řádky dokladu se po přepočtu vrátí na hodnoty
                    // dle dokladu dodavatele (§ 73 / § 100), odpočtové řádky zůstanou
                    // doslova dle zbývajících DDKPZ a haléřový rozdíl ponese samostatný
                    // řádek „Zaokrouhlení § 37a". Bez snapshotu by recompute uvnitř
                    // applyGrossTargets rozpustil haléř do zdanitelných řádků faktury.
                    $rowSnapshot = $this->repo->snapshotItemTotals($finalId);
                    $this->applyGrossTargets($finalId, $supplierId, $final['vat_overrides'] ?? null, $targets);
                    $this->repo->restoreItemTotals($rowSnapshot);
                    $this->pinAllSettlementRows($finalId, $supplierId, false);
                    // Audit 2026-08-07: deleteSettlementRoundingRows smazal řádky VŠECH
                    // sazeb, ale $targets pokrývá jen sazby ODPOJOVANÉHO dokladu — sazba
                    // nesená pouze zbývajícím DDKPZ by přišla o zaokrouhlovací řádek
                    // a rozešla se s vat_overrides. Resync proto podle CELÉ výsledné
                    // rekapitulace (post-applyGrossTargets), ne jen podle odpojovaného.
                    $this->syncRoundingRows($finalId, $supplierId,
                        $this->targetsFromRecap($finalId, $supplierId, $targets));
                }
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
        // Odkaz na zálohovou fakturu, ke které byl daňový doklad vystaven — z konečné
        // faktury je tak vidět celý řetězec záloha → DDKPZ → vyúčtování bez proklikávání.
        $advanceRef = '';
        $linkedAdvance = $doc['linked_advance'] ?? null;
        if (is_array($linkedAdvance)) {
            $advNumber = (string) ($linkedAdvance['vendor_invoice_number'] ?? $linkedAdvance['varsymbol'] ?? '');
            if ($advNumber !== '') {
                $advanceRef = ' (' . $advNumber . ')';
            }
        }
        $pricesIncludeVat = !empty($final['prices_include_vat']);

        // Cíl § 37a se počítá z HODNOT DOKLADŮ, ne ze stavu řádků: nově vložené
        // odpočtové řádky mají totály ještě nulové (dopočítá je až recompute), takže
        // čtení „čerstvých" řádků by dalo cíl = původní částka.
        $beforeByRate = $this->breakdownByRate($final['items'] ?? []);
        $docByRate    = $this->breakdownByRate($doc['items'] ?? []);

        // Snapshot řádků PŘED přepočtem: doklad se eviduje tak, jak ho vystavil
        // dodavatel (§ 73 / § 100 ZDPH), takže se řádky po přepočtu vrátí na původní
        // hodnoty a haléřový rozdíl ponese samostatný řádek „Zaokrouhlení § 37a".
        $rowSnapshot = $this->repo->snapshotItemTotals($finalId);

        $rows = [];
        foreach ($this->rowGroups($doc['items'] ?? []) as $g) {
            // Režim řádku musí odpovídat hlavičce konečné faktury: zdola = záporný základ,
            // shora = záporné brutto (InvoiceMath interpretuje cenu podle prices_include_vat).
            $unitPrice = $pricesIncludeVat
                ? -round($g['base'] + $g['vat'], 2)
                : -$g['base'];
            $rows[] = [
                'description'             => 'Odpočet zálohy — daňový doklad ' . $docNumber . $advanceRef,
                'unit_price'              => $unitPrice,
                'vat_rate_id'             => $g['vat_rate_id'],
                'rate'                    => $g['rate'],
                'vat_classification_code' => $g['vat_classification_code'],
                'is_fixed_asset'          => $g['is_fixed_asset'],
            ];
        }
        $this->repo->addSettlementRows($finalId, $taxDocId, $rows);

        // Hrubý rozdíl per sazba: (dosavadní hrubá hodnota sazby) − (hrubá hodnota DDKPZ).
        $targets = [];
        foreach ($docByRate as $key => $g) {
            $before = $beforeByRate[$key] ?? ['base' => 0.0, 'vat' => 0.0];
            $targets[$key] = [
                'rate'  => $g['rate'],
                'gross' => round(
                    round((float) $before['base'] + (float) $before['vat'], 2)
                    - round($g['base'] + $g['vat'], 2),
                    2
                ),
            ];
        }
        $this->applyGrossTargets($finalId, $supplierId, $final['vat_overrides'] ?? null, $targets);

        // Řádky zpět dle dokladů (faktura z PDF, odpočty dle DDKPZ) a zbytek proti
        // cíli § 37a na jeden viditelný zaokrouhlovací řádek.
        $this->repo->restoreItemTotals($rowSnapshot);
        // compensate: false — haléřový rozdíl NEpřelévat do zdanitelných řádků faktury
        // (musejí zůstat dle dokladu dodavatele); absorbuje ho syncRoundingRows() níž.
        $this->pinAllSettlementRows($finalId, $supplierId, false);
        $this->syncRoundingRows($finalId, $supplierId, $targets);
    }

    /**
     * Dorovná rozdíl mezi součtem řádků sazby a cílem § 37a jedním viditelným řádkem
     * „Zaokrouhlení § 37a" — místo rozpouštění haléřů do zdanitelných položek, které
     * musejí sedět na doklad dodavatele.
     *
     * Řádek je ve stejné sazbě jako rozdíl, který nuluje — tím sazba vstoupí do
     * přiznání i KH správnou (nulovou) hodnotou. Kdyby stál „mimo DPH", zůstal by
     * v sazbě rozdíl 0,01 / −0,01 a doklad by hlásil rozpor znamének.
     *
     * @param array<string, array{rate:float, gross:float}> $targets
     */
    /**
     * Cíle pro sync zaokrouhlovacích řádků z CELÉ výsledné rekapitulace dokladu.
     *
     * Po unlinku drží `vat_overrides` cílovou rekapitulaci pro všechny zbývající
     * sazby; zaokrouhlovací řádek musí existovat pro každou z nich, ne jen pro
     * sazby odpojeného DDKPZ. `$fallback` (targets odpojeného dokladu) drží
     * hodnoty i pro sazbu, která už v recap není (její řádky mizí — rounding = 0).
     *
     * @param array<string,array{rate:float,gross:float}> $fallback
     * @return array<string,array{rate:float,gross:float}>
     */
    private function targetsFromRecap(int $finalId, int $supplierId, array $fallback): array
    {
        $fresh = $this->repo->find($finalId, $supplierId);
        $recap = is_array($fresh['vat_overrides'] ?? null) ? $fresh['vat_overrides'] : [];

        $out = $fallback;
        foreach ($recap as $o) {
            if (!isset($o['rate'])) {
                continue;
            }
            $key = number_format((float) $o['rate'], 2, '.', '');
            $out[$key] = [
                'rate'  => (float) $o['rate'],
                'gross' => round((float) ($o['base'] ?? 0) + (float) ($o['vat'] ?? 0), 2),
            ];
        }

        return $out;
    }

    private function syncRoundingRows(int $finalId, int $supplierId, array $targets): void
    {
        $fresh = $this->repo->find($finalId, $supplierId);
        if ($fresh === null) {
            return;
        }
        $sums = [];
        $rateIds = [];
        $codes = [];
        foreach ($fresh['items'] ?? [] as $it) {
            if (!empty($it['is_settlement_rounding'])) {
                continue;
            }
            $rate = (float) ($it['vat_rate_snapshot'] ?? 0);
            $key  = number_format($rate, 2, '.', '');
            $sums[$key] ??= ['base' => 0.0, 'vat' => 0.0];
            $sums[$key]['base'] += (float) ($it['total_without_vat'] ?? 0);
            $sums[$key]['vat']  += (float) ($it['total_vat'] ?? 0);
            $rateIds[$key] ??= (int) ($it['vat_rate_id'] ?? 0);
            if (!isset($codes[$key]) && !empty($it['vat_classification_code'])) {
                $codes[$key] = (string) $it['vat_classification_code'];
            }
        }

        foreach ($targets as $key => $t) {
            $rate  = (float) $t['rate'];
            $gross = (float) $t['gross'];
            $vatTarget  = $rate > 0.0 ? round($gross * $rate / (100 + $rate), 2) : 0.0;
            $baseTarget = round($gross - $vatTarget, 2);
            $sum = $sums[$key] ?? ['base' => 0.0, 'vat' => 0.0];
            $this->repo->syncSettlementRoundingRow(
                $finalId,
                $rateIds[$key] ?? 0,
                $rate,
                round($baseTarget - round($sum['base'], 2), 2),
                round($vatTarget - round($sum['vat'], 2), 2),
                $codes[$key] ?? null,
                'Zaokrouhlení § 37a (rozdíl rozpisu faktury a daňových dokladů k záloze)',
            );
        }

        // Hlavičkové součty ze skutečně uložených řádků (řádky se nepřepočítávají).
        $this->repo->syncHeaderTotalsFromItems($finalId, $supplierId);
    }

    /**
     * Přišpendlí hodnoty VŠECH odpočtových řádků dokladu doslova dle jejich zdrojových
     * DDKPZ. Volá se po každém recompute — ten totiž počítá řádky ze sazby a přepsal by
     * i dřív přišpendlené odpočty (druhé párování by rozhodilo první).
     */
    private function pinAllSettlementRows(int $finalId, int $supplierId, bool $compensate = true): void
    {
        $fresh = $this->repo->find($finalId, $supplierId);
        if ($fresh === null) {
            return;
        }
        foreach ($fresh['settlement_documents'] ?? [] as $d) {
            $src = $this->repo->find((int) $d['id'], $supplierId);
            if ($src === null) {
                continue;
            }
            $this->repo->pinSettlementRowTotals($finalId, (int) $d['id'], array_map(
                static fn (array $g) => ['base' => -$g['base'], 'vat' => -$g['vat'], 'rate' => $g['rate']],
                $this->rowGroups($src['items'] ?? [])
            ), $compensate);
        }
    }

    /**
     * Zapíše cílovou rekapitulaci DPH pro dané sazby z HRUBÉHO rozdílu a přepočítá doklad.
     *
     * § 37a se počítá na úrovni součtů dokladu: základ i daň se odvodí z jednoho čísla
     * (hrubý rozdíl) koeficientem § 37 — daň shora, základ jako zbytek. Tím nikdy
     * nevznikne kombinace kladný základ × záporná daň (obě hodnoty mají znaménko
     * hrubého rozdílu; při plné záloze 0,00/0,00). Sazba skupiny odpovídá § 37a
     * odst. 2: doplatek v sazbě plnění, přeplatek v sazbě zálohy (skupina JE sazba
     * zálohy). Overrides ostatních sazeb zůstávají nedotčené.
     *
     * @param mixed $existing aktuální vat_overrides (list<{rate,base?,vat?}>|null)
     * @param array<string, array{rate:float, gross:float}> $targets
     */
    private function applyGrossTargets(int $finalId, int $supplierId, mixed $existing, array $targets): void
    {
        $merged = [];
        foreach ((array) $existing as $o) {
            if (isset($o['rate'])) {
                $merged[number_format((float) $o['rate'], 2, '.', '')] = $o;
            }
        }
        foreach ($targets as $key => $t) {
            $rate  = (float) $t['rate'];
            $gross = (float) $t['gross'];
            $vat   = $rate > 0.0 ? round($gross * $rate / (100 + $rate), 2) : 0.0;
            $merged[$key] = ['rate' => $rate, 'base' => round($gross - $vat, 2), 'vat' => $vat];
        }
        $out = array_values($merged);
        $this->repo->setVatOverrides($finalId, $supplierId, $out !== [] ? $out : null);
        $this->calculator->recompute($finalId);
    }

    /**
     * Seskupí řádky dokladu per sazba: rateKey → base/vat/rate. Vynechává skupiny
     * s nulovým základem i daní. Slouží pro cíle vat_overrides (rekapitulace § 73
     * i § 37a se vede per sazba).
     *
     * @param list<array<string,mixed>> $items
     * @return array<string, array{base:float, vat:float, rate:float}>
     */
    private function breakdownByRate(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $rate = (float) ($it['vat_rate_snapshot'] ?? 0.0);
            $key  = number_format($rate, 2, '.', '');
            if (!isset($out[$key])) {
                $out[$key] = ['base' => 0.0, 'vat' => 0.0, 'rate' => $rate];
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

}
