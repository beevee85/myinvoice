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
 * DELETE /api/invoices/{id}/force — trvalé (nevratné) smazání dokladu.
 *
 * Jen admin, jen z koše (když je koš zapnutý). Tělo:
 *   { reason: string (min. 10 znaků), confirm_number: string, override?: bool }
 * confirm_number musí přesně odpovídat číslu dokladu (ochrana proti překliku);
 * u konceptu bez čísla se nevyžaduje. Blokující pravidla se kontrolují znovu —
 * vazby mohly vzniknout, i když doklad ležel v koši (DPH podání za jeho období).
 *
 * Před smazáním se ukládá kompletní snapshot do deleted_document_snapshots.
 *
 * Chyby: 403 forbidden_role · 409 blocked_* / not_in_trash
 *        · 422 reason_required / confirm_number_mismatch
 */
final class ForceDeleteInvoiceAction
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

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden_role', 'Trvale smazat doklad může pouze admin.', 403);
        }

        $settings = $this->trash->trashSettings((int) $existing['supplier_id']);
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
            $this->policy->blockersForInvoice($existing), !empty($body['override']), true,
        );
        if ($blockers !== []) {
            return Json::error($response, $blockers[0]['code'], $blockers[0]['message'], 409, [
                'blockers' => $blockers,
            ]);
        }

        $result = $this->trash->forceDeleteInvoice(
            $existing,
            isset($user['id']) ? (int) $user['id'] : null,
            $reason,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );
        return Json::ok($response, ['ok' => true] + $result);
    }
}
