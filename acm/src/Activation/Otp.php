<?php
/**
 * ACM S12 — 6-digit one-time codes.
 * ------------------------------------------------------------
 * Replaces the 6-char-selector + 8-char-secret pair for the ACTIVATION
 * ceremony. The school asked for six digits an officer can read aloud and
 * a student can type; NIST SP 800-63B-4 sets the same floor ("at least
 * six decimal digits") for a secret a human transmits.
 *
 * THE PROBLEM SIX DIGITS CREATES, STATED PLAINLY
 *   10^6 values is 20 bits. The old secret was ~39 bits and the selector
 *   was an indexed lookup key, so a wrong guess could only burn attempts
 *   on the one row it named. A 6-digit code cannot be its own lookup key:
 *   storing a hash of a 6-digit number and matching against it is
 *   reversible by enumerating 1,000,000 hashes. Verification must be
 *   SCOPED (see ActivationService::verifyOtp), and the scope is the
 *   guessing surface.
 *
 * WHAT MAKES IT SAFE — ALL FOUR TOGETHER
 *   1. Scoped lookup. A guess is only ever compared against the codes on
 *      ONE ticket. There is no global "which code is this" query, so a
 *      guess cannot be evaluated against the whole school.
 *   2. Per-ticket + per-source attempt budget (sql/102). Max five wrong
 *      guesses across a ticket: 5 in 10^6. The budget survives reissue,
 *      so asking for a new code does not buy fresh guesses.
 *   3. Short life. 10 minutes — NIST's ceiling for a transmitted secret,
 *      and it bounds how long a shoulder-surfed code is useful.
 *   4. Single use. The code row is consumed by compare-and-set before the
 *      grant is minted, in the same transaction.
 *
 * STORAGE
 *   `acm_activation_codes.secret_hash` holds `hash_hmac('sha256', otp,
 *   key)` where the key is an HMAC context bound to the CODE row, not a
 *   global secret. Why not password_hash(): bcrypt at the cost this repo
 *   uses is ~100ms; verifying a 6-digit code needs comparison to be cheap
 *   and the whole design leans on a tight attempt budget instead. Why a
 *   per-code HMAC context rather than `hash('sha256', $otp)`: a global
 *   unsalted hash of a 6-digit space is a rainbow table with a million
 *   rows. Binding the key to `uuid` (unique, random, per code) means two
 *   codes with the same six digits do not share a stored value, so the
 *   table cannot be precomputed.
 *
 *   This is a genuine change from "individually salted bcrypt" to "salted
 *   fast MAC", and it is only acceptable because the attempt budget is
 *   now per ACCOUNT rather than per IP. A table dump is still not
 *   directly usable: each row's key is that row's random uuid.
 */
namespace SSMS\Acm\Activation;

final class Otp
{
    /** Digits. NIST SP 800-63B-4 floor. */
    public const LENGTH = 6;
    /** Acceptance window, in minutes. NIST's ceiling for a transmitted secret. */
    public const TTL_MIN = 10;
    /**
     * Wrong guesses allowed across an entire ticket.
     *
     * Not per code: reissuing must not buy another five (SP 800-63B-4
     * Sec 3.1.3.2). Five is the low end of the common 3-5 band and is
     * affordable because the officer reads the code out in person.
     */
    public const MAX_ATTEMPTS = 5;
    /**
     * Wrong guesses allowed from one source address on one ticket.
     *
     * Lower than the ticket total so a single attacker cannot burn a
     * victim's budget and force a reissue loop.
     */
    public const MAX_ATTEMPTS_PER_SOURCE = 3;

    /**
     * Uniform 6-digit code, CSPRNG.
     *
     * `random_int(0, 999999)` is unbiased by construction (PHP's
     * random_int rejection-samples internally); string-padding it is the
     * only formatting needed. Do not reach for `rand()` or `mt_rand()`.
     */
    public static function generate(): string
    {
        return str_pad((string)random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT);
    }

    /** Exactly six ASCII digits, nothing else. */
    public static function isValid(string $value): bool
    {
        return strlen($value) === self::LENGTH && ctype_digit($value);
    }

    /**
     * Normalize what a human typed: strip the separators an officer might
     * add when reading it out, and reject anything that is not a digit.
     */
    public static function normalize(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    /**
     * Stored form. Keyed on the code's own uuid so the value is unique per
     * row and cannot be precomputed from the 10^6 space.
     */
    public static function hash(string $otp, string $codeUuid): string
    {
        return hash_hmac('sha256', $otp, self::keyFor($codeUuid));
    }

    /** Constant-time comparison of a typed code against a stored hash. */
    public static function verify(string $otp, string $storedHash, string $codeUuid): bool
    {
        return hash_equals($storedHash, self::hash($otp, $codeUuid));
    }

    /** Human form the officer reads out. Grouped for transcription. */
    public static function display(string $otp): string
    {
        return substr($otp, 0, 3) . ' ' . substr($otp, 3, 3);
    }

    private static function keyFor(string $codeUuid): string
    {
        return 'acm:otp:v1:' . $codeUuid;
    }
}
