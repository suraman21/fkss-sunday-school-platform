<?php
/**
 * Members app — route table.
 * ---------------------------------------------------------------------
 * This file IS the application's attack surface. Every reachable URL is listed
 * here; there is no reflection and no controller name taken from the request.
 *
 * A reviewer can read this file and know everything a stranger can reach.
 *
 * All routes return HTML. This app serves no JSON of its own — the data API is
 * `/api/v1`, which already owns authentication, authorization and rate
 * limiting. See ARCHIVE.md: a second API surface is exactly what the prototype
 * did wrong.
 */

declare(strict_types=1);

use SSMS\Members\Http\Response;
use SSMS\Members\Http\Support\View;

/** @var \SSMS\Members\Http\Router $router */

$security = [new SSMS\Members\Http\Middleware\SecurityHeaders()];

// ---------------------------------------------------------------------------
// Public: the welcome screen and login.
//
// These are page shells. They contain no member data — the login form posts to
// /api/v1/auth/login itself, so a password never passes through this app's PHP.
// ---------------------------------------------------------------------------
$router->get('/', static function (): Response {
    return Response::html((new View())->render('pages/welcome'));
}, $security);

$router->get('/login', static function (): Response {
    return Response::html((new View())->render('pages/login'));
}, $security);

// ---------------------------------------------------------------------------
// Signed-in shell pages.
//
// PHASE NOTE: the shells below are placeholders that render the real navigation
// and layout with an empty state. They are deliberately NOT faked with sample
// data — a placeholder that shows invented lessons is indistinguishable from a
// broken app, and worse, it hides whether the API contract is right.
// ---------------------------------------------------------------------------
$pages = [
    '/home' => 'pages/home',
    '/lessons' => 'pages/lessons',
    '/lesson/{id}' => 'pages/lesson',
    '/quiz/{id}' => 'pages/quiz',
    '/tasks' => 'pages/tasks',
    '/progress' => 'pages/progress',
    '/exam-prep' => 'pages/exam_prep',
    // In the prototype these were first-class pages. They are registered here
    // so the navigation cannot link to a 404, and so the feature list in
    // partials/placeholder.php has a page for every entry.
    '/assistant' => 'pages/assistant',
    '/notifications' => 'pages/notifications',
    '/account' => 'pages/account',
];

foreach ($pages as $pattern => $template) {
    $router->get($pattern, static function (array $params) use ($template): Response {
        return Response::html((new View())->render($template, ['params' => $params]));
    }, $security);
}
