"""Commit 7 — owner-safe global Sync Recovery Center.

Flutter tooling is intentionally unavailable in the audit sandbox. These pins
cover the source contracts, while the sqlite tests exercise the two critical
CAS/privacy invariants against real SQL semantics.
"""
from __future__ import annotations

import sqlite3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
APP = ROOT / "Mobile" / "wbws_flutter_app" / "lib"
SERVICES = APP / "services"
SCREENS = APP / "screens"
WIDGETS = APP / "widgets"


def source(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def between(text: str, start: str, end: str) -> str:
    i = text.index(start)
    return text[i:text.index(end, i)]


def test_center_has_all_required_sections_and_exact_actions() -> None:
    center = source(SCREENS / "profile" / "sync_center_screen.dart")
    for heading in (
        "Waiting to send",
        "Retry scheduled",
        "Needs attention",
        "Paused after sign-in/access change",
        "Resolved conflicts",
    ):
        assert heading in center
    for action in (
        "retryRejectedOperation",
        "retryRecoveryOperation",
        "retryOutbox",
        "discardRejectedOperation",
        "discardRecoveryOperation",
        "deleteOutbox",
    ):
        assert action in center
    assert "expectedState: item.state" in center
    assert "Conflict acknowledged." in center
    assert "Discard saved operation?" in center
    assert "This item changed. The list was reloaded." in center
    assert "_load(silent: true)" in center


def test_center_is_reachable_from_profile_shell_banners_hymns_and_messages() -> None:
    profile = source(SCREENS / "profile" / "profile_screen.dart")
    main = source(APP / "main.dart")
    shell = source(SCREENS / "shell" / "app_shell.dart")
    attention = source(WIDGETS / "sync_attention.dart")
    offline = source(WIDGETS / "offline_banner.dart")
    hymns = source(SCREENS / "mezmur" / "mezmur_hymns.dart")
    messages = source(SCREENS / "notifications" / "messages_screen.dart")
    att_home = source(SCREENS / "att_taker" / "att_taker_home.dart")

    assert "'/sync-center': (_) => const SyncCenterScreen()" in main
    assert "pushNamed('/sync-center')" in profile
    assert "privateUnresolvedTotal" in profile
    assert "totalPending" not in profile
    assert "const SyncRecoveryBanner()" in shell
    assert "openSyncCenter(context)" in attention
    assert "pushNamed('/sync-center')" in offline
    assert "'/sync-center'" in hymns
    assert "arguments: SyncRecoveryDomain.hymn" in hymns
    assert messages.count("'/sync-center'") >= 2
    assert messages.count("arguments: SyncRecoveryDomain.communication") >= 2
    assert "pushNamed('/sync-center')" in att_home
    assert "privateUnresolvedTotal" in att_home
    assert "getTotalPendingCount" not in att_home


def test_detail_query_is_current_owner_scope_only_and_payload_light() -> None:
    db = source(SERVICES / "local_db.dart")
    body = between(db, "getSyncRecoveryItems({", "retryHymnRecoveryOperation(")
    for table in (
        "pending_attendance",
        "pending_grades",
        "pending_mezmur",
        "pending_hr",
        "comm_outbox",
    ):
        assert table in body
    # Every private UNION arm has both placeholders. Five arms => five pairs.
    assert body.count("owner_user_id = ?") >= 5
    assert body.count("created_authorization_version = ?") >= 5
    for private_payload in ("student_name", "father_name", "member_code", "body"):
        assert private_payload not in body
    assert "payload_json" not in body
    assert "if (includeSharedHymns)" in body
    assert "Shared library operation" in body


def test_recovery_union_sql_executes_with_expected_shape() -> None:
    db_source = source(SERVICES / "local_db.dart")
    method = between(db_source, "getSyncRecoveryItems({", "final result = <SyncRecoveryItem>[];")
    sql = method.split("final rows = await txn.rawQuery('''", 1)[1].split("''', [", 1)[0]
    conn = sqlite3.connect(":memory:")
    conn.executescript(
        """
        CREATE TABLE pending_attendance (
          client_op_id TEXT, sync_state TEXT, class_name TEXT, date TEXT,
          sync_error TEXT, next_attempt_at TEXT, synced INTEGER,
          owner_user_id INTEGER, created_authorization_version INTEGER,
          class_id INTEGER);
        CREATE TABLE pending_grades (
          client_op_id TEXT, sync_state TEXT, assessment_name TEXT,
          class_name TEXT, sync_error TEXT, next_attempt_at TEXT,
          synced INTEGER, owner_user_id INTEGER,
          created_authorization_version INTEGER, assessment_id INTEGER);
        CREATE TABLE pending_mezmur (
          client_op_id TEXT, sync_state TEXT, date TEXT, section TEXT,
          sync_error TEXT, next_attempt_at TEXT, synced INTEGER,
          owner_user_id INTEGER, created_authorization_version INTEGER);
        CREATE TABLE pending_hr (
          client_op_id TEXT, sync_state TEXT, date TEXT, section TEXT,
          sync_error TEXT, next_attempt_at TEXT, synced INTEGER,
          owner_user_id INTEGER, created_authorization_version INTEGER);
        CREATE TABLE comm_outbox (
          client_tag TEXT, state TEXT, thread_id INTEGER, fail_reason TEXT,
          next_attempt_at TEXT, owner_user_id INTEGER,
          created_authorization_version INTEGER);
        """
    )
    cursor = conn.execute(sql, [42, 9] * 5)
    assert [column[0] for column in cursor.description] == [
        "domain", "operation_id", "row_id", "state", "title", "detail",
        "reason", "next_attempt_at",
    ]
    assert cursor.fetchall() == []


def test_old_scope_private_work_is_count_only_and_never_due() -> None:
    db = source(SERVICES / "local_db.dart")
    inventory = between(db, "getOutboxInventory({", "getLocalDataInventory()")
    assert "created_authorization_version <> ?" in inventory
    assert "final oldScopePrivate" in inventory
    assert "final pausedScope = currentScopePaused + oldScopePrivate" in inventory
    assert "await legacyOwnerState('1 = 1')" in inventory
    # Actual network-ready state stays exact-scope through allState/legacyState.
    assert "AND created_authorization_version = ?" in inventory
    details = between(db, "getSyncRecoveryItems({", "retryHymnRecoveryOperation(")
    assert "created_authorization_version <> ?" not in details


def test_legacy_recovery_actions_are_business_key_id_state_owner_scope_cas() -> None:
    db = source(SERVICES / "local_db.dart")
    retry = between(db, "retryRejectedOperation(", "discardRejectedOperation(")
    discard = between(db, "discardRejectedOperation(", "getRejectedBatches()")
    for body in (retry, discard):
        assert "client_op_id = ?" in body
        assert "owner_user_id = ?" in body
        assert "created_authorization_version = ?" in body
        assert "expectedState" in body
        assert "spec.businessKeyColumns" in body
        assert "keyWhere" in body
        assert "SyncRecoveryActionResult.stale" in body
        assert "affected == rows.length" in body
    assert "sync_state': 'pending'" in retry
    assert "attempt_count': 0" in retry
    assert "txn.delete(" in discard


def test_comm_and_hymn_actions_return_applied_or_stale_from_exact_cas() -> None:
    comm = source(SERVICES / "comm_store.dart")
    db = source(SERVICES / "local_db.dart")
    for name in ("deleteOutbox", "retryOutbox"):
        body = between(comm, f"{name}(", "outboxNextDue()" if name == "retryOutbox" else "retryOutbox(")
        assert "Future<SyncRecoveryActionResult>" in comm[comm.rfind("Future<", 0, comm.index(f"{name}(")):comm.index(f"{name}(") + len(name)]
        assert "client_tag = ?" in body
        assert "expectedState" in body
        assert "owner_user_id = ?" in body
        assert "created_authorization_version = ?" in body
        assert "affected == 1" in body
    for name in ("retryHymnRecoveryOperation", "discardHymnRecoveryOperation"):
        start = db.index(name)
        body = db[start:start + 1900]
        assert "id = ? AND client_op_id = ? AND synced = 0" in body
        assert "sync_state = ?" in body
        assert "affected == 1" in body
        assert "SyncRecoveryActionResult.stale" in body
    hymn = source(SERVICES / "hymn_store.dart")
    recovery = between(hymn, "retryRecoveryOperation(", "// ── local reads")
    assert recovery.count("if (!canEdit)") == 2
    assert "retryHymnRecoveryOperation" in recovery
    assert "discardHymnRecoveryOperation" in recovery


def test_all_three_submit_flows_keep_exact_ref_and_block_late_autosave() -> None:
    # HR attendance was retired (2026-10-07): its screen is deleted, so
    # the submit-flow contract now covers the three live flows.
    files = {
        "attendance": SCREENS / "attendance" / "attendance_screen.dart",
        "grades": SCREENS / "teacher" / "teacher_grades.dart",
        "mezmur": SCREENS / "mezmur" / "mezmur_attendance.dart",
    }
    pending_lookup = {
        "attendance": "getPendingAttendanceRecords",
        "grades": "getPendingGradeRecords",
        "mezmur": "getPendingMezmurRecords",
    }
    for name, path in files.items():
        text = source(path)
        if name == "attendance":
            # Capture the exact operation in the toast closure, never a
            # mutable ref that another sheet's submission can replace.
            assert "late final LegacyOperationRef operation;" in text
            assert "operation = await _db.saveAttendanceLocal(" in text
            assert "onUndo: () => _undoSubmit(operation, epoch)" in text
            assert "_submittedUndoRef" not in text
            undo_start = "Future<void> _undoSubmit(LegacyOperationRef operation, int epoch)"
        else:
            assert "LegacyOperationRef? _submittedUndoRef" in text, name
            assert "_submittedUndoRef = await _db.save" in text, name
            undo_start = "Future<void> _undoSubmit()"
        assert "notBefore: DateTime.now().add(const Duration(seconds: 5))" in text, name
        undo = between(text, undo_start, "@override\n  Widget build")
        if name == "attendance":
            assert "epoch != _loadEpoch || !_sheetSessionCurrent" in undo
        assert "undoSubmittedLegacyOperation(operation)" in undo, name
        assert pending_lookup[name] not in undo, name
        assert "SubmitUndoResult.supersededLocal" in undo, name
        assert "SubmitUndoResult.supersededSession" in undo, name

    attendance = source(files["attendance"])
    mezmur = source(files["mezmur"])
    grades = source(files["grades"])
    for text in (attendance, mezmur):
        submit = between(text, "Future<void> _submit", "Future<void> _undoSubmit")
        assert "_autoSave.cancel();" in submit
        assert "_submitting = true;" in submit
        assert "_submitting || PacketLock.isLocked" in text
    assert "_autosaveTimer?.cancel();" in grades
    assert "await _autosaveTail.catchError" in grades
    assert "List<Map<String, dynamic>>.unmodifiable" in grades
    assert "_persistDraftSnapshot(snapshot)" in grades
    assert "_commitInProgress" in grades


def test_all_legacy_save_apis_accept_not_before() -> None:
    db = source(SERVICES / "local_db.dart")
    for name in (
        "saveAttendanceLocal",
        "saveGradesLocal",
        "saveMezmurLocal",
        "saveHrLocal",
    ):
        start = db.index(f"Future<LegacyOperationRef> {name}")
        body = db[start:start + 2400]
        assert "DateTime? notBefore" in body, name
        assert "notBefore: notBefore" in body, name


def test_stale_cas_does_not_touch_replacement_sqlite() -> None:
    conn = sqlite3.connect(":memory:")
    conn.execute(
        """CREATE TABLE pending_attendance (
             id INTEGER PRIMARY KEY, class_id INTEGER, date TEXT,
             client_op_id TEXT, sync_state TEXT, synced INTEGER,
             owner_user_id INTEGER, created_authorization_version INTEGER
           )"""
    )
    # The reviewed op A was replaced by B before the user tapped Discard.
    conn.execute(
        "INSERT INTO pending_attendance VALUES "
        "(1, 7, '2026-09-24', 'op-B', 'pending', 0, 42, 9)"
    )
    changed = conn.execute(
        "DELETE FROM pending_attendance WHERE client_op_id = ? AND synced = 0 "
        "AND class_id = ? AND date = ? AND sync_state = ? "
        "AND owner_user_id = ? AND created_authorization_version = ?",
        ("op-A", 7, "2026-09-24", "needs_attention", 42, 9),
    ).rowcount
    assert changed == 0
    assert conn.execute(
        "SELECT client_op_id, sync_state FROM pending_attendance"
    ).fetchall() == [("op-B", "pending")]


def test_old_scope_detail_query_returns_nothing_but_safe_count_remains() -> None:
    conn = sqlite3.connect(":memory:")
    conn.execute(
        """CREATE TABLE comm_outbox (
             client_tag TEXT, thread_id INTEGER, body TEXT, state TEXT,
             owner_user_id INTEGER, created_authorization_version INTEGER
           )"""
    )
    conn.execute(
        "INSERT INTO comm_outbox VALUES "
        "('old-op', 99, 'private message body', 'paused_scope', 42, 8)"
    )
    details = conn.execute(
        "SELECT client_tag, body FROM comm_outbox WHERE owner_user_id = ? "
        "AND created_authorization_version = ?",
        (42, 9),
    ).fetchall()
    safe_count = conn.execute(
        "SELECT COUNT(*) FROM comm_outbox WHERE owner_user_id = ? "
        "AND created_authorization_version <> ?",
        (42, 9),
    ).fetchone()[0]
    assert details == []
    assert safe_count == 1
