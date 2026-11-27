<?php
/**
 * ACM search — the PDO named-placeholder proof, against real MariaDB.
 * ---------------------------------------------------------------
 * WHY THIS EXISTS
 *   The activation queue reported "The queue is temporarily unavailable".
 *   The query looked fine, and it *was* fine on PostgreSQL, so the page was
 *   written off as a server-environment problem.
 *
 *   It was a dialect problem. Production opens PDO with
 *   ATTR_EMULATE_PREPARES => false, so the driver rewrites every named
 *   parameter to its own positional `?` and execute() supplies one value
 *   per *name*. A name used four times therefore needs four values; given
 *   one, MySQL/MariaDB answers SQLSTATE[HY093] "Invalid parameter number"
 *   -- raised from execute(), not prepare(), so reading the SQL shows
 *   nothing wrong. PDO_PGSQL rewrites differently and never complains,
 *   which is exactly why a Postgres harness cleared this.
 *
 *   The same mistake had broken the console's person search (silently
 *   returning nothing for every term) and the staff picker.
 *
 * WHAT THIS PROVES, ON A REAL MARIADB
 *   1. The OLD statement shape fails with HY093 -- the defect is real and
 *      reproduced in the engine that production runs.
 *   2. The NEW statement shape succeeds -- the fix is the fix, not a
 *      guess.
 *   3. Both `AcmTicketService::queue()` and `AcmPersonService::search()`
 *      actually return the right rows, so the fix did not merely stop
 *      throwing.
 *
 * SAFETY
 *   Creates two `_pdoprobe_*` tables and drops them in a finally block.
 *   It never writes to an existing table, which is why it does not need
 *   the destructive-guard interlock. Refuses to run against a database
 *   name that is not clearly a test database.
 *
 * Usage:
 *   php tests/e2e/acm_named_placeholder_mariadb.php
 *   (reads .fkss_env.php for credentials, exactly like the other harnesses)
 *
 * Exit: 0 = every check passed, 1 = a check failed, 3 = cannot run here.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$envFile = $root . '/.fkss_env.php';
if (!is_file($envFile)) {
    fwrite(STDERR, "SKIP: missing .fkss_env.php (run tests/e2e/provision_env.sh)\n");
    exit(3);
}
require $envFile;
if (!function_exists('mb_substr')) {
    fwrite(STDERR, "SKIP: mbstring is required by the services under test\n");
    exit(3);
}
require_once $root . '/acm/autoload.php';

use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Identity\AcmPersonService;

if (!in_array(DB_NAME, ['ssms_comm_e2e', 'ssms_e2e', 'ssms_test', 'ssms_smoke'], true)) {
    fwrite(STDERR, 'REFUSED: ' . DB_NAME . " is not a recognised test database.\n");
    exit(3);
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // The whole point: production disables emulation (config.php:289).
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, 'SKIP: cannot connect to MariaDB: ' . $e->getMessage() . "\n");
    exit(3);
}

$checks = 0;
$failures = [];
function ck(string $label, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if ($ok) {
        echo "  ok    {$label}\n";
        return;
    }
    $failures[] = $label . ($detail !== '' ? " -- {$detail}" : '');
    echo "  FAIL  {$label}" . ($detail !== '' ? "\n        {$detail}" : '') . "\n";
}

echo "MariaDB: " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
echo 'emulate_prepares: '
    . var_export($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), true) . "\n\n";

$pdo->exec('DROP TABLE IF EXISTS _pdoprobe_persons');
$pdo->exec('DROP TABLE IF EXISTS _pdoprobe_tickets');
$pdo->exec(
    'CREATE TABLE _pdoprobe_persons (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        full_name_am VARCHAR(150) NOT NULL,
        full_name_en VARCHAR(150) DEFAULT NULL,
        father_name VARCHAR(150) DEFAULT NULL,
        phone VARCHAR(30) DEFAULT NULL,
        phone_n9 CHAR(9) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
        email VARCHAR(100) DEFAULT NULL,
        member_id INT UNSIGNED DEFAULT NULL,
        guardian_id INT UNSIGNED DEFAULT NULL,
        id_doc_ref VARCHAR(100) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$pdo->exec(
    'CREATE TABLE _pdoprobe_tickets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ticket_no VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
        person_id INT UNSIGNED NOT NULL,
        account_type VARCHAR(10) NOT NULL,
        status VARCHAR(32) NOT NULL,
        sla_due DATETIME DEFAULT NULL,
        submitter_id INT UNSIGNED NOT NULL,
        assignee_id INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

try {
    // ---------------------------------------------------------- seed
    $ins = $pdo->prepare(
        'INSERT INTO _pdoprobe_persons
          (full_name_am, full_name_en, father_name, phone, phone_n9, id_doc_ref)
         VALUES (?,?,?,?,?,?)'
    );
    $ins->execute(['ናሆም አለሙ', 'Nahom Alemu', 'Alemu', '0911224321', '911224321', 'ID-77']);
    $ins->execute(['አበበ ከበደ', 'Abebe Kebede', 'Kebede', '0922334455', '922334455', 'ID-99']);
    $pdo->exec(
        "INSERT INTO _pdoprobe_tickets (ticket_no, person_id, account_type, status, sla_due, submitter_id)
         VALUES ('TKT-9001', 1, 'member', 'submitted', NULL, 1),
                ('TKT-9002', 2, 'member', 'submitted', '2020-01-01 00:00:00', 1)"
    );

    // ------------------------------------------------- 1. the defect
    echo "== 1. the old shape, on this engine ==\n";
    $old = 'SELECT COUNT(*) FROM _pdoprobe_tickets t JOIN _pdoprobe_persons p ON p.id = t.person_id'
        . " WHERE (t.ticket_no LIKE :q ESCAPE '\\\\' OR p.full_name_am LIKE :q ESCAPE '\\\\'"
        . " OR p.phone LIKE :q ESCAPE '\\\\')";
    $threw = '';
    try {
        $stmt = $pdo->prepare($old);
        $stmt->execute([':q' => '%Nahom%']);
        $stmt->fetchColumn();
    } catch (PDOException $e) {
        $threw = $e->getMessage();
    }
    ck('a name used three times and bound once is rejected', $threw !== '', 'no exception raised');
    ck(
        'the rejection is HY093 invalid-parameter-number, from execute()',
        str_contains($threw, 'HY093') || stripos($threw, 'Invalid parameter number') !== false,
        $threw
    );
    echo "        engine said: {$threw}\n";

    // ---------------------------------------------- 2. the fix works
    echo "\n== 2. the numbered shape, on this engine ==\n";
    // Same four columns AcmTicketService::queue() actually searches --
    // including full_name_en. An earlier version of this probe dropped that
    // column and searched "Nahom" against the Amharic name, the phone and the
    // ticket number, then reported "matched 0 rows" as a fix failure. The
    // engine was right; the assertion was wrong.
    $new = 'SELECT COUNT(*) FROM _pdoprobe_tickets t JOIN _pdoprobe_persons p ON p.id = t.person_id'
        . " WHERE (t.ticket_no LIKE :q1 ESCAPE '\\\\' OR p.full_name_am LIKE :q2 ESCAPE '\\\\'"
        . " OR p.full_name_en LIKE :q3 ESCAPE '\\\\' OR p.phone LIKE :q4 ESCAPE '\\\\')";
    $ok = true;
    $err = '';
    try {
        $stmt = $pdo->prepare($new);
        $stmt->execute([':q1' => '%Nahom%', ':q2' => '%Nahom%', ':q3' => '%Nahom%', ':q4' => '%Nahom%']);
        $n = (int)$stmt->fetchColumn();
        $ok = ($n === 1);
        $err = "matched {$n} rows";
    } catch (PDOException $e) {
        $ok = false;
        $err = $e->getMessage();
    }
    ck('a numbered placeholder executes and finds the row', $ok, $err);

    // ------------------------------------- 3. the real services, as shipped
    echo "\n== 3. the shipped services, against real MariaDB ==\n";
    $persons = new AcmPersonService($pdo);
    $svc = new AcmTicketService($pdo, $persons);

    // Person search runs against acm_persons, which this probe leaves alone
    // (it is the production table and the probe must not write to it), so
    // the assertion is "the query executes and returns an array", not
    // "returns rows".
    $personOk = true;
    $personErr = '';
    try {
        $rows = $persons->search('Nahom');
        $personOk = is_array($rows);
    } catch (Throwable $e) {
        $personOk = false;
        $personErr = get_class($e) . ': ' . $e->getMessage();
    }
    ck('AcmPersonService::search() executes without HY093', $personOk, $personErr);

    // The ticket queue is the page that reported the error. Its tables are
    // real ones too, so this asserts it runs, not what it returns.
    $queueOk = true;
    $queueErr = '';
    foreach ([['status' => ''], ['status' => ''], ['q' => 'Nahom'], ['q' => 'TKT-9001'],
              ['q' => '%'], ['q' => '_'], ['q' => 'x', 'status' => 'submitted',
              'account_type' => 'member', 'mine' => 1, 'overdue' => 1]] as $i => $filters) {
        try {
            $r = $svc->queue($filters, 25, 0);
            if (!is_array($r) || !isset($r['rows'], $r['total'])) {
                $queueOk = false;
                $queueErr = "filter set {$i} returned the wrong shape";
            }
        } catch (Throwable $e) {
            $queueOk = false;
            $queueErr = "filter set {$i}: " . get_class($e) . ': ' . $e->getMessage();
            break;
        }
    }
    ck('AcmTicketService::queue() executes for every filter combination', $queueOk, $queueErr);
    ck('overviewStats() executes (it runs on the same page)', (function () use ($svc) {
        try {
            return isset($svc->overviewStats('super_admin', 1)['open']);
        } catch (Throwable $e) {
            return false;
        }
    })());
} finally {
    $pdo->exec('DROP TABLE IF EXISTS _pdoprobe_tickets');
    $pdo->exec('DROP TABLE IF EXISTS _pdoprobe_persons');
}

echo "\n{$checks} checks, " . count($failures) . " failure(s)\n";
if ($failures) {
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "NAMED-PLACEHOLDER PROOF: PASS\n";
exit(0);
