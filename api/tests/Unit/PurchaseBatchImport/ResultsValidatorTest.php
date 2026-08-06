<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\PurchaseBatchImport;

use MyInvoice\Service\PurchaseBatchImport\AmountRules;
use MyInvoice\Service\PurchaseBatchImport\IdentityRules;
use MyInvoice\Service\PurchaseBatchImport\ResultsValidator;
use MyInvoice\Service\PurchaseBatchImport\StrictJson;
use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — orchestrátor doménové validace.
 *
 * Těžiště je párování na manifest. Chybějící doklad v odpovědi je ta NEJTIŠŠÍ
 * varianta selhání, jaká tu může nastat: dávka se tváří jako hotová a část
 * dokladů se zahodí, aniž by kdokoli dostal chybu.
 */
final class ResultsValidatorTest extends TestCase
{
    private const TODAY = '2026-08-06';
    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const SHA_UNKNOWN = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private function validator(): ResultsValidator
    {
        return new ResultsValidator(
            new StrictJson(maxBytes: 200_000, maxDepth: 20, maxArrayItems: 200,
                           maxObjectKeys: 200, maxStringLength: 4096),
            new IdentityRules(),
            new AmountRules(),
        );
    }

    /** @param list<string> $shas */
    private function manifest(array $shas): array
    {
        return array_map(static fn (string $s) => ['sha256' => $s], $shas);
    }

    private function tenant(): array
    {
        return ['ic' => '00000027', 'dic' => 'CZ00000027'];
    }

    /**
     * `items` se nahrazuje CELÉ, ne rekurzivně. `array_replace_recursive` by do
     * přepsané položky propašoval klíče z výchozí (typicky `line_base`) a test
     * by pak měřil něco jiného, než si myslí — což se přesně stalo.
     */
    private function document(string $sha, array $over = []): array
    {
        $itemsOverride = $over['items'] ?? null;
        unset($over['items']);

        $doc = array_replace_recursive([
            'sha256'        => $sha,
            'document_kind' => 'invoice',
            'vendor'        => ['company_name' => 'Dodavatel s.r.o.', 'ic' => '00000019'],
            'customer_matches_tenant' => true,
            'numbers'       => ['vendor_invoice_number' => '2026001', 'varsymbol' => '2026001'],
            'dates'         => ['issue_date' => '2026-07-01', 'due_date' => '2026-07-15'],
            'items'         => [[
                'description' => 'Zboží', 'quantity' => '1',
                'unit_price_without_vat' => '100.00', 'line_base' => '100.00',
            ]],
            'totals'        => ['base' => '100.00', 'vat' => '21.00', 'total' => '121.00'],
        ], $over);

        if ($itemsOverride !== null) {
            $doc['items'] = $itemsOverride;
        }

        return $doc;
    }

