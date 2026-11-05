"""Runtime verification of the S1 operation/attempt ledger (schema v36).

This harness executes the REAL schema contract declared in
`lib/services/local_schema_v34.dart` against a real SQLite engine, exactly as
`test_mobile_v34_sqlite_runtime.py` does for v34. It does not assert that
source strings exist; it creates the table the app creates and then checks
what the engine actually permits and stores.

Where a statement is lifted verbatim from `local_db.dart` that is stated
explicitly. Where behaviour is mirrored rather than lifted (the Dart code uses
sqflite's `insert`/`update` helpers rather than raw SQL) that is stated too,
so nobody mistakes this for proof of the Dart call sites themselves. The Dart
call sites are covered by the Flutter suite in CI.
"""
from __future__ import annotations

import json
import re
import sqlite3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile" / "wbws_flutter_app"
SCHEMA_FILE = MOBILE / "lib" / "services" / "local_schema_v34.dart"
DB_FILE = MOBILE / "lib" / "services" / "local_db.dart"
MODELS_FILE = MOBILE / "lib" / "services" / "sync_attempt_models.dart"


def _schema_source() -> str:
    return SCHEMA_FILE.read_text(encoding="utf-8")


def _triple_sql(name: str) -> str:
    match = re.search(rf"const {name}[^=]*=\s*'''(.*?)''';", _schema_source(), re.DOTALL)
    assert match, f"missing production SQL constant {name}"
    return match.group(1)


def _attempt_index_sql() -> list[str]:
    block = re.search(
        r"const localSyncAttemptsV36IndexSql[^=]*=\s*<String>\[(.*?)\n\];",
        _schema_source(),
        re.DOTALL,
    )
    assert block, "missing localSyncAttemptsV36IndexSql"
    statements = re.findall(r"'''(CREATE .*?)'''", block.group(1), re.DOTALL)
    assert len(statements) == 5, "the index contract must stay declared in one place"
    return statements


def _ledger_db() -> sqlite3.Connection:
    """A real SQLite database carrying only the shipped v36 ledger contract."""
    connection = sqlite3.connect(":memory:")
    connection.execute(_triple_sql("localSyncAttemptsV36Sql"))
    for statement in _attempt_index_sql():
        connection.execute(statement)
    return connection


def _open_attempt(
    connection: sqlite3.Connection,
    *,
    op: str,
    number: int,
    uid: str,
    domain: str = "pending_attendance",
    started: str = "2026-03-01T10:14:02.000Z",
    owner: int | None = 11,
    entity: dict | None = None,
) -> None:
    """Mirrors `_openSyncAttempt` (Dart uses sqflite `insert`, not raw SQL)."""
    connection.execute(
        "INSERT OR IGNORE INTO sync_attempts "
        "(client_op_id, attempt_number, attempt_uid, domain, entity_ref, "
        " owner_user_id, started_at, retry_decision) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING')",
        (op, number, uid, domain,
         json.dumps(entity) if entity else None, owner, started),
    )


def _close_attempt(
    connection: sqlite3.Connection,
    *,
    op: str,
    number: int,
    finished: str,
    category: str,
    decision: str,
    status: int | None = None,
) -> int:
    """Mirrors `_closeSyncAttempt`, including its `finished_at IS NULL` guard."""
    cursor = connection.execute(
        "UPDATE sync_attempts SET finished_at = ?, http_status = ?, "
        "error_category = ?, retry_decision = ? "
        "WHERE client_op_id = ? AND attempt_number = ? AND finished_at IS NULL",
        (finished, status, category, decision, op, number),
    )
    return cursor.rowcount


def _interrupt_open(connection: sqlite3.Connection, now: str) -> int:
    """Mirrors `_interruptOpenSyncAttempts` — the `finished_at IS NULL` sweep."""
    cursor = connection.execute(
        "UPDATE sync_attempts SET finished_at = ?, retry_decision = 'INTERRUPTED', "
        "error_category = 'UNKNOWN', "
        "failure_message = 'The app closed before this attempt finished.' "
        "WHERE finished_at IS NULL",
        (now,),
    )
    return cursor.rowcount


