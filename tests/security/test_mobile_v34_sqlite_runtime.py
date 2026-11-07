"""Binding SQLite v34 migration and operation-identity runtime tests.

The sandbox has no Dart/Flutter executable.  This harness therefore executes
real SQLite 3 through Python while loading the production column, table-key,
session-table, and index declarations from local_schema_v34.dart.  Static
source checks below supplement (and do not replace) the runtime interleavings.
"""

from __future__ import annotations

import json
import re
import sqlite3
import uuid
from pathlib import Path
from typing import Any

import pytest

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile" / "wbws_flutter_app"
SCHEMA_SOURCE = MOBILE / "lib" / "services" / "local_schema_v34.dart"
DB_SOURCE = MOBILE / "lib" / "services" / "local_db.dart"

LEGACY_KEYS = {
    "pending_attendance": ("class_id", "date"),
    "pending_grades": ("assessment_id",),
    "pending_mezmur": ("date", "section"),
    "pending_hr": ("date", "section"),
}


def _schema_source() -> str:
    return SCHEMA_SOURCE.read_text(encoding="utf-8")


def _column_specs() -> list[tuple[str, str, str]]:
    source = _schema_source()
    pattern = re.compile(
        r"LocalColumnSpec\('([^']+)', '([^']+)',\s*(['\"])(.*?)\3\)",
        re.DOTALL,
    )
    specs = [(m.group(1), m.group(2), m.group(4)) for m in pattern.finditer(source)]
    assert len(specs) == 55, "the runtime harness must see every production v34 column"
    return specs


def _triple_sql(name: str) -> str:
    source = _schema_source()
    match = re.search(rf"const {name}[^=]*=\s*'''(.*?)''';", source, re.DOTALL)
    assert match, f"missing production SQL constant {name}"
    return match.group(1)


def _index_sql() -> list[str]:
    source = _schema_source()
    block_match = re.search(
        r"const localV34IndexSql[^=]*=\s*<String>\[(.*?)\n\];",
        source,
        re.DOTALL,
    )
    assert block_match
    statements = re.findall(r"'''(CREATE INDEX.*?)'''", block_match.group(1), re.DOTALL)
    assert len(statements) == 18
    return statements


def _columns(connection: sqlite3.Connection, table: str) -> set[str]:
    return {row[1] for row in connection.execute(f"PRAGMA table_info({table})")}


def _create_legacy_table(
    connection: sqlite3.Connection,
    table: str,
    *,
    include_client_op_id: bool = True,
    include_section: bool = True,
) -> None:
    if table == "pending_attendance":
        key = "class_id INTEGER NOT NULL, date TEXT NOT NULL,"
    elif table == "pending_grades":
        key = "assessment_id INTEGER NOT NULL,"
    elif include_section:
        key = "date TEXT NOT NULL, section TEXT NOT NULL DEFAULT '',"
    else:
        key = "date TEXT NOT NULL,"
    client_op_id = "client_op_id TEXT," if include_client_op_id else ""
    connection.execute(
        f"""
        CREATE TABLE IF NOT EXISTS {table} (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          {key}
          member_id INTEGER NOT NULL,
          payload_text TEXT NOT NULL,
          packet_kind TEXT NOT NULL DEFAULT 'draft',
          {client_op_id}
          synced INTEGER NOT NULL DEFAULT 0,
          created_at TEXT NOT NULL,
          synced_at TEXT,
          sync_error TEXT
        )
        """
    )


def _create_hymn_table(connection: sqlite3.Connection) -> None:
    connection.execute(
        """
        CREATE TABLE IF NOT EXISTS pending_hymn_ops (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          op TEXT NOT NULL,
          payload_json TEXT NOT NULL,
          client_op_id TEXT,
          created_at TEXT NOT NULL,
          synced INTEGER NOT NULL DEFAULT 0,
          synced_at TEXT,
          sync_error TEXT
        )
        """
    )


def _create_comm_tables(connection: sqlite3.Connection) -> None:
    connection.executescript(
        """
        CREATE TABLE IF NOT EXISTS comm_outbox (
          client_tag TEXT PRIMARY KEY,
          thread_id INTEGER NOT NULL,
          body TEXT NOT NULL,
          state TEXT NOT NULL DEFAULT 'pending',
          attempts INTEGER NOT NULL DEFAULT 0,
          next_attempt_at TEXT,
          created_at TEXT NOT NULL,
          fail_reason TEXT
        );
        CREATE TABLE IF NOT EXISTS comm_drafts (
          thread_id INTEGER PRIMARY KEY,
          body TEXT NOT NULL DEFAULT '',
          updated_at TEXT
        );
        """
    )


def _upgrade_fixture_to_v33(connection: sqlite3.Connection, from_version: int) -> None:
    """Build a representative old queue schema, then run its v33 bridge."""
    # v6 predates client_op_id; v7 has the two original operation-id columns.
    _create_legacy_table(
        connection,
        "pending_attendance",
        include_client_op_id=from_version >= 7,
    )
    _create_legacy_table(
        connection,
        "pending_grades",
        include_client_op_id=from_version >= 7,
    )
    # v9 introduced Mezmur without section; v10 made it section-scoped.
    if from_version >= 9:
        _create_legacy_table(
            connection,
            "pending_mezmur",
            include_section=from_version >= 10,
        )
    if from_version >= 11:
        _create_hymn_table(connection)
    if from_version >= 12:
        _create_legacy_table(connection, "pending_hr")
    if from_version >= 26:
        _create_comm_tables(connection)

    # Representative historical bridge to the pre-v34 queue shape.
    for table in ("pending_attendance", "pending_grades"):
        if "client_op_id" not in _columns(connection, table):
            connection.execute(f"ALTER TABLE {table} ADD COLUMN client_op_id TEXT")
    if not connection.execute(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='pending_mezmur'"
    ).fetchone():
        _create_legacy_table(connection, "pending_mezmur")
    elif "section" not in _columns(connection, "pending_mezmur"):
        connection.execute(
            "ALTER TABLE pending_mezmur "
            "ADD COLUMN section TEXT NOT NULL DEFAULT ''"
        )
    if not connection.execute(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='pending_hr'"
    ).fetchone():
        _create_legacy_table(connection, "pending_hr")
    if not connection.execute(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='pending_hymn_ops'"
    ).fetchone():
        _create_hymn_table(connection)
    _create_comm_tables(connection)
    connection.execute("PRAGMA user_version = 33")


def _packet_kind(row: sqlite3.Row) -> str:
    value = str(row["packet_kind"] or "draft").strip().lower()
    return value or "draft"


