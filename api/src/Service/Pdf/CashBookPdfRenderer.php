<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * FORK 0924 (H4): PDF pokladní knihy — A4 na výšku, chronologické pohyby
 * s průběžným zůstatkem, počáteční/konečný zůstatek, hlavička s firmou
 * a obdobím, patička se stránkováním (vzor CashDocumentPdfRenderer).
 */
final class CashBookPdfRenderer
{
    private ?Environment $twig = null;

    /**
     * @param array<string,mixed> $book     výstup CashRegisterAction::buildBook
     * @param array<string,mixed> $supplier company_name, street, city, zip, ic, dic
     */
    public function render(array $book, array $supplier): string
    {
        $body = $this->twig()->render('cash-book.twig', [
            'book'         => $book,
            'supplier'     => $supplier,
            'generated_at' => (new \DateTimeImmutable('now'))->format('d.m.Y H:i'),
        ]);

        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_left'   => 12,
            'margin_right'  => 12,
            'margin_top'    => 14,
            'margin_bottom' => 16,
            'tempDir'       => $tmpDir,
            'autoPageBreak' => true,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('Pokladní kniha ' . (string) ($book['register']['name'] ?? ''));
        $mpdf->SetCreator('MyInvoice.cz');
        // Patička „strana X z Y" — podklad pro kontrolu (Dokument 7 §12 vzor).
        $mpdf->SetHTMLFooter(
            '<div style="font-size:8pt; color:#666; text-align:center;">strana {PAGENO} z {nbpg}</div>'
        );
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
