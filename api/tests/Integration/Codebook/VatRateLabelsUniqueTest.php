<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Codebook;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Číselník sazeb DPH — popisky musejí být jednoznačné.
 *
 * Frontend i PDF berou popisek sazby z `vat_rates.label_cs` / `label_en`
 * (web/src/utils/vatRate.ts). Dvě sazby se shodným popiskem znamenají, že je
 * uživatel v selectu nerozliší — přesně to se stalo, když k „Osvobozeno" (CZ-0)
 * přibylo „Mimo DPH" (CZ-NA): obě 0 %, obě se vykreslovaly jako „0 % (osvob.)".
 * Rozdíl je přitom zásadní — osvobozené plnění se vykazuje v přiznání,
 * plnění mimo předmět daně ne.
 */
final class VatRateLabelsUniqueTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        // Bez cfg.php hodí Config::load() výjimku a test by skončil ERRORem místo skipu
        // (na rozdíl od zbytku Integration suity). Oba checky musí být PŘED buildApp().
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    /** @return list<array{id:int, code:string, label_cs:string, label_en:string, country:string, valid_to:?string}> */
    private function activeRates(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT id, code, label_cs, label_en, country, valid_to
               FROM vat_rates
              WHERE valid_to IS NULL OR valid_to >= CURDATE()"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function testActiveVatRatesHaveUniqueCzechLabels(): void
    {
        $seen = [];
        foreach ($this->activeRates() as $r) {
            $key = $r['country'] . '|' . mb_strtolower(trim((string) $r['label_cs']));
            $this->assertArrayNotHasKey(
                $key,
                $seen,
                sprintf(
                    'Sazby %s a %s mají shodný český popisek „%s" — v selectu je nelze rozlišit.',
                    $seen[$key] ?? '?', $r['code'], $r['label_cs'],
                ),
            );
            $seen[$key] = $r['code'];
        }
    }

    public function testActiveVatRatesHaveUniqueEnglishLabels(): void
    {
        $seen = [];
        foreach ($this->activeRates() as $r) {
            $key = $r['country'] . '|' . mb_strtolower(trim((string) $r['label_en']));
            $this->assertArrayNotHasKey(
                $key,
                $seen,
                sprintf(
                    'Rates %s and %s share the English label "%s".',
                    $seen[$key] ?? '?', $r['code'], $r['label_en'],
                ),
            );
            $seen[$key] = $r['code'];
        }
    }

    public function testEveryActiveRateHasNonEmptyLabels(): void
    {
        foreach ($this->activeRates() as $r) {
            $this->assertNotSame('', trim((string) $r['label_cs']), "Sazba {$r['code']} nemá český popisek.");
            $this->assertNotSame('', trim((string) $r['label_en']), "Sazba {$r['code']} nemá anglický popisek.");
        }
    }

    /**
     * „Mimo DPH" (CZ-NA) je odlišná sazba od „Osvobozeno" (CZ-0) — obě 0 %,
     * ale jiný daňový význam. Kontrola, že v číselníku obě existují a nesplývají.
     */
    public function testOutOfScopeRateExistsAndDiffersFromExempt(): void
    {
        $stmt = $this->db->pdo()->query(
            "SELECT code, rate_percent, label_cs FROM vat_rates WHERE code IN ('CZ-0', 'CZ-NA')"
        );
        $rates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $rates[(string) $r['code']] = $r;
        }
        $this->assertArrayHasKey('CZ-0', $rates, 'Chybí sazba „Osvobozeno" (CZ-0).');
        $this->assertArrayHasKey('CZ-NA', $rates, 'Chybí sazba „Mimo DPH" (CZ-NA, migrace 0906).');
        $this->assertEqualsWithDelta(0.0, (float) $rates['CZ-0']['rate_percent'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $rates['CZ-NA']['rate_percent'], 0.001);
        $this->assertNotSame(
            mb_strtolower((string) $rates['CZ-0']['label_cs']),
            mb_strtolower((string) $rates['CZ-NA']['label_cs']),
            'Osvobozeno a Mimo DPH musejí mít rozdílný popisek.',
        );
    }
}
