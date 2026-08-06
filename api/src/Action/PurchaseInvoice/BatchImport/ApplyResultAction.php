<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice\BatchImport;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseBatchImport\BatchApply;
use MyInvoice\Service\PurchaseBatchImport\BatchLimitException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FORK (beevee85) — POST /api/purchase-invoices/batch-import/{id}/results/{resultId}/apply
 *
 * Jedno kliknutí = jeden koncept. Hromadné schválení neexistuje ani tady —
 * kdyby endpoint uměl seznam, tlačítko „přijmout vše" by byl jen frontend
 * problém a někdo by si ho dopsal.
 *
 * TOKEN SE TU NEŽÁDÁ. Token váže ODESLÁNÍ VÝSLEDKŮ na dávku (druhý faktor
 * pro externí nástroj); schvaluje ale ČLOVĚK v přihlášené session a jeho
 * identita jde do `created_by` konceptu. Žádat token znovu by nutilo
 * uživatele držet ho i po odeslání — prodloužená životnost tajemství bez
 * bezpečnostního zisku.
 */
final class ApplyResultAction
{
    /** reasonCode → HTTP status. Co tu není, je 409 — konflikt stavu. */
    private const STATUS_BY_REASON = [
        'batch_not_found'  => 404,
        'result_not_found' => 404,
        'no_user'          => 400,
        'unknown_vat_rate' => 422,
        'invalid_currency' => 422,
        'raw_unreadable'   => 500,
    ];

    public function __construct(
        private readonly BatchApply $apply,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $batchId    = (int) ($args['id'] ?? 0);
        $resultId   = (int) ($args['resultId'] ?? 0);

        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        try {
            $result = $this->apply->apply($batchId, $resultId, $supplierId, $userId, date('Y-m-d'));
        } catch (BatchLimitException $e) {
            return Json::error($response, $e->reasonCode(), $e->getMessage(),
                self::STATUS_BY_REASON[$e->reasonCode()] ?? 409);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.batch_import_applied', $userId,
            'purchase_invoice', $result['purchase_invoice_id'],
            [
                'batch_id'      => $batchId,
                'result_id'     => $resultId,
                'warning_count' => count($result['warnings']),
            ],
            $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'ok'                  => true,
            'purchase_invoice_id' => $result['purchase_invoice_id'],
            'warnings'            => $result['warnings'],
            'batch_status'        => $result['batch_status'],
        ], 201);
    }
}
