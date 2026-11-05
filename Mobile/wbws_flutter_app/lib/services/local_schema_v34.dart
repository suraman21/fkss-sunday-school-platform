/// Declarative SQLite v34 schema contract.
///
/// This file intentionally has no Flutter dependency. The runtime SQLite
/// migration harness reads these declarations as its schema source of truth.
const localDatabaseSchemaVersion = 42;

const attendancePreparationSnapshotSql = '''
  CREATE TABLE IF NOT EXISTS attendance_preparation_snapshot (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    preparation_id TEXT NOT NULL,
    owner_user_id INTEGER NOT NULL,
    authorization_version INTEGER NOT NULL,
    server_identity TEXT NOT NULL,
    issued_at INTEGER NOT NULL,
    deadline INTEGER NOT NULL,
    payload_sha256 TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    write_year_id INTEGER NOT NULL,
    generation INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  )
''';

const gradesPreparationSnapshotSql = '''
  CREATE TABLE IF NOT EXISTS grades_preparation_snapshot (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    preparation_id TEXT NOT NULL,
    owner_user_id INTEGER NOT NULL,
    authorization_version INTEGER NOT NULL,
    server_identity TEXT NOT NULL,
    issued_at INTEGER NOT NULL,
    deadline INTEGER NOT NULL,
    payload_sha256 TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    write_year_id INTEGER NOT NULL,
    generation INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  )
''';

const gradesSyncReceiptSql = '''
  CREATE TABLE IF NOT EXISTS grades_sync_receipt (
    client_op_id TEXT PRIMARY KEY,
    owner_user_id INTEGER NOT NULL,
    authorization_version INTEGER NOT NULL,
    generation INTEGER NOT NULL,
    assessment_id INTEGER NOT NULL,
    http_status INTEGER NOT NULL,
    receipt_json TEXT NOT NULL,
    server_request_id TEXT,
    created_at TEXT NOT NULL
  )
''';

const gradesSyncReceiptIndexSql = '''
  CREATE INDEX IF NOT EXISTS idx_grades_receipt_owner
    ON grades_sync_receipt(owner_user_id, assessment_id)
''';


final class LocalColumnSpec {
  final String table;
  final String name;
  final String declaration;

  const LocalColumnSpec(this.table, this.name, this.declaration);
}

final class LegacyOutboxTableSpec {
  final String table;
  final List<String> businessKeyColumns;

  const LegacyOutboxTableSpec(this.table, this.businessKeyColumns);
}

/// Download-sync cursor state, one row per domain.
///
/// Deliberately generic: `domain` is the key, so grades, members and the
/// rest reuse this table instead of each growing its own. It mirrors the
/// server's feed contract — `cursor` is the last revision whose changes
/// were fully applied locally, and it is only ever advanced after the
/// apply transaction commits, so a crash mid-apply re-pulls rather than
/// skipping. `status`/`error` give the UI something honest to show when
/// a pull fails without blocking the app from opening offline.
const localSyncStateV35Sql = '''
  CREATE TABLE IF NOT EXISTS sync_state (
    domain TEXT PRIMARY KEY,
    cursor INTEGER NOT NULL DEFAULT 0,
    last_sync_at TEXT,
    status TEXT NOT NULL DEFAULT 'idle',
    error TEXT
  )
''';

const localSessionStateV34Sql = '''
  CREATE TABLE IF NOT EXISTS local_session_state (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    owner_user_id INTEGER,
    owner_username TEXT,
    owner_display_name TEXT,
    owner_role TEXT,
    owner_authorization_version INTEGER,
    state TEXT NOT NULL,
    reason TEXT,
    generation INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL
  )
''';

/// Durable operation/attempt lineage (schema v36, phase S1).
///
/// The outbox row already records the *current* state of an operation —
/// `attempt_count`, `last_attempt_at`, `failure_code`, `failure_http_status`.
/// What it cannot express is history: every attempt overwrites the previous
/// one, so "this succeeded on the fourth try after three timeouts" is
/// indistinguishable from "this succeeded first time".
///
/// This table is append-and-close only. It never participates in deciding
/// what to send: `sync_state` on the outbox row remains the single
/// authoritative execution state. One row here = one real transmission.
///
/// Identity: `client_op_id` is the operation (it is already stable across
/// retries, restarts and recovery, and is the same value the server stores
/// as `api_idempotency_records.idem_key`). `attempt_number` mirrors the
/// outbox row's `attempt_count` at claim time, so the two can never silently
/// disagree. `attempt_uid` is this one transmission's correlation id.
///
/// Privacy: identifiers, timings, status codes and controlled categories
/// only. `entity_ref` carries the operation's natural key (for example
/// `{"class_id":7,"date":"2026-03-01"}`) and never member rows, names,
/// message bodies, marks or credentials.
const localSyncAttemptsV36Sql = '''
  CREATE TABLE IF NOT EXISTS sync_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_op_id TEXT NOT NULL,
    attempt_number INTEGER NOT NULL,
    attempt_uid TEXT NOT NULL,
    domain TEXT NOT NULL,
    entity_ref TEXT,
    owner_user_id INTEGER,
    created_authorization_version INTEGER,
    started_at TEXT NOT NULL,
    finished_at TEXT,
    duration_ms INTEGER,
    http_status INTEGER,
    error_category TEXT,
    retry_decision TEXT NOT NULL DEFAULT 'PENDING',
    failure_message TEXT,
    next_attempt_at TEXT,
    server_ref TEXT,
    -- S2 Goal A.1: which kind of trigger ran the drain that produced this
    -- attempt. 'foreground' is the correct value for every row written
    -- before v37, because no background execution path existed.
    execution_source TEXT NOT NULL DEFAULT 'foreground'
  )
''';

