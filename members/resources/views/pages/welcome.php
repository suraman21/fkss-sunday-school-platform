<?php
/**
 * Welcome screen — the prototype's landing page, same composition:
 * centred brand, one sentence of orientation, one way forward.
 *
 * Rendered server-side with the layout chrome disabled. No data, no API call:
 * a first visit must paint instantly on a slow connection.
 *
 * @var string $content unused here; rendered by the route
 */
declare(strict_types=1);

$lang = $_COOKIE['members_lang'] ?? 'am';
$isAm = $lang !== 'en';

ob_start();
?>
<div class="fk-welcome">
    <div class="fk-logo" aria-hidden="true">ፈ</div>
    <h1>ፈለገ ቅዱሳን</h1>
    <p><?= $isAm
        ? 'ወደ ፈለገ ቅዱሳን የመማሪያ መድረክ እንኳን በደህና መጡ።'
        : 'Welcome to the Felege Kidusan learning platform.' ?></p>
    <a class="fk-btn gold" href="/members/login"><?= $isAm ? 'መግቢያ' : 'Sign in' ?></a>
    <p class="fk-help" style="max-width:30ch">
        <?= $isAm
            ? 'የመግቢያ መረጃዎን ከትምህርት ቤቱ ቢሮ ይቀበላሉ።'
            : 'Your sign-in details come from the accounts office.' ?></p>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => '',
    'active' => '',
    'chromeless' => true,
]);
