"""S2 Goal A.6 — the v36 -> v37 upgrade path, executed against real SQLite.

WHAT THIS PROVES, AND WHAT IT DOES NOT.

The v37 *create* path was already covered (test_mobile_sync_attempt_ledger.py
builds the shipped ledger in a real SQLite database). The gap was the path
every ALREADY-INSTALLED device takes:

    existing v36 database -> onUpgrade(db, 36, 37) -> v37 database

This module closes that gap as far as this repository's frozen environment
allows, and is explicit about the boundary:

  * REAL SQLITE IS USED. Every assertion below runs against a real sqlite3
    connection, with real DDL, real rows, real PRAGMA introspection and real
    constraint enforcement. Nothing here is a text match on the migration.

  * THE PRODUCTION DART FUNCTION IS NOT EXECUTED. `LocalDb._open` calls
    sqflite's `openDatabase(... onUpgrade: ...)`. sqflite has no
    implementation on a Linux CI host: it needs an Android/iOS platform
    binding, or `sqflite_common_ffi`, which is NOT in pubspec.lock and cannot
    be added because F-19 freezes pubspec.yaml/pubspec.lock. No Dart test in
    this repository opens a database for exactly this reason.

    So the Dart control flow of the v37 branch is REPRODUCED here, while the
    SQL it executes is EXTRACTED FROM THE PRODUCTION SOURCE at test time and
    never retyped. If production's ALTER, its guard, its CREATE or its schema
    version changes, these tests change with it or fail. That is materially
    stronger than a reproduction, and still weaker than running the Dart.
    The S2 A.6 document states this in the same terms.

  * The same boundary already applies to the pre-existing create-path
    evidence, which uses this identical mechanism.

VERSION BOOKKEEPING. The app never writes the schema version itself; it
passes `version: localDatabaseSchemaVersion` to sqflite, and sqflite records
it in `PRAGMA user_version`. The harness therefore reproduces that step too,
reading the target version from the production constant rather than hardcoding
37, and the existing v34 runtime harness uses the same PRAGMA.
"""

from __future__ import annotations

import re
import sqlite3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile" / "wbws_flutter_app"
SCHEMA_FILE = MOBILE / "lib" / "services" / "local_schema_v34.dart"
DB_FILE = MOBILE / "lib" / "services" / "local_db.dart"

# The v36 ledger as it actually shipped, taken from commit 5b32fc9
# ("feat: add sync operation and attempt lineage"), which introduced the
# table, and verified against 0ed7d72 (S2 Goal A.1), which added
# `execution_source`. Recorded as a column list rather than SQL text so the
# fixture below stays derived from production rather than duplicating it.
HISTORICAL_V36_COLUMNS = (
    "id",
    "client_op_id",
    "attempt_number",
    "attempt_uid",
    "domain",
    "entity_ref",
    "owner_user_id",
    "created_authorization_version",
    "started_at",
    "finished_at",
    "duration_ms",
    "http_status",
    "error_category",
    "retry_decision",
    "failure_message",
    "next_attempt_at",
    "server_ref",
)

# Dart string literal: '...' or "...". The v37 ALTER is written as two
# adjacent literals, the second double-quoted because it contains 'foreground'.
_DART_STRING = re.compile(r"'[^']*'|\"[^\"]*\"")


def _schema_source() -> str:
    return SCHEMA_FILE.read_text(encoding="utf-8")


def _db_source() -> str:
    return DB_FILE.read_text(encoding="utf-8")


def _triple_sql(name: str) -> str:
    match = re.search(
        rf"const {name}[^=]*=\s*'''(.*?)''';", _schema_source(), re.DOTALL
    )
    assert match, f"missing production SQL constant {name}"
    return match.group(1)


def _index_sql() -> list[str]:
    block = re.search(
        r"const localSyncAttemptsV36IndexSql[^=]*=\s*<String>\[(.*?)\n\];",
        _schema_source(),
        re.DOTALL,
    )
    assert block, "missing localSyncAttemptsV36IndexSql"
    statements = re.findall(r"'''(CREATE .*?)'''", block.group(1), re.DOTALL)
    assert statements, "no index statements extracted"
    return statements


def _target_schema_version() -> int:
    match = re.search(
        r"const localDatabaseSchemaVersion\s*=\s*(\d+);", _schema_source()
    )
    assert match, "missing localDatabaseSchemaVersion"
    return int(match.group(1))


