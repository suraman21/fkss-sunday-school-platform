<?php
/**
 * /members — front controller and the portal's home page.
 *
 * This file is reached two ways, and they must behave the same:
 *   - via /members        (clean URL, through browser_page.php)
 *   - via /members/       (the directory's DirectoryIndex)
 *
 * The page it draws is a CONSTANT written below. Nothing in the request
 * selects it: no $_GET, no $_POST, no SCRIPT_NAME parsing. A URL that should
 * open a different page has its own file (login.php, and later one file per
 * page). That is why an unexpected request can only ever produce the page
 * this file was written for.
 *
 * No business rule and no SQL belongs in this file. If it grows past a dozen
 * lines, the logic is in the wrong place.
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Support/bootstrap.php';

\MembersPortal\Support\Bootstrap::create()->run('');
