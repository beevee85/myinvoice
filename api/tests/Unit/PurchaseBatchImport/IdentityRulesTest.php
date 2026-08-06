<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\PurchaseBatchImport;

use MyInvoice\Service\PurchaseBatchImport\Finding;
use MyInvoice\Service\PurchaseBatchImport\IdentityRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — identita stran, čísla a datumy (V15–V17, V26, V28, V36, V38, V55).
 *
 * VŠECHNA IČO V TOMHLE SOUBORU JSOU SYNTETICKÁ. Splňují kontrolní součet,
 * ale nepatří skutečné firmě — identifikátory protistran do veřejného repa
 * podle AGENTS.md nepatří. Algoritmus byl ověřený i proti reálným hodnotám,
 * ty ale zůstaly mimo repozitář.
 */
final class IdentityRulesTest extends TestCase
{
    /** Syntetická, platná podle kontrolního součtu. */
    private const ICO_VENDOR = '00000019';
    private const ICO_TENANT = '00000027';

    private const TODAY = '2026-08-06';

    private function rules(): IdentityRules
    {
        return new IdentityRules();
    }

    private function tenant(string $ic = self::ICO_TENANT): array
    {
        return ['ic' => $ic, 'dic' => 'CZ' . $ic];
    }

    private function doc(array $over = []): array
    {
        return array_replace_recursive([
            'document_kind' => 'invoice',
            'vendor'        => ['company_name' => 'Dodavatel s.r.o.', 'ic' => self::ICO_VENDOR],
            'customer_matches_tenant' => true,
            'numbers'       => ['vendor_invoice_number' => '2026001', 'varsymbol' => '2026001'],
            'dates'         => ['issue_date' => '2026-07-01', 'due_date' => '2026-07-15', 'tax_date' => '2026-07-01'],
        ], $over);
    }

    /** @param list<Finding> $f @return list<string> */
    private function failIds(array $f): array
    {
        return array_values(array_map(
            static fn (Finding $x) => $x->ruleId,
            array_filter($f, static fn (Finding $x) => $x->severity === Finding::FAIL),
        ));
    }

    private function check(array $over = [], ?array $tenant = null): array
    {
        return $this->rules()->validate(
            $this->doc($over), $tenant ?? $this->tenant(), self::TODAY, '/documents/0',
        );
    }

    // -----------------------------------------------------------------------
    // V17 — kontrolní součet IČO (nová schopnost, v repu neexistovala)
    // -----------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool}> */
    public static function icoCases(): array
    {
        return [
            'platne syntetické A'  => ['00000019', true],
            'platne syntetické B'  => ['00000027', true],
            'spatna kontrolni cislice' => ['00000010', false],
            'nahodne osmicisli'    => ['12345678', false],
            'sedm cislic'          => ['1234567', false],
            'devet cislic'         => ['123456789', false],
            'pismena'              => ['1234567A', false],
            'prazdne'              => ['', false],
            's mezerou'            => ['0000001 9', false],
        ];
    }

    #[DataProvider('icoCases')]
    public function testIcoChecksum(string $ico, bool $expected): void
    {
        self::assertSame($expected, IdentityRules::isValidIco($ico));
    }

    public function testInvalidVendorIcoIsAFailure(): void
    {
        $f = $this->check(['vendor' => ['ic' => '12345678']]);
        self::assertContains('V17', $this->failIds($f));
    }

    public function testVendorWithoutNameAndIcoIsAFailure(): void
    {
        $f = $this->check(['vendor' => ['company_name' => '', 'ic' => '']]);
        self::assertContains('V17', $this->failIds($f));
    }

    // -----------------------------------------------------------------------
    // V15 a V16 — dvě různá pravidla, ne dvě formulace téhož
    // -----------------------------------------------------------------------

    public function testVendorBeingOurOwnCompanyIsAFailure(): void
    {
        $f = $this->check(['vendor' => ['ic' => self::ICO_TENANT]]);
        self::assertContains('V16', $this->failIds($f),
            'sami sobě nefakturujeme — strany jsou prohozené');
    }

    public function testCustomerNotBeingTenantIsAFailure(): void
    {
        $f = $this->check(['customer_matches_tenant' => false]);
        self::assertContains('V15', $this->failIds($f),
            'cizí faktura do naší evidence nepatří');
    }

    /** Doklad může projít V15 a padnout na V16 — proto se kontrolují obě. */
    public function testDocumentCanPassV15AndFailV16(): void
    {
        $f = $this->check([
            'customer_matches_tenant' => true,
            'vendor' => ['ic' => self::ICO_TENANT],
        ]);

        self::assertNotContains('V15', $this->failIds($f));
        self::assertContains('V16', $this->failIds($f));
    }

    /** Chybějící IČO dodavatele nesmí vyrobit falešný nález V16. */
    public function testMissingVendorIcoDoesNotTriggerSelfInvoiceRule(): void
    {
        $f = $this->check(['vendor' => ['company_name' => 'Dodavatel s.r.o.', 'ic' => '']]);
        self::assertNotContains('V16', $this->failIds($f));
    }