def _on_upgrade_body() -> str:
    """Just the onUpgrade function, so onCreate/onOpen cannot pollute parsing."""
    source = _db_source()
    start = source.index("onUpgrade: (db, oldVersion, newVersion) async {")
    end = source.index("onOpen: (db) async {", start)
    return source[start:end]


def _upgrade_branch_versions() -> list[int]:
    return [int(n) for n in re.findall(r"oldVersion < (\d+)", _on_upgrade_body())]


def _v37_alter_sql() -> str:
    """The ALTER exactly as production writes it, joined from its literals."""
    body = _on_upgrade_body()
    guard = body.index("if (!columns.contains('execution_source'))")
    call = body.index("await db.execute(", guard)
    end = body.index(");", call)
    pieces = _DART_STRING.findall(body[call + len("await db.execute("):end])
    assert pieces, "could not extract the v37 ALTER statement from source"
    return "".join(piece[1:-1] for piece in pieces)


def _derive_v36_create() -> str:
    """The shipped CREATE minus the one column v37 adds.

    Derived rather than vendored so it cannot drift away from production: if
    the shipped table changes, the derived v36 changes with it and the column
    assertion below is what catches an unexpected difference.
    """
    create = _triple_sql("localSyncAttemptsV36Sql")
    kept: list[str] = []
    for line in create.splitlines():
        stripped = line.strip()
        if stripped.startswith("--"):
            continue
        if "execution_source" in stripped:
            continue
        kept.append(line)
    # The column that is now last must not keep its trailing comma.
    for i in range(len(kept) - 1, -1, -1):
        stripped = kept[i].strip()
        if stripped and not stripped.startswith(")"):
            kept[i] = kept[i].rstrip().rstrip(",")
            break
    return "\n".join(kept)


def _table_exists(connection: sqlite3.Connection, table: str) -> bool:
    """Mirrors LocalDb._tableExists."""
    return (
        connection.execute(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
            (table,),
        ).fetchone()
        is not None
    )


def _column_names(connection: sqlite3.Connection, table: str) -> set[str]:
    """Mirrors LocalDb._columnNames."""
    return {row[1] for row in connection.execute(f"PRAGMA table_info({table})")}


def _v36_database(*, with_ledger: bool = True) -> sqlite3.Connection:
    """A real SQLite database in the pre-upgrade v36 state."""
    connection = sqlite3.connect(":memory:")
    connection.row_factory = sqlite3.Row
    # onConfigure does this on every open, including the upgrading one.
    connection.execute("PRAGMA foreign_keys = ON")
    if with_ledger:
        connection.execute(_derive_v36_create())
        for statement in _index_sql():
            connection.execute(statement)
    connection.execute("PRAGMA user_version = 36")
    return connection


def _seed_v36_rows(connection: sqlite3.Connection) -> None:
    """Representative pre-migration rows: closed, open, and sparse/NULL."""
    connection.executemany(
        "INSERT INTO sync_attempts "
        "(client_op_id, attempt_number, attempt_uid, domain, entity_ref, "
        " owner_user_id, created_authorization_version, started_at, "
        " finished_at, duration_ms, http_status, error_category, "
        " retry_decision, failure_message, next_attempt_at, server_ref) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            # a completed attempt
            ("op-alpha", 1, "uid-alpha-1", "pending_attendance",
             '{"id":41}', 11, 3, "2026-03-01T10:14:02.000Z",
             "2026-03-01T10:14:04.000Z", 2000, 200, None,
             "ACCEPTED", None, None, "srv-7"),
            # a retried attempt that is still waiting
            ("op-alpha", 2, "uid-alpha-2", "pending_attendance",
             '{"id":41}', 11, 3, "2026-03-01T10:20:00.000Z",
             "2026-03-01T10:20:01.500Z", 1500, 503, "transport",
             "RETRY", "upstream unavailable", "2026-03-01T10:25:00.000Z", None),
            # an open attempt with sparse columns
            ("op-beta", 1, "uid-beta-1", "pending_grades",
             None, 12, 4, "2026-03-01T11:00:00.000Z",
             None, None, None, None,
             "PENDING", None, None, None),
        ],
    )
    connection.commit()


def _run_v37_upgrade(connection: sqlite3.Connection, old_version: int) -> list[str]:
    """Reproduces onUpgrade's v37 branch, executing the SQL from production.

    The branch structure, the guards and their order are mirrored from
    `local_db.dart`; the ALTER text is extracted from it. Returns the
    operations performed so tests can assert the guard actually guarded.
    """
    performed: list[str] = []
    if old_version < 37:
        if _table_exists(connection, "sync_attempts"):
            columns = _column_names(connection, "sync_attempts")
            if "execution_source" not in columns:
                connection.execute(_v37_alter_sql())
                performed.append("alter")
    # This historical mirror executes ONLY v37, not the new v38 attendance
    # migration. Never stamp an unexecuted current version into this fixture.
    connection.execute("PRAGMA user_version = 37")
    connection.commit()
    return performed


