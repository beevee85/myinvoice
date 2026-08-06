<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseImportBatchRepository;
use PDO;

/**
 * FORK (beevee85) — Commit 13: příjem `results.json` a jeho zpracování.
 *
 * NEPOUŽÍVÁ `import_jobs`, ačkoli to PLAN.md §4 předpokládal. Důvod je změřený,
 * ne dojmový: `import_jobs.source` je ENUM
 * ('idoklad','fakturoid','pdf_isdoc_inbox','pdf_ai','monthly_export',
 *  'document_zip_import','document_zip_export','document_folder_import')
 * a přidání naší hodnoty by znamenalo ALTER na upstreamové tabulce — přesně
 * to, čemu se plán chtěl vyhnout (§11, minimum editovaných upstream souborů).
 * `purchase_import_batches` přitom stav, známku života i chybu už nese; je to
 * job záznam, jen pojmenovaný jinak.
 *
 * TOKEN JE DRUHÝ FAKTOR, NE AUTENTIZACE (A1). Volající už MUSÍ být přihlášený
 * a mít svého tenanta — token jen váže požadavek na konkrétní dávku. Proto se
 * ověřuje SPOLU s `supplier_id`, ne místo něj.
 *
 * VŠECHNO KONČÍ JAKO DRAFT. Tahle třída doklad nezakládá; ověří výsledek, uloží
 * ho a označí dávku. Zápis do účetnictví dělá až samostatný krok, který jde
 * přes `PurchaseInvoiceWriteService` — tedy stejnou cestou jako ruční pořízení,
 * ne kolem ní.
 */
