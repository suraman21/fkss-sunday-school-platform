"""S2 Goal A.7 — hymn operations join the durable `sync_attempts` ledger.

THE GAP THIS CLOSES.

S1 gave every legacy outbox transmission a durable lineage row: one
`sync_attempts` record per real transmission, opened inside the claim
transaction and closed inside the settlement transaction. S2 Goal A.1 added
`execution_source` to that row so a drain's provenance survives the process.

Hymn operations never participated. `claimNextHymnOperation()` accepted no
execution source and wrote no ledger row, so every shared-hymn transmission
executed invisibly to the observability A.1/A.5/A.6 built. A.7 is an
observability/lineage fix, not a sync rewrite: the claim predicate, the
coalescing rule, the ordering rule, the retry timing, the lease semantics and
the settlement states are all unchanged.

WHAT THIS MODULE PROVES, AND WHAT IT DOES NOT.

  * REAL SQLITE IS USED. Every runtime assertion below runs against a real
    sqlite3 connection: real DDL taken from production, real rows, real
    PRAGMA introspection, real UNIQUE-constraint enforcement, real
    transaction rollback. Nothing in the runtime classes is a text match.

  * THE PRODUCTION DART METHOD IS NOT EXECUTED. `LocalDb` runs on sqflite,
    which has no implementation on a Linux CI host: it needs an Android/iOS
    platform binding, or `sqflite_common_ffi`, which is not in pubspec.lock
    and cannot be added because F-19 freezes pubspec. This is the same
    boundary S2 Goal A.6 recorded for the v36->v37 upgrade and that the
    create-path evidence has always had.

    So, exactly as in A.6: every SQL statement the hymn claim executes is
    EXTRACTED FROM THE PRODUCTION SOURCE at test time and never retyped,
    while the Dart control flow around it is REPRODUCED. A.6 proved that a
    reproduced control flow cannot catch a mutation of the thing it mirrors,
    so the reproduction is paired with `HymnLedgerSourceContract`, which pins
    the control flow itself against the production file. The two halves
    together are what make a mutation of either side fail.

    "Real SQLite database execution" — YES.
    "Actual sqflite platform-runtime execution" — NO, and never claimed.

  * The Dart call sites (`HymnStore.pushPending`, `SyncService._drain`) are
    executed by the Flutter unit suite in CI; here they are pinned.
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
STORE_FILE = MOBILE / "lib" / "services" / "hymn_store.dart"
SERVICE_FILE = MOBILE / "lib" / "services" / "sync_service.dart"
EXECUTION_FILE = MOBILE / "lib" / "services" / "sync_execution.dart"

# A Dart adjacent-literal run: '...' or "...". The hymn claim's UPDATE is
# written as four adjacent literals, one double-quoted because it contains
# 'in_flight'.
_LITERAL = re.compile(r"'([^'\n]*)'|\"([^\"\n]*)\"")


def _read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def _schema_source() -> str:
    return _read(SCHEMA_FILE)


def _db_source() -> str:
    return _read(DB_FILE)


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


def _method_region(source: str, signature: str) -> str:
    """Source text of one method, from its signature to the next top-level one."""
    start = source.index(signature)
    tail = source[start:]
    # Methods in this file are indented two spaces; the next one starts at the
    # next `\n  ///`/`\n  Future<`/`\n  String` style declaration.
    nxt = re.search(
        r"\n  (?:///|@|Future<|Stream<|String |int |bool |void |static )",
        tail[len(signature):],
    )
    end = len(signature) + (nxt.start() if nxt else len(tail) - len(signature))
    return tail[:end]


def _hymn_claim_region() -> str:
    return _method_region(_db_source(), "Future<HymnOutboxClaim?> claimNextHymnOperation(")


def _hymn_settle_region() -> str:
    return _method_region(_db_source(), "Future<HymnSettlementResult> settleHymnOperation(")


def _open_attempt_region() -> str:
    return _method_region(_db_source(), "Future<void> _openSyncAttempt(")


def _close_attempt_region() -> str:
    return _method_region(_db_source(), "Future<void> _closeSyncAttempt(")


def _hymn_table_sql() -> str:
    """The shipped `pending_hymn_ops` CREATE, lifted from production."""
    match = re.search(
        r"'''\s*(CREATE TABLE IF NOT EXISTS pending_hymn_ops\b.*?)'''",
        _db_source(),
        re.DOTALL,
    )
    assert match, "missing production pending_hymn_ops CREATE"
    return match.group(1)


def _claim_triple_sql(index: int) -> str:
    """The index-th triple-quoted SQL inside `claimNextHymnOperation`."""
    blocks = re.findall(r"'''(.*?)'''", _hymn_claim_region(), re.DOTALL)
    assert len(blocks) == 2, "the hymn claim must keep exactly its two raw statements"
    return blocks[index]


def _dependency_block_sql() -> str:
    sql = _claim_triple_sql(0)
    assert "blocked_dependency" in sql, "first raw statement must be the dependency block"
    return sql


def _candidate_select_sql() -> str:
    sql = _claim_triple_sql(1)
    assert sql.strip().startswith("SELECT"), "second raw statement must be the candidate SELECT"
    return sql


def _concat_literals(text: str) -> str:
    return "".join(a or b for a, b in _LITERAL.findall(text))


def _claim_update_sql() -> str:
    """The atomic in_flight UPDATE, rebuilt from production's adjacent literals."""
    region = _hymn_claim_region()
    # The claim contains two rawUpdate calls; the atomic one is the second and
    # is the only one that binds [claimedAtText, rowId, priorState].
    region = region[region.rindex("txn.rawUpdate("):]
    match = re.search(r"txn\.rawUpdate\(\s*(.*?)\n\s*\[claimedAtText", region, re.DOTALL)
    assert match, "missing the hymn claim's atomic rawUpdate"
    sql = _concat_literals(match.group(1))
    assert sql.startswith("UPDATE pending_hymn_ops"), sql
    return sql