def _business_key(row: sqlite3.Row, columns: tuple[str, ...]) -> str:
    return json.dumps([row[column] for column in columns], separators=(",", ":"))


def apply_v34_migration(connection: sqlite3.Connection, now: str = "2026-09-24T00:00:00Z") -> None:
    """Execute the production-declared v34 schema with the reviewed mapping."""
    connection.row_factory = sqlite3.Row
    with connection:
        for table, name, declaration in _column_specs():
            if not connection.execute(
                "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)
            ).fetchone():
                continue
            if name not in _columns(connection, table):
                connection.execute(f"ALTER TABLE {table} ADD COLUMN {name} {declaration}")

        connection.execute(_triple_sql("localSessionStateV34Sql"))
        connection.execute(
            """
            INSERT OR IGNORE INTO local_session_state
              (id, state, generation, updated_at)
            VALUES (1, 'anonymous_clean', 0, ?)
            """,
            (now,),
        )

        for table, keys in LEGACY_KEYS.items():
            connection.execute(
                f"UPDATE {table} SET sync_state='synced' "
                "WHERE synced=1 AND sync_state<>'synced'"
            )
            connection.execute(
                f"""
                UPDATE {table}
                SET sync_state='needs_attention',
                    failure_code=COALESCE(failure_code, 'LEGACY_REJECTION'),
                    failed_at=COALESCE(failed_at, ?)
                WHERE synced=0 AND sync_error IS NOT NULL AND sync_state='pending'
                """,
                (now,),
            )

            rows = connection.execute(
                f"SELECT * FROM {table} WHERE synced=0 ORDER BY id"
            ).fetchall()
            groups: dict[str, list[sqlite3.Row]] = {}
            for row in rows:
                groups.setdefault(_business_key(row, keys), []).append(row)
            for group in groups.values():
                ids = {
                    str(row["client_op_id"] or "")
                    for row in group
                    if str(row["client_op_id"] or "").strip()
                }
                blanks = [
                    row
                    for row in group
                    if not str(row["client_op_id"] or "").strip()
                ]
                kinds = {_packet_kind(row) for row in group}
                valid_kind = len(kinds) == 1 and next(iter(kinds)) in {"draft", "submitted"}
                coherent = valid_kind and (not ids or (len(ids) == 1 and not blanks))
                if coherent and not ids:
                    generated = str(uuid.uuid4())
                    connection.executemany(
                        f"UPDATE {table} SET client_op_id=? WHERE id=? AND synced=0",
                        [(generated, row["id"]) for row in blanks],
                    )
                elif not coherent:
                    for row in group:
                        existing = str(row["client_op_id"] or "")
                        generated = existing if existing.strip() else str(uuid.uuid4())
                        connection.execute(
                            f"""
                            UPDATE {table}
                            SET client_op_id=?, sync_state='needs_attention',
                                failure_code='LEGACY_MIXED_OPERATION_SET',
                                failed_at=COALESCE(failed_at, ?)
                            WHERE id=? AND synced=0
                            """,
                            (generated, now, row["id"]),
                        )

        # Quarantine ids reused across a table/key/kind identity boundary.
        identities: dict[str, set[str]] = {}
        locations: dict[str, set[str]] = {}
        for table, keys in LEGACY_KEYS.items():
            for row in connection.execute(f"SELECT * FROM {table} WHERE synced=0"):
                op_id = str(row["client_op_id"] or "")
                if not op_id.strip():
                    continue
                identity = f"{table}|{_business_key(row, keys)}|{_packet_kind(row)}"
                identities.setdefault(op_id, set()).add(identity)
                locations.setdefault(op_id, set()).add(table)
        for op_id, identity_set in identities.items():
            if len(identity_set) <= 1:
                continue
            for table in locations[op_id]:
                connection.execute(
                    f"""
                    UPDATE {table}
                    SET sync_state='needs_attention',
                        failure_code='LEGACY_MIXED_OPERATION_SET',
                        failed_at=COALESCE(failed_at, ?)
                    WHERE client_op_id=? AND synced=0
                    """,
                    (now, op_id),
                )

        connection.execute(
            "UPDATE pending_hymn_ops SET sync_state='synced' "
            "WHERE synced=1 AND sync_state<>'synced'"
        )
        for row in connection.execute(
            "SELECT id FROM pending_hymn_ops WHERE synced=0 "
            "AND (client_op_id IS NULL OR TRIM(client_op_id)='')"
        ):
            connection.execute(
                "UPDATE pending_hymn_ops SET client_op_id=? WHERE id=?",
                (str(uuid.uuid4()), row["id"]),
            )
        connection.execute(
            """
            UPDATE comm_outbox
            SET failure_code=COALESCE(failure_code, 'LEGACY_COMM_FAILURE'),
                failed_at=COALESCE(failed_at, ?)
            WHERE state='failed'
            """,
            (now,),
        )
        for sql in _index_sql():
            connection.execute(sql)
        connection.execute("PRAGMA user_version = 34")


def recover_in_flight(connection: sqlite3.Connection, now: str) -> None:
    with connection:
        for table in LEGACY_KEYS:
            connection.execute(
                f"UPDATE {table} SET sync_state='retry_wait', next_attempt_at=? "
                "WHERE synced=0 AND sync_state='in_flight'",
                (now,),
            )
        connection.execute(
            "UPDATE pending_hymn_ops SET sync_state='retry_wait', next_attempt_at=? "
            "WHERE synced=0 AND sync_state='in_flight'",
            (now,),
        )
        connection.execute(
            "UPDATE comm_outbox SET state='retry_wait', next_attempt_at=? "
            "WHERE state='in_flight'",
            (now,),
        )


def insert_packet(
    connection: sqlite3.Connection,
    table: str,
    key: tuple[Any, ...],
    op_id: str,
    payloads: tuple[str, ...],
    *,
    state: str = "pending",
    owner: int = 17,
    authorization_version: int = 4,
    packet_kind: str = "draft",
) -> None:
    key_columns = LEGACY_KEYS[table]
    columns = [*key_columns, "member_id", "payload_text", "packet_kind", "client_op_id", "created_at"]
    if "sync_state" in _columns(connection, table):
        columns += ["sync_state", "owner_user_id", "created_authorization_version"]
    marks = ",".join("?" for _ in columns)
    for number, payload in enumerate(payloads, start=1):
        values: list[Any] = [*key, number, payload, packet_kind, op_id, "2026-09-24T00:00:00Z"]
        if "sync_state" in columns:
            values += [state, owner, authorization_version]
        connection.execute(
            f"INSERT INTO {table} ({','.join(columns)}) VALUES ({marks})", values
        )


