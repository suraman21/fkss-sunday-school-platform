-- 085_telegram_transfer_registration.sql (IDEMPOTENT) — apply after 083.
DROP PROCEDURE IF EXISTS `ssms_085_upgrade`;
DELIMITER $$
CREATE PROCEDURE `ssms_085_upgrade`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='telegram_bot_users' AND column_name='can_transfer') THEN
    ALTER TABLE telegram_bot_users ADD COLUMN can_transfer TINYINT(1) NOT NULL DEFAULT 0 AFTER can_direct;
  END IF;
  ALTER TABLE telegram_form_profiles MODIFY profile_key ENUM('waiting','direct','transfer') NOT NULL;
  ALTER TABLE telegram_registration_sessions MODIFY profile_key ENUM('waiting','direct','transfer') DEFAULT NULL;
END$$
DELIMITER ;
CALL `ssms_085_upgrade`();
DROP PROCEDURE IF EXISTS `ssms_085_upgrade`;

INSERT INTO telegram_form_profiles (profile_key,version,status,title_am,title_en,published_at)
SELECT 'transfer',1,'published','የዝውውር ምዝገባ','Transfer registration',CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM telegram_form_profiles WHERE profile_key='transfer');

INSERT IGNORE INTO telegram_form_fields
(profile_id,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en)
SELECT target.id,source.field_key,source.is_enabled,source.is_required,source.sort_order,
       source.prompt_am,source.prompt_en,source.help_am,source.help_en
FROM telegram_form_profiles target
JOIN telegram_form_profiles direct ON direct.profile_key='direct' AND direct.status='published'
JOIN telegram_form_fields source ON source.profile_id=direct.id
WHERE target.profile_key='transfer' AND target.version=1;
