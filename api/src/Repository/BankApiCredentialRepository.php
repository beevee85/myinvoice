<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tokeny pro přímé napojení na banku (zatím Fio) — jeden záznam na bankovní účet
 * (`currencies.id`). Token se ukládá šifrovaně a NIKDY neopouští backend: navenek
 * jde jen `has_token`.
 */
final class BankApiCredentialRepository
{
    public const PROVIDER_FIO = 'fio';

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $secrets,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Konfigurace všech účtů dodavatele — i těch dosud nenastavených, aby UI mohlo
     * nabídnout zapnutí u kteréhokoli účtu.
     *
     * @return list<array<string,mixed>>
     */
    public function listForSupplier(int $supplierId, string $provider = self::PROVIDER_FIO): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id AS currency_id, c.code AS currency, c.account_number, c.bank_code,
                    b.id, b.enabled, b.token_enc, b.last_fetched_on, b.last_external_id,
                    b.last_fetch_at, b.last_fetch_status, b.last_fetch_message
               FROM currencies c
          LEFT JOIN bank_api_credentials b
                 ON b.currency_id = c.id AND b.supplier_id = c.supplier_id AND b.provider = ?
              WHERE c.supplier_id = ? AND c.account_number IS NOT NULL AND c.account_number <> \'\'
           ORDER BY c.code, c.id'
        );
        $stmt->execute([$provider, $supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $row['id']          = $row['id'] !== null ? (int) $row['id'] : null;
            $row['currency_id'] = (int) $row['currency_id'];
            $row['enabled']     = (bool) ($row['enabled'] ?? false);
            $row['has_token']   = ($row['token_enc'] ?? null) !== null && $row['token_enc'] !== '';
            unset($row['token_enc']);

            return $row;
        }, $rows);
    }

    /**
     * Zapnuté účty s ROZŠIFROVANÝM tokenem — jen pro interní použití (cron, test).
     *
     * @return list<array<string,mixed>>
     */
    public function enabledForSupplier(int $supplierId, string $provider = self::PROVIDER_FIO): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM bank_api_credentials
              WHERE supplier_id = ? AND provider = ? AND enabled = 1 AND token_enc IS NOT NULL
           ORDER BY id'
        );
        $stmt->execute([$supplierId, $provider]);

        return array_values(array_filter(array_map(
            fn (array $row): ?array => $this->withToken($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        )));
    }

    /** @return array<string,mixed>|null */
    public function findWithToken(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM bank_api_credentials WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->withToken($row);
    }

    /**
     * Uloží konfiguraci účtu. Prázdný token ponechá dosavadní (vzor IMAP účtů),
     * takže UI nemusí token držet ani ho znovu zobrazovat.
     *
     * @param array<string,mixed> $body
     */
    public function save(int $supplierId, int $currencyId, array $body, string $provider = self::PROVIDER_FIO): int
    {
        $pdo     = $this->db->pdo();
        $owns    = $pdo->prepare('SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?');
        $owns->execute([$currencyId, $supplierId]);
        if ($owns->fetchColumn() === false) {
            throw new \RuntimeException('Bankovní účet nepatří tomuto dodavateli.');
        }

        $current = $pdo->prepare(
            'SELECT * FROM bank_api_credentials WHERE supplier_id = ? AND provider = ? AND currency_id = ?'
        );
        $current->execute([$supplierId, $provider, $currencyId]);
        $existing = $current->fetch(\PDO::FETCH_ASSOC) ?: null;

        $tokenEnc = $existing['token_enc'] ?? null;
        if (array_key_exists('token', $body) && trim((string) $body['token']) !== '') {
            $tokenEnc = $this->secrets->encrypt(trim((string) $body['token']));
        }
        $enabled = !empty($body['enabled']) ? 1 : 0;
        if ($enabled === 1 && ($tokenEnc === null || $tokenEnc === '')) {
            throw new \RuntimeException('Bez tokenu nelze stahování zapnout.');
        }

        if ($existing === null) {
            $pdo->prepare(
                'INSERT INTO bank_api_credentials (supplier_id, currency_id, provider, token_enc, enabled)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$supplierId, $currencyId, $provider, $tokenEnc, $enabled]);

            return (int) $pdo->lastInsertId();
        }

        $pdo->prepare('UPDATE bank_api_credentials SET token_enc = ?, enabled = ? WHERE id = ?')
            ->execute([$tokenEnc, $enabled, (int) $existing['id']]);

        return (int) $existing['id'];
    }

    public function delete(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM bank_api_credentials WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
    }

    /** Zapíše výsledek posledního stažení (kurzor + stav pro UI). */
    public function recordFetch(int $id, string $status, string $message, ?string $lastDate, ?string $lastExternalId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE bank_api_credentials
                SET last_fetch_at = NOW(), last_fetch_status = ?, last_fetch_message = ?,
                    last_fetched_on = COALESCE(?, last_fetched_on),
                    last_external_id = COALESCE(?, last_external_id)
              WHERE id = ?'
        )->execute([
            in_array($status, ['ok', 'error'], true) ? $status : 'error',
            mb_substr($message, 0, 500),
            $lastDate,
            $lastExternalId,
            $id,
        ]);
    }

    /** @return list<int> supplier_id všech dodavatelů se zapnutým stahováním */
    public function suppliersWithEnabled(string $provider = self::PROVIDER_FIO): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT supplier_id FROM bank_api_credentials
              WHERE provider = ? AND enabled = 1 AND token_enc IS NOT NULL ORDER BY supplier_id'
        );
        $stmt->execute([$provider]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>|null null = token nejde rozšifrovat (změněný klíč)
     */
    private function withToken(array $row): ?array
    {
        $enc = (string) ($row['token_enc'] ?? '');
        if ($enc === '') {
            return null;
        }
        try {
            $row['token'] = $this->secrets->decrypt($enc);
        } catch (\Throwable $e) {
            // Bez logu by účet jen tiše zmizel ze stahování a všechny ukazatele
            // by zůstaly zelené (typicky po výměně cfg.app.secret_encryption_key).
            $this->logger->error('Fio: token účtu nelze rozšifrovat — stahování je vypnuté.', [
                'credential_id' => (int) ($row['id'] ?? 0),
                'supplier_id'   => (int) ($row['supplier_id'] ?? 0),
                'error'         => $e->getMessage(),
            ]);

            return null;
        }
        unset($row['token_enc']);

        return $row;
    }
}
