<?php
/**
 * ACM S3 — credential-establishment idempotency (design §D3.3).
 * ------------------------------------------------------------
 * Backed by acm_idempotency_keys: same key + same grant replays
 * the stored result; same key + different grant is a conflict.
 * Keys carry their own expiry (retained >= 24 h); an expired key
 * is lazily deleted and may be reused as new.
 * The caller owns the surrounding transaction.
 */

namespace SSMS\Acm\Activation;

use PDO;

class IdempotencyStore
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Return the live row for $key, or null. Expired rows are
     * deleted (lazy expiry — no cron on shared hosting).
     * All time comparisons stay in SQL (NOW()), never PHP —
     * the repo's established pattern (PHP and MySQL clocks may
     * differ by timezone).
     */
    public function findValid(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT idem_key, grant_fingerprint, user_id, username, account_type, expires_at'
            . ' FROM acm_idempotency_keys WHERE idem_key = :k AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
        $del = $this->pdo->prepare(
            'DELETE FROM acm_idempotency_keys WHERE idem_key = :k AND expires_at <= NOW() LIMIT 1'
        );
        $del->execute([':k' => $key]);
        return null;
    }

    public function save(
        string $key,
        string $grantFingerprint,
        int $userId,
        string $username,
        string $accountType,
        int $ttlHours
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO acm_idempotency_keys'
            . ' (idem_key, grant_fingerprint, user_id, username, account_type, expires_at)'
            . ' VALUES (:k, :fp, :u, :un, :at, DATE_ADD(NOW(), INTERVAL '
            . max(1, $ttlHours) . ' HOUR))'
        );
        $stmt->execute([
            ':k' => $key, ':fp' => $grantFingerprint, ':u' => $userId,
            ':un' => $username, ':at' => $accountType,
        ]);
    }
}
