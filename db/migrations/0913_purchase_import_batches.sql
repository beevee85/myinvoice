-- 0913 — dávkový import přijatých dokladů: úložiště dávek, souborů a výsledků
--
-- FORK (beevee85). Featura je za příznakem `purchase_invoice.batch_import.enabled`
-- (default false) — s vypnutým příznakem se do těchto tabulek nikdo nedotkne
-- a aplikace se chová bit-pro-bit jako dnes.
--
-- KONVENCE ODVOZENÉ ZE SKUTEČNÉHO SCHÉMATU (ne odhad):
--   * ENGINE=InnoDB, CHARSET=utf8mb4, COLLATE=utf8mb4_unicode_ci — VŽDY EXPLICITNĚ.
--     Schéma `myinvoice` má default `utf8mb4_uca1400_ai_ci` (MariaDB 11.8 změnila
--     výchozí kolaci), ale všech 93 existujících tabulek má `utf8mb4_unicode_ci`.
--     Bez explicitního COLLATE by nové tabulky dostaly jinou kolaci než zbytek
--     databáze — cizí klíč nad znakovým sloupcem by MariaDB odmítla a porovnání
--     by se chovala jinak. Nic by nespadlo, jen by to za rok nikdo nechápal.
--   * `supplier.id` je INT UNSIGNED, `purchase_invoices.id` a `users.id` jsou
--     BIGINT UNSIGNED. Cizí klíče musí typ přesně kopírovat.
--   * Jména cizích klíčů `fk_<zkratka>_<cíl>`, indexy `idx_*`, unikáty `uq_*`
--     (doloženo ze 168 FK a 281 indexů; `uk_1` je jediná odchylka, nenapodobujeme).
--   * `created_at` má 60 z 93 tabulek, `updated_at` 35, `deleted_at` jen 8 —
--     soft delete se přidává cíleně, ne plošně. Dávky se mažou natvrdo (retence).
--
-- TENANT SCOPING: `supplier_id` je na VŠECH třech tabulkách, ne jen na hlavičce.
-- Sweeper retence i report se dotazují po tenantech a neomezený agregát je mimo
-- rozsah (V64, §4.1). Denormalizace je tady záměr, ne nedopatření.
--
-- OSOBNÍ ÚDAJE: `raw_json` a `normalized_json` jsou NOVÉ ÚLOŽIŠTĚ OSOBNÍCH ÚDAJŮ —
-- obsahují obsah cizích dokladů. Platí na ně limity V79 a retence V79b:
-- `raw_json` se maže při dosažení terminálního stavu (RAW_JSON_RETENTION_DAYS = 0),
-- `normalized_json` po 90 dnech. Do logů ani telemetrie se nedostanou nikdy (V82).
--
-- Idempotentní: nativní IF NOT EXISTS, opakované spuštění je no-op.

