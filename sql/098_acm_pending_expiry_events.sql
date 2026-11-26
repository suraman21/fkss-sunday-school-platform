-- ============================================================
-- 098_acm_pending_expiry_events.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ACM cleanup: the pending-activation expiry job (2026-11-26)
-- needs a word for what it did. 089 created the suspension-history
-- vocabulary as ('suspended','reactivated') and 090 appended
-- 'deactivated'; this migration appends 'expired'.
--
-- Why a value is needed at all: the cleanup job does not delete an
-- abandoned account, it DEACTIVATES it — is_active=0 and link
-- ->revoked — which is exactly the terminal state 090 already
-- describes. Reusing 'deactivated' would make an automatic timeout
-- indistinguishable from a deliberate super_admin action, which is
-- the one distinction an audit trail exists to preserve.
--
-- Append-only widen: no new tables, no new columns, no seeds, no
-- hard delete on audit-family data. The ENUM append is LAST so
-- existing indexes are preserved (084/088/090 precedent); the guard
-- below re-runs safely. Shared-hosting safe: single guarded ALTER,
-- no FOREIGN KEYs, no JSON column type.
-- ============================================================

-- Suspension-history action vocabulary (append-only widen).
SET @ssms_098_ac_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'acm_account_suspensions'
              AND column_name = 'action'
              AND column_type NOT LIKE '%expired%'
        ),
        'ALTER TABLE `acm_account_suspensions` MODIFY COLUMN `action`'
        ' ENUM(''suspended'',''reactivated'',''deactivated'',''expired'') NOT NULL',
        'SELECT 1'
    )
);

PREPARE ssms_098_ac_stmt FROM @ssms_098_ac_sql;
EXECUTE ssms_098_ac_stmt;
DEALLOCATE PREPARE ssms_098_ac_stmt;
