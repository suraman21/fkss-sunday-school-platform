"""Source boundary guards; production LocalDb/SQLite tests prove behavior."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / 'Mobile/wbws_flutter_app/lib/services/local_db.dart'


def test_attendance_coalesces_only_pristine_rows_without_attempt_evidence():
    source = DB.read_text()
    body = source.split('Future<LegacyOperationRef> _replaceLegacyOperation({', 1)[1].split('// PENDING ATTENDANCE', 1)[0]
    assert 'kind == LegacyOperationKind.attendance' in body
    for required in ["sync_state = 'pending' AND attempt_count = 0", 'last_attempt_at IS NULL',
                     'NOT EXISTS (SELECT 1 FROM sync_attempts',
                     'NOT EXISTS (SELECT 1 FROM pending_attendance sibling',
                     'sibling.attempt_count IS NOT 0',
                     'attempts.client_op_id = pending_attendance.client_op_id']:
        assert required in body
    assert "created_authorization_version = ?$replaceable" in body
    assert body.index('activeSessionMatches(') < body.index('await txn.delete(')


def test_attendance_claims_do_not_overtake_older_same_owner_sheet():
    source = DB.read_text()
    body = source.split('Future<LegacyClaimSnapshot?> claimNextLegacyOperation(', 1)[1].split('Future<LegacySettlementResult> settleLegacyOperation(', 1)[0]
    assert 'kind == LegacyOperationKind.attendance' in body
    eligibility = source.split('static const _attendanceClaimEligibilityWhere =', 1)[1].split('Future<LegacyClaimSnapshot?> claimNextLegacyOperation(', 1)[0]
    assert '_attendanceClaimEligibilityWhere' in body
    for required in ['older.class_id = pending_attendance.class_id', 'older.date = pending_attendance.date',
                     'older.owner_user_id = pending_attendance.owner_user_id',
                     'older.id < pending_attendance.id', "older.synced = 0",
                     "COALESCE(older.sync_state, '') <> 'resolved_conflict'"]:
        assert required in eligibility
    assert 'older.created_authorization_version =' not in eligibility


def test_latest_whole_generation_projection_includes_acknowledged_history():
    source = DB.read_text()
    predicate = source.split('static const _latestAttendanceGenerationWhere =', 1)[1].split('Future<List<Map<String, dynamic>>> getPendingAttendance()', 1)[0]
    assert 'ORDER BY newest.id DESC LIMIT 1' in predicate
    assert 'newest.synced' not in predicate
    for name, following in [('getPendingAttendance()', 'getPendingAttendanceRecords('),
                            ('getPendingAttendanceRecords(', 'findCachedMemberByCode(')]:
        body = source.split(name, 1)[1].split(following, 1)[0]
        assert '$_latestAttendanceGenerationWhere' in body


def test_attendance_undo_checks_attempt_metadata_ledger_and_atomic_result():
    source = DB.read_text()
    body = source.split('Future<SubmitUndoResult> undoSubmittedLegacyOperation(', 1)[1].split('Future<LegacyOperationRef> _replaceLegacyOperation({', 1)[0]
    assert "latestOnly = attendance ? ' AND $_latestAttendanceGenerationWhere'" in body
    assert "row['attempt_count'] != 0" in body
    assert "row['last_attempt_at'] != null" in body
    assert "txn.query('sync_attempts'" in body
    assert "if (attendance) throw StateError('Attendance undo was not atomic.')" in body


def test_scheduler_retry_and_cleanup_respect_attendance_generation_boundaries():
    source = DB.read_text()
    for start, end in [('Future<bool> hasDueLegacyOutbox(', 'Future<OutboxInventory> getOutboxInventory('),
                       ('Future<DateTime?> nextOutboxAttemptAt(', 'Future<int> getTotalPendingCount('),
                       ('Future<OutboxInventory> getOutboxInventory(', 'Future<void> cleanupSynced()')]:
        body = source.split(start, 1)[1].split(end, 1)[0]
        assert '_attendanceClaimEligibilityWhere' in body
        assert "spec.table == 'pending_attendance'" in body
    retry = source.split('Future<SyncRecoveryActionResult> retryRejectedOperation(', 1)[1].split('Future<SyncRecoveryActionResult> discardRejectedOperation(', 1)[0]
    assert "if (kind != 'attendance') 'attempt_count': 0" in retry
    assert "if (kind != 'attendance') 'last_attempt_at': null" in retry
    cleanup = source.split('Future<void> cleanupSynced()', 1)[1].split('Future<void> logSync(', 1)[0]
    for required in ['newer.synced = 1', 'older.synced = 0',
                     'newer.created_authorization_version = pending_attendance.created_authorization_version',
                     'older.created_authorization_version = pending_attendance.created_authorization_version',
                     'newer.client_op_id IS NOT pending_attendance.client_op_id']:
        assert required in cleanup