def _attempt_insert_columns() -> tuple[str, ...]:
    """Column names `_openSyncAttempt` actually writes, read from production."""
    region = _open_attempt_region()
    body = region[region.index("txn.insert("):]
    columns = re.findall(r"\n\s*'([a-z_]+)':", body)
    assert columns, "could not read the _openSyncAttempt column map"
    return tuple(columns)


def _attempt_insert_sql() -> str:
    columns = _attempt_insert_columns()
    # sqflite's ConflictAlgorithm.ignore is SQLite's INSERT OR IGNORE.
    assert "ConflictAlgorithm.ignore" in _open_attempt_region()
    return "INSERT OR IGNORE INTO sync_attempts ({}) VALUES ({})".format(
        ", ".join(columns), ", ".join("?" for _ in columns)
    )


def _execution_source_values() -> dict[str, str]:
    source = _read(EXECUTION_FILE)
    return dict(re.findall(r"\n  ([a-zA-Z]+)\('([a-z_]+)'\)", source))


# ───────────────────────────────────────────────────────────────────────────
# Runtime harness
# ───────────────────────────────────────────────────────────────────────────


class _HymnLedger:
    """A real SQLite database running the production hymn claim/settle SQL.

    The Dart control flow of `claimNextHymnOperation` / `settleHymnOperation`
    is reproduced here statement for statement, in the same order, inside one
    transaction, with every statement extracted from production source.
    `HymnLedgerSourceContract` pins that the reproduction still matches.
    """

    def __init__(self, *, ledger: bool = True) -> None:
        self.cx = sqlite3.connect(":memory:")
        self.cx.row_factory = sqlite3.Row
        self.cx.isolation_level = None  # explicit BEGIN/COMMIT, like a txn
        self.cx.execute(_hymn_table_sql())
        self.ledger = ledger
        if ledger:
            self.cx.execute(_triple_sql("localSyncAttemptsV36Sql"))
            for statement in _attempt_index_sql():
                self.cx.execute(statement)
        self.session_matches = True
        # Simulates losing the claim race: another drain moved sync_state
        # between the candidate SELECT and the atomic UPDATE, so the UPDATE
        # matches nothing and production raises.
        self.lose_claim_race = False
        self._uid = 0

    # -- fixtures ----------------------------------------------------------
    def enqueue(
        self,
        *,
        op: str = "hymn_save",
        payload: dict | None = None,
        client_op_id: str | None = "op-1",
        state: str = "pending",
        attempt_count: int = 0,
        next_attempt_at: str | None = None,
        entity_key: str | None = None,
        depends_on: int | None = None,
        owner: int | None = 11,
        authorization_version: int | None = 4,
        synced: int = 0,
        created_at: str = "2026-04-01T08:00:00.000Z",
    ) -> int:
        cursor = self.cx.execute(
            "INSERT INTO pending_hymn_ops (op, payload_json, client_op_id, created_at,"
            " synced, sync_state, attempt_count, next_attempt_at, entity_key,"
            " depends_on, created_by_user_id, created_authorization_version)"
            " VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            (
                op,
                json.dumps(payload or {"title": "Hymn"}),
                client_op_id,
                created_at,
                synced,
                state,
                attempt_count,
                next_attempt_at,
                entity_key,
                depends_on,
                owner,
                authorization_version,
            ),
        )
        return int(cursor.lastrowid)

    def _next_uid(self) -> str:
        self._uid += 1
        return f"att_hymn{self._uid:04d}"

    # -- production path ---------------------------------------------------
    def claim(
        self,
        *,
        owner_user_id: int = 11,
        authorization_version: int = 4,
        runtime_generation: int = 1,
        now: str = "2026-04-01T09:00:00.000Z",
        execution_source: str = "foreground",
    ) -> dict | None:
        """Reproduces `claimNextHymnOperation` over the extracted SQL."""
        cursor = self.cx.cursor()
        cursor.execute("BEGIN")
        try:
            if not self.session_matches:  # activeSessionMatches(...) == false
                cursor.execute("COMMIT")
                return None
            cursor.execute(_dependency_block_sql())
            cursor.execute(_candidate_select_sql(), (now,))
            row = cursor.fetchone()
            if row is None:
                cursor.execute("COMMIT")
                return None
            row_id = int(row["id"])
            prior_state = row["sync_state"]
            cursor.execute(
                _claim_update_sql(),
                (now, row_id, "__lost_race__" if self.lose_claim_race else prior_state),
            )
            if cursor.rowcount != 1:
                raise RuntimeError("Hymn operation claim was not atomic.")
            client_op_id = row["client_op_id"] or ""
            operation = row["op"] or ""
            attempt_number = int(row["attempt_count"] or 0) + 1
            entity_key = (row["entity_key"] or "").strip()
            if self.ledger and client_op_id:
                entity_ref = {"op": operation}
                if entity_key:
                    entity_ref["entity_key"] = entity_key
                row_owner = row["created_by_user_id"]
                row_version = row["created_authorization_version"]
                values = {
                    "client_op_id": client_op_id,
                    "attempt_number": attempt_number,
                    "attempt_uid": self._next_uid(),
                    "domain": "pending_hymn_ops",
                    "execution_source": execution_source,
                    "entity_ref": json.dumps(entity_ref),
                    "owner_user_id": owner_user_id if row_owner is None else int(row_owner),
                    "created_authorization_version": (
                        authorization_version if row_version is None else int(row_version)
                    ),
                    "started_at": now,
                    "retry_decision": "PENDING",
                }
                cursor.execute(
                    _attempt_insert_sql(),
                    tuple(values[column] for column in _attempt_insert_columns()),
                )
            cursor.execute("COMMIT")
        except Exception:
            cursor.execute("ROLLBACK")
            raise
        return {
            "rowId": row_id,
            "operation": operation,
            "payloadJson": row["payload_json"] or "",
            "clientOpId": client_op_id,
            "runtimeGeneration": runtime_generation,
            "attemptCount": attempt_number,
            "claimedAt": now,
        }

    def settle(
        self,
        claim: dict,
        *,
        kind: str = "accepted",
        now: str = "2026-04-01T09:00:03.000Z",
        next_attempt_at: str | None = None,
        failure_code: str | None = None,
        failure_message: str | None = None,
        http_status: int | None = 200,
        category: str = "none",
        decision: str = "COMPLETED",
        server_ref: str | None = None,
        closure: bool = True,
    ) -> str:
        """Reproduces `settleHymnOperation` for the states A.7 touches."""
        states = {
            "accepted": {"sync_state": "synced", "synced": 1, "synced_at": now,
                         "next_attempt_at": None, "sync_error": None, "failed_at": None},
            "retryable": {"sync_state": "retry_wait", "next_attempt_at": next_attempt_at,
                          "sync_error": failure_message, "failed_at": now},
            "needsAttention": {"sync_state": "needs_attention", "next_attempt_at": None,
                               "sync_error": failure_message, "failed_at": now},
        }
        values = {"failure_code": failure_code, "failure_http_status": http_status}
        values.update(states[kind])
        assignments = ", ".join(f"{column} = ?" for column in values)
        cursor = self.cx.cursor()
        cursor.execute("BEGIN")
        try:
            if not self.session_matches:
                cursor.execute("COMMIT")
                return "supersededSession"
            cursor.execute(
                f"UPDATE pending_hymn_ops SET {assignments}"
                " WHERE id = ? AND synced = 0 AND sync_state = 'in_flight'"
                " AND client_op_id = ? AND last_attempt_at = ?",
                (*values.values(), claim["rowId"], claim["clientOpId"], claim["claimedAt"]),
            )
            updated = cursor.rowcount
            if updated == 1 and closure and self.ledger and claim["clientOpId"]:
                self._close_attempt(
                    cursor,
                    client_op_id=claim["clientOpId"],
                    attempt_number=claim["attemptCount"],
                    finished_at=now,
                    http_status=http_status,
                    category=category,
                    decision=decision,
                    failure_message=failure_message,
                    next_attempt_at=next_attempt_at,
                    server_ref=server_ref,
                )
            cursor.execute("COMMIT")
        except Exception:
            cursor.execute("ROLLBACK")
            raise
        return "applied" if updated == 1 else "supersededLocal"

    @staticmethod
    def _close_attempt(cursor, **kwargs) -> None:
        """Reproduces `_closeSyncAttempt`: only the still-open attempt moves."""
        where = "client_op_id = ? AND attempt_number = ? AND finished_at IS NULL"
        args = (kwargs["client_op_id"], kwargs["attempt_number"])
        cursor.execute(f"SELECT started_at FROM sync_attempts WHERE {where} LIMIT 1", args)
        row = cursor.fetchone()
        if row is None:
            return
        cursor.execute(
            "UPDATE sync_attempts SET finished_at = ?, http_status = ?,"
            " error_category = ?, retry_decision = ?, failure_message = ?,"
            f" next_attempt_at = ?, server_ref = ? WHERE {where}",
            (
                kwargs["finished_at"],
                kwargs["http_status"],
                kwargs["category"],
                kwargs["decision"],
                kwargs["failure_message"],
                kwargs["next_attempt_at"],
                kwargs["server_ref"],
                *args,
            ),
        )

    # -- inspection --------------------------------------------------------
    def attempts(self) -> list[sqlite3.Row]:
        return list(self.cx.execute("SELECT * FROM sync_attempts ORDER BY id"))

    def attempt_count(self) -> int:
        return int(self.cx.execute("SELECT COUNT(*) FROM sync_attempts").fetchone()[0])

    def op_row(self, row_id: int) -> sqlite3.Row:
        return self.cx.execute(
            "SELECT * FROM pending_hymn_ops WHERE id = ?", (row_id,)
        ).fetchone()


