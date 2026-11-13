-- ============================================================
-- 082_telegram_registration_corrections.sql (IDEMPOTENT)
-- Phase 2 correction cycles and review history. Apply after 081.
-- ============================================================

DROP PROCEDURE IF EXISTS `ssms_082_upgrade`;
DELIMITER $$
CREATE PROCEDURE `ssms_082_upgrade`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='telegram_registration_sessions' AND column_name='correction_fields') THEN
    ALTER TABLE telegram_registration_sessions ADD COLUMN correction_fields TEXT DEFAULT NULL AFTER edit_return_to_review;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='telegram_registration_sessions' AND column_name='revision_number') THEN
    ALTER TABLE telegram_registration_sessions ADD COLUMN revision_number SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER correction_fields;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='telegram_registration_reviews' AND column_name='session_revision') THEN
    ALTER TABLE telegram_registration_reviews ADD COLUMN session_revision SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER session_id;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='telegram_registration_reviews' AND index_name='uniq_tg_session_decision') THEN
    ALTER TABLE telegram_registration_reviews DROP INDEX uniq_tg_session_decision;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='telegram_registration_reviews' AND index_name='idx_tg_review_session') THEN
    ALTER TABLE telegram_registration_reviews ADD INDEX idx_tg_review_session (session_id,created_at,id);
  END IF;
END$$
DELIMITER ;
CALL `ssms_082_upgrade`();
DROP PROCEDURE IF EXISTS `ssms_082_upgrade`;
