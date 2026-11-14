-- 091_telegram_v2_state_machine.sql (IDEMPOTENT)
-- Expands the Telegram registration bot to the v2 UX:
--   * richer session state machine (duplicate review / identity verification /
--     final validation / completed)
--   * message tracking for in-place edits and inline-keyboard navigation
--   * mother name collection on members (nullable; back-compatible)
--   * fixes the telegram_registration_reviews unique key so multiple review
--     decisions per session are allowed (corrections/reviews cycle)
--   * per-school configurable bot message strings (Amharic-first; editable)
--   * candidate-verification decisions and escalations
-- Apply after 090.

-- ---------------------------------------------------------------------------
-- 1. Add mother_name to members (if missing)
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `ssms_091_add_column`;
DELIMITER $$
CREATE PROCEDURE `ssms_091_add_column`(IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema=DATABASE() AND table_name='members')
     AND NOT EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='members' AND column_name=p_column) THEN
    SET @s = CONCAT('ALTER TABLE `members` ADD COLUMN `', REPLACE(p_column,'`','``'), '` ', p_definition);
    PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
  END IF;
END$$
DELIMITER ;
CALL ssms_091_add_column('mother_name',
  'VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `father_name`');
CALL ssms_091_add_column('mother_name_norm',
  'VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL AFTER `mother_name`');
DROP PROCEDURE IF EXISTS `ssms_091_add_column`;

-- ---------------------------------------------------------------------------
-- 2. Expand telegram_registration_sessions status enum + add tracking columns
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `ssms_091_modify_enum`;
DELIMITER $$
CREATE PROCEDURE `ssms_091_modify_enum`()
BEGIN
  DECLARE cur_enum TEXT;
  SELECT COLUMN_TYPE INTO cur_enum FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='telegram_registration_sessions'
      AND column_name='status';
  IF cur_enum IS NOT NULL AND cur_enum NOT LIKE '%duplicate_review%' THEN
    ALTER TABLE telegram_registration_sessions
      MODIFY COLUMN `status` ENUM(
        'choosing','collecting','review','submitted','approved','cancelled',
        'expired','failed','duplicate_search','duplicate_review',
        'identity_verification','final_validation','completed','escalated'
      ) NOT NULL DEFAULT 'choosing';
  END IF;
END$$
DELIMITER ;
CALL ssms_091_modify_enum();
DROP PROCEDURE IF EXISTS `ssms_091_modify_enum`;

DROP PROCEDURE IF EXISTS `ssms_091_add_session_column`;
DELIMITER $$
CREATE PROCEDURE `ssms_091_add_session_column`(IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema=DATABASE() AND table_name='telegram_registration_sessions')
     AND NOT EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='telegram_registration_sessions' AND column_name=p_column) THEN
    SET @s = CONCAT('ALTER TABLE `telegram_registration_sessions` ADD COLUMN `', REPLACE(p_column,'`','``'), '` ', p_definition);
    PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
  END IF;
