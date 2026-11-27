<?php
/** My information — the member's own register record plus department status. */
declare(strict_types=1);
$isAm = ($_COOKIE['members_lang'] ?? 'am') !== 'en';

ob_start();
?>
<h1 class="fk-title"><?= $isAm ? '📋 የእኔ መረጃ' : '📋 My information' ?></h1>
<p class="fk-sub"><?= $isAm
    ? 'ከትምህርት ቤቱ መዝገብ የተገኘ።'
    : 'From the school register.' ?></p>
<div id="profile-root">
    <div class="fk-skel"></div>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'የእኔ መረጃ' : 'My information',
    'active' => 'home',
    'requiresAuth' => true,
    'myPage' => true,
]);
