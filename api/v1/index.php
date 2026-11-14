<?php
/**
 * ============================================================
 * REST API v1 — Main Router
 * ============================================================
 * All requests to /api/v1/* come through here.
 * 
 * URL pattern: /api/v1/{resource}/{id?}/{action?}
 * Examples:
 *   GET  /api/v1/members           → routes/members.php
 *   GET  /api/v1/members/42        → routes/members.php (with $resourceId=42)
 *   POST /api/v1/auth/login        → routes/auth.php
 *   GET  /api/v1/dashboard/stats   → routes/dashboard.php
 * ============================================================
 */

if (!defined('WBWS_API_REQUEST')) {
    define('WBWS_API_REQUEST', true);
}

// Smaller JSON on 2G/3G. Harmless if zlib is missing.
if (!headers_sent() && extension_loaded('zlib')) {
    ini_set('zlib.output_compression', 'On');
    ini_set('zlib.output_compression_level', '5');
}

// Cheap liveness — no MariaDB, no auth. A 4G phone must not wait on
// the school database just to know the site is up.
$__fkssRoute = '';
if (!empty($_GET['_route'])) {
    $__fkssRoute = $_GET['_route'];
} elseif (!empty($_GET['path'])) {
    $__fkssRoute = $_GET['path'];
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $__fkssRoute = $_SERVER['PATH_INFO'];
}
if (!is_string($__fkssRoute)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>'error','message'=>'Invalid API route.']);
    exit;
}
$__fkssRoute = trim($__fkssRoute, '/');
if ($__fkssRoute === 'ping') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *');
    }
    echo json_encode([
        'status' => 'success',
        'data' => [
            'api' => 'FKSS',
            'version' => '1.0',
            'status' => 'running',
            'time' => date('c'),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Load core modules
require_once __DIR__ . '/core/response.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/acl.php';
require_once __DIR__ . '/core/middleware.php';

// Keep infrastructure diagnostics in server logs. Mobile clients always receive
// a stable JSON contract rather than PHP/MySQL exception text or an HTML error.
set_exception_handler(function (Throwable $error): void {
    reportInternalError('Unhandled API v1 exception', $error);
    apiSendJson(['status' => 'error', 'message' => 'The service could not complete the request.'], 500);
});
register_shutdown_function(function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    reportInternalError('Fatal API v1 error', $error['message'] ?? 'fatal error');
    apiSendJson(['status' => 'error', 'message' => 'The service could not complete the request.'], 500);
});

// CORS + headers
handleCors();

// Parse the route — supports multiple approaches:
// 1. .htaccess rewrite: /api/v1/members → index.php?_route=members
// 2. Query param: /api/v1/index.php?path=members
// 3. PATH_INFO: /api/v1/index.php/members
$route = $__fkssRoute;
$parts = explode('/', $route);
$resource = $parts[0] ?? '';
$resourceId = $parts[1] ?? null;
$subAction = $parts[2] ?? null;
$method = getMethod();

// Numeric IDs should be integers
if ($resourceId !== null && ctype_digit($resourceId)) {
    $resourceId = (int)$resourceId;
}

// API info endpoint
if ($route === '' || $route === 'ping') {
    ok([
        'api' => defined('API_NAME') ? API_NAME : 'School Management System',
        'version' => '1.0',
        'status' => 'running',
        'time' => date('c'),
        'database' => isset($conn) && !$conn->connect_error ? 'connected' : 'error'
    ]);
}

// ============================================================
// Route Map — each resource dispatches to its own file
// ============================================================
$routeMap = [
    'auth'          => 'auth.php',
    'acm'           => 'acm.php',
    'members'       => 'members.php',
    'attendance'    => 'attendance.php',
    'classes'       => 'classes.php',
    'dashboard'     => 'dashboard.php',
    'teachers'      => 'teachers.php',
    'enrollment'    => 'enrollment.php',
    'subjects'      => 'subjects.php',
    'users'         => 'users.php',
    'grades'        => 'grades.php',
    'app'           => 'app.php',
    'telemetry'     => 'telemetry.php',
    'mezmur'        => 'mezmur.php',
    'hr'            => 'hr.php',
    'notifications' => 'notifications.php',
    'sync'          => 'sync.php',
    'timetable'     => 'timetable.php',
];

// Check if resource exists
if (!isset($routeMap[$resource])) {
    err("Unknown endpoint: /{$resource}. Available: " . implode(', ', array_keys($routeMap)), 404);
}

// Feature flags are server-authoritative. Mobile UI filtering is convenience;
// a disabled module's API remains unavailable even to a crafted client.
$resourceFeature = \App\Services\FeatureGate::forApiResource($resource);
if ($resourceFeature !== null && !\App\Services\FeatureGate::isEnabled($resourceFeature)) {
    err('This feature is not enabled for this deployment.', 403);
}

// Load the route file
$routeFile = __DIR__ . '/routes/' . $routeMap[$resource];
if (!file_exists($routeFile)) {
    err("Endpoint /{$resource} is not yet implemented.", 501);
}

// Make route context available to route files
$ROUTE = [
    'resource'   => $resource,
    'id'         => $resourceId,
    'sub'        => $subAction,
    'method'     => $method,
    'parts'      => $parts,
    'full_route' => $route,
];

// Dispatch to route handler
require $routeFile;

// If the route file doesn't call ok() or err(), return 404
err("No handler matched for {$method} /{$route}", 404);
