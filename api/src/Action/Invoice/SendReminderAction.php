<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TrashGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\ReminderService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SendReminderAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ReminderService $reminders,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        // Doklad v koši je read-only (soft delete, 0905).
        if (($blocked = TrashGuard::blockIfTrashed($invoice, $response)) !== null) {
            return $blocked;
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        try {
            $result = $this->reminders->send($id, $userId, $ip, $request->getHeaderLine('User-Agent'));
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_state', $e->getMessage(), 409);
        } catch (\Throwable $e) {
            return Json::error($response, 'send_failed', 'Upomínku se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        return Json::ok($response, [
            'invoice'      => $this->repo->find($id),
            'sent_to'      => $result['sent_to'],
            'days_overdue' => $result['days_overdue'],
            'sent_at'      => date('Y-m-d H:i:s'),
        ]);
    }
}
