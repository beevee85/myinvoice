-- 0131: Volitelné atributy KH pro zvláštní režimy a opravy nedobytných pohledávek.

SET NAMES utf8mb4;

ALTER TABLE vat_classifications
    ADD COLUMN IF NOT EXISTS kh_regime_code VARCHAR(1) NULL
        COMMENT 'VetaA4.kod_rezim_pl: 0 běžný, 1 cestovní služba §89, 2 použité zboží §90'
        AFTER kod_pred_pl,
    ADD COLUMN IF NOT EXISTS kh_bad_debt VARCHAR(1) NULL
        COMMENT 'VetaA4/VetaB2.zdph_44: N běžné, P oprava dle §46/§74b'
        AFTER kh_regime_code;
