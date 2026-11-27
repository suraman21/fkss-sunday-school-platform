-- ============================================================
-- 001_members_learning_schema.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ------------------------------------------------------------
-- The Members App's OWN tables: the learning path, lesson progress and
-- quiz attempts that the prototype kept in a throwaway database
-- (`felege_kidusan_test`) and that this platform does not yet have.
--
-- WHAT IS *NOT* HERE, AND WHY
--   members, guardians, member_guardians, users, acm_*   -> owned by HR/ACM.
--   attendance, notifications, mezmur_*                  -> already exist.
--   This app READS those. It must never create a parallel copy: two tables
--   describing one student is the exact fault the ACM boundary work removed.
--
-- SCOPE OF THIS FILE
--   subject -> unit -> lesson  (the path)
--   lesson_progress            (explicit completion, one row per member/lesson)
--   lesson_unlocks             (teacher grants, scoped to a member)
--   quizzes + questions        (unit quiz)
--   quiz_attempts              (one row per attempt, with a review payload)
--
-- KEYED BY member_id, NOT user_id
--   A lesson belongs to a student, and a student may hold no login at all.
--   Keying progress to a user would lose a child's work the moment their
--   account was suspended, replaced or never created. member_id is the
--   register's identity and the one that survives.
-- ============================================================

CREATE TABLE IF NOT EXISTS `members_subjects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name_am` VARCHAR(150) NOT NULL,
  `name_en` VARCHAR(150) DEFAULT NULL,
  `description_am` TEXT DEFAULT NULL,
  `icon` VARCHAR(40) DEFAULT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_members_subject_code` (`code`),
  KEY `idx_members_subject_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `members_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_id` INT UNSIGNED NOT NULL,
  `number` SMALLINT UNSIGNED NOT NULL,
  `title_am` VARCHAR(200) NOT NULL,
  `title_en` VARCHAR(200) DEFAULT NULL,
  `summary_am` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_members_unit_number` (`subject_id`, `number`),
  KEY `idx_members_unit_subject` (`subject_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `members_lessons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_id` INT UNSIGNED NOT NULL,
  `number` SMALLINT UNSIGNED NOT NULL,
  `title_am` VARCHAR(200) NOT NULL,
  `title_en` VARCHAR(200) DEFAULT NULL,
  `body_am` MEDIUMTEXT DEFAULT NULL,
  -- Estimated reading time in minutes. Shown before a student commits to a
  -- lesson; authors fill it in, it is never computed from byte length.
  `est_minutes` SMALLINT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_members_lesson_number` (`unit_id`, `number`),
  KEY `idx_members_lesson_unit` (`unit_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `members_lesson_progress` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` INT UNSIGNED NOT NULL,
  `lesson_id` INT UNSIGNED NOT NULL,
  `status` ENUM('started','completed') NOT NULL DEFAULT 'started',
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  -- Who marked it complete. NULL means the student pressed "done" in the app;
  -- a staff id means the office recorded it. Kept because a completion is a
  -- claim about a child's work and should be attributable.
  `completed_by` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  -- One row per member per lesson. Without this a retry of "complete" creates
  -- a second row and every COUNT(*) overstates progress.
  UNIQUE KEY `uniq_members_progress` (`member_id`, `lesson_id`),
  KEY `idx_members_progress_member` (`member_id`, `status`),
  KEY `idx_members_progress_lesson` (`lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `members_lesson_unlocks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` INT UNSIGNED NOT NULL,
  `lesson_id` INT UNSIGNED NOT NULL,
  -- NULL = open to the whole grade / no expiry. A date means the unlock lapses,
  -- which is how a teacher lends access for one revision session.
  `expires_at` DATETIME DEFAULT NULL,
  `reason` VARCHAR(200) DEFAULT NULL,
  `granted_by` INT UNSIGNED DEFAULT NULL,
  `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_members_unlock` (`member_id`, `lesson_id`),
  KEY `idx_members_unlock_member` (`member_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `members_quizzes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_id` INT UNSIGNED NOT NULL,
  `title_am` VARCHAR(200) NOT NULL,
  `pass_score` TINYINT UNSIGNED NOT NULL DEFAULT 70,
  `time_limit_seconds` SMALLINT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- One unit quiz. The unlock rule ("pass the unit quiz") is only well defined
  -- if there is exactly one.
  UNIQUE KEY `uniq_members_quiz_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `members_quiz_questions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quiz_id` INT UNSIGNED NOT NULL,
  `prompt_am` TEXT NOT NULL,
  `options_json` TEXT NOT NULL,
  -- Index into options_json. A separate column so a question can be validated
  -- ("is the answer index inside the options?") without parsing in queries.
  `correct_index` TINYINT UNSIGNED NOT NULL,
  `explanation_am` TEXT DEFAULT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_members_question_quiz` (`quiz_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `members_quiz_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` INT UNSIGNED NOT NULL,
  `quiz_id` INT UNSIGNED NOT NULL,
  `score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `passed` TINYINT(1) NOT NULL DEFAULT 0,
  -- The answers AS SUBMITTED, so a result can be reviewed and disputed later.
  -- Stored as JSON text (no JSON column type: shared hosting compatibility,
  -- the same reason sql/069 avoided it).
  `answers_json` TEXT NOT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_members_attempt_member` (`member_id`, `quiz_id`, `submitted_at`),
  KEY `idx_members_attempt_quiz` (`quiz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
