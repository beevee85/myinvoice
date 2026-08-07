<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Static source-level guards — NEexercují runtime, jen čtou zdrojový kód a hlídají,
 * že konkrétní call-sity používají správnou API. Pomalu degradují (každý reformat
 * je rozbije), ale chytí regresi typu „někdo přepsal canBeMarkedPaid zpátky na
 * hasPositiveAmountToPay". Pro skutečnou behavioral coverage viz odpovídající
 * unit testy v tests/Unit/Service/Validation/.
 */
final class InvoiceAmountSourceGuardsTest extends TestCase
{
    public function testManualBankMatchUsesCanBeMarkedPaid(): void
    {
        $code = file_get_contents(dirname(__DIR__, 3) . '/api/src/Action/Bank/BankStatementAction.php');
        self::assertIsString($code);

        // canBeMarkedPaid honoruje výjimku finální-z-proformy (parent_invoice_id),
        // hasPositiveAmountToPay je strict. Pro bank match chceme to první.
        self::assertStringContainsString('InvoiceAmountPolicy::canBeMarkedPaid($invoice)', $code);
        self::assertStringContainsString('InvoiceAmountPolicy::NON_POSITIVE_MARK_PAID_MESSAGE', $code);
    }

    public function testInvoiceListsRenderZeroAmountToPayWithoutFallbackToTotal(): void
    {
        // Regrese: amount_to_pay = 0 nesmí padnout na total_with_vat (zmátlo by
        // uživatele u finálního daňového dokladu k záloze). `??` je správně, `||` špatně.
        $files = [
            '/web/src/pages/invoices/InvoiceList.vue',
            '/web/src/pages/projects/ProjectDetail.vue',
            '/web/src/pages/clients/ClientDetail.vue',
        ];
        $root = dirname(__DIR__, 3);

        foreach ($files as $rel) {
            $code = file_get_contents($root . $rel);
            self::assertIsString($code, "Nenalezen $rel");
            self::assertStringNotContainsString(
                'formatMoney(inv.amount_to_pay || inv.total_with_vat, inv.currency)',
                $code,
                "$rel stále používá `||` fallback — nahraď za `??`."
            );
            self::assertStringContainsString(
                'formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency)',
                $code,
                "$rel nepoužívá očekávaný `??` fallback pro amount_to_pay."
            );
        }
    }

    /**
     * Audit 2026-08-07: zámek odpočtových řádků § 37a musí mít KAŽDÁ cesta, která
     * přepisuje položky přijaté faktury — jinak jednou z nich (PUT /items) šlo
     * odpočtové řádky spárovaného DDKPZ smazat a rozbít rekapitulaci. Logika je
     * ve sdíleném SettlementLockGuard; tenhle test hlídá, že ho obě akce volají.
     *
     * (Substring check je bezpečný vůči BypassFinals — nestaví na `final`/`readonly`,
     * které stream wrapper v testech ze zdroje odstraňuje.)
     */
    public function testBothItemWritePathsCallSettlementLockGuard(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ([
            '/api/src/Action/PurchaseInvoice/SetPurchaseInvoiceItemsAction.php',
            '/api/src/Action/PurchaseInvoice/UpdatePurchaseInvoiceAction.php',
        ] as $rel) {
            $code = file_get_contents($root . $rel);
            self::assertIsString($code, "Nenalezen $rel");
            self::assertStringContainsString(
                'SettlementLockGuard::blockIfDroppingLinkedRows',
                $code,
                "$rel nevolá SettlementLockGuard — odpočtové řádky § 37a jdou touhle cestou smazat."
            );
        }
    }
}
