<?php

declare(strict_types=1);

namespace MyInvoice\Service\Legal;

use MyInvoice\Infrastructure\Config\Config;

/**
 * FORK — právní konstanty pro validace dokladů, pokladny a DPH.
 *
 * Zdroj: Dokument 3 (sekce 1) + Dokument 4 (sekce 3) + **Dokument 6** (sekce A a D,
 * ověřeno z primárních zdrojů na Beck-online 8. 8. 2026). Dokument 6 má přednost —
 * opravuje šest chybných odkazů na odstavce z dřívějších rešerší.
 *
 * Zásady:
 *  - Jediný zdroj pravdy: žádná z těchto hodnot se NESMÍ psát inline do kódu.
 *  - Každá hodnota je konfigurovatelná přes `cfg.php` / `cfg.local.php` klíčem
 *    `legal.<konstanta_malymi_pismeny>`.
 *  - Hodnoty mají ČASOVOU PLATNOST (from/to) — doklad se starým datem se musí
 *    validovat podle konstant platných k jeho datu, ne podle dnešních
 *    (Dokument 6, sekce D + akceptační kritérium F10). Použij valueAt().
 *
 * POZOR (Dokument 6, VB10): český ZDPH NEZNÁ „nulovou sazbu". § 47 odst. 1 zná jen
 * základní 21 % a sníženou 12 %. Osvobození (§ 51, § 63, § 71i), přenesení daňové
 * povinnosti (§ 92a), režim § 90 a plnění mimo předmět daně jsou JINÉ PRÁVNÍ
 * INSTITUTY — v aplikaci je nesou kódy číselníku vat_rates (CZ-0, CZ-NA, …),
 * nikdy položka „0 %" v seznamu sazeb.
 */
