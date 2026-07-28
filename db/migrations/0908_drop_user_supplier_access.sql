-- 0908_drop_user_supplier_access.sql
-- FORK (beevee85/myinvoice): odchod z vlastní FÁZE 2 na upstream implementaci.
--
-- Upstream v4.52.0 (migrace 0148_user_suppliers.sql) přinesl vlastní členství
-- uživatel↔firma — tabulku `user_suppliers` se schématem sdíleným s MyÚčto.cz
-- a navíc s per-firmu override role. Nahrazuje naši `user_supplier_access`
-- z migrace 0900 (viz PR radekhulan/myinvoice#247, autor převzal sémantiku,
-- ale zvolil vlastní tvar tabulky kvůli kompatibilitě obou projektů).
--
-- 1) Přeneseme případná přiřazení do nové tabulky (role = NULL = zdědit
--    globální users.role — naše tabulka override role neznala).
--    INSERT IGNORE: kdyby už řádek existoval, neduplikujeme.
-- 2) Starou tabulku zahodíme, ať nezůstane mrtvý zdroj pravdy.
--
-- Idempotentní: obojí pod IF EXISTS; po prvním běhu je krok 1 no-op.

INSERT IGNORE INTO user_suppliers (user_id, supplier_id, role, created_at)
SELECT usa.user_id, usa.supplier_id, NULL, usa.created_at
  FROM user_supplier_access usa
  JOIN users u    ON u.id = usa.user_id
  JOIN supplier s ON s.id = usa.supplier_id;

DROP TABLE IF EXISTS user_supplier_access;