def _prune_sql() -> str:
    """Lifted VERBATIM from `pruneSyncAttempts` in local_db.dart."""
    source = DB_FILE.read_text(encoding="utf-8")
    match = re.search(
        r"return db\.rawDelete\(\s*((?:\s*'[^']*'\s*)+)\s*,\s*\[keep\],", source, re.DOTALL
    )
    assert match, "pruneSyncAttempts raw SQL not found — did the method change?"
    return "".join(re.findall(r"'([^']*)'", match.group(1)))


class SyncAttemptSchemaTests(unittest.TestCase):
    """The declared v36 contract must be real, executable SQLite."""

    def test_the_ledger_table_is_created_by_the_shipped_sql(self) -> None:
        connection = _ledger_db()
        columns = {row[1] for row in connection.execute("PRAGMA table_info(sync_attempts)")}
        self.assertEqual(
            columns,
            {
                "id", "client_op_id", "attempt_number", "attempt_uid", "domain",
                "entity_ref", "owner_user_id", "created_authorization_version",
                "started_at", "finished_at", "duration_ms", "http_status",
                "error_category", "retry_decision", "failure_message",
                "next_attempt_at", "server_ref",
                # S2 Goal A.1 — execution provenance.
                "execution_source",
            },
        )

    def test_the_schema_version_pin_moved_with_the_migration(self) -> None:
        schema = _schema_source()
        db = DB_FILE.read_text(encoding="utf-8")
        # Only the current-version pin moves when a later migration lands.
        # v37 (S2 Goal A.1) added sync_attempts.execution_source.
        self.assertIn("const localDatabaseSchemaVersion = 38;", schema)
        # The migration must be reachable from onUpgrade AND onCreate, or a
        # fresh install and an upgraded install diverge. The v36 step is
        # unchanged history and must remain reachable.
        self.assertIn("if (oldVersion < 36)", db)
        self.assertIn("if (oldVersion < 37)", db)
        self.assertEqual(db.count("_createSyncAttemptLedger(db)"), 2)

    def test_attempt_identity_is_unique_per_operation(self) -> None:
        connection = _ledger_db()
        _open_attempt(connection, op="op-1", number=1, uid="att_a")
        with self.assertRaises(sqlite3.IntegrityError):
            connection.execute(
                "INSERT INTO sync_attempts "
                "(client_op_id, attempt_number, attempt_uid, domain, started_at) "
                "VALUES ('op-1', 1, 'att_b', 'pending_attendance', '2026-03-01T10:00:00Z')"
            )

    def test_a_correlation_id_cannot_describe_two_transmissions(self) -> None:
        connection = _ledger_db()
        _open_attempt(connection, op="op-1", number=1, uid="att_same")
        with self.assertRaises(sqlite3.IntegrityError):
            connection.execute(
                "INSERT INTO sync_attempts "
                "(client_op_id, attempt_number, attempt_uid, domain, started_at) "
                "VALUES ('op-2', 1, 'att_same', 'pending_grades', '2026-03-01T10:00:00Z')"
            )

    def test_retry_decision_is_never_silently_null(self) -> None:
        connection = _ledger_db()
        connection.execute(
            "INSERT INTO sync_attempts "
            "(client_op_id, attempt_number, attempt_uid, domain, started_at) "
            "VALUES ('op-1', 1, 'att_a', 'pending_attendance', '2026-03-01T10:00:00Z')"
        )
        row = connection.execute("SELECT retry_decision FROM sync_attempts").fetchone()
        self.assertEqual(row[0], "PENDING")