def _snapshot(connection: sqlite3.Connection) -> list[tuple]:
    columns = ", ".join(HISTORICAL_V36_COLUMNS)
    return [
        tuple(row)
        for row in connection.execute(
            f"SELECT {columns} FROM sync_attempts ORDER BY id"
        )
    ]


class V36FixtureFidelity(unittest.TestCase):
    """The starting point must really be v36, or the test proves nothing."""

    def test_the_shipped_create_carries_the_v37_column(self):
        self.assertIn("execution_source", _triple_sql("localSyncAttemptsV36Sql"))

    def test_the_v36_fixture_does_not_carry_the_v37_column(self):
        self.assertNotIn("execution_source", _derive_v36_create())

    def test_the_v36_fixture_matches_the_historical_v36_columns(self):
        connection = _v36_database()
        self.assertEqual(
            tuple(
                row[1]
                for row in connection.execute("PRAGMA table_info(sync_attempts)")
            ),
            HISTORICAL_V36_COLUMNS,
            "the derived v36 fixture no longer matches the ledger as it "
            "shipped at v36 — review before trusting this upgrade test",
        )

    def test_the_v36_fixture_is_a_real_database_at_version_36(self):
        connection = _v36_database()
        self.assertEqual(
            connection.execute("PRAGMA user_version").fetchone()[0], 36
        )
        self.assertTrue(_table_exists(connection, "sync_attempts"))

    def test_the_fixture_carries_the_shipped_indexes(self):
        connection = _v36_database()
        names = {
            row["name"]
            for row in connection.execute("PRAGMA index_list(sync_attempts)")
        }
        for statement in _index_sql():
            expected = re.search(r"INDEX IF NOT EXISTS (\w+)", statement).group(1)
            self.assertIn(expected, names)


class UpgradeBranchSelection(unittest.TestCase):
    """Historical v37 remains; current opens also require attendance v38."""

    def test_the_migration_declares_a_v37_branch(self):
        self.assertIn(37, _upgrade_branch_versions())

    def test_a_v36_device_enters_v37_and_attendance_v38_once(self):
        entered = [v for v in _upgrade_branch_versions() if 36 < v]
        self.assertEqual(
            entered, [37, 38],
            "a v36 install must run each required v37/v38 branch exactly once",
        )

    def test_the_target_version_comes_from_the_production_constant(self):
        self.assertEqual(_target_schema_version(), 38)

    def test_the_extracted_alter_is_the_production_statement(self):
        self.assertEqual(
            _v37_alter_sql(),
            "ALTER TABLE sync_attempts ADD COLUMN execution_source "
            "TEXT NOT NULL DEFAULT 'foreground'",
        )


