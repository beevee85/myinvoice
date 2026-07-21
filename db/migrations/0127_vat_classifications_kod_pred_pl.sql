-- 0127: KH „Kód předmětu plnění" (k_pred_pl) per klasifikace tuzemského reverse charge.
--
-- KontrolniHlaseniBuilder zapisoval do VetaA1/VetaB1 kod_pred_pl='5' natvrdo pro VŠECHNA
-- tuzemská plnění v přenesené povinnosti. Kód '5' = zboží dle přílohy č. 5 (odpad/šrot,
-- §92c), ale nejčastější reálný tuzemský RC — stavební a montážní práce (§92e) — má
-- kód '4'. Blanket '5' tedy misklasifikoval většinu případů.
--
-- Nový sloupec kod_pred_pl na vat_classifications drží kód předmětu plnění pro danou
-- RC klasifikaci; builder ho čte místo natvrdo hodnoty (fallback '5' + warning).
-- Seed: obecné tuzemské RC kódy '5' (příjemce, B.1) a '25s' (dodavatel, A.1) = '4'
-- (stavební/montážní práce jako nejčastější případ).
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS + UPDATE guardovaný na NULL. Globální seed.

SET NAMES utf8mb4;

ALTER TABLE vat_classifications
    ADD COLUMN IF NOT EXISTS kod_pred_pl VARCHAR(2) NULL
        COMMENT 'KH kód předmětu plnění (MFČR číselník k_pred_pl) pro tuzemský reverse charge';

UPDATE vat_classifications
   SET kod_pred_pl = '4'
 WHERE supplier_id IS NULL
   AND code IN ('5', '25s')
   AND kod_pred_pl IS NULL;
