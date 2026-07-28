<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\DocumentTrashService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/{id}/restore — obnova dokladu z koše.
 * Role: admin i účetní. Vrací se se stejným interním číslem.
 */
final class RestorePurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly DocumentTrashService $trash,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        if (empty($existing['deleted_at'])) {
            return Json::error($response, 'not_in_trash', 'Doklad není v koši.', 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') === 'readonly') {
            return Json::error($response, 'forbidden_role', 'Read-only role nemůže obnovovat doklady.', 403);
        }

        $this->trash->restorePurchaseInvoice(
            $existing,
            isset($user['id']) ? (int) $user['id'] : null,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );
        return Json::ok($response, ['ok' => true]);
    }
}
