-- =====================================================================
-- Migration 003 - unit unlocks + tables the student app reads
-- Run ONLY against felege_kidusan_test (no CREATE DATABASE / USE inside), after 001 and 002.
-- Adds new tables only. The friend's admin/teacher dashboards are expected to WRITE to:
--   unit_unlocks, notifications, attendance, past_papers, and to UPDATE permission_requests.
-- The student app READS them (and creates permission_requests / notification_reads).
-- =====================================================================

-- A teacher/admin unlocks a whole UNIT for a grade (class NULL = whole grade).
CREATE TABLE IF NOT EXISTS `unit_unlocks` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `chapter_id` INT NOT NULL,
  `grade` VARCHAR(20) NOT NULL,
  `class` VARCHAR(50) NULL,
  `class_key` VARCHAR(50) GENERATED ALWAYS AS (IFNULL(`class`, '')) STORED,
  `teacher_id` VARCHAR(100) NULL,
  `unlocked_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_unlock_target` (`chapter_id`, `grade`, `class_key`),
  CONSTRAINT `fk_unit_unlocks_chapter` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Announcements. Audience: all NULL = everyone; grade/class narrow it; student_id = one student.
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT NULL,
  `audience_grade` VARCHAR(20) NULL,
  `audience_class` VARCHAR(50) NULL,
  `student_id` VARCHAR(50) NULL,
  `created_by` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `notification_reads` (
  `student_id` VARCHAR(50) NOT NULL,
  `notification_id` INT NOT NULL,
  `read_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`, `notification_id`),
  CONSTRAINT `fk_reads_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reads_notification` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per student per day, recorded by admin/HR.
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `student_id` VARCHAR(50) NOT NULL,
  `attendance_date` DATE NOT NULL,
  `status` ENUM('present','absent','late','permission') NOT NULL,
  `note` VARCHAR(255) NULL,
  `recorded_by` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_day` (`student_id`, `attendance_date`),
  CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Students create these; admin/teacher review them (status, reviewed_by, review_note, reviewed_at).
CREATE TABLE IF NOT EXISTS `permission_requests` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `student_id` VARCHAR(50) NOT NULL,
  `request_date` DATE NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `status` ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
  `reviewed_by` VARCHAR(100) NULL,
  `review_note` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_permission_student` (`student_id`, `created_at`),
  CONSTRAINT `fk_permission_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Past exam papers, by grade/subject/year. file_path is relative to the uploads folder.
CREATE TABLE IF NOT EXISTS `past_papers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `grade` VARCHAR(20) NOT NULL,
  `subject` VARCHAR(150) NOT NULL,
  `exam_year` VARCHAR(10) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_past_papers_grade_year` (`grade`, `exam_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
