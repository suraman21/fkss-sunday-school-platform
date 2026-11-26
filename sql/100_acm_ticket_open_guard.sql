-- ============================================================
-- 100_acm_ticket_open_guard.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Close the duplicate-open-ticket race.
--
-- THE BUG THIS FIXES (found while designing 099):
--   AcmTicketService::create() does SELECT ... WHERE status IN
--   ('submitted','in_review','changes_requested') and then INSERTs.
--   There is no UNIQUE key on acm_activation_tickets and no lock,
--   so two office sessions submitting for the same person at the
--   same moment both pass the SELECT and both INSERT. The result is
--   two open tickets for one person: two codes, two ceremonies, and
--   an approval trail that cannot say which one is authoritative.
--
--   A pre-check cannot fix a race. The fix is a constraint the
--   database enforces on every writer, including a future caller
--   that forgets to pre-check.
--
-- THE CONSTRAINT
--   `open_guard` is a generated column that is NULL unless the row
--   is in an open state. A generated column cannot be written to,
--   so no caller can bypass it. The UNIQUE key then allows many
--   NULLs (MySQL permits duplicate NULLs in a UNIQUE index) but at
--   most ONE open row per (person_id, account_type).
--
--   When a ticket leaves the open states, open_guard becomes NULL
--   and the key frees up for the next request — exactly what the
--   pre-check was trying to express, but enforced.
--
-- HOW THIS FILE IS BUILT
--   Follows the 004_year_lifecycle.sql pattern: a temporary stored
--   procedure with information_schema guards, run once and dropped.
--   That avoids PREPARE of a multi-line ALTER (fragile quoting on
--   shared hosts) and makes the whole file re-runnable.
--
--   VIRTUAL, not STORED: same guarantee (004 uses VIRTUAL), and it
--   avoids rebuilding the table on every deploy. The CONTINUE
--   handler on the ALTER means a build that rejects a unique index
--   on a virtual column still finishes the migration; the service
--   keeps its pre-check, and 099's request layer already collapses
--   duplicates, so the system degrades rather than breaks.
--
-- SAFETY, per this repo's migration 018/031 rule:
--   Existing duplicates are REPORTED, never resolved automatically
--   (see the SELECT immediately below). Deleting or merging real
--   tickets is a human decision. The final SELECT reports which
--   branch was taken, so an operator can see whether the key was
--   actually created.
-- ============================================================

-- ------------------------------------------------------------
-- 0. REPORT existing duplicates BEFORE anything is altered.
--    Zero rows = clean. If rows appear here, resolve them by
--    hand (cancel or reject one), then re-run this file.
-- ------------------------------------------------------------
SELECT
    person_id,
    account_type,
    COUNT(*) AS open_tickets,
    GROUP_CONCAT(id ORDER BY id) AS ticket_ids
FROM `acm_activation_tickets`
WHERE `status` IN ('submitted', 'in_review', 'changes_requested')
GROUP BY `person_id`, `account_type`
HAVING COUNT(*) > 1;

DELIMITER $$

DROP PROCEDURE IF EXISTS `ssms_100_ticket_open_guard` $$

CREATE PROCEDURE `ssms_100_ticket_open_guard`()
BEGIN
    DECLARE v_col INT DEFAULT 0;
    DECLARE v_key INT DEFAULT 0;
    DECLARE v_dup INT DEFAULT 0;

    -- Column present?
    SELECT COUNT(*) INTO v_col
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'acm_activation_tickets'
       AND COLUMN_NAME  = 'open_guard';

    IF v_col = 0 THEN
        -- Best-effort: if this build rejects the expression, the
        -- handler leaves the column absent and the guard is simply
        -- not installed. v_col is re-read afterwards so the key
        -- step below does not try to index a column that is not
        -- there.
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            ALTER TABLE `acm_activation_tickets`
              ADD COLUMN `open_guard` VARCHAR(48)
                CHARACTER SET ascii COLLATE ascii_bin
                GENERATED ALWAYS AS (
                  CASE WHEN `status` IN ('submitted','in_review','changes_requested')
                       THEN CONCAT(`person_id`, ':', `account_type`)
                       ELSE NULL END
                ) VIRTUAL;
        END;

        SELECT COUNT(*) INTO v_col
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'acm_activation_tickets'
           AND COLUMN_NAME  = 'open_guard';
    END IF;

    -- Key already present?
    SELECT COUNT(*) INTO v_key
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'acm_activation_tickets'
       AND INDEX_NAME   = 'uniq_acm_ticket_open';

    -- Remaining duplicates? (Re-counted: an operator may have
    -- cleaned up between the SELECT above and this procedure.)
    SELECT COUNT(*) INTO v_dup FROM (
        SELECT 1
          FROM `acm_activation_tickets`
         WHERE `status` IN ('submitted', 'in_review', 'changes_requested')
         GROUP BY `person_id`, `account_type`
        HAVING COUNT(*) > 1
    ) AS d;

    IF v_col = 0 THEN
        SELECT 'open_guard column NOT created (build rejected the expression) — pre-check in AcmTicketService remains the only guard' AS result;
    ELSEIF v_dup > 0 THEN
        SELECT CONCAT('SKIPPED: ', v_dup, ' person(s) still have duplicate open tickets — resolve them, then re-run 100') AS result;
    ELSEIF v_key > 0 THEN
        SELECT 'already applied' AS result;
    ELSE
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            ALTER TABLE `acm_activation_tickets`
              ADD UNIQUE KEY `uniq_acm_ticket_open` (`open_guard`);
        END;

        SELECT COUNT(*) INTO v_key
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'acm_activation_tickets'
           AND INDEX_NAME   = 'uniq_acm_ticket_open';

        IF v_key > 0 THEN
            SELECT 'APPLIED: uniq_acm_ticket_open created — the duplicate-open-ticket race is now impossible' AS result;
        ELSE
            SELECT 'NOT APPLIED: this build rejected a UNIQUE index on the generated column — pre-check remains the only guard' AS result;
        END IF;
    END IF;
END $$

DELIMITER ;

CALL `ssms_100_ticket_open_guard`();
DROP PROCEDURE IF EXISTS `ssms_100_ticket_open_guard`;

-- ============================================================
-- VERIFY after running:
--   SHOW COLUMNS FROM acm_activation_tickets LIKE 'open_guard';
--   SHOW INDEX FROM acm_activation_tickets WHERE Key_name = 'uniq_acm_ticket_open';
--   -- and the duplicate query at the top of this file must return
--   -- zero rows.
-- ============================================================
