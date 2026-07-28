-- MyInvoice.cz (fork) — zaokrouhlovací řádek § 37a
--
-- Rozpis faktury dodavatele a rozpisy daňových dokladů k záloze se mohou lišit
-- o haléř (každý doklad zaokrouhluje po svých řádcích): FV15260816 nese
-- 393 381,83 + 82 610,17, oba DDKPZ dohromady 393 381,82 + 82 610,18 — obojí
-- hrubě 475 992,00. Dosud se rozdíl „rozpouštěl" do zdanitelných položek
-- (444 000,00 se zobrazilo jako 443 999,99), takže se doklad rozcházel s PDF.
--
-- Nově zůstávají VŠECHNY řádky přesně podle dokladů (faktura dle PDF, odpočty
-- dle DDKPZ) a rozdíl nese jeden viditelný řádek „Zaokrouhlení § 37a". Ten se
-- označuje tímto příznakem, aby ho párování umělo přepočítat/odstranit.
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS (MariaDB 10.6+).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoice_items
    ADD COLUMN IF NOT EXISTS is_settlement_rounding TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Auto-generovaný zaokrouhlovací řádek § 37a (rozdíl rozpisů faktury a DDKPZ)'
        AFTER settlement_source_purchase_invoice_id;
