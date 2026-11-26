-- ============================================================
-- 101_acm_request_bindings.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Two things the self-service flow needs that 099/100 do not cover.
--
-- (A) AUTHENTICATOR BINDING — username, or the member code as a
--     login identifier.
--
--     Decision D3: the member may optionally choose a username at
--     activation. If they do not, their member code (the "ID
--     number") is the login identifier. Both must work, both
--     resolve to the same users row, and the password is the only
--     secret.
--
--     The danger of a second identifier namespace is COLLISION:
--     a member code like "M-0001" normalizing to "m0001" could
--     equal a username another user already holds. A collision is
--     an account-takeover vector, not a cosmetic problem, because
--     login resolves whichever row matches first.
--
--     So the binding is stored, not derived:
--       acm_authenticators(identifier_hash UNIQUE, user_id, kind)
--     Every identifier that may be used to log in lives in this
--     one table with a single UNIQUE key, which makes "two users
--     answer to the same identifier" impossible by construction —
--     including across the username/member-code boundary, which a
--     UNIQUE key on users.username alone could never do.
--
--     The raw identifier is NOT stored here beyond what users
--     already holds; `identifier_hash` is SHA-256 of the
--     normalized identifier so a table dump does not hand over a
--     login directory.
--
--     NOTE: users.username keeps its own UNIQUE key. This table is
--     additive — it is the collision authority for the second
--     namespace, and the login resolver consults it after the
--     username lookup misses.
--
-- (B) PARENT PARITY — decision D5 says parents follow the same
--     flow. acm_activation_requests already carries account_type,
--     so no schema change is needed there. What is needed is a
--     stable place to record which guardian a request was matched
--     to, because a parent account's identity proof is a guardian
--     record, not a member record. `guardian_id` is added to the
--     request table for that purpose.
--
-- Both blocks are guarded and re-runnable. No FOREIGN KEY (this
-- repo's rule for shared hosts), no JSON column type.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS `ssms_101_request_bindings` $$

CREATE PROCEDURE `ssms_101_request_bindings`()
BEGIN
    -- ── (A) authenticator bindings ──────────────────────────────
    CREATE TABLE IF NOT EXISTS `acm_authenticators` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` INT UNSIGNED NOT NULL,
      -- SHA-256 hex of the normalized identifier. Never the raw
      -- value: this table must be useless to an attacker who can
      -- read it but not log in.
      `identifier_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      -- What kind of identifier this is. 'member_code' and
      -- 'guardian_ref' are the ID-number namespaces; 'username'
      -- mirrors users.username so one lookup path serves both.
      `kind` ENUM('username','member_code','guardian_ref') NOT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      -- THE collision authority: one identifier, one user, across
      -- every kind.
      UNIQUE KEY `uniq_acm_authenticator` (`identifier_hash`),
      KEY `idx_acm_authenticator_user` (`user_id`,`kind`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- ── (B) guardian binding on the request ─────────────────────
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'acm_activation_requests'
               AND COLUMN_NAME  = 'guardian_id'
        ) THEN
            ALTER TABLE `acm_activation_requests`
              ADD COLUMN `guardian_id` INT UNSIGNED DEFAULT NULL
                AFTER `person_id`,
              ADD KEY `idx_acm_request_guardian` (`guardian_id`);
        END IF;
    END;
END $$

DELIMITER ;

CALL `ssms_101_request_bindings`();
DROP PROCEDURE IF EXISTS `ssms_101_request_bindings`;

-- ============================================================
-- VERIFY after running:
--   SHOW TABLES LIKE 'acm_authenticators';
--   SHOW INDEX FROM acm_authenticators WHERE Key_name = 'uniq_acm_authenticator';
--   SHOW COLUMNS FROM acm_activation_requests LIKE 'guardian_id';
-- ============================================================
