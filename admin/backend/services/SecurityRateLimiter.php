<?php
/**
 * Shared authentication rate limiter.
 *
 * The database backend is atomic and works across application instances. The
 * locked-file backend exists only as a compatibility fallback while migration
 * 008 is being deployed; it fails closed if the counter cannot be persisted.
 */
namespace App\Services;

final class SecurityRateLimiter
{
    private ?\PDO $database;
    private string $fallbackDirectory;

    public function __construct(?\PDO $database, string $fallbackDirectory)
    {
        $this->database = $database;
        $this->fallbackDirectory = rtrim($fallbackDirectory, '/\\');
    }

    /**
     * Consume one attempt from a fixed window.
     *
     * @return array{allowed:bool,retry_after:int}
     */
    public function consume(string $action, string $subject, int $limit, int $windowSeconds): array
    {
        $limit = max(1, min($limit, 1000));
        $windowSeconds = max(1, min($windowSeconds, 86400));
        $bucket = $this->bucket($action, $subject);

        if ($this->database instanceof \PDO) {
            try {
                return $this->consumeDatabase($bucket, $action, $limit, $windowSeconds);
            } catch (\Throwable $error) {
                // Migration 008 may not be installed yet. Preserve availability
                // with an atomic single-host fallback without creating schema in
                // the request path.
            }
        }

        return $this->consumeFile($bucket, $limit, $windowSeconds);
    }

    /**
     * Read a bucket WITHOUT consuming an attempt.
     *
     * Needed by callers that must decide before doing work — e.g. the
     * login throttle refuses an over-limit account before spending a
     * bcrypt verification. `consume()` cannot be used for that: incrementing
     * to ask "am I blocked?" locks people out of their own account simply
     * by logging in correctly enough times.
     *
     * @return array{attempts:int,retry_after:int}
     */
    public function peek(string $action, string $subject): array
    {
        $bucket = $this->bucket($action, $subject);

        if ($this->database instanceof \PDO) {
            try {
                $statement = $this->database->prepare(
                    'SELECT attempts, GREATEST(0, TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, window_ends_at)) AS retry_after
                     FROM security_rate_limits WHERE bucket_hash = ? AND window_ends_at > CURRENT_TIMESTAMP'
                );
                $statement->execute([$bucket]);
                $row = $statement->fetch(\PDO::FETCH_ASSOC);
                if (!$row) {
                    // No live window: the bucket has expired or never existed.
                    return ['attempts' => 0, 'retry_after' => 0];
                }
                return [
                    'attempts' => (int)($row['attempts'] ?? 0),
                    'retry_after' => max(0, (int)($row['retry_after'] ?? 0)),
                ];
            } catch (\Throwable $error) {
                // Fall through to the file backend.
            }
        }

        $path = $this->fallbackPath($bucket);
        if (!is_file($path)) {
            return ['attempts' => 0, 'retry_after' => 0];
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            // Cannot read the counter: report a full window and let the
            // caller fail closed rather than pretending there were no
            // attempts.
            return ['attempts' => PHP_INT_MAX, 'retry_after' => 60];
        }
        try {
            @flock($handle, LOCK_SH);
            $decoded = json_decode((string)stream_get_contents($handle), true);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
        $now = time();
        $windowEnds = is_array($decoded) ? (int)($decoded['window_ends'] ?? 0) : 0;
        if ($windowEnds <= $now) {
            return ['attempts' => 0, 'retry_after' => 0];
        }
        return [
            'attempts' => (int)($decoded['attempts'] ?? 0),
            'retry_after' => max(1, $windowEnds - $now),
        ];
    }

    public function clear(string $action, string $subject): void
    {
        $bucket = $this->bucket($action, $subject);
        if ($this->database instanceof \PDO) {
            try {
                $statement = $this->database->prepare('DELETE FROM security_rate_limits WHERE bucket_hash = ?');
                $statement->execute([$bucket]);
            } catch (\Throwable $error) {
                // Also clear the fallback below. Missing migration is expected
                // during rolling deployment and must not break a valid login.
            }
        }

        $path = $this->fallbackPath($bucket);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array{allowed:bool,retry_after:int} */
    private function consumeDatabase(string $bucket, string $action, int $limit, int $windowSeconds): array
    {
        $sql = "
            INSERT INTO security_rate_limits
                (bucket_hash, action_name, attempts, window_started_at, window_ends_at, updated_at)
            VALUES
                (:bucket, :action_name, 1, CURRENT_TIMESTAMP,
                 DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$windowSeconds} SECOND), CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                window_started_at = IF(window_ends_at <= CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, window_started_at),
                attempts = IF(window_ends_at <= CURRENT_TIMESTAMP, 1, attempts + 1),
                window_ends_at = IF(window_ends_at <= CURRENT_TIMESTAMP,
                    DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$windowSeconds} SECOND), window_ends_at),
                updated_at = CURRENT_TIMESTAMP
        ";
        $statement = $this->database->prepare($sql);
        $statement->execute([':bucket' => $bucket, ':action_name' => substr($action, 0, 64)]);

