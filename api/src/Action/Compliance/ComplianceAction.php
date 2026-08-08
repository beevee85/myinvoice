<?php

declare(strict_types=1);

namespace MyInvoice\Action\Compliance;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ComplianceFlagRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FORK 0925 — přehled rizik (Dokument 7).
 *
 *   GET  /api/compliance/summary                — dlaždice + badge (jen NEODBAVENÉ)
 *   GET  /api/compliance/flags                  — seznam s filtry
 *   POST /api/compliance/flags/{id}/acknowledge — odbavení volbou (individuální;
 *        HROMADNÉ ODBAVENÍ ZÁMĚRNĚ NEEXISTUJE — Dokument 7 §10)
 *
 * Mazání příznaku neexistuje na žádné vrstvě (repo nemá delete metodu).
 */
final class ComplianceAction
{
    public function __construct(
        private readonly ComplianceFlagRepository $flags,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function summary(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->flags->summary($this->sid($request)));
    }

    public function list(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        return Json::ok($response, $this->flags->list($this->sid($request), [
            'severity'  => $q['severity'] ?? null,
            'status'    => $q['status'] ?? null,
            'type'      => $q['type'] ?? null,
            'client_id' => $q['client_id'] ?? null,
            'year'      => $q['year'] ?? null,
        ]));
    }

    public function acknowledge(Request $request, Response $response, array $args): Response
    {
        $sid = $this->sid($request);
        $id = (int) ($args['id'] ?? 0);
        $flag = $this->flags->find($sid, $id);
        if ($flag === null) {
            return Json::error($response, 'not_found', 'Příznak nenalezen.', 404);
        }
        $b = (array) ($request->getParsedBody() ?? []);
        $choice = trim((string) ($b['choice'] ?? ''));
        $note = trim((string) ($b['note'] ?? ''));
        if ($choice === '') {
            return Json::error($response, 'choice_required', 'Zvolte, jak pokračovat.', 400);
        }
        // Riziková volba vyžaduje zdůvodnění (Dokument 5 §4.2 — žádný předvyplněný text).
        if ($choice === 'accept_risk' && mb_strlen($note) < 10) {
            return Json::error($response, 'note_required', 'Volba „vzít riziko na vědomí" vyžaduje důvod (alespoň 10 znaků).', 400);
        }
        if ($choice === 'not_suspicious' && mb_strlen($note) < 50) {
            return Json::error($response, 'note_required', 'Posouzení „nejde o podezřelý obchod" vyžaduje zdůvodnění (alespoň 50 znaků).', 400);
        }
        $status = (string) ($b['status'] ?? '');
        if (!in_array($status, ['acknowledged', 'explained', 'resolved'], true)) {
            $status = $choice === 'not_suspicious' ? 'explained' : 'acknowledged';
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ok = $this->flags->acknowledge($sid, $id, (int) ($user['id'] ?? 0),
            (string) ($user['role'] ?? ''), $choice, $note !== '' ? $note : null, $status);
        if (!$ok) {
            return Json::error($response, 'not_acknowledgeable', 'Příznak už je vyřešený nebo zneaktuálněný.', 409);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('compliance_flag.acknowledged', $user['id'] ?? null, 'compliance_flag', $id, [
            'type' => $flag['type'], 'choice' => $choice, 'status' => $status,
            'note' => $note !== '' ? $note : null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->flags->find($sid, $id));
    }

    private function sid(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }
}
