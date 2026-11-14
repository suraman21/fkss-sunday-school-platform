-- ============================================================
-- 084_acm_device_registry.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ACM S4: challenge-signature device registry. Registered devices
-- hold a verified Keystore public key (SPKI DER, fingerprint
-- lookup); registration challenges are hash-only, single-use,
-- short-TTL proof artifacts. Refresh families link to devices via
-- a NULLABLE acm_device_id (legacy NULLs keep current behavior;
-- recording a link is not enforcing binding -- enforcement is S5).
-- Shared-hosting safe: no FOREIGN KEYs (application-enforced,
-- same as 069/079/081/082), no JSON column type, InnoDB + utf8mb4.
-- Existing tables are untouched except an APPEND-ONLY ENUM widen
-- on acm_ticket_events.event (new values appended; existing values
-- keep their order and indexes) and one NULLABLE column + index on
-- api_refresh_sessions.
-- S4 code deploys first and keeps working pre-migration: device
-- rows are written only by new actions, which need these tables
-- anyway; the login link and revoke cascade probe the column.
-- Numbering verified 2026-11-13: 080 telegram, 081 ACM office
-- (+081 telegram phase2 interview), 082 ACM ceremony (+082 telegram
-- corrections), 083 telegram approval, 084 ACM registry. (Renumbered
-- 083->084: telegram landed 083 first; ACM yields per the 080
-- precedent. 081/082 pairs are telegram-vs-ACM collisions owned by
-- the telegram stream -- migration-hygiene stays red until those
-- are resolved.)
-- ============================================================

-- Registered devices. One row per (account, installation) keypair:
-- the app generates a DISTINCT Keystore keypair per account context
-- on shared phones, so per-account revocation stays possible. Only
-- the public key is stored (SPKI DER, base64); private key material
-- never leaves the device and never reaches this table.
CREATE TABLE IF NOT EXISTS `acm_devices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `installation_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `pubkey` MEDIUMTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `alg` ENUM('ES256','RS256') NOT NULL,
  `name` VARCHAR(80) DEFAULT NULL,
  `status` ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
  `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` DATETIME DEFAULT NULL,
  `revoked_by` INT UNSIGNED DEFAULT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `revoke_reason` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acm_device_uuid` (`uuid`),
  UNIQUE KEY `uniq_acm_device_user_fp` (`user_id`,`fingerprint`),
  KEY `idx_acm_device_install` (`installation_id`,`status`),
  KEY `idx_acm_device_user` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registration challenges: self-contained proof artifacts. The
-- challenge row carries everything `complete` needs (user,
-- installation, pending device, key, algorithm, authorizing
-- grant-or-session) so possession proof never depends on a join
-- that a re-begin could have superseded. Only the challenge HASH
-- is stored; the solution travels once inside the begin response
-- and returns once inside the signed complete request
-- (echo-compared against the stored hash).
CREATE TABLE IF NOT EXISTS `acm_device_challenges` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `device_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `installation_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `pubkey` MEDIUMTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `alg` ENUM('ES256','RS256') NOT NULL,
  `grant_id` INT UNSIGNED DEFAULT NULL,
  `session_bound` TINYINT(1) NOT NULL DEFAULT 0,
  `challenge_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acm_challenge_uuid` (`uuid`),
  KEY `idx_acm_challenge_device` (`device_id`),
  KEY `idx_acm_challenge_user` (`user_id`),
  KEY `idx_acm_challenge_grant` (`grant_id`),
  KEY `idx_acm_challenge_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Refresh-family device link (design D3.8/D5.7). NULLABLE: legacy
-- families keep current behavior; revocation touches only families
-- with an explicit link. Single ALTER so column + index land
-- together under one idempotency guard.
SET @ssms_084_link_sql = (
    SELECT IF(
        NOT EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'api_refresh_sessions'
              AND column_name = 'acm_device_id'
        ),
        'ALTER TABLE `api_refresh_sessions` ADD COLUMN `acm_device_id` INT UNSIGNED DEFAULT NULL, ADD INDEX `idx_api_refresh_acm_device` (`acm_device_id`)',
        'SELECT 1'
    )
);
PREPARE ssms_084_link_stmt FROM @ssms_084_link_sql;
EXECUTE ssms_084_link_stmt;
DEALLOCATE PREPARE ssms_084_link_stmt;

-- Device challenge TTL (minutes): short-lived proof window.
INSERT INTO `system_settings` (`setting_key`, `setting_value`)
SELECT 'acm.device_challenge_ttl_minutes', '10' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'acm.device_challenge_ttl_minutes');

-- Challenge attempts before the challenge burns (mirrors code burn).
INSERT INTO `system_settings` (`setting_key`, `setting_value`)
SELECT 'acm.device_challenge_max_attempts', '5' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'acm.device_challenge_max_attempts');

-- Max ACTIVE devices per account (owner decision D10 #12,
-- approved 2026-11-13: 5). Revoked devices do not count.
INSERT INTO `system_settings` (`setting_key`, `setting_value`)
SELECT 'acm.max_devices_per_account', '5' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'acm.max_devices_per_account');

-- Ticket event vocabulary (append-only widen).
SET @ssms_084_ev_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'acm_ticket_events'
              AND column_name = 'event'
              AND column_type NOT LIKE '%device_revoked%'
        ),
        'ALTER TABLE `acm_ticket_events` MODIFY COLUMN `event` ENUM(''created'',''submitted'',''claimed'',''released'',''note'',''changes_requested'',''approved'',''rejected'',''provisioned'',''archived'',''code_issued'',''code_reissued'',''code_burned'',''grant_verified'',''credentials_set'',''activated'',''canceled'',''expired'',''revoked'',''activation_failed'',''device_registered'',''device_revoked'') NOT NULL',
        'SELECT 1'
    )
);
PREPARE ssms_084_ev_stmt FROM @ssms_084_ev_sql;
EXECUTE ssms_084_ev_stmt;
DEALLOCATE PREPARE ssms_084_ev_stmt;
