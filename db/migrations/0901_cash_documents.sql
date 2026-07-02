-- 0901_cash_documents.sql
-- FORK (beevee85/myinvoice): pokladní doklady (PPD/VPD) — v1.
-- Číslováno v naší řadě 0900+ (upstream pokračuje 0124+), viz CUSTOMIZATIONS.md.
--
-- Koncept v1: doklad o pohybu hotovosti (příjem/výdej). Daňovým dokladem
-- zůstává faktura — pokladní doklad ji volitelně doprovází (invoice_id /
-- purchase_invoice_id), nebo stojí samostatně bez DPH rozpisu. Žádná vazba
-- na VatLedgerService (DPH výkazy tyto doklady nezohledňují — záměr).

CREATE TABLE IF NOT EXISTS cash_documents (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  kind                ENUM('income','expense') NOT NULL,          -- PPD / VPD
  number              VARCHAR(30) NOT NULL,                       -- PPD-2026-0001 / VPD-2026-0001
  issue_date          DATE NOT NULL,
  amount              DECIMAL(14,2) NOT NULL,                     -- vždy kladná; směr určuje kind
  currency            CHAR(3) NOT NULL DEFAULT 'CZK',             -- ISO 4217
  counterparty        VARCHAR(190) NOT NULL DEFAULT '',           -- přijato od / vyplaceno komu
  description         VARCHAR(500) NOT NULL DEFAULT '',           -- účel platby
  invoice_id          BIGINT UNSIGNED NULL,                       -- uhrazená vydaná faktura
  purchase_invoice_id BIGINT UNSIGNED NULL,                       -- uhrazená přijatá faktura
  created_by          BIGINT UNSIGNED NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cashdoc_number (supplier_id, number),
  KEY idx_cashdoc_supplier_date (supplier_id, issue_date),
  CONSTRAINT fk_cashdoc_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id),
  CONSTRAINT fk_cashdoc_invoice  FOREIGN KEY (invoice_id)  REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_cashdoc_purchase FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_cashdoc_user     FOREIGN KEY (created_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
