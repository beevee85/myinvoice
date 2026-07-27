<?php

declare(strict_types=1);

/**
 * CLI: nouzově resetuje všechny MFA faktory uživatele podle e-mailu.
 *
 * Vypne TOTP, odvolá passkeys, zruší trusted devices, login OTP, rozpracované
 * WebAuthn ceremonies a step-up proofy. Invaliduje všechny session uživatele.
 *
 * Použití:
 *   php api/bin/reset-mfa.php admin@example.com
 */

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tento skript musí běžet z CLI.\n");
    exit(1);
}

[$_, $email] = array_pad($argv, 2, null);
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Použití: php api/bin/reset-mfa.php <email>\n");
    exit(2);
}

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$sessions = $container->get(\MyInvoice\Service\Auth\SessionManager::class);

$stmt = $pdo->prepare('SELECT id, email, totp_enabled FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "User '$email' neexistuje.\n");
    exit(3);
}

$pdo->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?')
    ->execute([(int) $user['id']]);

// E-mailové 2FA: zruš důvěryhodná zařízení (vynutí znovuověření) a čekající kódy.
$td = $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?');
$td->execute([(int) $user['id']]);
$otp = $pdo->prepare('DELETE FROM login_otps WHERE user_id = ?');
$otp->execute([(int) $user['id']]);
$passkeys = $pdo->prepare(
    'UPDATE webauthn_credentials
        SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6))
      WHERE user_id = ? AND revoked_at IS NULL'
);
$passkeys->execute([(int) $user['id']]);
$ceremonies = $pdo->prepare('DELETE FROM webauthn_ceremonies WHERE user_id = ?');
$ceremonies->execute([(int) $user['id']]);
$proofs = $pdo->prepare('DELETE FROM mfa_step_up_proofs WHERE user_id = ?');
$proofs->execute([(int) $user['id']]);

$killed = $sessions->destroyAllForUser((int) $user['id']);
$wasEnabled = ((int) ($user['totp_enabled'] ?? 0) === 1) ? 'ano' : 'ne';

$pdo->prepare(
    "INSERT INTO activity_log (user_id, action, entity_type, entity_id, payload)
     VALUES (?, 'auth.mfa_reset', 'user', ?, ?)"
)->execute([
    (int) $user['id'],
    (int) $user['id'],
    json_encode([
        'passkeys_revoked' => $passkeys->rowCount(),
        'sessions_invalidated' => $killed,
    ], JSON_THROW_ON_ERROR),
]);

echo "✓ MFA reset pro {$user['email']} (id={$user['id']}, TOTP původně aktivní: {$wasEnabled}).\n";
echo "  Odvoláno {$passkeys->rowCount()} passkeys, {$td->rowCount()} důvěryhodných zařízení, "
    . "{$otp->rowCount()} e-mailových kódů, {$ceremonies->rowCount()} flow, "
    . "{$proofs->rowCount()} proofů a $killed session(í).\n";
