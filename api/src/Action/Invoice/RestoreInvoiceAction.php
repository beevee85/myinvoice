<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\DocumentTrashService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/restore — obnova dokladu z koše.
 *
 * Role: admin i účetní. Doklad se vrátí do všech přehledů se stejným číslem —
 * řádek nikdy nezmizel, unikátní index čísla ho celou dobu držel obsazený.
 */
final class RestoreInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly DocumentTrashService $trash,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (empty($existing['deleted_at'])) {
            return Json::error($response, 'not_in_trash', 'Doklad není v koši.', 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') === 'readonly') {
            return Json::error($response, 'forbidden_role', 'Read-only role nemůže obnovovat doklady.', 403);
        }

        $this->trash->restoreInvoice(
            $existing,
            isset($user['id']) ? (int) $user['id'] : null,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );
        return Json::ok($response, ['ok' => true]);
    }
}
