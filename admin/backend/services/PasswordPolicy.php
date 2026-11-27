<?php
/**
 * Shared password policy for every browser and mobile account workflow.
 * Existing hashes remain valid; this policy applies when a password is created
 * or changed.
 */
namespace App\Services;

final class PasswordPolicy
{
    /**
     * Minimum length, in characters.
     *
     * THIS WAS 12, AND THE REASON IT WAS 12 IS NOW HANDLED ELSEWHERE.
     * 12 was chosen because login was throttled per IP only: an attacker
     * with a pool of addresses got unlimited guesses against any single
     * account, so length was the only thing standing between a weak
     * password and an offline-speed online attack. Raising length was
     * compensating for a missing control.
     *
     * That control now exists: `LoginThrottle` counts failures per
     * ACCOUNT (not per IP) and locks the identifier for fifteen minutes
     * after `LoginThrottle::ACCOUNT_SOFT_LIMIT` misses. With guessing
     * bounded per account, 6 is defensible — it is the same floor NIST
     * SP 800-63B-4 sets for out-of-band secrets, and what the school asked
     * for.
     *
     * Six CHARACTERS is the floor, and it is not the same thing as six
     * digits: the policy has no character-class or entropy rule, so
     * `aaaaaa` passes. That is a deliberate, documented consequence of
     * going to 6 -- what keeps it safe is the per-account throttle and
     * the common-password list below, not composition rules. Do not add
     * composition rules to compensate without saying why.
     *
     * Do NOT lower this without LoginThrottle being wired into every
     * password-checking endpoint. The policy cannot enforce that itself;
     * tests/security/test_login_throttle.py pins the wiring.
     */
    public const MIN_CHARACTERS = 6;

    // PHP's current PASSWORD_DEFAULT is bcrypt, which only processes 72 bytes.
    public const MAX_BYTES = 72;

    // The old floor's exact strings are gone: at 6 characters a list of
    // 12-character passwords no longer describes what users actually pick.
    // These are the ones a 6-character minimum makes reachable, plus the
    // obvious school-context guesses.
    private const COMMON_PASSWORDS = [
        '123456', '1234567', '12345678', '123456789', '1234567890',
        '111111', '000000', '121212', '123123', '654321', '666666',
        'password', 'passwd', 'qwerty', 'qwertyui', 'abc123', 'abcd1234',
        'admin', 'admin123', 'letmein', 'welcome', 'iloveyou', 'monkey',
        'dragon', 'sunshine', 'princess', 'football', 'baseball',
        'changeme', 'secret', 'student', 'school', 'ssms', 'member',
        'fkss', 'fkss123', 'ethiopia', 'addis', 'addisababa',
    ];

    /** @return string[] */
    public static function errors($password): array
    {
        if (!is_string($password) || $password === '') {
            return ['Password is required.'];
        }

        $errors = [];
        if (preg_match('//u', $password) !== 1) {
            return ['Password must be valid UTF-8 text.'];
        }
        $characters = function_exists('mb_strlen')
            ? mb_strlen($password, 'UTF-8')
            : strlen($password);
        if ($characters < self::MIN_CHARACTERS) {
            $errors[] = 'Password must be at least ' . self::MIN_CHARACTERS . ' characters.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            $errors[] = 'Password is too long (maximum ' . self::MAX_BYTES . ' UTF-8 bytes).';
        }
        if (strpos($password, "\0") !== false || preg_match('/^\s+$/u', $password)) {
            $errors[] = 'Password contains invalid content.';
        }

        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($password, 'UTF-8')
            : strtolower($password);
        if (in_array($normalized, self::COMMON_PASSWORDS, true)) {
            $errors[] = 'Choose a less common password.';
        }

        return array_values(array_unique($errors));
    }

    public static function isValid($password): bool
    {
        return self::errors($password) === [];
    }
}
