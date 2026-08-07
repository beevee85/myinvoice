<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice\Attachment;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TrashGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DeleteAttachmentAction
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceAttachmentRepository $attachments,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $attId = (int) ($args['attId'] ?? 0);

        $invoice = $this->invoices->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        // FORK audit 2026-08-07: doklad v koši je read-only (0905).
        if (($blocked = TrashGuard::blockIfTrashed($invoice, $response)) !== null) {
            return $blocked;
        }

        $att = $this->attachments->find($attId, $id);
        if ($att === null) {
            return Json::error($response, 'not_found', 'Příloha nenalezena.', 404);
        }

        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $path = $this->attachments->pathFor($supplierId, $id, (string) $att['filename']);
        if (is_file($path)) {
            @unlink($path);
        }

        $this->attachments->delete($attId, $id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.attachment_deleted', $user['id'] ?? null, 'invoice', $id, [
            'attachment_id' => $attId,
            'original_name' => $att['original_name'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => $attId]);
    }
}
