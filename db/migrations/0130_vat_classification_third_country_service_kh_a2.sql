-- 0130: Přijatá služba ze 3. země (kód 24, § 24, ř.12/13) patří do KH A.2.
--
-- A.2 zahrnuje plnění od osoby neusazené v tuzemsku, u kterých přiznává daň
-- příjemce. VAT ID ani kód členského státu nejsou pro dodavatele ze 3. země povinné.
-- Opravuje chybnou migraci 0129; klasifikace se čte živě, backfill dokladů není třeba.

SET NAMES utf8mb4;

UPDATE vat_classifications
   SET kh_section = 'A.2'
 WHERE supplier_id IS NULL
   AND direction = 'purchase'
   AND code = '24'
   AND (kh_section IS NULL OR kh_section <> 'A.2');
