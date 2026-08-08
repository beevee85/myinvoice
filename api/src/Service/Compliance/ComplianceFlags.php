<?php

declare(strict_types=1);

namespace MyInvoice\Service\Compliance;

/**
 * FORK 0925 — číselník typů compliance příznaků (Dokument 7 §3 + Dokument 8 §8).
 *
 * Závažnost je ODVOZENÁ Z TYPU, nikdy volitelná („kdyby si ji uživatel mohl
 * nastavit, do půl roku bude všechno LOW"). Texty řádků drží frontend
 * (doc_relations/compliance i18n) — sdílený zdroj pro modal i drawer.
 */
final class ComplianceFlags
{
    /** typ => [severity, výchozí právní odkaz, detekční pravidlo] */
    public const TYPES = [
        'HOTOVOST_NAD_LIMIT'        => ['high',   '§ 4 zák. č. 254/2004 Sb.',                'VB3b'],
        'AML_STRUKTUROVANI'         => ['high',   '§ 6 odst. 1 písm. b) zák. č. 253/2008 Sb.', 'VW-AML1'],
        'AML_CHYBI_IDENTIFIKACE'    => ['high',   '§ 7 odst. 1 zák. č. 253/2008 Sb.',        'VW-AML2'],
        'AML_CHYBI_KONTROLA'        => ['high',   '§ 9 zák. č. 253/2008 Sb.',                'VW-AML3'],
        'ODPOCET_ZE_ZALOHY_K_OPRAVE' => ['high',  '§ 74 odst. 2 ZDPH',                       'C1'],
        'POKLADNA_ROZDIL'           => ['high',   '§ 29 zákona č. 563/1991 Sb.',             'VW10'],
        'ODPOCET_PROPADL'           => ['high',   '§ 73 odst. 3 ZDPH',                       'D8'],
        'KH_ROZPOR_OBDOBI'          => ['high',   '§ 101c písm. b) bod 2 ZDPH',              'VB13'],
        'VOZIDLO_LIMIT_420'         => ['medium', '§ 72 odst. 3 ZDPH',                       'VW6'],
        'LHUTA_ODPOCET'             => ['medium', '§ 73 odst. 3 a 4 ZDPH',                   'VW3'],
        'LHUTA_DOKLAD'              => ['medium', '§ 28 odst. 8 a 9 ZDPH',                   'VW9'],
        'DOKLAD_NEDORUCEN'          => ['medium', '§ 28 odst. 11 ZDPH',                      'VW9'],
        'LHUTA_OPRAVA_ZAKLADU'      => ['medium', '§ 42 odst. 8 ZDPH',                       'C2'],
        'KH_CHYBI_DIC'              => ['medium', 'pokyn GFŘ ke kontrolnímu hlášení',        'VW7'],
        'KH_INTERNI_CISLO'          => ['medium', 'náležitosti KH oddíl B.2',                'VW8'],
        'VOZIDLO_BEZ_VIN'           => ['medium', '§ 29 odst. 1 písm. f) ZDPH',              'VW2'],
        'NEDOLOZENE_VYUCTOVANI_37A' => ['low',    '§ 37a ZDPH',                              'VW5'],
        'ZALOHA_NEURCITA'           => ['low',    '§ 20a odst. 3 ZDPH',                      'VB11'],
        'INVENTARIZACE_CHYBI'       => ['low',    '§ 29 zákona č. 563/1991 Sb.',             'A8'],
        'NEGATIVE_CASH'             => ['high',   '§ 8 zákona č. 563/1991 Sb.',              'VB8'],
    ];

    public static function severity(string $type): string
    {
        return self::TYPES[$type][0] ?? 'medium';
    }

    public static function legalReference(string $type): ?string
    {
        return self::TYPES[$type][1] ?? null;
    }

    public static function rule(string $type): ?string
    {
        return self::TYPES[$type][2] ?? null;
    }

    public static function isKnown(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }
}
