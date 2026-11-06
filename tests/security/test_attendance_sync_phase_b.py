"""PHASE B: the Flutter attendance consumer of the server change feed.

There is no Dart or Flutter executable in this sandbox, so this file is
deliberately split in two, and it is explicit about which half proves
what:

  * SqliteCursorRuntimeTests execute REAL SQLite 3 through Python using
    the REAL table declaration loaded out of local_schema_v34.dart. They
    prove the cursor storage behaves — defaults, idempotent replay,
    advance-only-on-commit, rollback leaving the cursor untouched. That
    is genuine runtime evidence, following the approach already used by
    test_mobile_v34_sqlite_runtime.py.

  * CoordinatorContractTests read the Dart source. They pin the wiring
    decisions that a runtime harness in another language cannot honestly
    verify, and they are NOT a substitute for running the Dart.

WHAT IS STILL UNVERIFIED: the Dart itself has never been compiled or
executed. applyAttendanceDelta's sheet patching, the coordinator's
paging loop and the SyncService wiring REQUIRE FLUTTER VERIFICATION on a
real Flutter environment. The Dart-side behavioural tests live in
Mobile/wbws_flutter_app/test/attendance_sync_coordinator_test.dart and
have not been run.

Baseline relevance
------------------
Before this phase sync_service.dart was upload-only: its sole download
path, cacheForOffline(), refetched dashboard stats and the class list.
No cursor existed anywhere in the app, so a record another device
changed or deleted stayed wrong in the local cache indefinitely. Every
assertion here fails on that baseline.
"""
from __future__ import annotations

import json
import re
import sqlite3
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile" / "wbws_flutter_app"
SCHEMA_SOURCE = MOBILE / "lib" / "services" / "local_schema_v34.dart"
LOCAL_DB = MOBILE / "lib" / "services" / "local_db.dart"
COORDINATOR = MOBILE / "lib" / "services" / "attendance_sync_coordinator.dart"
SYNC_SERVICE = MOBILE / "lib" / "services" / "sync_service.dart"
API_SERVICE = MOBILE / "lib" / "services" / "api_service.dart"
DART_TESTS = MOBILE / "test" / "attendance_sync_coordinator_test.dart"


def _sync_state_ddl() -> str:
    """The production CREATE TABLE text, lifted from the Dart contract.

    Loading it rather than restating it means this harness cannot drift
    away from what the app actually creates.
    """
    source = SCHEMA_SOURCE.read_text(encoding="utf-8")
    match = re.search(
        r"const localSyncStateV35Sql = '''(.*?)''';", source, re.S
    )
    assert match, "localSyncStateV35Sql not found in the schema contract"
    return match.group(1)


def _connection() -> sqlite3.Connection:
    connection = sqlite3.connect(":memory:")
    connection.row_factory = sqlite3.Row
    connection.execute(_sync_state_ddl())
    # The sheet cache, in the shape local_db.dart creates it.
    connection.execute(
        """
        CREATE TABLE cached_attendance (
          class_id INTEGER NOT NULL,
          date TEXT NOT NULL,
          response_json TEXT NOT NULL,
          updated_at TEXT,
          PRIMARY KEY (class_id, date)
        )
        """
    )
    return connection


def _read_cursor(connection: sqlite3.Connection, domain: str = "attendance") -> int:
    row = connection.execute(
        "SELECT cursor FROM sync_state WHERE domain = ?", (domain,)
    ).fetchone()
    return 0 if row is None else row["cursor"]


def _seed_sheet(connection: sqlite3.Connection, class_id: int, date: str, students: list) -> None:
    connection.execute(
        "INSERT INTO cached_attendance (class_id, date, response_json, updated_at) VALUES (?, ?, ?, ?)",
        (class_id, date, json.dumps({"students": students, "submission_status": "", "locked": False}), "t0"),
    )


def _sheet_students(connection: sqlite3.Connection, class_id: int, date: str) -> list:
    row = connection.execute(
        "SELECT response_json FROM cached_attendance WHERE class_id = ? AND date = ?",
        (class_id, date),
    ).fetchone()
    return json.loads(row["response_json"])["students"]


