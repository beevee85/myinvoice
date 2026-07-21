<?php

declare(strict_types=1);

namespace MyInvoice\Service\Currency;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;

/**
 * Po každém Create/Update faktury: pokud měna ≠ CZK, zjistí kurz CNB pro issue_date
 * (s fallbackem den-zpět + last-known) a uloží do `invoices.exchange_rate`.
 *
 * Vrací metadata pro UI (warning toast po uložení) — null pokud se nic neaplikuje
 * (CZK faktura) nebo pokud DB neobsahuje žádný kurz pro danou měnu (ani last-known).
 */
final class ExchangeRateApplier
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly CnbExchangeRateClient $cnb,
    ) {}

    /**
     * @return array{
     *   currency: string,
     *   rate: float,
     *   rate_date: string,
     *   fallback_used: bool,
     *   source: 'cache'|'fresh'|'last_known'
     * }|null
     */
    public function applyToInvoice(int $invoiceId): ?array
    {
        // Kurz se váže k DUZP (§ 4 odst. 5 / § 8 ZDPH — den vzniku daňové povinnosti),
        // ne k datu vystavení. Fallback na issue_date, když DUZP chybí.
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(i.tax_date, i.issue_date) AS rate_date, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ?'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $code = strtoupper((string) $row['currency']);
        if ($code === 'CZK') {
            // Reset — kdyby uživatel změnil EUR fakturu na CZK
            $this->invoices->setExchangeRate($invoiceId, null, null);
            return null;
        }

        try {
            $rateDate = new DateTimeImmutable((string) $row['rate_date']);
        } catch (\Exception) {
            return null;
        }

        $result = $this->cnb->getRate($code, $rateDate);
        if ($result === null) {
            $this->invoices->setExchangeRate($invoiceId, null, null);
            return null;
        }

        $this->invoices->setExchangeRate(
            $invoiceId,
            (float) $result['rate'],
            (string) $result['rate_date'],
        );

        return [
            'currency'      => $code,
            'rate'          => (float) $result['rate'],
            'rate_date'     => (string) $result['rate_date'],
            'fallback_used' => (bool) $result['fallback_used'],
            'source'        => (string) $result['source'],
        ];
    }

    /**
     * Idempotentní backfill — pokud faktura v cizí měně nemá zafixovaný kurz
     * (legacy data, nebo dřívější fetch z ČNB selhal), zkusí cache → CNB → last
     * known. Volá se z GetInvoiceAction / PdfAction při každém otevření faktury,
     * aby starší doklady automaticky dostaly přepočet jakmile bude kurz dostupný.
     *
     * Pokud kurz už uložen je, nic nedělá. Pokud měna je CZK, nic nedělá.
     */
    public function ensureRate(int $invoiceId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.exchange_rate, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ?'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return;
        if (strtoupper((string) $row['currency']) === 'CZK') return;
        if ($row['exchange_rate'] !== null) return;

        $this->applyToInvoice($invoiceId);
    }
}
