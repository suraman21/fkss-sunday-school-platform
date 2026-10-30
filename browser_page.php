<?php
/** Finite browser-page dispatcher; never accepts a file/handler parameter. */
require_once __DIR__ . '/admin/backend/services/BrowserPageRoutes.php';

$identity = \App\Services\BrowserPageRoutes::dispatchIdentity($_SERVER, __DIR__);
if ($identity === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Page not found.';
    exit;
}
// Existing session, role and feature guards must see the REAL legacy script,
// not this public dispatcher or a friendly slug absent from their role maps.
$_SERVER = $identity;
chdir(dirname($_SERVER['SCRIPT_FILENAME']));
require $_SERVER['SCRIPT_FILENAME'];