def claim_packet(
    connection: sqlite3.Connection,
    table: str,
    owner: int,
    authorization_version: int,
    now: str,
) -> dict[str, Any] | None:
    connection.row_factory = sqlite3.Row
    with connection:
        candidate = connection.execute(
            f"""
            SELECT client_op_id
            FROM {table}
            WHERE synced=0 AND sync_state IN ('pending','retry_wait')
              AND (next_attempt_at IS NULL OR next_attempt_at<=?)
              AND owner_user_id=? AND created_authorization_version=?
              AND client_op_id IS NOT NULL AND TRIM(client_op_id)<>''
            GROUP BY client_op_id
            ORDER BY MIN(created_at), MIN(id)
            LIMIT 1
            """,
            (now, owner, authorization_version),
        ).fetchone()
        if candidate is None:
            return None
        op_id = candidate["client_op_id"]
        rows = connection.execute(
            f"SELECT * FROM {table} WHERE client_op_id=? AND synced=0 ORDER BY id",
            (op_id,),
        ).fetchall()
        keys = LEGACY_KEYS[table]
        assert len({_business_key(row, keys) for row in rows}) == 1
        assert len({_packet_kind(row) for row in rows}) == 1
        states = {row["sync_state"] for row in rows}
        assert len(states) == 1 and next(iter(states)) in {"pending", "retry_wait"}
        affected = connection.execute(
            f"""
            UPDATE {table}
            SET sync_state='in_flight', attempt_count=attempt_count+1,
                last_attempt_at=?, next_attempt_at=NULL
            WHERE client_op_id=? AND synced=0 AND sync_state=?
              AND owner_user_id=? AND created_authorization_version=?
            """,
            (now, op_id, next(iter(states)), owner, authorization_version),
        ).rowcount
        assert affected == len(rows)
        snapshot = connection.execute(
            f"SELECT * FROM {table} WHERE client_op_id=? AND sync_state='in_flight' ORDER BY id",
            (op_id,),
        ).fetchall()
        return {
            "table": table,
            "op_id": op_id,
            "key": tuple(snapshot[0][column] for column in keys),
            "owner": owner,
            "authorization_version": authorization_version,
            "claimed_at": now,
            "rows": [dict(row) for row in snapshot],
        }


def settle_packet(
    connection: sqlite3.Connection,
    claim: dict[str, Any],
    state: str,
    *,
    current_owner: int = 17,
    current_authorization_version: int = 4,
    failure_code: str | None = None,
) -> str:
    if current_owner != claim["owner"] or current_authorization_version != claim["authorization_version"]:
        return "supersededSession"
    table = claim["table"]
    keys = LEGACY_KEYS[table]
    key_where = " AND ".join(f"{column}=?" for column in keys)
    args = [
        claim["op_id"],
        claim["claimed_at"],
        claim["owner"],
        claim["authorization_version"],
        *claim["key"],
    ]
    exact = (
        "client_op_id=? AND synced=0 AND sync_state='in_flight' "
        "AND last_attempt_at=? AND owner_user_id=? "
        "AND created_authorization_version=? AND " + key_where
    )
    with connection:
        count = connection.execute(
            f"SELECT COUNT(*) FROM {table} WHERE {exact}", args
        ).fetchone()[0]
        if count != len(claim["rows"]):
            return "supersededLocal"
        if state == "synced":
            affected = connection.execute(
                f"UPDATE {table} SET sync_state='synced', synced=1, "
                f"synced_at='2026-09-24T00:01:00Z' WHERE {exact}",
                args,
            ).rowcount
        else:
            affected = connection.execute(
                f"UPDATE {table} SET sync_state=?, failure_code=?, "
                f"failed_at='2026-09-24T00:01:00Z' WHERE {exact}",
                [state, failure_code, *args],
            ).rowcount
        assert affected == count
        return "applied"


def _connection(version: int = 33) -> sqlite3.Connection:
    connection = sqlite3.connect(":memory:")
    connection.row_factory = sqlite3.Row
    _upgrade_fixture_to_v33(connection, version)
    return connection


def _schema_signature(connection: sqlite3.Connection) -> dict[str, Any]:
    managed = {(table, name) for table, name, _ in _column_specs()}
    session_columns = {
        "id",
        "owner_user_id",
        "owner_username",
        "owner_display_name",
        "owner_role",
        "owner_authorization_version",
        "state",
        "reason",
        "generation",
        "updated_at",
    }
    tables = [*LEGACY_KEYS, "pending_hymn_ops", "comm_outbox", "comm_drafts", "local_session_state"]
    columns: dict[str, list[tuple[Any, ...]]] = {}
    for table in tables:
        rows = connection.execute(f"PRAGMA table_info({table})")
        columns[table] = sorted(
            (row[1], row[2], row[3], row[4], row[5])
            for row in rows
            if (table, row[1]) in managed
            or (table == "local_session_state" and row[1] in session_columns)
        )
    return {
        "columns": columns,
        "indexes": sorted(
            row[0]
            for row in connection.execute(
                "SELECT name FROM sqlite_master WHERE type='index' AND name LIKE 'idx_%'"
            )
        ),
    }


@pytest.mark.parametrize("from_version", [6, 7, 9, 10, 12, 20, 26, 33])
def test_representative_upgrade_versions_reach_v34(from_version: int) -> None:
    connection = _connection(from_version)
    representative_keys = {
        "pending_attendance": (4, "2026-09-24"),
        "pending_grades": (8,),
        "pending_mezmur": ("2026-09-24", "choir"),
        "pending_hr": ("2026-09-24", "staff"),
    }
    for table, key in representative_keys.items():
        insert_packet(connection, table, key, "", (f"payload-from-v{from_version}",))
    connection.execute(
        "INSERT INTO pending_hymn_ops(op,payload_json,created_at) VALUES(?,?,?)",
        ("hymn_save", '{"title":"kept"}', "2026-09-24T00:00:00Z"),
    )
    connection.execute(
        "INSERT INTO comm_outbox(client_tag,thread_id,body,state,created_at) VALUES(?,?,?,?,?)",
        ("tag", 1, "kept", "pending", "2026-09-24T00:00:00Z"),
    )
    apply_v34_migration(connection)
    assert connection.execute("PRAGMA user_version").fetchone()[0] == 34
    assert connection.execute("SELECT state FROM local_session_state WHERE id=1").fetchone()[0] == "anonymous_clean"
    for table in LEGACY_KEYS:
        assert {"sync_state", "owner_user_id", "created_authorization_version"} <= _columns(connection, table)
        row = connection.execute(
            f"SELECT payload_text,client_op_id,sync_state FROM {table}"
        ).fetchone()
        assert row[0] == f"payload-from-v{from_version}"
        assert row[1]
        assert row[2] == "pending"
    assert {"created_by_user_id", "entity_key", "depends_on"} <= _columns(connection, "pending_hymn_ops")
    assert {"owner_user_id", "failure_code", "last_attempt_at"} <= _columns(connection, "comm_outbox")


