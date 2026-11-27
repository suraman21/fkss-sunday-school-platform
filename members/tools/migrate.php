<?php
/**
 * Members app — migration CLI.
 *
 *   php members/tools/migrate.php --status
 *   php members/tools/migrate.php --dry-run
 *   php members/tools/migrate.php --execute
 *
 * CLI ONLY. The guard below is not decoration: a migration runner reachable by
 * URL can be triggered by a crawler, and the test suite requires every CLI
 * script inside the website tree to prove it refuses web requests.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    // Say nothing about what the file is or where the app lives.
    echo "Forbidden.\n";
    exit;
}

$mode = '--status';
foreach ($argv as $arg) {
    if (in_array($arg, ['--status', '--dry-run', '--execute', '--help'], true)) {
        $mode = $arg;
    }
}

if ($mode === '--help') {
    fwrite(STDOUT, "Usage:\n"
        . "  php members/tools/migrate.php --status    list applied and pending\n"
        . "  php members/tools/migrate.php --dry-run   show what would run\n"
        . "  php members/tools/migrate.php --execute   apply pending migrations\n");
    exit(0);
}

require_once dirname(__DIR__) . '/bootstrap.php';

use SSMS\Members\Infrastructure\Database\Connection;
use SSMS\Members\Infrastructure\Database\Migrator;

try {
    $migrator = new Migrator(Connection::pdo());
} catch (Throwable $error) {
    fwrite(STDERR, 'Cannot connect: ' . $error->getMessage() . "\n");
    exit(1);
}

// The ledger lives in sql/000 and is applied by hand once. Say so plainly
// rather than letting a missing table surface as a confusing SQL error.
try {
    $pending = $migrator->pending();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
$drift = $migrator->drift();

fwrite(STDOUT, 'Database: ' . (defined('DB_NAME') ? DB_NAME : '(unknown)') . "\n");
fwrite(STDOUT, 'Pending:  ' . count($pending) . "\n");
foreach ($pending as $migration) {
    fwrite(STDOUT, '  + ' . $migration['file'] . "\n");
}

if ($drift !== []) {
    fwrite(STDOUT, "\nALREADY-APPLIED FILES THAT CHANGED SINCE (not repaired automatically):\n");
    foreach ($drift as $entry) {
        fwrite(STDOUT, '  ! ' . $entry . "\n");
    }
    fwrite(STDOUT, "A changed migration means history was edited. Decide what the database\n"
        . "actually needs before running anything.\n");
}

if ($mode === '--status' || $mode === '--dry-run') {
    fwrite(STDOUT, "\nNothing was changed.\n");
    exit(0);
}

if ($pending === []) {
    fwrite(STDOUT, "\nNothing to apply.\n");
    exit(0);
}

try {
    $applied = $migrator->run();
} catch (Throwable $error) {
    fwrite(STDERR, "\nFAILED: " . $error->getMessage() . "\n");
    fwrite(STDERR, "Migrations already recorded before the failure remain recorded.\n");
    exit(1);
}

fwrite(STDOUT, "\nApplied " . count($applied) . ":\n");
foreach ($applied as $name) {
    fwrite(STDOUT, '  ✓ ' . $name . "\n");
}
exit(0);
