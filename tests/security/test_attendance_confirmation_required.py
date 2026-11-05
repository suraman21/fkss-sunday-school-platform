"""Policy boundary guards supplement production LocalDb/SQLite regressions."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
LIB = ROOT / 'Mobile/wbws_flutter_app/lib'


def test_manual_attendance_removal_fails_closed_before_storage_access():
    source = (LIB / 'services/local_db.dart').read_text()
    body = source.split('Future<SyncRecoveryActionResult> discardRejectedOperation(', 1)[1].split('Future<List<Map<String, dynamic>>> getRejectedBatches()', 1)[0]
    guard = body.index("if (kind == 'attendance')")
    denial = body.index('return SyncRecoveryActionResult.serverConfirmationRequired;')
    assert guard < denial < body.index('const terminal') < body.index('await database') < body.index('txn.delete(')
    signature = body.split(') async {', 1)[0]
    assert 'force' not in signature and 'serverConfirmed' not in signature
    drop = source.split('Future<void> dropPendingAttendance(', 1)[1].split('Future<void> dropPendingGrades(', 1)[0]
    assert 'throw StateError(' in drop
    assert 'await database' not in drop and '.delete(' not in drop


def test_attendance_actions_require_confirmation_without_changing_retry():
    source = (LIB / 'services/sync_recovery_models.dart').read_text()
    normalized = re.sub(r'\s+', ' ', source)
    assert 'requiresServerConfirmation => domain == SyncRecoveryDomain.attendance' in normalized
    assert 'canAcknowledge => !requiresServerConfirmation && isConflict' in normalized
    assert 'canDiscard => !requiresServerConfirmation && (needsAttention || isConflict)' in normalized
    assert "canRetry => state == 'needs_attention' || state == 'failed'" in normalized


def test_recovery_ui_explains_policy_and_stale_actions_reach_guarded_storage():
    source = (LIB / 'screens/profile/sync_center_screen.dart').read_text()
    normalized = re.sub(r'\s+', ' ', source)
    assert 'Attendance stays saved until its exact outcome is confirmed by the server.' in source
    assert 'SyncRecoveryActionResult.serverConfirmationRequired => _attendanceConfirmationMessage' in normalized
    assert 'item.requiresServerConfirmation && (item.needsAttention || item.isConflict)' in normalized
    acknowledge = source.split('_acknowledge(SyncRecoveryItem item)', 1)[1].split('_confirmDiscard(SyncRecoveryItem item)', 1)[0]
    discard = source.split('_confirmDiscard(SyncRecoveryItem item)', 1)[1].split('Future<SyncRecoveryActionResult> _discardExact(', 1)[0]
    assert '_discardExact(item)' in acknowledge and '_discardExact(item)' in discard
    storage = source.split('Future<SyncRecoveryActionResult> _discardExact(', 1)[1].split('void _showActionResult(', 1)[0]
    assert '_db.discardRejectedOperation(' in storage and 'expectedState: item.state' in storage
    assert 'if (item.canAcknowledge)' in source and 'if (item.canDiscard && !item.canAcknowledge)' in source
