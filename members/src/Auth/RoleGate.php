<?php
declare(strict_types=1);

namespace MembersPortal\Auth;

use MembersPortal\Http\Route;
use MembersPortal\Support\HttpException;

/**
 * The portal's only authorisation decision.
 *
 * ── Why a class and not a role map ───────────────────────────────────
 * The audit (MEMBER_PARENT_ACCOUNT_ANALYSIS.md §4) found four role maps in
 * this repository that had drifted from one another, with member and parent
 * missing from every one. The lesson taken here is not "add the roles to a
 * fifth list" but: each page declares its own allowed roles, and one
 * function enforces them.
 *
 * ── What it checks ───────────────────────────────────────────────────
 *   1. a session exists            (unless the page is public)
 *   2. the role is non-empty
 *   3. the role is in the page's allow-list
 *
 * All three fail CLOSED. A missing role is refused, not treated as
 * "probably fine". There is no default branch that lets anyone in.
 *
 * ── What it does NOT check ───────────────────────────────────────────
 * Whether this particular person may see a particular row. A parent may
 * enter the portal and still not see a given child. That check belongs next
 * to the data, in /api/v1/core/acl.php::apiParentCanSeeMember(), which is
 * built and tested. Do not duplicate it here.
 */
final class RoleGate
{
    /**
     * @param array<string,mixed> $session snapshot from Auth\Session
     *
     * @throws HttpException 401 when nobody is signed in,
     *                       403 when the role may not open this page
     */
    public static function assertAllowed(Route $page, array $session): void
    {
        if (!$page->requiresSession()) {
            return;
        }

        if (($session['logged_in'] ?? false) !== true) {
            // Public pages are excluded above; reaching here means the
            // visitor has to authenticate first.
            throw new HttpException(401, 'Please sign in.');
        }

        $role = (string)($session['role'] ?? '');

        // An empty role is a broken session, not a low-privilege one, and a
        // staff role is a person who used the wrong door. Both are refused
        // here and explained by the caller.
        if ($role === '' || !in_array($role, $page->roles(), true)) {
            if (self::isStaffRole($role)) {
                throw new HttpException(
                    403,
                    'This area is for members and parents. Please use the staff console.'
                );
            }
            throw new HttpException(403, 'You do not have access to this page.');
        }
    }

    /**
     * Staff roles the platform already knows about.
     *
     * Used ONLY to word the refusal better. It is not an authorisation list:
     * a role that is not named here is refused exactly the same way.
     */
    public static function isStaffRole(string $role): bool
    {
        return in_array($role, [
            'admin', 'super_admin', 'school_admin', 'teacher', 'info_dept',
            'finance', 'hr', 'edu_dept', 'attendance', 'content', 'monitor',
        ], true);
    }
}
