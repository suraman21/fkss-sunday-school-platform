<?php
/** Local PHP-server rewrite adapter, never a production router. */
if (PHP_SAPI !== 'cli-server' || getenv('SSMS_AUDIT_TESTING') !== '1') {
    http_response_code(404); exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($path) || preg_match('~(?:^|/)\.|^/(?:tests|sql|docs|tools|scripts|vendor|Mobile)(?:/|$)~', $path)) {
    http_response_code(404); exit;
}
if (str_starts_with($path, '/api/v1/') && !str_ends_with($path, '.php')) {
    $_GET['_route'] = substr($path, strlen('/api/v1/'));
    require __DIR__ . '/../../api/v1/index.php';
    return true;
}
// Mirror the finite browser aliases for CLI-server integration tests.
// Apache's actual rewrite behavior is verified separately; this adapter never
// replaces or relaxes the handler's session/role/feature checks.
require_once __DIR__ . '/../../admin/backend/services/BrowserPageRoutes.php';
$_SERVER['FKSS_BROWSER_ROUTES'] = '1';
$root = dirname(__DIR__, 2);
$isAliasShape = preg_match('~^/(?:verify-member|admin/(?:(?:dashboards|id_cards)/)?[a-z][a-z0-9-]*|(?:frontend/pages|monitor)/[a-z][a-z0-9-]*)$~D', $path);
if (isset(\App\Services\BrowserPageRoutes::pages()[$path])
    || ($isAliasShape && !is_file($root . $path) && !is_dir($root . $path))) {
    $_SERVER['SCRIPT_NAME'] = '/browser_page.php';
    require __DIR__ . '/../../browser_page.php';
    return true;
}
return false;
