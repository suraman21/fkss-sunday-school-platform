-- 087_telegram_registration_attachments.sql (IDEMPOTENT) — apply after 086.
CREATE TABLE IF NOT EXISTS telegram_registration_attachments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  field_key VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  telegram_file_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  telegram_unique_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  stored_path VARCHAR(500) DEFAULT NULL,
  mime_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  byte_size INT UNSIGNED DEFAULT NULL,
  status ENUM('pending','stored','attached','rejected','deleted') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tg_attachment_field (session_id,field_key),
  KEY idx_tg_attachment_cleanup (status,created_at),
  KEY idx_tg_attachment_unique (telegram_unique_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
