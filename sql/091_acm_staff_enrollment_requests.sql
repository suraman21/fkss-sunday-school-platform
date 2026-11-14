-- ACM S10 — Staff enrollment request queue.
-- Request-approve flow (owner decision #35, Shape B): a staffer (session-
-- authed users.id) files an enrollment REQUEST; an accounts officer decides
-- it in the console and the approval mints a `staff_enroll` grant into
-- acm_activation_grants. Staff have no acm_persons rows and no tickets —
-- the request keys on users.id and the grant carries 0-sentinels for
-- code/ticket/person (no S3 FKs exist on those columns). No self-approval:
-- requester and decider must differ (service-enforced).
-- FIXED_ENUMS guard applies to both enums below.

CREATE TABLE IF NOT EXISTS `acm_staff_enrollment_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'Staffer (users.id) to enroll',
  `requester_uid` INT UNSIGNED NOT NULL COMMENT 'ACM user id that filed the request',
  `operation` ENUM('activation','device_add') NOT NULL DEFAULT 'activation',
  `status` ENUM('requested','approved','rejected','canceled','expired','consumed') NOT NULL DEFAULT 'requested',
  `decided_by` INT UNSIGNED DEFAULT NULL COMMENT 'Officer user id that approved/rejected',
  `decided_at` DATETIME DEFAULT NULL,
  `decision_note` VARCHAR(255) DEFAULT NULL,
  `grant_id` INT UNSIGNED DEFAULT NULL COMMENT 'acm_activation_grants.id minted by approval',
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acm_staff_req_uuid` (`uuid`),
  KEY `idx_acm_staff_req_status` (`status`,`expires_at`),
  KEY `idx_acm_staff_req_user` (`user_id`),
  KEY `idx_acm_staff_req_requester` (`requester_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- S10 grant operation (S6 precedent: 'reset' widened this same enum).
ALTER TABLE `acm_activation_grants`
  MODIFY `operation` ENUM('activation','device_add','reset','staff_enroll') NOT NULL;