    // -----------------------------------------------------------------------
    // V26, V28 — čísla
    // -----------------------------------------------------------------------

    public function testMissingDocumentNumberIsAFailure(): void
    {
        $f = $this->check(['numbers' => ['vendor_invoice_number' => '']]);
        self::assertContains('V26', $this->failIds($f));
    }

    public function testControlCharactersInDocumentNumberAreAFailure(): void
    {
        $f = $this->check(['numbers' => ['vendor_invoice_number' => "2026\x00001"]]);
        self::assertContains('V26', $this->failIds($f));
    }

    public function testOverlongDocumentNumberIsAFailure(): void
    {
        $f = $this->check(['numbers' => ['vendor_invoice_number' => str_repeat('9', 51)]]);
        self::assertContains('V26', $this->failIds($f));
    }

    public function testNonNumericVarsymbolIsAFailure(): void
    {
        $f = $this->check(['numbers' => ['varsymbol' => 'ZA-2026/001']]);
        self::assertContains('V28', $this->failIds($f),
            'variabilní symbol musí být číselný — dnešní validace to nekontroluje');
    }

    public function testEmptyVarsymbolIsAllowed(): void
    {
        $f = $this->check(['numbers' => ['varsymbol' => '']]);
        self::assertNotContains('V28', $this->failIds($f));
    }

    // -----------------------------------------------------------------------
    // V36, V38 — datumy se NEOPRAVUJÍ
    // -----------------------------------------------------------------------

    public function testSwappedIssueAndDueDatesAreAFindingNotSilentlyFixed(): void
    {
        $f = $this->check(['dates' => ['issue_date' => '2026-07-15', 'due_date' => '2026-07-01']]);

        self::assertContains('V36', $this->failIds($f),
            'prohozená data jsou nález; AiPdfExtractor je dnes tiše otočí');
    }

    public function testIssueDateInFutureIsAFailure(): void
    {
        $f = $this->check(['dates' => ['issue_date' => '2026-12-31', 'due_date' => '2027-01-15']]);
        self::assertContains('V38', $this->failIds($f));
    }

    /** Pár dní dopředu je běžné (doklad vystavený dnes, doručený zítra). */
    public function testIssueDateSlightlyInFutureIsTolerated(): void
    {
        $f = $this->check(['dates' => ['issue_date' => '2026-08-07', 'due_date' => '2026-08-20',
                                       'tax_date' => '2026-08-07']]);
        self::assertNotContains('V38', $this->failIds($f));
    }

    /** @return array<string, array{0: string}> */
    public static function badDates(): array
    {
        return [
            'prazdne'          => [''],
            'ceske poradi'     => ['15.06.2026'],
            'lomitka'          => ['2026/06/15'],
            'relativni'        => ['+1 day'],
            'nyni'             => ['now'],
            'neexistujici den' => ['2026-02-30'],
            'trinacty mesic'   => ['2026-13-01'],
            'jen rok'          => ['2026'],
        ];
    }

    /**
     * `strtotime()` by „now" i „+1 day" tiše přijal — proto se nepoužívá.
     *
     * `due_date` se schválně vyprazdňuje: V36 se emituje i pro prohozená data,
     * takže s výchozí splatností by se test trefil jinou cestou, než měří.
     * Odhaleno mutací — s ponechaným `due_date` prošly případy „now" a „+1 day"
     * i s parserem přes `strtotime()`.
     */
    #[DataProvider('badDates')]
    public function testUnparseableIssueDateIsAFailure(string $raw): void
    {
        $f = $this->check(['dates' => ['issue_date' => $raw, 'due_date' => '', 'tax_date' => '']]);

        $onIssueDate = array_filter(
            $f,
            static fn (Finding $x) => $x->severity === Finding::FAIL
                && $x->pointer === '/documents/0/dates/issue_date',
        );
        self::assertNotEmpty($onIssueDate,
            'nález musí ukazovat přímo na datum vystavení, ne na jiné pravidlo');
    }

    // -----------------------------------------------------------------------
    // V55 — typ dokladu
    // -----------------------------------------------------------------------

    public function testUnknownDocumentKindIsAFailure(): void
    {
        $f = $this->check(['document_kind' => 'faktura-danova']);
        self::assertContains('V55', $this->failIds($f));
    }

    public function testAllKnownDocumentKindsPass(): void
    {
        foreach (['invoice', 'receipt', 'credit_note', 'advance', 'tax_document'] as $kind) {
            $f = $this->check(['document_kind' => $kind]);
            self::assertNotContains('V55', $this->failIds($f), "typ {$kind} musí projít");
        }
    }

    // -----------------------------------------------------------------------

    public function testCleanDocumentHasNoFailures(): void
    {
        self::assertSame([], $this->failIds($this->check()));
    }

    public function testIcoIsNeverEchoedInMessage(): void
    {
        $f = $this->check(['vendor' => ['ic' => '12345678']]);

        foreach ($f as $finding) {
            self::assertStringNotContainsString('12345678', $finding->message,
                'IČO na allow-listu není — identifikuje protistranu');
        }
    }
}
