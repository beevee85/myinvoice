-- 0129: Přijatá SLUŽBA ze 3. ZEMĚ (kód 24, § 24, ř.12) NEPATŘÍ do KH oddílu A.2.
--
-- Migrace 0120 (issue #164) přiřadila kh_section = 'A.2' kódům 24 (3. země) i 24e (EU).
-- Pro kód 24 to bylo CHYBNÉ: KH oddíl A.2 je určen jen pro dodavatele registrované k DPH
-- v JINÉM ČLENSKÉM STÁTĚ EU — VetaA2 vyžaduje `k_stat` (kód člen. státu EU) a `vatid_dod`
-- (EU DIČ dodavatele). Dodavatel ze 3. země (Anthropic, GitHub, … z USA) tyto údaje nemá
-- a do A.2 se strukturálně nevejde.
--
-- Reverse charge službu ze 3. země český plátce vykazuje POUZE v přiznání DPH (ř.12
-- samovyměření + ř.43 odpočet); do kontrolního hlášení NE. Ověřeno proti:
--   • reálnému výstupu účetní (Kniha DPH 05/2026: Anthropic/GitHub na ř.012/043,
--     sloupec KH prázdný — na rozdíl od tuzemských B.2/A.4),
--   • dphkh1.xsd (k_stat dokumentován výhradně pro členské státy EU).
--
-- Kód 24e (služba z JČS/EU, ř.5) ZŮSTÁVÁ A.2 — tam je dodavatel z EU s platným EU DIČ.
-- Kód 25 (dovoz zboží ze 3. země) už má kh_section = NULL.
--
-- kh_section čte VatLedgerService živě podle kódu → oprava se okamžitě promítne i do už
-- zaúčtovaných dokladů (žádný per-doklad backfill). DPHDP3 (ř.12 + ř.43) beze změny.
-- Idempotentní; sahá jen na globální seed (supplier_id IS NULL).

SET NAMES utf8mb4;

UPDATE vat_classifications
   SET kh_section = NULL
 WHERE supplier_id IS NULL
   AND direction = 'purchase'
   AND code = '24'
   AND kh_section IS NOT NULL;
