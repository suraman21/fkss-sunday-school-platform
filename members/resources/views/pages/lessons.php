<?php
/**
 * Lessons — the learning path.
 *
 * THE FIRST REAL PAGE: it talks to /api/v1/learning/path and renders what the
 * server says. The container is rendered empty server-side and filled by
 * learning.js, because the data is per-member and must never be cached or
 * embedded in a page a proxy could reuse.
 *
 * Loading and error states are the client's job, and they exist: a spinner on
 * a slow connection is the difference between "working" and "broken".
 */

declare(strict_types=1);

$isAm = ($_COOKIE['members_lang'] ?? 'am') !== 'en';

ob_start();
?>
<h1 class="fk-title"><?= $isAm ? 'ትምህርቶች' : 'Lessons' ?></h1>
<p class="fk-sub"><?= $isAm
    ? 'በትምህርት ቤቱ በሚወሰነው ቅደም ተከታታይ መሠረት።'
    : 'In the order the school sets them.' ?></p>

<div id="path-root">
    <div class="fk-skel"></div>
    <div class="fk-skel" style="margin-top:12px"></div>
</div>
<?php
$body = (string)ob_get_clean();

$view = new SSMS\Members\Http\Support\View();
echo $view->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'ትምህርቶች' : 'Lessons',
    'active' => 'lessons',
    // Tells app.js this page needs a signed-in session.
    'requiresAuth' => true,
    'learningPage' => true,
]);
