-- 083_telegram_registration_approval.sql (IDEMPOTENT) — apply after 082.
DROP PROCEDURE IF EXISTS `ssms_083_upgrade`;
DELIMITER $$
CREATE PROCEDURE `ssms_083_upgrade`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='telegram_registration_sessions' AND column_name='created_member_id') THEN
    ALTER TABLE telegram_registration_sessions ADD COLUMN created_member_id INT UNSIGNED DEFAULT NULL AFTER revision_number;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='telegram_registration_sessions' AND index_name='uniq_tg_created_member') THEN
    ALTER TABLE telegram_registration_sessions ADD UNIQUE INDEX uniq_tg_created_member (created_member_id);
  END IF;
  ALTER TABLE telegram_registration_sessions MODIFY status ENUM('choosing','collecting','review','submitted','approved','cancelled','expired','failed') NOT NULL DEFAULT 'choosing';
END$$
DELIMITER ;
CALL `ssms_083_upgrade`();
DROP PROCEDURE IF EXISTS `ssms_083_upgrade`;
