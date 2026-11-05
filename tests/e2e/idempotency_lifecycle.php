<?php
/**
 * Behavioural harness for API idempotency atomicity (S1 / finding F-20).
 *
 * This harness drives the REAL App\Services\ApiIdempotencyService against a
 * real MariaDB/MySQL database. It never reimplements the service, and it never
 * asserts on source strings: every verdict below is produced by executing the
 * shipped reservation/replay code against real rows.
 *
 * It models the production call order exactly as api/v1 performs it, which is
 * the whole point of the exercise:
 *
 *     apiIdempotencyBegin()      -> service->begin()      (its own statement)
 *     $conn->begin_transaction()
 *     ... business writes ...
 *     $conn->commit()                                     <- effect is durable
 *     ok() -> apiSendJson() -> apiIdempotencyStore()
 *                             -> service->complete()      (its own statement)
 *
 * The gap between commit() and complete() is the window F-20 describes. A
 * crash there is simulated faithfully by simply not calling complete(), which
 * is precisely what a dead PHP worker does.
 *
 * Lease expiry is driven by ageing lease_expires_at directly rather than by
 * sleeping for the configured 300 seconds. The service reads that column as
 * its clock, so ageing it exercises the same branch a real clock would; this
 * is a deterministic clock substitute, not a weakened assertion.
 *
 * COMMAND LINE ONLY. It DROPs and re-CREATEs its tables.
 *
 * Usage: SSMS_AUDIT_TESTING=1 php tests/e2e/idempotency_lifecycle.php <scenario>
 * Scenarios: all | success | duplicate | concurrent | precommit_failure
 *            | postcommit_crash | lease_expiry | replay | error_pinning
 *            | comm_append | attendance_converge | atomic_completion
 *            | attendance_response | attendance_response_failures
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ROOT = dirname(__DIR__, 2);
require $ROOT . '/.fkss_env.php';

require_once __DIR__ . '/destructive_guard.php';
ssms_require_disposable_database(defined('DB_NAME') ? (string)DB_NAME : '', basename(__FILE__));

require_once $ROOT . '/admin/backend/services/ApiIdempotencyService.php';

$SCENARIO = $argv[1] ?? 'all';
$passed = 0;
$failed = 0;

function check(bool $cond, string $name, string $why = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "E2E-PASS: $name\n";
    } else {
        $failed++;
        echo "E2E-FAIL: $name" . ($why !== '' ? " — $why" : '') . "\n";
    }
}

function verdict(): void
{
    global $passed, $failed, $SCENARIO;
    echo 'E2E-VERDICT: ' . ($failed === 0 ? 'PASS' : 'FAIL') . " ($SCENARIO: $passed checks)\n";
    exit($failed === 0 ? 0 : 1);
}

function db(): mysqli
{
    static $c = null;
    if ($c === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $c->set_charset('utf8mb4');
    }
    return $c;
}

function fallbackDir(): string
{
    $dir = sys_get_temp_dir() . '/ssms_idem_fallback';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function service(): \App\Services\ApiIdempotencyService
{
    return new \App\Services\ApiIdempotencyService(db(), fallbackDir());
}

/** Rebuild the idempotency tables plus two stand-in business tables. */
function resetSchema(): void
{
    $c = db();
    $c->query('DROP TABLE IF EXISTS api_idempotency_records');
    $c->query('DROP TABLE IF EXISTS api_idempotency');
    $c->query('DROP TABLE IF EXISTS probe_attendance');
    $c->query('DROP TABLE IF EXISTS probe_messages');

    // Apply the shipped migration verbatim rather than restating its DDL here,
    // so the probe can never drift from the table the server actually uses.
    $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/sql/009_api_idempotency.sql');
    $lines = [];
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;   // strip whole-line comments before splitting on ';'
        }
        $lines[] = $line;
    }
    foreach (array_filter(array_map('trim', explode(';', implode("\n", $lines)))) as $stmt) {
        if ($stmt === '') {
            continue;
        }
        $c->query($stmt);
    }

    // Mirrors production attendance: UNIQUE(member_id, attendance_date) makes a
    // re-execution converge instead of duplicating.
    $c->query(
        'CREATE TABLE probe_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            status VARCHAR(16) NOT NULL,
            UNIQUE KEY uq_member_date (member_id, attendance_date)
        ) ENGINE=InnoDB'
    );

    // Mirrors comm_outbox delivery: an append-only message row with no natural
    // uniqueness. This is the shape S0.5 flagged as NOT VERIFIED.
    $c->query(
        'CREATE TABLE probe_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            thread_id INT NOT NULL,
            sender_id INT NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );
}

function requestHash(array $payload): string
{
    return hash('sha256', 'POST' . "\0" . 'POST /api/v1/attendance' . "\0" . json_encode($payload));
}

function countRows(string $table): int
{
    $r = db()->query("SELECT COUNT(*) AS n FROM $table")->fetch_assoc();
    return (int)$r['n'];
}

