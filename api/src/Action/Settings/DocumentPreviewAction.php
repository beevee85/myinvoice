<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use Mpdf\Mpdf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * FORK (beevee85) REDESIGN F8: GET /api/settings/document-preview.pdf
 *
 * Živý náhled PDF dokladu na UKÁZKOVÝCH datech pro sekci Nastavení → Vzhled
 * dokladu. Nic nezapisuje — sample faktura se rendereru podstrčí přes
 * supplier_snapshot/client_snapshot/bank_snapshot (resolve* metody snapshot
 * preferují), takže jde přes identickou pipeline jako ostrý doklad, včetně
 * brandingu a QR platby.
 *
 * Query parametry (přepisují uložené hodnoty → náhled NEuložených změn):
 *   lang=cs|en, branding_profile_id=N, attribution=0|1, barcode=0|1,
 *   legal_text=..., paid=1 (ukázka štítku ZAPLACENO)
 */
final class DocumentPreviewAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoicePdfRenderer $renderer,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }

        $q = $request->getQueryParams();
        $locale = ((string) ($q['lang'] ?? 'cs')) === 'en' ? 'en' : 'cs';
        $isPaid = ($q['paid'] ?? '') === '1';

        // Živý supplier jako základ snapshotu + přepisy z query (náhled neuložených změn)
        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$supplier) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }
        // Country jména si renderer bere ze snapshotu — doplň z countries
        $co = $this->db->pdo()->prepare('SELECT iso2, name_cs, name_en FROM countries WHERE id = ?');
        $co->execute([(int) ($supplier['country_id'] ?? 0)]);
        if ($c = $co->fetch(\PDO::FETCH_ASSOC)) {
            $supplier['country_iso2'] = $c['iso2'];
            $supplier['country_name_cs'] = $c['name_cs'];
            $supplier['country_name_en'] = $c['name_en'];
        }
        if (isset($q['attribution'])) $supplier['pdf_attribution_enabled'] = $q['attribution'] === '1' ? 1 : 0;
        if (isset($q['barcode'])) $supplier['pdf_barcode_enabled'] = $q['barcode'] === '1' ? 1 : 0;
        if (array_key_exists('legal_text', $q)) $supplier['pdf_legal_text'] = trim((string) $q['legal_text']);

        // Bankovní účet: účet dodavatele pro CZK (currencies), fallback ukázkový
        $bank = null;
        $bk = $this->db->pdo()->prepare(
            "SELECT account_number, bank_code, bank_name, iban, bic FROM currencies
              WHERE supplier_id = ? AND code = 'CZK' LIMIT 1"
        );
        try {
            $bk->execute([$sid]);
            $bank = $bk->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable) {
            $bank = null;
        }
        if (!$bank || (empty($bank['account_number']) && empty($bank['iban']))) {
            $bank = [
                'account_number' => '123456789',
                'bank_code'      => '0100',
                'bank_name'      => 'Komerční banka',
                'iban'           => 'CZ6501000000000123456789',
                'bic'            => 'KOMBCZPP',
            ];
        }

        $isVatPayer = !empty($supplier['is_vat_payer']);
        // Ukázkové položky: dvě sazby (21/12) u plátce, u neplátce prosté částky
        $items = [
            ['description' => $locale === 'en' ? 'Web application development — July' : 'Vývoj webové aplikace — červenec',
             'quantity' => 32, 'unit' => 'h', 'unit_price_without_vat' => 1200.0,
             'vat_rate_snapshot' => 21.0, 'total_without_vat' => 38400.0, 'total_with_vat' => 46464.0, 'item_kind' => 'work'],
            ['description' => $locale === 'en' ? 'Technical documentation (print)' : 'Technická dokumentace (tisk)',
             'quantity' => 2, 'unit' => 'ks', 'unit_price_without_vat' => 850.0,
             'vat_rate_snapshot' => 12.0, 'total_without_vat' => 1700.0, 'total_with_vat' => 1904.0, 'item_kind' => 'work'],
            ['description' => $locale === 'en' ? 'Domain and hosting (12 months)' : 'Doména a hosting (12 měsíců)',
             'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 2400.0,
             'vat_rate_snapshot' => 21.0, 'total_without_vat' => 2400.0, 'total_with_vat' => 2904.0, 'item_kind' => 'work'],
            ['description' => $locale === 'en' ? 'Consultation beyond scope' : 'Konzultace nad rámec zadání',
             'quantity' => 3.5, 'unit' => 'h', 'unit_price_without_vat' => 1400.0,
             'vat_rate_snapshot' => 21.0, 'total_without_vat' => 4900.0, 'total_with_vat' => 5929.0, 'item_kind' => 'work'],
        ];
        $withoutVat = 47400.0;
        $vat = $isVatPayer ? 9801.0 : 0.0;
        $withVat = $isVatPayer ? 57201.0 : 47400.0;
        $breakdown = $isVatPayer ? [
            ['rate' => 21.0, 'base' => 45700.0, 'vat' => 9597.0],
            ['rate' => 12.0, 'base' => 1700.0, 'vat' => 204.0],
        ] : [];
        if (!$isVatPayer) {
            foreach ($items as &$it) { $it['vat_rate_snapshot'] = 0.0; $it['total_with_vat'] = $it['total_without_vat']; }
            unset($it);
        }

        $invoice = [
            'id'                  => 0,
            'supplier_id'         => $sid,
            'status'              => $isPaid ? 'paid' : 'issued',
            'invoice_type'        => 'invoice',
            'varsymbol'           => date('Y') . '0142',
            'currency'            => 'CZK',
            'currency_id'         => null,
            'language'            => $locale,
            'issue_date'          => date('Y-m-d'),
            'tax_date'            => date('Y-m-d'),
            'due_date'            => date('Y-m-d', strtotime('+14 days')),
            'paid_at'             => $isPaid ? date('Y-m-d') : null,
            'payment_method'      => 'bank_transfer',
            'reverse_charge'      => 0,
            'prices_include_vat'  => 0,
            'project_name'        => $locale === 'en' ? 'Sample project' : 'Vzorová zakázka',
            'project_number'      => 'OBJ-2026-077',
            'contract_number'     => null,
            'note_above_items'    => null,
            'note_below_items'    => null,
            'items'               => $items,
            'vat_breakdown'       => $breakdown,
            'totals'              => ['without_vat' => $withoutVat, 'vat' => $vat, 'with_vat' => $withVat],
            'advance_paid_amount' => 0,
            'amount_to_pay'       => $withVat,
            'paid_total'          => $isPaid ? $withVat : 0,
            'czk_recap'           => null,
            'parent_invoice_id'   => null,
            'branding_profile_id' => isset($q['branding_profile_id']) && $q['branding_profile_id'] !== ''
                ? (int) $q['branding_profile_id'] : null,
            'supplier_snapshot'   => $supplier,
            'client_snapshot'     => [
                'company_name'  => $locale === 'en' ? 'Sample Client Ltd.' : 'Vzorový klient s.r.o.',
                'street'        => 'Dlouhá 123/4',
                'city'          => 'Praha 1',
                'zip'           => '110 00',
                'ic'            => '12345678',
                'dic'           => $isVatPayer ? 'CZ12345678' : null,
                'country_iso2'  => 'CZ',
                'country_name_cs' => 'Česká republika',
                'country_name_en' => 'Czech Republic',
            ],
            'bank_snapshot'       => $bank,
        ];

        // Render přes identickou pipeline jako ostrý doklad (bez ISDOC a výkazů)
        try {
            $html = $this->renderer->renderHtml($invoice, includeCss: true, hasIsdocAttachment: false, includeWorkReport: false);
            $tmpDir = RuntimePaths::storage('mpdf-temp');
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
            $mpdf = new Mpdf([
                'mode'          => 'utf-8',
                'format'        => 'A4',
                'margin_top'    => 14,
                'margin_bottom' => 18,
                'margin_left'   => 16,
                'margin_right'  => 16,
                'tempDir'       => $tmpDir,
                'autoPageBreak' => true,
                ...MpdfFontConfig::options(),
            ]);
            $mpdf->WriteHTML($html);
            $bytes = $mpdf->OutputBinaryData();
        } catch (\Throwable $e) {
            return Json::error($response, 'preview_failed', $e->getMessage(), 500);
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="nahled-dokladu.pdf"')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody(new Stream($stream));
    }
}
