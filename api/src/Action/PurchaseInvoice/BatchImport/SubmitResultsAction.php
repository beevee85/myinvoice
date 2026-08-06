<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice\BatchImport;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\PurchaseBatchImport\BatchLimitException;
use MyInvoice\Service\PurchaseBatchImport\ResultsIntake;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FORK (beevee85) — POST /api/purchase-invoices/batch-import/{id}/results
 *
 * PROČ `batch-import` A NE `import-batches`: upstream už má
 * `GET /api/purchase-invoices/import-batches` pro ÚPLNĚ JINÝ koncept — dohledání
 * dávky hromadného AI importu (#232). Sdílet s ním cestu by byla táž chyba,
 * kterou A3 zamítlo na úrovni databáze: dva různé významy pod jedním jménem.
 * Prefix `/api/purchase-invoices/` zůstává, takže `RoleMiddleware`,
 * `SupplierScopeMiddleware` i `ApiScopeMiddleware` fungují beze změny (A1, O-2)
 * a neupravuje se ani jeden middleware.
 *
 * TOKEN JE DRUHÝ FAKTOR. Tahle akce je ZA `AuthMiddleware`, takže volající už
 * je přihlášený a má svého tenanta. Token jen váže požadavek na konkrétní dávku;
 * kdyby autentizoval, byl by to pátý přihlašovací mechanismus a sáhli bychom
 * do čtyř upstream middlewarů.
 */
final class SubmitResultsAction
{
    /** `results.json` větší než tohle nemá smysl ani číst (V79). */
    private const MAX_BODY_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly ResultsIntake $intake,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $batchId    = (int) ($args['id'] ?? 0);

        if ($batchId <= 0) {
            return Json::error($response, 'invalid_batch', 'Neplatné id dávky.', 400);
        }

        $token = trim((string) ($request->getHeaderLine('X-Batch-Token') ?: ''));
        if ($token === '') {
            return Json::error($response, 'missing_batch_token',
                'Chybí hlavička X-Batch-Token.', 400);
        }

        // Tělo se čte jako SYROVÝ TEXT, ne přes parsované body. Duplicitní klíče
        // by jinak Slim zahodil dřív, než je StrictJson uvidí (V80) — a právě
        // ty jsou ta nejzajímavější část nepřátelského vstupu.
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return Json::error($response, 'empty_body', 'Prázdné tělo požadavku.', 400);
        }
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return Json::error($response, 'body_too_large',
                'Tělo požadavku překračuje povolený limit.', 413);
        }

        $tenant = $this->tenantOf($supplierId);

        try {
            $result = $this->intake->accept(
                $batchId,
                $supplierId,
                hash('sha256', $token),
                $raw,
                $tenant,
                date('Y-m-d'),
            );
        } catch (BatchLimitException $e) {
            // 403 u tokenu, 409 u stavu — rozlišení má význam pro klienta:
            // první znamená „nemáš právo", druhé „už se to stalo".
            $status = $e->reasonCode() === 'invalid_batch_token' ? 403 : 409;

            return Json::error($response, $e->reasonCode(), $e->getMessage(), $status);
        }

        // Neúspěšná validace NENÍ chyba serveru ani požadavku — je to výsledek.
        // Vrací se 200 s `ok: false` a nálezy, aby je UI mohlo zobrazit.
        return Json::ok($response, [
            'ok'       => $result['ok'],
            'batch_id' => $result['batch_id'],
            'findings' => $result['findings'],
        ]);
    }

    /**
     * Identita tenanta se načítá z databáze, ne z atributu požadavku:
     * `SupplierScopeMiddleware` nese jen `supplier.current_id`, celý záznam ne.
     * Ověřeno čtením middlewaru, ne předpokladem.
     *
     * @return array{ic: string, dic: string}
     */
    private function tenantOf(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic, dic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'ic'  => (string) ($row['ic'] ?? ''),
            'dic' => (string) ($row['dic'] ?? ''),
        ];
    }
}
