-- 0924 — pokladny, storno a náležitosti pokladních dokladů (Doplněk H3–H7)
--
-- FORK (beevee85). Rozšíření modulu pokladny (0901) dle Dokumentu 2 (H3, H4, H7)
-- s korekcemi Dokumentu 6 (R2: částka slovy není zákonná povinnost; R3: podpisy
-- stačí jako identifikační záznam osoby, § 33a odst. 10 ZoÚ; A8: inventarizace
-- „4× ročně" v zákoně není — periodicky k rozvahovému dni, prokazování 5 let
-- dle § 29 odst. 3 ZoÚ).
--
--  1. cash_registers — více pokladen (CZK/EUR/provozovna), výchozí per tenant.
--  2. cash_documents — cash_register_id (řada per pokladna+druh+rok),
--     protistrana strukturovaně (klient/IČO/adresa), accounting_date (okamžik
--     uskutečnění), status active/storno + storno_of_id (H7: mazání se ruší),
--     is_tax_document + vat_breakdown (H3: PPD za hotovostní prodej bez faktury
--     = zjednodušený daňový doklad § 30a; PPD hradící fakturu rozpis MÍT NESMÍ),
--     podpisové záznamy issued_by/received_by/approved_by, note.
--     Storno protidoklad nese ZÁPORNOU částku (H7) — konvence „amount vždy
--     kladná" z 0901 platí nadále pro běžné doklady.
--  3. cash_register_inventories — inventarizace (zjištěný stav, rozdíl, doklad).
--
-- KONVENCE dle 0913 (ENGINE/CHARSET/COLLATE explicitně, typy FK dle cílů,
-- fk_/idx_/uq_ prefixy). Aditivní, idempotentní.

CREATE TABLE IF NOT EXISTS cash_registers (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED    NOT NULL,
    name        VARCHAR(120)    NOT NULL,
    currency    CHAR(3)         NOT NULL DEFAULT 'CZK',
    is_default  TINYINT(1)      NOT NULL DEFAULT 0,
    is_archived TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cashreg_supplier (supplier_id, is_archived),
    CONSTRAINT fk_cashreg_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Výchozí pokladna pro každého dodavatele (idempotentní — jen kde chybí).
INSERT INTO cash_registers (supplier_id, name, currency, is_default)
SELECT s.id, 'Hlavní pokladna', 'CZK', 1
  FROM supplier s
 WHERE NOT EXISTS (SELECT 1 FROM cash_registers cr WHERE cr.supplier_id = s.id);

ALTER TABLE cash_documents
    ADD COLUMN IF NOT EXISTS cash_register_id INT UNSIGNED NULL AFTER supplier_id,
    ADD COLUMN IF NOT EXISTS counterparty_client_id BIGINT UNSIGNED NULL AFTER counterparty,
    ADD COLUMN IF NOT EXISTS counterparty_ico VARCHAR(20) NULL AFTER counterparty_client_id,
    ADD COLUMN IF NOT EXISTS counterparty_address VARCHAR(255) NULL AFTER counterparty_ico,
    ADD COLUMN IF NOT EXISTS accounting_date DATE NULL AFTER issue_date,
    ADD COLUMN IF NOT EXISTS status ENUM('active','storno') NOT NULL DEFAULT 'active' AFTER description,
    ADD COLUMN IF NOT EXISTS storno_of_id BIGINT UNSIGNED NULL AFTER status,
    ADD COLUMN IF NOT EXISTS is_tax_document TINYINT(1) NOT NULL DEFAULT 0 AFTER storno_of_id,
    ADD COLUMN IF NOT EXISTS vat_breakdown LONGTEXT NULL AFTER is_tax_document,
    ADD COLUMN IF NOT EXISTS issued_by VARCHAR(120) NULL AFTER vat_breakdown,
    ADD COLUMN IF NOT EXISTS received_by VARCHAR(120) NULL AFTER issued_by,
    ADD COLUMN IF NOT EXISTS approved_by VARCHAR(120) NULL AFTER received_by,
    ADD COLUMN IF NOT EXISTS note TEXT NULL AFTER approved_by;

-- Backfill: existující doklady do výchozí pokladny tenanta, accounting_date = issue_date.
UPDATE cash_documents cd
  JOIN cash_registers cr ON cr.supplier_id = cd.supplier_id AND cr.is_default = 1
   SET cd.cash_register_id = cr.id
 WHERE cd.cash_register_id IS NULL;

UPDATE cash_documents SET accounting_date = issue_date WHERE accounting_date IS NULL;

ALTER TABLE cash_documents
    ADD KEY IF NOT EXISTS idx_cashdoc_register (cash_register_id, accounting_date),
    ADD KEY IF NOT EXISTS idx_cashdoc_counterparty (counterparty_client_id);

ALTER TABLE cash_documents
    ADD CONSTRAINT fk_cashdoc_register FOREIGN KEY (cash_register_id)
        REFERENCES cash_registers (id) ON DELETE RESTRICT;

ALTER TABLE cash_documents
    ADD CONSTRAINT fk_cashdoc_counterparty FOREIGN KEY (counterparty_client_id)
        REFERENCES clients (id) ON DELETE SET NULL;

ALTER TABLE cash_documents
    ADD CONSTRAINT fk_cashdoc_storno_of FOREIGN KEY (storno_of_id)
        REFERENCES cash_documents (id) ON DELETE RESTRICT;

-- Inventarizace pokladny (H4): zjištěný stav, rozdíl, případný vypořádací doklad.
-- Záznam se nemaže (§ 29/3 ZoÚ — prokazování 5 let).
CREATE TABLE IF NOT EXISTS cash_register_inventories (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id      INT UNSIGNED    NOT NULL,
    cash_register_id INT UNSIGNED    NOT NULL,
    inventory_date   DATE            NOT NULL,
    expected_amount  DECIMAL(14,2)   NOT NULL,
    actual_amount    DECIMAL(14,2)   NOT NULL,
    difference       DECIMAL(14,2)   NOT NULL,
    settlement_document_id BIGINT UNSIGNED NULL,
    note             VARCHAR(500)    NULL,
    created_by       BIGINT UNSIGNED NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cashinv_register (cash_register_id, inventory_date),
    CONSTRAINT fk_cashinv_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
    CONSTRAINT fk_cashinv_register FOREIGN KEY (cash_register_id) REFERENCES cash_registers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cashinv_settlement FOREIGN KEY (settlement_document_id) REFERENCES cash_documents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
