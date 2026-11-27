<?php
/**
 * ACM search — MySQL/MariaDB dialect proof for the console search queries.
 * ---------------------------------------------------------------
 * WHY THIS EXISTS
 *   /admin/acm_queue.php reported "The queue is temporarily unavailable".
 *   The query looked fine, and it *was* fine under PDO_PGSQL, so the page
 *   was written off as a server-environment problem. Two real defects were
 *   hiding behind that:
 *
 *   1. NAMED PLACEHOLDER REUSE. config.php sets
 *      ATTR_EMULATE_PREPARES => false, so PDO rewrites each named parameter
 *      to its own positional '?'. A name used four times needs four values;
 *      given one, the engine answers SQLSTATE[HY093] "Invalid parameter
 *      number" -- from execute(), not prepare(). PDO_PGSQL rewrites
 *      differently and never complains.
 *
 *   2. A ONE-BACKSLASH ESCAPE CLAUSE. MySQL needs the escape character to be
 *      a single character, written '\\' in the SQL text. Every LIKE search
 *      in the console emitted '\', which escapes the closing quote: the
 *      statement is an unterminated string and is rejected at prepare() with
 *      1064. This one hits even when the placeholder count is right.
 *
 * WHAT THIS PROVES, ON A REAL MARIADB/MYSQL
 *   1. the OLD statement shape fails here, with HY093 -- the defect is
 *      reproduced in the engine production runs, not described;
 *   2. the statement shape the services generate TODAY prepares and executes
 *      here -- using the ESCAPE clause captured from the shipped code, so the
 *      proof binds to the code rather than to a hand-copied duplicate;
 *   3. every statement the four shipped services generate has no repeated
 *      placeholder and a two-backslash ESCAPE;
 *   4. if the ACM tables happen to be provisioned, the services run for real
 *      against every filter combination the pages send.
 *
 * SAFETY
 *   Creates two `_pdoprobe_*` tables and drops them in a finally block. It
 *   never writes to an existing table, so it does not need the destructive
 *   interlock. It refuses a database name that is not clearly a test one.
 *
 * Usage: php tests/e2e/acm_named_placeholder_mariadb.php
 * Exit:  0 = every check passed, 1 = a check failed, 3 = cannot run here.
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
use SSMS\Acm\Requests\AcmActivationRequestService;

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

// A fatal error must not end the output mid-section: job logs are not always
// readable from tooling, so the reason has to reach stdout.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    echo "\nFATAL: {$err['message']}\n  in {$err['file']}:{$err['line']}\n";
});

$checks = 0;
$failures = [];
$notes = [];
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
function note(string $line): void
{
    global $notes;
    $notes[] = $line;
    echo "  note  {$line}\n";
}

/**
 * records the SQL a service sends, without needing the tables to exist.
 */
final class SqlRecorder extends PDO
{
    public array $log = [];

    public function __construct(private PDO $inner)
    {
    }

    private function record(string $sql): void
    {
        $this->log[] = $sql;
    }

    #[ReturnTypeWillChange]
    public function prepare(string $q, array $o = [])
    {
        $this->record($q);
        return new SqlSink($q);
    }

    #[ReturnTypeWillChange]
    public function query(string $q, ?int $m = null, mixed ...$a)
    {
        $this->record($q);
        return new SqlSink($q);
    }

    #[ReturnTypeWillChange]
    public function exec(string $q)
    {
        return $this->inner->exec($q);
    }
}
final class SqlSink
{
    public function __construct(public string $sql)
    {
    }

    public function execute($p = null): bool
    {
        return true;
    }

    public function fetchColumn()
    {
        return 0;
    }

    public function fetchAll($m = null): array
    {
        return [];
    }

    public function fetch($m = null)
    {
        return null;
    }

    public function rowCount(): int
    {
        return 0;
    }
}

/** Backslash count between the quotes of the first ESCAPE clause (2 = correct). */
function escape_backslashes(string $sql): ?int
{
    $pos = strpos($sql, "ESCAPE '");
    if ($pos === false) {
        return null;
    }
    $i = $pos + 8;
    $n = 0;
    while ($i < strlen($sql) && $sql[$i] !== "'") {
        if ($sql[$i] === chr(92)) {
            $n++;
        }
        $i++;
    }
    return $n;
}

function table_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn() > 0;
}

