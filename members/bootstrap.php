<?php
/**
 * Members app — bootstrap (the composition root).
 * ---------------------------------------------------------------------
 * EVERY request starts here. Nothing outside `public/` is reachable from the
 * web (see members/.htaccess), so this file is the only door in.
 *
 * Order matters and is deliberate:
 *   1. error handling installed FIRST, so a failure during bootstrap still
 *      produces a safe response rather than a stack trace;
 *   2. configuration loaded;
 *   3. autoloader (static manifest — see autoload.php for why);
 *   4. request objects built;
 *   5. dispatch.
 */

declare(strict_types=1);

// This file lives at members/bootstrap.php, so MEMBERS_ROOT is its own
// directory and REPO_ROOT is one level up. Getting this backwards silently
// resolves every include to the wrong tree, so it is namespaced explicitly.
define('MEMBERS_ROOT', __DIR__);
define('REPO_ROOT', dirname(__DIR__));
define('MEMBERS_APP', MEMBERS_ROOT . '/app');

// ---------------------------------------------------------------
// 1. Errors: log everything, show nothing.
// ---------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $error): void {
    error_log('[members] unhandled ' . get_class($error) . ': ' . $error->getMessage()
        . ' @ ' . $error->getFile() . ':' . $error->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    // Never echo the exception. A member-facing 500 says nothing an attacker
    // can use and nothing a member can misread.
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
        . '<p style="font-family:system-ui;padding:2rem">Something went wrong. '
        . 'Please try again, or ask the accounts office for help.</p>';
});

// ---------------------------------------------------------------
// 2. Configuration
// ---------------------------------------------------------------
/** @var array<string,mixed> $config */
$config = require MEMBERS_ROOT . '/config/app.php';

// ---------------------------------------------------------------
// 3. Autoloader
// ---------------------------------------------------------------
require_once MEMBERS_ROOT . '/autoload.php';

// ---------------------------------------------------------------
// 4. Shared collaborators
// ---------------------------------------------------------------
$router = new SSMS\Members\Http\Router();

// ---------------------------------------------------------------
// 5. Routes + dispatch
// ---------------------------------------------------------------
require_once MEMBERS_ROOT . '/routes/web.php';

return $router;