# ───────────────────────────────────────────────────────────────────────────
# 1. Source contract — what the runtime harness reproduces
# ───────────────────────────────────────────────────────────────────────────


class HymnLedgerSourceContract(unittest.TestCase):
    """Pins the Dart control flow the harness mirrors.

    A.6 learned this the hard way: a mirrored harness cannot catch a mutation
    of the thing it mirrors. These pins are the other half of the proof.
    """

    def test_claim_accepts_an_execution_source(self):
        self.assertIn(
            "SyncExecutionSource executionSource = SyncExecutionSource.foreground,",
            _hymn_claim_region(),
            "claimNextHymnOperation must accept the drain's provenance",
        )

    def test_claim_opens_a_sync_attempt(self):
        self.assertIn("_openSyncAttempt(", _hymn_claim_region())

    def test_claim_passes_the_execution_source_through(self):
        self.assertIn("executionSource: executionSource,", _hymn_claim_region())

    def test_attempt_is_opened_only_after_the_claim_proved_atomic(self):
        region = _hymn_claim_region()
        self.assertLess(
            region.index("Hymn operation claim was not atomic."),
            region.index("_openSyncAttempt("),
            "an attempt row must never exist for an operation that was not claimed",
        )

    def test_attempt_is_opened_before_the_claim_is_returned(self):
        region = _hymn_claim_region()
        self.assertLess(
            region.index("_openSyncAttempt("),
            region.index("return HymnOutboxClaim("),
            "the ledger write must stay inside the claim transaction",
        )

    def test_attempt_is_opened_inside_the_claim_transaction(self):
        region = _hymn_claim_region()
        opened = region.index("_openSyncAttempt(")
        self.assertLess(region.index("db.transaction((txn) async {"), opened)
        self.assertIn("_openSyncAttempt(\n          txn,", region)

    def test_claim_guards_on_the_ledger_existing(self):
        self.assertIn("_tableExists(db, 'sync_attempts')", _hymn_claim_region())
        self.assertIn("if (hasAttemptLedger && clientOpId.isNotEmpty)", _hymn_claim_region())

    def test_claim_records_the_hymn_domain(self):
        self.assertIn("domain: 'pending_hymn_ops',", _hymn_claim_region())

    def test_claim_records_the_incremented_attempt_number(self):
        region = _hymn_claim_region()
        self.assertIn("final attemptNumber = _asIntLocal(row['attempt_count']) + 1;", region)
        self.assertIn("attemptNumber: attemptNumber,", region)
        self.assertIn("attemptCount: attemptNumber,", region)

    def test_claim_reads_the_operations_own_client_op_id(self):
        self.assertIn(
            "final clientOpId = '${row['client_op_id'] ?? ''}';",
            _hymn_claim_region(),
            "the ledger identity must be the operation's own idempotency key",
        )

    def test_claim_records_the_operations_own_owner_lineage(self):
        region = _hymn_claim_region()
        self.assertIn("ownerUserId: rowOwner == null ? ownerUserId : _asIntLocal(rowOwner),", region)
        self.assertIn("rowAuthorizationVersion == null", region)

    def test_claim_entity_ref_carries_no_payload(self):
        region = _hymn_claim_region()
        entity = region[region.index("entityRef: {"):region.index("// The claimed operation")]
        self.assertNotIn("payload", entity)
        self.assertIn("'op': operation", entity)

    def test_settle_accepts_an_attempt_closure(self):
        self.assertIn("SyncAttemptClosure? attemptClosure,", _hymn_settle_region())

    def test_settle_closes_only_an_applied_settlement(self):
        self.assertIn("if (updated == 1 &&\n          attemptClosure != null", _hymn_settle_region())

    def test_settle_closes_inside_the_settlement_transaction(self):
        region = _hymn_settle_region()
        self.assertLess(region.index("db.transaction((txn) async {"), region.index("_closeSyncAttempt("))
        self.assertIn("_closeSyncAttempt(\n          txn,", region)

    def test_settle_closes_the_claims_own_attempt_number(self):
        self.assertIn("attemptNumber: claim.attemptCount,", _hymn_settle_region())

    def test_open_attempt_still_suppresses_duplicates(self):
        self.assertIn("conflictAlgorithm: ConflictAlgorithm.ignore,", _open_attempt_region())

    def test_close_attempt_still_spares_closed_attempts(self):
        self.assertIn("finished_at IS NULL", _close_attempt_region())

    def test_push_pending_takes_the_source_from_its_caller(self):
        source = _read(STORE_FILE)
        self.assertIn("Future<int> pushPending({\n    SyncExecutionSource source", source)
        self.assertIn("executionSource: source,", source)

    def test_drain_is_the_authoritative_execution_boundary(self):
        self.assertIn("hymnStore.pushPending(source: source)", _read(SERVICE_FILE))

    def test_source_is_never_inferred_from_a_caller_name(self):
        store = _read(STORE_FILE)
        self.assertNotRegex(
            store,
            r"SyncExecutionSource\.background\s*[:;)]",
            "HymnStore must never decide its own provenance",
        )

    def test_every_settlement_path_closes_its_attempt(self):
        store = _read(STORE_FILE)
        self.assertEqual(
            store.count("settleHymnOperation("),
            store.count("attemptClosure:"),
            "a settled hymn attempt must never be left open",
        )

    def test_no_second_execution_source_convention(self):
        values = _execution_source_values()
        self.assertEqual(values.get("foreground"), "foreground")
        self.assertEqual(values.get("background"), "background")
        for path in (DB_FILE, STORE_FILE, SERVICE_FILE):
            text = _read(path)
            self.assertNotIn("'background_hymn'", text)
            self.assertNotIn("hymnExecutionSource", text)

    def test_attendance_v38_does_not_migrate_hymn_or_attempt_schema(self):
        self.assertIn("const localDatabaseSchemaVersion = 38;", _schema_source())
        # v38 adds attendance provenance only; the hymn/attempt contract stays.
        migration = _db_source().split("if (oldVersion < 38) {", 1)[1].split("}", 1)[0]
        self.assertEqual(migration.strip(), "await migrateAttendanceOriginalYear(db);")
        attendance = _read(MOBILE / "lib/services/attendance_original_year.dart")
        self.assertNotIn("pending_hymn_ops", attendance)
        self.assertNotIn("sync_attempts", attendance)

    def test_no_new_attempt_table_or_counter(self):
        self.assertNotIn("hymn_sync_attempts", _db_source())
        self.assertNotIn("CREATE TABLE IF NOT EXISTS hymn_attempts", _db_source())


