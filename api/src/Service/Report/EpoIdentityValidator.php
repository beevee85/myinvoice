<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Kontrola úplnosti identifikace daňového subjektu PŘED generováním EPO XML
 * (DPHKH1 / DPHDP3 / DPHSHV).
 *
 * EpoSupplierBlockBuilder staví atributy VetaP podmíněně (if !empty) — když
 * pole v nastavení chybí, atribut se prostě vynechá a vznikne validně
 * vypadající XML, které ale EPO portál odmítne. Uživatel to zjistil až na
 * Moje daně, typicky v den lhůty. Tahle služba chybějící pole pojmenuje
 * PŘEDEM: povinná blokují generování (HTTP 422), doporučená jen varují.
 *
 * Záměrně NEsahá do builderů (KontrolniHlaseniBuilder/DphPriznaniBuilder
 * zůstávají beze změny) — volá se z akcí před buildem.
 */
final class EpoIdentityValidator
{
    /** Podporované kódy výkazů (ovlivňují jen doporučená pole). */
    public const DOC_DPHKH1 = 'dphkh1';
    public const DOC_DPHDP3 = 'dphdp3';
    public const DOC_DPHSHV = 'dphshv';

    public function __construct(private readonly \MyInvoice\Infrastructure\Database\Connection $db) {}

    /**
     * Kompletní kontrola pro report akce: načte supplier row a vrátí chybějící
     * povinná pole + lidsky čitelné warningy doporučených. Akce tak přidává
     * jedinou závislost a tři řádky kódu.
     *
     * @return array{missing: list<array{field:string, label:string, why:string}>, warnings: list<string>}
     */
    public function forSupplier(int $supplierId, string $doc): array
    {
        $supplier = $this->loadSupplier($supplierId);
        if ($supplier === null) {
            return ['missing' => [], 'warnings' => []]; // neexistující tenant řeší builder 404/výjimkou
        }
        return [
            'missing'  => $this->validate($supplier, $doc),
            'warnings' => $this->recommendedWarnings($supplier, $doc),
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadSupplier(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT financial_office_code, workplace_code, dic, taxpayer_type, email,
                    phone, cz_nace_code, opr_jmeno, opr_prijmeni, opr_postaveni
               FROM supplier
              WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Chybějící POVINNÁ pole — neprázdný výsledek znamená, že EPO podání
     * s vysokou pravděpodobností selže a XML nemá smysl generovat.
     *
     * Povinné vždy: kód FÚ (c_ufo), kód ÚzP (c_pracufo), DIČ, typ poplatníka,
     * e-mail. U právnické osoby navíc oprávněná osoba (opr_jmeno/prijmeni/
     * postaveni) — bez ní EPO podání PO neprojde.
     *
     * @param array<string,mixed> $supplier řádek `supplier` (loadSupplier builderů)
     * @param string $doc DOC_* konstanta (zatím required pole nediferencuje)
     * @return list<array{field:string, label:string, why:string}>
     */
    public function validate(array $supplier, string $doc): array
    {
        $missing = [];
        $add = static function (string $field, string $label, string $why) use (&$missing): void {
            $missing[] = ['field' => $field, 'label' => $label, 'why' => $why];
        };

        if (self::blank($supplier['financial_office_code'] ?? null)) {
            $add('financial_office_code', 'Kód finančního úřadu',
                'Atribut c_ufo je v EPO povinný — bez něj portál podání odmítne.');
        }
        if (self::blank($supplier['workplace_code'] ?? null)) {
            $add('workplace_code', 'Kód územního pracoviště (ÚzP)',
                'Atribut c_pracufo určuje územní pracoviště FÚ — najdeš na mojedane.gov.cz nebo v hlavičce dřívějšího podání.');
        }
        if (self::blank($supplier['dic'] ?? null)) {
            $add('dic', 'DIČ',
                'Kmenová část DIČ je povinná identifikace plátce ve VetaP.');
        }
        if (self::blank($supplier['taxpayer_type'] ?? null)) {
            $add('taxpayer_type', 'Typ poplatníka (FO/PO)',
                'Určuje typ daňového subjektu (typ_ds F/P) — bez něj hrozí odmítnutí kvůli tvaru DIČ.');
        }
        if (self::blank($supplier['email'] ?? null)) {
            $add('email', 'E-mail',
                'Kontakt pro finanční úřad (atribut email) — EPO ho u podání vyžaduje.');
        }

        if (($supplier['taxpayer_type'] ?? null) === 'po') {
            $oprWhy = 'U právnické osoby EPO vyžaduje oprávněnou osobu k podpisu (jednatel apod.).';
            if (self::blank($supplier['opr_jmeno'] ?? null)) {
                $add('opr_jmeno', 'Jméno oprávněné osoby', $oprWhy);
            }
            if (self::blank($supplier['opr_prijmeni'] ?? null)) {
                $add('opr_prijmeni', 'Příjmení oprávněné osoby', $oprWhy);
            }
            if (self::blank($supplier['opr_postaveni'] ?? null)) {
                $add('opr_postaveni', 'Postavení oprávněné osoby (funkce)', $oprWhy);
            }
        }

        return $missing;
    }

    /**
     * Chybějící DOPORUČENÁ pole — generování neblokují, jen se propíší do
     * warnings[] náhledu: telefon (c_telef) vždy, CZ-NACE (c_okec) u DPHDP3.
     *
     * @param array<string,mixed> $supplier
     * @return list<array{field:string, label:string, why:string}>
     */
    public function recommended(array $supplier, string $doc): array
    {
        $out = [];
        if (self::blank($supplier['phone'] ?? null)) {
            $out[] = ['field' => 'phone', 'label' => 'Telefon',
                      'why' => 'Doporučený kontakt pro FÚ (atribut c_telef) — urychluje řešení výzev.'];
        }
        if ($doc === self::DOC_DPHDP3 && self::blank($supplier['cz_nace_code'] ?? null)) {
            $out[] = ['field' => 'cz_nace_code', 'label' => 'CZ-NACE kód',
                      'why' => 'Bez něj se c_okec v přiznání nevyplní — FÚ může žádat doplnění hlavní činnosti.'];
        }
        return $out;
    }

    /**
     * Doporučená pole jako lidsky čitelné warning řetězce pro summary/preview.
     *
     * @param array<string,mixed> $supplier
     * @return list<string>
     */
    public function recommendedWarnings(array $supplier, string $doc): array
    {
        return array_map(
            static fn (array $r): string => "V daňovém nastavení chybí doporučené pole „{$r['label']}“ — {$r['why']}",
            $this->recommended($supplier, $doc),
        );
    }

    private static function blank(mixed $v): bool
    {
        return $v === null || trim((string) $v) === '';
    }
}
