-- 0923 — zakázky napříč nákupem a prodejem (Dokument 1 bod B2, Dokument 7 §7)
--
-- FORK (beevee85). Zakázka = obchodní případ: dosud vázaná JEN na odběratele
-- (client_id NOT NULL, tenant scope odvozený přes clients.supplier_id), takže
-- nemohla nést nákupní stranu. Autobazar potřebuje na jedné zakázce nákup
-- vozidla (přijaté doklady), prodej (vydané doklady), vozidlo (VIN) a pokladnu
-- — teprve pak jde ukázat marže a postavit pohled „Podle zakázek" (/compliance).
--
-- Kroky:
--  1. projects.supplier_id — VLASTNÍ tenant scope (backfill z clients);
--     dosud se odvozoval přes client_id, které teď bude nepovinné.
--  2. projects.client_id → NULL (zakázka může začít nákupem, bez odběratele).
--  3. projects.car_id — vozidlo případu (nositel VIN, viz R7/A6: entita cars,
--     ne nové volné pole subject_identifier).
--  4. project_participants — protistrany zakázky (customer/vendor); backfill
--     stávajícího client_id jako customer.
--  5. purchase_invoices.project_id + cash_documents.project_id — vazby dokladů.
--
-- KONVENCE dle 0913: explicitní ENGINE/CHARSET/COLLATE; typy FK přesně dle cílů
-- (supplier.id INT UNSIGNED, projects.id/clients.id/purchase_invoices.id BIGINT
-- UNSIGNED, cars.id INT UNSIGNED); fk_/idx_/uq_ prefixy. Aditivní, idempotentní.

ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS supplier_id INT UNSIGNED NULL AFTER id;

-- Backfill tenanta z dosavadní vazby na klienta (idempotentní — jen NULL řádky).
UPDATE projects p
  JOIN clients c ON c.id = p.client_id
   SET p.supplier_id = c.supplier_id
 WHERE p.supplier_id IS NULL;

ALTER TABLE projects
    ADD KEY IF NOT EXISTS idx_proj_supplier (supplier_id, status);

ALTER TABLE projects
    ADD CONSTRAINT fk_proj_supplier FOREIGN KEY (supplier_id)
        REFERENCES supplier (id) ON DELETE RESTRICT;

-- client_id nově nepovinné (zakázka založená z nákupní strany).
ALTER TABLE projects
    MODIFY COLUMN client_id BIGINT UNSIGNED NULL;

-- Vozidlo případu (VIN nese entita cars — R7/A6).
ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS car_id INT UNSIGNED NULL AFTER client_id;

ALTER TABLE projects
    ADD KEY IF NOT EXISTS idx_proj_car (car_id);

ALTER TABLE projects
    ADD CONSTRAINT fk_proj_car FOREIGN KEY (car_id)
        REFERENCES cars (id) ON DELETE SET NULL;

-- Protistrany zakázky (Dokument 1: „project_participants (klient/dodavatel, role)").
-- Udržují se automaticky z přiřazených dokladů + ručně; UNIQUE brání duplicitám.
CREATE TABLE IF NOT EXISTS project_participants (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    client_id  BIGINT UNSIGNED NOT NULL,
    role       ENUM('customer','vendor') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pp_project_client_role (project_id, client_id, role),
    KEY idx_pp_client (client_id),
    CONSTRAINT fk_pp_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_pp_client  FOREIGN KEY (client_id)  REFERENCES clients (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: dosavadní klient zakázky = participant customer.
INSERT IGNORE INTO project_participants (project_id, client_id, role)
SELECT p.id, p.client_id, 'customer'
  FROM projects p
 WHERE p.client_id IS NOT NULL;

-- Přijaté doklady na zakázce (nákladová strana).
ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS project_id BIGINT UNSIGNED NULL AFTER expense_category_id;

ALTER TABLE purchase_invoices
    ADD KEY IF NOT EXISTS idx_pi_project (project_id);

ALTER TABLE purchase_invoices
    ADD CONSTRAINT fk_pi_project FOREIGN KEY (project_id)
        REFERENCES projects (id) ON DELETE SET NULL;

-- Pokladní doklady na zakázce (Dokument 7 §7 — hotovost se musí potkat s případem).
ALTER TABLE cash_documents
    ADD COLUMN IF NOT EXISTS project_id BIGINT UNSIGNED NULL AFTER purchase_invoice_id;

ALTER TABLE cash_documents
    ADD KEY IF NOT EXISTS idx_cashdoc_project (project_id);

ALTER TABLE cash_documents
    ADD CONSTRAINT fk_cashdoc_project FOREIGN KEY (project_id)
        REFERENCES projects (id) ON DELETE SET NULL;
