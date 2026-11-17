-- Migration 096: mobile offline photo upload foundation.
--
-- Supports:
--   * POST /api/v1/members/{id}/photo (WebP/JPEG, multipart, idempotent by
--     client_op_id + sha256).
--   * Multi-photographer conflict resolution via a candidates table
--     (member_photo_candidates); server never silently overwrites one
--     photographer's shot with another's.
--   * Audit trail (photo_upload_receipts) so the mobile app and the ZIP
--     importer both converge on the same server-side record of which
--     photos were accepted.
--   * Per-device attribution (device_id) for cross-photographer diffs.
--
-- This migration is additive — it does not modify the existing members
-- table. student_photo_path is updated in the same transaction that
-- flips is_current on the winning candidate row.

-- ------------------------------------------------------------------
-- photo_upload_receipts
--
-- One row per photo that landed on the server, regardless of path
-- (mobile sync, ZIP import, future web upload). Keyed by client_op_id
-- (from mobile) or import_uuid (from ZIP batches). We keep both keys
-- nullable because ZIP imports don't have client_op_ids. The
-- (operator_admin_id, sha256) pair lets us answer "did this operator
-- already give us this exact file" quickly.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS photo_upload_receipts (
  id                  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  client_op_id        VARCHAR(64) NULL,
  import_item_id      INT(10) UNSIGNED NULL,
  member_id           INT(10) UNSIGNED NOT NULL,
  field               VARCHAR(32) NOT NULL DEFAULT 'student_photo',
  operator_admin_id   INT(10) UNSIGNED NOT NULL,
  device_id           VARCHAR(64) NULL,
  sha256              CHAR(64) NOT NULL,
  stored_path         VARCHAR(255) NOT NULL,
  mime                VARCHAR(32) NOT NULL,
  byte_size           INTEGER NOT NULL,
  width               INTEGER NULL,
  height              INTEGER NULL,
  taken_at            DATETIME NULL,
  created_at          DATETIME NOT NULL,
  UNIQUE KEY uq_photo_receipt_op (client_op_id),
  PRIMARY KEY (id),
  KEY idx_photo_receipt_member (member_id, field, created_at),
  KEY idx_photo_receipt_sha (operator_admin_id, sha256),
  KEY idx_photo_receipt_import (import_item_id),
  CONSTRAINT fk_photo_receipt_member
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- member_photo_candidates
--
-- Every photo is a candidate until an admin explicitly marks it as
-- current (auto-wins for same-operator retakes and first-photo cases
-- are set is_current=1 at write time; cross-photographer duplicates
-- land with is_current=0, decision_pending=1 for admin review).
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS member_photo_candidates (
  id                  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id           INT(10) UNSIGNED NOT NULL,
  field               VARCHAR(32) NOT NULL DEFAULT 'student_photo',
  receipt_id          INT(10) UNSIGNED NOT NULL,
  operator_admin_id   INT(10) UNSIGNED NOT NULL,
  device_id           VARCHAR(64) NULL,
  sha256              CHAR(64) NOT NULL,
  stored_path         VARCHAR(255) NOT NULL,
  mime                VARCHAR(32) NOT NULL,
  byte_size           INTEGER NOT NULL,
  width               INTEGER NULL,
  height              INTEGER NULL,
  taken_at            DATETIME NULL,
  is_current          TINYINT(1) NOT NULL DEFAULT 0,
  decision_pending    TINYINT(1) NOT NULL DEFAULT 0,
  archived            TINYINT(1) NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL,
  decided_at          DATETIME NULL,
  decided_by_admin_id INT(10) UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_photo_cand_member_current (member_id, field, is_current, archived),
  KEY idx_photo_cand_pending (decision_pending, field, created_at),
  UNIQUE KEY uq_photo_cand_receipt (receipt_id),
  CONSTRAINT fk_photo_cand_member
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_photo_cand_receipt
    FOREIGN KEY (receipt_id) REFERENCES photo_upload_receipts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Concurrency invariant for is_current:
-- At most one candidate per (member_id, field) can have is_current=1.
-- Enforced in application code inside a transaction:
--   1. Insert new candidate (is_current=0)
--   2. UPDATE member_photo_candidates SET is_current=0
--        WHERE member_id=? AND field=? AND is_current=1
--   3. UPDATE member_photo_candidates SET is_current=1 WHERE id=?
--   4. UPDATE members SET student_photo_path=? WHERE id=?
-- A regular index supports the lookup; we do not attempt a partial
-- unique index (MySQL 5.x compatibility varies). The SELECT in step 5
-- asserts the invariant as a defensive check.
CREATE INDEX idx_photo_cand_current_lookup
  ON member_photo_candidates(member_id, field, is_current);

-- ------------------------------------------------------------------
-- photo_import_batches / photo_import_items
--
-- These are also used by ZIP imports (see OFFLINE_PHOTO_ZIP_IMPORT_DESIGN).
-- They live in this migration because the PhotoSyncWorker needs the
-- batches table to close out ZIP lineage on device feedback.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS photo_import_batches (
  id                  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_uuid          VARCHAR(64) NOT NULL,
  operator_admin_id   INT(10) UNSIGNED NOT NULL,
  device_id           VARCHAR(64) NULL,
  source              VARCHAR(16) NOT NULL DEFAULT 'zip',  -- 'zip' | 'web'
  manifest_sig_valid  TINYINT(1) NOT NULL DEFAULT 1,
  total_photos        INTEGER NOT NULL DEFAULT 0,
  committed_photos    INTEGER NOT NULL DEFAULT 0,
  status              VARCHAR(24) NOT NULL DEFAULT 'uploaded',
  zip_path            VARCHAR(255) NULL,
  manifest_json       MEDIUMTEXT NULL,
  created_at          DATETIME NOT NULL,
  committed_at        DATETIME NULL,
  UNIQUE KEY uq_photo_import_uuid (batch_uuid),
  PRIMARY KEY (id),
  KEY idx_photo_import_operator (operator_admin_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS photo_import_items (
  id                  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id            INT(10) UNSIGNED NOT NULL,
  seq                 INTEGER NOT NULL,
  member_id           INT(10) UNSIGNED NULL,
  field               VARCHAR(32) NOT NULL DEFAULT 'student_photo',
  sha256              CHAR(64) NOT NULL,
  decision            VARCHAR(24) NOT NULL DEFAULT 'pending',
  resolved_member_id  INT(10) UNSIGNED NULL,
  receipt_id          INT(10) UNSIGNED NULL,
  created_at          DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_photo_import_item_batch (batch_id, seq),
  CONSTRAINT fk_photo_import_item_batch
    FOREIGN KEY (batch_id) REFERENCES photo_import_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_photo_import_item_receipt
    FOREIGN KEY (receipt_id) REFERENCES photo_upload_receipts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Permissions
-- ------------------------------------------------------------------
-- The photo_importer role is introduced as a least-privilege account
-- for volunteers who only handle ZIP uploads. We store it in the
-- existing user_roles table; no schema change required here. The
-- access_control layer and admin UI check for this role separately.
--
-- Adding the role itself is done by an INSERT in the seed/ops layer
-- (roles are typically seeded by config.php or a migration seeding
-- the roles table), not in this DDL.