def test_migration_maps_states_reconciles_ids_and_preserves_payload_bytes() -> None:
    connection = _connection()
    insert_packet(connection, "pending_attendance", (4, "2026-09-24"), "", ("a\x00", "b\n"))
    insert_packet(connection, "pending_attendance", (5, "2026-09-23"), "synced-op", ("sent",))
    connection.execute(
        "UPDATE pending_attendance SET synced=1,synced_at='2026-09-23T00:01:00Z' "
        "WHERE client_op_id='synced-op'"
    )
    insert_packet(connection, "pending_grades", (8,), "z-op", ("score-a",))
    insert_packet(connection, "pending_grades", (8,), "a-op", ("score-b",))
    insert_packet(connection, "pending_mezmur", ("2026-09-24", "choir"), "same-op", ("m1",), packet_kind="draft")
    insert_packet(connection, "pending_mezmur", ("2026-09-24", "choir"), "same-op", ("m2",), packet_kind="submitted")
    insert_packet(connection, "pending_hr", ("2026-09-24", "staff"), "f8-op", ("h1",))
    connection.execute("UPDATE pending_hr SET sync_error='Already submitted' WHERE client_op_id='f8-op'")
    connection.execute(
        "INSERT INTO pending_hymn_ops(op,payload_json,client_op_id,created_at,sync_error) VALUES(?,?,?,?,?)",
        ("hymn_save", '{"raw":"payload"}', "", "2026-09-24T00:00:00Z", "network"),
    )
    connection.execute(
        "INSERT INTO pending_hymn_ops(op,payload_json,client_op_id,created_at,synced,synced_at) "
        "VALUES(?,?,?,?,1,?)",
        (
            "hymn_save",
            '{"raw":"sent"}',
            "sent-hymn",
            "2026-09-23T00:00:00Z",
            "2026-09-23T00:01:00Z",
        ),
    )
    connection.execute(
        "INSERT INTO comm_outbox(client_tag,thread_id,body,state,created_at,fail_reason) VALUES(?,?,?,?,?,?)",
        ("tag-f", 1, "body", "failed", "2026-09-24T00:00:00Z", "old reason"),
    )
    before_payloads = {
        table: [row[0] for row in connection.execute(f"SELECT payload_text FROM {table} ORDER BY id")]
        for table in LEGACY_KEYS
    }

    apply_v34_migration(connection)

    after_payloads = {
        table: [row[0] for row in connection.execute(f"SELECT payload_text FROM {table} ORDER BY id")]
        for table in LEGACY_KEYS
    }
    assert after_payloads == before_payloads
    attendance = connection.execute(
        "SELECT client_op_id,sync_state FROM pending_attendance "
        "WHERE synced=0 ORDER BY id"
    ).fetchall()
    assert attendance[0][0] and attendance[0][0] == attendance[1][0]
    assert {row[1] for row in attendance} == {"pending"}
    assert connection.execute(
        "SELECT sync_state FROM pending_attendance WHERE synced=1"
    ).fetchone()[0] == "synced"

    grades = connection.execute(
        "SELECT client_op_id,sync_state,failure_code FROM pending_grades ORDER BY id"
    ).fetchall()
    assert [row[0] for row in grades] == ["z-op", "a-op"]
    assert {(row[1], row[2]) for row in grades} == {
        ("needs_attention", "LEGACY_MIXED_OPERATION_SET")
    }
    mezmur = connection.execute(
        "SELECT sync_state,failure_code FROM pending_mezmur"
    ).fetchall()
    assert {(row[0], row[1]) for row in mezmur} == {
        ("needs_attention", "LEGACY_MIXED_OPERATION_SET")
    }
    hr = connection.execute(
        "SELECT client_op_id,sync_state,failure_code,sync_error FROM pending_hr"
    ).fetchone()
    assert tuple(hr) == ("f8-op", "needs_attention", "LEGACY_REJECTION", "Already submitted")
    hymn = connection.execute(
        "SELECT client_op_id,sync_state,sync_error,payload_json "
        "FROM pending_hymn_ops WHERE synced=0"
    ).fetchone()
    assert hymn[0] and tuple(hymn[1:]) == ("pending", "network", '{"raw":"payload"}')
    assert connection.execute(
        "SELECT sync_state FROM pending_hymn_ops WHERE synced=1"
    ).fetchone()[0] == "synced"
    comm = connection.execute(
        "SELECT state,failure_code,fail_reason FROM comm_outbox"
    ).fetchone()
    assert tuple(comm) == ("failed", "LEGACY_COMM_FAILURE", "old reason")
    for table in [*LEGACY_KEYS, "comm_outbox", "comm_drafts"]:
        owner_column = "owner_user_id"
        if owner_column in _columns(connection, table):
            assert connection.execute(
                f"SELECT COUNT(*) FROM {table} WHERE {owner_column} IS NOT NULL"
            ).fetchone()[0] == 0


def test_reused_and_partially_blank_operation_ids_are_quarantined_without_merge() -> None:
    connection = _connection()
    insert_packet(
        connection,
        "pending_attendance",
        (4, "2026-09-24"),
        "reused-id",
        ("attendance",),
    )
    insert_packet(
        connection,
        "pending_hr",
        ("2026-09-24", "staff"),
        "reused-id",
        ("hr",),
    )
    insert_packet(connection, "pending_grades", (8,), "existing-id", ("grade-a",))
    insert_packet(connection, "pending_grades", (8,), "", ("grade-b",))

    apply_v34_migration(connection)

    for table in ("pending_attendance", "pending_hr"):
        row = connection.execute(
            f"SELECT client_op_id,sync_state,failure_code,payload_text FROM {table}"
        ).fetchone()
        assert tuple(row[:3]) == (
            "reused-id",
            "needs_attention",
            "LEGACY_MIXED_OPERATION_SET",
        )
        assert row[3] in {"attendance", "hr"}
    grades = connection.execute(
        "SELECT client_op_id,sync_state,failure_code,payload_text "
        "FROM pending_grades ORDER BY id"
    ).fetchall()
    assert grades[0][0] == "existing-id"
    assert grades[1][0] and grades[1][0] != "existing-id"
    assert {tuple(row[1:3]) for row in grades} == {
        ("needs_attention", "LEGACY_MIXED_OPERATION_SET")
    }
    assert [row[3] for row in grades] == ["grade-a", "grade-b"]


