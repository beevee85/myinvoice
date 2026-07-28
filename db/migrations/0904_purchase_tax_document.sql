-- MyInvoice.cz (fork) — Daňový doklad k přijaté záloze na PŘIJATÉ straně
--
-- Přijatá strana dosud znala jen invoice|receipt|credit_note|advance. Daňový
-- doklad k přijaté záloze (DDKPZ, § 28 odst. 1 písm. d) ZDPH) se tak ukládal
-- jako běžná `invoice` → dvojí náklad vedle konečné faktury a žádná opora pro
-- § 37a (konečná faktura vstupuje do DPH/KH jen rozdílem). Tato migrace přidává:
--
--   • document_kind ENUM += 'tax_document' — zrcadlí vydanou stranu
--     (invoices.invoice_type='tax_document', migrace 0108). DDKPZ vstupuje do
--     DPH/KH jako plnohodnotný daňový doklad a nese náklad za zaplacenou zálohu.
--   • settled_by_purchase_invoice_id — FK NA ŘÁDKU DDKPZ ukazující na konečnou
--     (vyúčtovací) fakturu. N daňových dokladů : 1 konečná faktura (víc záloh
--     k jedné dodávce). ON DELETE SET NULL — smazání konečné faktury nesmí
--     smazat DDKPZ. Vazba DDKPZ → záloha používá stávající
--     advance_purchase_invoice_id (migrace 0064): tím záloha automaticky
--     vypadne z nákladových agregací (NOT EXISTS predikáty) beze změn dotazů.
--   • purchase_invoice_items.settlement_source_purchase_invoice_id — označuje
--     automaticky generované záporné „odpočtové" řádky § 37a na konečné faktuře
--     a jejich zdrojový DDKPZ; unlink podle něj řádky zase odebere.
--
-- Idempotentní: ADD COLUMN/KEY IF NOT EXISTS (MariaDB 10.6+), FK přes
-- DROP FOREIGN KEY IF EXISTS + ADD (vzor migrace 0064).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    MODIFY COLUMN document_kind ENUM('invoice','receipt','credit_note','advance','tax_document')
        NOT NULL DEFAULT 'invoice';

-- purchase_invoices.id je BIGINT UNSIGNED → FK sloupce MUSÍ být taky UNSIGNED.
ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS settled_by_purchase_invoice_id BIGINT UNSIGNED NULL
        COMMENT 'Jen document_kind=tax_document: FK na konečnou (vyúčtovací) fakturu, která tento daňový doklad k záloze zúčtovává (§ 37a)'
        AFTER advance_link_suggested_id;

ALTER TABLE purchase_invoices
    ADD KEY IF NOT EXISTS idx_pi_settled_by (settled_by_purchase_invoice_id);

ALTER TABLE purchase_invoices
    DROP FOREIGN KEY IF EXISTS fk_pi_settled_by;
ALTER TABLE purchase_invoices
    ADD CONSTRAINT fk_pi_settled_by
        FOREIGN KEY (settled_by_purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL;

ALTER TABLE purchase_invoice_items
    ADD COLUMN IF NOT EXISTS settlement_source_purchase_invoice_id BIGINT UNSIGNED NULL
        COMMENT 'Auto-generovaný záporný odpočtový řádek § 37a: FK na zdrojový daňový doklad k záloze (tax_document)'
        AFTER vat_classification_code;

ALTER TABLE purchase_invoice_items
    ADD KEY IF NOT EXISTS idx_pii_settlement_source (settlement_source_purchase_invoice_id);

ALTER TABLE purchase_invoice_items
    DROP FOREIGN KEY IF EXISTS fk_pii_settlement_source;
ALTER TABLE purchase_invoice_items
    ADD CONSTRAINT fk_pii_settlement_source
        FOREIGN KEY (settlement_source_purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL;
