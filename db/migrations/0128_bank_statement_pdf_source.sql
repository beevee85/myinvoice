-- MyInvoice.cz — zdroj výpisu „pdf" (Upload PDF, deterministický parser dle banky)
--
-- Výpisy dosud rozlišovaly jen 'gpc' (nahraný/importovaný GPC soubor) a 'email_notice'
-- (měsíční agregát e-mailových avíz). Nová cesta „Upload PDF" vytváří bank_statements
-- řádek přímo z PDF výpisu banky (bez GPC ekvivalentu) — parsuje ho bank-specifický
-- parser (Creditas jako první, rozšiřitelné) a transakce zakládá stejně jako GPC import
-- (StatementImporter::importParsedPdf, sdílená matcher/reconciler logika).
--
-- Uložení: PDF bajty jdou do existujících pdf_content/pdf_name/pdf_hash sloupců
-- (migrace 0052) — file_content zůstává NULL (žádný GPC soubor), takže „GPC" download
-- tlačítko se u těchto výpisů korektně nezobrazí (has_file=false), jen „PDF".
--
-- Idempotentní: MODIFY COLUMN lze spustit opakovaně beze změny výsledku.

SET NAMES utf8mb4;

ALTER TABLE bank_statements
    MODIFY COLUMN source ENUM('gpc','email_notice','pdf') NOT NULL DEFAULT 'gpc';
