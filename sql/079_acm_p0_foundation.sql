-- ============================================================
-- 079_acm_p0_foundation.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ACM P0: persons + person<->account links + activation tickets
-- + ticket events. Shared-hosting safe: no generated columns, no
-- JSON column type (TEXT + json_encode in PHP), no CHECK
-- constraints, no FOREIGN KEYs (application-enforced, same as 069),
-- InnoDB + utf8mb4 like the rest of the schema.
-- Existing tables (users, members, guardians, ...) are untouched.
-- Apply in phpMyAdmin before deploying the matching code.
-- ============================================================

CREATE TABLE IF NOT EXISTS `acm_persons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `full_name_am` VARCHAR(150) NOT NULL,
  `full_name_en` VARCHAR(150) DEFAULT NULL,
  `baptismal_name` VARCHAR(150) DEFAULT NULL,
  `father_name` VARCHAR(150) DEFAULT NULL,
  `grandfather_name` VARCHAR(150) DEFAULT NULL,
  `gender` ENUM('male','female') DEFAULT NULL,
  `date_of_birth` DATE DEFAULT NULL,
  `dob_ec_day` TINYINT UNSIGNED DEFAULT NULL,
  `dob_ec_month` TINYINT UNSIGNED DEFAULT NULL,
  `dob_ec_year` SMALLINT UNSIGNED DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `phone_n9` CHAR(9) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `id_doc_type` VARCHAR(40) DEFAULT NULL,
  `id_doc_ref` VARCHAR(100) DEFAULT NULL,
  `photo_path` VARCHAR(255) DEFAULT NULL,
  `member_id` INT UNSIGNED DEFAULT NULL,
  `guardian_id` INT UNSIGNED DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acm_person_uuid` (`uuid`),
  KEY `idx_acm_person_phone_n9` (`phone_n9`),
  KEY `idx_acm_person_name` (`full_name_am`),
  KEY `idx_acm_person_member` (`member_id`),
  KEY `idx_acm_person_guardian` (`guardian_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `acm_person_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `person_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `account_type` ENUM('staff','member','parent') NOT NULL,
  `status` ENUM('pending','active','suspended','revoked') NOT NULL DEFAULT 'pending',
  `linked_by` INT UNSIGNED DEFAULT NULL,
  `linked_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acm_pa_user` (`user_id`),
  UNIQUE KEY `uniq_acm_pa_person_user` (`person_id`,`user_id`),
  KEY `idx_acm_pa_person` (`person_id`),
  KEY `idx_acm_pa_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `acm_activation_tickets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ticket_no` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `person_id` INT UNSIGNED NOT NULL,
  `account_type` ENUM('member','parent') NOT NULL,
  `status` ENUM('draft','submitted','in_review','changes_requested','approved','rejected','provisioned','archived') NOT NULL DEFAULT 'submitted',
  `member_id` INT UNSIGNED DEFAULT NULL,
  `guardian_id` INT UNSIGNED DEFAULT NULL,
  `evidence` TEXT DEFAULT NULL,
  `submitter_id` INT UNSIGNED NOT NULL,
  `assignee_id` INT UNSIGNED DEFAULT NULL,
  `decided_by` INT UNSIGNED DEFAULT NULL,
  `decision_note` TEXT DEFAULT NULL,
  `decided_at` DATETIME DEFAULT NULL,
  `sla_due` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acm_ticket_uuid` (`uuid`),
  UNIQUE KEY `uniq_acm_ticket_no` (`ticket_no`),
  KEY `idx_acm_ticket_status` (`status`),
  KEY `idx_acm_ticket_person` (`person_id`),
  KEY `idx_acm_ticket_submitter` (`submitter_id`),
  KEY `idx_acm_ticket_assignee` (`assignee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `acm_ticket_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT UNSIGNED NOT NULL,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `event` ENUM('created','submitted','claimed','released','note','changes_requested','approved','rejected','provisioned','archived') NOT NULL,
  `note` TEXT DEFAULT NULL,
  `meta` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acm_event_ticket` (`ticket_id`),
  KEY `idx_acm_event_actor` (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
