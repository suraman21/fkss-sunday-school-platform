<?php
/**
 * Members app — database connection.
 *
 * ONE DATABASE, ONE CREDENTIAL SOURCE
 *   This app reads the same school database as the rest of the platform, using
 *   the same credentials, from the same `config.php`. A second config file with
 *   its own copy of the password is how two systems drift apart and how a
 *   rotation breaks one of them silently.
 *
 *   config.php already establishes `$conn` (mysqli) and `$pdo` (PDO). This class
 *   prefers the existing PDO handle and only builds one if it is absent.
 */

declare(strict_types=1);

namespace SSMS\Members\Infrastructure\Database;

use PDO;
use RuntimeException;

final class Connection
{
    private static ?PDO $pdo = null;

    /**
     * The shared PDO handle.
     *
     * Attributes chosen for correctness, not convenience:
     *   ERRMODE_EXCEPTION  — a failed query must throw; a silent false becomes
     *                        an empty list, and an empty list is indistinguishable
     *                        from "this member has no lessons".
     *   EMULATE_PREPARES=false — real server-side prepares. Emulated ones quote
     *                        on the client and have historically been the source
     *                        of charset-based injection.
     *   FETCH_ASSOC        — no numeric duplicates in every result row.
     */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        // The site's config establishes $pdo. Reuse it rather than opening a
        // second connection with duplicated credentials.
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            self::$pdo = $GLOBALS['pdo'];
            return self::$pdo;
        }

        $configFile = defined('REPO_ROOT') ? REPO_ROOT . '/config.php' : dirname(__DIR__, 4) . '/config.php';
        if (!is_file($configFile)) {
            throw new RuntimeException('Database configuration not found.');
        }

        // config.php defines the DB_* constants. It also starts a session and
        // may redirect; WBWS_API_REQUEST suppresses the web-page behaviour,
        // the same guard api/v1/core/database.php uses.
        if (!defined('WBWS_API_REQUEST')) {
            define('WBWS_API_REQUEST', true);
        }
        require_once $configFile;

        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
            throw new RuntimeException('Database configuration is incomplete.');
        }

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            self::$pdo = $GLOBALS['pdo'];
            return self::$pdo;
        }

        self::$pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            defined('DB_PASS') ? DB_PASS : '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );

        return self::$pdo;
    }

    /** Test seam: inject a handle (unit tests use an in-memory fake). */
    public static function setForTests(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}
