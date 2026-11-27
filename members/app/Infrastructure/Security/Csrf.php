<?php
/**
 * Members app — CSRF tokens.
 *
 * The prototype's own handover note admits: "No CSRF token ... add one before
 * production." This is that token.
 *
 * WHY IT IS STILL NEEDED WITH A BEARER TOKEN
 *   The token lives in sessionStorage, not a cookie, so a cross-site request
 *   cannot carry it — which already stops the classic attack. But this app also
 *   renders forms whose target may later move to a cookie-based session (mobile
 *   webviews, a future server-rendered flow). Shipping the defence now costs one
 *   hidden field and means that migration is safe by default rather than by
 *   memory.
 *
 * The token is bound to the PHP session, compared in constant time, and rotates
 * on login so a pre-login token cannot be replayed after authentication.
 */

declare(strict_types=1);

namespace SSMS\Members\Infrastructure\Security;

use SSMS\Members\Core\Exception\ForbiddenException;

final class Csrf
{
    private const SESSION_KEY = 'members_csrf';
    private const FIELD = '_csrf';

    /** Current token, creating one if needed. */
    public static function token(): string
    {
        self::ensureSession();
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }
        return $token;
    }

    /** Hidden form field, ready to echo inside a <form>. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    /**
     * Verify a submitted token, or throw.
     *
     * hash_equals is constant-time: a plain === leaks the position of the first
     * differing byte through timing, which is enough to forge a token byte by
     * byte given enough attempts.
     */
    public static function verify(?string $submitted): void
    {
        self::ensureSession();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($expected) || $expected === '') {
            throw new ForbiddenException('Your form session expired. Please reload the page and try again.');
        }
        if (!is_string($submitted) || !hash_equals($expected, $submitted)) {
            throw new ForbiddenException('Your form session expired. Please reload the page and try again.');
        }
    }

    /** Rotate after authentication. */
    public static function rotate(): void
    {
        self::ensureSession();
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }

    /**
     * Start a session with hardened cookie flags.
     *
     * SameSite=Lax stops the cookie riding along on cross-site POSTs; HttpOnly
     * keeps it away from scripts; Secure is set only under TLS so a local HTTP
     * run still works.
     */
    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => $https,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}
