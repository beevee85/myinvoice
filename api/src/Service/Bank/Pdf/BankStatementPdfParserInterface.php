<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

/**
 * Bank-specifický parser PDF výpisu z banky (protějšek GpcParser pro banky, které
 * nenabízejí GPC/ABO export, jen PDF). Vrací STEJNÝ tvar jako {@see \MyInvoice\Service\Bank\GpcParser}
 * (`['header' => [...], 'transactions' => [...]]`), aby ho šlo persistovat přes
 * {@see \MyInvoice\Service\Bank\StatementImporter::importParsedPdf()}.
 *
 * PŘIDÁNÍ NOVÉ BANKY: nová třída implements toto rozhraní + zaregistrovat v Bootstrapu
 * do BankStatementPdfParserRegistry (pořadí = priorita, první supports()=true vyhrává).
 */
interface BankStatementPdfParserInterface
{
    /** Stabilní klíč parseru (log, diagnostika). */
    public function key(): string;

    /** Rozpozná parser, že text/PDF je z jeho banky (hlavička, layout, patička)? */
    public function supports(string $text): bool;

    /**
     * Naparsuje PDF na stejný tvar jako GpcParser::parse().
     *
     * Musí interně provést self-check (součet transakcí musí sedět na hlavičkové
     * zůstatky/součty z dokladu) a vyhodit \RuntimeException, pokud parsing selže
     * nebo self-check nesedí — nikdy nevracet částečná/nedůvěryhodná finanční data.
     *
     * @return array{header: array{account_number:string, statement_date:string,
     *   statement_number:string, prev_balance:float, curr_balance:float,
     *   debit_total:float, credit_total:float},
     *   transactions: list<array<string,mixed>>}
     */
    public function parse(string $pdfBytes, string $text): array;
}
