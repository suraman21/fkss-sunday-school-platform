-- ============================================================
-- 080_telegram_registration_foundation.sql (IDEMPOTENT / RE-RUNNABLE)
-- Authorized Telegram registration drafts + configurable form profiles.
-- Phase 1 is review-only: this migration never creates a member from a bot.
-- Shared-hosting safe: no daemon, JSON column, generated column or FK cascade.
-- Apply with phpMyAdmin after 079, before enabling FEATURE_TELEGRAM_REGISTRATION.
-- ============================================================

CREATE TABLE IF NOT EXISTS `telegram_bot_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `telegram_user_id` BIGINT NOT NULL,
  `linked_user_id` INT UNSIGNED DEFAULT NULL,
  `display_name` VARCHAR(150) DEFAULT NULL,
  `username_snapshot` VARCHAR(64) DEFAULT NULL,
  `status` ENUM('pending','active','suspended','revoked') NOT NULL DEFAULT 'pending',
  `can_waiting` TINYINT(1) NOT NULL DEFAULT 1,
  `can_direct` TINYINT(1) NOT NULL DEFAULT 0,
  `daily_registration_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  `expires_at` DATETIME DEFAULT NULL,
  `authorization_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_seen_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tg_user_id` (`telegram_user_id`),
  KEY `idx_tg_linked_user` (`linked_user_id`),
  KEY `idx_tg_user_status` (`status`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_form_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_key` ENUM('waiting','direct') NOT NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
  `title_am` VARCHAR(150) NOT NULL,
  `title_en` VARCHAR(150) NOT NULL,
  `published_by` INT UNSIGNED DEFAULT NULL,
  `published_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tg_form_version` (`profile_key`,`version`),
  KEY `idx_tg_form_published` (`profile_key`,`status`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_form_fields` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_id` INT UNSIGNED NOT NULL,
  `field_key` VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `prompt_am` VARCHAR(500) NOT NULL,
  `prompt_en` VARCHAR(500) NOT NULL,
  `help_am` VARCHAR(500) DEFAULT NULL,
  `help_en` VARCHAR(500) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tg_form_field` (`profile_id`,`field_key`),
  KEY `idx_tg_form_field_order` (`profile_id`,`is_enabled`,`sort_order`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_registration_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `bot_user_id` INT UNSIGNED NOT NULL,
  `telegram_chat_id` BIGINT NOT NULL,
  `profile_key` ENUM('waiting','direct') DEFAULT NULL,
  `form_profile_id` INT UNSIGNED DEFAULT NULL,
  `form_version` INT UNSIGNED DEFAULT NULL,
  `language` ENUM('am','en') NOT NULL DEFAULT 'am',
  `status` ENUM('choosing','collecting','review','submitted','cancelled','expired','failed') NOT NULL DEFAULT 'choosing',
  `current_field_key` VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `duplicate_state` ENUM('not_checked','none','possible','likely','error') NOT NULL DEFAULT 'not_checked',
  `duplicate_summary` TEXT DEFAULT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `expires_at` DATETIME NOT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tg_session_public` (`public_id`),
  KEY `idx_tg_session_actor` (`bot_user_id`,`status`,`updated_at`),
  KEY `idx_tg_session_chat` (`telegram_chat_id`,`status`,`updated_at`),
  KEY `idx_tg_session_review` (`status`,`submitted_at`,`id`),
  KEY `idx_tg_session_expiry` (`status`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_registration_answers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `field_key` VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_text` TEXT DEFAULT NULL,
  `value_normalized` VARCHAR(500) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tg_answer` (`session_id`,`field_key`),
  KEY `idx_tg_answer_session` (`session_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_processed_updates` (
  `update_id` BIGINT NOT NULL,
  `telegram_user_id` BIGINT DEFAULT NULL,
  `update_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` ENUM('processing','processed','ignored','failed') NOT NULL DEFAULT 'processing',
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME DEFAULT NULL,
  `processing_ms` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`update_id`),
  KEY `idx_tg_update_retention` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_registration_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `decision` ENUM('approved','rejected','changes_requested','duplicate') NOT NULL,
  `reviewer_user_id` INT UNSIGNED NOT NULL,
  `member_id` INT UNSIGNED DEFAULT NULL,
  `note` VARCHAR(1000) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tg_session_decision` (`session_id`),
  KEY `idx_tg_review_reviewer` (`reviewer_user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type` ENUM('telegram','admin','system') NOT NULL,
  `actor_id` BIGINT DEFAULT NULL,
  `event` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `entity_type` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `entity_id` BIGINT DEFAULT NULL,
  `result` ENUM('success','denied','error') NOT NULL DEFAULT 'success',
  `meta` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tg_audit_created` (`created_at`),
  KEY `idx_tg_audit_actor` (`actor_type`,`actor_id`,`created_at`),
  KEY `idx_tg_audit_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Search-only canonical identity columns. Original names remain untouched.
DROP PROCEDURE IF EXISTS `ssms_080_add_column`;
DELIMITER $$
CREATE PROCEDURE `ssms_080_add_column`(IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='members')
     AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='members' AND column_name=p_column) THEN
    SET @s = CONCAT('ALTER TABLE `members` ADD COLUMN `', REPLACE(p_column,'`','``'), '` ', p_definition);
    PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
  END IF;
END$$
DELIMITER ;
CALL ssms_080_add_column('name_norm_version', 'TINYINT UNSIGNED DEFAULT NULL');
CALL ssms_080_add_column('student_name_norm', 'VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL');
CALL ssms_080_add_column('father_name_norm', 'VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL');
CALL ssms_080_add_column('grandfather_name_norm', 'VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL');
CALL ssms_080_add_column('name_pair_hash', 'BINARY(16) DEFAULT NULL');
CALL ssms_080_add_column('phone_suffix9', 'CHAR(9) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL');
DROP PROCEDURE IF EXISTS `ssms_080_add_column`;

DROP PROCEDURE IF EXISTS `ssms_080_add_index`;
DELIMITER $$
CREATE PROCEDURE `ssms_080_add_index`(IN p_name VARCHAR(64), IN p_columns TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='members')
     AND NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='members' AND index_name=p_name) THEN
    SET @s = CONCAT('ALTER TABLE `members` ADD INDEX `', REPLACE(p_name,'`','``'), '` (', p_columns, ')');
    PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
  END IF;
END$$
DELIMITER ;
CALL ssms_080_add_index('idx_members_norm_pair', '`student_name_norm`(63),`father_name_norm`(63),`status`,`id`');
CALL ssms_080_add_index('idx_members_name_pair_hash', '`name_pair_hash`,`status`,`id`');
CALL ssms_080_add_index('idx_members_phone_suffix9', '`phone_suffix9`,`status`,`id`');
CALL ssms_080_add_index('idx_members_dob_norm', '`dob_ec_year`,`dob_ec_month`,`dob_ec_day`,`student_name_norm`(32),`id`');
DROP PROCEDURE IF EXISTS `ssms_080_add_index`;

-- Published defaults. A form version is immutable after sessions reference it;
-- administrators create/publish a new version for future sessions.
INSERT INTO telegram_form_profiles (profile_key,version,status,title_am,title_en,published_at)
SELECT 'waiting',1,'published','የጊዜያዊ / ተጠባባቂ ምዝገባ','Temporary / waiting registration',CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM telegram_form_profiles WHERE profile_key='waiting' AND version=1);
INSERT INTO telegram_form_profiles (profile_key,version,status,title_am,title_en,published_at)
SELECT 'direct',1,'published','ቀጥታ ምዝገባ','Direct registration',CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM telegram_form_profiles WHERE profile_key='direct' AND version=1);

