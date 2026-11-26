<?php
/**
 * Per-ACCOUNT login throttling.
 * ------------------------------------------------------------
 * WHY THIS EXISTS
 *   Login was throttled per IP address only (`isApiRateLimited(
 *   'auth_login', 10)`), which bounds a single host and nothing else.
 *   An attacker with a pool of addresses could take an unlimited number
 *   of guesses at one account. `PasswordPolicy::MIN_CHARACTERS` was
 *   raised to 12 to compensate, which is the wrong tool: length only
 *   helps against guessing if guessing is bounded.
 *
 *   With this throttle in place the password floor can be 6 — the
 *   school's requirement, and the same floor NIST SP 800-63B-4 sets for
 *   a secret a person has to read and retype. The two changes belong
 *   together; do not lower the floor without this being wired in.
 *
 * WHAT IT IS NOT
 *   This is not `users.is_active` and it must never touch it. Writing the
 *   lock to the row would make it survive a successful password reset,
 *   lock the account out of the office's own recovery screen, and turn a
 *   temporary condition into a permanent support ticket. State lives in
 *   `security_rate_limits` and expires on its own.
 *
 * ONE THRESHOLD, DELIBERATELY
 *   This class used to have two: refuse at 5 without verifying, and a
 *   "hard" line at 10 that refused a CORRECT password with a distinct
 *   message. The second one was incoherent and never ran. The only way
 *   to reach `password_verify()` was through `attempt()`, which refuses
 *   at the soft limit — so by the time a password had been verified the
 *   counter was by construction below the hard line, and `hardLocked()`
 *   was always false. Testing found it dead; it is not coming back in
 *   that shape.
 *
 *   There is now one line. At ACCOUNT_SOFT_LIMIT the identifier is
 *   locked for the rest of the window and the password is NOT verified.
 *
 * WHY THE LOCKOUT MESSAGE IS SAFE TO SHOW
 *   It is tempting to answer a lockout with the same generic 401 as a
 *   wrong password, to avoid confirming the account exists. That is not
 *   needed here, because the bucket is keyed on the identifier the caller
 *   TYPED: anyone can send five failures for an invented username and
 *   then see the identical lockout message. A real account and a
 *   nonexistent one are indistinguishable at every step, so the honest
 *   message leaks nothing, and a locked-out student is told what
 *   happened instead of being left guessing.
 *
 * COUNTER BEHAVIOUR
 *   Once an identifier is locked, this class STOPS consuming for it. That
 *   matters: every consume slides the window forward, so continuing to
 *   count would let an attacker hold a victim's account locked forever
 *   with one request every few minutes. Stopping means the lock expires
 *   on its own, from the last real attempt.
 *
 *   A successful login clears the identifier bucket, so a user who
 *   mistyped twice and then got it right is not left nearer a lockout.
 */
namespace App\Services;

final class LoginThrottle
{
    /** Evaluated password guesses allowed per window, per identifier. */
    public const ACCOUNT_SOFT_LIMIT = 5;
    /** Failed logins from one source address, across all identifiers. */
    public const SOURCE_LIMIT = 30;

    public const WINDOW_SECONDS = 900;

    /**
     * Upper bound passed to the limiter so `consume()` keeps counting
     * instead of freezing. The limiter clamps to 1000.
     */
    private const COUNTER_CEILING = 1000;

    private SecurityRateLimiter $limiter;
    private string $source;
    private string $bucketKey;

    public function __construct(SecurityRateLimiter $limiter, string $identifier, string $source)
    {
        $this->limiter = $limiter;
        $this->source = $source !== '' ? $source : 'unknown';
        // Hashed so a dump of the limiter table cannot be used to see which
        // usernames or member codes people tried.
        $this->bucketKey = 'id:' . hash('sha256', self::normalize($identifier));
    }

    /**
     * Decide whether this attempt may be verified.
     *
     * @return array{allowed:bool,locked:bool,retry_after:int}
     */
    public function attempt(): array
    {
        $state = $this->limiter->peek('login_account', $this->bucketKey);
        if ($state['attempts'] >= self::ACCOUNT_SOFT_LIMIT) {
            // Already locked. Do NOT consume: the window must be allowed to
            // expire rather than slide forward under continued pressure.
            return [
                'allowed' => false,
                'locked' => true,
                'retry_after' => max(1, $state['retry_after']),
            ];
        }

        // Source budget: catches a spray across many identifiers from one
        // address. Consumed only after the per-identifier budget has room,
        // so one busy network cannot lock out every account behind it.
        $source = $this->limiter->consume(
            'login_source',
            $this->source,
            self::SOURCE_LIMIT,
            self::WINDOW_SECONDS
        );
        if (!$source['allowed']) {
            return [
                'allowed' => false,
                'locked' => false,
                'retry_after' => max(1, (int)$source['retry_after']),
            ];
        }

        $this->limiter->consume(
            'login_account',
            $this->bucketKey,
            self::COUNTER_CEILING,
            self::WINDOW_SECONDS
        );

        return ['allowed' => true, 'locked' => false, 'retry_after' => 0];
    }

    /** A correct password clears the identifier bucket. */
    public function recordSuccess(): void
    {
        $this->limiter->clear('login_account', $this->bucketKey);
    }

    public static function normalize(string $identifier): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower(trim($identifier), 'UTF-8')
            : strtolower(trim($identifier));
    }
}