class OperationLineageTests(unittest.TestCase):
    """The S1 question: what actually happened to this operation?"""

    def test_three_failures_then_a_success_is_one_operation(self) -> None:
        connection = _ledger_db()
        ladder = [
            (1, "TIMEOUT", "RETRY_SCHEDULED", None),
            (2, "SERVICE_UNAVAILABLE", "RETRY_SCHEDULED", 503),
            (3, "SERVICE_UNAVAILABLE", "RETRY_SCHEDULED", 503),
            (4, "none", "COMPLETED", 200),
        ]
        for number, category, decision, status in ladder:
            _open_attempt(connection, op="OP-8F21", number=number, uid=f"att_{number}")
            _close_attempt(
                connection, op="OP-8F21", number=number,
                finished=f"2026-03-01T10:14:{10 + number:02d}.000Z",
                category=category, decision=decision, status=status,
            )

        rows = connection.execute(
            "SELECT attempt_number, error_category, retry_decision, http_status "
            "FROM sync_attempts WHERE client_op_id = 'OP-8F21' ORDER BY attempt_number"
        ).fetchall()

        self.assertEqual(len(rows), 4, "four transmissions, one operation")
        self.assertEqual([r[0] for r in rows], [1, 2, 3, 4])
        self.assertEqual(rows[-1][2], "COMPLETED")
        # the earlier failures are still legible after the success
        self.assertEqual([r[1] for r in rows[:3]],
                         ["TIMEOUT", "SERVICE_UNAVAILABLE", "SERVICE_UNAVAILABLE"])

        operations = connection.execute(
            "SELECT COUNT(DISTINCT client_op_id) FROM sync_attempts"
        ).fetchone()[0]
        self.assertEqual(operations, 1, "this must not look like four operations")

    def test_a_closed_attempt_is_never_rewritten_by_a_later_settlement(self) -> None:
        connection = _ledger_db()
        _open_attempt(connection, op="op-1", number=1, uid="att_1")
        _close_attempt(connection, op="op-1", number=1,
                       finished="2026-03-01T10:00:01Z", category="TIMEOUT",
                       decision="RETRY_SCHEDULED")
        # A duplicate settlement for the same attempt must change nothing.
        changed = _close_attempt(connection, op="op-1", number=1,
                                 finished="2026-03-01T10:00:09Z", category="none",
                                 decision="COMPLETED", status=200)
        self.assertEqual(changed, 0, "the guard is `finished_at IS NULL`")
        row = connection.execute(
            "SELECT error_category, retry_decision FROM sync_attempts"
        ).fetchone()
        self.assertEqual(row, ("TIMEOUT", "RETRY_SCHEDULED"))

    def test_an_interrupted_attempt_is_closed_as_interrupted_not_successful(self) -> None:
        connection = _ledger_db()
        _open_attempt(connection, op="op-1", number=1, uid="att_1")
        _open_attempt(connection, op="op-2", number=1, uid="att_2")
        _close_attempt(connection, op="op-2", number=1,
                       finished="2026-03-01T10:00:02Z", category="none",
                       decision="COMPLETED", status=200)

        swept = _interrupt_open(connection, "2026-03-01T11:00:00Z")
        self.assertEqual(swept, 1, "only the still-open attempt is swept")

        interrupted = connection.execute(
            "SELECT retry_decision, http_status FROM sync_attempts WHERE client_op_id='op-1'"
        ).fetchone()
        self.assertEqual(interrupted[0], "INTERRUPTED")
        self.assertIsNone(interrupted[1], "an interrupted attempt has no HTTP result")

        settled = connection.execute(
            "SELECT retry_decision FROM sync_attempts WHERE client_op_id='op-2'"
        ).fetchone()
        self.assertEqual(settled[0], "COMPLETED", "a settled attempt is left alone")

    def test_no_open_attempt_survives_the_recovery_sweep(self) -> None:
        connection = _ledger_db()
        for index in range(5):
            _open_attempt(connection, op=f"op-{index}", number=1, uid=f"att_{index}")
        _interrupt_open(connection, "2026-03-01T11:00:00Z")
        still_open = connection.execute(
            "SELECT COUNT(*) FROM sync_attempts WHERE finished_at IS NULL"
        ).fetchone()[0]
        self.assertEqual(still_open, 0)

    def test_an_idempotent_replay_is_recorded_as_delivery_evidence(self) -> None:
        connection = _ledger_db()
        _open_attempt(connection, op="op-1", number=1, uid="att_1")
        _close_attempt(connection, op="op-1", number=1,
                       finished="2026-03-01T10:00:30Z", category="TIMEOUT",
                       decision="RETRY_SCHEDULED")
        _open_attempt(connection, op="op-1", number=2, uid="att_2")
        _close_attempt(connection, op="op-1", number=2,
                       finished="2026-03-01T10:00:40Z",
                       category="IDEMPOTENCY_REPLAY", decision="COMPLETED",
                       status=200)
        categories = [r[0] for r in connection.execute(
            "SELECT error_category FROM sync_attempts ORDER BY attempt_number"
        )]
        self.assertEqual(categories, ["TIMEOUT", "IDEMPOTENCY_REPLAY"])

    def test_the_ledger_stores_identifiers_and_never_payloads(self) -> None:
        connection = _ledger_db()
        _open_attempt(
            connection, op="op-1", number=1, uid="att_1",
            entity={"class_id": 7, "date": "2026-03-01"},
        )
        row = connection.execute("SELECT entity_ref FROM sync_attempts").fetchone()
        stored = json.loads(row[0])
        self.assertEqual(stored, {"class_id": 7, "date": "2026-03-01"})
        # The natural key is the only business data the table may carry.
        self.assertNotIn("records", stored)
        self.assertNotIn("member_id", stored)