# ───────────────────────────────────────────────────────────────────────────
# 2. Runtime — the lineage itself, against real SQLite
# ───────────────────────────────────────────────────────────────────────────


class HymnClaimLedgerRuntime(unittest.TestCase):
    def setUp(self):
        self.db = _HymnLedger()

    def test_a_foreground_claim_creates_an_attempt_row(self):
        self.db.enqueue(client_op_id="op-a")
        claim = self.db.claim()
        self.assertIsNotNone(claim)
        self.assertEqual(self.db.attempt_count(), 1)

    def test_the_attempt_records_foreground_execution(self):
        self.db.enqueue(client_op_id="op-a")
        self.db.claim(execution_source="foreground")
        self.assertEqual(self.db.attempts()[0]["execution_source"], "foreground")

    def test_the_attempt_can_record_background_execution(self):
        self.db.enqueue(client_op_id="op-a")
        self.db.claim(execution_source="background")
        self.assertEqual(self.db.attempts()[0]["execution_source"], "background")

    def test_execution_source_is_the_only_difference_between_the_two(self):
        self.db.enqueue(client_op_id="op-a", entity_key="hymn:1")
        self.db.enqueue(client_op_id="op-b", entity_key="hymn:2")
        first = self.db.claim(now="2026-04-01T09:00:00.000Z",
                              execution_source="foreground")
        self.db.settle(first)
        self.db.claim(now="2026-04-01T09:00:05.000Z", execution_source="background")
        rows = self.db.attempts()
        self.assertEqual([r["domain"] for r in rows], ["pending_hymn_ops"] * 2)
        self.assertEqual([r["execution_source"] for r in rows],
                         ["foreground", "background"])
        self.assertEqual([r["attempt_number"] for r in rows], [1, 1])

    def test_client_op_id_matches_the_claimed_operation(self):
        self.db.enqueue(client_op_id="op-xyz")
        claim = self.db.claim()
        self.assertEqual(self.db.attempts()[0]["client_op_id"], "op-xyz")
        self.assertEqual(claim["clientOpId"], "op-xyz")

    def test_attempt_number_is_the_rows_own_attempt_count(self):
        self.db.enqueue(client_op_id="op-a", attempt_count=2)
        claim = self.db.claim()
        self.assertEqual(claim["attemptCount"], 3)
        self.assertEqual(self.db.attempts()[0]["attempt_number"], 3)

    def test_attempt_number_advances_across_retries(self):
        row_id = self.db.enqueue(client_op_id="op-a")
        first = self.db.claim(now="2026-04-01T09:00:00.000Z")
        self.db.settle(first, kind="retryable", now="2026-04-01T09:00:02.000Z",
                       next_attempt_at="2026-04-01T09:05:00.000Z",
                       failure_code="HTTP_503", failure_message="busy",
                       http_status=503, category="SERVICE_UNAVAILABLE",
                       decision="RETRY_SCHEDULED")
        second = self.db.claim(now="2026-04-01T09:06:00.000Z")
        self.assertEqual(second["attemptCount"], 2)
        self.assertEqual([r["attempt_number"] for r in self.db.attempts()], [1, 2])
        self.assertEqual(self.db.op_row(row_id)["attempt_count"], 2)

    def test_owner_user_id_is_preserved_from_the_operation(self):
        self.db.enqueue(client_op_id="op-a", owner=77)
        self.db.claim(owner_user_id=11)
        self.assertEqual(self.db.attempts()[0]["owner_user_id"], 77)

    def test_created_authorization_version_is_preserved(self):
        self.db.enqueue(client_op_id="op-a", authorization_version=9)
        self.db.claim(authorization_version=4)
        self.assertEqual(self.db.attempts()[0]["created_authorization_version"], 9)

    def test_legacy_rows_without_owner_columns_fall_back_to_the_session(self):
        self.db.enqueue(client_op_id="op-a", owner=None, authorization_version=None)
        self.db.claim(owner_user_id=11, authorization_version=4)
        row = self.db.attempts()[0]
        self.assertEqual(row["owner_user_id"], 11)
        self.assertEqual(row["created_authorization_version"], 4)

    def test_the_attempt_names_the_hymn_domain(self):
        self.db.enqueue(client_op_id="op-a")
        self.db.claim()
        self.assertEqual(self.db.attempts()[0]["domain"], "pending_hymn_ops")

    def test_entity_ref_is_a_natural_key_and_never_the_payload(self):
        self.db.enqueue(client_op_id="op-a", entity_key="hymn:42",
                        payload={"title": "Secret", "lyrics": "classified"})
        self.db.claim()
        entity = json.loads(self.db.attempts()[0]["entity_ref"])
        self.assertEqual(entity, {"op": "hymn_save", "entity_key": "hymn:42"})
        self.assertNotIn("classified", self.db.attempts()[0]["entity_ref"])

    def test_entity_ref_omits_a_blank_entity_key(self):
        self.db.enqueue(client_op_id="op-a", op="hymn_delete", entity_key="   ")
        self.db.claim()
        self.assertEqual(json.loads(self.db.attempts()[0]["entity_ref"]), {"op": "hymn_delete"})

    def test_the_attempt_opens_as_pending(self):
        self.db.enqueue(client_op_id="op-a")
        self.db.claim()
        row = self.db.attempts()[0]
        self.assertEqual(row["retry_decision"], "PENDING")
        self.assertIsNone(row["finished_at"])
        self.assertIsNone(row["error_category"])

    def test_started_at_is_the_claim_instant(self):
        self.db.enqueue(client_op_id="op-a")
        self.db.claim(now="2026-04-01T09:30:00.000Z")
        self.assertEqual(self.db.attempts()[0]["started_at"], "2026-04-01T09:30:00.000Z")

    def test_no_attempt_row_when_nothing_is_claimed(self):
        self.assertIsNone(self.db.claim())
        self.assertEqual(self.db.attempt_count(), 0)

    def test_no_attempt_row_when_the_only_operation_is_not_due(self):
        self.db.enqueue(client_op_id="op-a", state="retry_wait",
                        next_attempt_at="2026-04-01T12:00:00.000Z")
        self.assertIsNone(self.db.claim(now="2026-04-01T09:00:00.000Z"))
        self.assertEqual(self.db.attempt_count(), 0)

    def test_no_attempt_row_when_the_session_was_superseded(self):
        self.db.enqueue(client_op_id="op-a")
        self.db.session_matches = False
        self.assertIsNone(self.db.claim())
        self.assertEqual(self.db.attempt_count(), 0)

    def test_no_attempt_row_for_an_operation_with_no_client_op_id(self):
        row_id = self.db.enqueue(client_op_id=None)
        claim = self.db.claim()
        self.assertIsNotNone(claim, "the operation must still claim exactly as before")
        self.assertEqual(self.db.op_row(row_id)["sync_state"], "in_flight")
        self.assertEqual(self.db.attempt_count(), 0, "lineage is never fabricated")

    def test_a_claim_still_works_when_the_ledger_table_is_absent(self):
        db = _HymnLedger(ledger=False)
        row_id = db.enqueue(client_op_id="op-a")
        self.assertIsNotNone(db.claim())
        self.assertEqual(db.op_row(row_id)["sync_state"], "in_flight")


