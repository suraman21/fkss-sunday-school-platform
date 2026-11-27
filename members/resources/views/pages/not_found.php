<?php
/** 404 page. */
declare(strict_types=1);

$isAm = ($_COOKIE['members_lang'] ?? 'am') !== 'en';

ob_start();
?>
<div class="fk-state">
    <h1 class="fk-title"><?= $isAm ? 'አልተገኘም' : 'Not found' ?></h1>
    <p><?= $isAm
        ? 'የፈለጉትን ገጽ ማግኘት አልቻልንም።'
        : 'We could not find that page.' ?></p>
    <a class="fk-btn ghost" href="/members/home">
        <?= $isAm ? 'ወደ መነሻ' : 'Back to home' ?>
    </a>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'አልተገኘም' : 'Not found',
    'active' => '',
]);