def test_migration_is_repeat_safe_and_fresh_schema_is_equivalent() -> None:
    upgraded = _connection()
    insert_packet(upgraded, "pending_grades", (9,), "a", ("one",))
    insert_packet(upgraded, "pending_grades", (9,), "b", ("two",))
    apply_v34_migration(upgraded)
    first_rows = list(upgraded.iterdump())
    apply_v34_migration(upgraded, now="2026-09-25T00:00:00Z")
    assert list(upgraded.iterdump()) == first_rows

    # A fresh database uses the canonical production CREATE TABLE bodies, then
    # the same repeat-safe v34 finalizer for the session row and indexes.
    fresh = sqlite3.connect(":memory:")
    fresh.row_factory = sqlite3.Row
    source = DB_SOURCE.read_text(encoding="utf-8")
    for table in [*LEGACY_KEYS, "pending_hymn_ops", "comm_outbox", "comm_drafts"]:
        bodies = re.findall(
            rf"CREATE TABLE(?: IF NOT EXISTS)? {table} \((.*?)\n\s*\)", source, re.DOTALL
        )
        assert bodies, table
        body = max(bodies, key=len)
        fresh.execute(f"CREATE TABLE {table} ({body})")
    apply_v34_migration(fresh)
    assert _schema_signature(fresh) == _schema_signature(upgraded)


def test_indexes_are_used_for_exact_operation_and_owner_due_queries() -> None:
    connection = _connection()
    apply_v34_migration(connection)
    plan = " ".join(
        str(cell)
        for row in connection.execute(
            "EXPLAIN QUERY PLAN SELECT * FROM pending_attendance "
            "WHERE client_op_id=? AND sync_state=? AND synced=0",
            ("op", "in_flight"),
        )
        for cell in row
    )
    assert "idx_pending_attendance_operation" in plan
    due_plan = " ".join(
        str(cell)
        for row in connection.execute(
            "EXPLAIN QUERY PLAN SELECT * FROM pending_attendance "
            "WHERE owner_user_id=? AND sync_state=? AND next_attempt_at<=?",
            (17, "retry_wait", "2026-09-24T00:00:00Z"),
        )
        for cell in row
    )
    assert "idx_pending_attendance_owner_due" in due_plan


@pytest.mark.parametrize("table,key", [
    ("pending_attendance", (4, "2026-09-24")),
    ("pending_grades", (8,)),
    ("pending_mezmur", ("2026-09-24", "choir")),
    ("pending_hr", ("2026-09-24", "staff")),
])
def test_claim_replace_then_accept_or_reject_cannot_mutate_replacement(
    table: str, key: tuple[Any, ...]
) -> None:
    for terminal, code in (("synced", None), ("needs_attention", "WORKFLOW_REJECTED")):
        connection = _connection()
        apply_v34_migration(connection)
        insert_packet(connection, table, key, "operation-a", ("A1", "A2"))
        claim = claim_packet(connection, table, 17, 4, "2026-09-24T00:00:01Z")
        assert claim and [row["payload_text"] for row in claim["rows"]] == ["A1", "A2"]
        with connection:
            connection.execute(f"DELETE FROM {table} WHERE synced=0 AND " + " AND ".join(f"{c}=?" for c in LEGACY_KEYS[table]), key)
            insert_packet(connection, table, key, "operation-b", ("B1", "B2"))
        assert settle_packet(connection, claim, terminal, failure_code=code) == "supersededLocal"
        replacement = connection.execute(
            f"SELECT client_op_id,payload_text,sync_state,synced,failure_code FROM {table} ORDER BY id"
        ).fetchall()
        assert [tuple(row) for row in replacement] == [
            ("operation-b", "B1", "pending", 0, None),
            ("operation-b", "B2", "pending", 0, None),
        ]


@pytest.mark.parametrize("table,key", [
    ("pending_attendance", (4, "2026-09-24")),
    ("pending_grades", (8,)),
    ("pending_mezmur", ("2026-09-24", "choir")),
    ("pending_hr", ("2026-09-24", "staff")),
])
def test_replacement_is_scoped_and_preserves_other_authorization_generation(
    table: str, key: tuple[Any, ...]
) -> None:
    connection = _connection()
    apply_v34_migration(connection)
    insert_packet(
        connection, table, key, "old-scope-operation", ("old",),
        authorization_version=3,
    )
    key_where = " AND ".join(f"{column}=?" for column in LEGACY_KEYS[table])
    connection.execute(
        f"DELETE FROM {table} WHERE {key_where} AND synced=0 "
        "AND owner_user_id=? AND created_authorization_version=?",
        (*key, 17, 4),
    )
    insert_packet(connection, table, key, "active-scope-operation", ("new",))
    rows = connection.execute(
        f"SELECT client_op_id,created_authorization_version,payload_text "
        f"FROM {table} ORDER BY created_authorization_version"
    ).fetchall()
    assert [tuple(row) for row in rows] == [
        ("old-scope-operation", 3, "old"),
        ("active-scope-operation", 4, "new"),
    ]


@pytest.mark.parametrize("table,key", [
    ("pending_attendance", (4, "2026-09-24")),
    ("pending_grades", (8,)),
    ("pending_mezmur", ("2026-09-24", "choir")),
    ("pending_hr", ("2026-09-24", "staff")),
])
def test_stale_claim_cannot_settle_same_operation_after_recovery_and_reclaim(
    table: str, key: tuple[Any, ...]
) -> None:
    connection = _connection()
    apply_v34_migration(connection)
    insert_packet(connection, table, key, "stable-operation", ("one", "two"))
    stale = claim_packet(connection, table, 17, 4, "2026-09-24T00:00:01Z")
    assert stale
    recover_in_flight(connection, "2026-09-24T00:00:02Z")
    current = claim_packet(connection, table, 17, 4, "2026-09-24T00:00:03Z")
    assert current and current["op_id"] == stale["op_id"]
    assert current["claimed_at"] != stale["claimed_at"]

    assert settle_packet(connection, stale, "synced") == "supersededLocal"
    rows = connection.execute(
        f"SELECT sync_state,synced,last_attempt_at FROM {table} ORDER BY id"
    ).fetchall()
    assert [tuple(row) for row in rows] == [
        ("in_flight", 0, "2026-09-24T00:00:03Z"),
        ("in_flight", 0, "2026-09-24T00:00:03Z"),
    ]
    assert settle_packet(connection, current, "synced") == "applied"


