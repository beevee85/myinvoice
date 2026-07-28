<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\DocumentTrashPolicy;
use MyInvoice\Service\Invoice\DocumentTrashService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/trash/empty — vysypání koše vydaných faktur (jen admin).
 *
 * Tělo: { reason: string (min. 10 znaků) }
 *
 * Každý doklad projde znovu blokujícími pravidly; blokované se přeskočí
 * (nespadne celá dávka) a vrátí se v `skipped` s důvodem. Každé smazání
 * zanechá vlastní audit záznam + snapshot; navrch souhrnný invoice.trash_emptied.
 */
final class EmptyInvoiceTrashAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $repo,
        private readonly DocumentTrashPolicy $policy,
        private readonly DocumentTrashService $trash,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden_role', 'Vysypat koš může pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);

        $body   = (array) $request->getParsedBody();
        $reason = trim((string) ($body['reason'] ?? ''));
        if (mb_strlen($reason) < 10) {
            return Json::error($response, 'reason_required', 'Uveďte důvod smazání (alespoň 10 znaků).', 422);
        }

        $st = $this->db->pdo()->prepare(
            'SELECT id FROM invoices WHERE supplier_id = ? AND deleted_at IS NOT NULL ORDER BY id'
        );
        $st->execute([$supplierId]);
        $ids = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua = $request->getHeaderLine('User-Agent');
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = $this->repo->find($id);
            if ($row === null) {
                continue;
            }
            $blockers = $this->policy->blockersForInvoice($row);
            if ($blockers !== []) {
                $skipped[] = [
                    'id'        => $id,
                    'varsymbol' => $row['varsymbol'] ?? null,
                    'blockers'  => $blockers,
                ];
                continue;
            }
            $this->trash->forceDeleteInvoice($row, $userId, $reason, $ip, $ua);
            $deleted[] = ['id' => $id, 'varsymbol' => $row['varsymbol'] ?? null];
        }

        $this->logger->log('invoice.trash_emptied', $userId, 'invoice', null, [
            'deleted' => count($deleted),
            'skipped' => count($skipped),
            'reason'  => $reason,
        ], $ip, $ua, $supplierId);

        return Json::ok($response, ['ok' => true, 'deleted' => $deleted, 'skipped' => $skipped]);
    }
}
