<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * FORK (beevee85) — repozitář dávkového importu přijatých dokladů (migrace 0913).
 *
 * TENANT SCOPING JE POVINNÝ. Každá metoda bere `supplier_id` explicitně a žádná
 * neumí dotaz napříč tenanty — ani jako agregát. Není to opatrnost: analytický
 * skener měl `supplier_id` nepovinný s defaultem „všichni" a výsledná baseline
 * smíchala doklady dvou různých právnických osob. Číslo o neznámé populaci není
 * zjištění, je to zdroj chybných rozhodnutí.
 *
 * TOKEN se v databázi drží JEN jako SHA-256, nikdy v plaintextu. Není to
 * autentizace — je to druhý faktor uvnitř už ověřené session nebo PAT (A1/V63).
 *
 * OSOBNÍ ÚDAJE: `raw_json` a `normalized_json` nesou obsah cizích dokladů.
 * Nikdy se neloggují (V82) a mažou se podle retence (V79b) — `raw_json` při
 * dosažení terminálního stavu, `normalized_json` po uplynutí lhůty.
 */
final class PurchaseImportBatchRepository
{
    /** Stavy, ze kterých už dávka nikam nepokračuje — spouštěč retence, cesta A. */
    public const TERMINAL_STATUSES = ['done', 'failed', 'cancelled'];

    /** Stav při založení. Dokud worker nezapíše známku života, je dávka „bez života". */
    public const STATUS_PENDING = 'pending';

    public function __construct(private readonly Connection $db) {}

    // -----------------------------------------------------------------------
    // Zápis
    // -----------------------------------------------------------------------

    /**
     * Založí hlavičku dávky. Volá se z HTTP akce PŘED spawnem workeru, nikdy
     * z workeru samotného — neúspěšný spawn tak zůstane vidět jako řádek bez
     * známky života, tedy jako něco. Kdyby řádek zakládal worker, byl by
     * neúspěšný spawn vidět jako nic.
     */
    public function create(
        int $supplierId,
        ?int $userId,
        string $tokenSha256,
        string $tokenExpiresAt,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_import_batches
                 (supplier_id, created_by_user_id, status, token_sha256, token_expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $userId, self::STATUS_PENDING, $tokenSha256, $tokenExpiresAt]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function addFile(
        int $batchId,
        int $supplierId,
        string $originalName,
        string $storedName,
        int $byteSize,
        string $sha256,
        ?string $mimeType = null,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_import_batch_files
                 (purchase_import_batch_id, supplier_id, original_name, stored_name, byte_size, sha256, mime_type)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$batchId, $supplierId, $originalName, $storedName, $byteSize, $sha256, $mimeType]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Vrací počet dotčených řádků — 0 znamená cizí tenant nebo neexistující dávka. */
    public function setStatus(
        int $batchId,
        int $supplierId,
        string $status,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): int {
        $appliedAt = $status === 'done' ? 'current_timestamp()' : 'applied_at';
        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_import_batches
                SET status = ?, error_code = ?, error_message = ?, applied_at = {$appliedAt}
              WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$status, $errorCode, $errorMessage, $batchId, $supplierId]);

        return $stmt->rowCount();
    }

    /**
     * Známka života workeru. Strop opuštěné dávky se počítá odsud, ne od
     * `created_at` — jinak by sweeper smazal běžící dlouhou dávku.
     */
    public function heartbeat(int $batchId, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE purchase_import_batches
                SET heartbeat_at = current_timestamp()
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$batchId, $supplierId]);

        return $stmt->rowCount();
    }

    // -----------------------------------------------------------------------
    // Čtení
    // -----------------------------------------------------------------------

    public function find(int $batchId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, created_by_user_id, status, token_expires_at,
                    file_count, total_bytes, manifest_sha256, heartbeat_at,
                    error_code, error_message, created_at, updated_at, applied_at
               FROM purchase_import_batches
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$batchId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->cast($row);
    }

    /**
     * Ověření jednorázového tokenu. Tenant se ověřuje SPOLU s tokenem, ne až
     * potom — jinak by shoda hashe stačila k dohledání cizí dávky.
     */
    public function findByToken(string $tokenSha256, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, status, token_expires_at
               FROM purchase_import_batches
              WHERE token_sha256 = ? AND supplier_id = ?
                AND token_expires_at IS NOT NULL AND token_expires_at > current_timestamp()'
        );
        $stmt->execute([$tokenSha256, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->cast($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForTenant(int $supplierId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, status, file_count, total_bytes, created_at, applied_at
               FROM purchase_import_batches
              WHERE supplier_id = ?
              ORDER BY created_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute([$supplierId]);

        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function filesForBatch(int $batchId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, original_name, stored_name, byte_size, sha256, mime_type, page_count, has_text_layer
               FROM purchase_import_batch_files
              WHERE purchase_import_batch_id = ? AND supplier_id = ?
              ORDER BY id ASC'
        );
        $stmt->execute([$batchId, $supplierId]);

        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Dávky bez známky života starší než N hodin — vstup pro sweeper retence
     * (cesta B dle V79b). Nemůže být řízená stavem dávky: ke změně stavu
     * u opuštěné dávky z definice nedojde, právě proto je opuštěná.
     *
     * @return list<array<string,mixed>>
     */
    public function findAbandoned(int $supplierId, int $olderThanHours): array
    {
        $placeholders = implode(',', array_fill(0, count(self::TERMINAL_STATUSES), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, status, created_at, heartbeat_at
               FROM purchase_import_batches
              WHERE supplier_id = ?
                AND status NOT IN ({$placeholders})
                AND COALESCE(heartbeat_at, created_at) < (current_timestamp() - INTERVAL ? HOUR)
              ORDER BY id ASC"
        );
        $stmt->execute([$supplierId, ...self::TERMINAL_STATUSES, $olderThanHours]);

        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // -----------------------------------------------------------------------
    // Retence (V79b)
    // -----------------------------------------------------------------------

    /**
     * Smaže `raw_json` jedné dávky. Vrací počet dotčených řádků, aby volající
     * mohl zalogovat ROZSAH A POČET — nikdy obsah (V82).
     *
     * Omezeno na jednu dávku jednoho tenanta. Automatický úklid, který se
     * splete v rozsahu, je tichá ztráta cizích dat.
     */
    public function purgeRawJson(int $batchId, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE purchase_import_batch_results
                SET raw_json = NULL, raw_purged_at = current_timestamp()
              WHERE purchase_import_batch_id = ? AND supplier_id = ?
                AND raw_json IS NOT NULL'
        );
        $stmt->execute([$batchId, $supplierId]);

        return $stmt->rowCount();
    }

    /** Idempotentní: druhý běh nemá co mazat a vrátí 0. */
    public function purgeNormalizedJsonOlderThan(int $supplierId, int $days): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE purchase_import_batch_results
                SET normalized_json = NULL, normalized_purged_at = current_timestamp()
              WHERE supplier_id = ?
                AND normalized_json IS NOT NULL
                AND created_at < (current_timestamp() - INTERVAL ? DAY)'
        );
        $stmt->execute([$supplierId, $days]);

        return $stmt->rowCount();
    }

    // -----------------------------------------------------------------------

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'created_by_user_id', 'file_count', 'total_bytes',
                  'byte_size', 'page_count'] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null) {
                $row[$k] = (int) $row[$k];
            }
        }
        if (array_key_exists('has_text_layer', $row) && $row['has_text_layer'] !== null) {
            $row['has_text_layer'] = (bool) $row['has_text_layer'];
        }

        return $row;
    }
}