@pytest.mark.parametrize("table,key", [
    ("pending_attendance", (4, "2026-09-24")),
    ("pending_grades", (8,)),
    ("pending_mezmur", ("2026-09-24", "choir")),
    ("pending_hr", ("2026-09-24", "staff")),
])
def test_exact_claim_snapshot_and_settlement_for_each_legacy_outbox(
    table: str, key: tuple[Any, ...]
) -> None:
    connection = _connection()
    apply_v34_migration(connection)
    insert_packet(connection, table, key, "operation-a", ("first", "second"), packet_kind="submitted")
    claim = claim_packet(connection, table, 17, 4, "2026-09-24T00:00:01Z")
    assert claim is not None
    assert claim["op_id"] == "operation-a"
    assert {row["client_op_id"] for row in claim["rows"]} == {"operation-a"}
    assert {row["packet_kind"] for row in claim["rows"]} == {"submitted"}
    assert settle_packet(connection, claim, "synced") == "applied"
    assert connection.execute(
        f"SELECT COUNT(*) FROM {table} WHERE synced=1 AND sync_state='synced'"
    ).fetchone()[0] == 2


def test_replacement_survives_stale_session_settlement_and_seven_day_cleanup() -> None:
    connection = _connection()
    apply_v34_migration(connection)
    table = "pending_attendance"
    key = (4, "2026-09-24")
    insert_packet(connection, table, key, "operation-a", ("old",))
    claim = claim_packet(connection, table, 17, 4, "2026-09-24T00:00:01Z")
    assert claim is not None
    connection.execute(
        f"DELETE FROM {table} WHERE class_id=? AND date=? AND synced=0 "
        "AND owner_user_id=? AND created_authorization_version=?",
        (*key, 17, 4),
    )
    insert_packet(connection, table, key, "operation-b", ("new",))

    # Scope/session CAS wins before local lease settlement; either ordering
    # leaves the replacement untouched.
    assert settle_packet(
        connection, claim, "synced", current_authorization_version=5
    ) == "supersededSession"
    connection.execute(
        f"DELETE FROM {table} WHERE synced=1 AND synced_at<?",
        ("2026-10-01T00:00:00Z",),
    )
    row = connection.execute(
        f"SELECT client_op_id,sync_state,synced,payload_text FROM {table}"
    ).fetchone()
    assert tuple(row) == ("operation-b", "pending", 0, "new")


def test_stale_manual_discard_cannot_delete_replacement_operation() -> None:
    connection = _connection()
    apply_v34_migration(connection)
    table = "pending_grades"
    insert_packet(connection, table, (8,), "terminal-operation", ("old",))
    connection.execute(
        f"UPDATE {table} SET sync_state='needs_attention', "
        "sync_error='SERVER_REJECTED' WHERE client_op_id='terminal-operation'"
    )

    # A later user save atomically supersedes that natural-key generation
    # while an already-open review dialog still holds its old operation id.
    connection.execute(
        f"DELETE FROM {table} WHERE assessment_id=? AND synced=0", (8,)
    )
    insert_packet(connection, table, (8,), "replacement-operation", ("new",))

    stale_discard = connection.execute(
        f"DELETE FROM {table} WHERE client_op_id=? AND synced=0 "
        "AND sync_state IN ('needs_attention','resolved_conflict') "
        "AND owner_user_id=? AND created_authorization_version=?",
        ("terminal-operation", 17, 4),
    )
    assert stale_discard.rowcount == 0
    remaining = connection.execute(
        f"SELECT client_op_id,sync_state,payload_text FROM {table}"
    ).fetchall()
    assert [tuple(row) for row in remaining] == [
        ("replacement-operation", "pending", "new")
    ]


def test_wrong_owner_scope_state_and_crash_recovery_preserve_identity() -> None:
    connection = _connection()
    apply_v34_migration(connection)
    insert_packet(connection, "pending_attendance", (4, "2026-09-24"), "stable-id", ("one",))
    claim = claim_packet(connection, "pending_attendance", 17, 4, "2026-09-24T00:00:01Z")
    assert claim
    assert settle_packet(connection, claim, "synced", current_owner=18) == "supersededSession"
    assert settle_packet(connection, claim, "synced", current_authorization_version=5) == "supersededSession"
    connection.execute(
        "INSERT INTO pending_hymn_ops"
        "(op,payload_json,client_op_id,created_at,sync_state,attempt_count) "
        "VALUES(?,?,?,?,?,?)",
        ("hymn_save", "{}", "hymn-stable", "2026-09-24T00:00:00Z", "in_flight", 2),
    )
    connection.execute(
        "INSERT INTO comm_outbox"
        "(client_tag,thread_id,body,state,attempts,created_at) VALUES(?,?,?,?,?,?)",
        ("comm-stable", 1, "body", "in_flight", 2, "2026-09-24T00:00:00Z"),
    )
    recover_in_flight(connection, "2026-09-24T00:02:00Z")
    row = connection.execute(
        "SELECT client_op_id,sync_state,attempt_count,next_attempt_at FROM pending_attendance"
    ).fetchone()
    assert tuple(row) == ("stable-id", "retry_wait", 1, "2026-09-24T00:02:00Z")
    hymn = connection.execute(
        "SELECT client_op_id,sync_state,attempt_count,next_attempt_at "
        "FROM pending_hymn_ops WHERE client_op_id='hymn-stable'"
    ).fetchone()
    assert tuple(hymn) == (
        "hymn-stable",
        "retry_wait",
        2,
        "2026-09-24T00:02:00Z",
    )
    comm = connection.execute(
        "SELECT client_tag,state,attempts,next_attempt_at "
        "FROM comm_outbox WHERE client_tag='comm-stable'"
    ).fetchone()
    assert tuple(comm) == (
        "comm-stable",
        "retry_wait",
        2,
        "2026-09-24T00:02:00Z",
    )
    assert settle_packet(connection, claim, "synced") == "supersededLocal"


