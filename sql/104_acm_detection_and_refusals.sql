-- 104_acm_detection_and_refusals.sql
-- ---------------------------------------------------------------
-- S12: automatic identity detection before a claim is accepted.
--
-- WHY A NEW TABLE
--   A refused claim must create no request row (that was the defect: the
--   queue filled with claims that could never be matched). But the office
--   still needs to see that people are TRYING and failing, because a run of
--   refusals usually means the member app is misreading the ID format or the
--   register has wrong names in it. This table is that signal.
--
-- WHAT IS DELIBERATELY NOT STORED
--   The typed FIRST NAME and the typed phone. A refusal log holding names
--   typed by someone who guessed an ID is itself a privacy problem, and the
--   office does not need it: `refusal_kind` plus the member code is enough to
--   answer "why did this person fail".
--
-- Shared-hosting safe: no FOREIGN KEYs, no triggers, additive only.
-- Idempotent: safe to re-run.

CREATE TABLE IF NOT EXISTS `acm_request_refusals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- The code as the claimant gave it, normalised. NOT a foreign key: the
  -- whole point is that it may name no member at all.
  `member_code` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_type` ENUM('member','parent') NOT NULL DEFAULT 'member',
  -- no_member | name_mismatch | ambiguous | not_active | no_account
  `refusal_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- exact | homophone | close | different | ambiguous | none
  `match_kind` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
  `match_score` DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  `origin_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acm_refusal_code` (`member_code`, `created_at`),
  KEY `idx_acm_refusal_kind` (`refusal_kind`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