END$$
DELIMITER ;
CALL ssms_091_add_session_column('last_bot_message_id', 'INT UNSIGNED DEFAULT NULL AFTER current_field_key');
CALL ssms_091_add_session_column('current_candidate_index', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER duplicate_summary');
CALL ssms_091_add_session_column('selected_candidate_id', 'INT UNSIGNED DEFAULT NULL AFTER current_candidate_index');
CALL ssms_091_add_session_column('identity_verification_decision', "ENUM('undecided','same_person','different_person','uncertain','escalated') DEFAULT 'undecided' AFTER selected_candidate_id");
CALL ssms_091_add_session_column('viewing_menu', "ENUM('home','name_input','candidates','verify','edit_menu','field_edit','final_review','completed','search_results','my_registrations') DEFAULT 'home' AFTER identity_verification_decision");
CALL ssms_091_add_session_column('pending_corrections_reason', 'VARCHAR(1000) DEFAULT NULL AFTER correction_fields');
CALL ssms_091_add_session_column('final_validation_blocker', 'VARCHAR(1000) DEFAULT NULL');
CALL ssms_091_add_session_column('escalated_at', 'DATETIME DEFAULT NULL');
CALL ssms_091_add_session_column('escalation_note', 'VARCHAR(1000) DEFAULT NULL');
DROP PROCEDURE IF EXISTS `ssms_091_add_session_column`;

-- ---------------------------------------------------------------------------
-- 3. Add candidate-verification audit table
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `telegram_candidate_decisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `candidate_member_id` INT UNSIGNED NOT NULL,
  `decision` ENUM('same','different','uncertain','viewed') NOT NULL,
  `decided_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tg_cand_session` (`session_id`,`candidate_member_id`),
  KEY `idx_tg_cand_member` (`candidate_member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Fix telegram_registration_reviews: allow multiple reviews per session
--    (the unique key on session_id prevented correction/resubmission cycles).
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `ssms_091_fix_review_key`;
DELIMITER $$
CREATE PROCEDURE `ssms_091_fix_review_key`()
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.statistics
               WHERE table_schema=DATABASE() AND table_name='telegram_registration_reviews'
                 AND index_name='uniq_tg_session_decision') THEN
    ALTER TABLE telegram_registration_reviews DROP INDEX uniq_tg_session_decision;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
               WHERE table_schema=DATABASE() AND table_name='telegram_registration_reviews'
                 AND index_name='idx_tg_session_reviews') THEN
    ALTER TABLE telegram_registration_reviews ADD INDEX idx_tg_session_reviews (session_id,id);
  END IF;
END$$
DELIMITER ;
CALL ssms_091_fix_review_key();
DROP PROCEDURE IF EXISTS `ssms_091_fix_review_key`;

-- ---------------------------------------------------------------------------
-- 5. Expand profile_key enum to accept 'transfer' safely on form_profiles
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `ssms_091_expand_profile_enum`;
DELIMITER $$
CREATE PROCEDURE `ssms_091_expand_profile_enum`()
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='telegram_form_profiles'
                 AND column_name='profile_key') THEN
    ALTER TABLE telegram_form_profiles
      MODIFY COLUMN `profile_key` ENUM('waiting','direct','transfer') NOT NULL;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema=DATABASE() AND table_name='telegram_registration_sessions'
                 AND column_name='profile_key') THEN
    ALTER TABLE telegram_registration_sessions
      MODIFY COLUMN `profile_key` ENUM('waiting','direct','transfer') DEFAULT NULL;
  END IF;
END$$
DELIMITER ;
CALL ssms_091_expand_profile_enum();
DROP PROCEDURE IF EXISTS `ssms_091_expand_profile_enum`;

-- ---------------------------------------------------------------------------
-- 6. Seed father_name and mother_name into every published form profile if missing.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO telegram_form_fields (profile_id, field_key, is_enabled, is_required, sort_order, prompt_am, prompt_en, help_am, help_en)
SELECT p.id, 'father_name', 1, 1, 35,
  'የአባሉን የአባት ስም ያስገቡ።',
  'Enter the father\'s given name.',
  'ምሳሌ፦ ገብረ', 'Example: Kebede'
FROM telegram_form_profiles p
WHERE p.status='published';

INSERT IGNORE INTO telegram_form_fields (profile_id, field_key, is_enabled, is_required, sort_order, prompt_am, prompt_en, help_am, help_en)
SELECT p.id, 'mother_name', 1, 0, 36,
  'የአባሉን የእናት ስም ያስገቡ። ካልታወቀ /skip ይጫኑ።',
  'Enter the mother\'s name, or /skip if unknown.',
  'አጭር ጽሑፍ።', 'Optional.'
FROM telegram_form_profiles p
WHERE p.status='published';

