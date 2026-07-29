-- MyInvoice.cz (fork) — přímé napojení na Fio banku (REST API pro stahování pohybů)
--
-- Dosud se transakce dostávaly do systému jen z výpisu (GPC/PDF), z e-mailových avíz
-- nebo importem z iDokladu. Fio nabízí veřejné REST API s tokenem, takže pohyby lze
-- stahovat bez ručního uploadu — což je jediná chybějící součást párovacího řetězce
-- (auto-matching i cron infrastruktura už existují).
--
-- Návrh (viz docs/analyza-2026-07/40_navrhy.md, N-003):
--   • `bank_api_credentials` — token per BANKOVNÍ ÚČET (currencies.id), ne per měnu:
--     `currencies` nese jednotlivé účty a víc CZK účtů je podporovaný scénář.
--     Token je zašifrovaný (SecretEncryption, prefix `enc:v1:`) — proto NEsmí bydlet
--     na `currencies`, které se serializují do veřejného API /settings/currencies.
--   • `last_external_id` + `last_fetched_on` = NÁŠ kurzor. Fio sice umí endpoint
--     `/last/` se serverovou zarážkou, ta se ale posouvá už při stažení, ne po našem
--     uložení — pád mezi HTTP odpovědí a commitem by znamenal tichou a nevratnou
--     ztrátu pohybů. Stahujeme proto přes `/periods/` s překryvem a deduplikujeme
--     podle ID pohybu, takže opakované stažení je neškodné.
--
-- Enum `source` se rozšiřuje na obou stranách; UNIQUE (source, source_ref) se VĚDOMĚ
-- nepřidává — migrace 0136/0139 ho zrušily, protože na starých instalacích s duplicitami
-- blokoval upgrade. Deduplikace je aplikační (SELECT před INSERT), stejně jako u avíz.
--
-- Idempotentní: MODIFY COLUMN je opakovatelné, CREATE TABLE IF NOT EXISTS taktéž.

SET NAMES utf8mb4;

ALTER TABLE bank_statements
    MODIFY COLUMN source ENUM('gpc','email_notice','pdf','idoklad','fio') NOT NULL DEFAULT 'gpc';

ALTER TABLE bank_transactions
    MODIFY COLUMN source ENUM('statement','email_notice','idoklad','fio') NOT NULL DEFAULT 'statement';

CREATE TABLE IF NOT EXISTS bank_api_credentials (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id         INT UNSIGNED    NOT NULL,
    currency_id         INT UNSIGNED    NOT NULL COMMENT 'Bankovní účet (řádek currencies), ne měna jako taková',
    provider            VARCHAR(32)     NOT NULL DEFAULT 'fio',
    token_enc           VARCHAR(512)        NULL COMMENT 'Šifrovaný token (SecretEncryption, prefix enc:v1:)',
    enabled             TINYINT(1)      NOT NULL DEFAULT 0,
    last_fetched_on     DATE                NULL COMMENT 'Nejnovější datum pohybu, který jsme úspěšně uložili — základ klouzavého okna',
    last_external_id    VARCHAR(64)         NULL COMMENT 'ID posledního zpracovaného pohybu (informativně, dedup jde přes source_ref)',
    last_fetch_at       TIMESTAMP           NULL,
    last_fetch_status   ENUM('ok','error')  NULL,
    last_fetch_message  VARCHAR(500)        NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT current_timestamp(),
    updated_at          TIMESTAMP       NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uq_bac_account (supplier_id, provider, currency_id),
    KEY idx_bac_enabled (enabled),
    CONSTRAINT fk_bac_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)  ON DELETE CASCADE,
    CONSTRAINT fk_bac_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
