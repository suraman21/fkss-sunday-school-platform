-- ============================================================
-- 086_acm_refresh_binding.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ACM S5: refresh-time device-binding enforcement. Bound refresh
-- families require a DPoP-style proof (signed with the S4-registered
-- Keystore key) at every rotation; unbound families rotate exactly
-- as before. Binding is recorded per family (acm_bound) and set only
-- for families issued after S5 with a valid login assertion --
-- pre-S5 linked families stay unbound (link is not binding, D3.8),
-- so no app is forcibly migrated and rollback degrades gracefully.
-- Shared-hosting safe: no FOREIGN KEYs (application-enforced,
-- same as 069/079/081/082/084), no JSON column type, InnoDB + utf8mb4.
-- Existing tables are untouched except one additive column (with a
-- NOT NULL DEFAULT 0 so pre-S5 rows read unbound) on
-- api_refresh_sessions. No ticket-event vocabulary change: proof
-- failures are unauthenticated 401 noise and are never audited
-- (same precedent as invalid-token 401s; only reuse audits).
-- S5 code deploys first and keeps working pre-migration: the bound
-- flag is probe-guarded (S4 deviceLinkAvailable precedent) and
-- pre-migration families read unbound, so refresh never bricks on
-- schema skew.
-- Numbering verified 2026-11-14: 080 telegram, 081 ACM office
-- (+081 telegram phase2 interview), 082 ACM ceremony (+082 telegram
-- corrections), 083 telegram approval, 084 ACM registry, 085 telegram
-- transfer registration, 086 ACM refresh binding. (081/082 pairs are
-- telegram-vs-ACM collisions owned by the telegram stream --
-- migration-hygiene stays red until those are resolved.)
-- ============================================================

-- Proof replay store: one row per consumed DPoP jti. The jti is
-- claimed atomically inside the rotation transaction (INSERT; a
-- duplicate-key 23000 means the proof was already used), so a proof
-- is consumed if and only if its rotation commits -- crashed or
-- failed rotations leave the proof reusable for client retry.
-- Rows self-expire (TTL seed below) and are swept by the sampled
-- bounded cleanup piggybacked on RefreshTokenService Titus.
CREATE TABLE IF NOT EXISTS `acm_refresh_proof_jtis` (
  `jti` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `device_id` INT UNSIGNED NOT NULL,
  `claimed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`jti`),
  KEY `idx_acm_proof_jti_expiry` (`expires_at`),
  KEY `idx_acm_proof_jti_device` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-family binding flag (design D3.8: recording a link is not
-- enforcing binding). DEFAULT 0: every pre-S5 row (linked or not)
-- reads unbound and rotates proof-free. No index: the flag is read
-- from the session row already loaded FOR UPDATE by rotation.
SET @ssms_086_bound_sql = (
    SELECT IF(
        NOT EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'api_refresh_sessions'
              AND column_name = 'acm_bound'
        ),
        'ALTER TABLE `api_refresh_sessions` ADD COLUMN `acm_bound` TINYINT(1) NOT NULL DEFAULT 0',
        'SELECT 1'
    )
);
PREPARE ssms_086_bound_stmt FROM @ssms_086_bound_sql;
EXECUTE ssms_086_bound_stmt;
DEALLOCATE PREPARE ssms_086_bound_stmt;

-- Proof freshness window (seconds): a DPoP iat older or newer than
-- this many seconds from server time is rejected. Wide enough for
-- real-world clock drift; the jti claim (not the window) is what
-- stops replays.
INSERT INTO `system_settings` (`setting_key`, `setting_value`)
SELECT 'acm.refresh_proof_skew_seconds', '300' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'acm.refresh_proof_skew_seconds');

-- Replay-store TTL (seconds): must exceed twice the skew window so a
-- jti stays claimed for the whole interval its proof is acceptable.
INSERT INTO `system_settings` (`setting_key`, `setting_value`)
SELECT 'acm.refresh_proof_jti_ttl_seconds', '900' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'acm.refresh_proof_jti_ttl_seconds');
