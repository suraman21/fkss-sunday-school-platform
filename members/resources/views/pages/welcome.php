<?php
/**
 * Welcome screen — the prototype's landing composition.
 *
 * CLONED FROM docs/prototypes/member-app-2026-11-26/frontend/
 *   Gold patterned page, one centred framed ivory card with the emblem
 *   medallion straddling its top edge, brand in the display face, a one-line
 *   orientation sentence, and a single way forward. That is the prototype's
 *   welcome page; this is not a reinterpretation of it.
 *
 * Rendered with the layout chrome disabled — the card supplies its own page
 * background, so the shared ivory page and church watermark must not appear.
 *
 * No API call: a first visit paints instantly on a slow connection, which is
 * the whole point of a landing screen.
 */
declare(strict_types=1);

$lang = $_COOKIE['members_lang'] ?? 'am';
$isAm = $lang !== 'en';

ob_start();
?>
<div class="fk-auth-page">

    <?php require __DIR__ . '/../partials/lang-pill.php'; ?>

    <main class="fk-auth-main">
        <div class="fk-auth-card">
            <h1>ፈለገ ቅዱሳን</h1>
            <h2><?= $isAm ? 'እንኳን በደህና መጡ' : 'Welcome' ?></h2>

            <p><?= $isAm
                ? 'ወደ ፈለገ ቅዱሳን የመማሪያ መድረክ እንኳን በደህና መጡ።'
                : 'Welcome to the Felege Kidusan learning platform.' ?></p>

            <a class="fk-btn gold" href="/members/login"><?= $isAm ? 'መግቢያ' : 'Sign in' ?></a>

            <p class="fk-auth-foot" style="text-align:center">
                <?= $isAm
                    ? 'የመግቢያ መረጃዎን ከትምህርት ቤቱ ቢሮ ይቀበላሉ።'
                    : 'Your sign-in details come from the accounts office.' ?>
            </p>
        </div>
    </main>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'እንኳን በደህና መጡ' : 'Welcome',
    'active' => '',
    'chromeless' => true,
]);
