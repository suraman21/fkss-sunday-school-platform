-- ============================================================
-- 090_acm_deactivation_events.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ACM S8: account deactivation (baseline step 13). This migration
-- ONLY appends the `deactivated` history-action value; no new
-- tables, no new columns, no seeds. The suspensions-history table
-- (089) carries deactivation evidence too: terminal evidence is
-- append-only, never deleted (v2: no hard delete on audit-family
-- data). The ENUM append is LAST so existing indexes are
-- preserved (084/088 precedent); the guard below re-runs safely.
-- Shared-hosting safe: single guarded ALTER, no FKs, no JSON.
-- ============================================================

-- Suspension-history action vocabulary (append-only widen).
SET @ssms_090_ac_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'acm_account_suspensions'
              AND column_name = 'action'
              AND column_type NOT LIKE '%deactivated%'
        ),
        'ALTER TABLE `acm_account_suspensions` MODIFY COLUMN `action`'
        ' ENUM(''suspended'',''reactivated'',''deactivated'') NOT NULL',
        'SELECT 1'
    )
);

PREPARE ssms_090_ac_stmt FROM @ssms_090_ac_sql;
EXECUTE ssms_090_ac_stmt;
DEALLOCATE PREPARE ssms_090_ac_stmt;
