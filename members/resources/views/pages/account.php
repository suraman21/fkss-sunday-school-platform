<?php
/**
 * My account.
 *
 * WHAT IS LIVE AND WHAT IS NOT — this distinction matters here.
 *   Sign out                 LIVE. app.js clears the token first, then revokes.
 *   Change password          NOT possible from this app yet. The existing
 *                            password-change endpoint (admin/api_settings.php)
 *                            authenticates with a PHP session + CSRF and only
 *                            accepts the staff roles that expose a dashboard.
 *                            A member signs in with a bearer token and has no
 *                            PHP session, so that endpoint answers 401.
 *                            Adding a token-authenticated password change is an
 *                            ACM decision (it owns credential lifecycle), not
 *                            something this app should bolt on.
 *   Password reset           LIVE, via ACM — the office issues a recovery.
 *
 * Saying "contact the office" for a change is honest; wiring this page to an
 * endpoint that cannot accept a member session would just produce a 401 that
 * looks like a bug.
 */

declare(strict_types=1);

$lang = $_COOKIE['members_lang'] ?? 'am';
$isAm = $lang !== 'en';

ob_start();
?>
<h1 class="fk-title"><?= $isAm ? 'መለያዬ' : 'My account' ?></h1>

<div class="fk-card" style="margin-bottom:16px">
    <h3><?= $isAm ? 'የመግቢያ መረጃ' : 'Sign-in details' ?></h3>
    <p class="fk-help">
        <?= $isAm
            ? 'የተጠቃሚ ስምዎንና የሚገኙበትን መረጃ ለማየት የቢሮውን ያግኙ።'
            : 'Contact the accounts office to see your username and contact details on record.' ?>
    </p>
</div>

<div class="fk-card" style="margin-bottom:16px">
    <h3><?= $isAm ? 'የይለፍ ቃል' : 'Password' ?></h3>
    <p class="fk-help">
        <?= $isAm
            ? 'የይለፍ ቃል ለመቀየር የትምህርት ቤቱን ቢሮ ያግኙ። ቢሮው የሚሰጥዎትን አዲስ የይለፍ ቃል በስልክዎ መቀየር ይችላሉ።'
            : 'To change your password, contact the accounts office. They issue a '
              . 'recovery, and you set the new password on your phone.' ?>
    </p>
    <p class="fk-note" style="margin-top:.6rem">
        <?= $isAm ? 'በዚህ ገጽ ላይ የይለፍ ቃል መቀየር ገና አልተጨመረም።'
                  : 'Changing your password from this page is not available yet.' ?>
    </p>
</div>

<div class="fk-card">
    <h3><?= $isAm ? 'ውጣ' : 'Sign out' ?></h3>
    <p class="fk-help">
        <?= $isAm
            ? 'በተጋራ ስልክ ላይ ካሉ ሁልጊዜ ውጡ።'
            : 'Always sign out if you are using a shared phone.' ?>
    </p>
    <button type="button" class="fk-btn ghost" data-action="logout">
        <?= $isAm ? 'ውጣ' : 'Sign out' ?>
    </button>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'መለያዬ' : 'My account',
    'active' => '',
]);
