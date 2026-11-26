<?php
declare(strict_types=1);

namespace MembersPortal\Auth;

/**
 * Read-only view of the platform session.
 *
 * ── What this is ─────────────────────────────────────────────────────
 * The web session is written by admin/backend/login.php as
 * $_SESSION['admin_logged_in'], ['admin_id'], ['admin_username'],
 * ['admin_role'], ['admin_full_name']. Despite the 'admin_' prefix it is the
 * session for every web role. This class reads that shape and nothing else.
 *
 * ── What this is NOT ─────────────────────────────────────────────────
 * It does not create a session and it does not sign anyone in. There is no
 * write method, by design: sign-in is blocked on the browser activation
 * decision (README §2), and a half-working login here would be worse than
 * none.
 *
 * A snapshot rather than $_SESSION itself: a page or template that receives
 * an array cannot quietly read a session key it was not given, and a test
 * can supply any session it likes.
 */
final class Session
{
    /**
     * @return array{logged_in:bool,id:int,username:string,role:string,full_name:string}
     */
    public static function current(): array
    {
        // In a real request config.php has already called
        // ssms_start_browser_session(), so there is nothing to do here. Start
        // one only if a page is loaded without that bootstrap (a test
        // harness), and never after output has begun — a session that cannot
        // start is not a reason to emit a warning over a member's page.
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli' && !headers_sent()) {
            session_start();
        }

        return self::fromArray([
            'logged_in' => $_SESSION['admin_logged_in'] ?? false,
            'id'        => $_SESSION['admin_id'] ?? 0,
            'username'  => $_SESSION['admin_username'] ?? '',
            'role'      => $_SESSION['admin_role'] ?? '',
            'full_name' => $_SESSION['admin_full_name'] ?? '',
        ]);
    }

    /**
     * Normalise anything to the snapshot shape. Every field gets a type, so
     * a page can never compare a string to an int and be surprised.
     *
     * @param array<string,mixed> $values
     *
     * @return array{logged_in:bool,id:int,username:string,role:string,full_name:string}
     */
    public static function fromArray(array $values): array
    {
        return [
            'logged_in' => ($values['logged_in'] ?? false) === true,
            'id'        => (int)($values['id'] ?? 0),
            'username'  => (string)($values['username'] ?? ''),
            'role'      => (string)($values['role'] ?? ''),
            'full_name' => (string)($values['full_name'] ?? ''),
        ];
    }
}
