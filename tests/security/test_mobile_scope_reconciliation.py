import re
import sqlite3
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile" / "wbws_flutter_app"
SERVICES = MOBILE / "lib" / "services"


def source(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def method_block(text: str, start: str, end: str) -> str:
    begin = text.index(start)
    return text[begin:text.index(end, begin)]


def test_refresh_has_all_typed_outcomes_and_scope_never_uses_retry_path() -> None:
    models = source(SERVICES / "session_models.dart")
    api = source(SERVICES / "api_service.dart")
    outcomes = method_block(models, "enum AuthRefreshOutcome", "enum SessionRoot")
    for value in (
        "sameScope",
        "scopeChanged",
        "rejected",
        "transientFailure",
        "superseded",
    ):
        assert value in outcomes

    refresh = method_block(
        api,
        "Future<AuthRefreshOutcome> refreshAccessToken()",
        "// ============================================================\n  // DASHBOARD",
    )
    assert "Future<bool> refreshAccessToken()" not in api
    assert "candidate.role != userRole" in refresh
    assert "candidate.authorizationVersion != authorizationVersion" in refresh
    assert "onAuthorizationScopeChanged" in refresh
    assert "AuthRefreshOutcome.scopeChanged" in refresh

    # Every authenticated transport checks scopeChanged/superseded before the
    # only branch which retries the original request.
    #
    # The count is asserted as "every sameScope branch has a scopeChanged
    # branch", not as a literal. The literal was 4 and went stale when
    # uploadMemberPhoto() was added as a fifth authenticated transport -- a
    # transport that DOES the check. A pin that fails when correct code is
    # added is measuring the file's size, not its safety.
    changed = api.count("refreshOutcome == AuthRefreshOutcome.scopeChanged")
    same = api.count("if (refreshOutcome == AuthRefreshOutcome.sameScope)")
    assert changed >= 4, f"a transport lost its scopeChanged check ({changed})"
    assert changed == same, (
        "each sameScope branch needs its own scopeChanged branch: "
        f"{changed} scopeChanged vs {same} sameScope"
    )
    for block_start, block_end in (
        ("Future<ApiResponse> _doGet", "/// Core POST request"),
        ("Future<ApiResponse> post", "/// Core PUT request"),
        ("Future<ApiResponse> put", "/// Handle response"),
        ("Future<ApiResponse> _uploadTaxonomyImage", "/// Drop a category's cover image"),
        # Added with the transport itself: photo upload is authenticated and
        # retries, so it needs the same ordering guarantee as the other four.
        # It is the last method in the class; the class closes after it.
        ("Future<ApiResponse> uploadMemberPhoto", "\n}\n\n/// "),
    ):
        block = method_block(api, block_start, block_end)
        assert block.index("AuthRefreshOutcome.scopeChanged") < block.index(
            "AuthRefreshOutcome.sameScope"
        )


def test_scope_transition_order_and_bootstrap_recovery_are_explicit() -> None:
    session = source(SERVICES / "session_service.dart")
    models = source(SERVICES / "session_models.dart")
    live = method_block(
        session,
        "Future<bool> applyAuthorizationScopeChange",
        "Future<LoginActivationResult> login",
    )
    ordered = (
        "state: SessionState.scopeReconciling",
        "_generation = targetGeneration",
        "_api.activateCredentials(candidate)",
        "_stopPrivateServices()",
        "_reconcileAuthorizationScopedLocalState",
        "_persistActive(candidate)",
        "_resumeCompatibleOperations(candidate)",
        "_startPrivateServices()",
    )
    positions = [live.index(token) for token in ordered]
    assert positions == sorted(positions)
    assert live.index("_root = SessionRoot.scopeReconciling") < live.index(
        "await _db.persistLocalSession"
    )
    assert "ownerUserId: candidate.userId" in live
    assert "authorizationVersion: candidate.authorizationVersion" in live
    assert "ownerRole: candidate.role" in live
    assert "previousAuthorizationVersion" in live
    assert "_scopeMarkerReason(" in live
    assert "scope_marker_persist_failed" in live
    assert "state: SessionState.reauthRequired" in live

    reconcile = method_block(
        session,
        "Future<void> _reconcileAuthorizationScopedLocalState",
        "Future<void> _bootstrapComplete",
    )
    assert reconcile.index("backfillOwnerlessRows") < reconcile.index(
        "pausePrivateOperationsOutsideAuthorizationScope"
    )

    assert "scopeReconciling('scope_reconciling')" in models
    assert "if (_record.state == SessionState.scopeReconciling)" in session
    interrupted = method_block(
        session,
        "Future<void> _finishInterruptedAuthorizationScopeChange",
        "Future<void> _reconcileAuthorizationScopedLocalState",
    )
    assert interrupted.index("_reconcileAuthorizationScopedLocalState") < interrupted.index(
        "credentialsMatchTarget"
    )
    assert "scope_change_credentials_incomplete" in interrupted
    assert "SessionRoot.reauthentication" in interrupted


def test_scope_reconciliation_purges_reads_but_preserves_durable_writes() -> None:
    db = source(SERVICES / "local_db.dart")
    purge = method_block(
        db,
        "Future<void> clearAuthorizationScopedReadCaches()",
        "Future<LocalSessionRecord> getLocalSession",
    )
    expected_read_tables = {
        "cached_classes",
        "cached_students",
        "cached_subjects",
        "cached_assessments",
        "cached_dashboard",
        "cached_members",
        "cached_attendance",
        "cached_grade_sheets",
        "cached_mezmur_sheet",
        "cached_mezmur_sections",
        "cached_mezmur_days",
        "cached_mezmur_analytics_last",
        "cached_review_packets",
        "cached_review_packet_details",
        "cached_review_stats",
        "cached_edu_classes",
        "cached_edu_class_rosters",
        "cached_edu_subjects",
        "cached_edu_teacher_snapshot",
        "cached_edu_teachers",
        "cached_edu_teacher_details",
        "cached_hr_sheet",
        "cached_hr_sections",
        "comm_threads",
        "comm_messages",
        "comm_meta",
        "cached_notifications",
        "cached_announcements",
        "sync_log",
    }
    registry = db.split(
        "static const List<String> _privateReadCacheTables = [", 1
    )[1].split("];", 1)[0]
    assert set(re.findall(r"'([a-z0-9_]+)'", registry)) == expected_read_tables
    assert "for (final table in _privateReadCacheTables)" in purge

    assert "db.transaction((txn) async" in purge
    assert "PRAGMA wal_checkpoint(TRUNCATE)" in purge
    assert "await db.execute('VACUUM')" not in purge

    for preserved in (
        "pending_attendance",
        "pending_grades",
        "pending_mezmur",
        "pending_hr",
        "pending_hymn_ops",
        "comm_outbox",
        "comm_drafts",
        "cached_hymns",
        "cached_hymn_categories",
        "cached_hymn_zemarians",
        "hymn_sync_meta",
    ):
        assert f"txn.delete('{preserved}'" not in purge

    pause = method_block(
        db,
        "Future<void> pausePrivateOperationsOutsideAuthorizationScope",
        "Future<void> clearAuthorizationScopedReadCaches",
    )
    assert "'sync_state': 'paused_scope'" in pause
    assert "'state': 'paused_scope'" in pause
    assert "AUTH_SCOPE_CHANGED" in pause
    assert "created_authorization_version IS NULL" in pause
    assert "created_authorization_version <> ?" in pause
    assert "pending_hymn_ops" not in pause
    assert "comm_drafts" not in pause

    resume = method_block(
        db,
        "Future<void> resumeLegacyPausedAuthentication",
        "/// Atomically claims and snapshots one due operation",
    )
    assert "owner_user_id = ?" in resume
    assert "created_authorization_version = ?" in resume
    assert "resumeSharedHymnOperations" in resume
    session = source(SERVICES / "session_service.dart")
    assert "resumeSharedHymnOperations: HymnStore().canEdit" in session


def test_scope_sql_policy_quarantines_only_old_private_scope_and_keeps_payloads() -> None:
    connection = sqlite3.connect(":memory:")
    connection.row_factory = sqlite3.Row
    for table in ("pending_attendance", "pending_grades", "pending_mezmur", "pending_hr"):
        connection.execute(
            f"""
            CREATE TABLE {table} (
              id INTEGER PRIMARY KEY,
              payload_text TEXT,
              synced INTEGER NOT NULL,
              sync_state TEXT NOT NULL,
              owner_user_id INTEGER,
              created_authorization_version INTEGER,
              next_attempt_at TEXT,
              sync_error TEXT,
              failure_code TEXT,
              failure_http_status INTEGER
            )
            """
        )
        connection.executemany(
            f"INSERT INTO {table} VALUES(?,?,?,?,?,?,?,?,?,?)",
            (
                (1, "old payload", 0, "in_flight", 17, 4, None, None, None, None),
                (2, "new payload", 0, "pending", 17, 5, None, None, None, None),
                (3, "other owner", 0, "pending", 18, 4, None, None, None, None),
                (4, "legacy scope", 0, "needs_attention", 17, None, None, "keep", "OLD", 422),
                (5, "ownerless upgrade", 0, "pending", None, None, None, None, None, None),
            ),
        )

    connection.execute(
        """CREATE TABLE comm_outbox (
        client_tag TEXT PRIMARY KEY, body TEXT, state TEXT, owner_user_id INTEGER,
        created_authorization_version INTEGER, next_attempt_at TEXT,
        fail_reason TEXT, failure_code TEXT, failure_http_status INTEGER)"""
    )
    connection.executemany(
        "INSERT INTO comm_outbox VALUES(?,?,?,?,?,?,?,?,?)",
        (
            ("old", "message one", "in_flight", 17, 4, None, None, None, None),
            ("new", "message two", "pending", 17, 5, None, None, None, None),
        ),
    )
    connection.execute(
        "CREATE TABLE comm_drafts(thread_id INTEGER PRIMARY KEY, body TEXT, owner_user_id INTEGER, created_authorization_version INTEGER)"
    )
    connection.execute("INSERT INTO comm_drafts VALUES(1,'unfinished',17,4)")
    connection.execute(
        "CREATE TABLE pending_hymn_ops(id INTEGER PRIMARY KEY, payload_json TEXT, synced INTEGER, sync_state TEXT)"
    )
    connection.execute("INSERT INTO pending_hymn_ops VALUES(1,'shared edit',0,'pending')")

    for table in ("pending_attendance", "pending_grades", "pending_mezmur", "pending_hr"):
        # A complete marker binds v34 ownerless rows to the previous scope so
        # they are quarantined rather than silently promoted to version 5.
        connection.execute(
            f"UPDATE {table} SET owner_user_id=?, created_authorization_version=? "
            "WHERE synced=0 AND owner_user_id IS NULL",
            (17, 4),
        )
        connection.execute(
            f"""UPDATE {table}
                   SET sync_state='paused_scope', next_attempt_at=NULL,
                       sync_error='Authorization changed before this saved operation was sent.',
                       failure_code='AUTH_SCOPE_CHANGED', failure_http_status=NULL
                 WHERE synced=0 AND owner_user_id=?
                   AND (created_authorization_version IS NULL OR created_authorization_version<>?)""",
            (17, 5),
        )
    connection.execute(
        """UPDATE comm_outbox
              SET state='paused_scope', next_attempt_at=NULL,
                  fail_reason='Authorization changed before this saved message was sent.',
                  failure_code='AUTH_SCOPE_CHANGED', failure_http_status=NULL
            WHERE state<>'synced' AND owner_user_id=?
              AND (created_authorization_version IS NULL OR created_authorization_version<>?)""",
        (17, 5),
    )

    for table in ("pending_attendance", "pending_grades", "pending_mezmur", "pending_hr"):
        rows = connection.execute(
            f"SELECT id,payload_text,sync_state,failure_code FROM {table} ORDER BY id"
        ).fetchall()
        assert [(row["id"], row["sync_state"]) for row in rows] == [
            (1, "paused_scope"),
            (2, "pending"),
            (3, "pending"),
            (4, "paused_scope"),
            (5, "paused_scope"),
        ]
        assert [row["payload_text"] for row in rows] == [
            "old payload",
            "new payload",
            "other owner",
            "legacy scope",
            "ownerless upgrade",
        ]
        assert rows[0]["failure_code"] == "AUTH_SCOPE_CHANGED"
        assert rows[3]["failure_code"] == "AUTH_SCOPE_CHANGED"

    comm_rows = connection.execute(
        "SELECT client_tag,state,body FROM comm_outbox ORDER BY client_tag"
    ).fetchall()
    assert [tuple(row) for row in comm_rows] == [
        ("new", "pending", "message two"),
        ("old", "paused_scope", "message one"),
    ]
    assert connection.execute("SELECT body FROM comm_drafts").fetchone()[0] == "unfinished"
    assert connection.execute("SELECT payload_json FROM pending_hymn_ops").fetchone()[0] == "shared edit"


def test_shell_routes_and_memory_hints_are_rebuilt_for_new_generation() -> None:
    main = source(MOBILE / "lib" / "main.dart")
    session = source(SERVICES / "session_service.dart")
    app_nav = source(SERVICES / "app_nav.dart")
    navigator = source(SERVICES / "app_navigator.dart")
    assert "SessionRoot.scopeReconciling" in main
    assert "AppShell(key: ValueKey(_session.generation))" in main
    assert "AppNav().resetForAuthorizationScope()" in session
    assert "AppNavigator.popToRootForAuthorizationScope()" in session
    assert "attendanceClassId = null" in app_nav
    assert "_lastGradesLoad = null" in app_nav
    assert "popUntil((route) => route.isFirst)" in navigator
