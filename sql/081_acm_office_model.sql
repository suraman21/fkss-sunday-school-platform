-- ============================================================
-- 081_acm_office_model.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ACM S1: single accounts_office seat + office modes + designated
-- approvers + independent reviewers + monthly attestations.
-- Shared-hosting safe: no FOREIGN KEYs (application-enforced, same
-- as 069/079), no JSON column type (TEXT + json_encode in PHP),
-- InnoDB + utf8mb4 like the rest of the schema.
-- Existing tables are untouched except: one new column on
-- acm_activation_tickets (self_approved flag) and the P0 seat
-- rename on users.role (test seats, one day old at time of writing).
-- The owner applies this by hand in phpMyAdmin; the verification
-- SELECTs at the end must show 0 old-role rows before deploying
-- the matching code.
-- Numbering: first written as 080 (verified 2026-11-13: 079 highest,
-- 047 reverted, 061 never existed). A parallel line landed
-- 080_telegram_registration_foundation.sql on remote main first, so
-- this file takes the next free number 081 at merge time. It was
-- never applied as 080 anywhere (owner-applies-by-hand, dev step
-- still pending), so the rename is deployment-safe.
-- ============================================================

-- Designated solo approvers. One row per designation event; revoke
-- flips status on the active row. History is retained (no DELETE).
CREATE TABLE IF NOT EXISTS `acm_office_approvers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('active','revoked') NOT NULL DEFAULT 'active',
  `designated_by` INT UNSIGNED DEFAULT NULL,
  `designated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `effective_from` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_by` INT UNSIGNED DEFAULT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `revoke_reason` TEXT DEFAULT NULL,
  `revoke_effective` DATETIME DEFAULT NULL,
  `designate_reason` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acm_approver_user` (`user_id`,`status`),
  KEY `idx_acm_approver_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Independent audit reviewers (owner decision #4, 2026-11-13).
-- A designation, NOT a users.role: any active staff account may be
-- designated; attestation additionally enforces independence (the
-- attester must have no office actions in the attested month).
CREATE TABLE IF NOT EXISTS `acm_office_reviewers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('active','revoked') NOT NULL DEFAULT 'active',
  `designated_by` INT UNSIGNED DEFAULT NULL,
  `designated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `effective_from` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_by` INT UNSIGNED DEFAULT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `revoke_reason` TEXT DEFAULT NULL,
  `revoke_effective` DATETIME DEFAULT NULL,
  `designate_reason` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acm_reviewer_user` (`user_id`,`status`),
  KEY `idx_acm_reviewer_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Monthly attestations: accountable approvals of a report snapshot
-- (NOT a cryptographic immutability claim, NOT a digital signature).
-- Corrections append new rows; rows are never edited or deleted.
CREATE TABLE IF NOT EXISTS `acm_office_attestations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `month` CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'YYYY-MM',
  `period_from` DATETIME NOT NULL,
  `period_to` DATETIME NOT NULL,
  `generated_at` DATETIME NOT NULL,
  `schema_version` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `hash_algo` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'sha256',
  `report_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attested_by` INT UNSIGNED NOT NULL,
  `attested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` TEXT NOT NULL,
  `kind` ENUM('first','correction') NOT NULL DEFAULT 'first',
  `supersedes_id` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acm_attest_month` (`month`,`kind`),
  KEY `idx_acm_attest_by` (`attested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent office-mode history: every switch is a row. No silent
-- toggling; the current mode also lives in system_settings.
CREATE TABLE IF NOT EXISTS `acm_office_mode_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mode` ENUM('separated','solo') NOT NULL,
  `reason` TEXT NOT NULL,
  `effective_date` DATE NOT NULL,
  `changed_by` INT UNSIGNED DEFAULT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acm_mode_changed` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Office settings (system_settings shape from 013: setting_key UNIQUE).
INSERT INTO `system_settings` (`setting_key`, `setting_value`)
SELECT 'acm.office_mode', 'separated' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'acm.office_mode');

INSERT INTO `system_settings` (`setting_key`, `setting_value`)
SELECT 'acm.office_network_enforced', '0' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'acm.office_network_enforced');

INSERT INTO `system_settings` (`setting_key`, `setting_value`)
SELECT 'acm.max_accounts_per_device', '5' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'acm.max_accounts_per_device');

-- Solo self-approval flag on tickets (immutable once set; S1 only).
SET @ssms_081_sql = (
    SELECT IF(
        NOT EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'acm_activation_tickets'
              AND column_name = 'self_approved'
        ),
        'ALTER TABLE `acm_activation_tickets` ADD COLUMN `self_approved` TINYINT(1) NOT NULL DEFAULT 0',
        'SELECT 1'
    )
);
PREPARE ssms_081_stmt FROM @ssms_081_sql;
EXECUTE ssms_081_stmt;
DEALLOCATE PREPARE ssms_081_stmt;

-- P0 seat rename: registrar + account_activator -> accounts_office.
UPDATE `users` SET `role` = 'accounts_office'
WHERE `role` IN ('registrar', 'account_activator');

-- Verification (owner: both checks before deploying code):
-- (1) must return 0.
SELECT COUNT(*) AS old_roles_remaining FROM `users`
WHERE `role` IN ('registrar', 'account_activator');
-- (2) shows the migrated seats.
SELECT `role`, COUNT(*) AS seats FROM `users`
WHERE `role` = 'accounts_office' GROUP BY `role`;