-- ---------------------------------------------------------------------------
-- 7. Configurable bot message strings (per-school brandable copy)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `telegram_bot_messages` (
  `message_key` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `language` CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'am',
  `message_text` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_key`,`language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 8. Seed default Amharic message strings into telegram_bot_messages.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("welcome","am","ሰላም! እንኳን ወደ ውሉደ ብርሃን የአባላት ምዝገባ ስርዓት በደህና መጡ።\n\nእዚህ አዲስ አባል መመዝገብ፣ የቀድሞ አባል መረጃ መፈለግ እና የምዝገባ ሂደትን ማስተዳደር ይችላሉ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("access_denied","am","ይህ መለያ ገና አልተፈቀደም።\n\nTelegram ID: {telegram_id}\nPairing request: {pairing_id}\nAsk an administrator to link it to your active system account.");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("cancelled","am","ምዝገባው ተሰርዟል።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("daily_limit_reached","am","የዕለቱ የምዝገባ ገደብ ደርሷል። አስተዳዳሪ ያነጋግሩ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("resume_no_draft","am","ያልተጠናቀቀ ምዝገባ የለም። አዲስ ለመጀመር «አዲስ አባል መመዝገብ»ን ይጫኑ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_full_name","am","የአባሉን ሙሉ ስም ያስገቡ።\n\nእባክዎ የመጀመሪያ ስም፣ የአባት ስም እና የአያት ስም በትክክል ይጻፉ።\n\nምሳሌ፦ ሚካኤል ገብረ ማርያም ተስፋዬ");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("name_too_short","am","እባክዎ ቢያንስ የራስ እና የአባት ስም ያስገቡ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("name_script_error","am","ስሙ በአማርኛ ፊደል ብቻ ይጻፉ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("searching_duplicates","am","🔍 በመፈለግ ላይ... ተመሳሳይ አባላትን ካሉ እያጣራሁ ነው።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("duplicate_header","am","⚠️ ተመሳሳይ ሊሆኑ የሚችሉ አባላት ተገኝተዋል\n\nከዚህ በፊት በስርዓቱ ውስጥ የተመዘገቡ ከዚህ ስም ጋር ተመሳሳይነት ያላቸው አባላት ተገኝተዋል።\n\nእባክዎ ከሚመዘገበው ሰው ጋር በማረጋገጥ መረጃዎቹን ያወዳድሩ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("no_duplicates","am","✅ በአሁኑ ፍለጋ ውስጥ ተመሳሳይ አባል አልተገኘም።\n\nይህ ሰው ከዚህ በፊት አለመመዝገቡን ዋስትና አይሰጥም። ተጨማሪ መረጃ ሲገባ ተጨማሪ ምርመራ ይካሄዳል።\n\nወደ ቀሪው የምዝገባ መረጃ ይቀጥሉ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("candidate_index","am","እጩ {index} ከ{total}");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("match_high","am","ከፍተኛ ተመሳሳይነት");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("match_medium","am","መካከለኛ ተመሳሳይነት");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("match_low","am","ዝቅተኛ ተመሳሳይነት");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("verify_prompt","am","የአባሉን መረጃ ከሚመዘገበው ሰው ጋር በማወዳደር ያረጋግጡ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("same_person_confirmed","am","✅ ተመሳሳይ አባል መሆኑ ተረጋግጧል።\n\nይህ ሰው ቀደም ሲል በስርዓቱ ውስጥ ተመዝግቧል።\nየአባል መለያ፦ {member_code}\n\nአዲስ መዝገብ አልተፈጠረም።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("different_person_confirmed","am","✅ የተለያዩ ሰዎች መሆናቸው ተረጋግጧል።\n\nአዲሱን አባል መመዝገብ ይችላሉ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("uncertain_prompt","am","❓ ማረጋገጫው ገና አልተጠናቀቀም።\n\nየአባሉን ማንነት በበቂ ሁኔታ ማረጋገጥ ካልቻሉ እባክዎ ምዝገባውን አያጠናቅቁ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("escalated","am","📨 ምዝገባው ለኃላፊ ተልኳል።\n\nማጣቀሻ፦ {public_id}\nኃላፊው ሲያጣራ ይመለሳል።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("paused","am","⏸️ ምዝገባው ለጊዜው ቆይቷል። /resume በመጫን ይቀጥሉ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_baptismal_name","am","የአባሉን የክርስትና ስም ያስገቡ። ከሌለ «ዝለል»ን ይጫኑ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_gender","am","ፆታ ይምረጡ፦");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_dob_ec","am","የትውልድ ቀን በኢትዮጵያ ዘመን አቆጣጠር ያስገቡ። (ቀን/ወር/ዓመት)\n\nምሳሌ፦ 12/04/2002");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_father_name","am","የአባሉን የአባት ስም ያስገቡ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_mother_name","am","የአባሉን የእናት ስም ያስገቡ። ካልታወቀ «ዝለል»ን ይጫኑ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_phone","am","የስልክ ቁጥር ያስገቡ። (09... or +2519...) ከሌለ «ዝለል»ን ይጫኑ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_guardian_name","am","የአሳዳጊ ስም ያስገቡ። (ለሕፃናት) ከሌለ «ዝለል»ን ይጫኑ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_guardian_phone","am","የአሳዳጊ ስልክ ቁጥር ያስገቡ። ከሌለ «ዝለል»ን ይጫኑ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_city","am","ከተማ ያስገቡ። «ዝለል»ን መጫንም ይችላሉ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_sub_city","am","ክፍለ ከተማ ያስገቡ። «ዝለል»ን መጫንም ይችላሉ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_woreda","am","ወረዳ ያስገቡ። «ዝለል»ን መጫንም ይችላሉ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_current_section","am","ክፍል / ሴክሽን ይምረጡ፦");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_education_level","am","የትምህርት ደረጃ ይምረጡ፦");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_spiritual_education","am","መንፈሳዊ ትምህርት ደረጃ ይምረጡ፦");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prompt_student_photo","am","የአባሉን ፎቶ ይላኩ (ፎቶ ወይም PDF)። «ዝለል»ን መጫንም ይችላሉ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("invalid_input","am","⚠️ {error}\n\n{prompt}");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("field_saved","am","✅ {label} ተቀምጧል።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("edit_menu_title","am","የትኛውን መረጃ ማስተካከል ይፈልጋሉ?");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("edit_prompt","am","አሁን ያለው {label}፦\n{current}\n\nአዲሱን ያስገቡ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("edit_done","am","✅ የ{label} ማስተካከያው ተሳክቷል።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("review_header","am","📋 የአባል ምዝገባ ማጠቃለያ\n\nእባክዎ የተሞሉትን መረጃዎች በጥንቃቄ ይመርምሩ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("review_duplicate_warn","am","🚨 ያልተፈታ ከፍተኛ ተመሳሳይነት ያለው እጩ አለ። ከማስረከብዎ በፊት መፍታት አለብዎት።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("submit_success","am","✅ የምዝገባ መረጃው በተሳካ ሁኔታ ተልኳል።\n\nየአባሉ ምዝገባ ለHR ማጽደቅ ቀርቧል።\n- የምዝገባ መለያ፦ {public_id}\n- ሁኔታ፦ በማጽደቅ ላይ\n- ቀን፦ {date}");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("approval_notice","am","✅ HR ምዝገባውን አጽድቋል።\nመዝገብ፦ {public_id}\nአባል ቁጥር፦ {member_code}\nአባሉ በተሳካ ሁኔታ ተፈጥሯል።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("review_decision_notice","am","HR ግምገማ፦ {decision}\nመዝገብ፦ {public_id}\nማስታወሻ፦ {note}");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("correction_request_notice","am","HR ማስተካከያ ጠይቋል።\nመዝገብ፦ {public_id}\nማስተካከል ያለባቸው፦ {fields}\n\n/resume በመጫን ያስተካክሉ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("search_prompt","am","🔎 ለመፈለግ የአባሉን ስም ወይም መለያ ቁጥር ያስገቡ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("search_no_results","am","ምንም አልተገኘም።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("my_registrations_empty","am","እስካሁን ያስመዘገቧቸው አባላት የሉም።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("not_authorized_pm","am","This bot works only in an authorized private chat.");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("processing_retry","am","የቀድሞው መልስ እየተሰራ ነው። ትንሽ ቆይተው እንደገና ይሞክሩ።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("final_validation_blocked","am","🚨 {blocker}\n\nከማስረከብዎ በፊት ይህንን መፍታት አለብዎት።");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("next_candidate","am","ቀጣይ እጩ");
INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES ("prev_candidate","am","ያለፈው እጩ");
