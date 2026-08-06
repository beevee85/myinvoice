<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseBatchImport;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseImportBatchRepository;

/**
 * FORK (beevee85) — Commit 10: generátor promptu pro dávkovou extrakci.
 *
 * Prompt je ARTEFAKT, KTERÝ ODCHÁZÍ ZE SYSTÉMU — čte ho nástroj mimo aplikaci.
 * Proto platí dvě věci, které u interního kódu neplatí:
 *
 *   1. Ven jde jen to, co je k extrakci nutné. Identifikace tenanta (IČO, DIČ,
 *      název) tam být musí, aby extrakce poznala, že tahle firma je VŽDY
 *      odběratel a nikdy dodavatel — bez toho se u faktur s dominantní
 *      hlavičkou dodavatele prohodí strany (V16). Nic dalšího tam nepatří.
 *   2. Soubory se adresují `sha256`, ne jménem ani pořadím. Výsledek se pak
 *      páruje zpět na manifest (V4) a nejde podstrčit doklad, který v dávce
 *      nebyl (V5).
 *
 * DETERMINISMUS (V85): týž stav dávky dá bajtově týž prompt. Žádný čas, žádné
 * náhodné pořadí — soubory jsou seřazené podle `sha256` stejně jako v manifestu.
 * Bez toho by se nedalo ověřit, že se prompt mezi dvěma běhy nezměnil.
 *
 * PRAVIDLO O TEXTOVÝCH ŘÁDCÍCH (§13, delta V43) je v promptu záměrně, ne jako
 * ozdoba: stínová validace nad historií ukázala, že popisné řádky s nulovým
 * množstvím jsou legitimní jev, ale extrakce je vyrábí i tam, kde nemá. Levnější
 * je říct to rovnou než to potom validovat.
 */