class LedgerGrowthTests(unittest.TestCase):
    """Bounded growth, without pretending to be a retention policy."""

    def test_prune_keeps_the_newest_operations_and_drops_the_rest(self) -> None:
        connection = _ledger_db()
        for index in range(10):
            op = f"op-{index:02d}"
            _open_attempt(connection, op=op, number=1, uid=f"att_{index}",
                          started=f"2026-03-01T10:{index:02d}:00.000Z")
            _close_attempt(connection, op=op, number=1,
                           finished=f"2026-03-01T10:{index:02d}:05.000Z",
                           category="none", decision="COMPLETED", status=200)

        connection.execute(_prune_sql(), (3,))
        remaining = sorted(
            r[0] for r in connection.execute("SELECT DISTINCT client_op_id FROM sync_attempts")
        )
        self.assertEqual(remaining, ["op-07", "op-08", "op-09"])

    def test_prune_never_discards_an_operation_still_in_flight(self) -> None:
        connection = _ledger_db()
        # An old operation whose attempt is still open must survive.
        _open_attempt(connection, op="op-old", number=1, uid="att_old",
                      started="2026-01-01T00:00:00.000Z")
        for index in range(5):
            op = f"op-new-{index}"
            _open_attempt(connection, op=op, number=1, uid=f"att_new_{index}",
                          started=f"2026-03-01T10:{index:02d}:00.000Z")
            _close_attempt(connection, op=op, number=1,
                           finished=f"2026-03-01T10:{index:02d}:05.000Z",
                           category="none", decision="COMPLETED", status=200)

        connection.execute(_prune_sql(), (2,))
        survivors = {r[0] for r in connection.execute(
            "SELECT DISTINCT client_op_id FROM sync_attempts")}
        self.assertIn("op-old", survivors,
                      "an unfinished attempt must never be pruned away")


class LedgerIsolationTests(unittest.TestCase):
    """Observability must not become a way around account isolation."""

    def test_each_attempt_records_the_owner_that_transmitted_it(self) -> None:
        connection = _ledger_db()
        _open_attempt(connection, op="op-a", number=1, uid="att_a", owner=11)
        _open_attempt(connection, op="op-b", number=1, uid="att_b", owner=22)
        owners = dict(connection.execute(
            "SELECT client_op_id, owner_user_id FROM sync_attempts"))
        self.assertEqual(owners, {"op-a": 11, "op-b": 22})

        # A diagnostic view scoped to one owner cannot see the other's work.
        visible = connection.execute(
            "SELECT client_op_id FROM sync_attempts WHERE owner_user_id = ?", (11,)
        ).fetchall()
        self.assertEqual(visible, [("op-a",)])