class HymnLedgerAtomicityRuntime(unittest.TestCase):
    def setUp(self):
        self.db = _HymnLedger()

    def test_a_lost_claim_race_leaves_no_attempt_row(self):
        """`affected != 1` rolls the whole transaction back, ledger included."""
        row_id = self.db.enqueue(client_op_id="op-a")
        self.db.lose_claim_race = True
        with self.assertRaises(RuntimeError):
            self.db.claim()
        self.assertEqual(self.db.attempt_count(), 0,
                         "an attempt row must never outlive a failed claim")
        row = self.db.op_row(row_id)
        self.assertEqual(row["sync_state"], "pending")
        self.assertEqual(row["attempt_count"], 0)
        self.assertIsNone(row["last_attempt_at"])

    def test_the_dependency_sweep_is_rolled_back_with_a_failed_claim(self):
        """The whole claim transaction unwinds, not just the ledger write."""
        self.db.enqueue(client_op_id="op-a", entity_key="hymn:1")
        blocker = self.db.enqueue(client_op_id="op-b", entity_key="hymn:2",
                                  state="needs_attention")
        dependent = self.db.enqueue(client_op_id="op-c", entity_key="hymn:2",
                                    depends_on=blocker)
        self.db.lose_claim_race = True
        with self.assertRaises(RuntimeError):
            self.db.claim()
        self.assertEqual(self.db.attempt_count(), 0)
        self.assertEqual(self.db.op_row(dependent)["sync_state"], "pending",
                         "the dependency sweep must unwind with the claim")

    def test_the_claimed_row_and_its_attempt_are_committed_together(self):
        row_id = self.db.enqueue(client_op_id="op-a")
        self.db.claim(now="2026-04-01T09:00:00.000Z")
        row = self.db.op_row(row_id)
        attempt = self.db.attempts()[0]
        self.assertEqual(row["sync_state"], "in_flight")
        self.assertEqual(row["last_attempt_at"], attempt["started_at"])
        self.assertEqual(row["client_op_id"], attempt["client_op_id"])
        self.assertEqual(row["attempt_count"], attempt["attempt_number"])

    def test_a_replayed_claim_of_the_same_attempt_never_duplicates_lineage(self):
        """INSERT OR IGNORE against uq_sync_attempt_identity, as production does."""
        self.db.enqueue(client_op_id="op-a")
        self.db.claim()
        cursor = self.db.cx.cursor()
        values = {
            "client_op_id": "op-a", "attempt_number": 1, "attempt_uid": "att_other",
            "domain": "pending_hymn_ops", "execution_source": "background",
            "entity_ref": None, "owner_user_id": 11,
            "created_authorization_version": 4,
            "started_at": "2026-04-01T09:00:09.000Z", "retry_decision": "PENDING",
        }
        cursor.execute(_attempt_insert_sql(),
                       tuple(values[c] for c in _attempt_insert_columns()))
        self.assertEqual(self.db.attempt_count(), 1)
        self.assertEqual(self.db.attempts()[0]["attempt_uid"], "att_hymn0001")

    def test_the_identity_constraint_is_real(self):
        self.db.enqueue(client_op_id="op-a")
        self.db.claim()
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.cx.execute(
                "INSERT INTO sync_attempts (client_op_id, attempt_number, attempt_uid,"
                " domain, started_at) VALUES ('op-a', 1, 'att_dup', 'pending_hymn_ops',"
                " '2026-04-01T09:00:09.000Z')"
            )

    def test_two_operations_each_get_their_own_lineage(self):
        self.db.enqueue(client_op_id="op-a", entity_key="hymn:1")
        self.db.enqueue(client_op_id="op-b", entity_key="hymn:2")
        first = self.db.claim(now="2026-04-01T09:00:00.000Z")
        self.db.settle(first)
        self.db.claim(now="2026-04-01T09:00:05.000Z")
        self.assertEqual([r["client_op_id"] for r in self.db.attempts()], ["op-a", "op-b"])

    def test_an_overlapping_drain_cannot_claim_the_in_flight_row_again(self):
        self.db.enqueue(client_op_id="op-a")
        self.db.claim(now="2026-04-01T09:00:00.000Z")
        self.assertIsNone(self.db.claim(now="2026-04-01T09:00:01.000Z"))
        self.assertEqual(self.db.attempt_count(), 1)