INSERT IGNORE INTO telegram_form_fields
(profile_id,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en)
SELECT p.id,'full_name_am',1,1,10,
'ሙሉ ስምዎን ያስገቡ። ቢያንስ የራስ እና የአባት ስም፤ ሦስት ስም ይመረጣል።',
'Enter the full name. First and father name are required; three names are preferred.',
'ምሳሌ፦ አበበ ከበደ ተስፋዬ','Example: Abebe Kebede Tesfaye'
FROM telegram_form_profiles p WHERE p.version=1;
INSERT IGNORE INTO telegram_form_fields
(profile_id,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en)
SELECT p.id,'baptismal_name',1,0,20,'የክርስትና ስም ያስገቡ። ከሌለ /skip ይጫኑ።','Enter the Christian/baptismal name, or /skip.','አጭር ጽሑፍ ብቻ።','Text only.' FROM telegram_form_profiles p WHERE p.version=1;
INSERT IGNORE INTO telegram_form_fields
(profile_id,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en)
SELECT p.id,'gender',1,1,30,'ጾታ ይምረጡ፦ ወንድ / ሴት','Choose gender: Male / Female','በአዝራሩ ይምረጡ።','Use the buttons.' FROM telegram_form_profiles p WHERE p.version=1;
INSERT IGNORE INTO telegram_form_fields
(profile_id,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en)
SELECT p.id,'dob_ec',1,0,40,'የትውልድ ቀን በኢትዮጵያ ዘመን አቆጣጠር ቀን/ወር/ዓመት ያስገቡ። ከሌለ /skip።','Enter Ethiopian date of birth as day/month/year, or /skip.','ምሳሌ፦ 12/04/2002። 13ኛው ወር 5 ወይም 6 ቀን ነው።','Example: 12/04/2002. Month 13 has 5 or 6 days.' FROM telegram_form_profiles p WHERE p.version=1;
INSERT IGNORE INTO telegram_form_fields
(profile_id,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en)
SELECT p.id,'phone_number',1,0,50,'ስልክ ቁጥር ያስገቡ። ከሌለ /skip።','Enter phone number, or /skip.','09… ወይም +2519…','Use 09… or +2519…' FROM telegram_form_profiles p WHERE p.version=1;
INSERT IGNORE INTO telegram_form_fields
(profile_id,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en)
SELECT p.id,'guardian_name',1,0,60,'የአሳዳጊ ሙሉ ስም ያስገቡ። ከሌለ /skip።','Enter guardian full name, or /skip.','ለሕፃናት ይሙሉ።','Complete for a child.' FROM telegram_form_profiles p WHERE p.version=1;
INSERT IGNORE INTO telegram_form_fields
(profile_id,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en)
SELECT p.id,'guardian_phone1',1,0,70,'የአሳዳጊ ስልክ ያስገቡ። ከሌለ /skip።','Enter guardian phone, or /skip.','09… ወይም +2519…','Use 09… or +2519…' FROM telegram_form_profiles p WHERE p.version=1;