class PopulatedUpgrade(unittest.TestCase):
    """The case every installed device actually performs."""

    def setUp(self):
        self.connection = _v36_database()
        _seed_v36_rows(self.connection)
        self.before = _snapshot(self.connection)
        self.performed = _run_v37_upgrade(self.connection, 36)

    def test_the_migration_actually_ran(self):
        self.assertEqual(self.performed, ["alter"])

    def test_the_column_now_exists(self):
        self.assertIn("execution_source", _column_names(self.connection, "sync_attempts"))

    def test_the_column_has_the_declared_type_nullability_and_default(self):
        row = next(
            r
            for r in self.connection.execute("PRAGMA table_info(sync_attempts)")
            if r["name"] == "execution_source"
        )
        self.assertEqual(row["type"], "TEXT")
        self.assertEqual(row["notnull"], 1)
        self.assertEqual(row["dflt_value"], "'foreground'")

    def test_the_version_moved_to_37(self):
        self.assertEqual(
            self.connection.execute("PRAGMA user_version").fetchone()[0],
            37,
        )

    def test_every_pre_existing_row_survived_byte_for_byte(self):
        self.assertEqual(_snapshot(self.connection), self.before)

    def test_no_rows_were_added_or_deleted(self):
        self.assertEqual(
            self.connection.execute("SELECT COUNT(*) FROM sync_attempts").fetchone()[0],
            3,
        )

    def test_primary_keys_are_unchanged(self):
        self.assertEqual(
            [r["id"] for r in self.connection.execute(
                "SELECT id FROM sync_attempts ORDER BY id")],
            [1, 2, 3],
        )

    def test_existing_rows_are_backfilled_as_foreground(self):
        # Not a placeholder: no background execution path existed before v37,
        # so every pre-upgrade attempt was necessarily foreground.
        self.assertEqual(
            [r["execution_source"] for r in self.connection.execute(
                "SELECT execution_source FROM sync_attempts ORDER BY id")],
            ["foreground", "foreground", "foreground"],
        )

    def test_nulls_were_not_rewritten(self):
        row = self.connection.execute(
            "SELECT * FROM sync_attempts WHERE client_op_id = 'op-beta'"
        ).fetchone()
        self.assertIsNone(row["finished_at"])
        self.assertIsNone(row["entity_ref"])
        self.assertIsNone(row["server_ref"])

    def test_the_indexes_survived_the_migration(self):
        names = {
            row["name"]
            for row in self.connection.execute("PRAGMA index_list(sync_attempts)")
        }
        for statement in _index_sql():
            expected = re.search(r"INDEX IF NOT EXISTS (\w+)", statement).group(1)
            self.assertIn(expected, names)

    def test_the_identity_constraint_is_still_enforced(self):
        with self.assertRaises(sqlite3.IntegrityError):
            self.connection.execute(
                "INSERT INTO sync_attempts "
                "(client_op_id, attempt_number, attempt_uid, domain, started_at) "
                "VALUES ('op-alpha', 1, 'uid-dup', 'pending_attendance', 'now')"
            )

    def test_the_database_passes_an_integrity_check(self):
        self.assertEqual(
            self.connection.execute("PRAGMA integrity_check").fetchone()[0], "ok"
        )


class PostUpgradeUse(unittest.TestCase):
    """The upgraded database must support the v37 functionality."""

    def setUp(self):
        self.connection = _v36_database()
        _seed_v36_rows(self.connection)
        _run_v37_upgrade(self.connection, 36)

    def test_a_background_attempt_can_be_written_and_read_back(self):
        self.connection.execute(
            "INSERT INTO sync_attempts "
            "(client_op_id, attempt_number, attempt_uid, domain, owner_user_id, "
            " started_at, retry_decision, execution_source) "
            "VALUES ('op-gamma', 1, 'uid-gamma-1', 'pending_attendance', 11, "
            "        '2026-03-02T08:00:00.000Z', 'PENDING', 'background')"
        )
        self.connection.commit()
        row = self.connection.execute(
            "SELECT execution_source FROM sync_attempts "
            "WHERE client_op_id = 'op-gamma' AND attempt_number = 1"
        ).fetchone()
        self.assertEqual(row["execution_source"], "background")

    def test_an_insert_that_omits_the_column_takes_the_default(self):
        self.connection.execute(
            "INSERT INTO sync_attempts "
            "(client_op_id, attempt_number, attempt_uid, domain, started_at) "
            "VALUES ('op-delta', 1, 'uid-delta-1', 'pending_grades', 'now')"
        )
        self.connection.commit()
        self.assertEqual(
            self.connection.execute(
                "SELECT execution_source FROM sync_attempts "
                "WHERE client_op_id = 'op-delta'"
            ).fetchone()["execution_source"],
            "foreground",
        )

    def test_the_column_rejects_null(self):
        with self.assertRaises(sqlite3.IntegrityError):
            self.connection.execute(
                "INSERT INTO sync_attempts "
                "(client_op_id, attempt_number, attempt_uid, domain, started_at, "
                " execution_source) "
                "VALUES ('op-eps', 1, 'uid-eps-1', 'pending_hr', 'now', NULL)"
            )

    def test_rows_can_be_partitioned_by_execution_source(self):
        self.connection.execute(
            "INSERT INTO sync_attempts "
            "(client_op_id, attempt_number, attempt_uid, domain, started_at, "
            " execution_source) "
            "VALUES ('op-zeta', 1, 'uid-zeta-1', 'pending_attendance', 'now', "
            "        'background')"
        )
        self.connection.commit()
        counts = dict(
            self.connection.execute(
                "SELECT execution_source, COUNT(*) FROM sync_attempts "
                "GROUP BY execution_source"
            ).fetchall()
        )
        self.assertEqual(counts, {"foreground": 3, "background": 1})

    def test_the_operation_lineage_query_still_works(self):
        rows = self.connection.execute(
            "SELECT attempt_number, execution_source FROM sync_attempts "
            "WHERE client_op_id = 'op-alpha' ORDER BY attempt_number"
        ).fetchall()
        self.assertEqual(
            [(r["attempt_number"], r["execution_source"]) for r in rows],
            [(1, "foreground"), (2, "foreground")],
        )


