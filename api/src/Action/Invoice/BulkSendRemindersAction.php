<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\ReminderService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadně odešle upomínky na vybrané faktury.
 * Body: { "invoice_ids": [1,2,3] }
 * Faktury, které nesplní podmínky (paid, draft, není po splatnosti, chybí email),
 * skončí v `errors[]` — neúspěch jedné nezablokuje ostatní.
 */
final class BulkSendRemindersAction
{
    public function __construct(
        private readonly ReminderService $reminders,
        private readonly InvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = array_values(array_unique(array_map('intval', (array) ($body['invoice_ids'] ?? []))));

        if (empty($ids)) {
            return Json::error($response, 'no_invoices', 'Není vybrána žádná faktura.', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua = $request->getHeaderLine('User-Agent');

        $sent = [];
        $errors = [];
        foreach ($ids as $invId) {
            // Per-supplier ownership check — skip cizí faktury jako not_found
            $invoice = $this->repo->find($invId);
            if (!SupplierGuard::owns($request, $invoice)) {
                $errors[] = ['invoice_id' => $invId, 'error' => 'not_found'];
                continue;
            }
            // Doklad v koši (soft delete) se do dávky nebere.
            if (!empty($invoice['deleted_at'])) {
                $errors[] = ['invoice_id' => $invId, 'error' => 'in_trash'];
                continue;
            }
            try {
                $r = $this->reminders->send($invId, $userId, $ip, $ua);
                $sent[] = [
                    'invoice_id'   => $invId,
                    'sent_to'      => $r['sent_to'],
                    'days_overdue' => $r['days_overdue'],
                ];
            } catch (\DomainException $e) {
                $errors[] = ['invoice_id' => $invId, 'error' => $e->getMessage()];
            } catch (\Throwable $e) {
                $errors[] = ['invoice_id' => $invId, 'error' => 'send_failed: ' . $e->getMessage()];
            }
        }

        $this->logger->log('invoice.reminder_sent_bulk', $userId, null, null, [
            'requested'   => count($ids),
            'sent_count'  => count($sent),
            'error_count' => count($errors),
        ], $ip, $ua);

        return Json::ok($response, ['sent' => $sent, 'errors' => $errors]);
    }
}
