-- FORK (beevee85) — REDESIGN Fáze 8: nastavení vzhledu PDF dokladu
-- Aditivní + idempotentní (IF NOT EXISTS, MariaDB 10.3+).
--  * pdf_attribution_enabled — vypnutelná patička „Používá fakturační systém MyInvoice.cz…"
--    (default 1 = dosavadní chování)
--  * pdf_legal_text — volitelná právní věta pod položkami (NULL/prázdná = netiskne se)
--  * pdf_barcode_enabled — Code128 čárový kód variabilního symbolu v hlavičce dokladu
--  * branding_profiles.signature_path — razítko/podpis per brandingový profil
--    (supplier.signature_path existuje od 0001_init a dosud se nepoužíval)

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS pdf_attribution_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS pdf_legal_text TEXT NULL,
  ADD COLUMN IF NOT EXISTS pdf_barcode_enabled TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE branding_profiles
  ADD COLUMN IF NOT EXISTS signature_path VARCHAR(255) NULL;