def test_dart_sources_bind_runtime_contract_and_never_use_lexical_max() -> None:
    schema = _schema_source()
    db = DB_SOURCE.read_text(encoding="utf-8")
    models = (MOBILE / "lib" / "services" / "legacy_outbox_models.dart").read_text(encoding="utf-8")
    policy = (MOBILE / "lib" / "services" / "outbox_policy.dart").read_text(encoding="utf-8")
    session = (MOBILE / "lib" / "services" / "session_models.dart").read_text(encoding="utf-8")

    # v35 added the download-sync cursor table (sync_state); v36 added the
    # S1 operation/attempt ledger (sync_attempts). The v33→v34 migration this
    # harness exercises is unchanged history; only the current-version pin
    # moves. The v36 contract has its own runtime harness in
    # test_mobile_sync_attempt_ledger.py.
    assert "const localDatabaseSchemaVersion = 42;" in schema
    assert "version: localDatabaseSchemaVersion" in db
    assert "await _migrateToV34(db);" in db
    assert "claimNextLegacyOperation" in db and "settleLegacyOperation" in db
    assert "client_op_id = ?" in db and "sync_state = 'in_flight'" in db
    assert "owner_user_id = ?" in db and "created_authorization_version = ?" in db
    assert "MAX(client_op_id)" not in db
    assert "class LegacyOperationRef" in models
    assert "class LegacyClaimSnapshot" in models
    assert "enum LegacySettlementResult" in models
    assert "enum OutboxDecision" in policy and "supersededLocal" in policy
    assert "enum ApiFailureKind" in policy
    assert "enum SessionState" in session and "reauth_required" in session


def test_risk9_complete_credentials_backfill_ownerless_rows_atomically() -> None:
    connection = _connection()
    apply_v34_migration(connection)
    insert_packet(
        connection,
        "pending_attendance",
        (4, "2026-09-24"),
        "attendance-op",
        ("one", "two"),
        owner=0,
        authorization_version=0,
    )
    connection.execute(
        "UPDATE pending_attendance SET owner_user_id=NULL, "
        "created_authorization_version=NULL"
    )
    connection.execute(
        "INSERT INTO comm_outbox(client_tag,thread_id,body,state,created_at) "
        "VALUES(?,?,?,?,?)",
        ("comm-op", 2, "body", "pending", "2026-09-24T00:00:00Z"),
    )
    connection.execute(
        "INSERT INTO comm_drafts(thread_id,body,updated_at) VALUES(?,?,?)",
        (2, "draft", "2026-09-24T00:00:00Z"),
    )
    connection.execute(
        "INSERT INTO pending_hymn_ops"
        "(op,payload_json,client_op_id,created_at) VALUES(?,?,?,?)",
        ("hymn_save", "{}", "hymn-op", "2026-09-24T00:00:00Z"),
    )

    with connection:
        connection.execute(
            "UPDATE pending_attendance SET owner_user_id=?, "
            "created_authorization_version=? "
            "WHERE synced=0 AND owner_user_id IS NULL",
            (17, 4),
        )
        for table in ("comm_outbox", "comm_drafts"):
            connection.execute(
                f"UPDATE {table} SET owner_user_id=?, "
                "created_authorization_version=? WHERE owner_user_id IS NULL",
                (17, 4),
            )
        connection.execute(
            "UPDATE pending_hymn_ops SET created_by_user_id=?, "
            "created_authorization_version=? "
            "WHERE synced=0 AND created_by_user_id IS NULL",
            (17, 4),
        )

    assert [tuple(row) for row in connection.execute(
        "SELECT DISTINCT owner_user_id,created_authorization_version "
        "FROM pending_attendance"
    ).fetchall()] == [(17, 4)]
    assert tuple(connection.execute(
        "SELECT owner_user_id,created_authorization_version FROM comm_outbox"
    ).fetchone()) == (17, 4)
    assert tuple(connection.execute(
        "SELECT owner_user_id,created_authorization_version FROM comm_drafts"
    ).fetchone()) == (17, 4)
    assert tuple(connection.execute(
        "SELECT created_by_user_id,created_authorization_version "
        "FROM pending_hymn_ops"
    ).fetchone()) == (17, 4)


def test_risk9_inventory_counts_batches_messages_drafts_and_hymns_separately() -> None:
    connection = _connection()
    apply_v34_migration(connection)
    insert_packet(
        connection,
        "pending_attendance",
        (4, "2026-09-24"),
        "attendance-op",
        ("one", "two"),
        state="needs_attention",
    )
    insert_packet(connection, "pending_grades", (8,), "grade-op", ("grade",))
    insert_packet(
        connection,
        "pending_mezmur",
        ("2026-09-24", "choir"),
        "mezmur-op",
        ("mezmur",),
        state="paused_auth",
    )
    insert_packet(
        connection,
        "pending_hr",
        ("2026-09-24", "staff"),
        "hr-op",
        ("hr",),
    )
    connection.executemany(
        "INSERT INTO comm_outbox"
        "(client_tag,thread_id,body,state,created_at,owner_user_id) "
        "VALUES(?,?,?,?,?,?)",
        [
            ("pending", 1, "p", "pending", "2026-09-24T00:00:00Z", 17),
            ("failed", 1, "f", "failed", "2026-09-24T00:00:01Z", 17),
        ],
    )
    connection.executemany(
        "INSERT INTO comm_drafts(thread_id,body,updated_at,owner_user_id) "
        "VALUES(?,?,?,?)",
        [(1, "real draft", "2026-09-24T00:00:00Z", 17), (2, "   ", "2026-09-24T00:00:00Z", 17)],
    )
    connection.execute(
        "INSERT INTO pending_hymn_ops"
        "(op,payload_json,client_op_id,created_at) VALUES(?,?,?,?)",
        ("hymn_save", "{}", "shared-op", "2026-09-24T00:00:00Z"),
    )

    operation_counts = {
        table: connection.execute(
            f"SELECT COUNT(DISTINCT client_op_id) FROM {table} WHERE synced=0"
        ).fetchone()[0]
        for table in LEGACY_KEYS
    }
    assert operation_counts == {
        "pending_attendance": 1,
        "pending_grades": 1,
        "pending_mezmur": 1,
        "pending_hr": 1,
    }
    assert connection.execute(
        "SELECT COUNT(*) FROM comm_outbox WHERE state='pending'"
    ).fetchone()[0] == 1
    assert connection.execute(
        "SELECT COUNT(*) FROM comm_outbox WHERE state='failed'"
    ).fetchone()[0] == 1
    assert connection.execute(
        "SELECT COUNT(*) FROM comm_drafts WHERE TRIM(body)<>''"
    ).fetchone()[0] == 1
    assert connection.execute(
        "SELECT COUNT(DISTINCT client_op_id) FROM pending_hymn_ops WHERE synced=0"
    ).fetchone()[0] == 1


