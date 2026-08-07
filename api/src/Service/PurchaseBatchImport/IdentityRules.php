<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

/**
 * FORK (beevee85) — Commit 11c: identita stran, čísla a datumy.
 *
 * Pokrývá V15, V16, V17, V26, V28, V36, V38, V55.
 *
 * V17 JE NOVÁ SCHOPNOST. Kontrolní součet IČO v repu neexistoval — ověřeno
 * grepem, ne předpokladem. Algoritmus (váhy 8..2, modulo 11) je ověřený proti
 * třem reálným IČO; do repa jdou jen syntetické hodnoty, protože identifikátory
 * skutečných protistran sem podle AGENTS.md nepatří.
 *
 * PROČ SE V15 A V16 KONTROLUJÍ OBĚ: nejsou to dvě formulace téhož. V15 hlídá,
 * že doklad patří NÁM (odběratel je tenant) — bez toho by se do naší evidence
 * dostala cizí faktura. V16 hlídá, že si nefakturujeme sami sobě, což je jiný
 * jev: vzniká, když extrakce prohodí strany u dokladu s dominantní hlavičkou
 * dodavatele. Doklad může projít V15 a padnout na V16.
 *
 * DATUMY se nepřepočítávají ani neopravují. `AiPdfExtractor` dnes prohozené
 * `issue_date`/`due_date` tiše OPRAVÍ (`fixSwappedIssueDueDates()`); tady je to
 * nález. Oprava vstupu, kterou nikdo neviděl, je horší než odmítnutí.
 */
final class IdentityRules
{
    private const ALLOWED_DOC_KINDS = ['invoice', 'receipt', 'credit_note', 'advance', 'tax_document'];

    /** Doklad vystavený dál než tolik dní dopředu je chyba čtení, ne obchodní případ. */
    private const MAX_FUTURE_DAYS = 2;

    /**
     * @param array<string,mixed> $doc
     * @param array{ic: string, dic: string} $tenant  identita naší firmy
     * @param string $today  „YYYY-MM-DD" — vždy injektované, nikdy z hodin (V84)
     * @return list<Finding>
     */
    public function validate(array $doc, array $tenant, string $today, string $base): array
    {
        return array_merge(
            $this->validateKind($doc, $base),
            $this->validateParties($doc, $tenant, $base),
            $this->validateNumbers($doc, $base),
            $this->validateDates($doc, $today, $base),
        );
    }

    // -----------------------------------------------------------------------

    /** Kontrolní součet českého IČO: váhy 8,7,6,5,4,3,2 a modulo 11. */
    public static function isValidIco(string $ico): bool
    {
        if (!preg_match('/^[0-9]{8}$/', $ico)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += ((int) $ico[$i]) * (8 - $i);
        }

        $remainder = $sum % 11;
        $check = match ($remainder) {
            0       => 1,
            1       => 0,
            default => 11 - $remainder,
        };

        return $check === (int) $ico[7];
    }

    // -----------------------------------------------------------------------

    /** @return list<Finding> */
    private function validateKind(array $doc, string $base): array
    {
        $kind = (string) ($doc['document_kind'] ?? '');

        if ($kind === '') {
            return [Finding::fail('V55', $base . '/document_kind', 'Chybí typ dokladu.')];
        }
        if (!in_array($kind, self::ALLOWED_DOC_KINDS, true)) {
            return [Finding::fail('V55', $base . '/document_kind', 'Neznámý typ dokladu.')->withValue($kind)];
        }

        return [];
    }