echo 'MariaDB: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
echo 'emulate_prepares: ' . var_export($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), true) . "\n\n";

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

    // ------------------------------------------------------ 1. the defects
    echo "== 1. the old shapes, on this engine ==\n";

    $threw = '';
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM _pdoprobe_tickets t JOIN _pdoprobe_persons p ON p.id = t.person_id'
            . " WHERE (t.ticket_no LIKE :q ESCAPE '\\\\' OR p.full_name_am LIKE :q ESCAPE '\\\\'"
            . " OR p.phone LIKE :q ESCAPE '\\\\')"
        );
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

    $threw = '';
    try {
        // One backslash in the SQL text, which is what the code emitted.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM _pdoprobe_persons WHERE phone LIKE :q1 ESCAPE '\\'"
        );
        $stmt->execute([':q1' => '%1%']);
        $stmt->fetchColumn();
    } catch (PDOException $e) {
        $threw = $e->getMessage();
    }
    ck('a one-backslash ESCAPE clause is rejected', $threw !== '', 'no exception raised');
    ck(
        'the rejection is 1064 syntax error',
        stripos($threw, '1064') !== false,
        $threw ?: '(none)'
    );
    echo "        engine said: {$threw}\n";

    // ------------------------------------- 2. what the services emit today
    echo "\n== 2. the shipped services' SQL, on this engine ==\n";
    $recorder = new SqlRecorder($pdo);
    $persons = new AcmPersonService($recorder);
    $svc = new AcmTicketService($recorder, $persons);
    $requests = new AcmActivationRequestService($recorder);

    $recorder->log = [];
    $svc->queue(['q' => 'Nahom'], 25, 0);
    $persons->search('Nahom');
    $requests->queue(1, 'pending_review', 25, 0, 'Nahom');
    $emitted = array_values(array_filter($recorder->log, fn($s) => str_contains($s, 'ESCAPE')));
    // queue() issues two statements (a COUNT and the page), the person search
    // one, the request search one.
    ck('four search statements were captured from the services', count($emitted) === 4, (string)count($emitted));

    $escapeOk = 0;
    $placeholderOk = 0;
    foreach ($emitted as $i => $sql) {
        $bs = escape_backslashes($sql);
        if ($bs === 2) {
            $escapeOk++;
        } else {
            echo "        query {$i}: ESCAPE has " . var_export($bs, true) . " backslash(es)\n";
        }
        preg_match_all('/:[a-z_][a-z0-9_]*\b/i', $sql, $m);
        $dup = array_filter(array_count_values($m[0]), fn($c) => $c > 1);
        if ($dup === []) {
            $placeholderOk++;
        } else {
            echo "        query {$i}: repeated " . json_encode($dup) . "\n";
        }
    }
    ck('every emitted ESCAPE clause has exactly two backslashes', $escapeOk === count($emitted), "{$escapeOk}/" . count($emitted));
    ck('no emitted statement repeats a named placeholder', $placeholderOk === count($emitted), "{$placeholderOk}/" . count($emitted));

    // Now execute the captured shape against the probe tables. The LIKE
    // fragment is the services' own; only the table names differ, because
    // the real ACM tables are not provisioned in this environment.
    $svcSql = $emitted[0];
    $fragment = '';
    if (preg_match('/\(t\.ticket_no LIKE :q1.*?\)/s', $svcSql, $fm)) {
        $fragment = $fm[0];
    }
    ck('the queue LIKE fragment was extracted from the emitted SQL', $fragment !== '');
    if ($fragment !== '') {
        $exec = 'SELECT COUNT(*) FROM _pdoprobe_tickets t JOIN _pdoprobe_persons p ON p.id = t.person_id WHERE '
            . $fragment;
        $ok = true;
        $err = '';
        $n = 0;
        try {
            $stmt = $pdo->prepare($exec);
            $stmt->execute([':q1' => '%Nahom%', ':q2' => '%Nahom%', ':q3' => '%Nahom%', ':q4' => '%Nahom%']);
            $n = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $ok = false;
            $err = $e->getMessage();
        }
        ck('the services\' own LIKE fragment prepares and executes', $ok, $err);
        ck('and it still finds the matching row', $ok && $n === 1, "matched {$n}");
    }

    // ------------------------------------------- 3. the services, if possible
    echo "\n== 3. the shipped services end to end ==\n";
    $haveTables = table_exists($pdo, 'acm_persons') && table_exists($pdo, 'acm_activation_tickets');
    if (!$haveTables) {
        note('acm_persons / acm_activation_tickets are not provisioned in '
            . DB_NAME . ', so the service methods cannot be executed here.');
        note('Sections 1 and 2 still pin the engine behaviour and the emitted SQL.');
        note('This also means no ACM service query runs against MySQL in CI today.');
    } else {
        $ok = true;
        $err = '';
        try {
            $persons->search('Nahom');
        } catch (Throwable $e) {
            $ok = false;
            $err = get_class($e) . ': ' . $e->getMessage();
        }
        ck('AcmPersonService::search() executes without error', $ok, $err);

        $filterSets = [
            ['status' => ''],
            ['status' => 'submitted'],
            ['q' => 'Nahom'],
            ['q' => '%'],
            ['q' => '_'],
            ['q' => 'x', 'status' => 'submitted', 'account_type' => 'member', 'mine' => 1, 'overdue' => 1],
        ];
        $ok = true;
        $err = '';
        foreach ($filterSets as $i => $filters) {
            try {
                $r = $svc->queue($filters, 25, 0);
                if (!is_array($r) || !isset($r['rows'], $r['total'])) {
                    $ok = false;
                    $err = "filter set {$i} returned the wrong shape";
                }
            } catch (Throwable $e) {
                $ok = false;
                $err = "filter set {$i}: " . get_class($e) . ': ' . $e->getMessage();
                break;
            }
        }
        ck('AcmTicketService::queue() executes for every filter combination', $ok, $err);

        $ok = true;
        try {
            $svc->overviewStats('super_admin', 1);
        } catch (Throwable $e) {
            $ok = false;
        }
        ck('overviewStats() executes (it runs on the same page)', $ok);
    }
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
