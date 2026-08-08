<?php

declare(strict_types=1);

namespace MyInvoice\Service\Legal;

use MyInvoice\Infrastructure\Config\Config;

/**
 * FORK — právní konstanty pro validace dokladů, pokladny a DPH (Dokument 3, Sekce 1).
 *
 * Jediný zdroj pravdy: žádná z těchto hodnot se NESMÍ psát inline do kódu.
 * Každá hodnota je konfigurovatelná přes `cfg.php` / `cfg.local.php` klíčem
 * `legal.<konstanta_malymi_pismeny>` (např. `legal.cash_payment_limit_czk`),
 * protože část hodnot vychází z rešerše, která není daňovým poradenstvím,
 * a musí jít změnit bez zásahu do kódu.
 *
 * Právní základ a datum účinnosti jsou u každé konstanty v DEFAULTS.
 * Stav rešerše: ověřeno k 8. 8. 2026.
 */
final class LegalConstants
{
    /**
     * Výchozí hodnoty. Klíč = název konstanty (UPPER_SNAKE dle zadání).
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        // § 47 ZDPH — sazby DPH od 1. 1. 2024 (základní 21 %, snížená 12 %, nulová).
        'VAT_RATES' => [21, 12, 0],

        // § 4 odst. 1 zák. č. 254/2004 Sb., o omezení plateb v hotovosti.
        // ⚠ Od 10. 7. 2027 (AMLR, nařízení (EU) 2024/1624) nahradit limitem 10 000 EUR
        //   — viz AMLR_SWITCH_DATE a cashPaymentLimit().
        'CASH_PAYMENT_LIMIT_CZK' => 270000,

        // § 4 odst. 4 tamtéž — sčítají se všechny platby mezi týmiž účastníky
        // v jednom kalendářním dni, v CZK i cizí měně.
        'CASH_LIMIT_SCOPE' => 'per_day_per_counterparty',

        // § 4 odst. 3 tamtéž — cizí měna se přepočítá kurzem ČNB ke dni platby.
        'CASH_LIMIT_FX_SOURCE' => 'CNB_rate_on_payment_date',

        // § 30 odst. 1 ZDPH — zjednodušený daňový doklad do 10 000 Kč vč. DPH.
        'SIMPLIFIED_TAX_DOC_LIMIT_CZK' => 10000,

        // Metodika GFŘ ke kontrolnímu hlášení — hranice A.4/A.5 a B.2/B.3, vč. DPH.
        'KH_ITEMIZATION_LIMIT_CZK' => 10000,

        // § 28 odst. 5 ZDPH — daňový doklad do 15 dnů od DUZP / přijetí úplaty.
        'TAX_DOC_ISSUE_DEADLINE_DAYS' => 15,

        // § 45 ZDPH — opravný daňový doklad do 15 dnů + úsilí o doručení.
        'CORRECTIVE_DOC_DEADLINE_DAYS' => 15,

        // § 73 odst. 3 ZDPH — lhůta pro uplatnění odpočtu. ZMĚNA od 1. 1. 2025
        // (dříve 3 roky, nyní 2).
        'VAT_DEDUCTION_CLAIM_YEARS' => 2,

        // § 42 ZDPH — oprava základu daně. ZMĚNA od 1. 1. 2025 (dříve 3 roky, nyní 7).
        'TAX_BASE_CORRECTION_YEARS' => 7,

        // § 42 ZDPH — u vrácení přijaté úplaty (zálohy) zůstává lhůta 3 roky.
        'ADVANCE_REFUND_CORRECTION_YEARS' => 3,

        // § 72 odst. 4 ZDPH — strop odpočtu u vybraného osobního automobilu
        // v DLOUHODOBÉM MAJETKU (NE u zboží k dalšímu prodeji!). Viz
        // cars.acquisition_purpose (migrace 0921).
        'CAR_VAT_DEDUCTION_CAP_CZK' => 420000,

        // § 4 ZDPH — nový dopravní prostředek: do 6 měsíců od prvního uvedení
        // do provozu NEBO méně než 6 000 km (stačí jedna podmínka).
        'NEW_VEHICLE_MONTHS' => 6,
        'NEW_VEHICLE_KM' => 6000,

        // § 35 ZDPH — archivace daňových dokladů (rozhodující pro plátce DPH).
        'ARCHIVE_YEARS_TAX_DOCS' => 10,

        // § 31 odst. 2 písm. b) ZoÚ — účetní doklady, knihy, inventurní soupisy.
        'ARCHIVE_YEARS_ACCOUNTING' => 5,

        // § 31 odst. 2 písm. a) ZoÚ — účetní závěrka, výroční zpráva.
        'ARCHIVE_YEARS_STATEMENTS' => 10,

        // § 101e ZDPH — kontrolní hlášení do 25. dne měsíce; lhůtu NELZE prodloužit.
        'KH_DEADLINE_DAY_OF_MONTH' => 25,

        // § 101g ZDPH — reakce na výzvu správce daně do 5 pracovních dnů.
        'KH_NOTICE_RESPONSE_WORKDAYS' => 5,

        // ⚠ TODO-1 (NEOVĚŘENO): vychází z praxe, nikoli z výslovného § 29/30 ZoÚ.
        // Proto konfigurovatelné; před vynucováním ověřit u účetní/daňového poradce.
        'CASH_INVENTORY_MIN_PER_YEAR' => 4,

        // § 90 odst. 3 ZDPH — zvláštní režim přirážky: záporná přirážka → základ 0.
        'MARGIN_SCHEME_MIN_BASE_CZK' => 0,

        // § 90 odst. 4 ZDPH — souhrnná přirážka jen u kusů do 1 000 Kč → u vozidel NELZE.
        'MARGIN_SCHEME_SUMMARY_LIMIT_CZK' => 1000,

        // Nařízení (EU) 2024/1624 (AMLR) — od tohoto data limit hotovosti 10 000 EUR.
        'AMLR_SWITCH_DATE' => '2027-07-10',

        // AMLR čl. 80 — limit po přepnutí (viz cashPaymentLimit()).
        'AMLR_CASH_LIMIT_EUR' => 10000,

        // ── AML (zák. č. 253/2008 Sb., ve znění novely 280/2025 Sb.; Dokument 4) ──
        // Prahy jsou v EUR (limit ZOPH výše je v CZK — NESMĚŠOVAT); přepočet kurzem
        // ČNB ke dni obchodu (AML_FX_SOURCE).

        // § 7 odst. 1 — povinná identifikace klienta od hodnoty obchodu 1 000 EUR.
        'AML_IDENTIFICATION_THRESHOLD_EUR' => 1000,

        // § 9 odst. 1 písm. d) ve spojení s § 2 odst. 2 písm. c) — kontrola klienta
        // u hotovostního obchodu od 10 000 EUR.
        'AML_CDD_THRESHOLD_CASH_EUR' => 10000,

        // § 9 odst. 1 písm. a) bod 1 — obecný práh kontroly klienta 15 000 EUR.
        'AML_CDD_THRESHOLD_GENERAL_EUR' => 15000,

        // § 2 odst. 2 písm. c) — práh vzniku statusu povinné osoby (hotovost).
        'AML_OBLIGED_ENTITY_CASH_EUR' => 10000,

        // § 6 odst. 1 písm. b) — strukturování: „jeden den nebo dny bezprostředně
        // následující"; zákon okno nečísluje, výchozí 3 dny, konfigurovatelné.
        'AML_STRUCTURING_WINDOW_DAYS' => 3,

        // § 18 odst. 1 — oznámení podezřelého obchodu „bez zbytečného odkladu".
        'AML_REPORT_DEADLINE' => 'bez_zbytecneho_odkladu',

        // Přepočet EUR→CZK pro AML prahy — kurz ČNB ke dni obchodu.
        'AML_FX_SOURCE' => 'CNB_rate_on_transaction_date',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Hodnota konstanty s možností override v cfg: `legal.<nazev_malymi>`.
     * Neznámý název je programátorská chyba → výjimka, ne tiché null.
     */
    public function value(string $name): mixed
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Neznámá právní konstanta: {$name}");
        }
        $override = $this->config->get('legal.' . strtolower($name));
        return $override ?? self::DEFAULTS[$name];
    }

    /** @return list<int|float> Platné sazby DPH (§ 47 ZDPH). */
    public function vatRates(): array
    {
        return array_values((array) $this->value('VAT_RATES'));
    }

    /**
     * Limit plateb v hotovosti k danému dni.
     *
     * Do 9. 7. 2027: 270 000 Kč / den / protistranu (§ 4 zák. 254/2004 Sb.).
     * Od 10. 7. 2027 (AMLR): 10 000 EUR — vrací se v EUR, přepočet kurzem ČNB
     * ke dni platby řeší volající (CASH_LIMIT_FX_SOURCE).
     *
     * @return array{amount: float, currency: string}
     */
    public function cashPaymentLimit(?\DateTimeInterface $onDate = null): array
    {
        $onDate ??= new \DateTimeImmutable('today');
        $switch = new \DateTimeImmutable((string) $this->value('AMLR_SWITCH_DATE'));
        if ($onDate >= $switch) {
            return ['amount' => (float) $this->value('AMLR_CASH_LIMIT_EUR'), 'currency' => 'EUR'];
        }
        return ['amount' => (float) $this->value('CASH_PAYMENT_LIMIT_CZK'), 'currency' => 'CZK'];
    }

    /** § 30 odst. 1 ZDPH — limit zjednodušeného daňového dokladu vč. DPH. */
    public function simplifiedTaxDocLimitCzk(): float
    {
        return (float) $this->value('SIMPLIFIED_TAX_DOC_LIMIT_CZK');
    }

    /** § 28 odst. 5 ZDPH — lhůta pro vystavení daňového dokladu (dny). */
    public function taxDocIssueDeadlineDays(): int
    {
        return (int) $this->value('TAX_DOC_ISSUE_DEADLINE_DAYS');
    }

    /** Metodika GFŘ ke KH — hranice pro A.4/A.5 a B.2/B.3 vč. DPH. */
    public function khItemizationLimitCzk(): float
    {
        return (float) $this->value('KH_ITEMIZATION_LIMIT_CZK');
    }

    /** § 72 odst. 4 ZDPH — strop odpočtu u osobního automobilu v majetku. */
    public function carVatDeductionCapCzk(): float
    {
        return (float) $this->value('CAR_VAT_DEDUCTION_CAP_CZK');
    }
}
