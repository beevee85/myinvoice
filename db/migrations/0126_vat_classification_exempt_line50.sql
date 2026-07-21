-- 0126: Osvobozená tuzemská plnění bez nároku na odpočet (§ 51 ZDPH) → DPHDP3 ř.50.
--
-- Kód '3' (dodání zboží / poskytnutí služby s místem plnění v tuzemsku – osvobozeno)
-- měl dphdp3_line=NULL (migrace 0063 ho vynulovala kvůli staré chybě „kód = řádek",
-- kdy 3 chybně mířilo na ř.3 = pořízení zboží z JČS). Osvobozené plnění ale PATŘÍ
-- na ř.50 (Veta5.plnosv_kf) — sloupec „S nárokem na odpočet" vstupující do výpočtu
-- koeficientu podle § 76. Bez řádku se plnění do DPHDP3 vůbec nedostalo (Veta5 se
-- nikdy negenerovala).
--
-- Plný koeficient § 76 (koef_p20_nov/…, ř.52/53) tato migrace NEřeší — jen základ
-- na ř.50. Sahá pouze na globální seed (supplier_id IS NULL).
-- Idempotentní: opakované spuštění nastaví stejnou hodnotu.

SET NAMES utf8mb4;

UPDATE vat_classifications
   SET dphdp3_line = '50'
 WHERE supplier_id IS NULL
   AND code = '3'
   AND (dphdp3_line IS NULL OR dphdp3_line = '');
