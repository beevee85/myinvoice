<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Api;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankApiCredentialRepository;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Bank\StatementMatcher;
use PDO;

/**
 * Stažení pohybů z Fio API do `bank_transactions` a spuštění párování.
 *
 * Klouzavé okno: od nejnovějšího už uloženého pohybu mínus překryv (zpětná doúčtování
 * a storna přicházejí se zpožděním), nejdál ale 89 dní zpět — dál API historii nedá.
 * Opakované stažení téhož období je neškodné: deduplikace běží podle ID pohybu,
 * které je dle dokumentace unikátní a neopakuje se.
 *
 * Deduplikace je aplikační (SELECT před INSERT) — DB unikát na (source, source_ref)
 * repo vědomě nemá, protože na instalacích s historickými duplicitami blokoval upgrade
 * (migrace 0136/0139).
 */
final class FioTransactionImporter
{
    /** Překryv okna: kolik dnů zpět stahovat i to, co už (nejspíš) máme. */
    private const OVERLAP_DAYS = 7;

    public function __construct(
        private readonly Connection $db,
        private readonly FioApiClient $api,
        private readonly BankApiCredentialRepository $credentials,
        private readonly StatementMatcher $matcher,
        private readonly EmailNoticeReconciler $reconciler,
    ) {}

