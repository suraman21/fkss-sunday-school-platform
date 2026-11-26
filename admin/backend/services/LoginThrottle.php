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
 * TWO THRESHOLDS, AND WHY THE HARD ONE TELLS THE TRUTH
 *   SOFT (ACCOUNT_SOFT_LIMIT) is checked BEFORE the password is verified.
 *   Past it we refuse without performing the expensive `password_verify`.
 *   Guessing stops here; the answer is the same generic 401, so it leaks
 *   nothing.
 *
 *   HARD (ACCOUNT_HARD_LIMIT) is checked AFTER the password verifies. If
 *   the correct password arrives but the account is over this line, the
 *   attempt is refused with a message that says so. That message does
 *   reveal "this address is real and its password was right" — accepted
 *   deliberately. The alternative is to log the attacker in, which is
 *   worse. Guessing stops at SOFT anyway; what the hard threshold
 *   protects is the legitimate user who keeps retrying after a lockout
 *   and needs to be told why a correct password is still refused.
 *
 * KEYING
 *   The bucket is keyed on a hash of the identifier the caller TYPED,
 *   never on a resolved user id. Keying on the resolved id would let
 *   anyone lock a known account outright just by submitting its username
 *   — a denial-of-service amplifier we do not need. Keying on the typed
 *   string means a username bucket and a member-code bucket for the same
 *   account are independent, and each is still bounded.
 *
 *   A successful login clears the identifier bucket, so a user who
 *   mistyped twice and then got it right is not left nearer a lockout.
 */
namespace App\Services;

final class LoginThrottle
{
    /** Failures in the window after which we stop verifying at all. */
    public const ACCOUNT_SOFT_LIMIT = 5;
    /** Failures after which even a correct password is refused. */
    public const ACCOUNT_HARD_LIMIT = 10;
    /** Failures from one source address across all identifiers. */
    public const SOURCE_LIMIT = 30;

    public const WINDOW_SECONDS = 900;

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
     * Spend one guess. Call BEFORE verifying; returns false when the
     * caller should refuse without checking the password.
     *
     * Consuming here (rather than counting only failures) is what makes
     * the budget a real budget: an attacker cannot probe for free. A
     * correct login clears the bucket immediately after, so honest use
     * never accumulates.
     *
     * @return array{allowed:bool,retry_after:int}
     */
    public function attempt(): array
    {
        $state = $this->limiter->peek('login_account', $this->bucketKey);
        if ($state['attempts'] >= self::ACCOUNT_HARD_LIMIT) {
            // Hard lockout: refuse without spending another attempt, and say
            // nothing about the password. Long window, because the short one
            // is what they already burned through.
            return ['allowed' => false, 'retry_after' => max(60, $state['retry_after'])];
        }
        if ($state['attempts'] >= self::ACCOUNT_SOFT_LIMIT) {
            return ['allowed' => false, 'retry_after' => max(1, $state['retry_after'])];
        }

        // Source budget: catching a spray across many identifiers, so it is
        // consumed only once the per-identifier budget has room. Order
        // matters — checking source first would let one busy network lock
        // out every account behind it.
        $source = $this->limiter->consume(
            'login_source',
            $this->source,
            self::SOURCE_LIMIT,
            self::WINDOW_SECONDS
        );
        if (!$source['allowed']) {
            return ['allowed' => false, 'retry_after' => (int)$source['retry_after']];
        }

        $consume = $this->limiter->consume(
            'login_account',
            $this->bucketKey,
            self::ACCOUNT_HARD_LIMIT,
            self::WINDOW_SECONDS
        );
        // consume() reports allowed when the post-increment count is within
        // the limit, so a refusal here means this attempt crossed the line.
        return [
            'allowed' => (bool)$consume['allowed'],
            'retry_after' => (int)($consume['retry_after'] ?? 0),
        ];
    }

    /**
     * Has the account crossed the hard line? Call AFTER a password
     * verified, to decide between signing in and refusing.
     */
    public function hardLocked(): bool
    {
        $state = $this->limiter->peek('login_account', $this->bucketKey);
        return $state['attempts'] > self::ACCOUNT_HARD_LIMIT;
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
