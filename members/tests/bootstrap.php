<?php
/**
 * Members app — PHPUnit bootstrap.
 *
 * Deliberately NOT members/bootstrap.php: that file is the composition root for
 * a real request. It installs a global exception handler, starts a session and
 * builds a connection. A unit test wants the class definitions and nothing
 * else, so this file defines the same constants and loads the same static
 * autoloader, then stops.
 *
 * If a test ever needs the full request pipeline, it belongs in Integration/,
 * which is where the real end-to-end checks will live.
 */

declare(strict_types=1);

define('MEMBERS_ROOT', dirname(__DIR__));
define('REPO_ROOT', dirname(MEMBERS_ROOT));
define('MEMBERS_APP', MEMBERS_ROOT . '/app');

require_once MEMBERS_ROOT . '/autoload.php';
