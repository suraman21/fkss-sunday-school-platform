-- ============================================================
-- 107_acm_person_identity_unique.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ------------------------------------------------------------
-- WHY
--   acm_persons is a PROJECTION of HR's register: one person row per
--   members.id, one per guardians.id. sql/079 gave both columns a plain
--   KEY, not a UNIQUE key, so the database has never enforced that.
--
--   That mattered as soon as a person row became DERIVED rather than
--   hand-typed (see docs/design/acm-department-boundaries.md). With a
--   manual form the office would notice a duplicate human; with an
--   automatic projection two simultaneous first-claims for the same member
--   can both insert, and nobody is looking.
--
--   This adds the constraint the projection always assumed it had.
--
-- SAFETY — WHY THIS IS NOT "JUST ADD A UNIQUE KEY"
--   A deployment may ALREADY hold duplicates from the manual era. Adding a
--   UNIQUE key with duplicates present fails, and on MySQL a failed ALTER
--   inside a partially-applied migration is how a deployment gets stuck.
--   So this does NOT delete anything. It:
--     1. writes a duplicate report to a table (so the office can act), and
--     2. adds the key ONLY when no duplicate exists.
--   Re-running after the duplicates are merged adds the key then. This is
--   the repo rule (018/031): never resolve duplicates for the user.
--
--   Nothing here touches `members`, `guardians` or `member_guardians`.
--   HR's tables are read-only to ACM.
--
-- Shared-hosting safe: no FOREIGN KEYs, no generated columns, additive only.
-- Apply in phpMyAdmin before deploying the matching code.
-- ============================================================

-- ------------------------------------------------------------
-- 1. The duplicate report. One row per (column, value) that repeats.
--    Kept as a table rather than a SELECT so the finding survives the
--    session: whoever applies the migration may not be the office that
--    has to fix it.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `acm_person_duplicate_report` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- 'member_id' or 'guardian_id': which link is duplicated.
  `link_column` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- The members.id / guardians.id that more than one person points at.
  `link_value` INT UNSIGNED NOT NULL,
  -- How many person rows claim it, and which ones.
  `person_count` INT UNSIGNED NOT NULL,
  `person_ids` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `detected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acm_dup_link` (`link_column`,`link_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Record the duplicates that exist right now.
--    INSERT ... SELECT with GROUP BY/HAVING is the same shape as the
--    duplicate check in sql/100; the ON DUPLICATE KEY keeps re-runs quiet
--    and refreshes the counts.
-- ------------------------------------------------------------
INSERT INTO `acm_person_duplicate_report`
       (`link_column`, `link_value`, `person_count`, `person_ids`)
SELECT 'member_id', `member_id`, COUNT(*), GROUP_CONCAT(`id` ORDER BY `id`)
  FROM `acm_persons`
 WHERE `member_id` IS NOT NULL
 GROUP BY `member_id`
HAVING COUNT(*) > 1
ON DUPLICATE KEY UPDATE
  `person_count` = VALUES(`person_count`),
  `person_ids`   = VALUES(`person_ids`),
  `detected_at`  = CURRENT_TIMESTAMP;

INSERT INTO `acm_person_duplicate_report`
       (`link_column`, `link_value`, `person_count`, `person_ids`)
SELECT 'guardian_id', `guardian_id`, COUNT(*), GROUP_CONCAT(`id` ORDER BY `id`)
  FROM `acm_persons`
 WHERE `guardian_id` IS NOT NULL
 GROUP BY `guardian_id`
HAVING COUNT(*) > 1
ON DUPLICATE KEY UPDATE
  `person_count` = VALUES(`person_count`),
  `person_ids`   = VALUES(`person_ids`),
  `detected_at`  = CURRENT_TIMESTAMP;

-- ------------------------------------------------------------
-- 3. Add each UNIQUE key only if that link is currently duplicate-free.
--    Two separate procedures so a clean member link still gets its key
--    even when the guardian side is dirty.
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `ssms_107_member_unique`;
DROP PROCEDURE IF EXISTS `ssms_107_guardian_unique`;

DELIMITER $$

CREATE PROCEDURE `ssms_107_member_unique`()
BEGIN
    DECLARE dupes INT DEFAULT 0;
    DECLARE has_key INT DEFAULT 0;

    SELECT COUNT(*) INTO dupes FROM (
        SELECT `member_id` FROM `acm_persons`
         WHERE `member_id` IS NOT NULL
         GROUP BY `member_id` HAVING COUNT(*) > 1
    ) d;

    IF dupes = 0 THEN
        SELECT COUNT(*) INTO has_key
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'acm_persons'
           AND INDEX_NAME   = 'uniq_acm_person_member';
        IF has_key = 0 THEN
            ALTER TABLE `acm_persons`
                ADD UNIQUE KEY `uniq_acm_person_member` (`member_id`);
        END IF;
    END IF;
END $$

CREATE PROCEDURE `ssms_107_guardian_unique`()
BEGIN
    DECLARE dupes INT DEFAULT 0;
    DECLARE has_key INT DEFAULT 0;

    SELECT COUNT(*) INTO dupes FROM (
        SELECT `guardian_id` FROM `acm_persons`
         WHERE `guardian_id` IS NOT NULL
         GROUP BY `guardian_id` HAVING COUNT(*) > 1
    ) d;

    IF dupes = 0 THEN
        SELECT COUNT(*) INTO has_key
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'acm_persons'
           AND INDEX_NAME   = 'uniq_acm_person_guardian';
        IF has_key = 0 THEN
            ALTER TABLE `acm_persons`
                ADD UNIQUE KEY `uniq_acm_person_guardian` (`guardian_id`);
        END IF;
    END IF;
END $$

DELIMITER ;

CALL `ssms_107_member_unique`();
CALL `ssms_107_guardian_unique`();

DROP PROCEDURE IF EXISTS `ssms_107_member_unique`;
DROP PROCEDURE IF EXISTS `ssms_107_guardian_unique`;

-- ------------------------------------------------------------
-- 4. What the operator should see after running this.
--    Rows here are NOT an error: they are work for HR to merge.
--    The key is added automatically on the run AFTER they are resolved.
-- ------------------------------------------------------------
SELECT 'unresolved duplicate person links (merge these, then re-run)' AS note,
       COUNT(*) AS n
  FROM `acm_person_duplicate_report`
 WHERE `resolved_at` IS NULL;