final class LegalConstants
{
    /**
     * Výchozí hodnoty. Klíč = název konstanty (UPPER_SNAKE dle zadání).
     *
     * Formát hodnoty:
     *  - skalár/pole  = platí bez časového omezení,
     *  - list období  = [['from' => 'Y-m-d'|null, 'to' => 'Y-m-d'|null, 'value' => mixed], …]
     *    (období se prochází v pořadí; první, které obsahuje dotazované datum, vyhrává).
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        // § 47 odst. 1 ZDPH — POUZE platné sazby daně (žádná „0 %", viz hlavička).
        // Do 31. 12. 2023: 21/15/10; od 1. 1. 2024 (konsolidační balíček): 21/12.
        'VAT_RATES' => [
            ['from' => null,         'to' => '2023-12-31', 'value' => [21, 15, 10]],
            ['from' => '2024-01-01', 'to' => null,         'value' => [21, 12]],
        ],

        // § 4 odst. 1 zák. č. 254/2004 Sb. (ZOPH), znění od 1. 7. 2017.
        // ⚠ Od 10. 7. 2027 (AMLR, nařízení (EU) 2024/1624) limit 10 000 EUR
        //   — viz AMLR_SWITCH_DATE a cashPaymentLimit().
        'CASH_PAYMENT_LIMIT_CZK' => [
            ['from' => '2017-07-01', 'to' => null, 'value' => 270000],
        ],

        // § 4 odst. 4 ZOPH — sčítají se všechny platby mezi týmiž účastníky
        // v jednom kalendářním dni, v CZK i cizí měně.
        'CASH_LIMIT_SCOPE' => 'per_day_per_counterparty',

        // § 4 odst. 3 ZOPH — cizí měna kurzem ČNB ke dni platby.
        'CASH_LIMIT_FX_SOURCE' => 'CNB_rate_on_payment_date',

        // § 30 odst. 1 ZDPH — zjednodušený daňový doklad do 10 000 Kč vč. daně.
        // § 30 odst. 2: NELZE u dodání do JČS osvobozeného s nárokem na odpočet,
        // prodeje na dálku, přenesené daňové povinnosti a tabáku za jiné než pevné ceny.
        'SIMPLIFIED_TAX_DOC_LIMIT_CZK' => 10000,

        // ⚠ POKYN GFŘ ke kontrolnímu hlášení, NIKOLI ZÁKON (§ 101c–101k hranici
        // neuvádí) — Dokument 6, E1. V textech hlášek NECITOVAT jako zákon.
        'KH_ITEMIZATION_LIMIT_CZK' => 10000,

        // § 28 odst. 8 ZDPH (Dokument 6 — oprava, dřív chybně odst. 5): daňový
        // doklad do 15 dnů ode dne vzniku povinnosti přiznat daň nebo plnění.
        'TAX_DOC_ISSUE_DEADLINE_DAYS' => 15,

        // § 28 odst. 9 ZDPH — u dodání do JČS, přeshraničních služeb a úplat
        // k nim: 15 dnů OD KONCE KALENDÁŘNÍHO MĚSÍCE, ve kterém plnění nastalo.
        // Samostatné počítadlo, nesměšovat s odst. 8! (+ § 28 odst. 11: povinnost
        // vynaložit úsilí o doručení dokladu příjemci ve lhůtě pro vystavení.)
        'TAX_DOC_ISSUE_DEADLINE_MONTH_END_DAYS' => 15,

        // § 42 odst. 5 ZDPH (Dokument 6 — oprava, dřív chybně § 45): opravný
        // daňový doklad do 15 dnů + povinnost vynaložit úsilí o doručení.
        'CORRECTIVE_DOC_DEADLINE_DAYS' => 15,

        // § 73 odst. 3 ZDPH — nárok na odpočet NELZE uplatnit po uplynutí DRUHÉHO
        // KALENDÁŘNÍHO ROKU bezprostředně následujícího po roce vzniku nároku.
        // POZOR: počítá se na KONCE ROKŮ (deadline = 31. 12. (rok_vzniku + N)),
        // ne „N let od data" (Dokument 6, A6). Do 31. 12. 2024 N=3, od 2025 N=2
        // (novela 461/2024 Sb.) — proto časová platnost dle roku vzniku nároku.
        'VAT_DEDUCTION_CLAIM_YEARS' => [
            ['from' => null,         'to' => '2024-12-31', 'value' => 3],
            ['from' => '2025-01-01', 'to' => null,         'value' => 2],
        ],

        // § 42 odst. 8 ZDPH — opravu základu daně nelze provést po konci SEDMÉHO
        // kalendářního roku po roce vzniku povinnosti přiznat daň u původního plnění.
        'TAX_BASE_CORRECTION_YEARS' => 7,

        // § 42 odst. 8 ZDPH — 3 roky od konce zdaňovacího období přijetí úplaty,
        // pokud se plnění ještě neuskutečnilo (vrácení zálohy).
        'ADVANCE_REFUND_CORRECTION_YEARS' => 3,

        // § 72 odst. 3 ZDPH (Dokument 6 — oprava, dřív chybně odst. 4): u vybraného
        // osobního automobilu v DLOUHODOBÉM MAJETKU se za daň na vstupu považuje
        // nejvýše 420 000 Kč. NE u zboží k dalšímu prodeji (cars.acquisition_purpose).
        // § 72 odst. 4 = navazující režim technického zhodnocení (souhrnný limit).
        // § 72 odst. 10 = definice: kategorie M1, není sanitní/pohřební/koncese/sportovní.
        // Výjimka pro osoby se zdravotním postižením se NEPOTVRDILA (Dokument 6, E3)
        // — neimplementovat.
        'CAR_VAT_DEDUCTION_CAP_CZK' => [
            ['from' => '2024-01-01', 'to' => null, 'value' => 420000],
        ],

        // § 19 ZDPH (Dokument 6 — oprava, dřív chybně § 4): nový dopravní prostředek
        // = motorové pozemní vozidlo se zdvihovým objemem > 48 cm³ NEBO výkonem
        // > 7,2 kW, a (dodání do 6 měsíců od prvního uvedení do provozu NEBO najeto
        // NEJVÝŠE 6 000 km — tedy „<=", ne „<"). První uvedení do provozu: § 19/2.
        'NEW_VEHICLE_MONTHS' => 6,
        'NEW_VEHICLE_MAX_KM' => 6000,
        'NEW_VEHICLE_MIN_ENGINE_CC' => 48,
        'NEW_VEHICLE_MIN_POWER_KW' => 7.2,

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

        // Dokument 6, A8: povinnost „4× ročně" v ZoÚ NEEXISTUJE (číslo 4 je
        // z § 30 odst. 6 písm. a) — inventuru lze zahájit nejdřív 4 měsíce před
        // rozvahovým dnem). Peněžní prostředky se inventarizují periodicky
        // k rozvahovému dni (§ 29 odst. 2 průběžnou inventarizaci umožňuje jen
        // u zásob a DHM). Výchozí 1× ročně; vyšší četnost si účetní jednotka
        // může stanovit vnitřním předpisem (pak přenastavit v cfg).
        'CASH_INVENTORY_MIN_PER_YEAR' => 1,

        // § 29 odst. 3 ZoÚ — provedení inventarizace se prokazuje 5 let.
        'INVENTORY_PROOF_RETENTION_YEARS' => 5,

        // § 30 odst. 6 ZoÚ — okno inventury: zahájení nejdřív 4 měsíce před
        // rozvahovým dnem, ukončení nejpozději 2 měsíce po něm.
        'INVENTORY_START_MONTHS_BEFORE' => 4,
        'INVENTORY_END_MONTHS_AFTER' => 2,

        // § 90 odst. 3 ZDPH — režim přirážky: záporná přirážka → základ 0.
        'MARGIN_SCHEME_MIN_BASE_CZK' => 0,

        // § 90 odst. 4 ZDPH — souhrnná přirážka jen u jednotkové pořizovací ceny
        // do 1 000 Kč → u vozidel vždy individuálně.
        'MARGIN_SCHEME_SUMMARY_LIMIT_CZK' => 1000,

        // § 90 odst. 14 ZDPH (Dokument 6, A4/C3) — na dokladu PRÁVĚ JEDEN z těchto
        // textů dle druhu zboží; zákaz samostatného uvedení daně z přirážky.
        // § 90 odst. 12: režim NELZE při dodání nového dopravního prostředku do JČS
        // ani u zboží, u něhož byl při pořízení uplatněn odpočet.
        'MARGIN_SCHEME_DOC_TEXTS' => [
            'used_goods'   => 'zvláštní režim – použité zboží',
            'art'          => 'zvláštní režim – umělecká díla',
            'collectibles' => 'zvláštní režim – sběratelské předměty a starožitnosti',
        ],

        // Nařízení (EU) 2024/1624 (AMLR) — od tohoto data limit hotovosti 10 000 EUR.
        'AMLR_SWITCH_DATE' => '2027-07-10',

        // AMLR čl. 80 — limit po přepnutí (viz cashPaymentLimit()).
        'AMLR_CASH_LIMIT_EUR' => 10000,

        // ── AML (zák. č. 253/2008 Sb., ve znění novely 280/2025 Sb.) ──
        // Prahy v EUR (limit ZOPH výše v CZK — NESMĚŠOVAT); přepočet kurzem ČNB
        // ke dni obchodu (AML_FX_SOURCE).

        // § 7 odst. 1 — povinná identifikace klienta od hodnoty obchodu 1 000 EUR.
        'AML_IDENTIFICATION_THRESHOLD_EUR' => 1000,

        // § 9 odst. 1 písm. d) ve spojení s § 2 odst. 2 písm. c) — kontrola
        // klienta u hotovostního obchodu od 10 000 EUR.
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
     * Hodnota konstanty platná K DANÉMU DATU (výchozí dnes).
     *
     * Override v cfg (`legal.<nazev_malymi>`) může být buď skalár (platí vždy),
     * nebo stejný list období jako v DEFAULTS. Neznámý název je programátorská
     * chyba → výjimka, ne tiché null.
     */
    public function valueAt(string $name, ?\DateTimeInterface $onDate = null): mixed
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Neznámá právní konstanta: {$name}");
        }
        $raw = $this->config->get('legal.' . strtolower($name)) ?? self::DEFAULTS[$name];
        return self::resolvePeriod($raw, $onDate ?? new \DateTimeImmutable('today'));
    }

    /** Zkratka pro valueAt() k dnešnímu dni. */
    public function value(string $name): mixed
    {
        return $this->valueAt($name);
    }

    /**
     * @return list<int|float> Platné sazby daně k danému dni (§ 47/1; sazba se dle
     *                         § 47/2 určuje ke dni vzniku povinnosti přiznat daň —
     *                         u zálohy ke dni přijetí úplaty, § 37a ji nepřepočítává).
     */
    public function vatRates(?\DateTimeInterface $onDate = null): array
    {
        return array_values((array) $this->valueAt('VAT_RATES', $onDate));
    }

    /**
     * Limit plateb v hotovosti k danému dni.
     *
     * Do 9. 7. 2027: 270 000 Kč / den / protistranu (§ 4 ZOPH). Od 10. 7. 2027
     * (AMLR): 10 000 EUR — vrací se v EUR, přepočet kurzem ČNB ke dni platby
     * řeší volající (CASH_LIMIT_FX_SOURCE).
     *
     * @return array{amount: float, currency: string}
     */
    public function cashPaymentLimit(?\DateTimeInterface $onDate = null): array
    {
        $onDate ??= new \DateTimeImmutable('today');
        $switch = new \DateTimeImmutable((string) $this->valueAt('AMLR_SWITCH_DATE', $onDate));
        if ($onDate >= $switch) {
            return ['amount' => (float) $this->valueAt('AMLR_CASH_LIMIT_EUR', $onDate), 'currency' => 'EUR'];
        }
        return ['amount' => (float) $this->valueAt('CASH_PAYMENT_LIMIT_CZK', $onDate), 'currency' => 'CZK'];
    }

    /**
     * Poslední den, kdy lze uplatnit odpočet z nároku vzniklého $claimArose.
     *
     * § 73 odst. 3: konec druhého (do 2024: třetího) kalendářního roku
     * bezprostředně následujícího po roce vzniku nároku — počítá se na konce
     * roků, ne „N let od data" (Dokument 6, A6).
     */
    public function vatDeductionDeadline(\DateTimeInterface $claimArose): \DateTimeImmutable
    {
        $years = (int) $this->valueAt('VAT_DEDUCTION_CLAIM_YEARS', $claimArose);
        return new \DateTimeImmutable(sprintf('%d-12-31', (int) $claimArose->format('Y') + $years));
    }

    /** § 30 odst. 1 ZDPH — limit zjednodušeného daňového dokladu vč. daně. */
    public function simplifiedTaxDocLimitCzk(?\DateTimeInterface $onDate = null): float
    {
        return (float) $this->valueAt('SIMPLIFIED_TAX_DOC_LIMIT_CZK', $onDate);
    }

    /** § 28 odst. 8 ZDPH — lhůta pro vystavení daňového dokladu (dny). */
    public function taxDocIssueDeadlineDays(?\DateTimeInterface $onDate = null): int
    {
        return (int) $this->valueAt('TAX_DOC_ISSUE_DEADLINE_DAYS', $onDate);
    }

    /** Pokyn GFŘ ke KH (NE zákon — E1) — hranice pro A.4/A.5 a B.2/B.3 vč. daně. */
    public function khItemizationLimitCzk(?\DateTimeInterface $onDate = null): float
    {
        return (float) $this->valueAt('KH_ITEMIZATION_LIMIT_CZK', $onDate);
    }

    /** § 72 odst. 3 ZDPH — strop daně na vstupu u vybraného osobního automobilu. */
    public function carVatDeductionCapCzk(?\DateTimeInterface $onDate = null): float
    {
        return (float) $this->valueAt('CAR_VAT_DEDUCTION_CAP_CZK', $onDate);
    }

    /** Rozbalí případný list období na hodnotu platnou k datu. */
    private static function resolvePeriod(mixed $raw, \DateTimeInterface $onDate): mixed
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === []
            || !is_array($raw[0]) || !array_key_exists('value', $raw[0])) {
            return $raw; // skalár nebo obyčejné pole (např. VAT texty) — platí vždy
        }
        $day = $onDate->format('Y-m-d');
        foreach ($raw as $period) {
            $from = $period['from'] ?? null;
            $to   = $period['to'] ?? null;
            if (($from === null || $day >= $from) && ($to === null || $day <= $to)) {
                return $period['value'];
            }
        }
        throw new \RuntimeException("Právní konstanta nemá hodnotu platnou k {$day}.");
    }
}
