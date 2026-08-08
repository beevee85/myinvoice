-- 0920 — způsob úhrady u jednotlivých plateb a přijatých faktur (H1 zadání pokladny)
--
-- FORK (beevee85). Bez evidence způsobu platby u ÚHRADY (ne jen v hlavičce vydané
-- faktury) nejde poznat, které úhrady proběhly v hotovosti — a na tom stojí
-- pokladní doklady (PPD/VPD), pokladní kniha i kontrola limitu plateb v hotovosti
-- (zákon č. 254/2004 Sb.).
--
--   * `invoice_payments.payment_method` — způsob úhrady konkrétní platby.
--     NULL = neurčeno (všechny historické platby; zpětně se nedomýšlí, hlavička
--     faktury je jen deklarace očekávaného způsobu, ne důkaz o skutečné platbě).
--   * `purchase_invoices.payment_method` — přijatá strana platby neeviduje
--     (úhrada = stavový přechod received/booked → paid), proto způsob úhrady
--     sedí na hlavičce dokladu. NULL = neurčeno.
--
-- ENUM hodnoty přesně kopírují `invoices.payment_method` (migrace 0020).
-- Aditivní, idempotentní (IF NOT EXISTS), žádný backfill.

ALTER TABLE invoice_payments
  ADD COLUMN IF NOT EXISTS payment_method ENUM('bank_transfer','card','cash','other') NULL DEFAULT NULL AFTER source;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS payment_method ENUM('bank_transfer','card','cash','other') NULL DEFAULT NULL AFTER paid_at;
