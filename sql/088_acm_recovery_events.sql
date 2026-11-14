-- ============================================================
-- 088_acm_recovery_events.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ACM S6: credential recovery (forgot-password) ceremony. This
-- migration ONLY appends the `recovered` ticket-event value; no new
-- tables, no new columns, no seeds. Recovery rides the S3 code +
-- grant + ticket machinery: the `reset` purpose, its 24h TTL and 10
-- attempts are already seeded as PHP consts (ActivationService
-- CODE_TTL_MIN / CODE_MAX_ATTEMPTS), and grants already mint with
-- operation = purpose. No status-ENUM change: a recovered ticket
-- returns to `active` (it was never deactivated, only its password
-- was unusable). The ENUM append is LAST so existing indexes are
-- preserved (084 precedent); the guard below re-runs safely.
-- Shared-hosting safe: single guarded ALTER, no FKs, no JSON.
-- ============================================================

-- Ticket event vocabulary (append-only widen).
SET @ssms_088_ev_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'acm_ticket_events'
              AND column_name = 'event'
              AND column_type NOT LIKE '%recovered%'
        ),
        'ALTER TABLE `acm_ticket_events` MODIFY COLUMN `event` ENUM(''created'',''submitted'',''claimed'',''released'',''note'',''changes_requested'',''approved'',''rejected'',''provisioned'',''archived'',''code_issued'',''code_reissued'',''code_burned'',''grant_verified'',''credentials_set'',''activated'',''canceled'',''expired'',''revoked'',''activation_failed'',''device_registered'',''device_revoked'',''recovered'') NOT NULL',
        'SELECT 1'
    )
);
PREPARE ssms_088_ev_stmt FROM @ssms_088_ev_sql;
EXECUTE ssms_088_ev_stmt;
DEALLOCATE PREPARE ssms_088_ev_stmt;
