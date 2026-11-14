-- ============================================================
-- 089_acm_account_suspensions.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ACM S7: suspension/reactivation history for member/parent
-- accounts. One row per suspend/reactivate action: who, which
-- account, which direction, why (officer-written reason), when.
-- The LIVE state lives on acm_person_accounts.status
-- (active/suspended) + users.is_active; this table is the
-- append-only trail behind it (audit-grade: who did what to whom
-- and why). No seeds, no ENUM changes, no ticket vocabulary:
-- suspension is a direct console action, not a ceremony.
-- Shared-hosting safe: no FOREIGN KEYs (application-enforced,
-- same as 069/079/081/082/084/086), no JSON column type,
-- InnoDB + utf8mb4.
-- ============================================================

CREATE TABLE IF NOT EXISTS `acm_account_suspensions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `person_id` INT UNSIGNED NOT NULL,
  `account_type` ENUM('member','parent') NOT NULL,
  `action` ENUM('suspended','reactivated') NOT NULL,
  `reason` VARCHAR(2000) NOT NULL,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acm_susp_user` (`user_id`),
  KEY `idx_acm_susp_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
