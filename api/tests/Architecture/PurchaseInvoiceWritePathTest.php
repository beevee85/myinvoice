<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * FORK (beevee85) — hlídá, že zapisovací sekvence přijaté faktury zůstane pohromadě.
 *
 * PROČ PRÁVĚ TENHLE TEST: blok, ze kterého se sekvence vytáhla, je nejteplejší místo
 * celého `CreatePurchaseInvoiceAction` — upstream do něj sáhl v 6 z 11 commitů, které
 * ten soubor kdy měnily. Nebezpečný není hlučný konflikt, ale TICHÝ merge: kdyby
 * upstream přidal pátý krok a ten se po sloučení přilepil ZA delegaci, běžel by až
 * za přepočtem. U kroku typu `setVatOverrides` by to znamenalo, že se rekapitulace
 * DPH podle dokladu (§ 73 ZDPH) nezapeče do řádkových totálů — doklad by se rozešel
 * s papírem dodavatele o haléře a nikdo by si toho nevšiml.
 *
 * Test je statický (čte zdrojáky), takže nepotřebuje databázi.
 *
 * ROZSAH: zatím jen převedená ruční cesta. Až se převedou importní cesty, rozšíří se
 * i tenhle test na ně (pravidlo V77 „žádná zapisovací cesta mimo write service").
 */
final class PurchaseInvoiceWritePathTest extends TestCase
{
    private const ACTION  = 'src/Action/PurchaseInvoice/CreatePurchaseInvoiceAction.php';
    private const SERVICE = 'src/Service/Invoice/PurchaseInvoiceWriteService.php';

    private static function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return list<array{string, string}> */
    public static function writeSteps(): array
    {
        return [
            'založení hlavičky' => ['->createDraft(', 'PurchaseInvoiceRepository::createDraft'],
            'zápis položek'     => ['->replaceItems(', 'PurchaseInvoiceRepository::replaceItems'],
            'rekapitulace § 73' => ['->setVatOverrides(', 'PurchaseInvoiceRepository::setVatOverrides'],
            'přepočet součtů'   => ['->recompute(', 'PurchaseInvoiceCalculator::recompute'],
        ];
    }

    /**
     * Akce nesmí žádný krok sekvence volat sama — jinak by se cesty zase rozešly.
     */
    public function testActionDelegatesEveryWriteStep(): void
    {
        $action = self::source(self::ACTION);

        foreach (self::writeSteps() as $label => [$needle, $what]) {
            self::assertStringNotContainsString(
                $needle,
                $action,
                "CreatePurchaseInvoiceAction volá {$what} přímo ({$label}). "
                . 'Sekvence patří do PurchaseInvoiceWriteService — nejspíš ji tam vrátil merge upstreamu.',
            );
        }

        self::assertStringContainsString(
            '->createWithItems(',
            $action,
            'Akce už nedeleguje na PurchaseInvoiceWriteService::createWithItems().',
        );
    }

    /**
     * Každý volající musí zdroj zápisu předat VÝSLOVNĚ.
     *
     * `createWithItems()` má čtvrtý argument s defaultem `unknown` kvůli zpětné
     * kompatibilitě, ale spoléhat se na něj nesmí nikdo — jinak by za pár měsíců
     * skončila půlka stínové telemetrie jako neoznačená a nešlo by podle ní rozhodnout
     * per cestu. Default má zůstat teoretickou možností, ne provozní realitou.
     */
    public function testEveryCallerPassesExplicitSource(): void
    {
        $apiDir = dirname(__DIR__, 2);
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($apiDir . '/src', \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // Filtr na write service: `createWithItems` se jmenuje i nesouvisející metoda
            // na vydané straně (RecurringTemplateAction), která se sem plést nesmí.
            if (!str_contains($source, 'PurchaseInvoiceWriteService') || !str_contains($source, '->createWithItems(')) {
                continue;
            }

            preg_match_all('/->createWithItems\(([^;]*?)\);/s', $source, $matches);
            foreach ($matches[1] as $args) {
                // Čtyři argumenty = tři čárky na nejvyšší úrovni. Volání jsou plochá.
                if (substr_count($args, ',') < 3) {
                    $offenders[] = str_replace($apiDir . '/', '', $file->getPathname())
                        . ': createWithItems(' . trim($args) . ')';
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", array_merge(
            ['Tenhle volající nechává zdroj zápisu na defaultu `unknown`:'],
            array_map(static fn (string $o): string => '  ' . $o, $offenders),
            ['Doplň čtvrtý argument (manual / ai_pdf / isdoc / …), jinak nález nepůjde zařadit.'],
        )));
    }

    /** Služba naopak musí mít všechny čtyři kroky. */
    public function testServiceContainsWholeSequence(): void
    {
        $service = self::source(self::SERVICE);

        foreach (self::writeSteps() as $label => [$needle, $what]) {
            self::assertStringContainsString(
                $needle,
                $service,
                "PurchaseInvoiceWriteService neobsahuje krok „{$label}\" ({$what}).",
            );
        }
    }

    /**
     * Rekapitulace DPH dle dokladu (§ 73 ZDPH) MUSÍ být zapsaná PŘED přepočtem,
     * aby ji kalkulátor zapekl do řádkových totálů. Prohození pořadí je tichá
     * daňová chyba — proto vlastní test.
     */
    public function testVatOverridesAreWrittenBeforeRecompute(): void
    {
        $service = self::source(self::SERVICE);

        $overridesAt = strpos($service, '->setVatOverrides(');
        $recomputeAt = strpos($service, '->recompute(');

        self::assertIsInt($overridesAt, 'Ve službě chybí zápis vat_overrides.');
        self::assertIsInt($recomputeAt, 'Ve službě chybí přepočet.');
        self::assertLessThan(
            $recomputeAt,
            $overridesAt,
            'setVatOverrides musí předcházet recompute — jinak se rekapitulace dokladu (§ 73) '
            . 'nezapeče do řádkových totálů a doklad se rozejde s papírem dodavatele.',
        );
    }

    /**
     * Sekvence musí vzniknout jednou. Kdyby se do služby omylem dostal druhý přepočet
     * (typicky slepením při merge), tichý dvojí zápis by se hledal těžko.
     */
    public function testSequenceIsNotDuplicatedInsideService(): void
    {
        $service = self::source(self::SERVICE);

        foreach (['->replaceItems(', '->setVatOverrides(', '->recompute('] as $needle) {
            self::assertSame(
                1,
                substr_count($service, $needle),
                "Krok {$needle} je ve službě vícekrát — sekvence se nejspíš zdvojila.",
            );
        }
    }
}
