-- 0921 — účel pořízení vozidla (Dokument 3, R7; podklad pro § 72 odst. 3 ZDPH)
--
-- FORK (beevee85). Strop odpočtu DPH 420 000 Kč (CAR_VAT_DEDUCTION_CAP_CZK)
-- platí JEN pro vybraný osobní automobil (§ 72 odst. 10: kategorie M1) pořízený
-- jako DLOUHODOBÝ MAJETEK, NE pro zboží k dalšímu prodeji. Autobazar potřebuje
-- oba režimy rozlišit na kartě vozidla, jinak nelze kontrolu § 72/3 postavit.
-- (Pozn.: komentář opraven dle Dokumentu 6/A — dřívější odkaz na § 72/4 byl chybný;
-- odst. 4 je navazující režim technického zhodnocení.)
--
-- NULL = neurčeno (existující vozidla; uživatel doplní ručně).
-- Aditivní, idempotentní (IF NOT EXISTS).

ALTER TABLE cars
  ADD COLUMN IF NOT EXISTS acquisition_purpose ENUM('goods_for_resale','fixed_asset') NULL DEFAULT NULL AFTER vin;
