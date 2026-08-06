<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseImportBatchRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Invoice\PurchaseInvoiceWriteService;

/**
 * FORK (beevee85) — apply krok: z ověřeného výsledku KONCEPT.
 *
 * SCHVALUJE SE PO DOKLADECH, NE PO DÁVKÁCH. Tlačítko „přijmout vše" neexistuje
 * záměrně a tahle služba mu nejde naproti: jedno volání = jeden doklad. Kdo
 * chce padesát konceptů, klikne padesátkrát — a u každého se podíval.
 *
 * ZÁPIS JDE PŘES `PurchaseInvoiceWriteService`, stejnou cestou jako ruční
 * pořízení (V77). Celé apply běží v JEDNÉ transakci; write service je
 * re-entrantní, takže pád kdekoli vrátí VŠECHNO — koncept, označení řádku
 * i vazbu na dávku. Poloviční apply neexistuje.
 *
 * ČEMU SE TU NEVĚŘÍ: sazba DPH se hledá v číselníku PŘESNĚ; nenalezená sazba
 * je CHYBA APPLY, ne tichých 0 % (ISDOC cesta padá na 0 % fallback — tady by
 * tichá nula znamenala koncept, který VYPADÁ jako doklad, ale daňově lže).
 * Rozdíl mezi přepočtenými totály a papírem se přizná varováním na konceptu,
 * nikdy se tiše nepřepíše.
 */
