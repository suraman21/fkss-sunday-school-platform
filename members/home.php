<?php
/**
 * /members/home — the home page under its explicit name.
 *
 * Exists so /members and /members/home are both real, nameable pages rather
 * than one of them being an alias that only the web server knows about. Same
 * constant-slug rule as index.php: the page is written here, not requested.
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Support/bootstrap.php';

\MembersPortal\Support\Bootstrap::create()->run('/home');