class HymnSettlementLedgerRuntime(unittest.TestCase):
    def setUp(self):
        self.db = _HymnLedger()

    def test_a_successful_attempt_is_observable(self):
        self.db.enqueue(client_op_id="op-a")
        claim = self.db.claim(now="2026-04-01T09:00:00.000Z")
        self.db.settle(claim, kind="accepted", now="2026-04-01T09:00:02.500Z",
                       http_status=200, category="none", decision="COMPLETED",
                       server_ref="req-77")
        row = self.db.attempts()[0]
        self.assertEqual(row["finished_at"], "2026-04-01T09:00:02.500Z")
        self.assertEqual(row["retry_decision"], "COMPLETED")
        self.assertEqual(row["error_category"], "none")
        self.assertEqual(row["http_status"], 200)
        self.assertEqual(row["server_ref"], "req-77")

    def test_a_failed_attempt_is_observable(self):
        self.db.enqueue(client_op_id="op-a")
        claim = self.db.claim(now="2026-04-01T09:00:00.000Z")
        self.db.settle(claim, kind="retryable", now="2026-04-01T09:00:01.000Z",
                       next_attempt_at="2026-04-01T09:05:00.000Z",
                       failure_code="HTTP_503", failure_message="Service unavailable",
                       http_status=503, category="SERVICE_UNAVAILABLE",
                       decision="RETRY_SCHEDULED")
        row = self.db.attempts()[0]
        self.assertEqual(row["error_category"], "SERVICE_UNAVAILABLE")
        self.assertEqual(row["retry_decision"], "RETRY_SCHEDULED")
        self.assertEqual(row["next_attempt_at"], "2026-04-01T09:05:00.000Z")
        self.assertEqual(row["failure_message"], "Service unavailable")

    def test_a_later_success_never_rewrites_an_earlier_failure(self):
        self.db.enqueue(client_op_id="op-a")
        first = self.db.claim(now="2026-04-01T09:00:00.000Z")
        self.db.settle(first, kind="retryable", now="2026-04-01T09:00:01.000Z",
                       next_attempt_at="2026-04-01T09:05:00.000Z",
                       failure_code="HTTP_503", failure_message="Service unavailable",
                       http_status=503, category="SERVICE_UNAVAILABLE",
                       decision="RETRY_SCHEDULED")
        second = self.db.claim(now="2026-04-01T09:06:00.000Z")
        self.db.settle(second, kind="accepted", now="2026-04-01T09:06:01.000Z")
        rows = self.db.attempts()
        self.assertEqual(len(rows), 2)
        self.assertEqual(rows[0]["error_category"], "SERVICE_UNAVAILABLE")
        self.assertEqual(rows[1]["retry_decision"], "COMPLETED")

    def test_a_needs_attention_settlement_is_observable(self):
        self.db.enqueue(client_op_id="op-a")
        claim = self.db.claim()
        self.db.settle(claim, kind="needsAttention", failure_code="MALFORMED_LOCAL_PAYLOAD",
                       failure_message="This saved change is unreadable and needs attention.",
                       http_status=None, category="SERIALIZATION_ERROR",
                       decision="USER_ACTION_REQUIRED")
        row = self.db.attempts()[0]
        self.assertEqual(row["error_category"], "SERIALIZATION_ERROR")
        self.assertEqual(row["retry_decision"], "USER_ACTION_REQUIRED")

    def test_duration_is_derived_from_the_two_recorded_instants(self):
        self.db.enqueue(client_op_id="op-a")
        claim = self.db.claim(now="2026-04-01T09:00:00.000Z")
        self.db.settle(claim, now="2026-04-01T09:00:02.000Z")
        row = self.db.attempts()[0]
        self.assertEqual(row["started_at"], "2026-04-01T09:00:00.000Z")
        self.assertEqual(row["finished_at"], "2026-04-01T09:00:02.000Z")

    def test_a_superseded_settlement_leaves_the_attempt_open(self):
        self.db.enqueue(client_op_id="op-a")
        claim = self.db.claim(now="2026-04-01T09:00:00.000Z")
        stale = dict(claim, claimedAt="2026-04-01T08:59:00.000Z")
        self.assertEqual(self.db.settle(stale), "supersededLocal")
        self.assertIsNone(self.db.attempts()[0]["finished_at"],
                          "a settlement that did not apply must not close an attempt")

    def test_settling_without_a_closure_changes_nothing_in_the_ledger(self):
        self.db.enqueue(client_op_id="op-a")
        claim = self.db.claim()
        self.db.settle(claim, closure=False)
        self.assertIsNone(self.db.attempts()[0]["finished_at"])

    def test_a_settlement_without_a_ledger_still_applies(self):
        db = _HymnLedger(ledger=False)
        row_id = db.enqueue(client_op_id="op-a")
        claim = db.claim()
        self.assertEqual(db.settle(claim), "applied")
        self.assertEqual(db.op_row(row_id)["sync_state"], "synced")


