<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\PurchaseImportBatchRepository;

/**
 * FORK (beevee85) — Commit 8: sestavení dávky a manifestu.
 *
 * Vezme nahrané soubory, ověří je proti limitům, uloží mimo webroot a založí
 * dávku v databázi. Výstupem je manifest — seznam souborů se `sha256`, proti
 * kterému se později ověřuje `results.json` (V4).
 *
 * CO SE SEM NEDOSTANE:
 *   - Uživatelovo jméno souboru NIKDY nejde na disk. Uloží se pod `sha256.pdf`.
 *     Originál se drží jen v databázi jako text. Tím padá celá třída útoků přes
 *     jméno (traversal, null byte, dvojitá přípona, jméno delší než limit FS)
 *     a nemusíme se spoléhat na to, že jsme sanitizaci napsali dobře (V7).
 *   - Soubor, který se netváří jako PDF SVÝM OBSAHEM. Přípona se nekontroluje
 *     vůbec — rozhoduje magic bytes (V1). `scan-inbox` dnes věří příponě, což
 *     je slabší.
 *
 * DETERMINISMUS (V85): manifest je seřazený podle `sha256` a neobsahuje čas ani
 * nic odvozeného od běhu. Táž dávka dá bajtově týž manifest — jinak by kontrola
 * `sha256` manifestu nebyla kontrola, ale loterie.
 */
final class BatchBuilder
{
    /** Magic bytes PDF. Rozhoduje obsah, ne přípona (V1). */
    private const PDF_MAGIC = '%PDF-';

    public function __construct(
        private readonly Config $config,
        private readonly PurchaseImportBatchRepository $repo,
    ) {}

    /**
     * @param list<array{name: string, path: string}> $uploads
     * @return array{batch_id: int, manifest: array<string,mixed>, manifest_sha256: string}
     *
     * @throws BatchLimitException při překročení limitu nebo nepřijatelném souboru
     */
    public function build(int $supplierId, ?int $userId, array $uploads, string $tokenSha256): array
    {
        $limits = $this->limits();

        if ($uploads === []) {
            throw new BatchLimitException('empty_batch', 'Dávka neobsahuje žádný soubor.');
        }
        if (count($uploads) > $limits['max_files']) {
            throw new BatchLimitException(
                'max_files',
                sprintf('Dávka smí mít nejvýš %d dokladů, dostal jsem %d.', $limits['max_files'], count($uploads)),
            );
        }

        // 1. Ověření VŠECH souborů PŘED jakýmkoli zápisem. Dávka je buď celá
        //    přijatelná, nebo se neuloží nic — poloviční dávka je horší než žádná.
        $checked   = [];
        $totalBytes = 0;
        $seenHashes = [];

        foreach ($uploads as $i => $up) {
            $entry = $this->inspect($up, $i, $limits);
            $totalBytes += $entry['byte_size'];

            if ($totalBytes > $limits['max_batch_bytes']) {
                throw new BatchLimitException(
                    'max_batch_bytes',
                    sprintf('Dávka překročila %d B.', $limits['max_batch_bytes']),
                );
            }
            // Dedup uvnitř dávky (V69). Databáze to hlídá unikátem, ale chceme
            // srozumitelnou chybu, ne SQLSTATE 23000 vyhozený uživateli do tváře.
            if (isset($seenHashes[$entry['sha256']])) {
                throw new BatchLimitException(
                    'duplicate_in_batch',
                    sprintf('Soubory „%s" a „%s" jsou bajtově totožné.',
                        $seenHashes[$entry['sha256']], $entry['original_name']),
                );
            }
            $seenHashes[$entry['sha256']] = $entry['original_name'];
            $checked[] = $entry;
        }

        // 2. Teprve teď zápis.
        $batchId = $this->repo->create($supplierId, $userId, $tokenSha256, $this->tokenExpiry($limits));
        $dir     = $this->batchDir($batchId);

        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new BatchLimitException('storage_unavailable', 'Úložiště dávky nelze vytvořit.');
        }

        foreach ($checked as $entry) {
            // Jméno na disku je odvozené z obsahu, ne ze vstupu.
            $storedName = $entry['sha256'] . '.pdf';
            if (!@copy($entry['source_path'], $dir . DIRECTORY_SEPARATOR . $storedName)) {
                throw new BatchLimitException('storage_write_failed', 'Soubor dávky nelze uložit.');
            }
            $this->repo->addFile(
                $batchId, $supplierId,
                $entry['original_name'], $storedName,
                $entry['byte_size'], $entry['sha256'], 'application/pdf',
            );
        }

