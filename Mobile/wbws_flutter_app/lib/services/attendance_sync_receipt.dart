/// Exact atomic receipt for one attendance outbox operation.
///
/// The server's response for POST /attendance and POST /attendance/submit is
/// already atomic (attendance rows + workflow packet + idempotency record in
/// one MySQL transaction). The client must mirror that atomicity: the local
/// business rows and the receipt that proves their acceptance must commit or
/// roll back together, never separately.
///
/// This file is the durable contract only (v40). No existing code writes to
/// this table yet — the future SyncService drain will INSERT the receipt in
/// the same SQLite transaction that marks the outbox row `synced=1` / `completed`.
/// Reads are advisory (banner, reconciliation) and never synthesize a receipt.
///
/// Schema: one row per `client_op_id` (the idempotency key). `receipt_json`
/// stores the server's exact response body (business + receipt fields) as
/// text, `server_request_id` stores `X-Request-Id` when present, and
/// `http_status`/`created_at` provide audit lineage. `owner_user_id`/`AV`/
/// `generation` bind the receipt to the session that produced it, so a
/// cross-account restore cannot silently attribute another user's receipt.
const attendanceSyncReceiptSql = '''
  CREATE TABLE IF NOT EXISTS attendance_sync_receipt (
    client_op_id TEXT PRIMARY KEY,
    owner_user_id INTEGER NOT NULL,
    authorization_version INTEGER NOT NULL,
    generation INTEGER NOT NULL,
    class_id INTEGER NOT NULL,
    date TEXT NOT NULL,
    http_status INTEGER NOT NULL,
    receipt_json TEXT NOT NULL,
    server_request_id TEXT,
    created_at TEXT NOT NULL
  )
''';

const attendanceSyncReceiptIndexSql = '''
  CREATE INDEX IF NOT EXISTS idx_attendance_receipt_owner
    ON attendance_sync_receipt(owner_user_id, date, class_id)
''';