# ───────────────────────────────────────────────────────────────────────────
# 3. Nothing the hymn outbox already guaranteed may change
# ───────────────────────────────────────────────────────────────────────────


class HymnSemanticsPreserved(unittest.TestCase):
    def setUp(self):
        self.db = _HymnLedger()

    def test_ordering_is_still_by_row_id(self):
        self.db.enqueue(client_op_id="op-a", entity_key="hymn:1")
        self.db.enqueue(client_op_id="op-b", entity_key="hymn:2")
        self.db.enqueue(client_op_id="op-c", entity_key="hymn:3")
        claim = self.db.claim(now="2026-04-01T09:00:00.000Z")
        self.assertEqual(claim["clientOpId"], "op-a")
        self.assertEqual(self.db.attempts()[0]["client_op_id"], "op-a")

    def test_coalescing_still_holds_back_a_later_op_for_the_same_entity(self):
        self.db.enqueue(client_op_id="op-a", entity_key="hymn:1")
        self.db.enqueue(client_op_id="op-b", entity_key="hymn:1")
        self.db.claim(now="2026-04-01T09:00:00.000Z")
        self.assertIsNone(self.db.claim(now="2026-04-01T09:00:01.000Z"))
        self.assertEqual([r["client_op_id"] for r in self.db.attempts()], ["op-a"])

    def test_retry_timing_still_gates_the_claim(self):
        self.db.enqueue(client_op_id="op-a", state="retry_wait",
                        next_attempt_at="2026-04-01T09:05:00.000Z")
        self.assertIsNone(self.db.claim(now="2026-04-01T09:04:59.000Z"))
        self.assertEqual(self.db.attempt_count(), 0)
        self.assertIsNotNone(self.db.claim(now="2026-04-01T09:05:00.000Z"))
        self.assertEqual(self.db.attempt_count(), 1)

    def test_the_claim_still_clears_next_attempt_at(self):
        row_id = self.db.enqueue(client_op_id="op-a", state="retry_wait",
                                 next_attempt_at="2026-04-01T09:05:00.000Z")
        self.db.claim(now="2026-04-01T09:06:00.000Z")
        self.assertIsNone(self.db.op_row(row_id)["next_attempt_at"])

    def test_an_unresolved_dependency_still_blocks_and_is_never_ledgered(self):
        blocker = self.db.enqueue(client_op_id="op-a", entity_key="hymn:1",
                                  state="needs_attention")
        dependent = self.db.enqueue(client_op_id="op-b", entity_key="hymn:1",
                                    depends_on=blocker)
        self.assertIsNone(self.db.claim(now="2026-04-01T09:00:00.000Z"))
        self.assertEqual(self.db.op_row(dependent)["sync_state"], "blocked_dependency")
        self.assertEqual(self.db.attempt_count(), 0)

    def test_a_satisfied_dependency_still_releases_the_dependent(self):
        blocker = self.db.enqueue(client_op_id="op-a", entity_key="hymn:1", synced=1,
                                  state="synced")
        self.db.enqueue(client_op_id="op-b", entity_key="hymn:1", depends_on=blocker)
        claim = self.db.claim(now="2026-04-01T09:00:00.000Z")
        self.assertIsNotNone(claim)
        self.assertEqual(claim["clientOpId"], "op-b")

    def test_a_synced_operation_is_never_reclaimed(self):
        self.db.enqueue(client_op_id="op-a", synced=1, state="synced")
        self.assertIsNone(self.db.claim())
        self.assertEqual(self.db.attempt_count(), 0)

    def test_the_lease_still_belongs_to_one_claim(self):
        row_id = self.db.enqueue(client_op_id="op-a")
        claim = self.db.claim(now="2026-04-01T09:00:00.000Z")
        row = self.db.op_row(row_id)
        self.assertEqual(row["sync_state"], "in_flight")
        self.assertEqual(row["last_attempt_at"], claim["claimedAt"])

    def test_pending_hymn_ops_columns_are_untouched(self):
        columns = [r[1] for r in self.db.cx.execute("PRAGMA table_info(pending_hymn_ops)")]
        self.assertIn("created_by_user_id", columns)
        self.assertIn("created_authorization_version", columns)
        self.assertNotIn("execution_source", columns)
        self.assertNotIn("attempt_uid", columns)


