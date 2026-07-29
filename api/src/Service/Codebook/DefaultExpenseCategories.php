<?php

declare(strict_types=1);

namespace MyInvoice\Service\Codebook;

use PDO;

/**
 * Výchozí číselník kategorií nákladu pro nově zakládaného tenanta.
 *
 * Kategorie pohánějí rozpad nákladů na dashboardu a v CRM. Bez předvyplněného
 * číselníku startoval každý tenant s prázdným seznamem, uživatel kategorie
 * nezadával a rozpad nákladů zůstal nepoužitelný („nezařazeno" = 100 %).
 *
 * Sada je záměrně OBECNÁ (ne oborová) — odpovídá běžnému členění nákladů a je
 * použitelná napříč obory. Uživatel si ji může přejmenovat, doplnit i archivovat.
 * Tutéž sadu doplňuje stávajícím tenantům migrace 0912.
 *
 * Použití: {@see \MyInvoice\Action\Settings\SettingsAction} (přidání firmy)
 * a {@see \MyInvoice\Action\Auth\SetupAction} (první firma při instalaci).
 */
final class DefaultExpenseCategories
{
    /** @var list<array{0:string,1:string,2:string,3:int}> code, label, fixed|variable, pořadí */
    public const DEFAULTS = [
        ['zbozi',       'Zboží k dalšímu prodeji',     'variable', 10],
        ['material',    'Materiál a spotřeba',         'variable', 20],
        ['sluzby',      'Služby',                      'variable', 30],
        ['najem',       'Nájem a energie',             'fixed',    40],
        ['doprava',     'Doprava a PHM',               'variable', 50],
        ['marketing',   'Marketing a reklama',         'variable', 60],
        ['software',    'Software a IT',               'fixed',    70],
        ['poradenstvi', 'Odborné a poradenské služby', 'variable', 80],
        ['majetek',     'Dlouhodobý majetek',          'variable', 90],
        ['ostatni',     'Ostatní',                     'variable', 100],
    ];

    /**
     * Naseeduje výchozí kategorie tenantovi. Idempotentní — pokud už tenant
     * jakoukoli kategorii má, neudělá nic (nepřepisujeme vlastní číselník).
     *
     * @return int počet vložených kategorií
     */
    public static function seed(PDO $pdo, int $supplierId): int
    {
        if ($supplierId <= 0) {
            return 0;
        }
        $has = $pdo->prepare('SELECT 1 FROM expense_categories WHERE supplier_id = ? LIMIT 1');
        $has->execute([$supplierId]);
        if ($has->fetchColumn() !== false) {
            return 0;
        }

        $ins = $pdo->prepare(
            'INSERT INTO expense_categories (supplier_id, code, label, fixed_or_var, display_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        $n = 0;
        foreach (self::DEFAULTS as [$code, $label, $fixedOrVar, $order]) {
            $ins->execute([$supplierId, $code, $label, $fixedOrVar, $order]);
            $n++;
        }
        return $n;
    }
}
