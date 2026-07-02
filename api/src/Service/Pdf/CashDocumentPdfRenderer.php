<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Text\AmountInWordsCz;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * FORK (beevee85): PDF pokladního dokladu (PPD/VPD) — A5 na šířku, vzor
 * PaymentOrderPdfRenderer. Částka slovy přes AmountInWordsCz.
 */
final class CashDocumentPdfRenderer
{
    private ?Environment $twig = null;

    public function __construct(
        private readonly AmountInWordsCz $words,
    ) {}

    /**
     * @param array<string,mixed> $doc      řádek cash_documents (cast z CashDocumentAction)
     * @param array<string,mixed> $supplier company_name, street, city, zip, ic, dic
     */
    public function render(array $doc, array $supplier): string
    {
        $body = $this->twig()->render('cash-document.twig', [
            'doc'          => $doc,
            'supplier'     => $supplier,
            'is_income'    => ($doc['kind'] ?? '') === 'income',
            'amount_words' => $this->words->convert((float) ($doc['amount'] ?? 0), (string) ($doc['currency'] ?? 'CZK')),
        ]);

        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A5-L',
            'orientation'   => 'L',
            'margin_left'   => 12,
            'margin_right'  => 12,
            'margin_top'    => 12,
            'margin_bottom' => 12,
            'tempDir'       => $tmpDir,
            'autoPageBreak' => true,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle((string) ($doc['number'] ?? 'Pokladní doklad'));
        $mpdf->SetCreator('MyInvoice.cz');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $loader = new FilesystemLoader([
                Bootstrap::rootDir() . '/api/templates/cash-document',
            ]);
            $this->twig = new Environment($loader, [
                'autoescape'       => 'html',
                'strict_variables' => false,
                'cache'            => false,
            ]);
            $this->twig->addFilter(new \Twig\TwigFilter('cz_money', static function ($v) {
                return number_format((float) $v, 2, ',', ' ');
            }));
            $this->twig->addFilter(new \Twig\TwigFilter('cz_date', static function ($v) {
                if (!$v) {
                    return '';
                }
                try {
                    return (new \DateTimeImmutable((string) $v))->format('d.m.Y');
                } catch (\Throwable) {
                    return '';
                }
            }));
        }
        return $this->twig;
    }
}
