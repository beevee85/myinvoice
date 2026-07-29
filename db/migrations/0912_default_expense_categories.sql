-- 0912 — výchozí číselník kategorií nákladu
--
-- Kategorie nákladu (`expense_categories`) pohánějí rozpad nákladů na dashboardu
-- a v CRM, ale číselník se dosud nikde nepředvyplňoval — každý tenant startoval
-- s prázdným seznamem, takže se pole `expense_category_id` v praxi nechávalo NULL
-- a rozpad nákladů zůstal nepoužitelný ("nezařazeno" = 100 %).
--
-- Tato migrace doplní obecnou výchozí sadu KAŽDÉMU tenantovi, který zatím nemá
-- ANI JEDNU kategorii. Tenantům s vlastními kategoriemi se nesahá.
-- Sada je záměrně obecná (ne oborová) a odpovídá běžnému účtovému členění;
-- uživatel si ji může přejmenovat, doplnit nebo archivovat.
--
-- Idempotentní: opakované spuštění nic nepřidá (uq_expense_categories + NOT EXISTS).

INSERT INTO expense_categories (supplier_id, code, label, fixed_or_var, display_order)
SELECT s.id, d.code, d.label, d.fixed_or_var, d.display_order
  FROM supplier s
  JOIN (
        SELECT 'zbozi'     AS code, 'Zboží k dalšímu prodeji'      AS label, 'variable' AS fixed_or_var, 10 AS display_order
  UNION SELECT 'material',        'Materiál a spotřeba',           'variable', 20
  UNION SELECT 'sluzby',          'Služby',                        'variable', 30
  UNION SELECT 'najem',           'Nájem a energie',               'fixed',    40
  UNION SELECT 'doprava',         'Doprava a PHM',                 'variable', 50
  UNION SELECT 'marketing',       'Marketing a reklama',           'variable', 60
  UNION SELECT 'software',        'Software a IT',                 'fixed',    70
  UNION SELECT 'poradenstvi',     'Odborné a poradenské služby',   'variable', 80
  UNION SELECT 'majetek',         'Dlouhodobý majetek',            'variable', 90
  UNION SELECT 'ostatni',         'Ostatní',                       'variable', 100
       ) d
 WHERE NOT EXISTS (
        SELECT 1 FROM expense_categories ec WHERE ec.supplier_id = s.id
       );
