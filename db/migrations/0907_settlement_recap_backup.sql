-- MyInvoice.cz (fork) — záloha rekapitulace DPH před párováním § 37a
--
-- `PurchaseSettlementService::link()` přepíše `vat_overrides` konečné faktury na
-- cíl podle § 37a (rozdíl). Odpojení posledního daňového dokladu k záloze pak
-- původní rozklad základ/daň nedokáže zrekonstruovat — z hrubé částky vyjde vždy
-- rozklad koeficientem (111 000,00 → 91 735,53 + 19 264,47), i když doklad
-- dodavatele nesl 91 735,54 + 19 264,46 (rekapitulace § 73 z importu).
--
-- Sloupec drží JSON snapshot `vat_overrides` z okamžiku PRVNÍHO párování
-- (`{"overrides": <hodnota|null>}` — samotné NULL ve sloupci znamená „žádná
-- záloha", aby šlo odlišit uložené NULL od chybějícího snapshotu). Při odpojení
-- POSLEDNÍHO daňového dokladu se snapshot vrátí a sloupec se vynuluje.
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS (MariaDB 10.6+).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS settlement_recap_backup JSON NULL
        COMMENT 'Snapshot vat_overrides před prvním párováním DDKPZ (§ 37a); {"overrides": …}'
        AFTER settled_by_purchase_invoice_id;