class TestSqliteCursorRuntime:
    """Real SQLite, real DDL. These actually execute."""

    def test_absent_cursor_reads_as_zero(self):
        """A fresh install has no row, and 0 is the value that makes the
        server demand a bootstrap rather than serve a delta."""
        connection = _connection()
        assert _read_cursor(connection) == 0

    def test_cursor_upsert_is_idempotent(self):
        """Applying the same page twice must leave one row at one value,
        not two rows or a doubled cursor."""
        connection = _connection()
        for _ in range(3):
            connection.execute(
                "INSERT OR REPLACE INTO sync_state (domain, cursor, last_sync_at, status, error)"
                " VALUES (?, ?, ?, ?, ?)",
                ("attendance", 42, "t1", "ok", None),
            )
        rows = connection.execute("SELECT * FROM sync_state").fetchall()
        assert len(rows) == 1
        assert rows[0]["cursor"] == 42

    def test_cursor_and_data_commit_together(self):
        """The invariant the whole phase rests on: the sheet edit and the
        cursor move are one transaction."""
        connection = _connection()
        _seed_sheet(connection, 10, "2026-03-01", [{"member_id": 1, "status": None}])
        connection.commit()

        connection.execute("BEGIN")
        students = _sheet_students(connection, 10, "2026-03-01")
        students[0]["status"] = "present"
        connection.execute(
            "UPDATE cached_attendance SET response_json = ? WHERE class_id = ? AND date = ?",
            (json.dumps({"students": students, "submission_status": "", "locked": False}), 10, "2026-03-01"),
        )
        connection.execute(
            "INSERT OR REPLACE INTO sync_state (domain, cursor, last_sync_at, status, error)"
            " VALUES ('attendance', 7, 't1', 'ok', NULL)"
        )
        connection.commit()

        assert _read_cursor(connection) == 7
        assert _sheet_students(connection, 10, "2026-03-01")[0]["status"] == "present"

    def test_rollback_leaves_cursor_and_data_untouched(self):
        """A failure part-way through an apply must lose the data change
        AND the cursor move, so the page is re-pulled rather than lost."""
        connection = _connection()
        _seed_sheet(connection, 10, "2026-03-01", [{"member_id": 1, "status": "present"}])
        connection.execute(
            "INSERT INTO sync_state (domain, cursor, last_sync_at, status, error)"
            " VALUES ('attendance', 5, 't0', 'ok', NULL)"
        )
        connection.commit()

        try:
            connection.execute("BEGIN")
            connection.execute(
                "UPDATE cached_attendance SET response_json = ? WHERE class_id = ? AND date = ?",
                (json.dumps({"students": [{"member_id": 1, "status": "absent"}]}), 10, "2026-03-01"),
            )
            connection.execute(
                "INSERT OR REPLACE INTO sync_state (domain, cursor, last_sync_at, status, error)"
                " VALUES ('attendance', 9, 't1', 'ok', NULL)"
            )
            raise RuntimeError("apply failed half way")
        except RuntimeError:
            connection.rollback()

        assert _read_cursor(connection) == 5, "cursor advanced despite a failed apply"
        assert _sheet_students(connection, 10, "2026-03-01")[0]["status"] == "present"

    def test_one_cursor_table_serves_every_domain(self):
        """Keyed by domain, so grades and the rest reuse it instead of
        each growing a table of its own."""
        connection = _connection()
        for domain, cursor in (("attendance", 11), ("grades", 22)):
            connection.execute(
                "INSERT INTO sync_state (domain, cursor, last_sync_at, status, error)"
                " VALUES (?, ?, 't1', 'ok', NULL)",
                (domain, cursor),
            )
        assert _read_cursor(connection, "attendance") == 11
        assert _read_cursor(connection, "grades") == 22

    def test_status_can_record_failure_without_moving_the_cursor(self):
        """'Sync failed' and 'here is where I got to' are separate facts;
        the UI needs both and they must not be conflated."""
        connection = _connection()
        connection.execute(
            "INSERT INTO sync_state (domain, cursor, last_sync_at, status, error)"
            " VALUES ('attendance', 5, 't0', 'ok', NULL)"
        )
        connection.execute(
            "UPDATE sync_state SET status = 'offline', error = 'No connection', last_sync_at = 't1'"
            " WHERE domain = 'attendance'"
        )
        row = connection.execute("SELECT * FROM sync_state").fetchone()
        assert row["cursor"] == 5
        assert row["status"] == "offline"
        assert row["error"] == "No connection"