final class ResultsIntake
{
    /** Stavy, ve kterých dávka výsledky přijímá. */
    private const ACCEPTING_STATUSES = ['pending', 'awaiting_results'];

    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseImportBatchRepository $repo,
        private readonly ResultsValidator $validator,
        private readonly BatchBuilder $builder,
    ) {}

    /**
     * @param string $rawResults nedůvěryhodný `results.json`
     * @param array{ic: string, dic: string} $tenant
     * @param string $today „YYYY-MM-DD", injektovaný (V84)
     * @return array{ok: bool, batch_id: int, findings: list<array<string,string>>}
     *
     * @throws BatchLimitException když dávku nelze přijmout vůbec
     */
    public function accept(
        int $batchId,
        int $supplierId,
        string $tokenSha256,
        string $rawResults,
        array $tenant,
        string $today,
    ): array {
        // 1. Token + tenant SPOLU. Shoda hashe sama nesmí stačit.
        $batch = $this->repo->findByToken($tokenSha256, $supplierId);
        if ($batch === null || (int) $batch['id'] !== $batchId) {
            throw new BatchLimitException('invalid_batch_token',
                'Token neplatí pro tuhle dávku, expiroval, nebo dávka patří jinému tenantovi.');
        }

        // 2. Stav. Dávku, která už skončila, nelze přijmout znovu — jinak by
        //    druhé odeslání přepsalo výsledek prvního.
        $status = (string) $batch['status'];
        if (!in_array($status, self::ACCEPTING_STATUSES, true)) {
            throw new BatchLimitException('batch_not_accepting',
                'Dávka už výsledky nepřijímá.');
        }

        // 3. Známka života — od téhle chvíle sweeper ví, že se na dávce pracuje.
        $this->repo->heartbeat($batchId, $supplierId);

        // 4. Validace. Manifest se čte z DATABÁZE, ne z požadavku — jinak by
        //    si odesílatel mohl manifest určit sám a kontrola V4/V6 by neměla
        //    proti čemu porovnávat.
        $files  = $this->repo->filesForBatch($batchId, $supplierId);
        $result = $this->validator->validate($rawResults, $files, $tenant, $today);

        // 5. Uložení. I neúspěšná validace se uloží — bez ní by uživatel
        //    v UI neviděl, PROČ dávka neprošla.
        $this->store($batchId, $supplierId, $files, $rawResults, $result);

        if ($result['ok']) {
            $this->repo->setStatus($batchId, $supplierId, 'validating');
        } else {
            $this->repo->setStatus($batchId, $supplierId, 'failed', 'validation_failed',
                sprintf('Validace nalezla %d problémů.', count($result['findings'])));
            // V79b, cesta A: terminální stav → raw_json I PDF pryč. Neúspěšná
            // dávka nemá důvod držet obsah cizích dokladů déle než ostatní.
            // (PDF mazání do review 6. 8. neexistovalo — retence byla poloviční.)
            $this->repo->purgeRawJson($batchId, $supplierId);
            $this->builder->purgeStoredFiles($batchId, $supplierId);
        }

        return ['ok' => $result['ok'], 'batch_id' => $batchId, 'findings' => $result['findings']];
    }

    /**
     * Sweeper opuštěných dávek (V79b, cesta B). Volá ho `api/bin/cron-cleanup.php`
     * — DO 6. 8. 2026 TO TENHLE KOMENTÁŘ JEN TVRDIL a žádný volající neexistoval;
     * review to našlo a zapojení je teď skutečné.
     *
     * Zametá VÝHRADNĚ dávky čekající na externí nástroj (pending/building/
     * awaiting_results). Dávka ve `validating`/`applying` čeká na ČLOVĚKA
     * a review konceptů deadline nemá — sweep by zbylé validované řádky
     * nevratně umrtvil (purge raw_json = apply už nemá z čeho číst).
     *
     * @return array{swept: int, purged: int, files: int}
     */
    public function sweepAbandoned(int $supplierId, int $olderThanHours): array
    {
        $swept = 0;
        $purged = 0;
        $files = 0;

        foreach ($this->repo->findAbandoned($supplierId, $olderThanHours) as $batch) {
            $id = (int) $batch['id'];
            $this->repo->setStatus($id, $supplierId, 'failed', 'abandoned',
                'Dávka nedokončila a nejeví známky života.');
            $purged += $this->repo->purgeRawJson($id, $supplierId);
            $files  += $this->builder->purgeStoredFiles($id, $supplierId);
            $swept++;
        }

        return ['swept' => $swept, 'purged' => $purged, 'files' => $files];
    }

    // -----------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $files
     * @param array{ok: bool, findings: list<array<string,string>>} $result
     */
    private function store(int $batchId, int $supplierId, array $files, string $raw, array $result): void
    {
        // Když selhala DÁVKA, nesmí žádný řádek tvrdit `validated` — i kdyby
        // sám žádný nález neměl. Nálezy V4 (soubor, který v dávce není) a V6
        // (chybějící soubor) se totiž k žádnému ZNÁMÉMU souboru nepřiřadí:
        // V4 ukazuje na cizí sha256, V6 na úroveň dávky. Bez tohohle by řádek
        // hlásil „validated" u dávky, která neprošla — tedy vypadal by hotově.
        $batchOk = (bool) $result['ok'];
        $byId = [];
        foreach ($files as $f) {
            $byId[(string) $f['sha256']] = (int) $f['id'];
        }

        // Nálezy se rozdělí podle dokladu, ať uživatel v UI vidí, který soubor
        // je vadný. Pointer má tvar „/documents/<index>/…", takže index vede
        // na pořadí v odpovědi — mapa sha256 → index se sestaví z payloadu.
        $shaByIndex = $this->documentShas($raw);

        $perFile = [];
        foreach ($result['findings'] as $f) {
            if (preg_match('#^/documents/(\d+)#', $f['pointer'], $m)) {
                $sha = $shaByIndex[(int) $m[1]] ?? null;
                if ($sha !== null) {
                    $perFile[$sha][] = $f;
                    continue;
                }
            }
            $perFile['__batch'][] = $f;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            foreach ($byId as $sha => $fileId) {
                $findings = $perFile[$sha] ?? [];
                $hasFail  = false;
                foreach ($findings as $f) {
                    if ($f['severity'] === 'fail') {
                        $hasFail = true;
                        break;
                    }
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO purchase_import_batch_results
                         (purchase_import_batch_id, purchase_import_batch_file_id, supplier_id,
                          status, raw_json, findings_json)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                          status = VALUES(status),
                          raw_json = VALUES(raw_json),
                          findings_json = VALUES(findings_json)'
                );
                $stmt->execute([
                    $batchId, $fileId, $supplierId,
                    ($hasFail || !$batchOk) ? 'rejected' : 'validated',
                    $raw,
                    json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Mapa index → sha256 z payloadu. Čte se TOLERANTNĚ: payload už prošel
     * validací, tohle je jen pro rozdělení nálezů. Když se nepodaří, nálezy
     * skončí na úrovni dávky, což je horší UX, ale ne chyba.
     *
     * @return array<int,string>
     */
    private function documentShas(string $raw): array
    {
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data) || !is_array($data['documents'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($data['documents'] as $i => $doc) {
            if (is_array($doc) && is_string($doc['sha256'] ?? null)) {
                $out[(int) $i] = $doc['sha256'];
            }
        }

        return $out;
    }
}
