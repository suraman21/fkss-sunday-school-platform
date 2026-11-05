/// Exact atomic receipt for one grades outbox operation.
/// Mirrors attendance receipt: same tx as outbox `synced=1`.
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
