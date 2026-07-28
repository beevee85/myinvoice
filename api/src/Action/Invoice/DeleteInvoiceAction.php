<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\DocumentTrashPolicy;
use MyInvoice\Service\Invoice\DocumentTrashService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/invoices/{id} — přesun do koše (soft delete).
 *
 * Tři oddělené operace mazání (iDoklad/Vyfakturuj vzor):
 *   storno/dobropis (CancelInvoiceAction) → koš (tady) → trvalé smazání
 *   (ForceDeleteInvoiceAction, jen admin, jen z koše).
 *
 * Tělo: { reason: string (povinné, min. 10 znaků), override?: bool }
 *   override=true (jen admin) přebije přebitelné blokace (odesláno klientovi,
 *   exportováno). DPH období a vazby na jiné doklady přebít nejdou.
 *
 * Role: admin i účetní (readonly stopne RoleMiddleware). Když je koš v Nastavení
 * vypnutý, chová se jako trvalé smazání — pak drafty smí i účetní (historické
 * chování), ostatní stavy jen admin.
 *
 * Chyby: 403 forbidden_role · 409 blocked_* / already_in_trash · 422 reason_required
 */
final class DeleteInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly DocumentTrashPolicy $policy,
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
            $this->policy->blockersForInvoice($existing), $override, $isAdmin,
        );
        if ($blockers !== []) {
            return Json::error($response, $blockers[0]['code'], $blockers[0]['message'], 409, [
                'blockers' => $blockers,
            ]);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua = $request->getHeaderLine('User-Agent');
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        $settings = $this->trash->trashSettings((int) $existing['supplier_id']);
        if (!$settings['enabled']) {
            // Koš vypnutý → „Do koše" provádí rovnou trvalé smazání (plný dialog
            // i audit zůstávají). Non-draft smí jen admin (spec: hard = admin).
            if (($existing['status'] ?? '') !== 'draft' && !$isAdmin) {
                return Json::error(
                    $response,
                    'forbidden_role',
                    'Trvale smazat vystavený doklad může jen admin (koš je vypnutý).',
                    403,
                );
            }
            $result = $this->trash->forceDeleteInvoice($existing, $userId, $reason, $ip, $ua);
            return Json::ok($response, ['ok' => true, 'hard_deleted' => true] + $result);
        }

        $this->trash->trashInvoice($existing, $userId, $reason, $ip, $ua);
        return Json::ok($response, ['ok' => true, 'hard_deleted' => false]);
    }
}
