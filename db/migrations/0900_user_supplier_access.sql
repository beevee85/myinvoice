-- 0900_user_supplier_access.sql
-- FORK (beevee85/myinvoice): per-user omezení přístupu na dodavatele.
-- Číslováno od 0900, aby nekolidovalo s upstream migracemi (ty pokračují 0120+).
--
-- Sémantika: žádný záznam pro user_id = uživatel vidí všechny dodavatele
-- (zpětná kompatibilita — stávajících uživatelů se migrace nijak nedotkne).
-- Role admin restrikci vždy ignoruje; enforcement dělá SupplierScopeMiddleware.

CREATE TABLE IF NOT EXISTS user_supplier_access (
  user_id     BIGINT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED    NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, supplier_id),
  KEY idx_usa_supplier (supplier_id),
  CONSTRAINT fk_usa_user     FOREIGN KEY (user_id)     REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_usa_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
