-- 0125: Zůstatek účtu z e-mailových avíz (#Creditas „disponibilní zůstatek",
-- Fio „Aktuální zůstatek", RB „Disponibilní zůstatek").
-- Per-transakce, protože avíza se sbírají do měsíčního bank_statements a jeden
-- statement tak nese N zůstatků v čase; GPC transakce zůstávají NULL (autoritativní
-- zůstatek GPC nese hlavička výpisu v bank_statements.curr_balance).

ALTER TABLE bank_transactions
  ADD COLUMN IF NOT EXISTS balance DECIMAL(14,2) NULL DEFAULT NULL AFTER amount;
