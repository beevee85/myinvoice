-- 0902_invoice_internal_note.sql
-- FORK (beevee85/myinvoice): interní poznámka u faktury (à la Vyfakturuj).
-- Vidí ji jen přihlášení uživatelé v aplikaci — NIKDY se netiskne na PDF
-- ani neposílá klientovi. Kód ji čte přes SHOW COLUMNS detekci (viz
-- InvoiceRepository::supportsInternalNote), takže instalace pozadu
-- s migrací nespadne.

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS internal_note VARCHAR(1000) NULL AFTER note_below_items;
