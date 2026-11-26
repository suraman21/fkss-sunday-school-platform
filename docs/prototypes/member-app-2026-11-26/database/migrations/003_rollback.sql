-- Rollback for migration 003 (test database only). Deletes these tables and everything in them.
DROP TABLE IF EXISTS `past_papers`;
DROP TABLE IF EXISTS `permission_requests`;
DROP TABLE IF EXISTS `attendance`;
DROP TABLE IF EXISTS `notification_reads`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `unit_unlocks`;
