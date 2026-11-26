<?php
/**
 * Members web app — bootstrap.
 *
 * This is the ONLY file that loads anything, and it exists for one reason:
 * the deployment host has no Composer (ADR-0001), so first-party classes are
 * listed here by hand, in dependency order.
 *
 * Nothing else is wired yet. There is no configuration, no database, no
 * session and no HTTP entry point: the application has no behaviour, so this
 * file has nothing to start.
 *
 * HOW TO ADD A CLASS
 *   1. create the file under src/
 *   2. add one require_once line below, in dependency order
 *   3. keep the dependency direction one-way: Http -> Service -> Repository
 *
 * Paths are written as __DIR__ . '/…' so that a static include check can
 * resolve them. A path built from a constant reads as "dynamic" to such a
 * check and needs a permanent allowlist entry.
 */

declare(strict_types=1);

require_once __DIR__ . '/HttpException.php';
