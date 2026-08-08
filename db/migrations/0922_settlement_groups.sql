-- 0922 — vyúčtovací skupiny (SettlementGroup, Dokument 1 bod B1)
--
-- FORK (beevee85). Obchodní případ = řetězec dokladů k jednomu plnění
-- (zálohová faktura → daňový doklad k záloze/platbě → konečná faktura).
-- Vazby mezi doklady už v DB jsou (advance_purchase_invoice_id,
-- settled_by_purchase_invoice_id, parent_invoice_id) — skupina je jejich
-- persistovaná agregace pro UI „Podle vyúčtování", propagaci compliance
-- příznaků a budoucí pokladní doklady (H8).
--
-- Skupiny staví SettlementGroupService z vazeb (rebuild na čtení, idempotentní);
-- ručně editovatelné jsou jen label a external_ref (auto-fill jen když NULL).
--
-- KONVENCE dle 0913: ENGINE/CHARSET/COLLATE explicitně; supplier.id INT UNSIGNED,
-- purchase_invoices.id/invoices.id/clients.id BIGINT UNSIGNED; fk_/idx_/uq_ prefixy.
--
-- `final_document_id` je polymorfní dle `direction` (purchase_invoices.id nebo
-- invoices.id) → bez FK, integritu drží service. Členství nese sloupec
-- settlement_group_id na dokladech (FK se SET NULL — smazání skupiny nesmí
-- sáhnout na doklady).

CREATE TABLE IF NOT EXISTS settlement_groups (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id       INT UNSIGNED    NOT NULL,
    direction         ENUM('purchase','sale') NOT NULL,
    label             VARCHAR(190)    NULL,
    external_ref      VARCHAR(64)     NULL,
    counterparty_id   BIGINT UNSIGNED NULL,
    final_document_id BIGINT UNSIGNED NULL,
    status            ENUM('open','settled','mismatch') NOT NULL DEFAULT 'open',
    total_amount      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
    currency          CHAR(3)         NOT NULL DEFAULT 'CZK',
    label_is_manual   TINYINT(1)      NOT NULL DEFAULT 0,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sg_supplier (supplier_id, direction, status),
    KEY idx_sg_counterparty (counterparty_id),
    CONSTRAINT fk_sg_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
    CONSTRAINT fk_sg_counterparty FOREIGN KEY (counterparty_id) REFERENCES clients (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS settlement_group_id BIGINT UNSIGNED NULL AFTER settled_by_purchase_invoice_id,
    ADD COLUMN IF NOT EXISTS settlement_role ENUM('advance','advance_tax_document','final','credit_note') NULL AFTER settlement_group_id;

ALTER TABLE purchase_invoices
    ADD KEY IF NOT EXISTS idx_pi_settlement_group (settlement_group_id);

ALTER TABLE purchase_invoices
    ADD CONSTRAINT fk_pi_settlement_group FOREIGN KEY (settlement_group_id)
        REFERENCES settlement_groups (id) ON DELETE SET NULL;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS settlement_group_id BIGINT UNSIGNED NULL AFTER parent_invoice_id,
    ADD COLUMN IF NOT EXISTS settlement_role ENUM('advance','advance_tax_document','final','credit_note') NULL AFTER settlement_group_id;

ALTER TABLE invoices
    ADD KEY IF NOT EXISTS idx_inv_settlement_group (settlement_group_id);

ALTER TABLE invoices
    ADD CONSTRAINT fk_inv_settlement_group FOREIGN KEY (settlement_group_id)
        REFERENCES settlement_groups (id) ON DELETE SET NULL;
