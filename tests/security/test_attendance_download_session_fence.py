"""Source boundary guards supplement production LocalDb and coordinator tests."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LIB = ROOT / 'Mobile/wbws_flutter_app/lib/services'


def test_attendance_download_mutators_require_transaction_session_fence():
    source = (LIB / 'local_db.dart').read_text()
    for name, following in [
        ('applyAttendanceDelta', 'resetAttendanceSyncForBootstrap'),
        ('resetAttendanceSyncForBootstrap', 'completeAttendanceBootstrap'),
        ('completeAttendanceBootstrap', 'cacheAttendanceResponse'),
    ]:
        body = source.split(f'Future<void> {name}(', 1)[1].split(f'Future<void> {following}(', 1)[0]
        assert 'required LegacyWriteSession expectedSession' in body
        assert 'await db.transaction((txn) async {' in body
        assert 'await _requireAttendanceSyncSession(txn, expectedSession)' in body
        mutations = [body.index(s) for s in ['await txn.delete(', 'await txn.update(', 'await txn.insert('] if s in body]
        assert mutations and body.index('await _requireAttendanceSyncSession') < min(mutations)


def test_scoped_status_guard_cannot_be_swallowed_by_legacy_best_effort_path():
    source = (LIB / 'local_db.dart').read_text()
    body = source.split('Future<void> setSyncStatus(', 1)[1].split('Future<void> _requireAttendanceSyncSession(', 1)[0]
    assert 'LegacyWriteSession? expectedSession' in body
    assert body.index('_requireAttendanceSyncSession(txn, expectedSession)') < body.index('try {')
    assert 'txn.query(\'sync_state\'' in body
    guard = source.split('Future<void> _requireAttendanceSyncSession(', 1)[1].split('/// Apply one page', 1)[0]
    for text in ['!expectedSession.isValid', 'activeSessionMatches(', 'executor: executor', 'StaleAttendanceSyncSession']:
        assert text in guard


def test_coordinator_forwards_original_capture_and_scopes_all_status_writes():
    source = (LIB / 'attendance_sync_coordinator.dart').read_text()
    assert source.count('final session = LegacyWriteSession(') == 1
    assert source.count('_db.setSyncStatus(') == 1
    assert "_db.setSyncStatus('attendance', status," in source
    assert 'expectedSession: session' in source
    assert 'await _requireCurrentSession(session);' in source
    assert 'response.sessionSuperseded' in source
    assert '} on StaleAttendanceSyncSession {' in source
    assert '_running = false;' in source
