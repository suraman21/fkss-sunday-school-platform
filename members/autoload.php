<?php
/**
 * Members app — static autoloader.
 * ---------------------------------------------------------------------
 * Every class is required explicitly, in dependency order. THIS IS NOT AN
 * OVERSIGHT.
 *
 * The repository constitution forbids registering an SPL autoloader outside
 * `vendor/` (see tests/security — `test_class_dependency_closure`), because a
 * dynamic resolver makes the folder-safety tests unable to prove statically
 * which file a runtime will load. `acm/autoload.php` takes the same approach
 * for the same reason, and this file copies that convention rather than
 * inventing a second one.
 *
 * The cost is a manifest to maintain. The benefit is that every include is a
 * literal path a static analyser can verify, and a reviewer can see the whole
 * dependency graph of the app in one screen.
 *
 * Rules when adding a class:
 *   - add its require_once line here, in dependency order;
 *   - contracts/interfaces BEFORE the classes that name them.
 */

declare(strict_types=1);

if (defined('SSMS_MEMBERS_AUTOLOAD')) {
    return;
}
define('SSMS_MEMBERS_AUTOLOAD', true);

// ---------------------------------------------------------------
// Core primitives (no dependencies)
// ---------------------------------------------------------------
// The escaping helper is a NAMESPACE FUNCTION, compiled at require time not
// on demand, so it must be loaded before anything that calls it.
require_once MEMBERS_APP . '/Http/Support/escapes.php';
require_once MEMBERS_APP . '/Core/Config.php';
require_once MEMBERS_APP . '/Core/Exception/AppException.php';
require_once MEMBERS_APP . '/Core/Exception/NotFoundException.php';
require_once MEMBERS_APP . '/Core/Exception/ValidationException.php';
require_once MEMBERS_APP . '/Core/Exception/UnauthorizedException.php';
require_once MEMBERS_APP . '/Core/Exception/ForbiddenException.php';

// ---------------------------------------------------------------
// HTTP layer
// ---------------------------------------------------------------
require_once MEMBERS_APP . '/Http/Request.php';
require_once MEMBERS_APP . '/Http/Response.php';
require_once MEMBERS_APP . '/Http/Router.php';
require_once MEMBERS_APP . '/Http/Middleware/Middleware.php';
require_once MEMBERS_APP . '/Http/Middleware/SecurityHeaders.php';
require_once MEMBERS_APP . '/Http/Support/View.php';

// ---------------------------------------------------------------
// Infrastructure
// ---------------------------------------------------------------
require_once MEMBERS_APP . '/Infrastructure/Database/Connection.php';
require_once MEMBERS_APP . '/Infrastructure/Database/Migrator.php';
require_once MEMBERS_APP . '/Infrastructure/Security/Csrf.php';
require_once MEMBERS_APP . '/Infrastructure/Security/RateLimiter.php';
