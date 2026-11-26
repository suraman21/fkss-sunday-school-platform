<?php
declare(strict_types=1);

namespace MembersPortal\Http;

use MembersPortal\Support\HttpException;

/**
 * The page table: the complete set of pages in this portal, and who may open
 * each one.
 *
 * ── Two tables, on purpose ───────────────────────────────────────────
 *   1. admin/backend/services/BrowserPageRoutes.php — the web-server layer.
 *      It turns the clean URL /members/login into the file
 *      /members/login.php. It knows nothing about roles.
 *   2. this class — the application layer. It says which roles may open
 *      /members/login, and which template draws it.
 *
 * A URL can therefore be routed without being authorised. That ordering is
 * the whole design: "this address exists" and "you may see it" are separate
 * questions, answered by separate code, in that order.
 *
 * ── Why a slug and not a request path ────────────────────────────────
 * Router::route() takes a slug the ENTRY FILE names as a constant. No entry
 * file reads the request to decide which page it is. A file can therefore
 * only ever draw the page it was written for; there is no request input that
 * can change which template runs.
 */
final class Router
{
    /** The only roles this portal serves, anywhere, ever. */
    public const MEMBER_ROLES = ['member', 'parent'];

    /**
     * slug => [view, title, roles, requiresSession]
     *
     * Key '' is the portal root /members. Every other key is exactly one
     * segment, because the clean URL and its file must share a parent
     * directory (see BrowserPageRoutes) — so a nested slug could never be
     * reached through the registry.
     */
    private const PAGES = [
        ''       => ['home',    'Home',    self::MEMBER_ROLES, true],
        '/home'  => ['home',    'Home',    self::MEMBER_ROLES, true],
        '/login' => ['sign-in', 'Sign in', self::MEMBER_ROLES, false],
    ];

    /**
     * @throws HttpException 404 when the slug is not in the table
     */
    public static function route(string $slug): Route
    {
        if (!array_key_exists($slug, self::PAGES)) {
            throw HttpException::notFound();
        }

        [$view, $title, $roles, $requiresSession] = self::PAGES[$slug];

        return new Route($slug, $view, $title, $roles, $requiresSession);
    }

    /** @return array<string, array{0:string,1:string,2:string[],3:bool}> */
    public static function pages(): array
    {
        return self::PAGES;
    }
}
