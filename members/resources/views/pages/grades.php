<?php
/** My grades — own assessment results. */
declare(strict_types=1);
$isAm = ($_COOKIE['members_lang'] ?? 'am') !== 'en';

ob_start();
?>
<h1 class="fk-title"><?= $isAm ? '📈 ውጤቶቼ' : '📈 My grades' ?></h1>
<p class="fk-sub"><?= $isAm ? 'የተመዘገቡ ውጤቶች።' : 'Results recorded by the school.' ?></p>
<div id="grades-root">
    <div class="fk-skel"></div>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'ውጤቶቼ' : 'My grades',
    'active' => 'home',
    'requiresAuth' => true,
    'myPage' => true,
]);
