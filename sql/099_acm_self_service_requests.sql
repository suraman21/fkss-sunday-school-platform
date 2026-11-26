-- ============================================================
-- 099_acm_self_service_requests.sql   (IDEMPOTENT / RE-RUNNABLE)
-- ACM self-service activation: the member-initiated request
-- (design: docs/design/acm-self-service-activation.md)
--
-- Why a separate table and not acm_activation_tickets:
--   A ticket is created BY a staff actor (`ticket.create` is
--   accounts_office only) and is already a trusted office record.
--   A request is created by an UNAUTHENTICATED stranger, carries
--   only a claim, and must be reviewed before any person is bound.
--   Mixing them would put untrusted rows in the queue that the
--   policy layer assumes are trustworthy.
--
-- The one invariant this table exists to enforce:
--   two identical public requests, landing in the same
--   microsecond, must produce EXACTLY ONE row and ONE request_ref.
--   MySQL has no partial unique index, so the guard is a plain
--   UNIQUE key on a hash of the normalized claim over ALL rows of
--   that claim. A second concurrent INSERT fails with 23000; the
--   service catches it and returns the existing request_ref, so
--   the response is identical whether the caller won or lost.
--
-- Privacy: nothing here is a verified fact. member_code and
-- full_name are what the stranger TYPED (normalized), stored so
-- the officer can compare them to the real record. They are never
-- used to authorize anything before an officer approves.
--
-- Shared-hosting safe: no FOREIGN KEY (shared hosts and this
-- repo's migrations forbid them), no JSON column type, ENUM
-- append-only discipline is not needed here because the table is
-- new.
-- ============================================================

CREATE TABLE IF NOT EXISTS `acm_activation_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- Short human reference shown to the caller and read over the
  -- phone. Crockford base32, 8 chars: unambiguous when spoken.
  `request_ref` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,

  -- SHA-256 of "account_type|normalized_code|normalized_name|phone_last4".
  -- UNIQUE on purpose: see the invariant above.
  `dedupe_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- Number of identical submissions collapsed onto this row. Useful
  -- operationally (10 attempts = someone is stuck, or someone is
  -- probing) and cheap to carry.
  `request_count` INT UNSIGNED NOT NULL DEFAULT 1,

  -- The claim, normalized at the boundary:
  --   member_code : uppercase, separators stripped
  --   name_key    : lowercase, non-alphanumerics stripped, single-spaced
  --   phone_last4 : digits only, may be NULL when not supplied
  `account_type` ENUM('member','parent') NOT NULL DEFAULT 'member',
  `member_code` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name_key` VARCHAR(120) NOT NULL,
  `phone_last4` CHAR(4) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,

  -- pending_review -> approved | rejected | expired | canceled
  `status` ENUM('pending_review','approved','rejected','expired','canceled')
      NOT NULL DEFAULT 'pending_review',

  -- Review bookkeeping. claimed_* is the existing office claim
  -- vocabulary (a second officer cannot claim a claimed row);
  -- decided_* is who made the final call.
  `claimed_by` INT UNSIGNED DEFAULT NULL,
  `claimed_at` DATETIME DEFAULT NULL,
  `decided_by` INT UNSIGNED DEFAULT NULL,
  `decided_at` DATETIME DEFAULT NULL,
  `decision_reason` VARCHAR(2000) DEFAULT NULL,

  -- How the officer tied the claim to a real person. Never guessed
  -- by the platform: 'manual' means the officer chose a person the
  -- matcher did not suggest, which is exactly the case an audit
  -- trail must be able to find later.
  `match_basis` ENUM('member_code','member_code+phone','member_code+name','manual')
      DEFAULT NULL,
  `person_id` INT UNSIGNED DEFAULT NULL,
  `ticket_id` INT UNSIGNED DEFAULT NULL,

  -- Requesting IP, truncated to 45 chars (IPv6 max). Stored for
  -- abuse investigation, never shown to the caller.
  `origin_ip` VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,

  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acm_request_uuid` (`uuid`),
  UNIQUE KEY `uniq_acm_request_ref` (`request_ref`),
  UNIQUE KEY `uniq_acm_request_dedupe` (`dedupe_hash`),
  KEY `idx_acm_request_status` (`status`,`created_at`),
  KEY `idx_acm_request_claim` (`claimed_by`,`status`),
  KEY `idx_acm_request_person` (`person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Event timeline, mirroring acm_ticket_events so one console
-- reader can render both. Append-only: this table is an audit
-- family and is never deleted from.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `acm_request_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `event` ENUM(
      'requested','claim','release','note',
      'approved','rejected','changes_requested','canceled','expired',
      'matched','ticket_created','code_issued','code_burned'
  ) NOT NULL,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `note` VARCHAR(2000) DEFAULT NULL,
  `meta` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acm_request_event` (`request_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
