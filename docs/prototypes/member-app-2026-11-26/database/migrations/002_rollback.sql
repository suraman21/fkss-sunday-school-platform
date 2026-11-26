-- Rollback for migration 002 (test database only). This deletes all lesson completion records.
DROP TABLE IF EXISTS `lesson_progress`;
