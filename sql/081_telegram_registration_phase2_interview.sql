-- ============================================================
-- 081_telegram_registration_phase2_interview.sql (IDEMPOTENT)
-- Phase 2 interview state: return a one-field correction to review.
-- Apply after 080 before deploying the Phase 2 interview service.
-- ============================================================

DROP PROCEDURE IF EXISTS `ssms_081_add_column`;
DELIMITER $$
CREATE PROCEDURE `ssms_081_add_column`()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name='telegram_registration_sessions'
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema=DATABASE()
      AND table_name='telegram_registration_sessions'
      AND column_name='edit_return_to_review'
  ) THEN
    ALTER TABLE `telegram_registration_sessions`
      ADD COLUMN `edit_return_to_review` TINYINT(1) NOT NULL DEFAULT 0
      AFTER `current_field_key`;
  END IF;
END$$
DELIMITER ;
CALL `ssms_081_add_column`();
DROP PROCEDURE IF EXISTS `ssms_081_add_column`;
