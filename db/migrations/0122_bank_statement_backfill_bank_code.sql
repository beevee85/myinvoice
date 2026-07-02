-- MyInvoice.cz — dorovnání kódu banky u existujících bankovních výpisů
--
-- GPC import dřív neukládal bank_code (na rozdíl od e-mailových avíz, která si ho
-- načtou z textu), takže staré GPC výpisy mají bank_statements.bank_code = NULL.
-- Doplníme ho z konfigurovaného účtu (currencies) přes normalizovanou shodu čísla
-- účtu (TRIM vodicích nul + jen číslice). Idempotentní — plní jen prázdné hodnoty.
--
-- bank_statements nemá supplier_id; účet je napříč tenanty de-facto unikátní a
-- shodu bereme přes LIMIT 1, takže výsledek je deterministický.

UPDATE bank_statements bs
   SET bs.bank_code = (
        SELECT cur.bank_code
          FROM currencies cur
         WHERE cur.bank_code IS NOT NULL AND cur.bank_code <> ''
           AND cur.account_number IS NOT NULL AND cur.account_number <> ''
           AND TRIM(LEADING '0' FROM REGEXP_REPLACE(cur.account_number, '[^0-9]', ''))
             = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', ''))
         LIMIT 1
       )
 WHERE (bs.bank_code IS NULL OR bs.bank_code = '')
   AND bs.account_number IS NOT NULL AND bs.account_number <> ''
   AND EXISTS (
        SELECT 1 FROM currencies cur
         WHERE cur.bank_code IS NOT NULL AND cur.bank_code <> ''
           AND cur.account_number IS NOT NULL AND cur.account_number <> ''
           AND TRIM(LEADING '0' FROM REGEXP_REPLACE(cur.account_number, '[^0-9]', ''))
             = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', ''))
       );