        $manifest = $this->manifest($batchId, $checked);
        $json     = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sha      = hash('sha256', $json);

        $this->repo->setManifest($batchId, $supplierId, $sha, count($checked), $totalBytes);

        return ['batch_id' => $batchId, 'manifest' => $manifest, 'manifest_sha256' => $sha];
    }

    // -----------------------------------------------------------------------

    /**
     * @param array{name: string, path: string} $up
     * @param array<string,int> $limits
     * @return array{original_name: string, source_path: string, byte_size: int, sha256: string}
     */
    private function inspect(array $up, int $index, array $limits): array
    {
        $path = $up['path'];

        if (!is_file($path) || !is_readable($path)) {
            throw new BatchLimitException('unreadable_file', sprintf('Soubor č. %d nelze přečíst.', $index + 1));
        }

        $size = (int) filesize($path);
        if ($size <= 0) {
            throw new BatchLimitException('empty_file', sprintf('Soubor č. %d je prázdný.', $index + 1));
        }
        if ($size > $limits['max_file_bytes']) {
            throw new BatchLimitException(
                'max_file_bytes',
                sprintf('Soubor č. %d má %d B, limit je %d B.', $index + 1, $size, $limits['max_file_bytes']),
            );
        }

        // V1 — rozhoduje OBSAH, ne přípona.
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new BatchLimitException('unreadable_file', sprintf('Soubor č. %d nelze otevřít.', $index + 1));
        }
        $head = (string) fread($fh, strlen(self::PDF_MAGIC));
        fclose($fh);

        if ($head !== self::PDF_MAGIC) {
            throw new BatchLimitException(
                'not_a_pdf',
                sprintf('Soubor č. %d není PDF (rozhoduje obsah, ne přípona).', $index + 1),
            );
        }

        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            throw new BatchLimitException('hash_failed', sprintf('Soubor č. %d nelze zahashovat.', $index + 1));
        }

        return [
            // Jméno se drží jen jako text v DB. Ořezáváme na délku sloupce a
            // vyhazujeme řídicí znaky — ne kvůli filesystemu (na disk nejde),
            // ale aby se nedostaly do reportu a logu.
            'original_name' => $this->safeLabel($up['name']),
            'source_path'   => $path,
            'byte_size'     => $size,
            'sha256'        => $sha,
        ];
    }

    private function safeLabel(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '') {
            $name = 'bez-nazvu.pdf';
        }

        return mb_substr($name, 0, 255);
    }

    /**
     * @param list<array{original_name: string, byte_size: int, sha256: string}> $files
     * @return array<string,mixed>
     */
    private function manifest(int $batchId, array $files): array
    {
        // Řazení podle sha256 — stabilní klíč nezávislý na pořadí uploadu (V85).
        usort($files, static fn (array $a, array $b) => strcmp($a['sha256'], $b['sha256']));

        return [
            'schema'   => 'myinvoice.purchase-import-batch.manifest/1',
            'batch_id' => $batchId,
            'files'    => array_map(static fn (array $f) => [
                'sha256'        => $f['sha256'],
                'byte_size'     => $f['byte_size'],
                'original_name' => $f['original_name'],
            ], $files),
        ];
    }

    private function batchDir(int $batchId): string
    {
        return RuntimePaths::storage('purchase-import-batches' . DIRECTORY_SEPARATOR . $batchId);
    }

    private function tokenExpiry(array $limits): string
    {
        return date('Y-m-d H:i:s', time() + ($limits['token_ttl_minutes'] * 60));
    }

    /** @return array<string,int> */
    private function limits(): array
    {
        $g = fn (string $k, int $d): int => (int) $this->config->get('purchase_invoice.batch_import.' . $k, $d);

        return [
            'max_files'         => $g('max_files', 50),
            'max_file_bytes'    => $g('max_file_bytes', 20 * 1024 * 1024),
            'max_batch_bytes'   => $g('max_batch_bytes', 200 * 1024 * 1024),
            'token_ttl_minutes' => $g('token_ttl_minutes', 120),
        ];
    }
}