    private function payload(array $documents): string
    {
        return json_encode([
            'schema'    => 'myinvoice.purchase-import-batch.results/1',
            'documents' => $documents,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function check(string $raw, array $shas): array
    {
        return $this->validator()->validate($raw, $this->manifest($shas), $this->tenant(), self::TODAY);
    }

    /** @return list<string> */
    private function rules(array $result): array
    {
        return array_values(array_unique(array_map(
            static fn (array $f) => $f['rule'],
            array_filter($result['findings'], static fn (array $f) => $f['severity'] === 'fail'),
        )));
    }

    // -----------------------------------------------------------------------
    // Čistý průchod
    // -----------------------------------------------------------------------

    public function testCleanBatchPasses(): void
    {
        $r = $this->check(
            $this->payload([$this->document(self::SHA_A), $this->document(self::SHA_B)]),
            [self::SHA_A, self::SHA_B],
        );

        self::assertTrue($r['ok'], 'čistá dávka musí projít: ' . json_encode($r['findings'], JSON_UNESCAPED_UNICODE));
    }

    // -----------------------------------------------------------------------
    // V4, V5, V6 — párování na manifest
    // -----------------------------------------------------------------------

    public function testDocumentForFileNotInBatchIsRejected(): void
    {
        $r = $this->check($this->payload([$this->document(self::SHA_UNKNOWN)]), [self::SHA_A]);

        self::assertFalse($r['ok']);
        self::assertContains('V4', $this->rules($r), 'doklad, který se nikdy nenahrál');
    }

    public function testSameFileTwiceIsRejected(): void
    {
        $r = $this->check(
            $this->payload([$this->document(self::SHA_A), $this->document(self::SHA_A)]),
            [self::SHA_A],
        );

        self::assertContains('V5', $this->rules($r), 'jeden soubor by vyrobil dva doklady');
    }

    /** Nejtišší selhání: odpověď o jednom dokladu na dávku o dvou. */
    public function testMissingDocumentMakesBatchIncomplete(): void
    {
        $r = $this->check($this->payload([$this->document(self::SHA_A)]), [self::SHA_A, self::SHA_B]);

        self::assertFalse($r['ok'], 'neúplná dávka se nesmí tvářit jako hotová');
        self::assertContains('V6', $this->rules($r));
    }

    public function testMalformedShaIsRejected(): void
    {
        $r = $this->check($this->payload([$this->document('nenisha')]), [self::SHA_A]);
        self::assertContains('V4', $this->rules($r));
    }

    /** Počet chybějících souborů se nehlásí — prozradil by velikost dávky. */
    public function testMissingCountIsNotDisclosed(): void
    {
        $r = $this->check($this->payload([]), [self::SHA_A, self::SHA_B, self::SHA_UNKNOWN]);

        $v6 = array_filter($r['findings'], static fn (array $f) => $f['rule'] === 'V6');
        self::assertCount(1, $v6, 'jeden nález bez ohledu na počet chybějících');
    }

    // -----------------------------------------------------------------------
    // Tvar odpovědi
    // -----------------------------------------------------------------------

    public function testUnknownSchemaStopsValidation(): void
    {
        $raw = json_encode(['schema' => 'nekdo.jiny/9', 'documents' => []], JSON_THROW_ON_ERROR);
        $r = $this->check($raw, [self::SHA_A]);

        self::assertContains('V9', $this->rules($r));
        self::assertNotContains('V6', $this->rules($r),
            'bez známého schématu se obsah neinterpretuje');
    }

    public function testHostileJsonIsRejectedBeforeAnythingElse(): void
    {
        $r = $this->check('{"schema":"x","schema":"y"}', [self::SHA_A]);

        self::assertContains('V80', $this->rules($r), 'duplicitní klíč se chytí dřív než tvar');
    }

    public function testDocumentsMustBeAList(): void
    {
        $raw = '{"schema":"myinvoice.purchase-import-batch.results/1","documents":{"a":1}}';
        self::assertContains('V9', $this->rules($this->check($raw, [self::SHA_A])));
    }

    // -----------------------------------------------------------------------
    // V62 — podezřelý obsah
    // -----------------------------------------------------------------------

    public function testScriptTagInDescriptionIsRejected(): void
    {
        $r = $this->check($this->payload([$this->document(self::SHA_A, [
            'items' => [['description' => 'Zboží <script>alert(1)</script>']],
        ])]), [self::SHA_A]);

        self::assertContains('V62', $this->rules($r));
    }

    public function testFormulaPrefixIsRejectedForCsvSafety(): void
    {
        $r = $this->check($this->payload([$this->document(self::SHA_A, [
            'numbers' => ['vendor_invoice_number' => '=HYPERLINK("http://zly.example")'],
        ])]), [self::SHA_A]);

        self::assertContains('V62', $this->rules($r));
    }

    /** Kontrola je rekurzivní — pole, které dnes neznáme, musí být pokryté taky. */
    public function testSuspiciousContentIsFoundInUnknownField(): void
    {
        $r = $this->check($this->payload([$this->document(self::SHA_A, [
            'nejake_nove_pole' => ['vnorene' => 'javascript:alert(1)'],
        ])]), [self::SHA_A]);

        self::assertContains('V62', $this->rules($r),
            'kontrola jen známých polí by novou verzi schématu nepokryla');
    }

    public function testOrdinaryTextWithEqualsSignInsideIsNotSuspicious(): void
    {
        $r = $this->check($this->payload([$this->document(self::SHA_A, [
            'items' => [['description' => 'Servis 2+2 = 4 hodiny práce']],
        ])]), [self::SHA_A]);

        self::assertNotContains('V62', $this->rules($r),
            'rovnítko uvnitř textu je běžné — hlídá se jen na začátku');
    }

    // -----------------------------------------------------------------------
    // Determinismus a tvar výstupu
    // -----------------------------------------------------------------------

    public function testFindingOrderIsDeterministic(): void
    {
        $raw = $this->payload([
            $this->document(self::SHA_B, ['vendor' => ['ic' => '12345678']]),
            $this->document(self::SHA_A, ['document_kind' => 'nesmysl']),
        ]);

        $first  = $this->check($raw, [self::SHA_A, self::SHA_B]);
        $second = $this->check($raw, [self::SHA_A, self::SHA_B]);

        self::assertSame($first['findings'], $second['findings'],
            'shodný vstup musí dát bajtově shodný seznam nálezů');
    }

    public function testEveryFindingCarriesRuleAndPointer(): void
    {
        $r = $this->check($this->payload([
            $this->document(self::SHA_A, ['document_kind' => 'nesmysl', 'vendor' => ['ic' => '12345678']]),
        ]), [self::SHA_A]);

        self::assertNotSame([], $r['findings']);
        foreach ($r['findings'] as $f) {
            self::assertSame(['rule', 'severity', 'pointer', 'message'], array_keys($f));
            self::assertMatchesRegularExpression('/^V\d+[a-e]?$/', $f['rule']);
        }
    }

    public function testWarningsAloneDoNotFailTheBatch(): void
    {
        $r = $this->check($this->payload([$this->document(self::SHA_A, [
            'items' => [
                ['description' => 'Poznámka k dodávce', 'quantity' => '0', 'unit_price_without_vat' => '0'],
                ['description' => 'Zboží', 'quantity' => '1',
                 'unit_price_without_vat' => '100.00', 'line_base' => '100.00'],
            ],
        ])]), [self::SHA_A]);

        self::assertTrue($r['ok'], 'popisný řádek je INFO, ne důvod k odmítnutí dávky');
    }
}
