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
 * DELETE /api/purchase-invoices/{id}/force — trvalé (nevratné) smazání.
 *
 * Jen admin, jen z koše (když je koš zapnutý). Tělo:
 *   { reason: string (min. 10 znaků), confirm_number: string, override?: bool }
 * confirm_number musí odpovídat internímu číslu dokladu (varsymbol).
 * Před smazáním se ukládá kompletní snapshot do deleted_document_snapshots.
 *
 * Chyby: 403 forbidden_role · 409 blocked_* / not_in_trash
 *        · 422 reason_required / confirm_number_mismatch
 */
final class ForceDeletePurchaseInvoiceAction
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
        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden_role', 'Trvale smazat doklad může pouze admin.', 403);
        }

        $settings = $this->trash->trashSettings($supplierId);
        if ($settings['enabled'] && empty($existing['deleted_at'])) {
            return Json::error(
                $response,
                'not_in_trash',
                'Trvale smazat lze jen doklad v koši. Nejdřív ho přesuňte do koše.',
                409,
            );
        }

        $body    = (array) $request->getParsedBody();
        $reason  = trim((string) ($body['reason'] ?? ''));
        $confirm = trim((string) ($body['confirm_number'] ?? ''));
        if (mb_strlen($reason) < 10) {
            return Json::error($response, 'reason_required', 'Uveďte důvod smazání (alespoň 10 znaků).', 422);
        }
        $varsymbol = (string) ($existing['varsymbol'] ?? '');
        if ($varsymbol !== '' && $confirm !== $varsymbol) {
            return Json::error(
                $response,
                'confirm_number_mismatch',
                'Opsané číslo dokladu nesouhlasí. Pro potvrzení opište přesně: ' . $varsymbol,
                422,
            );
        }

        $blockers = $this->policy->withoutOverridden(
            $this->policy->blockersForPurchaseInvoice($existing), !empty($body['override']), true,
        );
        if ($blockers !== []) {
            return Json::error($response, $blockers[0]['code'], $blockers[0]['message'], 409, [
                'blockers' => $blockers,
            ]);
        }

        $result = $this->trash->forceDeletePurchaseInvoice(
            $existing,
            isset($user['id']) ? (int) $user['id'] : null,
            $reason,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );
        return Json::ok($response, ['ok' => true] + $result);
    }
}
