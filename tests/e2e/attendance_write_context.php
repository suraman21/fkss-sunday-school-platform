<?php
/** Execute the real route with synthetic boundary doubles, no DB/config/network.
 * This verifies adapter behavior, NOT real authentication, SQL or HTTP headers.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$scenario = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$events = [];
class ContextResponse extends RuntimeException {
    public function __construct(public int $status, public array $data) { parent::__construct(); }
}
function err($message, $status = 400, $extra = []) { throw new ContextResponse($status, ['message'=>$message] + $extra); }
function ok($data, $status = 200) { throw new ContextResponse($status, $data); }
function apiRequireAuth() {
    global $scenario, $events;
    $events[] = 'authenticate';
    if (!empty($scenario['anonymous'])) err('Unauthorized', 401);
    return ['uid'=>101, 'av'=>7, 'rol'=>'teacher'];
}
function apiRevalidateAuthorizationScope($auth, $strict = false) {
    global $scenario, $events;
    if (!$strict) throw new RuntimeException('Current authority was not required');
    $events[] = 'current-authority';
    if (!empty($scenario['stale'])) err('Scope changed', 401, ['code'=>'AUTH_SCOPE_CHANGED']);
    return $auth;
}
function apiRolesAttendance() { return ['teacher']; }
function apiRoleIs($auth, $roles) { global $scenario; return empty($scenario['wrong_role']); }
function getCurrentAcademicYear() {
    global $scenario, $events;
    $events[] = 'write-year';
    return array_key_exists('year', $scenario) ? ($scenario['year'] === null ? null : ['id'=>$scenario['year']]) : ['id'=>6];
}
function apiRequireClassAccess($conn, $auth, $classId, $yearId) {
    global $scenario, $events;
    $events[] = ['class-access', $auth['uid'], $classId, $yearId];
    if (!empty($scenario['wrong_class'])) err('Class denied', 403);
}
function getBody() { throw new RuntimeException('Discovery parsed a write body'); }
function apiIdempotencyBegin(...$args) { throw new RuntimeException('Discovery claimed an operation'); }
class ContextConnection {
    public function prepare($sql) {
        global $events;
        if ($sql !== 'SELECT id FROM classes WHERE id = ?') throw new RuntimeException('Unexpected SQL');
        $events[] = 'class-existence';
        return new ContextStatement();
    }
}
class ContextStatement {
    public function bind_param($types, &$id) { if ($types !== 'i') throw new RuntimeException('Unbound class'); }
    public function execute() { return true; }
    public function close() {}
    public function get_result() { return $this; }
    public function fetch_assoc() { global $scenario; return empty($scenario['missing_class']) ? ['id'=>3] : null; }
}
$conn = new ContextConnection();
$method = $scenario['method'] ?? 'GET';
$ROUTE = ['id'=>'write-context', 'sub'=>$scenario['sub'] ?? ''];
$_GET = $scenario['query'] ?? ['class_id'=>'3', 'date'=>'2026-11-05'];
$_GET['_route'] = 'attendance/write-context'; // Both Apache and the CI router add this.
try {
    require $argv[1] ?? __DIR__ . '/../../api/v1/routes/attendance.php';
    throw new RuntimeException('Route returned without a response');
} catch (ContextResponse $response) {
    echo json_encode(['status'=>$response->status, 'data'=>$response->data, 'events'=>$events], JSON_THROW_ON_ERROR);
}