class TestCoordinatorContract:
    """Source-level pins. NOT a substitute for executing the Dart."""

    @classmethod
    def setup_class(cls):
        cls.schema = SCHEMA_SOURCE.read_text(encoding="utf-8")
        cls.local_db = LOCAL_DB.read_text(encoding="utf-8")
        cls.coordinator = COORDINATOR.read_text(encoding="utf-8")
        cls.sync_service = SYNC_SERVICE.read_text(encoding="utf-8")
        cls.api = API_SERVICE.read_text(encoding="utf-8")

    def test_schema_version_was_bumped_for_the_new_table(self):
        # The v35 step is unchanged history and must remain reachable; only
        # the current-version pin moves when a later migration lands (v36
        # added the S1 operation/attempt ledger; v37 added its
        # execution_source column).
        assert "const localDatabaseSchemaVersion = 42;" in self.schema
        assert "if (oldVersion < 35)" in self.local_db
        assert "await db.execute(localSyncStateV35Sql);" in self.local_db

    def test_cursor_advances_inside_the_apply_transaction(self):
        """The cursor write must sit inside the same db.transaction block
        as the sheet writes, using the transaction handle."""
        # Bounded by the next method so the match cannot stop early at
        # the parameter list's closing brace.
        match = re.search(
            r"Future<void> applyAttendanceDelta\(.*?Future<void> resetAttendanceSyncForBootstrap",
            self.local_db, re.S,
        )
        assert match, "applyAttendanceDelta not found"
        body = match.group(0)
        assert "await db.transaction((txn) async {" in body
        assert "txn.insert(\n        'sync_state'," in body, (
            "the cursor must be written through the transaction handle"
        )

    def test_delete_unmarks_the_student_and_keeps_the_roster_entry(self):
        """The sheet entry is enrollment data; only the attendance status
        belongs to the deleted record. The rule lives in the pure
        AttendanceDelta so it is testable without a database."""
        delta = (MOBILE / "lib" / "services" / "attendance_delta.dart").read_text(encoding="utf-8")
        assert "bool get isDeletion => status == null;" in delta
        assert "status: deleted ? null : _asStatus(record['status'])" in delta
        assert "AttendanceDelta.applyToSheet(sheet, target);" in self.local_db

    def test_coordinator_handles_the_full_response_contract(self):
        for token in ("bootstrap_required", "has_more", "next_cursor", "items", "records"):
            assert token in self.coordinator, f"{token} unhandled"

    def test_coordinator_refuses_a_cursor_that_does_not_advance(self):
        assert "nextCursor <= cursor" in self.coordinator

    def test_coordinator_paging_is_bounded(self):
        assert "maxPagesPerRun" in self.coordinator
        assert "pages < maxPagesPerRun" in self.coordinator

    def test_bootstrap_clears_cache_and_cursor_together(self):
        match = re.search(
            r"Future<void> resetAttendanceSyncForBootstrap\(\s*\{required LegacyWriteSession expectedSession\}\) async \{.*?Future<void> completeAttendanceBootstrap",
            self.local_db, re.S,
        )
        assert match, "resetAttendanceSyncForBootstrap not found"
        body = match.group(0)
        assert "txn.delete('cached_attendance')" in body
        assert "'cursor': 0" in body

    def test_purging_scoped_caches_also_resets_the_cursor(self):
        """Otherwise the device resumes from a revision describing data
        that was just deleted, and never refills."""
        match = re.search(
            r"Future<void> clearAuthorizationScopedReadCaches\(\) async \{.*?\n    \}\);",
            self.local_db, re.S,
        )
        assert match
        assert "txn.delete('sync_state')" in match.group(0)

    def test_api_method_targets_the_phase_a_endpoint(self):
        assert "'/sync/changes'" in self.api
        assert "'entity': 'attendance'" in self.api

    def test_wired_into_existing_sync_lifecycle_not_a_new_service(self):
        """Reuses SyncService's triggers; no second sync service and no
        background infrastructure."""
        assert "pullServerChanges" in self.sync_service
        assert "await pullServerChanges(generation: generation);" in self.sync_service
        assert "Timer.periodic" not in self.coordinator
        assert "class SyncService" not in self.coordinator

    def test_sync_failure_cannot_break_startup(self):
        """pull() swallows its failures and records them instead."""
        assert "catch (e) {" in self.coordinator
        assert "setSyncStatus" in self.coordinator

    def test_screens_are_not_given_a_network_path(self):
        """The consumer writes to the local DB only; no screen was changed
        to fetch the feed directly."""
        screens = list((MOBILE / "lib" / "screens").rglob("*.dart"))
        offenders = [
            p.relative_to(MOBILE).as_posix()
            for p in screens
            if "getAttendanceChanges" in p.read_text(encoding="utf-8", errors="replace")
            or "AttendanceSyncCoordinator" in p.read_text(encoding="utf-8", errors="replace")
        ]
        assert offenders == [], f"screens must read the local DB, not the feed: {offenders}"

    def test_dart_behavioural_tests_exist_even_though_unrunnable_here(self):
        """They are the deliverable that a real Flutter environment runs.
        Their absence would hide that this phase is unverified."""
        assert DART_TESTS.is_file(), "Dart behavioural tests are missing"
        body = DART_TESTS.read_text(encoding="utf-8")
        for case in (
            "cursor starts at zero",
            "INSERT delta marks the student",
            "UPDATE delta replaces the stale local value",
            "DELETE tombstone unmarks the student",
            "replay of the same delta is safe",
            "moved to another day",
            "unaddressable entry is skipped",
            "malformed sheet is left alone",
        ):
            assert case in body, f"no Dart test covers: {case}"


def test_phase_b_is_not_claimed_as_verified():
    """A guard against this phase being reported as done. The Dart has
    not been executed; the marker must stay until it has."""
    body = COORDINATOR.read_text(encoding="utf-8")
    assert "REQUIRES FLUTTER VERIFICATION" in (
        DART_TESTS.read_text(encoding="utf-8") if DART_TESTS.is_file() else ""
    ), "the Dart test file must carry the unverified marker"
    assert body  # coordinator exists


if __name__ == "__main__":
    pytest.main([__file__])