final class PromptBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseImportBatchRepository $repo,
    ) {}

    /**
     * @return array{prompt: string, files: list<array<string,mixed>>}
     * @throws BatchLimitException když dávka neexistuje nebo patří jinému tenantovi
     */
    public function build(int $batchId, int $supplierId): array
    {
        $batch = $this->repo->find($batchId, $supplierId);
        if ($batch === null) {
            throw new BatchLimitException('batch_not_found', 'Dávka neexistuje nebo nepatří tomuto tenantovi.');
        }

        $files = $this->repo->filesForBatch($batchId, $supplierId);
        if ($files === []) {
            throw new BatchLimitException('empty_batch', 'Dávka neobsahuje žádný soubor.');
        }

        // Totéž řazení jako v manifestu — jinak by prompt a manifest mluvily
        // o téže dávce v jiném pořadí a párování by záviselo na náhodě.
        usort($files, static fn (array $a, array $b) => strcmp((string) $a['sha256'], (string) $b['sha256']));

        return [
            'prompt' => $this->tenantBlock($supplierId)
                . $this->fileBlock($batchId, $files)
                . $this->rules()
                . $this->schema(),
            'files' => $files,
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * Identifikace tenanta. Bez ní extrakce nepozná, kdo je odběratel — a u
     * faktury s dominantní hlavičkou dodavatele prohodí strany.
     *
     * Když se tenant nepodaří načíst, blok se vynechá a prompt je bez něj —
     * žádný hard fail, jen slabší výsledek. Stejný přístup jako u BYOK cesty.
     */
    private function tenantBlock(int $supplierId): string
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT company_name, ic, dic FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $t = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return '';
        }
        if ($t === false || ($t['company_name'] ?? '') === '' && ($t['ic'] ?? '') === '') {
            return '';
        }

        $name = (string) ($t['company_name'] ?? '');
        $ic   = (string) ($t['ic'] ?? '');
        $dic  = (string) ($t['dic'] ?? '');

        return <<<TXT
        ODBĚRATEL (naše firma — na každém dokladu je to VŽDY ona, NIKDY dodavatel):
          název: {$name}
          IČO:   {$ic}
          DIČ:   {$dic}

        Když se na dokladu tahle firma objeví na straně dodavatele, je to chyba čtení,
        ne vlastnost dokladu — strany prohoď. Když se na dokladu nevyskytuje vůbec,
        nevymýšlej si ji: nastav `customer_matches_tenant` na false a pokračuj.


        TXT;
    }

    /** @param list<array<string,mixed>> $files */
    private function fileBlock(int $batchId, array $files): string
    {
        $lines = '';
        foreach ($files as $f) {
            $lines .= sprintf(
                "  - sha256: %s   (%d B)   původní název: %s\n",
                (string) $f['sha256'],
                (int) $f['byte_size'],
                (string) $f['original_name'],
            );
        }

        $count = count($files);

        return <<<TXT
        DÁVKA #{$batchId} — {$count} dokladů:
        {$lines}
        Soubory jsou na disku pojmenované `<sha256>.pdf`. V odpovědi každý doklad
        adresuj jeho `sha256` — ne jménem, ne pořadím. Původní název je jen pro
        člověka a nesmí se použít jako identifikátor.


        TXT;
    }

    private function rules(): string
    {
        return <<<'TXT'
        PRAVIDLA:

        1. Nevymýšlej si. Když údaj na dokladu není, vrať null. Prázdná hodnota je
           platný výsledek; vymyšlená hodnota je chyba, kterou nikdo nezachytí.

        2. Částky opisuj tak, jak jsou vytištěné — jako řetězec, s desetinnou
           tečkou, bez oddělovačů tisíců a bez vědecké notace. Nepřepočítávej,
           nezaokrouhluj, nedopočítávej chybějící. Kontrolní součty si uděláme sami.

        3. Volný text z dokladu patří do `note_above_items` nebo `note_below_items`,
           případně do `description` peněžního řádku — NE do samostatných řádků
           s nulovým množstvím a nulovou cenou. Takový řádek vytvářej jen tehdy,
           když na dokladu opravdu je jako samostatná položka, a musí mít neprázdný
           popis. Nikdy ho nevyráběj jen proto, že se text vizuálně nachází mezi
           položkami.

        4. Každý peněžní řádek musí mít nenulové množství i jednotkovou cenu.
           Řádek, který uvádí cenu bez množství, je chybně přečtený — přečti ho znovu.

        5. U zálohové faktury, daňového dokladu k záloze a konečné faktury opiš
           i vazby mezi nimi (číslo dokladu, variabilní symbol), pokud jsou uvedené.
           Neodvozuj je z podobnosti částek.

        6. Na dokladu, kterému nerozumíš, se nesnaž uhodnout. Nastav
           `confidence: "low"` a do `notes` napiš proč. Odmítnutý doklad je
           levnější než špatně přečtený.


        TXT;
    }

    private function schema(): string
    {
        return <<<'TXT'
        ODPOVĚĎ: jediný JSON objekt, bez markdown, bez komentáře před ani za.

        {
          "schema": "myinvoice.purchase-import-batch.results/1",
          "documents": [
            {
              "sha256": "<sha256 souboru z dávky>",
              "confidence": "high" | "medium" | "low",
              "document_kind": "invoice" | "advance" | "tax_document" | "credit_note" | "receipt",
              "vendor": {
                "company_name": string,
                "ic": string|null,
                "dic": string|null
              },
              "customer_matches_tenant": boolean,
              "numbers": {
                "vendor_invoice_number": string,
                "varsymbol": string|null
              },
              "dates": {
                "issue_date": "YYYY-MM-DD",
                "tax_date": "YYYY-MM-DD"|null,
                "due_date": "YYYY-MM-DD"|null
              },
              "totals": {
                "base": string,
                "vat": string,
                "total": string,
                "currency": "CZK"|"EUR"|...
              },
              "items": [
                {
                  "description": string,
                  "quantity": string,
                  "unit_price_without_vat": string,
                  "vat_rate": string,
                  "line_base": string
                }
              ],
              "note_above_items": string|null,
              "note_below_items": string|null,
              "linked_documents": [
                { "relation": "advance_for" | "settles", "number": string }
              ],
              "notes": string|null
            }
          ]
        }

        Pole `documents` musí obsahovat právě tolik položek, kolik je souborů
        v dávce, a každé `sha256` právě jednou.
        TXT;
    }
}
