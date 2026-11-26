<?php
/**
 * Members portal bootstrap — the single door to the rest of the platform.
 * ------------------------------------------------------------
 * Everything the portal needs from the main application is loaded here and
 * nowhere else, so the coupling between this app and the monolith is one
 * file long.
 *
 * Deliberately NOT loaded here:
 *   - admin/access_control.php — its ROLE_MAP has neither 'member' nor
 *     'parent', so it refuses the very people this portal serves. The
 *     portal does its own gate (src/Auth/RoleGate.php).
 *   - the api/v1 route files — they are HTTP handlers, not libraries.
 *   - config.php — NOT YET. Wiring the real bootstrap is a separate step
 *     with its own verification (see README §2). Until then this file
 *     loads only the portal itself, which means the skeleton can be
 *     reviewed and tested without touching a database or a session.
 *
 * No request input is read in this file.
 */

declare(strict_types=1);

/**
 * Folder layout
 * -------------
 *   members/                     web entry directory — entry files, assets
 *   members/index.php            /members
 *   members/<page>.php           /members/<page>
 *   members/src/                 application code, never served
 *   members/templates/           presentation, never served
 *
 * Entry files sit at the top of members/ rather than in a public/ subfolder
 * because BrowserPageRoutes requires each clean URL's parent directory to
 * equal its handler's parent directory. README §5 has the detail.
 *
 * No path constants are defined here. Every internal path is written as
 * __DIR__ . '…' so that tests/security/test_structure_include_paths.py can
 * resolve it statically; a constant would look "dynamic" to that check.
 */
if (!defined('MEMBERS_PORTAL')) {
    define('MEMBERS_PORTAL', true);
}

/**
 * Explicit autoload map, in dependency order.
 *
 * There is no Composer on the host (ADR-0001), so first-party code is
 * listed by hand, in the same style as acm/autoload.php. Adding a class
 * means adding a line here, in dependency order.
 *
 * Paths are written as __DIR__ . '/…' rather than PORTAL_ROOT . '/src/…'
 * so that tests/security/test_structure_include_paths.py can resolve every
 * one of them statically. A path built from a constant would be reported as
 * "dynamic" and would need a permanent allowlist entry, which is a weaker
 * check for no benefit.
 */
require_once __DIR__ . '/HttpException.php';          // Support\HttpException
require_once __DIR__ . '/../Http/Route.php';         // Http\Route
require_once __DIR__ . '/../Http/Router.php';        // Http\Router
require_once __DIR__ . '/../View/Template.php';      // View\Template
require_once __DIR__ . '/../Http/Response.php';      // Http\Response (uses Template)
require_once __DIR__ . '/../Auth/Session.php';       // Auth\Session
require_once __DIR__ . '/../Auth/RoleGate.php';      // Auth\RoleGate
require_once __DIR__ . '/Bootstrap.php';             // Support\Bootstrap
