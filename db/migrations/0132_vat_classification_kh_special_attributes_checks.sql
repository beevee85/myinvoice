-- 0132: Databázová validace volitelných KH atributů klasifikace.

ALTER TABLE vat_classifications
    ADD CONSTRAINT IF NOT EXISTS chk_vatcls_kh_regime_code
        CHECK (kh_regime_code IS NULL OR kh_regime_code IN ('0', '1', '2')),
    ADD CONSTRAINT IF NOT EXISTS chk_vatcls_kh_bad_debt
        CHECK (kh_bad_debt IS NULL OR kh_bad_debt IN ('N', 'P'));