    /**
     * Stáhne pohyby pro všechny zapnuté účty dodavatele.
     *
     * @return array{created:int,skipped:int,matched:int,accounts:int,errors:list<string>}
     */
    public function importForSupplier(int $supplierId, ?\DateTimeImmutable $now = null): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'matched' => 0, 'accounts' => 0, 'errors' => []];

        foreach ($this->credentials->enabledForSupplier($supplierId) as $credential) {
            $result['accounts']++;
            try {
                $one = $this->importAccount($credential, $now);
                $result['created'] += $one['created'];
                $result['skipped'] += $one['skipped'];
                $result['matched'] += $one['matched'];
            } catch (\Throwable $e) {
                // Chyba jednoho účtu nesmí shodit stahování ostatních — a MUSÍ se
                // propsat do stavu, jinak by účet, kterému systematicky selhává
                // stahování, vypadal v UI i v cron reportu zdravě.
                $result['errors'][] = $e->getMessage();
                $this->credentials->recordFetch((int) $credential['id'], 'error', $e->getMessage(), null, null);
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $credential řádek `bank_api_credentials` vč. rozšifrovaného tokenu
     *
     * @return array{created:int,skipped:int,matched:int}
     */
    public function importAccount(array $credential, ?\DateTimeImmutable $now = null): array
    {
        $now   = $now ?? new \DateTimeImmutable('today');
        $token = (string) ($credential['token'] ?? '');
        if ($token === '') {
            throw new FioApiException('Účet nemá uložený token pro Fio API.');
        }

        $from    = $this->windowStart($credential, $now);
        $gapFrom = $this->truncatedGapStart($credential, $from);
        $movements = $this->api->fetchPeriod($token, $from, $now);

        $pdo         = $this->db->pdo();
        $supplierId  = (int) $credential['supplier_id'];
        $account     = $this->account($pdo, (int) $credential['currency_id']);
        $result      = ['created' => 0, 'skipped' => 0, 'matched' => 0, 'dropped' => 0];
        $newestDate  = (string) ($credential['last_fetched_on'] ?? '');
        $newestExtId = (string) ($credential['last_external_id'] ?? '');

        foreach ($movements as $movement) {
            $date = $movement['posted_at'] ?? null;
            if ($date === null || abs((float) $movement['amount']) < 0.005) {
                // Nerozpoznaný pohyb (typicky změna formátu data u banky) se NESMÍ
                // ztratit mezi deduplikovanými — jinak by tichá ztráta plateb
                // vypadala jako úspěšný běh.
                $result['dropped']++;
                continue;
            }
            $sourceRef = $supplierId . ':' . $movement['external_id'];
            if ($this->exists($pdo, $sourceRef)) {
                $result['skipped']++;
                continue;
            }

            $statementId = 0;
            $txId        = 0;
            try {
                $statementId = $this->statement($pdo, $supplierId, $date, $account);
                $pdo->prepare(
                    "INSERT INTO bank_transactions
                        (source, source_ref, statement_id, posted_at, amount, currency,
                         variable_symbol, constant_symbol, specific_symbol, counterparty_account,
                         counterparty_bank, counterparty_name, description, bank_ref)
                     VALUES ('fio', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $sourceRef,
                    $statementId,
                    $date,
                    number_format((float) $movement['amount'], 2, '.', ''),
                    // Měna účtu je autoritativní — Fio u některých výstupů plní měnu
                    // konstantně (viz #109), takže per-pohyb hodnotu nepřebíráme.
                    (string) $account['currency'],
                    self::text($movement['variable_symbol'], 20),
                    self::text($movement['constant_symbol'], 10),
                    self::text($movement['specific_symbol'], 20),
                    self::text($movement['counterparty_account'], 40),
                    self::text($movement['counterparty_bank'], 4),
                    self::text($movement['counterparty_name'], 190),
                    self::text($movement['description'], 255),
                    self::text($movement['external_id'], 40),
                ]);
                $txId = (int) $pdo->lastInsertId();

                // Tentýž pohyb může dorazit i e-mailovým avízem Fio (parser existuje)
                // nebo GPC výpisem — bez těchto dvou kroků by vznikla dvojí úhrada.
                // Pořadí autority: výpis > Fio API > avízo. Nejdřív tedy ustup výpisu,
                // a pokud žádný není, převezmi párování po případném avízu.
                if ($this->reconciler->ignoreSecondaryWhenAuthoritativeTwinExists($txId) === null) {
                    if ($this->reconciler->takeOverFromEmailNotice($txId) !== null) {
                        $result['matched']++;
                    } else {
                        $match = $this->matcher->match($txId);
                        if (in_array($match['status'] ?? '', ['auto_exact', 'auto_partial'], true)) {
                            $result['matched']++;
                        }
                    }
                }
                $this->refreshStatement($pdo, $statementId);
                $result['created']++;

                if ($newestDate === '' || $date > $newestDate) {
                    $newestDate  = $date;
                    $newestExtId = (string) $movement['external_id'];
                }
            } catch (\Throwable $e) {
                if ($txId > 0) {
                    $pdo->prepare('DELETE FROM bank_transactions WHERE id = ?')->execute([$txId]);
                }
                if ($statementId > 0) {
                    $this->refreshStatement($pdo, $statementId);
                }
                throw $e;
            }
        }

        $message = sprintf('Načteno %d nových pohybů (%d už existovalo).', $result['created'], $result['skipped']);
        $status  = 'ok';
        if ($result['dropped'] > 0) {
            $status   = 'error';
            $message .= sprintf(' POZOR: %d pohybů se nepodařilo přečíst — zkontroluj log.', $result['dropped']);
        }
        // Oříznuté okno = mezera, kterou už API nikdy nevrátí (historie sahá 90 dní).
        // Nehlásit ji jako „ok" — uživatel musí vědět, že období musí doplnit výpisem.
        if ($gapFrom !== null) {
            $status   = 'error';
            $message .= sprintf(
                ' Období %s–%s se přes API už stáhnout nedá (historie sahá 90 dnů) — doplň ho nahráním výpisu.',
                $gapFrom->format('d.m.Y'),
                $from->modify('-1 day')->format('d.m.Y')
            );
        }
        $this->credentials->recordFetch(
            (int) $credential['id'],
            $status,
            $message,
            $newestDate !== '' ? $newestDate : null,
            $newestExtId !== '' ? $newestExtId : null,
        );

        return $result;
    }

    /**
     * Začátek okna: den posledního uloženého pohybu mínus překryv; bez historie
     * jdeme na maximum, které API dovolí.
     *
     * @param array<string,mixed> $credential
     */
    /**
     * Začátek mezery, kterou API už nepokryje — tedy den po posledním staženém
     * pohybu, pokud je starší, než kam historie sahá. null = žádná mezera.
     *
     * @param array<string,mixed> $credential
     */
    private function truncatedGapStart(array $credential, \DateTimeImmutable $windowStart): ?\DateTimeImmutable
    {
        $last = (string) ($credential['last_fetched_on'] ?? '');
        if ($last === '') {
            return null;
        }
        $expected = (new \DateTimeImmutable($last))->modify('-' . self::OVERLAP_DAYS . ' days');

        return $expected < $windowStart ? $expected : null;
    }

    private function windowStart(array $credential, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $maxBack = $now->modify('-' . FioApiClient::MAX_HISTORY_DAYS . ' days');
        $last    = (string) ($credential['last_fetched_on'] ?? '');
        if ($last === '') {
            return $maxBack;
        }
        $from = (new \DateTimeImmutable($last))->modify('-' . self::OVERLAP_DAYS . ' days');

        return $from < $maxBack ? $maxBack : $from;
    }

    private function exists(PDO $pdo, string $sourceRef): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM bank_transactions WHERE source = 'fio' AND source_ref = ? LIMIT 1");
        $stmt->execute([$sourceRef]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Transakce musí viset na výpisu (`statement_id` je NOT NULL), takže si držíme
     * jeden syntetický měsíční „výpis" per účet — stejně jako avíza a iDoklad import.
     *
     * @param array<string,mixed> $account
     */
    private function statement(PDO $pdo, int $supplierId, string $date, array $account): int
    {
        $month = substr($date, 0, 7);
        $ref   = $supplierId . ':' . (string) $account['account_number'] . ':' . $month;
        $hash  = hash('sha256', 'fio:' . $ref);
        $pdo->prepare(
            "INSERT INTO bank_statements
                (source, source_ref, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES ('fio', ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE statement_date = GREATEST(statement_date, VALUES(statement_date))"
        )->execute([
            $ref,
            'Fio API ' . $month,
            $hash,
            (string) $account['account_number'],
            self::text($account['bank_code'] ?? null, 4),
            (string) $account['currency'],
            $date,
        ]);
        $stmt = $pdo->prepare('SELECT id FROM bank_statements WHERE file_hash = ?');
        $stmt->execute([$hash]);

        return (int) $stmt->fetchColumn();
    }

    private function refreshStatement(PDO $pdo, int $statementId): void
    {
        $pdo->prepare(
            "UPDATE bank_statements SET
                transaction_count = (SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ?),
                matched_count = (SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ?
                                  AND match_status IN ('auto_exact','auto_partial','manual'))
              WHERE id = ?"
        )->execute([$statementId, $statementId, $statementId]);
    }

    /** @return array<string,mixed> */
    private function account(PDO $pdo, int $currencyId): array
    {
        $stmt = $pdo->prepare('SELECT account_number, bank_code, code AS currency FROM currencies WHERE id = ?');
        $stmt->execute([$currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (string) ($row['account_number'] ?? '') === '') {
            throw new FioApiException('Bankovní účet pro Fio API nemá vyplněné číslo účtu.');
        }

        return $row;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