        $statement = $this->database->prepare(
            'SELECT attempts, GREATEST(0, TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, window_ends_at)) AS retry_after
             FROM security_rate_limits WHERE bucket_hash = ?'
        );
        $statement->execute([$bucket]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return ['allowed' => false, 'retry_after' => $windowSeconds];
        }

        // Bounded probabilistic cleanup prevents expired buckets accumulating
        // without adding a cleanup query to every authentication request.
        if (random_int(1, 1000) === 1) {
            try {
                $this->database->exec(
                    'DELETE FROM security_rate_limits WHERE window_ends_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY) LIMIT 1000'
                );
            } catch (\Throwable $error) {
                // Cleanup failure does not affect enforcement.
            }
        }

        $attempts = (int)($row['attempts'] ?? ($limit + 1));
        return [
            'allowed' => $attempts <= $limit,
            'retry_after' => max(1, (int)($row['retry_after'] ?? $windowSeconds)),
        ];
    }

    /** @return array{allowed:bool,retry_after:int} */
    private function consumeFile(string $bucket, int $limit, int $windowSeconds): array
    {
        if (!is_dir($this->fallbackDirectory) && !@mkdir($this->fallbackDirectory, 0700, true) && !is_dir($this->fallbackDirectory)) {
            return ['allowed' => false, 'retry_after' => $windowSeconds];
        }

        $path = $this->fallbackPath($bucket);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return ['allowed' => false, 'retry_after' => $windowSeconds];
        }
        @chmod($path, 0600);

        try {
            if (!flock($handle, LOCK_EX)) {
                return ['allowed' => false, 'retry_after' => $windowSeconds];
            }

            rewind($handle);
            $decoded = json_decode((string)stream_get_contents($handle), true);
            $now = time();
            $data = is_array($decoded) ? $decoded : [];
            $windowEnds = (int)($data['window_ends'] ?? 0);
            $attempts = (int)($data['attempts'] ?? 0);
            if ($windowEnds <= $now) {
                $attempts = 0;
                $windowEnds = $now + $windowSeconds;
            }
            $attempts++;

            $encoded = json_encode(['attempts' => $attempts, 'window_ends' => $windowEnds]);
            if ($encoded === false || !ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, $encoded) === false) {
                return ['allowed' => false, 'retry_after' => max(1, $windowEnds - $now)];
            }
            fflush($handle);

            return [
                'allowed' => $attempts <= $limit,
                'retry_after' => max(1, $windowEnds - $now),
            ];
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function bucket(string $action, string $subject): string
    {
        return hash('sha256', $action . "\0" . $subject);
    }

    private function fallbackPath(string $bucket): string
    {
        return $this->fallbackDirectory . '/security_rate_' . $bucket . '.json';
    }
}
