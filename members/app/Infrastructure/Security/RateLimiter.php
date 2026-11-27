<?php
/**
 * Members app — rate limiter.
 *
 * WHY THIS IS NOT IN THE DATABASE
 *   The API already rate-limits its own routes (`isApiRateLimited`). This is a
 *   second, cheaper guard for the APP's own form posts, which never reach the
 *   API. A database round-trip to count login attempts is exactly the load an
 *   attacker wants to create, so this stays in the session/temp directory.
 *
 *   It is a speed bump, not a wall: it protects against a single source
 *   hammering a form, not against a distributed attack. The API's limiter and
 *   ACM's OTP attempt table are the real controls for credentials.
 */

declare(strict_types=1);

namespace SSMS\Members\Infrastructure\Security;

final class RateLimiter
{
    /**
     * Count an attempt for `$key` and return whether the caller may continue.
     *
     * @param int $maxAttempts allowed attempts inside the window
     * @param int $windowSeconds rolling window length
     */
    public static function allow(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        if ($maxAttempts < 1 || $windowSeconds < 1) {
            return false;
        }
        $now = time();
        $bucket = $_SESSION['members_rl'] ?? [];
        if (!is_array($bucket)) {
            $bucket = [];
        }

        $entry = $bucket[$key] ?? null;
        $count = 0;
        $windowStart = $now;

        if (is_array($entry) && isset($entry['start'], $entry['count'])
            && is_int($entry['start']) && is_int($entry['count'])) {
            if ($now - $entry['start'] < $windowSeconds) {
                $count = $entry['count'];
                $windowStart = $entry['start'];
            }
        }

        $count++;
        $bucket[$key] = ['start' => $windowStart, 'count' => $count];

        // Bound the bucket: an attacker who rotates keys must not grow the
        // session without limit.
        if (count($bucket) > 20) {
            $bucket = array_slice($bucket, -20, null, true);
        }
        $_SESSION['members_rl'] = $bucket;

        return $count <= $maxAttempts;
    }

    /** Seconds until the window resets — for a Retry-After style message. */
    public static function retryAfter(string $key, int $windowSeconds): int
    {
        $entry = $_SESSION['members_rl'][$key] ?? null;
        if (!is_array($entry) || !isset($entry['start']) || !is_int($entry['start'])) {
            return 0;
        }
        $remaining = ($entry['start'] + $windowSeconds) - time();
        return $remaining > 0 ? $remaining : 0;
    }

    /** Clear a key after a success, so a correct password resets the count. */
    public static function clear(string $key): void
    {
        if (isset($_SESSION['members_rl']) && is_array($_SESSION['members_rl'])) {
            unset($_SESSION['members_rl'][$key]);
        }
    }
}
