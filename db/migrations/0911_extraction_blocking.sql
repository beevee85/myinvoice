-- MyInvoice.cz (fork) — blokující upozornění z AI extrakce
--
-- Rozpor „doklad obsahuje rozpis DPH, ale dodavatel je veden jako neplátce"
-- se dosud ukládal jen do volnotextového `extraction_warning`. To je ale čistě
-- informativní pruh, který se navíc při přechodu z konceptu automaticky maže —
-- doklad tedy šlo zaúčtovat, aniž kdokoli rozpor vyřešil (a odpočet DPH tiše
-- propadl). Zadání přitom žádá BLOKUJÍCÍ hlášku.
--
-- Sloupec drží příznak „upozornění blokuje přechod z konceptu". Uživatel ho
-- shodí buď opravou (nastaví plátcovství / odpočet), nebo vědomým zavřením
-- upozornění (DELETE /purchase-invoices/{id}/extraction-warning).
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS (MariaDB 10.6+).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS extraction_blocking TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Upozornění z extrakce blokuje přechod z konceptu (rozpor plátcovství vs. DPH na dokladu)'
        AFTER extraction_warning;
