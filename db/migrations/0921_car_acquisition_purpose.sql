-- 0921 — účel pořízení vozidla (Dokument 3, R7; podklad pro § 72 odst. 4 ZDPH)
--
-- FORK (beevee85). Strop odpočtu DPH 420 000 Kč (CAR_VAT_DEDUCTION_CAP_CZK)
-- platí JEN pro vybraný osobní automobil pořízený jako DLOUHODOBÝ MAJETEK,
-- NE pro zboží k dalšímu prodeji. Autobazar potřebuje oba režimy rozlišit
-- na kartě vozidla, jinak nelze kontrolu § 72/4 vůbec postavit.
--
-- NULL = neurčeno (existující vozidla; uživatel doplní ručně).
-- Aditivní, idempotentní (IF NOT EXISTS).

ALTER TABLE cars
  ADD COLUMN IF NOT EXISTS acquisition_purpose ENUM('goods_for_resale','fixed_asset') NULL DEFAULT NULL AFTER vin;