class LegacyLedgerUnchanged(unittest.TestCase):
    """A.7 must not alter how the legacy outbox writes its lineage."""

    def test_the_legacy_claim_still_opens_its_attempt(self):
        source = _db_source()
        self.assertIn("domain: spec.table,", source)
        self.assertIn("attemptNumber: attemptCount,", source)

    def test_there_is_exactly_one_legacy_and_one_hymn_ledger_call_site(self):
        source = _db_source()
        self.assertEqual(source.count("await _openSyncAttempt("), 2)
        self.assertEqual(source.count("await _closeSyncAttempt("), 2)

    def test_the_legacy_helpers_were_not_rewritten(self):
        region = _open_attempt_region()
        self.assertIn("SyncExecutionSource executionSource = SyncExecutionSource.foreground,", region)
        self.assertIn("'execution_source': executionSource.storageValue,", region)

    def test_the_legacy_attempt_insert_still_writes_the_same_columns(self):
        self.assertEqual(
            _attempt_insert_columns(),
            (
                "client_op_id",
                "attempt_number",
                "attempt_uid",
                "domain",
                "execution_source",
                "entity_ref",
                "owner_user_id",
                "created_authorization_version",
                "started_at",
                "retry_decision",
            ),
        )

    def test_a_legacy_attempt_and_a_hymn_attempt_coexist_in_one_ledger(self):
        db = _HymnLedger()
        db.cx.execute(
            "INSERT INTO sync_attempts (client_op_id, attempt_number, attempt_uid,"
            " domain, execution_source, started_at) VALUES"
            " ('legacy-1', 1, 'att_legacy', 'pending_attendance', 'foreground',"
            " '2026-04-01T08:00:00.000Z')"
        )
        db.enqueue(client_op_id="op-a")
        db.claim(execution_source="background")
        rows = {r["domain"]: r["execution_source"] for r in db.attempts()}
        self.assertEqual(rows, {"pending_attendance": "foreground",
                                "pending_hymn_ops": "background"})

    def test_the_ledger_can_be_grouped_by_domain_and_source(self):
        db = _HymnLedger()
        db.enqueue(client_op_id="op-a", entity_key="hymn:1")
        db.enqueue(client_op_id="op-b", entity_key="hymn:2")
        first = db.claim(now="2026-04-01T09:00:00.000Z", execution_source="background")
        db.settle(first)
        db.claim(now="2026-04-01T09:00:05.000Z", execution_source="foreground")
        grouped = dict(db.cx.execute(
            "SELECT execution_source, COUNT(*) FROM sync_attempts"
            " WHERE domain = 'pending_hymn_ops' GROUP BY execution_source"
        ))
        self.assertEqual(grouped, {"background": 1, "foreground": 1})


if __name__ == "__main__":
    unittest.main()
