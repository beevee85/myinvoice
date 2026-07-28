-- 0905_document_trash.sql
-- FORK (beevee85/myinvoice): koš + tvrdé mazání dokladů (vydané i přijaté faktury).
-- Aditivní + idempotentní (IF NOT EXISTS, MariaDB 10.3+).
--
--  * invoices / purchase_invoices: soft-delete trojice deleted_at/deleted_by/
--    delete_reason (vzor: documents.deleted_at z 0067). NULL = aktivní doklad.
--    Všechny provozní dotazy filtrují deleted_at IS NULL; koš = IS NOT NULL.
--  * deleted_document_snapshots: kompletní JSON otisk dokladu pořízený při
--    TVRDÉM smazání (hlavička + položky + úhrady + vazby + cesty k PDF),
--    aby šel doklad forenzně dohledat i po odstranění řádku.
--  * supplier.doc_trash_enabled — přepínač Koš pro doklady (default 1 = zapnuto;
--    0 = „Do koše" provádí rovnou hard delete, se stejným dialogem i auditem).
--  * supplier.doc_trash_retention_days — retence koše ve dnech pro cron-cleanup
--    (default 30, 0 = neomezeně).

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS deleted_by INT NULL,
  ADD COLUMN IF NOT EXISTS delete_reason TEXT NULL,
  ADD INDEX IF NOT EXISTS idx_inv_trash (supplier_id, deleted_at);

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS deleted_by INT NULL,
  ADD COLUMN IF NOT EXISTS delete_reason TEXT NULL,
  ADD INDEX IF NOT EXISTS idx_pi_trash (supplier_id, deleted_at);

CREATE TABLE IF NOT EXISTS deleted_document_snapshots (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  entity_type ENUM('invoice','purchase_invoice') NOT NULL,
  entity_id   BIGINT UNSIGNED NOT NULL,           -- původní id (řádek už neexistuje, bez FK)
  payload     JSON NOT NULL,                      -- kompletní hlavička, položky, úhrady, vazby, cesty k PDF
  deleted_at  DATETIME NOT NULL,
  deleted_by  INT NULL,                           -- users.id (bez FK — user může být smazán)
  reason      TEXT NULL,
  KEY idx_dds_supplier (supplier_id, entity_type, deleted_at),
  KEY idx_dds_entity (entity_type, entity_id),
  CONSTRAINT fk_dds_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS doc_trash_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS doc_trash_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 30;
