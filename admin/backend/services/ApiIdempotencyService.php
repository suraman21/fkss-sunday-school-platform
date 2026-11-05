<?php
/**
 * Atomic API idempotency reservations and response replay.
 *
 * Migration 009 provides the shared multi-instance backend. A locked local
 * fallback preserves compatibility while that migration is rolled out, without
 * creating or altering schema in an API request.
 */
namespace App\Services;

final class ApiIdempotencyService
{
    private const LEASE_SECONDS = 300;
    private const RETENTION_SECONDS = 604800;
    private const MAX_RESPONSE_BYTES = 8388608;

    private ?\mysqli $database;
    private string $fallbackDirectory;

    public function __construct(?\mysqli $database, string $fallbackDirectory)
    {
        $this->database = $database;
        $this->fallbackDirectory = rtrim($fallbackDirectory, '/\\');
    }

    /**
     * @return array{state:string,record_hash?:string,owner_token?:string,backend?:string,status_code?:int,body?:string,retry_after?:int}
     */
    public function begin(int $userId, string $key, string $scope, string $requestHash): array
    {
        if ($userId <= 0 || $key === '' || $scope === '' || !preg_match('/^[a-f0-9]{64}$/', $requestHash)) {
            return ['state' => 'unavailable'];
        }

        $legacy = $this->legacyReplay($userId, $key);
        if ($legacy !== null) {
            return $legacy;
        }

        $recordHash = hash('sha256', $userId . "\0" . $key . "\0" . $scope);
        $ownerToken = bin2hex(random_bytes(32));

        if ($this->database instanceof \mysqli) {
            try {
                return $this->beginDatabase(
                    $recordHash,
                    $ownerToken,
                    $userId,
                    $key,
                    $scope,
                    $requestHash
                );
            } catch (\mysqli_sql_exception $error) {
                // 1146 = migration 009 not installed yet. Only that expected
                // rolling-deploy state may use the single-instance fallback;
                // ambiguous database failures fail closed to prevent duplicates.
                if ((int)$error->getCode() !== 1146 && (int)$this->database->errno !== 1146) {
                    return ['state' => 'unavailable'];
                }
            } catch (\Throwable $error) {
                // Older mysqli configurations return false instead of throwing
                // mysqli_sql_exception for a missing table.
                if ((int)$this->database->errno !== 1146) {
                    return ['state' => 'unavailable'];
                }
            }
        }

        return $this->beginFile($recordHash, $ownerToken, $userId, $key, $scope, $requestHash);
    }

