<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MfaStepUpAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly MfaStepUpService $stepUp,
        private readonly TotpService $totp,
        private readonly SecretEncryption $crypto,
        private readonly BruteForceGuard $bruteForce,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function totp(Request $request, Response $response): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $sessionToken = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        if ($userId < 1 || $sessionToken === '') {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $operation = trim((string) ($body['operation'] ?? ''));
        try {
            $operation = $this->stepUp->assertAllowed($userId, $operation, 'totp');
        } catch (StepUpOperationException) {
            return Json::error($response, 'step_up_operation_invalid', 'Nepovolený účel ověření.', 400);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT totp_secret, totp_enabled FROM users WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([$userId]);
        $freshUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($freshUser === false
            || (int) $freshUser['totp_enabled'] !== 1
            || empty($freshUser['totp_secret'])
        ) {
            return Json::error($response, 'totp_unavailable', 'TOTP není pro uživatele aktivní.', 409);
        }
        if ($this->bruteForce->isTotpLocked($userId)) {
            return Json::error(
                $response,
                'too_many_attempts',
                'Příliš mnoho TOTP pokusů. Zkus to později.',
                429,
            );
        }

        $code = trim((string) ($body['code'] ?? ''));
        try {
            $secret = $this->crypto->decrypt((string) $freshUser['totp_secret']);
        } catch (\RuntimeException) {
            return Json::error($response, 'server_error', 'Chyba konfigurace serveru.', 500);
        }
        if (!$this->totp->verify($secret, $code)) {
            $this->bruteForce->recordTotpFailure($userId);
            return Json::error($response, 'invalid_code', 'Neplatný TOTP kód.', 401);
        }

        $this->bruteForce->recordTotpSuccess($userId);
        $proofToken = $this->stepUp->issue($userId, $sessionToken, $operation, 'totp');
        $this->logger->log(
            'auth.totp_step_up',
            $userId,
            'user',
            $userId,
            ['operation' => $operation],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, ['step_up_token' => $proofToken]);
    }
}
