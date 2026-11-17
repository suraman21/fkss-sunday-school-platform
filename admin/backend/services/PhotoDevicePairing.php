<?php
/**
 * P7 hardening — per-device HMAC key pairing.
 *
 * On first successful upload from a (operator_admin_id, device_id) pair
 * we mint a random 32-byte key, persist it, and hand it to the client
 * via response header + body. The client stores it in
 * photo_device_identity.photo_hmac_key and uses it to HMAC-SHA256 its
 * ZIP manifest.json when exporting; the ZIP importer verifies the
 * signature against the stored key before ingest.
 *
 * Why (operator, device) scoping: a device_id is just a UUID the client
 * mints; scoping to the logged-in admin prevents a stolen/forged
 * device_id from impersonating someone else's key.
 */

namespace App\Services;

use mysqli;

final class PhotoDevicePairing
{
    /**
     * Return the hex-encoded HMAC key for ($operatorId, $deviceId). If no
     * row exists, generate one, persist, and set $isNew=true. Returns
     * null when inputs are invalid or DB is unrecoverable.
     */
    public static function getOrCreateKey(
        mysqli $conn,
        int $operatorId,
        string $deviceId,
        bool &$isNew,
    ): ?string {
        $isNew = false;
        if ($operatorId <= 0 || $deviceId === ''
            || !preg_match('/^[a-f0-9\-]{32,64}$/D', $deviceId)) {
            return null;
        }
        // Ensure the pairing table exists (defense in depth: migration
        // 097 creates it at deploy; this self-heals if the migration
        // hasn't run yet on a given host).
        self::ensureTable($conn);

        try {
        // Existing key?
        $stmt = $conn->prepare(
            'SELECT hmac_key_hex FROM photo_device_identities
              WHERE operator_admin_id = ? AND device_id = ? LIMIT 1'
        );
        $stmt->bind_param('is', $operatorId, $deviceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && !empty($row['hmac_key_hex'])) {
            // Touch last_seen_at (non-fatal if fails — inner try because
            // mysqli strict mode throws instead of returning false).
            try {
                $u = $conn->prepare(
                    'UPDATE photo_device_identities SET last_seen_at = NOW()
                      WHERE operator_admin_id = ? AND device_id = ?'
                );
                $u->bind_param('is', $operatorId, $deviceId);
                $u->execute();
                $u->close();
            } catch (\Throwable) { /* ignore: touch is best-effort */ }
            return (string)$row['hmac_key_hex'];
        }

        // Mint new key (32 random bytes = 256-bit HMAC-SHA256 key).
        $key = random_bytes(32);
        $hex = bin2hex($key);
        $stmt = $conn->prepare(
            'INSERT INTO photo_device_identities
               (operator_admin_id, device_id, hmac_key_hex,
                first_seen_at, last_seen_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                last_seen_at = NOW()'
        );
        $stmt->bind_param('iss', $operatorId, $deviceId, $hex);
        $stmt->execute(); // throws on DB error (caught below → null)
        $inserted = ($stmt->affected_rows === 1);
        $stmt->close();
        // Re-read the winning row: parallel first-uploads from one device
        // can race here; losers must return the stored key (not their own
        // minted key) so every client converges on the same secret.
        $stmt = $conn->prepare(
            'SELECT hmac_key_hex FROM photo_device_identities
              WHERE operator_admin_id = ? AND device_id = ? LIMIT 1'
        );
        $stmt->bind_param('is', $operatorId, $deviceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['hmac_key_hex'])) return null;
        $stored = (string)$row['hmac_key_hex'];
        $isNew = $inserted && hash_equals($stored, $hex);
        return $stored;
        } catch (\Throwable) {
            // Best-effort pairing: a DB hiccup degrades to an unpaired
            // upload, never fatals the request (honors the ?string contract).
            return null;
        }
    }

    /**
     * Look up the stored key for ($operatorId, $deviceId) for signature
     * verification. Returns hex key or null if unknown.
     */
    public static function lookupKey(
        mysqli $conn,
        int $operatorId,
        string $deviceId,
    ): ?string {
        if ($operatorId <= 0 || $deviceId === '') return null;
        self::ensureTable($conn);
        try {
        $stmt = $conn->prepare(
            'SELECT hmac_key_hex FROM photo_device_identities
              WHERE operator_admin_id = ? AND device_id = ? LIMIT 1'
        );
        $stmt->bind_param('is', $operatorId, $deviceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (string)$row['hmac_key_hex'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static bool $tableChecked = false;

    private static function ensureTable(mysqli $conn): void {
        if (self::$tableChecked) return;
        @$conn->query(
            'CREATE TABLE IF NOT EXISTS photo_device_identities (
              id                INTEGER PRIMARY KEY AUTO_INCREMENT,
              operator_admin_id INTEGER NOT NULL,
              device_id         VARCHAR(64) NOT NULL,
              hmac_key_hex      CHAR(64) NOT NULL,
              first_seen_at     DATETIME NOT NULL,
              last_seen_at      DATETIME NOT NULL,
              UNIQUE KEY uq_photo_device (operator_admin_id, device_id),
              KEY idx_photo_device_device (device_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableChecked = true;
    }
}