function recordState(string $recordHash): ?array
{
    $stmt = db()->prepare(
        'SELECT record_state, status_code, response_body FROM api_idempotency_records WHERE record_hash=?'
    );
    $stmt->bind_param('s', $recordHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function recordHashFor(int $userId, string $key, string $scope): string
{
    return hash('sha256', $userId . "\0" . $key . "\0" . $scope);
}

const SCOPE = 'POST /api/v1/attendance';

/**
 * One production-shaped attendance write.
 *
 * @param string $crashAt '' | 'before_commit' | 'after_commit'
 * @return array{state:string,reservation?:array}
 */
function attendanceWrite(
    int $userId,
    string $key,
    array $payload,
    string $crashAt = '',
    int $responseCode = 200
): array {
    $svc = service();
    $begin = $svc->begin($userId, $key, SCOPE, requestHash($payload));
    if (($begin['state'] ?? '') !== 'acquired') {
        return $begin;
    }

    $c = db();
    $c->begin_transaction();
    try {
        $stmt = $c->prepare(
            'INSERT INTO probe_attendance (member_id, attendance_date, status)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE status=VALUES(status)'
        );
        foreach ($payload['records'] as $row) {
            $stmt->bind_param('iss', $row['member_id'], $payload['date'], $row['status']);
            $stmt->execute();
        }
        $stmt->close();

        if ($crashAt === 'before_commit') {
            $c->rollback();
            // The worker dies before any response is produced, so complete()
            // is never reached. The reservation stays 'processing'.
            return ['state' => 'crashed_before_commit', 'reservation' => $begin];
        }
        $c->commit();
    } catch (\Throwable $e) {
        $c->rollback();
        throw $e;
    }

    if ($crashAt === 'after_commit') {
        // THE F-20 WINDOW: business effect is durable, complete() never runs.
        return ['state' => 'crashed_after_commit', 'reservation' => $begin];
    }

    $body = json_encode(['status' => 'success', 'saved' => count($payload['records']), 'key' => $key]);
    $svc->complete($begin, $body, $responseCode);
    return ['state' => 'completed', 'reservation' => $begin, 'body' => $body];
}

/** One append-only message write, same production call order. */
function messageWrite(int $userId, string $key, array $payload, string $crashAt = ''): array
{
    $svc = service();
    $begin = $svc->begin($userId, $key, 'POST /api/v1/notifications/message', requestHash($payload));
    if (($begin['state'] ?? '') !== 'acquired') {
        return $begin;
    }
    $c = db();
    $c->begin_transaction();
    $stmt = $c->prepare('INSERT INTO probe_messages (thread_id, sender_id, body) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $payload['thread_id'], $userId, $payload['body']);
    $stmt->execute();
    $stmt->close();
    $c->commit();

    if ($crashAt === 'after_commit') {
        return ['state' => 'crashed_after_commit', 'reservation' => $begin];
    }
    $svc->complete($begin, json_encode(['status' => 'success']), 200);
    return ['state' => 'completed', 'reservation' => $begin];
}

/** Age a reservation's lease so the expiry branch becomes reachable now. */
function expireLease(string $recordHash): void
{
    $stmt = db()->prepare(
        'UPDATE api_idempotency_records
         SET lease_expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND)
         WHERE record_hash=?'
    );
    $stmt->bind_param('s', $recordHash);
    $stmt->execute();
    $stmt->close();
}

/**
 * S2/F-20 FIX PATH: the same append-only write, but the idempotency record is
 * finalised INSIDE the business transaction via completeWithinTransaction().
 *
 * This mirrors exactly what MezmurHymnService::saveHymn() now does through its
 * $beforeCommit hook. complete() is deliberately NEVER called afterwards —
 * that models the worker dying in the old F-20 window. If the fix works, the
 * record is already 'completed' when the process dies, so the retry replays.
 *
 * @param bool $rollback roll the transaction back instead of committing, to
 *                       prove the completion is rolled back WITH the effect.
 */
function messageWriteAtomic(
    int $userId,
    string $key,
    array $payload,
    bool $rollback = false
): array {
    $svc = service();
    $begin = $svc->begin($userId, $key, 'POST /api/v1/notifications/message', requestHash($payload));
    if (($begin['state'] ?? '') !== 'acquired') {
        return $begin;
    }
    $c = db();
    $c->begin_transaction();
    $stmt = $c->prepare('INSERT INTO probe_messages (thread_id, sender_id, body) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $payload['thread_id'], $userId, $payload['body']);
    $stmt->execute();
    $stmt->close();

    $body = json_encode(['status' => 'success', 'atomic' => true]);
    $marked = $svc->completeWithinTransaction($begin, $body, 201);

    if ($rollback) {
        $c->rollback();
        return ['state' => 'rolled_back', 'reservation' => $begin, 'marked' => $marked];
    }
    $c->commit();
    // Worker dies here. complete() is never reached — the F-20 window.
    return ['state' => 'committed_then_crashed', 'reservation' => $begin, 'marked' => $marked, 'body' => $body];
}

// ── Scenario I — S2/F-20: atomic completion closes the window ──────────────
function scenarioAtomicCompletion(array $payload): void
{
    $msg = ['thread_id' => 9, 'body' => 'append-only payload'];
    $scope = 'POST /api/v1/notifications/message';

    // I-1 REGRESSION BASELINE: the legacy order still reproduces the bug, so
    // this scenario can never silently stop testing anything.
    resetSchema();
    messageWrite(1, 'op-legacy-1', $msg, 'after_commit');
    check(countRows('probe_messages') === 1, 'I-1: legacy path wrote 1 append-only row');
    $legacyHash = recordHashFor(1, 'op-legacy-1', $scope);
    check((recordState($legacyHash)['record_state'] ?? '') === 'processing',
        'I-1: legacy path leaves the record processing after a durable commit');
    expireLease($legacyHash);
    $legacyRetry = service()->begin(1, 'op-legacy-1', $scope, requestHash($msg));
    check(($legacyRetry['state'] ?? '') === 'acquired',
        'I-1: legacy path RE-ACQUIRES after lease expiry (F-20 reproduced)');

    // I-2 THE FIX: completion inside the transaction.
    resetSchema();
    $r = messageWriteAtomic(1, 'op-atomic-1', $msg);
    check($r['state'] === 'committed_then_crashed', 'I-2: committed, then the worker died before complete()');
    check($r['marked'] === true, 'I-2: completeWithinTransaction() marked the record');
    check(countRows('probe_messages') === 1, 'I-2: exactly one append-only row is durable');

    $hash = recordHashFor(1, 'op-atomic-1', $scope);
    $rec = recordState($hash);
    check(($rec['record_state'] ?? '') === 'completed',
        'I-2: THE FIX — the record is already completed, because it committed WITH the effect');
    check((int)($rec['status_code'] ?? 0) === 201, 'I-2: the real status code survived the crash');
    check($rec['response_body'] === $r['body'], 'I-2: the real response body survived the crash');

    // The decisive assertion: after lease expiry the operation REPLAYS.
    expireLease($hash);
    $retry = service()->begin(1, 'op-atomic-1', $scope, requestHash($msg));
    check(($retry['state'] ?? '') === 'replay',
        'I-2: after lease expiry the retry REPLAYS instead of re-executing — F-20 CLOSED');
    check(countRows('probe_messages') === 1,
        'I-2: still exactly one row — no second business effect');
    check(($retry['body'] ?? '') === $r['body'], 'I-2: the replay returns the original body');

    // I-3 ROLLBACK SAFETY: the completion must die with the effect, otherwise
    // the fix would trade duplicate writes for silently lost writes.
    resetSchema();
    $rb = messageWriteAtomic(1, 'op-atomic-2', $msg, true);
    check($rb['state'] === 'rolled_back', 'I-3: the business transaction rolled back');
    check($rb['marked'] === true, 'I-3: the UPDATE reported success before the rollback');
    check(countRows('probe_messages') === 0, 'I-3: no business row survived');
    $hash2 = recordHashFor(1, 'op-atomic-2', $scope);
    check((recordState($hash2)['record_state'] ?? '') === 'processing',
        'I-3: the completion was rolled back TOO — the record is processing again');

    expireLease($hash2);
    $redo = service()->begin(1, 'op-atomic-2', $scope, requestHash($msg));
    check(($redo['state'] ?? '') === 'acquired',
        'I-3: the rolled-back operation is correctly re-executable');

    // I-4 complete() after atomic completion must be a harmless no-op.
    resetSchema();
    $r4 = messageWriteAtomic(1, 'op-atomic-3', $msg);
    service()->complete($r4['reservation'], json_encode(['status' => 'success', 'late' => true]), 200);
    $rec4 = recordState(recordHashFor(1, 'op-atomic-3', $scope));
    check($rec4['response_body'] === $r4['body'],
        'I-4: a late complete() cannot overwrite the atomically stored body');
    check((int)($rec4['status_code'] ?? 0) === 201, 'I-4: a late complete() cannot overwrite the status code');
}

// Database-backed attendance response boundary; executes shipped middleware.
function responseContext(string $key, array $payload): array
{
    $svc = service();
    $reservation = $svc->begin(1, $key, SCOPE, requestHash($payload));
    check(($reservation['state'] ?? '') === 'acquired', 'J: acquired response reservation');
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $key;
    $GLOBALS['conn'] = db();
    $GLOBALS['_fkss_idem'] = ['service' => $svc, 'reservation' => $reservation, 'monitor_id' => null];
    return $reservation;
}

function scenarioAttendanceResponse(array $payload): void
{
    require_once dirname(__DIR__, 2) . '/api/v1/core/response.php';
    require_once dirname(__DIR__, 2) . '/api/v1/core/middleware.php';
    resetSchema();
    $r = responseContext('op-response-atomic', $payload);
    // Shadow only this connection: advisory monitoring must not write inside
    // the business transaction after the authoritative receipt checkpoint.
    db()->query("CREATE TEMPORARY TABLE api_sync_attempts (
        id INT PRIMARY KEY, status VARCHAR(30), idempotency_state VARCHAR(30),
        retry_decision VARCHAR(30), error_category VARCHAR(50), error_code VARCHAR(100),
        http_status INT, completed_at DATETIME, updated_at DATETIME
    ) ENGINE=InnoDB");
    db()->query("INSERT INTO api_sync_attempts (id,status) VALUES (17,'in_flight')");
    $GLOBALS['_fkss_idem']['monitor_id'] = 17;
    $data = ['message' => 'Synthetic ተሳክቷል', 'saved' => 2, 'class_id' => 7, 'date' => $payload['date'], 'submission_id' => 42, 'submission_status' => 'draft'];
    $wire = apiEncodeJsonResponse(apiSuccessPayload($data));
    db()->begin_transaction();
    db()->query("INSERT INTO probe_messages (thread_id,sender_id,body) VALUES (9,1,'synthetic attendance effect')");
    check(apiIdempotencyCompleteDatabaseSuccess($data, 201), 'J: response recorded before business commit');
    check(db()->query('SELECT status FROM api_sync_attempts WHERE id=17')->fetch_assoc()['status'] === 'in_flight',
        'J: advisory monitoring performs no precommit write after the receipt checkpoint');
    db()->commit(); // Simulate crash here: no ordinary response completion.
    $replay = service()->begin(1, 'op-response-atomic', SCOPE, requestHash($payload));
    check($replay['state'] === 'replay' && $replay['body'] === $wire, 'J: lost response replays byte-identical success');
    check($replay['status_code'] === 201 && countRows('probe_messages') === 1, 'J: one effect and original 201');
    apiIdempotencyStore($wire, 201); // normal sender, AFTER business commit
    check(db()->query('SELECT status FROM api_sync_attempts WHERE id=17')->fetch_assoc()['status'] === 'completed',
        'J: ordinary postcommit sender still completes advisory monitoring');
    service()->complete($r, '{"status":"error"}', 500);
    check(recordState($r['record_hash'])['response_body'] === $wire, 'J: late completion cannot replace committed success');
    db()->query('DROP TEMPORARY TABLE api_sync_attempts');

    resetSchema();
    responseContext('op-response-rollback', $payload);
    db()->begin_transaction();
    db()->query("INSERT INTO probe_messages (thread_id,sender_id,body) VALUES (9,1,'synthetic rollback')");
    apiIdempotencyCompleteDatabaseSuccess($data, 201);
    apiIdempotencyRollbackResponse(db());
    check(countRows('probe_messages') === 0 && countRows('api_idempotency_records') === 0, 'J: rollback removes effect and abandons processing reservation');
    apiIdempotencyStore('{"status":"error"}', 503);
    check(countRows('api_idempotency_records') === 0, 'J: retryable 503 is not pinned');
    check(service()->begin(1, 'op-response-rollback', SCOPE, requestHash($payload))['state'] === 'acquired', 'J: identical rolled-back operation can retry');
}

function scenarioAttendanceResponseFailures(array $payload): void
{
    require_once dirname(__DIR__, 2) . '/api/v1/core/response.php';
    require_once dirname(__DIR__, 2) . '/api/v1/core/middleware.php';
    foreach (['encoding', 'owner', 'write', 'oversize'] as $failure) {
        resetSchema();
        $r = responseContext('op-response-' . $failure, $payload);
        if ($failure === 'owner') {
            $GLOBALS['_fkss_idem']['reservation']['owner_token'] = 'wrong-owner';
        }
        if ($failure === 'write') {
            db()->query("CREATE TRIGGER refuse_response BEFORE UPDATE ON api_idempotency_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic refusal'");
        }
        db()->begin_transaction();
        db()->query("INSERT INTO probe_messages (thread_id,sender_id,body) VALUES (9,1,'synthetic must rollback')");
        $failed = false;
        try {
            $data = ['saved' => 2, 'message' => $failure === 'encoding' ? "\xB1" : 'Synthetic'];
            if ($failure === 'oversize') $data['message'] = str_repeat('x', 8388609);
            apiIdempotencyCompleteDatabaseSuccess($data, 201);
        } catch (ApiIdempotencyResponseNotRecorded $error) {
            $failed = true;
            apiIdempotencyRollbackResponse(db());
        }
        check($failed, 'K: ' . $failure . ' refuses business commit');
        check(countRows('probe_messages') === 0, 'K: ' . $failure . ' leaves no effect');
        apiIdempotencyStore('{"status":"error"}', 503);
        check((recordState($r['record_hash'])['record_state'] ?? '') !== 'completed', 'K: failure does not pin a completed 503');
        if ($failure === 'owner') {
            check(recordState($r['record_hash'])['record_state'] === 'processing', 'K: foreign reservation is not deleted');
        }
    }
    resetSchema();
    $r = responseContext('op-response-rollback-denied', $payload);
    $ctx = $GLOBALS['_fkss_idem'];
    $refusal = new class extends mysqli {
        public function __construct() {}
        public function rollback(int $flags = 0, ?string $name = null): bool { return false; }
    };
    $failed = false;
    try { apiIdempotencyRollbackResponse($refusal); } catch (RuntimeException $error) { $failed = true; }
    apiIdempotencyStore('{"status":"error"}', 500); // outer API exception handler
    check($failed && !isset($GLOBALS['_fkss_idem']), 'K: failed rollback detaches ordinary error recording');
    check(recordState($r['record_hash'])['record_state'] === 'processing', 'K: failed rollback cannot abandon reservation');
    $throwing = new class extends mysqli {
        public function __construct() {}
        public function rollback(int $flags = 0, ?string $name = null): bool { throw new RuntimeException('synthetic rollback failure'); }
    };
    $GLOBALS['_fkss_idem'] = $ctx;
    $failed = false;
    try { apiIdempotencyRollbackResponse($throwing); } catch (RuntimeException $error) { $failed = true; }
    apiIdempotencyStore('{"status":"error"}', 500);
    check($failed && !isset($GLOBALS['_fkss_idem']), 'K: throwing rollback also detaches ordinary error recording');
    check(recordState($r['record_hash'])['record_state'] === 'processing', 'K: throwing rollback preserves processing proof, never a cached 500');

    resetSchema();
    $r = responseContext('op-response-completed', $payload);
    service()->complete($r, '{"status":"success","data":{"saved":2}}', 201);
    $before = recordState($r['record_hash']);
    db()->begin_transaction();
    db()->query("INSERT INTO probe_messages (thread_id,sender_id,body) VALUES (9,1,'synthetic duplicate')");
    $failed = false;
    try { apiIdempotencyCompleteDatabaseSuccess(['saved' => 2], 201); }
    catch (ApiIdempotencyResponseNotRecorded $error) { $failed = true; apiIdempotencyRollbackResponse(db()); }
    check($failed && countRows('probe_messages') === 0, 'K: already-completed reservation cannot commit another effect');
    check(recordState($r['record_hash']) === $before, 'K: rollback cleanup never deletes or changes completed outcome');

    unset($GLOBALS['_fkss_idem']);
    $failed = false;
    try { apiIdempotencyCompleteDatabaseSuccess(['saved' => 2], 201); } catch (ApiIdempotencyResponseNotRecorded $error) { $failed = true; }
    check($failed, 'K: missing keyed context is not an unkeyed write');
    unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    check(!apiIdempotencyCompleteDatabaseSuccess(['saved' => 2], 201), 'K: unkeyed legacy mode unchanged, not strict');
    $GLOBALS['_fkss_idem'] = ['service' => service(), 'reservation' => ['backend' => 'file'], 'monitor_id' => null];
    check(!apiIdempotencyCompleteDatabaseSuccess(['saved' => 2], 201), 'K: known file compatibility mode unchanged, not strict');
    $GLOBALS['_fkss_idem'] = ['service' => service(), 'reservation' => ['backend' => 'unknown']];
    $failed = false;
    try { apiIdempotencyCompleteDatabaseSuccess(['saved' => 2], 201); } catch (ApiIdempotencyResponseNotRecorded $error) { $failed = true; }
    check($failed, 'K: unknown backend fails closed');
    unset($GLOBALS['_fkss_idem']);
    foreach ([null, [], 0, ['message' => 'Synthetic'], ['message' => 'Synthetic', 'saved' => 2], ['message' => null]] as $data) {
        $original = $data;
        $expected = ['status' => 'success'];
        if ($data !== null) {
            if (is_array($data) && isset($data['message'])) {
                $expected['message'] = $data['message']; unset($data['message']);
                if ($data) $expected['data'] = $data;
            } else $expected['data'] = $data;
        }
        check(apiSuccessPayload($original) === $expected, 'K: legacy success envelope');
    }
}

/** Inspecting uncertainty must not claim, renew, recover or expose a response. */
function scenarioReadOnlyLookup(array $payload): void
{
    resetSchema();
    $svc = service();
    $hash = requestHash($payload);
    $snapshot = static fn() => db()->query('SELECT * FROM api_idempotency_records ORDER BY record_hash')->fetch_all(MYSQLI_ASSOC);
    check($svc->lookup(1, 'lookup-op', SCOPE, $hash)['state'] === 'unknown', 'L: absent operation is unknown');
    check(countRows('api_idempotency_records') === 0, 'L: lookup never inserts a reservation');
    $r = $svc->begin(1, 'lookup-op', SCOPE, $hash);
    $before = $snapshot();
    check($svc->lookup(1, 'lookup-op', SCOPE, $hash)['state'] === 'processing', 'L: observes processing');
    check($before === $snapshot(), 'L: processing lookup changes no fields');
    expireLease($r['record_hash']);
    $before = $snapshot();
    check($svc->lookup(1, 'lookup-op', SCOPE, $hash)['state'] === 'processing', 'L: expired lease stays processing, never reacquired');
    check($before === $snapshot(), 'L: expired lease owner and timestamps preserved');
    check($svc->lookup(1, 'lookup-op', SCOPE, str_repeat('a', 64))['state'] === 'payload_mismatch', 'L: changed payload has no outcome evidence');
    check($svc->lookup(2, 'lookup-op', SCOPE, $hash)['state'] === 'unknown', 'L: another owner sees unknown');
    check($svc->lookup(1, 'lookup-op', SCOPE . '/submit', $hash)['state'] === 'unknown', 'L: another route sees unknown');
    check($before === $snapshot(), 'L: mismatch/owner/route checks are nonmutating');
    db()->begin_transaction();
    db()->query("INSERT INTO probe_messages (thread_id,sender_id,body) VALUES (9,1,'synthetic lookup effect')");
    check($svc->completeWithinTransaction($r, '{"status":"success","private_note":"never expose"}', 201), 'L: stage completion');
    $reader = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $readerSvc = new \App\Services\ApiIdempotencyService($reader, fallbackDir());
    check($readerSvc->lookup(1, 'lookup-op', SCOPE, $hash)['state'] === 'processing', 'L: reader never observes uncommitted acceptance');
    db()->commit();
    $before = $snapshot();
    $found = $readerSvc->lookup(1, 'lookup-op', SCOPE, $hash);
    check($found === ['state' => 'completed', 'cached_http_status' => 201], 'L: only bounded cached metadata, never raw response');
    check($before === $snapshot(), 'L: completed lookup changes no fields');
    db()->query("UPDATE api_idempotency_records SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND)");
    $before = $snapshot();
    check($svc->lookup(1, 'lookup-op', SCOPE, $hash)['state'] === 'unknown', 'L: expired completion is unknown, NOT permission to retry');
    check($before === $snapshot(), 'L: expired proof is not deleted, renewed or reset');
    $r2 = $svc->begin(1, 'lookup-error', SCOPE, $hash);
    $svc->complete($r2, '{"status":"error","private_note":"never expose"}', 500);
    check($svc->lookup(1, 'lookup-error', SCOPE, $hash) === ['state' => 'completed', 'cached_http_status' => 500], 'L: cached failure is NOT inferred business rejection');
    $offline = new \App\Services\ApiIdempotencyService(null, '/unusable-status-fallback');
    check($offline->lookup(1, 'lookup-op', SCOPE, $hash)['state'] === 'unavailable', 'L: missing DB does not create/read file fallback');
    check($svc->lookup(0, 'lookup-op', SCOPE, $hash)['state'] === 'unavailable', 'L: invalid owner fails closed');
    db()->query('DROP TABLE api_idempotency_records');
    check($svc->lookup(1, 'lookup-op', SCOPE, $hash)['state'] === 'unavailable', 'L: missing schema does not create/fallback');
    $reader->close();
}

$PAYLOAD = ['class_id' => 7, 'date' => '2026-03-01', 'records' => [
    ['member_id' => 101, 'status' => 'present'],
    ['member_id' => 102, 'status' => 'absent'],
]];

// ── Scenario A — normal success ─────────────────────────────────────────────
function scenarioSuccess(array $payload): void
{
    resetSchema();
    $r = attendanceWrite(1, 'op-success-1', $payload);
    check($r['state'] === 'completed', 'A: request completed');
    check(countRows('probe_attendance') === 2, 'A: exactly one business effect (2 member rows)');
    check(countRows('api_idempotency_records') === 1, 'A: exactly one idempotency record');
    $rec = recordState(recordHashFor(1, 'op-success-1', SCOPE));
    check(($rec['record_state'] ?? '') === 'completed', 'A: record_state=completed');
    check((int)($rec['status_code'] ?? 0) === 200, 'A: stored status 200');

    $svc = service();
    $again = $svc->begin(1, 'op-success-1', SCOPE, requestHash($payload));
    check(($again['state'] ?? '') === 'replay', 'A: a later identical call replays');
    check(($again['body'] ?? '') === $r['body'], 'A: replay returns the exact stored body');
}

// ── Scenario B — duplicate request ──────────────────────────────────────────
function scenarioDuplicate(array $payload): void
{
    resetSchema();
    $first = attendanceWrite(1, 'op-dup-1', $payload);
    $second = attendanceWrite(1, 'op-dup-1', $payload);
    check($first['state'] === 'completed', 'B: first request executed');
    check(($second['state'] ?? '') === 'replay', 'B: second identical request replayed, not executed');
    check(countRows('probe_attendance') === 2, 'B: no duplicate business effect');
    check(($second['body'] ?? '') === $first['body'], 'B: deterministic response');
    check((int)($second['status_code'] ?? 0) === 200, 'B: replay keeps the original status code');

    // Same key, different payload must be refused rather than silently applied.
    $conflicting = ['class_id' => 7, 'date' => '2026-03-01', 'records' => [
        ['member_id' => 101, 'status' => 'absent'],
    ]];
    $svc = service();
    $conflict = $svc->begin(1, 'op-dup-1', SCOPE, requestHash($conflicting));
    check(($conflict['state'] ?? '') === 'conflict', 'B: same key + different payload = conflict');
    check(countRows('probe_attendance') === 2, 'B: the conflicting call changed nothing');
}

// ── Scenario C — concurrent duplicate ───────────────────────────────────────
function scenarioConcurrent(array $payload): void
{
    resetSchema();
    // Two independent reservations race for the same key. begin() is backed by
    // INSERT IGNORE on a primary key, so exactly one may win.
    $a = service()->begin(1, 'op-conc-1', SCOPE, requestHash($payload));
    $b = service()->begin(1, 'op-conc-1', SCOPE, requestHash($payload));
    $states = [$a['state'] ?? '', $b['state'] ?? ''];
    sort($states);
    check($states === ['acquired', 'processing'],
        'C: exactly one concurrent caller acquires, the other is told it is processing',
        'got ' . json_encode($states));
    check((int)($b['retry_after'] ?? 0) > 0, 'C: the loser receives a positive Retry-After');

    // Only the winner may complete the record.
    $winner = ($a['state'] ?? '') === 'acquired' ? $a : $b;
    $loser = ($a['state'] ?? '') === 'acquired' ? $b : $a;
    service()->complete($winner, json_encode(['status' => 'success', 'who' => 'winner']), 200);
    service()->complete(
        ['record_hash' => $winner['record_hash'], 'owner_token' => str_repeat('0', 64), 'backend' => 'database'],
        json_encode(['status' => 'success', 'who' => 'impostor']),
        200
    );
    $rec = recordState(recordHashFor(1, 'op-conc-1', SCOPE));
    check(str_contains((string)($rec['response_body'] ?? ''), 'winner'),
        'C: a non-owner token cannot overwrite the completed response');
    check(!isset($loser['record_hash']), 'C: the losing caller never receives a reservation handle');
}

// ── Scenario D — failure before business commit ─────────────────────────────
function scenarioPrecommitFailure(array $payload): void
{
    resetSchema();
    $r = attendanceWrite(1, 'op-precommit-1', $payload, 'before_commit');
    check($r['state'] === 'crashed_before_commit', 'D: worker died before commit');
    check(countRows('probe_attendance') === 0, 'D: business transaction rolled back');

    $rec = recordState(recordHashFor(1, 'op-precommit-1', SCOPE));
    check(($rec['record_state'] ?? '') === 'processing', 'D: reservation is left processing');

    // Before the lease expires the client is correctly told to wait.
    $blocked = service()->begin(1, 'op-precommit-1', SCOPE, requestHash($payload));
    check(($blocked['state'] ?? '') === 'processing', 'D: retry inside the lease is told to wait');
    check(countRows('probe_attendance') === 0, 'D: still nothing written');

    // After the lease expires the retry may safely re-run, because nothing was
    // committed. This is the GOOD half of lease recovery.
    expireLease(recordHashFor(1, 'op-precommit-1', SCOPE));
    $retry = attendanceWrite(1, 'op-precommit-1', $payload);
    check($retry['state'] === 'completed', 'D: retry after lease expiry succeeds');
    check(countRows('probe_attendance') === 2, 'D: exactly one business effect overall');
}

// ── Scenario E/F — the F-20 window: crash AFTER commit, retry after lease ───
function scenarioPostcommitCrash(array $payload): void
{
    resetSchema();
    $r = attendanceWrite(1, 'op-f20-1', $payload, 'after_commit');
    check($r['state'] === 'crashed_after_commit', 'E: worker died after commit, before complete()');
    check(countRows('probe_attendance') === 2, 'E: the business effect IS durable');

    $hash = recordHashFor(1, 'op-f20-1', SCOPE);
    $rec = recordState($hash);
    check(($rec['record_state'] ?? '') === 'processing',
        'E: the idempotency record is still processing — it does not know the write happened');
    check($rec['response_body'] === null, 'E: no response body was ever stored');

    // Inside the lease the client is protected.
    $blocked = service()->begin(1, 'op-f20-1', SCOPE, requestHash($payload));
    check(($blocked['state'] ?? '') === 'processing', 'E: inside the lease the retry is held off');

    // F — after the 300s lease the same request is re-acquired and RE-EXECUTES.
    expireLease($hash);
    $reacquired = service()->begin(1, 'op-f20-1', SCOPE, requestHash($payload));
    check(($reacquired['state'] ?? '') === 'acquired',
        'F: after lease expiry the SAME committed operation is acquired again — '
        . 'the duplicate-execution window is REAL');
}

// ── Scenario F2 — what re-execution actually costs, per write shape ─────────
function scenarioAttendanceConverge(array $payload): void
{
    resetSchema();
    attendanceWrite(1, 'op-conv-1', $payload, 'after_commit');
    check(countRows('probe_attendance') === 2, 'F2: first execution wrote 2 rows');
    expireLease(recordHashFor(1, 'op-conv-1', SCOPE));
    $second = attendanceWrite(1, 'op-conv-1', $payload);
    check($second['state'] === 'completed', 'F2: the re-execution ran to completion');
    check(countRows('probe_attendance') === 2,
        'F2: attendance CONVERGES — UNIQUE(member_id, attendance_date) absorbs the replay');
}

function scenarioCommAppend(): void
{
    resetSchema();
    $payload = ['thread_id' => 3, 'body' => 'the only message'];
    $first = messageWrite(1, 'op-msg-1', $payload, 'after_commit');
    check($first['state'] === 'crashed_after_commit', 'F3: message committed, then the worker died');
    check(countRows('probe_messages') === 1, 'F3: one message row exists');

    $hash = hash('sha256', 1 . "\0" . 'op-msg-1' . "\0" . 'POST /api/v1/notifications/message');
    expireLease($hash);
    $second = messageWrite(1, 'op-msg-1', $payload);
    check($second['state'] === 'completed', 'F3: the retry re-executed the append');
    check(countRows('probe_messages') === 2,
        'F3: APPEND-ONLY WRITES DUPLICATE — two identical messages now exist');
}

// ── Scenario G — stored replay ──────────────────────────────────────────────
function scenarioReplay(array $payload): void
{
    resetSchema();
    $first = attendanceWrite(1, 'op-replay-1', $payload);
    $replay = service()->begin(1, 'op-replay-1', SCOPE, requestHash($payload));
    check(($replay['state'] ?? '') === 'replay', 'G: state is replay');
    check((int)($replay['status_code'] ?? 0) === 200, 'G: HTTP 200 preserved');
    check(($replay['body'] ?? '') === $first['body'], 'G: byte-identical stored body');
    check(countRows('probe_attendance') === 2, 'G: no second business effect');

    // A different user with the same key must not see another user's response.
    $other = service()->begin(2, 'op-replay-1', SCOPE, requestHash($payload));
    check(($other['state'] ?? '') === 'acquired', 'G: records are user-scoped, not key-global');
}

// ── Scenario H — is a transient 5xx pinned as the permanent answer? ─────────
function scenarioErrorPinning(array $payload): void
{
    resetSchema();
    // A transient server error: nothing committed, but the error response is
    // handed to complete() exactly as apiSendJson()/apiIdempotencyStore() do.
    $svc = service();
    $begin = $svc->begin(1, 'op-err-1', SCOPE, requestHash($payload));
    check(($begin['state'] ?? '') === 'acquired', 'H: reservation acquired');
    $svc->complete($begin, json_encode(['status' => 'error', 'message' => 'Could not save attendance.']), 500);
    check(countRows('probe_attendance') === 0, 'H: nothing was written');

    $retry = service()->begin(1, 'op-err-1', SCOPE, requestHash($payload));
    check(($retry['state'] ?? '') === 'replay',
        'H: the retry is answered from the idempotency record, not re-executed');
    check((int)($retry['status_code'] ?? 0) === 500,
        'H: THE TRANSIENT 500 IS PINNED — every later retry of this operation '
        . 'replays the failure and the work can never be delivered');

    // 429 is the one code the middleware abandons, so rate limiting stays retryable.
    $begin2 = service()->begin(1, 'op-err-2', SCOPE, requestHash($payload));
    // apiIdempotencyStore() calls abandon() instead of complete() when $code===429.
    $svc->abandon($begin2);
    $retry2 = service()->begin(1, 'op-err-2', SCOPE, requestHash($payload));
    check(($retry2['state'] ?? '') === 'acquired',
        'H: an abandoned (429) reservation stays retryable');
}

switch ($SCENARIO) {
    case 'success':             scenarioSuccess($PAYLOAD); break;
    case 'duplicate':           scenarioDuplicate($PAYLOAD); break;
    case 'concurrent':          scenarioConcurrent($PAYLOAD); break;
    case 'precommit_failure':   scenarioPrecommitFailure($PAYLOAD); break;
    case 'postcommit_crash':    scenarioPostcommitCrash($PAYLOAD); break;
    case 'lease_expiry':        scenarioPostcommitCrash($PAYLOAD); break;
    case 'attendance_converge': scenarioAttendanceConverge($PAYLOAD); break;
    case 'comm_append':         scenarioCommAppend(); break;
    case 'replay':              scenarioReplay($PAYLOAD); break;
    case 'error_pinning':       scenarioErrorPinning($PAYLOAD); break;
    case 'atomic_completion':   scenarioAtomicCompletion($PAYLOAD); break;
    case 'readonly_lookup': scenarioReadOnlyLookup($PAYLOAD); break;
    case 'attendance_response': scenarioAttendanceResponse($PAYLOAD); break;
    case 'attendance_response_failures': scenarioAttendanceResponseFailures($PAYLOAD); break;
    case 'all':
        scenarioSuccess($PAYLOAD);
        scenarioDuplicate($PAYLOAD);
        scenarioConcurrent($PAYLOAD);
        scenarioPrecommitFailure($PAYLOAD);
        scenarioPostcommitCrash($PAYLOAD);
        scenarioAttendanceConverge($PAYLOAD);
        scenarioCommAppend();
        scenarioReplay($PAYLOAD);
        scenarioErrorPinning($PAYLOAD);
        scenarioAtomicCompletion($PAYLOAD);
        scenarioAttendanceResponse($PAYLOAD);
        scenarioAttendanceResponseFailures($PAYLOAD);
        scenarioReadOnlyLookup($PAYLOAD);
        break;
    default:
        fwrite(STDERR, "unknown scenario: $SCENARIO\n");
        exit(2);
}

verdict();