/// Index contract for `sync_attempts`.
///
/// The two UNIQUE indexes are load-bearing, not optimisations:
///   * `uq_sync_attempt_identity` makes a non-incrementing attempt number a
///     hard constraint violation rather than a silently corrupted lineage.
///   * `uq_sync_attempt_uid` keeps one correlation id to one transmission.
const localSyncAttemptsV36IndexSql = <String>[
  '''CREATE UNIQUE INDEX IF NOT EXISTS uq_sync_attempt_identity
     ON sync_attempts(client_op_id, attempt_number)''',
  '''CREATE UNIQUE INDEX IF NOT EXISTS uq_sync_attempt_uid
     ON sync_attempts(attempt_uid)''',
  '''CREATE INDEX IF NOT EXISTS idx_sync_attempts_operation
     ON sync_attempts(client_op_id, attempt_number)''',
  '''CREATE INDEX IF NOT EXISTS idx_sync_attempts_open
     ON sync_attempts(finished_at, started_at)''',
  '''CREATE INDEX IF NOT EXISTS idx_sync_attempts_recent
     ON sync_attempts(started_at)''',
];

const legacyOutboxTableSpecs = <LegacyOutboxTableSpec>[
  LegacyOutboxTableSpec('pending_attendance', ['class_id', 'date']),
  LegacyOutboxTableSpec('pending_grades', ['assessment_id']),
  LegacyOutboxTableSpec('pending_mezmur', ['date', 'section']),
  LegacyOutboxTableSpec('pending_hr', ['date', 'section']),
];

