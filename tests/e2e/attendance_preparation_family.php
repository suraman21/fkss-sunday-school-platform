<?php
/** Actual auth routes/services on synthetic TEMP SQL; adapters explicitly doubled. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('SSMS_AUDIT_TESTING') !== '1') { fwrite(STDERR, "Explicit isolated test environment required.\n"); exit(2); }
$socket = getenv('SSMS_PREPARATION_TEST_SOCKET') ?: null;
if ($socket !== null && !preg_match('#^/home/user/\.cache/strict-preparation-db\.[A-Za-z0-9]+/mysql\.sock$#D', $socket)) throw new RuntimeException('Unexpected synthetic fixture socket.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($socket ? 'localhost' : '127.0.0.1', 'ssms', 'ssms', 'ssms_comm_e2e', $socket ? 0 : 3306, $socket);
$conn->set_charset('utf8mb4');
define('JWT_SECRET', 'synthetic-preparation-family-key-not-for-deployment');
require_once __DIR__ . '/../../api/v1/core/auth.php';
use App\Services\RefreshTokenService as R;
class FamilyFixtureResponse extends RuntimeException {
    public function __construct(public int $status, public array $body) { parent::__construct('Synthetic response adapter', $status); }
}
// Only transport/utility adapters are doubled. Signature, auth, route branches,
// refresh persistence/rotation/replay/revocation and SQL execute production code.
function err($message, $status = 400, $extra = []): never { throw new FamilyFixtureResponse($status, $extra); }
function ok($data = [], $message = null): never { throw new FamilyFixtureResponse(200, $data); }
function getBody(): array { return $GLOBALS['familyFixtureBody']; }
function isApiRateLimited($bucket, $limit): bool { return false; }
function logApiAction(...$args): void {}
function familyRoute(string $action, array $input): FamilyFixtureResponse {
    global $conn;
    $GLOBALS['familyFixtureBody'] = $input; $ROUTE = ['id'=>$action]; $method = 'POST';
    try { require __DIR__ . '/../../api/v1/routes/auth.php'; }
    catch (FamilyFixtureResponse $response) { return $response; }
    throw new RuntimeException('Route did not respond');
}
function familyAccess(string $token): FamilyFixtureResponse {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    try { return new FamilyFixtureResponse(200, apiRequireAuth()); }
    catch (FamilyFixtureResponse $response) { return $response; }
}
$checks = 0;
function verifyFamily(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function rejectsBinding(callable $issue): void {
    try { $issue(); } catch (RuntimeException|InvalidArgumentException $error) { verifyFamily(true, 'Controlled rejection'); return; }
    throw new RuntimeException('Unsupported family binding accepted');
}
function loginFamily(): array {
    $response = familyRoute('login', ['username'=>'synthetic-family-user','password'=>'synthetic-test-password']);
    verifyFamily($response->status === 200, 'Actual login succeeds');
    return $response->body;
}
function familyUpdate(string $sql, string $fid): void {
    global $conn; $s = $conn->prepare($sql); $s->bind_param('s', $fid); $s->execute(); $s->close();
}
$tables = [];
$log = tmpfile(); $previousLog = ini_get('error_log');
ini_set('error_log', stream_get_meta_data($log)['uri']);
try {
    $conn->query('CREATE TEMPORARY TABLE users (id INT PRIMARY KEY,username VARCHAR(80),email VARCHAR(80),full_name VARCHAR(80),role VARCHAR(40),password_hash VARCHAR(255),is_active INT,authorization_version BIGINT,profile_image_path VARCHAR(200),last_login DATETIME) ENGINE=InnoDB'); $tables[] = 'users';
    $hash = password_hash('synthetic-test-password', PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO users VALUES (7,'synthetic-family-user',NULL,'Synthetic Family User','teacher',?,1,4,NULL,NULL)");
    $stmt->bind_param('s', $hash); $stmt->execute(); $stmt->close();
    $schema = file_get_contents(__DIR__ . '/../../sql/010_refresh_token_rotation.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS `(api_refresh_[a-z_]+)` \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $matches, PREG_SET_ORDER);
    verifyFamily(count($matches) === 2, 'Actual refresh migration definitions found');
    foreach ($matches as $match) {
        $conn->query(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $match[0])); $tables[] = $match[1];
    }
    $strict = ($argv[1] ?? '') === 'strict';
    mysqli_report($strict ? MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT : MYSQLI_REPORT_OFF);
    $mode = (new mysqli_driver())->report_mode;
    $_SERVER['HTTP_X_APP_BUILD'] = '24'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_USER_AGENT'] = 'Synthetic fixture';
    $first = loginFamily(); $access = verifyToken($first['token']); $refresh = verifyToken($first['refresh_token']);
    verifyFamily(is_string($access['fid'] ?? null) && $access['fid'] === $refresh['fid'] && $access['rsid'] === $refresh['jti'], 'Actual login binds signed access to its refresh family');
    verifyFamily($access['jti'] !== $refresh['jti'] && $access['exp'] <= time() + API_TOKEN_EXPIRY, 'Access identity and expiry remain independent');
    verifyFamily(apiRefreshAccessBinding($first['refresh_token'], 7) === ['family_id'=>$access['fid'],'session_id'=>$refresh['jti']], 'Binding extraction verifies actual signed owner');
    verifyFamily(familyAccess($first['token'])->status === 200, 'Bound access works with live family');
    $rotation = familyRoute('refresh-token', ['refresh_token'=>$first['refresh_token']]);
    verifyFamily($rotation->status === 200, 'Actual refresh route rotates'); $rotated = $rotation->body;
    verifyFamily(verifyToken($rotated['token'])['fid'] === $access['fid'] && verifyToken($rotated['refresh_token'])['fid'] === $access['fid'], 'Rotation preserves signed family');
    verifyFamily(verifyToken($rotated['token'])['jti'] !== $access['jti'], 'New access still gets new jti');
    verifyFamily(familyAccess($first['token'])->status === 200 && familyAccess($rotated['token'])->status === 200, 'Legitimate rotation does not falsely revoke earlier unexpired access');
    $independent = loginFamily(); $otherFid = verifyToken($independent['token'])['fid']; $otherSid = verifyToken($independent['token'])['rsid'];
    $otherBinding = ['family_id'=>$otherFid,'session_id'=>$otherSid];
    verifyFamily($otherFid !== $access['fid'], 'Independent login gets independent family');
    $logout = familyRoute('logout', ['refresh_token'=>$first['refresh_token']]);
    verifyFamily($logout->status === 200, 'Actual happy-path logout revokes through consumed ancestor');
    foreach (['0','23','24','999'] as $build) {
        $_SERVER['HTTP_X_APP_BUILD'] = $build;
        foreach ([$first['token'], $rotated['token']] as $token) {
            $denial = familyAccess($token);
            verifyFamily($denial->status === 401 && ($denial->body['code'] ?? '') === 'SESSION_REVOKED', 'Build header cannot bypass bound-family revocation');
        }
    }
    $_SERVER['HTTP_X_APP_BUILD'] = '24';
    verifyFamily(verifyToken($first['token']) !== null, 'Denied access has not naturally expired');
    verifyFamily(familyAccess($independent['token'])->status === 200, 'Revocation does not revoke independent family');
    $denial = familyRoute('refresh-token', ['refresh_token'=>$rotated['refresh_token']]);
    verifyFamily($denial->status === 401 && $denial->body['code'] === 'SESSION_REVOKED', 'Actual refresh is denied after logout');
    $replayed = loginFamily();
    $next = familyRoute('refresh-token', ['refresh_token'=>$replayed['refresh_token']]);
    verifyFamily($next->status === 200, 'Replay fixture first rotates legitimately');
    $replay = familyRoute('refresh-token', ['refresh_token'=>$replayed['refresh_token']]);
    verifyFamily($replay->status === 401 && $replay->body['code'] === 'REFRESH_REUSED', 'Actual replay detection revokes family');
    verifyFamily(familyAccess($next->body['token'])->body['code'] === 'SESSION_REVOKED', 'Replay revocation also denies newly rotated access immediately');
    verifyFamily(familyAccess($independent['token'])->status === 200, 'Replay leaves other family live');
    foreach ([createToken(8,'other','teacher','Other',900,1,$otherBinding), createToken(7,'synthetic-family-user','teacher','Synthetic Family User',900,4,['family_id'=>str_repeat('a',64),'session_id'=>$otherSid])] as $token) {
        $denial = familyAccess($token); verifyFamily($denial->status === 401 && $denial->body['code'] === 'SESSION_REVOKED', 'Wrong owner or unknown family refused');
    }
    familyUpdate('UPDATE api_refresh_sessions SET consumed_at=CURRENT_TIMESTAMP WHERE family_id=?', $otherFid);
    verifyFamily(familyAccess($independent['token'])->status === 200, 'Consumption alone is not revocation of existing access');
    familyUpdate('UPDATE api_refresh_sessions SET consumed_at=NULL,expires_at=FROM_UNIXTIME(1) WHERE family_id=?', $otherFid);
    verifyFamily(familyAccess($independent['token'])->body['code'] === 'SESSION_REVOKED', 'Expired family not live');
    familyUpdate('UPDATE api_refresh_sessions SET expires_at=FROM_UNIXTIME(UNIX_TIMESTAMP()+3600) WHERE family_id=?', $otherFid);
    verifyFamily(familyAccess($independent['token'])->status === 200, 'Fixture restoration confirms cause of refusal');
    // Explain the EXACT production SQL against migration010's real indexes.
    $sql = (new ReflectionClass(R::class))->getConstant('ACCESS_SESSION_SQL');
    $stmt = $conn->prepare('EXPLAIN ' . $sql); $uid = 7;
    $stmt->bind_param('ssi',$otherSid,$otherFid,$uid); $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc(); $stmt->close();
    verifyFamily(($plan['key'] ?? '') === 'PRIMARY' && (int)$plan['rows'] <= 1, 'Primary-key lookup, not accumulated family-history scan');
    foreach (['', 'wrong', str_repeat('A',64), str_repeat('a',63), 0, false, [], ['family_id'=>$otherFid], ['family_id'=>$otherFid,'session_id'=>'bad'], $otherBinding + ['extra'=>1]] as $bad) {
        rejectsBinding(fn() => createToken(7,'synthetic','teacher','Synthetic',900,4,$bad));
    }
    rejectsBinding(fn() => apiRefreshAccessBinding($independent['refresh_token'], 8));
    rejectsBinding(fn() => apiRefreshAccessBinding($independent['token'], 7));
    rejectsBinding(fn() => apiRefreshAccessBinding($independent['refresh_token'] . 'bad', 7));
    $badRefresh = createRefreshToken(7,'synthetic','teacher','Synthetic',4,str_repeat('a',64),'malformed',time()+60);
    rejectsBinding(fn() => apiRefreshAccessBinding($badRefresh,7));
    rejectsBinding(fn() => apiRefreshAccessBinding(createRefreshToken(7,'synthetic','teacher','Synthetic',4,'bad-session',$otherFid,time()+60),7));
    rejectsBinding(fn() => apiRefreshAccessBinding(createRefreshToken(7,'synthetic','teacher','Synthetic',4,str_repeat('b',64),$otherFid,time()-60),7));
    foreach (['missing_fid','missing_rsid','bad_rsid','bad_fid'] as $kind) {
        $bad = verifyToken($independent['token']);
        if ($kind === 'missing_fid') unset($bad['fid']);
        if ($kind === 'missing_rsid') unset($bad['rsid']);
        if ($kind === 'bad_rsid') $bad['rsid'] = 'invalid';
        if ($kind === 'bad_fid') $bad['fid'] = [];
        $encoded = base64_encode(json_encode($bad, JSON_THROW_ON_ERROR));
        $signed = $encoded . '.' . hash_hmac('sha256',$encoded,API_TOKEN_SECRET);
        $denial = familyAccess($signed);
        verifyFamily($denial->status === 401 && $denial->body['code'] === 'SESSION_REVOKED', 'Signed partial/malformed binding cannot fall back to unbound compatibility');
    }
    $legacy = createToken(7,'synthetic-family-user','teacher','Synthetic Family User',900,4);
    verifyFamily(!array_key_exists('fid',verifyToken($legacy)), 'Old six-argument helper remains explicitly unbound');
    $_SERVER['HTTP_X_APP_BUILD'] = '0';
    verifyFamily(familyAccess($legacy)->status === 200, 'Unbound legacy compatibility preserved, NOT a preparation/revocation grant');
    // Real missing-column SQL failure shadows any persistent harness table;
    // never drop or alter a persistent application table to simulate failure.
    $conn->query('DROP TEMPORARY TABLE api_refresh_sessions');
    $conn->query('CREATE TEMPORARY TABLE api_refresh_sessions (session_id CHAR(64)) ENGINE=InnoDB');
    $denial = familyAccess($independent['token']);
    verifyFamily($denial->status === 503 && $denial->body['code'] === 'AUTH_SESSION_REVALIDATION_UNAVAILABLE', 'SQL failure is unavailable, not active or revoked');
    verifyFamily(familyAccess($legacy)->status === 200, 'Unbound compatibility is not silently changed by bound schema failure');
    verifyFamily((new mysqli_driver())->report_mode === $mode, 'Reporting mode unchanged');
    rewind($log); $logged = stream_get_contents($log);
    verifyFamily(substr_count($logged,'API refresh-family revalidation unavailable.') === 1 && !str_contains($logged, $first['token']), 'Unavailable log is fixed, no access token output');
    echo json_encode(['result'=>'PASS','checks'=>$checks,'mysqli_strict'=>$strict,
        'actual'=>['login route','refresh route','logout happy path','replay','signed access guard','SQL missing column'],
        'adapter_doubles'=>['body','rate limit','audit utility','response'],
        'not_proven'=>['HTTP transport','revocation write serialization','failed logout acknowledgment','unbound-token revocation','hardware binding','offline grant']], JSON_THROW_ON_ERROR) . "\n";
} finally {
    $conn->rollback();
    foreach (array_reverse($tables) as $table) $conn->query('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
    $conn->close(); ini_set('error_log', $previousLog); fclose($log);
}
