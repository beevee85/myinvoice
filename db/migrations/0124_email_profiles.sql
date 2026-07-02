-- MyInvoice.cz — odesílací e-mailové profily.
--
-- Profil sjednocuje hlavičku From/Reply-To, volitelný DKIM selector/doménu,
-- volitelný S/MIME podpisový profil, volitelný odchozí transport (SMTP/sendmail)
-- a volitelné uložení odeslané zprávy do IMAP složky.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS. Re-run safe.
--
-- Pozn.: šifrované secrety (SMTP/IMAP heslo) jsou VARCHAR(512) — SecretEncryption
-- (AES-256-GCM + base64) zvětší plaintext o ~80 %, takže 255 by pro delší hesla /
-- OAuth tokeny přeteklo a dešifrování by až při odeslání selhalo.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS email_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(80) NOT NULL,
  from_email VARCHAR(190) NOT NULL,
  from_name VARCHAR(120) NULL,
  reply_to_email VARCHAR(190) NULL,
  reply_to_name VARCHAR(120) NULL,
  reply_to_enabled TINYINT(1) NOT NULL DEFAULT 0,
  signing_profile_id BIGINT UNSIGNED NULL,
  dkim_domain VARCHAR(190) NULL,
  dkim_selector VARCHAR(80) NULL,
  dkim_enabled TINYINT(1) NOT NULL DEFAULT 0,
  transport_type ENUM('global','smtp','sendmail') NOT NULL DEFAULT 'global',
  smtp_host VARCHAR(190) NULL,
  smtp_port INT UNSIGNED NULL,
  smtp_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls',
  smtp_auth_enabled TINYINT(1) NOT NULL DEFAULT 0,
  smtp_auth_type ENUM('LOGIN','PLAIN','CRAM-MD5','XOAUTH2') NOT NULL DEFAULT 'PLAIN',
  smtp_username VARCHAR(190) NULL,
  smtp_password_enc VARCHAR(512) NULL,
  smtp_verify_peer TINYINT(1) NOT NULL DEFAULT 1,
  smtp_verify_peer_name TINYINT(1) NOT NULL DEFAULT 1,
  smtp_allow_self_signed TINYINT(1) NOT NULL DEFAULT 0,
  smtp_timeout INT UNSIGNED NULL,
  smtp_keepalive TINYINT(1) NOT NULL DEFAULT 0,
  sendmail_command VARCHAR(255) NULL,
  imap_sent_enabled TINYINT(1) NOT NULL DEFAULT 0,
  imap_host VARCHAR(190) NULL,
  imap_port INT UNSIGNED NULL,
  imap_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'ssl',
  imap_validate_cert TINYINT(1) NOT NULL DEFAULT 1,
  imap_username VARCHAR(190) NULL,
  imap_password_enc VARCHAR(512) NULL,
  imap_folder VARCHAR(190) NULL,
  imap_create_folder TINYINT(1) NOT NULL DEFAULT 0,
  imap_mark_seen TINYINT(1) NOT NULL DEFAULT 1,
  imap_timeout INT UNSIGNED NOT NULL DEFAULT 30,
  imap_on_failure ENUM('log_only','fail_send') NOT NULL DEFAULT 'log_only',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,

  UNIQUE KEY uq_email_profile_code (supplier_id, code),
  UNIQUE KEY uq_email_profile_supplier_id (supplier_id, id),
  KEY idx_email_profiles_default (supplier_id, is_default, deleted_at, is_active),
  KEY idx_email_profiles_signing_profile (signing_profile_id),
  KEY idx_email_profiles_created_by (created_by),
  CONSTRAINT fk_email_profile_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_email_profile_signing_profile
    FOREIGN KEY (signing_profile_id) REFERENCES signing_profiles(id) ON DELETE SET NULL,
  CONSTRAINT fk_email_profile_created_by
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotentní dorovnání pro DB, kde tabulka vznikla dřívější (rozdělenou) verzí
-- této migrace: doplní pozdější sloupce a rozšíří délku šifrovaných hesel na 512.
ALTER TABLE email_profiles
  ADD COLUMN IF NOT EXISTS imap_sent_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER sendmail_command,
  ADD COLUMN IF NOT EXISTS imap_host VARCHAR(190) NULL AFTER imap_sent_enabled,
  ADD COLUMN IF NOT EXISTS imap_port INT UNSIGNED NULL AFTER imap_host,
  ADD COLUMN IF NOT EXISTS imap_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'ssl' AFTER imap_port,
  ADD COLUMN IF NOT EXISTS imap_validate_cert TINYINT(1) NOT NULL DEFAULT 1 AFTER imap_encryption,
  ADD COLUMN IF NOT EXISTS imap_username VARCHAR(190) NULL AFTER imap_validate_cert,
  ADD COLUMN IF NOT EXISTS imap_password_enc VARCHAR(512) NULL AFTER imap_username,
  ADD COLUMN IF NOT EXISTS imap_folder VARCHAR(190) NULL AFTER imap_password_enc,
  ADD COLUMN IF NOT EXISTS imap_create_folder TINYINT(1) NOT NULL DEFAULT 0 AFTER imap_folder,
  ADD COLUMN IF NOT EXISTS imap_mark_seen TINYINT(1) NOT NULL DEFAULT 1 AFTER imap_create_folder,
  ADD COLUMN IF NOT EXISTS imap_timeout INT UNSIGNED NOT NULL DEFAULT 30 AFTER imap_mark_seen,
  ADD COLUMN IF NOT EXISTS imap_on_failure ENUM('log_only','fail_send') NOT NULL DEFAULT 'log_only' AFTER imap_timeout;

ALTER TABLE email_profiles MODIFY COLUMN smtp_password_enc VARCHAR(512) NULL;
ALTER TABLE email_profiles MODIFY COLUMN imap_password_enc VARCHAR(512) NULL;
