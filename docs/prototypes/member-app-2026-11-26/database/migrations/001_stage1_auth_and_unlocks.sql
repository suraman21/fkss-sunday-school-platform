-- =====================================================================
-- Migration 001 - Stage 1: sessions/roles support and teacher-scoped unlocks
-- =====================================================================
-- SAFETY:
--  * This file contains NO "CREATE DATABASE" and NO "USE".
--  * It runs against whichever database you name on the command line.
--  * Run it ONLY against felege_kidusan_test (see docs/STAGE1_SETUP.md).
--  * It only ADDS things and drops two redundant unique indexes.
--    No student, lesson, question or quiz rows are changed or deleted.
-- =====================================================================

-- 1) Students: flag for "must choose a private password".
--    Default 0, so existing students are NOT locked out.
ALTER TABLE `students`
  ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`;

-- 2) Teachers and administrators.
CREATE TABLE IF NOT EXISTS `staff` (
  `staff_id` VARCHAR(50) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `role` ENUM('teacher','admin') NOT NULL DEFAULT 'teacher',
  `password_hash` VARCHAR(255) NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 3) Which grades/classes each teacher may control.
--    class NULL = the whole grade.
CREATE TABLE IF NOT EXISTS `staff_scopes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `staff_id` VARCHAR(50) NOT NULL,
  `grade` VARCHAR(20) NOT NULL,
  `class` VARCHAR(50) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_staff_scopes_staff` (`staff_id`),
  CONSTRAINT `fk_staff_scopes_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 4) Failed-login log used for throttling.
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(100) NOT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_lookup` (`identifier`, `ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 5) Lesson unlocks: allow per-class unlocks.
--    Existing rows get class = NULL, i.e. "whole grade" - exactly what they meant before.
--    The new unique key is added FIRST because the lesson foreign key needs an index
--    that starts with lesson_id; only then are the two old indexes dropped.
ALTER TABLE `lesson_unlocks`
  ADD COLUMN `class` VARCHAR(50) NULL AFTER `grade`,
  ADD COLUMN `class_key` VARCHAR(50) GENERATED ALWAYS AS (IFNULL(`class`, '')) STORED AFTER `class`,
  ADD UNIQUE KEY `uq_unlock_target` (`lesson_id`, `grade`, `class_key`);

ALTER TABLE `lesson_unlocks`
  DROP INDEX `unique_lesson_grade`,
  DROP INDEX `uq_lesson_grade_teacher`;
