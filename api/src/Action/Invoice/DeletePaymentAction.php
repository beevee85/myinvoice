<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TrashGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/invoices/{id}/payments/{paymentId} — smaže evidovanou platbu.
 *
 * Guardy řeší InvoicePaymentService: platbu s bankovní vazbou maž přes „Zrušit
 * spárování" ve výpisu; platbu s vystaveným daňovým dokladem až po jeho
 * smazání/stornu. Pokud po smazání platby přestane být doklad pokrytý, vrátí se
 * ze stavu 'paid' zpět (jako unmark-paid).
 */
final class DeletePaymentAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePaymentService $payments,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $paymentId = (int) ($args['paymentId'] ?? 0);

        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        // Doklad v koši je read-only (soft delete, 0905).
        if (($blocked = TrashGuard::blockIfTrashed($invoice, $response)) !== null) {
            return $blocked;
        }
        $payment = $this->payments->findPayment($paymentId);
        if ($payment === null || (int) $payment['invoice_id'] !== $id) {
            return Json::error($response, 'not_found', 'Platba nenalezena.', 404);
        }

        try {
            $result = $this->payments->deletePayment($paymentId);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'payment_locked', $e->getMessage(), 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.payment_deleted', $user['id'] ?? null, 'invoice', $id, [
            'payment_id' => $paymentId,
            'amount'     => $payment['amount'],
            'paid_on'    => $payment['paid_on'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'invoice'       => $this->repo->find($id),
            'payments'      => $this->payments->listFor($id),
            'became_unpaid' => $result['became_unpaid'],
            'remaining'     => $result['remaining'],
        ]);
    }
}
