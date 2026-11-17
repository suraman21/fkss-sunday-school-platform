-- Migration 097: server-side per-device pairing for ZIP HMAC verification.
--
-- When a logged-in mobile client first uploads a photo (or any signed
-- request carrying X-Photo-Device-Id) the server generates a random
-- 256-bit HMAC key for that device_id and hands it back to the client
-- in the X-Photo-Pairing-Key response header. The client stores the
-- key in photo_device_identity.photo_hmac_key and uses it to sign
-- future ZIP manifests (HMAC-SHA256 over canonical manifest.json).
--
-- The ZIP importer verifies the signature against this table; a
-- mismatch surfaces a warning but does not block import (admin can
-- still accept the bundle but knows to verify the chain of custody).
--
-- Device identity is scoped to (operator_admin_id, device_id) so a
-- stolen device UUID cannot be re-used by a different account.

CREATE TABLE IF NOT EXISTS photo_device_identities (
  id                INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  operator_admin_id INT(10) UNSIGNED NOT NULL,
  device_id         VARCHAR(64) NOT NULL,
  hmac_key_hex      CHAR(64) NOT NULL,                 -- 32 random bytes, hex-encoded
  first_seen_at     DATETIME NOT NULL,
  last_seen_at      DATETIME NOT NULL,
  UNIQUE KEY uq_photo_device (operator_admin_id, device_id),
  KEY idx_photo_device_device (device_id),
  PRIMARY KEY (id),
  CONSTRAINT fk_photo_device_admin
    FOREIGN KEY (operator_admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
