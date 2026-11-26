-- =====================================================================
-- Migration 002 - lesson completion records
-- Run ONLY against felege_kidusan_test (no CREATE DATABASE / USE inside).
-- Adds one new table. Nothing existing is changed or deleted.
-- Foreign keys use RESTRICT so deleting a student or lesson can never
-- silently erase learning progress.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `lesson_progress` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `student_id` VARCHAR(50) NOT NULL,
  `lesson_id` INT NOT NULL,
  `completed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lesson_progress` (`student_id`, `lesson_id`),
  KEY `idx_lesson_progress_lesson` (`lesson_id`),
  CONSTRAINT `fk_lesson_progress_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_lesson_progress_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
