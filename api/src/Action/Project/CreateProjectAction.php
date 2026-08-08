<?php

declare(strict_types=1);

namespace MyInvoice\Action\Project;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateProjectAction
{
    public function __construct(
        private readonly ProjectRepository $repo,
        private readonly ClientRepository $clients,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $errors = Validation::project($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // FORK 0923 (B2): klient je nepovinný. Je-li uveden, musí existovat
        // a patřit aktuálnímu supplierovi; jinak tenant určí middleware.
        $clientId = isset($body['client_id']) && (int) $body['client_id'] > 0 ? (int) $body['client_id'] : null;
        if ($clientId !== null && !SupplierGuard::owns($request, $this->clients->find($clientId))) {
            return Json::error($response, 'client_not_found', 'Klient neexistuje.', 400);
        }
        $body['supplier_id'] = SupplierGuard::currentId($request);

        try {
            $id = $this->repo->create($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('project.created', $user['id'] ?? null, 'project', $id, [
            'client_id' => $clientId,
            'name'      => $body['name'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id), 201);
    }
}
