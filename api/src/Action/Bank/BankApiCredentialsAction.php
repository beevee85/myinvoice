<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BankApiCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\Api\FioApiClient;
use MyInvoice\Service\Bank\Api\FioApiException;
use MyInvoice\Service\Bank\Api\FioTransactionImporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FORK 0921: přímé napojení na banku (Fio) — správa tokenu, test spojení
 * a ruční stažení. Token se nikdy nevrací zpět do UI, jen příznak `has_token`.
 */
final class BankApiCredentialsAction
{
    public function __construct(
        private readonly BankApiCredentialRepository $repo,
        private readonly FioApiClient $api,
        private readonly FioTransactionImporter $importer,
        private readonly ActivityLogger $logger,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;

        return Json::ok($response, ['accounts' => $this->repo->listForSupplier($this->supplierId($request))]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid  = $this->supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $id = $this->repo->save($sid, (int) $args['currencyId'], $body);
        } catch (\PDOException $e) {
            // PDOException dědí z RuntimeException — bez této větve by se text
            // databázové chyby (jména tabulek a indexů) vrátil klientovi jako validace.
            throw $e;
        } catch (\RuntimeException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        // Token se do auditu NIKDY nepíše — jen fakt, že se měnil.
        $this->logger->log('bank.api_credentials_saved', null, 'bank_api_credential', $id, [
            'currency_id'   => (int) $args['currencyId'],
            'enabled'       => !empty($body['enabled']),
            'token_changed' => array_key_exists('token', $body) && trim((string) $body['token']) !== '',
        ]);

        return Json::ok($response, ['accounts' => $this->repo->listForSupplier($sid)]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        $this->repo->delete($sid, (int) $args['id']);
        $this->logger->log('bank.api_credentials_deleted', null, 'bank_api_credential', (int) $args['id'], []);

        return Json::ok($response, ['accounts' => $this->repo->listForSupplier($sid)]);
    }

    /** Ověří token jedním dotazem na krátké období — data se neukládají. */
    public function test(Request $request, Response $response, array $args): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $credential = $this->repo->findWithToken($this->supplierId($request), (int) $args['id']);
        if ($credential === null) {
            return Json::error($response, 'not_found', 'Napojení nebylo nalezeno nebo nemá token.', 404);
        }

        $to = new \DateTimeImmutable('today');
        try {
            $movements = $this->api->fetchPeriod((string) $credential['token'], $to->modify('-7 days'), $to);
        } catch (FioApiException $e) {
            return Json::ok($response, ['ok' => false, 'message' => $e->getMessage()]);
        }

        return Json::ok($response, [
            'ok'      => true,
            'message' => sprintf('Spojení funguje — za posledních 7 dní banka vrátila %d pohybů.', count($movements)),
        ]);
    }

    /** Ruční stažení („Stáhnout teď") — stejná cesta jako cron. */
    public function fetch(Request $request, Response $response): Response
    {
        if (!$this->admin($request, $response, $err)) return $err;
        $sid    = $this->supplierId($request);
        $result = $this->importer->importForSupplier($sid);
        $this->logger->log('bank.api_fetch', null, 'supplier', $sid, $result);

        return Json::ok($response, [
            'result'   => $result,
            'accounts' => $this->repo->listForSupplier($sid),
        ]);
    }

    private function admin(Request $request, Response $response, ?Response &$err): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);

            return false;
        }
        $err = null;

        return true;
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }
}
