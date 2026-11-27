<?php
/**
 * Members app — front controller.
 *
 * THE ONLY FILE UNDER members/ THAT THE WEB SERVER MAY EXECUTE.
 * Everything else (app/, resources/, config/, sql/, tests/, docs/) sits outside
 * this folder and is denied by members/.htaccess, which repeats the root
 * .htaccess denials because per-directory rewrite rules are not inherited.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

/** @var \SSMS\Members\Http\Router $router */
$request = \SSMS\Members\Http\Request::fromGlobals();
$response = $router->dispatch($request);
$response->send();
