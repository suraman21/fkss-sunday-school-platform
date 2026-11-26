-- Rollback for migration 001. Run ONLY on the test database.
-- WARNING: re-creating the old unique index (lesson_id, grade) fails if two rows now
-- exist for the same lesson+grade (for example a whole-grade row and a class row).
-- Delete the class-specific rows first if that happens.
ALTER TABLE `lesson_unlocks`
  ADD UNIQUE KEY `unique_lesson_grade` (`lesson_id`, `grade`);
ALTER TABLE `lesson_unlocks`
  DROP INDEX `uq_unlock_target`,
  DROP COLUMN `class_key`,
  DROP COLUMN `class`,
  ADD UNIQUE KEY `uq_lesson_grade_teacher` (`lesson_id`, `grade`, `teacher_id`);
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `staff_scopes`;
DROP TABLE IF EXISTS `staff`;
ALTER TABLE `students` DROP COLUMN `must_change_password`;
