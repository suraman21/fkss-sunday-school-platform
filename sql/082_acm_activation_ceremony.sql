-- ============================================================
-- 082_acm_activation_ceremony.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ACM S3: purpose-bound activation codes (selector + hash-only
-- secret), single-use activation grants, per-IP attempt throttles,
-- idempotency keys, office-network allowlist, ticket lifecycle
-- states. Shared-hosting safe: no FOREIGN KEYs
-- (application-enforced, same as 069/079/081), no JSON column type
-- (TEXT + json_encode in PHP), InnoDB + utf8mb4.
-- Existing tables are untouched except APPEND-ONLY ENUM widening on
-- acm_activation_tickets.status and acm_ticket_events.event (new
-- values appended; existing values keep their order and indexes).
-- S3 code deploys first and keeps working pre-migration: new states
-- are written only by new actions, which need these tables anyway.
-- Numbering verified 2026-11-13: 080 telegram, 081 ACM office.
-- ============================================================

-- Purpose-bound codes. The SELECTOR is public (indexed lookup key);
-- only a salted hash of the SECRET is stored. Plaintext exists only
-- in the single issue response. Prefix (A/D/R) is human aid only:
-- the purpose column is machine-enforced on every redemption.
CREATE TABLE IF NOT EXISTS `acm_activation_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ticket_id` INT UNSIGNED NOT NULL,
  `person_id` INT UNSIGNED NOT NULL,
  `purpose` ENUM('activation','device_add','reset') NOT NULL,
  `prefix` CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `selector` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `secret_hash` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issued_by` INT UNSIGNED DEFAULT NULL,
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
  `consumed_at` DATETIME DEFAULT NULL,
  `status` ENUM('active','consumed','revoked','expired') NOT NULL DEFAULT 'active',
  `office_ip` VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `reissue_of` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acm_code_uuid` (`uuid`),
  UNIQUE KEY `uniq_acm_code_selector` (`selector`),
  KEY `idx_acm_code_ticket` (`ticket_id`,`purpose`,`status`),
  KEY `idx_acm_code_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activation grants: full credentials. Hash-only storage, single-use,
-- short TTL, bound to ticket + person + operation. Lookup by
-- fingerprint (unique, high-entropy) + hash verify of the full token.
CREATE TABLE IF NOT EXISTS `acm_activation_grants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `code_id` INT UNSIGNED NOT NULL,
  `ticket_id` INT UNSIGNED NOT NULL,
  `person_id` INT UNSIGNED NOT NULL,
  `operation` ENUM('activation','device_add','reset') NOT NULL,
  `grant_hash` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fingerprint` CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `revocation_reason` VARCHAR(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acm_grant_uuid` (`uuid`),
  UNIQUE KEY `uniq_acm_grant_fp` (`fingerprint`),
  KEY `idx_acm_grant_ticket` (`ticket_id`,`operation`),
  KEY `idx_acm_grant_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-IP, per-selector failure counting. Lets the server throttle one
-- abusive IP WITHOUT spending the code's burn budget (anti-DoS).
CREATE TABLE IF NOT EXISTS `acm_activation_attempts` (
  `selector` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ip` VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`selector`,`ip`),
  KEY `idx_acm_att_ip` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Credential-establishment idempotency: same key + same grant replays
-- the stored result; same key + different grant is a conflict.
CREATE TABLE IF NOT EXISTS `acm_idempotency_keys` (
  `idem_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `grant_fingerprint` CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `username` VARCHAR(50) DEFAULT NULL,
  `account_type` VARCHAR(16) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`idem_key`),
  KEY `idx_acm_idem_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Office-network allowlist (super_admin-managed). The separate
-- acm.trusted_proxies setting holds deploy-level proxy ranges.
CREATE TABLE IF NOT EXISTS `acm_office_networks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cidr` VARCHAR(49) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `family` ENUM('v4','v6') NOT NULL,
  `label` VARCHAR(120) DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `added_by` INT UNSIGNED DEFAULT NULL,
  `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deactivated_by` INT UNSIGNED DEFAULT NULL,
  `deactivated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acm_net_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deploy-level trusted-proxy set (empty = none trusted = direct peer).
INSERT INTO `system_settings` (`setting_key`, `setting_value`)
SELECT 'acm.trusted_proxies', '' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'acm.trusted_proxies');

-- Office-network enforcement (design D3.7): default OFF until CIDRs
-- are recorded AND tested. '1' = redemption from a non-allowlisted
-- or unknown IP fails closed with network_denied.
INSERT INTO `system_settings` (`setting_key`, `setting_value`)
SELECT 'acm.office_network_enforced', '0' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'acm.office_network_enforced');

-- Ticket lifecycle states (append-only widen; existing order kept).
SET @ssms_082_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'acm_activation_tickets'
              AND column_name = 'status'
              AND column_type NOT LIKE '%activation_failed%'
        ),
        'ALTER TABLE `acm_activation_tickets` MODIFY COLUMN `status` ENUM(''draft'',''submitted'',''in_review'',''changes_requested'',''approved'',''rejected'',''provisioned'',''archived'',''code_issued'',''grant_verified'',''account_pending_device'',''active'',''canceled'',''expired'',''revoked'',''activation_failed'') NOT NULL DEFAULT ''submitted''',
        'SELECT 1'
    )
);
PREPARE ssms_082_stmt FROM @ssms_082_sql;
EXECUTE ssms_082_stmt;
DEALLOCATE PREPARE ssms_082_stmt;

-- Ticket event vocabulary (append-only widen).
SET @ssms_082_ev_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'acm_ticket_events'
              AND column_name = 'event'
              AND column_type NOT LIKE '%activation_failed%'
        ),
        'ALTER TABLE `acm_ticket_events` MODIFY COLUMN `event` ENUM(''created'',''submitted'',''claimed'',''released'',''note'',''changes_requested'',''approved'',''rejected'',''provisioned'',''archived'',''code_issued'',''code_reissued'',''code_burned'',''grant_verified'',''credentials_set'',''activated'',''canceled'',''expired'',''revoked'',''activation_failed'') NOT NULL',
        'SELECT 1'
    )
);
PREPARE ssms_082_ev_stmt FROM @ssms_082_ev_sql;
EXECUTE ssms_082_ev_stmt;
DEALLOCATE PREPARE ssms_082_ev_stmt;