    /**
     * Inspect ONLY the shared replay cache. Never claim/recover/renew, sweep,
     * read legacy/files, or infer a durable business outcome. Unknown is not
     * evidence of non-commit and never authorizes re-execution or settlement.
     * No response body, request hash, owner token or lease is disclosed.
     */
    public function lookup(int $userId, string $key, string $scope, string $requestHash): array
    {
        if ($userId <= 0 || strlen($key) > 80 || !preg_match('/^[A-Za-z0-9._-]+$/D', $key)
            || $scope === '' || strlen($scope) > 255
            || !preg_match('/^[a-f0-9]{64}$/D', $requestHash)
            || !($this->database instanceof \mysqli)) {
            return ['state' => 'unavailable'];
        }
        $recordHash = hash('sha256', $userId . "\0" . $key . "\0" . $scope);
        try {
            $stmt = $this->database->prepare(
                "SELECT user_id, idem_key, request_scope, request_hash, record_state,
                        status_code, (response_body IS NOT NULL) AS has_response
                 FROM api_idempotency_records
                 WHERE record_hash=? AND expires_at > CURRENT_TIMESTAMP LIMIT 1"
            );
            $stmt->bind_param('s', $recordHash);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) return ['state' => 'unknown'];
            if ((int)$row['user_id'] !== $userId
                || !hash_equals((string)$row['idem_key'], $key)
                || !hash_equals((string)$row['request_scope'], $scope)) {
                return ['state' => 'unavailable'];
            }
            if (!hash_equals((string)$row['request_hash'], $requestHash)) {
                return ['state' => 'payload_mismatch'];
            }
            if ($row['record_state'] === 'processing') return ['state' => 'processing'];
            $code = (int)($row['status_code'] ?? 0);
            if ($row['record_state'] === 'completed' && (int)$row['has_response'] === 1
                && $code >= 100 && $code <= 599) {
                return ['state' => 'completed', 'cached_http_status' => $code];
            }
        } catch (\Throwable $ignored) {
            // Missing schema/backend is unavailable, NEVER a file fallback.
        }
        return ['state' => 'unavailable'];
    }

    /**
     * Finalise a reservation INSIDE the caller's open transaction (S2, F-20).
     *
     * `complete()` is an autocommitted statement that runs *after* the business
     * transaction has already committed, which leaves a gap: a worker that dies
     * in that gap leaves a durable business effect behind a record that still
     * reads `processing`, and after the lease expires the same operation is
     * re-acquired and executed a second time.
     *
     * When the completion is performed inside the business transaction the gap
     * cannot exist, because the two outcomes are the same outcome:
     *
     *   commit   -> business effect AND completed record are both durable,
     *               so the retry replays instead of re-executing;
     *   rollback -> neither exists, the record stays `processing`, and the
     *               retry correctly re-executes after the lease expires.
     *
     * Deliberately NOT done here:
     *   - no commit/rollback: the caller owns the transaction;
     *   - no expiry sweep: a probabilistic DELETE has no business inside
     *     someone else's transaction and would widen its lock footprint;
     *   - no file fallback: it cannot participate in a database transaction,
     *     so callers must treat `false` as "not atomically completed" and let
     *     the normal post-response path run.
     *
     * The later `complete()` call from apiSendJson() is a safe no-op, because
     * its WHERE clause requires `record_state='processing'`.
     *
     * @return bool true when the record was atomically marked completed.
     */
    public function completeWithinTransaction(
        array $reservation,
        string $json,
        int $statusCode
    ): bool {
        if (strlen($json) > self::MAX_RESPONSE_BYTES) {
            return false;
        }
        $recordHash = (string)($reservation['record_hash'] ?? '');
        $ownerToken = (string)($reservation['owner_token'] ?? '');
        if ($recordHash === '' || $ownerToken === '') {
            return false;
        }
        if (($reservation['backend'] ?? '') !== 'database'
            || !($this->database instanceof \mysqli)) {
            return false;
        }
        $statusCode = max(100, min($statusCode, 599));
        try {
            $statement = $this->database->prepare(
                "UPDATE api_idempotency_records
                 SET record_state='completed', status_code=?, response_body=?,
                     lease_expires_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE record_hash=? AND owner_token=? AND record_state='processing'"
            );
            $statement->bind_param('isss', $statusCode, $json, $recordHash, $ownerToken);
            $statement->execute();
            $changed = $statement->affected_rows;
            $statement->close();
            return $changed === 1;
        } catch (\Throwable $error) {
            // Never break the business transaction for a bookkeeping write.
            return false;
        }
    }

    public function complete(array $reservation, string $json, int $statusCode): void
    {
        if (strlen($json) > self::MAX_RESPONSE_BYTES) {
            $this->abandon($reservation);
            return;
        }

        $recordHash = (string)($reservation['record_hash'] ?? '');
        $ownerToken = (string)($reservation['owner_token'] ?? '');
        if ($recordHash === '' || $ownerToken === '') {
            return;
        }
        $statusCode = max(100, min($statusCode, 599));

        if (($reservation['backend'] ?? '') === 'database' && $this->database instanceof \mysqli) {
            try {
                $statement = $this->database->prepare(
                    "UPDATE api_idempotency_records
                     SET record_state='completed', status_code=?, response_body=?,
                         lease_expires_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                     WHERE record_hash=? AND owner_token=? AND record_state='processing'"
                );
                $statement->bind_param('isss', $statusCode, $json, $recordHash, $ownerToken);
                $statement->execute();
                $statement->close();
                if (random_int(1, 1000) === 1) {
                    $this->database->query(
                        "DELETE FROM api_idempotency_records
                         WHERE expires_at < CURRENT_TIMESTAMP LIMIT 1000"
                    );
                }
            } catch (\Throwable $error) {
                // The application response remains valid. A failed persistence
                // write is not replaced with a misleading success/failure body.
            }
            return;
        }

        if (($reservation['backend'] ?? '') === 'file') {
            $this->completeFile($recordHash, $ownerToken, $json, $statusCode);
        }
    }

    public function abandon(array $reservation): void
    {
        $recordHash = (string)($reservation['record_hash'] ?? '');
        $ownerToken = (string)($reservation['owner_token'] ?? '');
        if ($recordHash === '' || $ownerToken === '') {
            return;
        }

        if (($reservation['backend'] ?? '') === 'database' && $this->database instanceof \mysqli) {
            try {
                $statement = $this->database->prepare(
                    "DELETE FROM api_idempotency_records
                     WHERE record_hash=? AND owner_token=? AND record_state='processing'"
                );
                $statement->bind_param('ss', $recordHash, $ownerToken);
                $statement->execute();
                $statement->close();
            } catch (\Throwable $error) {
                // Lease expiry makes an abandoned reservation recoverable.
            }
            return;
        }

        if (($reservation['backend'] ?? '') === 'file') {
            $this->abandonFile($recordHash, $ownerToken);
        }
    }

    /**
     * Preserve completed responses from the previous idempotency implementation
     * for seven days during migration. This is one indexed point lookup.
     *
     * @return array{state:string,status_code:int,body:string}|null
     */
    private function legacyReplay(int $userId, string $key): ?array
    {
        if (!$this->database instanceof \mysqli) {
            return null;
        }
        try {
            $statement = $this->database->prepare(
                "SELECT status_code, body FROM api_idempotency
                 WHERE idem_key=? AND user_id=?
                   AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)
                   AND body <> '' LIMIT 1"
            );
            $statement->bind_param('si', $key, $userId);
            $statement->execute();
            $row = $statement->get_result()->fetch_assoc();
            $statement->close();
            if ($row && isset($row['body'])) {
                return [
                    'state' => 'replay',
                    'status_code' => max(100, min((int)$row['status_code'], 599)),
                    'body' => (string)$row['body'],
                ];
            }
        } catch (\Throwable $error) {
            // The legacy table was created lazily and may never have existed.
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function beginDatabase(
        string $recordHash,
        string $ownerToken,
        int $userId,
        string $key,
        string $scope,
        string $requestHash
    ): array {
        $statement = $this->database->prepare(
            "INSERT IGNORE INTO api_idempotency_records
                (record_hash, user_id, idem_key, request_scope, request_hash,
                 owner_token, record_state, lease_expires_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 'processing',
                     DATE_ADD(CURRENT_TIMESTAMP, INTERVAL " . self::LEASE_SECONDS . " SECOND),
                     DATE_ADD(CURRENT_TIMESTAMP, INTERVAL " . self::RETENTION_SECONDS . " SECOND))"
        );
        $statement->bind_param('sissss', $recordHash, $userId, $key, $scope, $requestHash, $ownerToken);
        $statement->execute();
        $inserted = $statement->affected_rows === 1;
        $statement->close();
        if ($inserted) {
            return $this->acquired($recordHash, $ownerToken, 'database');
        }

        // Recover an expired record or processing lease atomically. A completed
        // record remains immutable until its retention period expires.
        $statement = $this->database->prepare(
            "UPDATE api_idempotency_records
             SET user_id=?, idem_key=?, request_scope=?, request_hash=?, owner_token=?,
                 record_state='processing', status_code=NULL, response_body=NULL,
                 lease_expires_at=DATE_ADD(CURRENT_TIMESTAMP, INTERVAL " . self::LEASE_SECONDS . " SECOND),
                 expires_at=DATE_ADD(CURRENT_TIMESTAMP, INTERVAL " . self::RETENTION_SECONDS . " SECOND),
                 updated_at=CURRENT_TIMESTAMP
             WHERE record_hash=?
               AND (expires_at <= CURRENT_TIMESTAMP
                    OR (record_state='processing' AND lease_expires_at <= CURRENT_TIMESTAMP
                        AND request_hash=?))"
        );
        $statement->bind_param(
            'issssss',
            $userId,
            $key,
            $scope,
            $requestHash,
            $ownerToken,
            $recordHash,
            $requestHash
        );
        $statement->execute();
        $recovered = $statement->affected_rows === 1;
        $statement->close();
        if ($recovered) {
            return $this->acquired($recordHash, $ownerToken, 'database');
        }

        $statement = $this->database->prepare(
            "SELECT user_id, idem_key, request_scope, request_hash, record_state,
                    status_code, response_body,
                    GREATEST(1, TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, lease_expires_at)) AS retry_after
             FROM api_idempotency_records WHERE record_hash=? LIMIT 1"
        );
        $statement->bind_param('s', $recordHash);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!$row) {
            return ['state' => 'unavailable'];
        }

        if ((int)$row['user_id'] !== $userId
            || !hash_equals((string)$row['idem_key'], $key)
            || !hash_equals((string)$row['request_scope'], $scope)
            || !hash_equals((string)$row['request_hash'], $requestHash)) {
            return ['state' => 'conflict'];
        }
        if ($row['record_state'] === 'completed' && $row['response_body'] !== null) {
            return [
                'state' => 'replay',
                'status_code' => max(100, min((int)$row['status_code'], 599)),
                'body' => (string)$row['response_body'],
            ];
        }

        return ['state' => 'processing', 'retry_after' => max(1, (int)$row['retry_after'])];
    }

    /** @return array{state:string,record_hash:string,owner_token:string,backend:string} */
    private function acquired(string $recordHash, string $ownerToken, string $backend): array
    {
        return [
            'state' => 'acquired',
            'record_hash' => $recordHash,
            'owner_token' => $ownerToken,
            'backend' => $backend,
        ];
    }

    /** @return array<string,mixed> */
    private function beginFile(
        string $recordHash,
        string $ownerToken,
        int $userId,
        string $key,
        string $scope,
        string $requestHash
    ): array {
        if (!is_dir($this->fallbackDirectory)
            && !@mkdir($this->fallbackDirectory, 0700, true)
            && !is_dir($this->fallbackDirectory)) {
            return ['state' => 'unavailable'];
        }

        $path = $this->fallbackPath($recordHash);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return ['state' => 'unavailable'];
        }
        @chmod($path, 0600);

        try {
            if (!flock($handle, LOCK_EX)) {
                return ['state' => 'unavailable'];
            }
            rewind($handle);
            $record = json_decode((string)stream_get_contents($handle), true);
            $record = is_array($record) ? $record : [];
            $now = time();
            $expired = (int)($record['expires_at'] ?? 0) <= $now;
            $leaseExpired = (int)($record['lease_expires_at'] ?? 0) <= $now;

            if (!$record || $expired) {
                $record = $this->newFileRecord($userId, $key, $scope, $requestHash, $ownerToken, $now);
                if (!$this->writeLockedFile($handle, $record)) {
                    return ['state' => 'unavailable'];
                }
                return $this->acquired($recordHash, $ownerToken, 'file');
            }

            if ((int)($record['user_id'] ?? 0) !== $userId
                || !hash_equals((string)($record['key'] ?? ''), $key)
                || !hash_equals((string)($record['scope'] ?? ''), $scope)
                || !hash_equals((string)($record['request_hash'] ?? ''), $requestHash)) {
                return ['state' => 'conflict'];
            }
            if (($record['state'] ?? '') === 'completed' && isset($record['body'])) {
                return [
                    'state' => 'replay',
                    'status_code' => max(100, min((int)($record['status_code'] ?? 200), 599)),
                    'body' => (string)$record['body'],
                ];
            }
            if ($leaseExpired) {
                $record['owner_token'] = $ownerToken;
                $record['lease_expires_at'] = $now + self::LEASE_SECONDS;
                if (!$this->writeLockedFile($handle, $record)) {
                    return ['state' => 'unavailable'];
                }
                return $this->acquired($recordHash, $ownerToken, 'file');
            }

            return [
                'state' => 'processing',
                'retry_after' => max(1, (int)$record['lease_expires_at'] - $now),
            ];
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    private function newFileRecord(
        int $userId,
        string $key,
        string $scope,
        string $requestHash,
        string $ownerToken,
        int $now
    ): array {
        return [
            'user_id' => $userId,
            'key' => $key,
            'scope' => $scope,
            'request_hash' => $requestHash,
            'owner_token' => $ownerToken,
            'state' => 'processing',
            'lease_expires_at' => $now + self::LEASE_SECONDS,
            'expires_at' => $now + self::RETENTION_SECONDS,
        ];
    }

    private function completeFile(
        string $recordHash,
        string $ownerToken,
        string $json,
        int $statusCode
    ): void {
        $this->updateFile($recordHash, function (array $record) use ($ownerToken, $json, $statusCode): ?array {
            if (($record['state'] ?? '') !== 'processing'
                || !hash_equals((string)($record['owner_token'] ?? ''), $ownerToken)) {
                return null;
            }
            $record['state'] = 'completed';
            $record['status_code'] = $statusCode;
            $record['body'] = $json;
            $record['lease_expires_at'] = time();
            return $record;
        });
    }

    private function abandonFile(string $recordHash, string $ownerToken): void
    {
        $path = $this->fallbackPath($recordHash);
        $this->updateFile($recordHash, function (array $record) use ($ownerToken, $path): ?array {
            if (($record['state'] ?? '') === 'processing'
                && hash_equals((string)($record['owner_token'] ?? ''), $ownerToken)) {
                return ['_delete_path' => $path];
            }
            return null;
        });
    }

    /** @param callable(array<string,mixed>):(?array) $mutator */
    private function updateFile(string $recordHash, callable $mutator): void
    {
        $path = $this->fallbackPath($recordHash);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            rewind($handle);
            $decoded = json_decode((string)stream_get_contents($handle), true);
            $record = is_array($decoded) ? $decoded : [];
            $updated = $mutator($record);
            if (!is_array($updated)) {
                return;
            }
            if (isset($updated['_delete_path'])) {
                ftruncate($handle, 0);
                fflush($handle);
                @unlink((string)$updated['_delete_path']);
                return;
            }
            $this->writeLockedFile($handle, $updated);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $record */
    private function writeLockedFile($handle, array $record): bool
    {
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false || !ftruncate($handle, 0) || !rewind($handle)) {
            return false;
        }
        $written = fwrite($handle, $encoded);
        return $written !== false && $written === strlen($encoded) && fflush($handle);
    }

    private function fallbackPath(string $recordHash): string
    {
        return $this->fallbackDirectory . '/api_idempotency_' . $recordHash . '.json';
    }
}
