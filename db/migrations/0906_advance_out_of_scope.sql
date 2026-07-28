-- MyInvoice.cz (fork) — Zálohová faktura: sazba „Mimo DPH" + náklady bez záloh
--
-- 1) Nová položka číselníku vat_rates: CZ-NA „Mimo DPH". Zálohová faktura
--    (document_kind='advance') není daňový doklad — její položky nejsou
--    „osvobozené plnění" (to se vykazuje v přiznání), ale stojí ÚPLNĚ mimo
--    režim DPH. Dosud se používala CZ-0 „Osvobozeno", což sémanticky slévalo
--    dvě různé věci. Položky se sazbou CZ-NA nikdy nedostanou klasifikační
--    kód → nevstupují do DP3 ani KH (PurchaseInvoiceRepository::replaceItems).
--
-- 2) sp_recompute_crm_monthly_summary: záloha (advance) se z nákladů vyřazuje
--    VŽDY — náklad nese daňový doklad k přijaté záloze (tax_document) nebo
--    konečná faktura. Dosud se vyřazovala jen zaplacená/spárovaná, což se po
--    zavedení DDKPZ (migrace 0904) mohlo s nákladem z DDKPZ dublovat.
--    Jinak je tělo procedury shodné s migrací 0074.
--
-- Idempotentní: INSERT ... ON DUPLICATE KEY (uq_vat_code), DROP/CREATE PROCEDURE.

SET NAMES utf8mb4;

INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge, valid_from, valid_to, display_order)
VALUES ('CZ-NA', 0.00, 'CZ', 'Mimo DPH', 'Out of VAT scope', 0, 0, '2024-01-01', NULL, 35)
ON DUPLICATE KEY UPDATE label_cs = VALUES(label_cs), label_en = VALUES(label_en), display_order = VALUES(display_order);

DELIMITER //

DROP PROCEDURE IF EXISTS sp_recompute_crm_monthly_summary //

CREATE PROCEDURE sp_recompute_crm_monthly_summary(IN p_supplier_id TINYINT UNSIGNED)
BEGIN
    DECLARE v_cutoff DATE;
    SET v_cutoff = DATE_SUB(CURDATE(), INTERVAL 13 MONTH);

    DELETE FROM crm_monthly_summary
     WHERE supplier_id = p_supplier_id
       AND period_ym >= DATE_FORMAT(v_cutoff, '%Y-%m');

    -- Revenue + invoice count z vydaných (status NOT IN draft, cancelled)
    INSERT INTO crm_monthly_summary
        (supplier_id, period_ym, currency, revenue, revenue_net, revenue_czk, revenue_net_czk, invoice_count,
         costs, costs_net, costs_czk, costs_net_czk, purchase_count, vat_output, vat_input)
    SELECT
        i.supplier_id,
        DATE_FORMAT(i.issue_date, '%Y-%m') AS ym,
        COALESCE(c.code, 'CZK') AS currency,
        SUM(COALESCE(i.total_with_vat, 0))    AS revenue,
        SUM(COALESCE(i.total_without_vat, 0)) AS revenue_net,
        SUM(COALESCE(i.total_with_vat, 0)    * COALESCE(IF(c.code = 'CZK', 1, i.exchange_rate), 1)) AS revenue_czk,
        SUM(COALESCE(i.total_without_vat, 0) * COALESCE(IF(c.code = 'CZK', 1, i.exchange_rate), 1)) AS revenue_net_czk,
        COUNT(*) AS invoice_count,
        0, 0, 0, 0, 0,
        SUM(COALESCE(i.total_with_vat, 0) - COALESCE(i.total_without_vat, 0)) AS vat_output,
        0
      FROM invoices i
 LEFT JOIN currencies c ON c.id = i.currency_id
     WHERE i.supplier_id = p_supplier_id
       AND i.status NOT IN ('draft', 'cancelled')
       AND i.issue_date >= v_cutoff
       AND i.invoice_type != 'proforma'  -- proformy vynechat (nejsou daňový doklad)
  GROUP BY i.supplier_id, ym, currency
       ON DUPLICATE KEY UPDATE
           revenue         = VALUES(revenue),
           revenue_net     = VALUES(revenue_net),
           revenue_czk     = VALUES(revenue_czk),
           revenue_net_czk = VALUES(revenue_net_czk),
           invoice_count   = VALUES(invoice_count),
           vat_output      = VALUES(vat_output);

    -- Costs + purchase count z přijatých (status NOT IN draft, cancelled).
    -- Záloha (advance) se vyřazuje VŽDY — není daňový doklad ani nositel nákladu;
    -- náklad nese DDKPZ (tax_document) nebo konečná faktura (migrace 0906).
    INSERT INTO crm_monthly_summary
        (supplier_id, period_ym, currency, revenue, revenue_net, revenue_czk, revenue_net_czk, invoice_count,
         costs, costs_net, costs_czk, costs_net_czk, purchase_count, vat_output, vat_input)
    SELECT
        pi.supplier_id,
        DATE_FORMAT(pi.issue_date, '%Y-%m') AS ym,
        COALESCE(c.code, 'CZK') AS currency,
        0, 0, 0, 0, 0,
        SUM(COALESCE(pi.total_with_vat, 0))    AS costs,
        SUM(COALESCE(pi.total_without_vat, 0)) AS costs_net,
        SUM(COALESCE(pi.total_with_vat, 0)    * COALESCE(IF(c.code = 'CZK', 1, pi.exchange_rate), 1)) AS costs_czk,
        SUM(COALESCE(pi.total_without_vat, 0) * COALESCE(IF(c.code = 'CZK', 1, pi.exchange_rate), 1)) AS costs_net_czk,
        COUNT(*) AS purchase_count,
        0,
        SUM(COALESCE(pi.total_with_vat, 0) - COALESCE(pi.total_without_vat, 0)) AS vat_input
      FROM purchase_invoices pi
 LEFT JOIN currencies c ON c.id = pi.currency_id
     WHERE pi.supplier_id = p_supplier_id
       AND pi.status NOT IN ('draft', 'cancelled')
       AND pi.issue_date >= v_cutoff
       AND COALESCE(pi.document_kind, '') <> 'advance'
  GROUP BY pi.supplier_id, ym, currency
       ON DUPLICATE KEY UPDATE
           costs          = VALUES(costs),
           costs_net      = VALUES(costs_net),
           costs_czk      = VALUES(costs_czk),
           costs_net_czk  = VALUES(costs_net_czk),
           purchase_count = VALUES(purchase_count),
           vat_input      = VALUES(vat_input);
END //

DELIMITER ;