-- ---------------------------------------------------------------------------
-- 1. Hlavička dávky
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_import_batches (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id         INT UNSIGNED    NOT NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,

    -- pending → building → awaiting_results → validating → applying → done
    -- terminální: done, failed, cancelled (spouštěč retence, cesta A dle V79b)
    status              VARCHAR(20)     NOT NULL DEFAULT 'pending',

    -- Jednorázový token (A1/V63). V DB jen SHA-256, nikdy plaintext.
    -- Není to autentizace — je to druhý faktor uvnitř už ověřené session/PAT.
    token_sha256        CHAR(64)        NULL,
    token_expires_at    TIMESTAMP       NULL DEFAULT NULL,

    file_count          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_bytes         BIGINT UNSIGNED   NOT NULL DEFAULT 0,
    manifest_sha256     CHAR(64)        NULL,

    -- Známka života workeru. Strop opuštěné dávky se počítá ODSUD, ne od created_at
    -- (cesta B dle V79b) — jinak by sweeper smazal běžící dlouhou dávku.
    -- BackgroundProcess::spawnPhp() je fire-and-forget přes @exec, takže neúspěšný
    -- spawn nikdo nevidí a dávka by zůstala ve `pending` navždy.
    heartbeat_at        TIMESTAMP       NULL DEFAULT NULL,

    error_code          VARCHAR(64)     NULL,
    error_message       TEXT            NULL,

    created_at          TIMESTAMP       NOT NULL DEFAULT current_timestamp(),
    updated_at          TIMESTAMP       NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    applied_at          TIMESTAMP       NULL DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_pib_supplier_status  (supplier_id, status),
    KEY idx_pib_supplier_created (supplier_id, created_at),
    KEY idx_pib_heartbeat        (status, heartbeat_at),
    UNIQUE KEY uq_pib_token      (token_sha256),
    CONSTRAINT fk_pib_supplier FOREIGN KEY (supplier_id)        REFERENCES supplier (id) ON DELETE CASCADE,
    CONSTRAINT fk_pib_user     FOREIGN KEY (created_by_user_id) REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Soubory v dávce
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_import_batch_files (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_import_batch_id  BIGINT UNSIGNED NOT NULL,
    supplier_id               INT UNSIGNED    NOT NULL,

    original_name             VARCHAR(255)    NOT NULL,
    stored_name               VARCHAR(255)    NOT NULL,
    byte_size                 INT UNSIGNED    NOT NULL,
    sha256                    CHAR(64)        NOT NULL,
    mime_type                 VARCHAR(100)    NULL,
    page_count                SMALLINT UNSIGNED NULL,
    has_text_layer            TINYINT(1)      NULL,

    created_at                TIMESTAMP       NOT NULL DEFAULT current_timestamp(),

    PRIMARY KEY (id),
    -- Dedup UVNITŘ dávky (V69) — mezi dávkami řeší pdf_hash na faktuře.
    UNIQUE KEY uq_pibf_batch_sha256 (purchase_import_batch_id, sha256),
    KEY idx_pibf_supplier (supplier_id),
    CONSTRAINT fk_pibf_batch    FOREIGN KEY (purchase_import_batch_id) REFERENCES purchase_import_batches (id) ON DELETE CASCADE,
    CONSTRAINT fk_pibf_supplier FOREIGN KEY (supplier_id)              REFERENCES supplier (id)                ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Výsledky extrakce
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_import_batch_results (
    id                             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_import_batch_id       BIGINT UNSIGNED NOT NULL,
    purchase_import_batch_file_id  BIGINT UNSIGNED NOT NULL,
    supplier_id                    INT UNSIGNED    NOT NULL,

    -- pending → validated | rejected ; po applied vzniká purchase_invoice_id
    status                         VARCHAR(20)     NOT NULL DEFAULT 'pending',

    -- OSOBNÍ ÚDAJE, limity V79, retence V79b. Nikdy do logů (V82).
    raw_json                       LONGTEXT        NULL,
    normalized_json                LONGTEXT        NULL,
    findings_json                  LONGTEXT        NULL,

    -- Vznikne až při apply; doklad je vždy DRAFT ke schválení člověkem.
    purchase_invoice_id            BIGINT UNSIGNED NULL,

    raw_purged_at                  TIMESTAMP       NULL DEFAULT NULL,
    normalized_purged_at           TIMESTAMP       NULL DEFAULT NULL,

    created_at                     TIMESTAMP       NOT NULL DEFAULT current_timestamp(),
    updated_at                     TIMESTAMP       NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id),
    -- Jeden výsledek na soubor (V5: batch_file_id musí patřit téže dávce).
    UNIQUE KEY uq_pibr_file (purchase_import_batch_file_id),
    KEY idx_pibr_batch_status (purchase_import_batch_id, status),
    KEY idx_pibr_supplier     (supplier_id),
    KEY idx_pibr_purge        (normalized_purged_at, created_at),
    CONSTRAINT fk_pibr_batch    FOREIGN KEY (purchase_import_batch_id)      REFERENCES purchase_import_batches (id)      ON DELETE CASCADE,
    CONSTRAINT fk_pibr_file     FOREIGN KEY (purchase_import_batch_file_id) REFERENCES purchase_import_batch_files (id)  ON DELETE CASCADE,
    CONSTRAINT fk_pibr_supplier FOREIGN KEY (supplier_id)                   REFERENCES supplier (id)                     ON DELETE CASCADE,
    CONSTRAINT fk_pibr_invoice  FOREIGN KEY (purchase_invoice_id)           REFERENCES purchase_invoices (id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Vazba z přijaté faktury na dávku (rozhodnutí A3)
-- ---------------------------------------------------------------------------
-- VLASTNÍ sloupec, NE upstreamový `import_batch_id`. Ten je VARCHAR(32) bez FK
-- a bez UNIQUE, plní ho frontend hodnotou crypto.randomUUID() a znamená
-- „dávka hromadného AI importu" (upstream commit 9120ffe1, #232). Sdílení by
-- (a) rozbilo náš vlastní report, protože classifySource() mapuje neprázdnou
-- hodnotu na zdroj `ai_pdf`, a (b) promíchalo dva druhy dávek v upstreamovém
-- dropdownu, kde limit 20 znamená, že by naše dávky vytlačily uživatelovy.
ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS purchase_import_batch_id BIGINT UNSIGNED NULL
        COMMENT 'FORK: dávkový import (0913). NE upstreamový import_batch_id — jiná sémantika.'
        AFTER import_batch_id;

ALTER TABLE purchase_invoices
    ADD KEY IF NOT EXISTS idx_pi_purchase_import_batch (supplier_id, purchase_import_batch_id);

-- Pozor na pořadí klauzulí: MariaDB chce `IF NOT EXISTS` AŽ ZA `FOREIGN KEY`,
-- ne za `ADD CONSTRAINT` — jinak parser čte `IF` jako jméno constraintu a spadne.
ALTER TABLE purchase_invoices
    ADD CONSTRAINT fk_pi_purchase_import_batch
        FOREIGN KEY IF NOT EXISTS (purchase_import_batch_id)
        REFERENCES purchase_import_batches (id) ON DELETE SET NULL;
