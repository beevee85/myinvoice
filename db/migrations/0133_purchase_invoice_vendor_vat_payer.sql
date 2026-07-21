-- MyInvoice.cz — Snapshot plátcovství DPH dodavatele NA přijaté faktuře
--
-- Motivace: plátcovství DPH je časově závislé. Dosud se u přijaté faktury četl jen
-- ŽIVÝ globální příznak `clients.is_vat_payer`, který se navíc při otevření/uložení
-- faktury přepisoval AKTUÁLNÍM stavem z ARES/VIES. U historické faktury, kde dodavatel
-- v době plnění plátce BYL, ale dnes už není, se tím příznak „odškrtl" a hrozila ztráta
-- legitimního nároku na odpočet (§ 72/73).
--
-- Řešení: doklad si drží VLASTNÍ snapshot `vendor_is_vat_payer` k datu plnění. Nastaví se
-- při vytvoření (freeze aktuálního stavu) a je ručně editovatelný v editoru (checkbox
-- „Dodavatel je plátce DPH"). Otevření dokladu už globální flag klienta nepřepisuje.
--
-- Sémantika sloupce:
--   NULL  = legacy/nezjištěno → čtecí cesty fallbackují na živý `clients.is_vat_payer`
--           (BC — zachová dosavadní chování u starých dokladů, dokud se nepřeuloží
--           nebo neproběhne backfill).
--   1 / 0 = zmrazený stav pro TENTO doklad (řídí nárok na odpočet a varování).
--
-- Backfill historických řádků NENÍ součástí migrace (konvence: VAT backfilly nespouštět
-- automaticky) — viz `api/bin/backfill-vendor-vat-payer.php` (spouští se ručně s --apply).
--
-- Idempotence: MariaDB-native ADD COLUMN IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS vendor_is_vat_payer TINYINT(1) NULL DEFAULT NULL
        COMMENT 'Snapshot plátcovství dodavatele k datu plnění. NULL=legacy (fallback na clients.is_vat_payer), 1/0=zmrazeno pro tento doklad.'
        AFTER vendor_id;