class LedgerSourceContractTests(unittest.TestCase):
    """Guard-the-guard: the properties the tests above rely on must hold."""

    def test_the_ledger_is_written_inside_the_claim_and_settle_transactions(self) -> None:
        db = DB_FILE.read_text(encoding="utf-8")
        claim = db.index("Future<LegacyClaimSnapshot?> claimNextLegacyOperation")
        settle = db.index("Future<LegacySettlementResult> settleLegacyOperation")
        recover = db.index("Future<void> _recoverOrphanedInFlightWithDb")

        claim_body = db[claim:settle]
        self.assertIn("_openSyncAttempt(", claim_body)
        self.assertIn("db.transaction((txn) async {", claim_body,
                      "the attempt must open inside the claim transaction")

        settle_body = db[settle:db.index("Future<SubmitUndoResult> undoSubmittedLegacyOperation")]
        self.assertIn("_closeSyncAttempt(", settle_body)

        recover_body = db[recover:recover + 2500]
        self.assertIn("_interruptOpenSyncAttempts(", recover_body)

    def test_the_attempt_number_comes_from_the_outbox_row_not_a_counter(self) -> None:
        """If the ledger invented its own counter the two could disagree."""
        db = DB_FILE.read_text(encoding="utf-8")
        claim_start = db.index("Future<LegacyClaimSnapshot?> claimNextLegacyOperation")
        claim_body = db[claim_start:claim_start + 7000]
        self.assertIn("attemptNumber: attemptCount", claim_body)
        self.assertIn("attempt_count = attempt_count + 1", claim_body)

    def test_a_retry_reuses_the_operation_id_and_never_mints_a_new_one(self) -> None:
        """Mutation guard (M2).

        Operation identity is the whole premise of lineage: if the claim path
        minted a fresh id per transmission, every retry would look like a new
        operation and the ledger would be worthless. The claim path must bind
        the attempt to the client_op_id it actually claimed.
        """
        db = DB_FILE.read_text(encoding="utf-8")
        claim_start = db.index("Future<LegacyClaimSnapshot?> claimNextLegacyOperation")
        claim_end = db.index("Future<LegacySettlementResult> settleLegacyOperation")
        claim_body = db[claim_start:claim_end]

        self.assertIn("clientOpId: clientOpId,", claim_body,
                      "the attempt must be bound to the claimed operation id")
        self.assertNotIn("newClientOpId()", claim_body,
                         "claiming an operation must never mint a new operation id")
        # The id claimed is the id transmitted and the id the server stores
        # as api_idempotency_records.idem_key.
        self.assertIn("final clientOpId = '${candidates.first['client_op_id']}';",
                      claim_body)

    def test_settling_one_attempt_cannot_touch_the_others(self) -> None:
        """Mutation guard (M3).

        A settlement must close exactly the attempt it settles. Widening that
        WHERE clause would let a later success rewrite — or a delete remove —
        the earlier failures, which is precisely the history S1 exists to keep.
        """
        db = DB_FILE.read_text(encoding="utf-8")
        start = db.index("Future<void> _closeSyncAttempt(")
        body = db[start:db.index("static String? _safeAttemptMessage")]
        self.assertEqual(
            body.count(
                "where: 'client_op_id = ? AND attempt_number = ? AND finished_at IS NULL'"
            ),
            2,
            "both the lookup and the update must be pinned to one open attempt",
        )
        self.assertIn("whereArgs: [clientOpId, attemptNumber]", body)
        # Closing an attempt is an UPDATE, never a DELETE.
        self.assertNotIn("delete(", body)
        self.assertNotIn("rawDelete", body)

    def test_every_attempt_is_written_with_its_transmitting_owner(self) -> None:
        """Mutation guard (M12).

        Diagnostics must never become a side channel around account
        isolation, so each attempt carries the owner that transmitted it.
        """
        db = DB_FILE.read_text(encoding="utf-8")
        start = db.index("Future<void> _openSyncAttempt(")
        body = db[start:db.index("Future<void> _closeSyncAttempt(")]
        self.assertIn("'owner_user_id': ownerUserId,", body)
        self.assertIn("'created_authorization_version': authorizationVersion,", body)
        self.assertIn("int? ownerUserId,", body)

        claim_start = db.index("Future<LegacyClaimSnapshot?> claimNextLegacyOperation")
        claim_body = db[claim_start:db.index(
            "Future<LegacySettlementResult> settleLegacyOperation")]
        self.assertIn("ownerUserId: ownerUserId,", claim_body,
                      "the claim's owner must reach the ledger row")
        self.assertIn("authorizationVersion: authorizationVersion,", claim_body)

    def test_the_ledger_never_uses_the_outer_handle_inside_a_transaction(self) -> None:
        """sqflite serialises one connection: `db.x()` inside `db.transaction`
        deadlocks. The ledger's table probe must therefore be hoisted above
        the transaction in both the claim and the settle path.
        """
        db = DB_FILE.read_text(encoding="utf-8")
        regions = [
            ("claim",
             "Future<LegacyClaimSnapshot?> claimNextLegacyOperation",
             "/// Settles only the exact claimed generation"),
            ("settle",
             "Future<LegacySettlementResult> settleLegacyOperation",
             "/// Converts only the exact, still-unclaimed submitted generation"),
        ]
        for label, start_marker, end_marker in regions:
            body = db[db.index(start_marker):db.index(end_marker)]
            transaction_at = body.index("db.transaction((txn)")
            inside = body[transaction_at:]
            for probe in ("_tableExists(db,", "db.rawQuery", "db.query(",
                          "db.insert(", "db.update(", "db.rawUpdate"):
                self.assertNotIn(
                    probe, inside,
                    f"{label}: `{probe}` on the outer handle inside a "
                    f"transaction deadlocks sqflite",
                )
            hoist_at = body.index("hasAttemptLedger = await _tableExists")
            self.assertLess(hoist_at, transaction_at,
                            f"{label}: the ledger probe must be hoisted")

    def test_diagnostic_messages_are_bounded(self) -> None:
        db = DB_FILE.read_text(encoding="utf-8")
        self.assertIn("_safeAttemptMessage", db)
        self.assertIn("trimmed.substring(0, 200)", db)

    def test_the_correlation_id_is_opaque_and_random(self) -> None:
        db = DB_FILE.read_text(encoding="utf-8")
        self.assertIn("String newAttemptUid() => 'att_${newClientOpId()", db)
        # newClientOpId is a Random.secure v4 UUID, so the attempt id carries
        # no user id, timestamp or payload.
        self.assertIn("final r = Random.secure();", db)

    def test_telemetry_reports_operations_and_attempts_separately(self) -> None:
        sync = (MOBILE / "lib" / "services" / "sync_service.dart").read_text(encoding="utf-8")
        models = MODELS_FILE.read_text(encoding="utf-8")
        self.assertIn("recordSyncPass(passSummary)", sync)
        self.assertIn("'operations': operationsAttempted", models)
        self.assertIn("'attempts': attemptsMade", models)
        self.assertIn("'retries': retriesMade", models)
        # the ambiguous pre-S1 call must no longer drive the drain
        self.assertNotIn("recordSyncResult(", sync)

    def test_telemetry_failure_cannot_break_a_drain(self) -> None:
        sync = (MOBILE / "lib" / "services" / "sync_service.dart").read_text(encoding="utf-8")
        self.assertIn("unawaited(TelemetryService.instance.recordSyncPass", sync)
        telemetry = (MOBILE / "lib" / "services" / "telemetry_service.dart").read_text(
            encoding="utf-8")
        self.assertIn("// Telemetry failures are strictly non-fatal", telemetry)


if __name__ == "__main__":
    unittest.main()