class UpgradeEdgeCases(unittest.TestCase):
    """The guards the production branch actually declares."""

    def test_an_empty_v36_database_upgrades_cleanly(self):
        connection = _v36_database()
        performed = _run_v37_upgrade(connection, 36)
        self.assertEqual(performed, ["alter"])
        self.assertIn("execution_source", _column_names(connection, "sync_attempts"))
        self.assertEqual(
            connection.execute("SELECT COUNT(*) FROM sync_attempts").fetchone()[0], 0
        )

    def test_a_v36_install_that_already_has_the_column_is_left_alone(self):
        # The production comment states this case explicitly: a v36 install
        # may have been created by _createSyncAttemptLedger after that helper
        # started shipping the column, and ALTERing twice is an error.
        connection = sqlite3.connect(":memory:")
        connection.row_factory = sqlite3.Row
        connection.execute(_triple_sql("localSyncAttemptsV36Sql"))  # shipped CREATE
        for statement in _index_sql():
            connection.execute(statement)
        connection.execute("PRAGMA user_version = 36")

        performed = _run_v37_upgrade(connection, 36)

        self.assertEqual(performed, [], "the column probe must suppress the ALTER")
        self.assertEqual(
            sum(
                1
                for row in connection.execute("PRAGMA table_info(sync_attempts)")
                if row["name"] == "execution_source"
            ),
            1,
        )

    def test_a_database_without_the_ledger_is_not_altered(self):
        # _tableExists guards the whole branch.
        connection = _v36_database(with_ledger=False)
        performed = _run_v37_upgrade(connection, 36)
        self.assertEqual(performed, [])
        self.assertFalse(_table_exists(connection, "sync_attempts"))

    def test_a_device_already_on_37_does_not_run_the_branch(self):
        connection = sqlite3.connect(":memory:")
        connection.row_factory = sqlite3.Row
        connection.execute(_triple_sql("localSyncAttemptsV36Sql"))
        connection.execute("PRAGMA user_version = 37")
        performed = _run_v37_upgrade(connection, 37)
        self.assertEqual(performed, [])



class MigrationSourceContract(unittest.TestCase):
    """Pins the Dart control flow this harness REPRODUCES.

    The SQL below is extracted from production, so an SQL change cannot pass
    unnoticed. The surrounding Dart logic is mirrored in Python, and a mirror
    cannot detect a change to the thing it mirrors — inverting the production
    guard would leave every runtime test above green. These pins close exactly
    that hole, and keep the mirror honest by asserting the production helpers
    still issue the SQL the mirror assumes.
    """

    def setUp(self):
        self.upgrade = _on_upgrade_body()
        self.db_source = _db_source()

    def test_the_v37_branch_is_keyed_on_oldversion_37(self):
        self.assertIn("if (oldVersion < 37) {", self.upgrade)

    def test_the_branch_is_guarded_by_a_table_probe(self):
        self.assertIn(
            "if (await _tableExists(db, 'sync_attempts')) {", self.upgrade
        )

    def test_the_alter_is_guarded_by_a_column_probe(self):
        self.assertIn("final columns = await _columnNames(db, 'sync_attempts');",
                      self.upgrade)
        self.assertIn("if (!columns.contains('execution_source')) {",
                      self.upgrade)

    def test_the_column_probe_is_not_inverted(self):
        # A missing "!" would make an upgrading v36 device skip the ALTER and
        # a v37 device run it twice. Both are silent in a mirrored harness.
        self.assertNotIn("if (columns.contains('execution_source')) {",
                         self.upgrade)

    def test_the_v37_branch_performs_exactly_one_statement(self):
        branch = self.upgrade[self.upgrade.index("if (oldVersion < 37) {"):]
        self.assertEqual(branch.count("await db.execute("), 1)

    def test_the_table_probe_sql_matches_the_mirror(self):
        self.assertIn(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
            self.db_source,
        )

    def test_the_column_probe_sql_matches_the_mirror(self):
        self.assertIn("PRAGMA table_info($table)", self.db_source)

    def test_the_version_is_handed_to_sqflite_not_written_by_the_app(self):
        self.assertIn("version: localDatabaseSchemaVersion,", self.db_source)
        self.assertNotIn("PRAGMA user_version =", self.db_source)


if __name__ == "__main__":
    unittest.main()