const localV34ColumnSpecs = <LocalColumnSpec>[
  LocalColumnSpec('pending_attendance', 'sync_state', "TEXT NOT NULL DEFAULT 'pending'"),
  LocalColumnSpec('pending_attendance', 'attempt_count', "INTEGER NOT NULL DEFAULT 0"),
  LocalColumnSpec('pending_attendance', 'next_attempt_at', "TEXT"),
  LocalColumnSpec('pending_attendance', 'last_attempt_at', "TEXT"),
  LocalColumnSpec('pending_attendance', 'failure_code', "TEXT"),
  LocalColumnSpec('pending_attendance', 'failure_http_status', "INTEGER"),
  LocalColumnSpec('pending_attendance', 'failed_at', "TEXT"),
  LocalColumnSpec('pending_attendance', 'created_authorization_version', "INTEGER"),
  LocalColumnSpec('pending_attendance', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('pending_grades', 'sync_state', "TEXT NOT NULL DEFAULT 'pending'"),
  LocalColumnSpec('pending_grades', 'attempt_count', "INTEGER NOT NULL DEFAULT 0"),
  LocalColumnSpec('pending_grades', 'next_attempt_at', "TEXT"),
  LocalColumnSpec('pending_grades', 'last_attempt_at', "TEXT"),
  LocalColumnSpec('pending_grades', 'failure_code', "TEXT"),
  LocalColumnSpec('pending_grades', 'failure_http_status', "INTEGER"),
  LocalColumnSpec('pending_grades', 'failed_at', "TEXT"),
  LocalColumnSpec('pending_grades', 'created_authorization_version', "INTEGER"),
  LocalColumnSpec('pending_grades', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('pending_mezmur', 'sync_state', "TEXT NOT NULL DEFAULT 'pending'"),
  LocalColumnSpec('pending_mezmur', 'attempt_count', "INTEGER NOT NULL DEFAULT 0"),
  LocalColumnSpec('pending_mezmur', 'next_attempt_at', "TEXT"),
  LocalColumnSpec('pending_mezmur', 'last_attempt_at', "TEXT"),
  LocalColumnSpec('pending_mezmur', 'failure_code', "TEXT"),
  LocalColumnSpec('pending_mezmur', 'failure_http_status', "INTEGER"),
  LocalColumnSpec('pending_mezmur', 'failed_at', "TEXT"),
  LocalColumnSpec('pending_mezmur', 'created_authorization_version', "INTEGER"),
  LocalColumnSpec('pending_mezmur', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('pending_hr', 'sync_state', "TEXT NOT NULL DEFAULT 'pending'"),
  LocalColumnSpec('pending_hr', 'attempt_count', "INTEGER NOT NULL DEFAULT 0"),
  LocalColumnSpec('pending_hr', 'next_attempt_at', "TEXT"),
  LocalColumnSpec('pending_hr', 'last_attempt_at', "TEXT"),
  LocalColumnSpec('pending_hr', 'failure_code', "TEXT"),
  LocalColumnSpec('pending_hr', 'failure_http_status', "INTEGER"),
  LocalColumnSpec('pending_hr', 'failed_at', "TEXT"),
  LocalColumnSpec('pending_hr', 'created_authorization_version', "INTEGER"),
  LocalColumnSpec('pending_hr', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('pending_hymn_ops', 'sync_state', "TEXT NOT NULL DEFAULT 'pending'"),
  LocalColumnSpec('pending_hymn_ops', 'attempt_count', "INTEGER NOT NULL DEFAULT 0"),
  LocalColumnSpec('pending_hymn_ops', 'next_attempt_at', "TEXT"),
  LocalColumnSpec('pending_hymn_ops', 'last_attempt_at', "TEXT"),
  LocalColumnSpec('pending_hymn_ops', 'failure_code', "TEXT"),
  LocalColumnSpec('pending_hymn_ops', 'failure_http_status', "INTEGER"),
  LocalColumnSpec('pending_hymn_ops', 'failed_at', "TEXT"),
  LocalColumnSpec('pending_hymn_ops', 'created_authorization_version', "INTEGER"),
  LocalColumnSpec('pending_hymn_ops', 'created_by_user_id', 'INTEGER'),
  LocalColumnSpec('pending_hymn_ops', 'entity_key', 'TEXT'),
  LocalColumnSpec('pending_hymn_ops', 'depends_on', 'INTEGER'),
  LocalColumnSpec('comm_outbox', 'last_attempt_at', 'TEXT'),
  LocalColumnSpec('comm_outbox', 'failure_code', 'TEXT'),
  LocalColumnSpec('comm_outbox', 'failure_http_status', 'INTEGER'),
  LocalColumnSpec('comm_outbox', 'failed_at', 'TEXT'),
  LocalColumnSpec('comm_outbox', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('comm_outbox', 'created_authorization_version', 'INTEGER'),
  LocalColumnSpec('comm_drafts', 'owner_user_id', 'INTEGER'),
  LocalColumnSpec('comm_drafts', 'created_authorization_version', 'INTEGER'),
];

const localV34IndexSql = <String>[
  '''CREATE INDEX IF NOT EXISTS idx_pending_attendance_operation
     ON pending_attendance(client_op_id, sync_state, synced)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_attendance_owner_due
     ON pending_attendance(owner_user_id, sync_state, next_attempt_at)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_attendance_overlay
     ON pending_attendance(class_id, date, synced, sync_state)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_grades_operation
     ON pending_grades(client_op_id, sync_state, synced)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_grades_owner_due
     ON pending_grades(owner_user_id, sync_state, next_attempt_at)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_grades_overlay
     ON pending_grades(assessment_id, synced, sync_state)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_mezmur_operation
     ON pending_mezmur(client_op_id, sync_state, synced)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_mezmur_owner_due
     ON pending_mezmur(owner_user_id, sync_state, next_attempt_at)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_mezmur_overlay
     ON pending_mezmur(date, section, synced, sync_state)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hr_operation
     ON pending_hr(client_op_id, sync_state, synced)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hr_owner_due
     ON pending_hr(owner_user_id, sync_state, next_attempt_at)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hr_overlay
     ON pending_hr(date, section, synced, sync_state)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hymn_operation
     ON pending_hymn_ops(client_op_id, sync_state, synced)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hymn_due
     ON pending_hymn_ops(sync_state, next_attempt_at, id)''',
  '''CREATE INDEX IF NOT EXISTS idx_pending_hymn_dependency
     ON pending_hymn_ops(depends_on, sync_state)''',
  '''CREATE INDEX IF NOT EXISTS idx_comm_outbox_owner_due
     ON comm_outbox(owner_user_id, state, next_attempt_at, created_at)''',
  '''CREATE INDEX IF NOT EXISTS idx_comm_outbox_thread_order
     ON comm_outbox(thread_id, state, next_attempt_at, created_at, client_tag)''',
  '''CREATE INDEX IF NOT EXISTS idx_comm_drafts_owner_updated
     ON comm_drafts(owner_user_id, updated_at)''',
];
