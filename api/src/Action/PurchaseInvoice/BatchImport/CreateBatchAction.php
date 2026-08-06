<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice\BatchImport;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseBatchImport\BatchBuilder;
use MyInvoice\Service\PurchaseBatchImport\BatchLimitException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * FORK (beevee85) — POST /api/purchase-invoices/batch-import
 *
 * Založení dávky z nahraných PDF. Tohle je JEDINÉ místo, kde vzniká token
 * dávky v čitelné podobě — vrací se v odpovědi PRÁVĚ JEDNOU a do databáze
 * jde jen jeho SHA-256. Kdo si ho neuloží, dávku znovu neodešle; to je
 * záměr, ne nedodělek — obnovitelný token by přestal být druhým faktorem.
 *
 * VALIDACI SOUBORŮ NEDĚLÁ AKCE, ALE BatchBuilder: magic bytes, limity,
 * dedup, all-or-nothing. Akce jen převádí PSR-7 uploady na cesty na disku
 * a mapuje `reasonCode` na HTTP status. Kontrola napsaná dvakrát by se
 * rozešla a platila by ta děravější.
 */
final class CreateBatchAction
{
    /** reasonCode → HTTP status. Co tu není, je 400 — chyba vstupu. */
    private const STATUS_BY_REASON = [
        'max_files'           => 413,
        'max_file_bytes'      => 413,
        'max_batch_bytes'     => 413,
        'storage_unavailable' => 500,
        'storage_write_failed'=> 500,
    ];

    public function __construct(
        private readonly BatchBuilder $builder,
        private readonly \MyInvoice\Repository\PurchaseImportBatchRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        $uploaded = $this->collectUploads($request);
        if ($uploaded === []) {
            return Json::error($response, 'no_files',
                'Žádný soubor nebyl odeslán (field name: files[]).', 400);
        }

        // PSR-7 upload → cesta na disku. BatchBuilder čte cesty, ne streamy —
        // soubory se přesunou do dočasného adresáře VEDLE cílového úložiště
        // (stejný filesystem, copy uvnitř builderu není přesun přes zařízení).
        $scratch = RuntimePaths::storage('purchase-import-batches')
            . DIRECTORY_SEPARATOR . '.incoming-' . bin2hex(random_bytes(8));
        if (!@mkdir($scratch, 0o750, true) && !is_dir($scratch)) {
            return Json::error($response, 'storage_unavailable', 'Úložiště nelze připravit.', 500);
        }

        try {
            $uploads = [];
            foreach ($uploaded as $i => $file) {
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    return Json::error($response, 'upload_failed',
                        sprintf('Nahrání souboru č. %d selhalo (kód %d).', $i + 1, $file->getError()), 400);
                }
                $tmp = $scratch . DIRECTORY_SEPARATOR . 'up-' . $i;
                try {
                    $file->moveTo($tmp);
                } catch (\Throwable) {
                    return Json::error($response, 'upload_failed',
                        sprintf('Soubor č. %d se nepodařilo uložit.', $i + 1), 500);
                }
                $uploads[] = [
                    'name' => (string) ($file->getClientFilename() ?? ''),
                    'path' => $tmp,
                ];
            }

            // Token: 32 náhodných bajtů. Plaintext existuje jen v téhle odpovědi;
            // v DB je od první chvíle pouze hash.
            $token = bin2hex(random_bytes(32));

            try {
                $built = $this->builder->build($supplierId, $userId > 0 ? $userId : null,
                    $uploads, hash('sha256', $token));
            } catch (BatchLimitException $e) {
                return Json::error($response, $e->reasonCode(), $e->getMessage(),
                    self::STATUS_BY_REASON[$e->reasonCode()] ?? 400);
            }
        } finally {
            // Dočasné soubory nepřežijí požadavek — ani při chybě. Uklízí se
            // explicitní výčet, žádný glob: mažeme jen to, co jsme si vytvořili.
            foreach ($uploads ?? [] as $u) {
                @unlink($u['path']);
            }
            @rmdir($scratch);
        }

        $batchId = (int) $built['batch_id'];
        $batch   = $this->repo->find($batchId, $supplierId) ?? [];

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        // Do logu jde POČET a id, nikdy jména souborů ani token.
        $this->logger->log('purchase_invoice.batch_import_created', $userId,
            'purchase_import_batch', $batchId,
            ['file_count' => count($uploads), 'total_bytes' => (int) ($batch['total_bytes'] ?? 0)],
            $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'ok'               => true,
            'batch_id'         => $batchId,
            'token'            => $token,
            'token_expires_at' => $batch['token_expires_at'] ?? null,
            'manifest_sha256'  => $built['manifest_sha256'],
            'file_count'       => (int) ($batch['file_count'] ?? count($uploads)),
            'total_bytes'      => (int) ($batch['total_bytes'] ?? 0),
        ], 201);
    }

    /**
     * Uploady z pole `files[]`, tolerantně i z `files` a vnořených polí.
     *
     * @return list<UploadedFileInterface>
     */
    private function collectUploads(Request $request): array
    {
        $out = [];
        $walk = static function ($node) use (&$walk, &$out): void {
            if ($node instanceof UploadedFileInterface) {
                $out[] = $node;
                return;
            }
            if (is_array($node)) {
                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };
        $walk($request->getUploadedFiles());

        return $out;
    }
}
