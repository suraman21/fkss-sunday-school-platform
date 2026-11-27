-- ============================================================
-- 000_members_migration_ledger.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ------------------------------------------------------------
-- The bookkeeping table for Members App migrations.
--
-- WHY THIS IS A FILE AND NOT CODE
--   The runner used to create it on first run. The repository's
--   request-time-schema guard (tests/security/test_request_time_schema.py)
--   refuses DDL anywhere on a normal application path, and it is right to: a
--   class that defines schema puts the schema in two places, and "run the
--   migrator" would become the moment a database grows a table nobody reviewed.
--
--   So the ledger is declared here, like every other table, and the runner only
--   reads it. Apply this file by hand ONCE, before the first
--   `php members/tools/migrate.php --execute`.
--
-- APPLY THIS BEFORE ANY OTHER members/ MIGRATION.
-- ============================================================

CREATE TABLE IF NOT EXISTS `members_schema_migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- ascii_bin: filenames are compared byte-for-byte. A case- or
  -- accent-insensitive collation would let 001_x.sql and 001_X.sql collide.
  `filename` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- sha256 of the file as applied. A later change means history was edited,
  -- which the runner reports instead of silently re-running.
  `checksum` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_members_migration` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