final class BatchApply
{
    /** Stavy dávky, ve kterých lze aplikovat. */
    private const APPLYING_STATUSES = ['validating', 'applying'];

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly PurchaseImportBatchRepository $batches,
        private readonly PurchaseInvoiceRepository $invoices,
        private readonly PurchaseInvoiceWriteService $writer,
        private readonly ClientResolver $clients,
    ) {}

    /**
     * @return array{purchase_invoice_id: int, warnings: list<string>, batch_status: string}
     *
     * @throws BatchLimitException když aplikovat nelze; reasonCode určuje HTTP status
     */
    public function apply(int $batchId, int $resultId, int $supplierId, int $userId, string $today): array
    {
        if ($userId <= 0) {
            // `created_by` je NOT NULL FK — bez uživatele nejde koncept založit.
            throw new BatchLimitException('no_user', 'Chybí identita schvalujícího uživatele.');
        }

        // Levná před-kontrola BEZ zámku — jen aby cizí/neexistující dávka
        // nezakládala transakci. Rozhoduje až zamčené čtení uvnitř.
        if ($this->batches->find($batchId, $supplierId) === null) {
            throw new BatchLimitException('batch_not_found', 'Dávka neexistuje.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // ZÁMEK HLAVIČKY PRVNÍ. Serializuje souběžná apply téže dávky:
            // druhé vlákno tu čeká na commit prvního a pak vidí jeho stav.
            // Řeší to obě race najednou — dvojité schválení řádku i přepis
            // `done` zpátky na `applying` u posledních dvou dokladů.
            $batch = $this->batches->lockForApply($batchId, $supplierId);
            if ($batch === null) {
                throw new BatchLimitException('batch_not_found', 'Dávka neexistuje.');
            }
            if (!in_array((string) $batch['status'], self::APPLYING_STATUSES, true)) {
                throw new BatchLimitException('batch_not_applicable',
                    'Dávka není ve stavu, ze kterého lze zakládat koncepty.');
            }

            $row = $this->batches->findResult($resultId, $batchId, $supplierId);
            if ($row === null) {
                throw new BatchLimitException('result_not_found', 'Výsledek neexistuje.');
            }
            if ((string) $row['status'] !== 'validated') {
                // `rejected` (doklad s FAILem) i `applied` končí TADY. Doklad
                // s nálezem FAIL nejde schválit vůbec — oprava patří do extrakce
                // a nového kola, ne do ručního ohýbání na cestě do účetnictví.
                throw new BatchLimitException('result_not_applicable',
                    'Výsledek nelze aplikovat — je odmítnutý, nebo už aplikovaný.');
            }

            $doc = $this->documentFor($row, $batchId, $supplierId);
            [$data, $warnings] = $this->mapToDraft($doc, $supplierId, $today);

            // Duplicitní doklad: unikát (supplier, vendor, číslo, datum) by
            // jinak vybuchl jako SQLSTATE 23000 → 500. Řekneme to srozumitelně.
            // Souběh PŘES RŮZNÉ DÁVKY tahle kontrola nechytí — ten dojede na
            // unikátu a překládá se v catch níž.
            $dupe = $this->findDuplicate($supplierId, (int) $data['vendor_id'],
                (string) $data['vendor_invoice_number'], (string) $data['issue_date']);
            if ($dupe !== null) {
                throw new BatchLimitException('duplicate_invoice',
                    sprintf('Doklad už v evidenci existuje (id %d).', $dupe));
            }

            $invoiceId = $this->writer->createWithItems($data, $userId, $supplierId, 'purchase_batch_import');

            // Vazba koncept → dávka. Vlastní forkový sloupec (A3), ne upstreamový
            // import_batch_id — dva významy pod jedním jménem byly zamítnuty.
            $pdo->prepare(
                'UPDATE purchase_invoices SET purchase_import_batch_id = ?
                  WHERE id = ? AND supplier_id = ?'
            )->execute([$batchId, $invoiceId, $supplierId]);

            $warnings = array_merge($warnings, $this->totalsWarnings($invoiceId, $supplierId, $doc));

            if ($warnings !== []) {
                $this->invoices->setExtractionWarning($invoiceId, $supplierId,
                    implode("\n\n", $warnings), false);
            }

            // Guard `status = validated` ve WHERE zůstává jako druhá vrstva
            // pod zámkem hlavičky.
            $marked = $this->batches->markResultApplied($resultId, $supplierId,
                $invoiceId, $this->normalizedJson($doc, $invoiceId, $warnings));
            if ($marked !== 1) {
                throw new BatchLimitException('result_not_applicable',
                    'Výsledek mezitím aplikoval někdo jiný.');
            }

            // Stav dávky UVNITŘ transakce — pád procesu už nemůže oddělit
            // koncept od stavu. Dřívější verze to dělala po commitu a dávka
            // mohla uvíznout ve `validating` s aplikovanými řádky navždy.
            $this->batches->heartbeat($batchId, $supplierId);
            if ($this->batches->countResultsNotApplied($batchId, $supplierId) === 0) {
                $this->batches->setStatus($batchId, $supplierId, 'done');
                // V79b: terminální stav → raw_json pryč. Normalizovaný payload
                // už leží na každém aplikovaném řádku.
                $this->batches->purgeRawJson($batchId, $supplierId);
                $batchStatus = 'done';
            } else {
                $this->batches->setStatus($batchId, $supplierId, 'applying');
                $batchStatus = 'applying';
            }

            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Souběžný duplikát přes jinou dávku dojede až na unikát
            // uq_pi_vendor_invoice — přeložit na 409, ne nechat spadnout na 500.
            if ((string) $e->getCode() === '23000') {
                throw new BatchLimitException('duplicate_invoice',
                    'Doklad už v evidenci existuje (souběžné založení).');
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'purchase_invoice_id' => $invoiceId,
            'warnings'            => $warnings,
            'batch_status'        => $batchStatus,
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * Doklad TOHOTO řádku z `raw_json` — párování přes sha256 souboru, ne přes
     * pořadí. Pořadí v odpovědi nikdo negarantoval; sha256 ano (V4/V5).
     *
     * @return array<string,mixed>
     */
    private function documentFor(array $row, int $batchId, int $supplierId): array
    {
        $raw = (string) ($row['raw_json'] ?? '');
        if ($raw === '') {
            throw new BatchLimitException('raw_purged',
                'Surová data dávky už byla smazána (retence) — doklad nelze aplikovat.');
        }

        $sha = null;
        foreach ($this->batches->filesForBatch($batchId, $supplierId) as $f) {
            if ((int) $f['id'] === (int) $row['purchase_import_batch_file_id']) {
                $sha = (string) $f['sha256'];
                break;
            }
        }
        if ($sha === null) {
            throw new BatchLimitException('result_not_found', 'Soubor výsledku neexistuje.');
        }

        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BatchLimitException('raw_unreadable', 'Uložený výsledek nejde přečíst.');
        }

        foreach ((array) ($data['documents'] ?? []) as $doc) {
            if (is_array($doc) && ($doc['sha256'] ?? null) === $sha) {
                return $doc;
            }
        }

        // Validace V6 tohle nepropustí; kdyby se to přesto stalo, je to porucha
        // uložených dat, ne vstup uživatele.
        throw new BatchLimitException('raw_unreadable',
            'Uložený výsledek neobsahuje doklad pro tento soubor.');
    }

    /**
     * Mapování doklad → vstup write service. Všechno, co tu je, má důvod:
     *
     *   numbers.varsymbol → payment.variable_symbol   (VS DODAVATELE; NIKDY
     *       `varsymbol` — to je NAŠE interní číslo a u konceptu musí zůstat
     *       NULL, generuje se až při draft→received)
     *   dates.due_date null → issue_date + 14 dní     (sloupec je NOT NULL;
     *       týž default jako ISDOC cesta)
     *   částky jako KANONICKÉ ŘETĚZCE z Money        (write service castí na
     *       float až na svém prahu; syrové haléře by se uložily 100× špatně)
     *
     * @param array<string,mixed> $doc
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    private function mapToDraft(array $doc, int $supplierId, string $today): array
    {
        $warnings = [];

        $vendor   = (array) ($doc['vendor'] ?? []);
        $resolved = $this->clients->resolveVendor([
            'company_name' => (string) ($vendor['company_name'] ?? ''),
            'ic'           => isset($vendor['ic']) ? (string) $vendor['ic'] : null,
            'dic'          => isset($vendor['dic']) ? (string) $vendor['dic'] : null,
        ], $supplierId);

        $issueDate = (string) (($doc['dates'] ?? [])['issue_date'] ?? '');
        $dueDate   = ($doc['dates'] ?? [])['due_date'] ?? null;
        $taxDate   = ($doc['dates'] ?? [])['tax_date'] ?? null;

        $items = [];
        foreach ((array) ($doc['items'] ?? []) as $i => $line) {
            $items[] = [
                'description'            => (string) ($line['description'] ?? ''),
                'quantity'               => (string) ($line['quantity'] ?? '0'),
                'unit'                   => 'ks',
                'unit_price_without_vat' => (string) ($line['unit_price_without_vat'] ?? '0'),
                'vat_rate_id'            => $this->vatRateIdFor((string) ($line['vat_rate'] ?? '')),
                'order_index'            => $i,
            ];
        }

        // DOBROPIS: evidence drží položky dobropisu ZÁPORNĚ (táž konvence jako
        // AI cesta — AiPdfExtractor dělá qty = -abs). Papír je ale běžně tiskne
        // kladně a V43e kladný dobropis výslovně připouští. Kladně opsaný
        // dobropis by v agregacích PŘIČÍTAL místo odečítal — proto se znaménko
        // převádí TADY, s přiznáním ve varování. Smíšená znaménka se nechávají
        // být: tam si extrakce už nějakou konvenci zvolila a přepis by hádal.
        if ((string) ($doc['document_kind'] ?? '') === 'credit_note') {
            $items = $this->normalizeCreditNoteSigns($items, $warnings);
        }

        if (($doc['confidence'] ?? null) === 'low') {
            $warnings[] = 'Extrakce si dokladem nebyla jistá (confidence: low) — zkontrolujte všechna pole proti PDF.';
        }
        // Neplátce DPH → odpočet nelze uplatnit. Týž bezpečný default jako
        // ruční cesta (CreatePurchaseInvoiceAction) — s přiznáním, ne tiše.
        if ($resolved['is_vat_payer'] === false) {
            $warnings[] = 'Dodavatel není plátce DPH — odpočet nastaven na „žádný".';
        }
        if (!empty($doc['linked_documents'])) {
            $warnings[] = 'Doklad odkazuje na jiné doklady (zálohy/vyúčtování) — vazby je třeba napárovat ručně, apply je nezakládá.';
        }

        $currencyId = $this->currencyIdFor(
            (string) (($doc['totals'] ?? [])['currency'] ?? 'CZK'), $supplierId, $warnings);

        $data = [
            'vendor_id'             => (int) $resolved['id'],
            'vendor_is_vat_payer'   => $resolved['is_vat_payer'],
            'vendor_invoice_number' => (string) (($doc['numbers'] ?? [])['vendor_invoice_number'] ?? ''),
            'document_kind'         => (string) ($doc['document_kind'] ?? 'invoice'),
            'issue_date'            => $issueDate,
            'tax_date'              => $taxDate !== null ? (string) $taxDate : null,
            'due_date'              => $dueDate !== null
                ? (string) $dueDate
                : date('Y-m-d', strtotime($issueDate . ' +14 days')),
            'received_at'           => $today,
            'currency_id'           => $currencyId,
            'language'              => 'cs',
            'note_above_items'      => $doc['note_above_items'] ?? null,
            'note_below_items'      => $doc['note_below_items'] ?? null,
            'payment'               => [
                'variable_symbol' => (($doc['numbers'] ?? [])['varsymbol'] ?? null),
            ],
            'items'                 => $items,
        ];

        if ($resolved['is_vat_payer'] === false) {
            $data['vat_deduction'] = 'none';
        }

        return [$data, $warnings];
    }

    /**
     * Sazba z papíru → id v číselníku. PŘESNÁ shoda (±0,01 p. b. na zaokrouhlení
     * reprezentace), ŽÁDNÝ FALLBACK: tichých 0 % by vyrobilo koncept, který
     * vypadá jako doklad a daňově lže. Nenalezená sazba je chyba apply
     * s návodem — doplnit sazbu do číselníku je vědomé rozhodnutí účetní.
     */
    private function vatRateIdFor(string $raw): int
    {
        $norm = str_replace([',', '%', ' '], ['.', '', ''], trim($raw));
        if ($norm === '' || !is_numeric($norm)) {
            throw new BatchLimitException('unknown_vat_rate',
                sprintf('Sazbu DPH „%s" nejde přečíst.', mb_substr($raw, 0, 20)));
        }
        $rate = (float) $norm;

        foreach ($this->invoices->vatRateMap() as $id => $percent) {
            if (abs($percent - $rate) < 0.01) {
                return $id;
            }
        }

        throw new BatchLimitException('unknown_vat_rate',
            sprintf('Sazba DPH %s %% není v číselníku — doplňte ji, nebo doklad pořiďte ručně.', $norm));
    }

    /**
     * Dobropis s kladně opsanými řádky → záporná množství (konvence evidence).
     *
     * @param list<array<string,mixed>> $items
     * @param list<string> $warnings mutuje se odkazem — každý převod se přizná
     * @return list<array<string,mixed>>
     */
    private function normalizeCreditNoteSigns(array $items, array &$warnings): array
    {
        $signs = [];
        foreach ($items as $it) {
            $q = (float) $it['quantity'];
            if ($q !== 0.0) {
                $signs[$q < 0 ? 'neg' : 'pos'] = true;
            }
        }

        if (isset($signs['pos']) && isset($signs['neg'])) {
            $warnings[] = 'Dobropis má smíšená znaménka položek — znaménka ponechána, zkontrolujte proti PDF.';

            return $items;
        }
        if (!isset($signs['pos'])) {
            // Už záporné (nebo bez peněžních řádků) — není co převádět.
            return $items;
        }

        foreach ($items as &$it) {
            $q = (string) $it['quantity'];
            if ((float) $q !== 0.0) {
                $it['quantity'] = '-' . ltrim($q, '+');
            }
        }
        unset($it);

        $warnings[] = 'Dobropis: množství položek převedena na záporná (konvence evidence, stejně jako AI import). Papír je tiskne kladně.';

        return $items;
    }

    /**
     * Find-or-create měny per tenant — týž postup jako ISDOC cesta, se dvěma
     * rozdíly: kód z nedůvěryhodného results.json musí mít TVAR měny
     * (tři velká písmena — jinak by cokoli až po CHAR(3) přeteklo na
     * SQLSTATE 22001 → 500), a auto-založení měny se PŘIZNÁ ve varování,
     * protože překlep extrakce („CZk" projde, „KORUNY" ne, „QQQ" založí
     * fantom) má vidět člověk na konceptu.
     */
    private function currencyIdFor(string $code, int $supplierId, array &$warnings): int
    {
        $code = strtoupper(trim($code)) ?: 'CZK';

        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            throw new BatchLimitException('invalid_currency',
                sprintf('Měna „%s" nemá tvar ISO kódu (tři písmena).', mb_substr($code, 0, 12)));
        }

        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ?
              ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $pdo->prepare(
            'INSERT INTO currencies
                 (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, 0)'
        )->execute([$supplierId, $code, "{$code} — jen pro nákup", $code, $code, $code]);

        $warnings[] = sprintf('Měna %s nebyla v číselníku — založena jako neaktivní nákupní měna. Zkontrolujte, že je to skutečně měna dokladu.', $code);

        return (int) $pdo->lastInsertId();
    }

    private function findDuplicate(int $supplierId, int $vendorId, string $number, string $issueDate): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $vendorId, $number, $issueDate]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Přepočtené totály konceptu vs. papír. Rozdíl se PŘIZNÁ, nikdy tiše
     * nepřepíše: recompute počítá z položek a sazeb, papír má vlastní
     * zaokrouhlení — když se rozejdou, má to vidět člověk, ne databáze.
     *
     * @param array<string,mixed> $doc
     * @return list<string>
     */
    private function totalsWarnings(int $invoiceId, int $supplierId, array $doc): array
    {
        $paper = (string) (($doc['totals'] ?? [])['total'] ?? '');
        if ($paper === '') {
            return [];
        }

        $invoice = $this->invoices->find($invoiceId, $supplierId);
        $computed = (string) ($invoice['total_with_vat'] ?? '');

        // Dobropis: znaménka jsme převedli na záporná (konvence evidence),
        // papír tiskne kladně — porovnává se ABSOLUTNÍ hodnota, jinak by
        // každý dobropis dostal falešné varování o rozdílu totálů.
        if ((string) ($doc['document_kind'] ?? '') === 'credit_note') {
            $computed = ltrim($computed, '-');
            $paper    = ltrim($paper, '-');
        }

        try {
            if ($computed !== '' && !Money::parse($computed)->equals(Money::parse($paper))) {
                return [sprintf(
                    'Přepočtený součet konceptu (%s) nesedí na celkovou částku dokladu (%s) — '
                    . 'typicky jiné zaokrouhlení DPH na papíře. Zkontrolujte položky a sazby.',
                    $computed, $paper,
                )];
            }
        } catch (MoneyFormatException) {
            return ['Celkovou částku konceptu nejde porovnat s dokladem — zkontrolujte ručně.'];
        }

        return [];
    }

    /**
     * Payload na řádek výsledku (V79): doklad + co z něj vzniklo. Limit velikosti
     * drží config; nadlimitní doklad se uloží BEZ obsahu, ale s přiznáním — řádek,
     * který mlčí, by vypadal jako řádek, který nic nezkrátil.
     *
     * @param array<string,mixed> $doc
     * @param list<string> $warnings
     */
    private function normalizedJson(array $doc, int $invoiceId, array $warnings): string
    {
        $payload = [
            'schema'   => 'myinvoice.purchase-import-batch.normalized/1',
            'document' => $doc,
            'applied'  => ['purchase_invoice_id' => $invoiceId, 'warnings' => $warnings],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $limit = (int) $this->config->get('purchase_invoice.batch_import.max_norm_json_bytes', 512 * 1024);
        if (strlen($json) > $limit) {
            $payload['document']  = null;
            $payload['truncated'] = 'doklad překročil limit normalized_json a nebyl uložen';
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $json;
    }
}
