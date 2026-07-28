-- MyInvoice.cz (fork) — klasifikace „mimo předmět DPH" pro sazbu CZ-NA
--
-- Sazba CZ-NA „Mimo DPH" (migrace 0906) stojí ÚPLNĚ mimo předmět daně — na rozdíl
-- od CZ-0 „Osvobozeno", které se v přiznání vykazuje (ř. 50). Položka s CZ-NA
-- proto nesmí skončit v žádném řádku přiznání ani v kontrolním hlášení.
--
-- Dosud se to řešilo tím, že `replaceItems()` u CZ-NA nastavil klasifikaci NULL.
-- To ale nestačí: VatLedgerService klasifikaci resolvuje řetězcem
--   COALESCE(položka, HLAVIČKA, dopočet ze sazby)
-- takže NULL na položce spadne na kód hlavičky (např. „40") a plnění mimo předmět
-- daně by se dostalo na ř. 40 / do KH B.2. Typicky u zaokrouhlovacího řádku
-- „mimo DPH" na konečné faktuře.
--
-- Zavádíme proto EXPLICITNÍ kód `NA` s prázdným řádkem přiznání i oddílem KH
-- (stejná konstrukce jako kód 42 = bez nároku na odpočet, mimo výkazy).
--
-- Idempotentní: INSERT ... ON DUPLICATE KEY (uq je (supplier_id, code)).

SET NAMES utf8mb4;

INSERT INTO vat_classifications
    (supplier_id, code, label, direction, dphdp3_line, dphdp3_line_secondary,
     kh_section, vat_rate, is_reverse_charge, kh_regime_code, kh_bad_debt,
     display_order, archived)
VALUES
    (NULL, 'NA', 'Mimo předmět DPH (nevykazuje se v přiznání ani v KH)', 'both',
     NULL, NULL, NULL, 0.00, 0, NULL, NULL, 900, 0)
ON DUPLICATE KEY UPDATE
    label                 = VALUES(label),
    direction             = VALUES(direction),
    dphdp3_line           = VALUES(dphdp3_line),
    dphdp3_line_secondary = VALUES(dphdp3_line_secondary),
    kh_section            = VALUES(kh_section),
    archived              = 0;

-- Doklady, které už mají položky se sazbou CZ-NA (zálohové faktury), přeznačit
-- z NULL na explicitní kód — ať se neopírají o fallback na hlavičku.
UPDATE purchase_invoice_items pii
   JOIN vat_rates vr ON vr.id = pii.vat_rate_id
    SET pii.vat_classification_code = 'NA'
  WHERE vr.code = 'CZ-NA'
    AND (pii.vat_classification_code IS NULL OR pii.vat_classification_code = '');
