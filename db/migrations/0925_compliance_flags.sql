-- 0925 — compliance vrstva: trvalé příznaky + AML případy (Dokumenty 4, 5, 7)
--
-- FORK (beevee85). Filozofie (Dokument 5): aplikace nepředepisuje obchodní
-- model — rizika ZVIDITELŇUJE, nechá rozhodnout uživatele a rozhodnutí trvale
-- zaznamená. Příznak není notifikace, je to STAV, který nelze smazat.
--
--  1. compliance_flags — trvalé příznaky. ŽÁDNÁ DELETE operace neexistuje
--     (UI, API ani migrace — hlídá ComplianceFlagGuardTest). Sloupce type,
--     severity, detected_at, context, legal_reference jsou po vytvoření
--     NEMĚNNÉ (vynucuje repository, updatují se jen stavová/odbavovací pole).
--     Stav `superseded` (NEAKTUALNI) nastavuje výhradně systém.
--  2. aml_cases — AML případy (Dokument 4 §5): neuzavírají se mazáním,
--     jen změnou stavu; uchovávat dle AML zákona (§ 16).
--  3. supplier.accepts_cash_payments / is_aml_obliged_entity — přepínače
--     Dokumentu 5 §4.3 (vypnutí hotovostních/AML kontrol per tenant; kdo
--     a kdy vypnul, loguje Settings akce do activity_logu).
--
-- KONVENCE dle 0913. Aditivní, idempotentní.

CREATE TABLE IF NOT EXISTS compliance_flags (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id       INT UNSIGNED    NOT NULL,
    type              VARCHAR(40)     NOT NULL,   -- číselník ComplianceFlags::TYPES
    severity          ENUM('high','medium','low') NOT NULL,
    status            ENUM('open','acknowledged','explained','resolved','superseded') NOT NULL DEFAULT 'open',
    origin            ENUM('document_save','batch','manual','import') NOT NULL DEFAULT 'document_save',
    detected_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    detected_rule     VARCHAR(40)     NULL,       -- ID validace (VB3b, VW-AML1, …)

    -- vazby (aspoň jedna): primární subjekt + denormalizované kotvy pro agregace
    subject_type      ENUM('invoice','purchase_invoice','cash_document','payment','client','project') NOT NULL,
    subject_id        BIGINT UNSIGNED NOT NULL,
    project_id        BIGINT UNSIGNED NULL,
    client_id         BIGINT UNSIGNED NULL,
    settlement_group_id BIGINT UNSIGNED NULL,
    car_vin           VARCHAR(40)     NULL,

    -- rozhodná data pro text hlášky (částky, data, čísla dokladů, limity)
    context           LONGTEXT        NULL,       -- JSON
    message           TEXT            NOT NULL,   -- jedna věta s čísly (Dokument 7 §6)
    legal_reference   VARCHAR(190)    NULL,

    deadline          DATE            NULL,
    remind_at         DATE            NULL,

    -- odbavení (jediná měnitelná část)
    acknowledged_by   BIGINT UNSIGNED NULL,
    acknowledged_at   TIMESTAMP       NULL,
    acknowledged_role VARCHAR(40)     NULL,
    acknowledgement_choice VARCHAR(40) NULL,      -- switch_to_transfer | escalate | accept_risk | not_suspicious | …
    acknowledgement_note   TEXT       NULL,

    KEY idx_cf_supplier (supplier_id, status, severity),
    KEY idx_cf_subject (subject_type, subject_id),
    KEY idx_cf_project (project_id),
    KEY idx_cf_client (client_id),
    KEY idx_cf_type (supplier_id, type),
    CONSTRAINT fk_cf_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aml_cases (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id       INT UNSIGNED    NOT NULL,
    trigger_type      ENUM('structuring','threshold_cdd','manual') NOT NULL,
    counterparty_id   BIGINT UNSIGNED NULL,
    related_payments  LONGTEXT        NULL,       -- JSON: [{source, id, date, amount}]
    total_cash_amount DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
    status            ENUM('open','assessed_not_suspicious','reported_fau','closed') NOT NULL DEFAULT 'open',
    assessed_by       BIGINT UNSIGNED NULL,
    assessed_at       TIMESTAMP       NULL,
    reasoning         TEXT            NULL,
    fau_report_ref    VARCHAR(120)    NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aml_supplier (supplier_id, status),
    KEY idx_aml_counterparty (counterparty_id),
    CONSTRAINT fk_aml_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
    CONSTRAINT fk_aml_counterparty FOREIGN KEY (counterparty_id) REFERENCES clients (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Přepínače Dokumentu 5 §4.3 — default zapnuto (autobazar je povinná osoba
-- dle § 2 odst. 1 písm. j) zák. 253/2008 Sb.).
ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS accepts_cash_payments TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS is_aml_obliged_entity TINYINT(1) NOT NULL DEFAULT 1;