def test_risk9_purging_resume_is_idempotent_and_preserves_shared_hymns() -> None:
    connection = _connection()
    apply_v34_migration(connection)
    insert_packet(
        connection,
        "pending_attendance",
        (4, "2026-09-24"),
        "private-op",
        ("private",),
    )
    connection.execute(
        "INSERT INTO comm_outbox(client_tag,thread_id,body,state,created_at) "
        "VALUES(?,?,?,?,?)",
        ("private-message", 1, "body", "pending", "2026-09-24T00:00:00Z"),
    )
    connection.execute(
        "INSERT INTO pending_hymn_ops"
        "(op,payload_json,client_op_id,created_at) VALUES(?,?,?,?)",
        ("hymn_save", '{"title":"kept"}', "shared-op", "2026-09-24T00:00:00Z"),
    )
    connection.execute(
        "UPDATE local_session_state SET state='purging',owner_user_id=17,generation=9"
    )

    # Resume the destructive phase twice to model process death at any delete.
    for _ in range(2):
        with connection:
            for table in (*LEGACY_KEYS, "comm_outbox", "comm_drafts"):
                connection.execute(f"DELETE FROM {table}")
    with connection:
        connection.execute(
            "UPDATE local_session_state SET state='anonymous_clean',"
            "owner_user_id=NULL,owner_authorization_version=NULL WHERE id=1"
        )

    assert connection.execute(
        "SELECT COUNT(*) FROM pending_attendance"
    ).fetchone()[0] == 0
    assert connection.execute("SELECT COUNT(*) FROM comm_outbox").fetchone()[0] == 0
    assert tuple(connection.execute(
        "SELECT client_op_id,payload_json FROM pending_hymn_ops"
    ).fetchone()) == ("shared-op", '{"title":"kept"}')
    assert tuple(connection.execute(
        "SELECT state,owner_user_id,generation FROM local_session_state WHERE id=1"
    ).fetchone()) == ("anonymous_clean", None, 9)


def test_hymn_dependency_failure_blocks_child_without_deleting_payload() -> None:
    connection = _connection()
    apply_v34_migration(connection)
    parent = connection.execute(
        "INSERT INTO pending_hymn_ops"
        "(op,payload_json,client_op_id,created_at,sync_state,failure_code,entity_key) "
        "VALUES(?,?,?,?,?,?,?)",
        (
            "category_save", '{"id":-1,"name":"Parent"}', "parent-op",
            "2026-09-24T00:00:00Z", "needs_attention", "VALIDATION_FAILED",
            "category:-1",
        ),
    ).lastrowid
    connection.execute(
        "INSERT INTO pending_hymn_ops"
        "(op,payload_json,client_op_id,created_at,sync_state,entity_key,depends_on) "
        "VALUES(?,?,?,?,?,?,?)",
        (
            "hymn_save", '{"id":-2,"title":"Child"}', "child-op",
            "2026-09-24T00:00:01Z", "pending", "hymn:-2", parent,
        ),
    )
    with connection:
        connection.execute(
            """
            UPDATE pending_hymn_ops
               SET sync_state='blocked_dependency',
                   failure_code='DEPENDENCY_UNRESOLVED',
                   next_attempt_at=NULL
             WHERE synced=0 AND sync_state IN ('pending','retry_wait')
               AND depends_on IS NOT NULL
               AND EXISTS (
                 SELECT 1 FROM pending_hymn_ops dependency
                  WHERE dependency.id=pending_hymn_ops.depends_on
                    AND dependency.synced=0
                    AND dependency.sync_state IN
                      ('needs_attention','paused_scope','resolved_conflict','blocked_dependency')
               )
            """
        )
    child = connection.execute(
        "SELECT payload_json,sync_state,failure_code,depends_on "
        "FROM pending_hymn_ops WHERE client_op_id='child-op'"
    ).fetchone()
    assert tuple(child) == (
        '{"id":-2,"title":"Child"}',
        "blocked_dependency",
        "DEPENDENCY_UNRESOLVED",
        parent,
    )
    assert connection.execute("SELECT COUNT(*) FROM pending_hymn_ops").fetchone()[0] == 2


def test_communication_fifo_skips_blocked_thread_and_lease_prevents_stale_settlement() -> None:
    connection = _connection()
    apply_v34_migration(connection)
    connection.executemany(
        "INSERT INTO comm_outbox"
        "(client_tag,thread_id,body,state,attempts,next_attempt_at,created_at,"
        "owner_user_id,created_authorization_version) VALUES(?,?,?,?,?,?,?,?,?)",
        [
            ("t1-head", 1, "wait", "retry_wait", 1,
             "2026-09-24T00:10:00Z", "2026-09-24T00:00:00Z", 17, 4),
            ("t1-later", 1, "must not overtake", "pending", 0, None,
             "2026-09-24T00:00:01Z", 17, 4),
            ("t2-head", 2, "independent", "pending", 0, None,
             "2026-09-24T00:00:02Z", 17, 4),
        ],
    )
    candidate = connection.execute(
        """
        SELECT c.client_tag FROM comm_outbox c
         WHERE c.owner_user_id=17 AND c.created_authorization_version=4
           AND c.state IN ('pending','retry_wait')
           AND (c.next_attempt_at IS NULL OR c.next_attempt_at<='2026-09-24T00:00:03Z')
           AND NOT EXISTS (
             SELECT 1 FROM comm_outbox prior
              WHERE prior.thread_id=c.thread_id
                AND prior.owner_user_id=c.owner_user_id
                AND prior.created_authorization_version=c.created_authorization_version
                AND (prior.created_at<c.created_at OR
                     (prior.created_at=c.created_at AND prior.client_tag<c.client_tag))
           )
         ORDER BY c.created_at,c.client_tag LIMIT 1
        """
    ).fetchone()[0]
    assert candidate == "t2-head"

    connection.execute(
        "UPDATE comm_outbox SET state='in_flight',attempts=1,last_attempt_at=? "
        "WHERE client_tag='t2-head' AND state='pending'",
        ("2026-09-24T00:00:03Z",),
    )
    recover_in_flight(connection, "2026-09-24T00:00:04Z")
    connection.execute(
        "UPDATE comm_outbox SET state='in_flight',attempts=2,last_attempt_at=?,"
        "next_attempt_at=NULL WHERE client_tag='t2-head' AND state='retry_wait'",
        ("2026-09-24T00:00:05Z",),
    )
    stale_delete = connection.execute(
        "DELETE FROM comm_outbox WHERE client_tag='t2-head' AND state='in_flight' "
        "AND last_attempt_at='2026-09-24T00:00:03Z' "
        "AND owner_user_id=17 AND created_authorization_version=4"
    ).rowcount
    assert stale_delete == 0
    assert tuple(connection.execute(
        "SELECT state,attempts,last_attempt_at FROM comm_outbox "
        "WHERE client_tag='t2-head'"
    ).fetchone()) == ("in_flight", 2, "2026-09-24T00:00:05Z")
