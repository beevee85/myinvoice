<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\DocumentTrashPolicy;
use MyInvoice\Service\Invoice\DocumentTrashService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/purchase-invoices/{id} — přesun do koše (soft delete).
 *
 * Nahrazuje dřívější „jen draft / force=1" mazání: chybné doklady (typicky
 * z AI importu) jdou do koše bez ohledu na stav, pokud neprojdou blokující
 * pravidla (DPH období, vazby, export). Trvalé smazání je zvlášť
 * (ForceDeletePurchaseInvoiceAction, jen admin, jen z koše).
 *
 * Tělo: { reason: string (povinné, min. 10 znaků), override?: bool }
 * Role: admin i účetní. Koš vypnutý v Nastavení → rovnou trvalé smazání
 * (drafty smí i účetní, ostatní stavy jen admin).
 *
 * Chyby: 403 forbidden_role · 409 blocked_* / already_in_trash · 422 reason_required
 */
final class DeletePurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly DocumentTrashPolicy $policy,
        private readonly DocumentTrashService $trash,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }
        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        if (!empty($existing['deleted_at'])) {
            return Json::error($response, 'already_in_trash', 'Doklad už je v koši.', 409);
        }

        $user    = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $role    = (string) ($user['role'] ?? '');
        $isAdmin = $role === 'admin';
        if ($role === 'readonly') {
            return Json::error($response, 'forbidden_role', 'Read-only role nemůže mazat.', 403);
        }

        $body     = (array) $request->getParsedBody();
        $reason   = trim((string) ($body['reason'] ?? ''));
        $override = !empty($body['override']);
        if (mb_strlen($reason) < 10) {
            return Json::error($response, 'reason_required', 'Uveďte důvod smazání (alespoň 10 znaků).', 422);
        }

        $blockers = $this->policy->withoutOverridden(
            $this->policy->blockersForPurchaseInvoice($existing), $override, $isAdmin,
        );
        if ($blockers !== []) {
            return Json::error($response, $blockers[0]['code'], $blockers[0]['message'], 409, [
                'blockers' => $blockers,
            ]);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua = $request->getHeaderLine('User-Agent');
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        $settings = $this->trash->trashSettings($supplierId);
        if (!$settings['enabled']) {
            if (($existing['status'] ?? '') !== 'draft' && !$isAdmin) {
                return Json::error(
                    $response,
                    'forbidden_role',
                    'Trvale smazat vystavený doklad může jen admin (koš je vypnutý).',
                    403,
                );
            }
            $result = $this->trash->forceDeletePurchaseInvoice($existing, $userId, $reason, $ip, $ua);
            return Json::ok($response, ['ok' => true, 'hard_deleted' => true] + $result);
        }

        $this->trash->trashPurchaseInvoice($existing, $userId, $reason, $ip, $ua);
        return Json::ok($response, ['ok' => true, 'hard_deleted' => false]);
    }
}
