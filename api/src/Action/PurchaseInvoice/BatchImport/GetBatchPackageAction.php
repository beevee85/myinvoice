<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice\BatchImport;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\PurchaseBatchImport\BatchBuilder;
use MyInvoice\Service\PurchaseBatchImport\BatchLimitException;
use MyInvoice\Service\PurchaseBatchImport\PromptBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use ZipArchive;

/**
 * FORK (beevee85) — GET /api/purchase-invoices/batch-import/{id}/package
 *
 * Balíček pro lokální extrakci: PDF pod jmény `<sha256>.pdf`, `manifest.json`
 * a `prompt.txt`. Extrakce adresuje doklady VÝHRADNĚ přes sha256 — proto jsou
 * v balíčku soubory pod obsahovým jménem, ne pod uživatelským.
 *
 * MANIFEST V BALÍČKU NENÍ KOPIE Z PAMĚTI, ALE REKONSTRUKCE OVĚŘENÁ PROTI
 * ULOŽENÉMU HASHI (BatchBuilder::packageContents). Kdyby někdo po založení
 * dávky sáhl do řádků `files`, balíček se NEVYDÁ — proti témuž hashi se
 * později ověřuje results.json (V4) a vydat balíček s jiným manifestem by
 * znamenalo poslat uživatele vstříc jistému selhání validace.
 *
 * TOKEN V BALÍČKU NENÍ. Balíček může ležet na disku, sdílet se s nástrojem,
 * zálohovat se — token je druhý faktor a patří jen do rukou toho, kdo dávku
 * založil.
 */
final class GetBatchPackageAction
{
    public function __construct(
        private readonly BatchBuilder $builder,
        private readonly PromptBuilder $prompt,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $batchId    = (int) ($args['id'] ?? 0);

        try {
            $contents = $this->builder->packageContents($batchId, $supplierId);
            $prompt   = $this->prompt->build($batchId, $supplierId);
        } catch (BatchLimitException $e) {
            // 404 i pro cizí dávku — 403 by prozradilo, že existuje.
            if ($e->reasonCode() === 'batch_not_found') {
                return Json::error($response, 'batch_not_found', 'Dávka nenalezena.', 404);
            }

            return Json::error($response, $e->reasonCode(), $e->getMessage(), 500);
        }

        // POZOR NA VZOR `tempnam() . '.zip'`, který má zbytek repa: placeholder
        // vytvořený tempnam (bez přípony) se pak nikdy nesmaže a každé stažení
        // nechá v temp adresáři jeden soubor navždy (nález review). Tady se
        // placeholder drží a maže spolu se ZIPem.
        $tmpBase = tempnam(sys_get_temp_dir(), 'batch-pkg-');
        if ($tmpBase === false) {
            return Json::error($response, 'zip_failed', 'Nelze vytvořit dočasný soubor.', 500);
        }
        $tmpZip = $tmpBase . '.zip';
        $cleanup = static function () use ($tmpBase, $tmpZip): void {
            if (is_file($tmpZip)) {
                @unlink($tmpZip);
            }
            if (is_file($tmpBase)) {
                @unlink($tmpBase);
            }
        };

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $cleanup();

            return Json::error($response, 'zip_failed', 'Nelze vytvořit ZIP.', 500);
        }

        foreach ($contents['files'] as $f) {
            $stored = (string) $f['stored_name'];
            // Jméno v ZIPu = jméno na disku = <sha256>.pdf. Žádný uživatelský
            // vstup, žádný zip-slip — jméno je odvozené z obsahu.
            $abs = $contents['dir'] . DIRECTORY_SEPARATOR . $stored;
            if (!is_file($abs)) {
                $zip->close();
                $cleanup();

                // Soubor v DB, ale ne na disku — to je porucha úložiště, ne 404.
                return Json::error($response, 'file_missing',
                    'Soubor dávky chybí na disku — balíček nelze sestavit celý.', 500);
            }
            $zip->addFile($abs, $stored);
        }

        $zip->addFromString('manifest.json', $contents['manifest_json']);
        $zip->addFromString('prompt.txt', (string) $prompt['prompt']);
        $zip->close();

        $size = filesize($tmpZip);
        $fp = fopen($tmpZip, 'rb');
        if ($fp === false) {
            $cleanup();

            return Json::error($response, 'zip_failed', 'Nelze otevřít ZIP ke streamu.', 500);
        }
        // Streamem z disku, ne přes paměť — dávka smí mít až 200 MiB.
        $stream = new Stream($fp);
        register_shutdown_function($cleanup);

        return $response
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition',
                'attachment; filename="myinvoice-davka-' . $batchId . '.zip"')
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Cache-Control', 'no-store');
    }
}
