-- MyInvoice.cz — výchozí kategorie jízd (předvolba u nové jízdy)
--
-- Per tenant smí být max jedna kategorie s is_default = 1 (udržuje repository
-- v transakci, stejně jako u cars.is_default). UI nabízí tuto kategorii jako
-- předvyplněnou při zakládání nové jízdy.

ALTER TABLE trip_categories
  ADD COLUMN IF NOT EXISTS is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER is_private;

-- Seed: pokud tenant ještě nemá výchozí kategorii, označ za výchozí kategorii
-- „business" (Služební) — případně první nearchivovanou. Idempotentní.
UPDATE trip_categories tc
   JOIN (
        SELECT supplier_id,
               COALESCE(
                   MIN(CASE WHEN code = 'business' AND is_archived = 0 THEN id END),
                   MIN(CASE WHEN is_archived = 0 THEN id END)
               ) AS pick_id
          FROM trip_categories
         GROUP BY supplier_id
   ) pick ON pick.supplier_id = tc.supplier_id AND pick.pick_id = tc.id
    SET tc.is_default = 1
  WHERE NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM trip_categories) d
         WHERE d.supplier_id = tc.supplier_id AND d.is_default = 1
  );