    /** @return list<Finding> */
    private function validateParties(array $doc, array $tenant, string $base): array
    {
        $findings = [];

        $vendor   = is_array($doc['vendor'] ?? null) ? $doc['vendor'] : [];
        $vendorIc = trim((string) ($vendor['ic'] ?? ''));
        $tenantIc = trim($tenant['ic'] ?? '');

        // --- V17: kontrolní součet IČO ---------------------------------
        if ($vendorIc !== '' && !self::isValidIco($vendorIc)) {
            $findings[] = Finding::fail('V17', $base . '/vendor/ic',
                'IČO dodavatele neprošlo kontrolním součtem.')->withValue($vendorIc);
        }

        // --- V16: dodavatel nesmí být náš tenant ------------------------
        // Vzniká, když extrakce prohodí strany u dokladu s dominantní
        // hlavičkou dodavatele. Kontroluje se, jen když obě IČO známe —
        // jinak by chybějící údaj vyrobil falešný nález.
        if ($vendorIc !== '' && $tenantIc !== '' && $vendorIc === $tenantIc) {
            $findings[] = Finding::fail('V16', $base . '/vendor/ic',
                'Dodavatelem je naše vlastní firma — strany jsou zřejmě prohozené.');
        }

        // --- V15: odběratelem musí být náš tenant -----------------------
        if (array_key_exists('customer_matches_tenant', $doc)
            && $doc['customer_matches_tenant'] === false) {
            $findings[] = Finding::fail('V15', $base . '/customer_matches_tenant',
                'Odběratelem na dokladu není naše firma — doklad do naší evidence nepatří.');
        }

        if (($vendor['company_name'] ?? '') === '' && $vendorIc === '') {
            $findings[] = Finding::fail('V17', $base . '/vendor',
                'Dodavatel nemá ani název, ani IČO.');
        }

        // Audit 2026-08-07: délka názvu proti sloupci clients.company_name
        // VARCHAR(190). Bez téhle kontroly extrakce slila název + adresu do
        // 220 znaků, validace prošla a apply spadl na SQLSTATE 22001 (500) —
        // řádek pak nešel aplikovat ani odmítnout a dávka uvízla ve `validating`
        // s neuklizeným raw_json. Měří se v BAJTECH (sloupec je bajtový, diakritika
        // je vícebajtová), s rezervou na sanitizaci v ClientResolveru.
        $companyName = (string) ($vendor['company_name'] ?? '');
        if ($companyName !== '' && strlen($companyName) > 190) {
            $findings[] = Finding::fail('V17', $base . '/vendor/company_name',
                'Název dodavatele je delší než 190 bajtů — nevejde se do evidence, zkraťte ho v dokladu.');
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function validateNumbers(array $doc, string $base): array
    {
        $findings = [];
        $numbers  = is_array($doc['numbers'] ?? null) ? $doc['numbers'] : [];

        // --- V26: číslo dokladu -----------------------------------------
        $number = trim((string) ($numbers['vendor_invoice_number'] ?? ''));
        if ($number === '') {
            $findings[] = Finding::fail('V26', $base . '/numbers/vendor_invoice_number',
                'Chybí číslo dokladu.');
        } elseif (mb_strlen($number) > 50) {
            $findings[] = Finding::fail('V26', $base . '/numbers/vendor_invoice_number',
                'Číslo dokladu je delší než 50 znaků.')->withValue($number);
        } elseif (preg_match('/[\x00-\x1F\x7F]/u', $number)) {
            $findings[] = Finding::fail('V26', $base . '/numbers/vendor_invoice_number',
                'Číslo dokladu obsahuje řídicí znaky.');
        }

        // --- V28: variabilní symbol -------------------------------------
        $vs = trim((string) ($numbers['varsymbol'] ?? ''));
        if ($vs !== '') {
            if (!preg_match('/^[0-9]{1,10}$/', $vs)) {
                $findings[] = Finding::fail('V28', $base . '/numbers/varsymbol',
                    'Variabilní symbol musí být 1–10 číslic.')->withValue($vs);
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function validateDates(array $doc, string $today, string $base): array
    {
        $findings = [];
        $dates    = is_array($doc['dates'] ?? null) ? $doc['dates'] : [];

        $issue = $this->parseDate((string) ($dates['issue_date'] ?? ''));
        if ($issue === null) {
            return [Finding::fail('V36', $base . '/dates/issue_date',
                'Datum vystavení chybí nebo není ve tvaru YYYY-MM-DD.')];
        }

        $due = $this->parseDate((string) ($dates['due_date'] ?? ''));
        $tax = $this->parseDate((string) ($dates['tax_date'] ?? ''));

        // --- V36: vystavení ≤ splatnost ---------------------------------
        // Tady se NIC NEOPRAVUJE. AiPdfExtractor dnes prohozená data tiše
        // otočí; oprava vstupu, kterou nikdo neviděl, je horší než nález.
        if ($due !== null && $due < $issue) {
            $findings[] = Finding::fail('V36', $base . '/dates/due_date',
                'Splatnost je dřív než vystavení — data jsou zřejmě prohozená.');
        }

        // --- V38: datum v budoucnu --------------------------------------
        $limit = $this->parseDate($today);
        if ($limit !== null) {
            $maxIssue = $limit + self::MAX_FUTURE_DAYS * 86400;
            if ($issue > $maxIssue) {
                $findings[] = Finding::fail('V38', $base . '/dates/issue_date',
                    'Datum vystavení leží v budoucnosti.');
            }
            if ($tax !== null && $tax > $maxIssue) {
                $findings[] = Finding::fail('V38', $base . '/dates/tax_date',
                    'Datum zdanitelného plnění leží v budoucnosti.');
            }
        }

        return $findings;
    }

    /**
     * Přísné YYYY-MM-DD. `strtotime()` se tu nepoužívá schválně — přijal by
     * „nyní", „+1 day" i „15/06/2026" a tiše by z nich udělal datum.
     * Vrací unixový čas, nebo null.
     */
    private function parseDate(string $raw): ?int
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if (!checkdate($mo, $d, $y)) {
            return null;
        }

        return mktime(0, 0, 0, $mo, $d, $y) ?: null;
    }
}
