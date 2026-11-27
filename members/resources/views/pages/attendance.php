<?php
/** My attendance — own history and summary. */
declare(strict_types=1);
$isAm = ($_COOKIE['members_lang'] ?? 'am') !== 'en';

ob_start();
?>
<h1 class="fk-title"><?= $isAm ? '📊 የመገኘት ሁኔታ' : '📊 Attendance' ?></h1>
<p class="fk-sub"><?= $isAm ? 'የመጨረሻዎቹ ቀናት።' : 'The most recent days.' ?></p>
<div id="attendance-root">
    <div class="fk-skel"></div>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'የመገኘት ሁኔታ' : 'Attendance',
    'active' => 'home',
    'requiresAuth' => true,
    'myPage' => true,
]);
