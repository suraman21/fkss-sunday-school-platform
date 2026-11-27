<?php
/**
 * Login screen — CLONED FROM THE PROTOTYPE.
 *
 * Source: docs/prototypes/member-app-2026-11-26/frontend/login.html and the
 * "WELCOME + LOGIN" block in css/fk-theme.css (604-720). Composition, in the
 * prototype's order:
 *
 *   1. gold patterned page                      (body.login-page)
 *   2. language pill, top right                 (.simple-header)
 *   3. one framed ivory card                    (.login-container)
 *   4. emblem medallion straddling its top      (::before)
 *   5. ፈለገ ቅዱሳን  — display face, wine
 *   6. መግቢያ       — with the gold rule beneath
 *   7. one sentence of orientation
 *   8. label / input / label / input, then the button
 *
 * WHAT DIFFERS, AND WHY
 *   - The prototype labels the first field "የተማሪ መለያ" (student ID, e.g. FK-1001).
 *     This platform authenticates on a `username` (the login route matches
 *     username OR email), and an ACM account may have either the member code or
 *     a chosen username. The field keeps the prototype's label treatment but
 *     says "የተጠቃሚ ስም", because that is what the server accepts. Telling someone to
 *     enter FK-1001 when the column is `username` would fail at the API.
 *   - A real activation link was added. The prototype had no such screen; the
 *     platform does have a full ACM activation ceremony behind
 *     /api/v1/acm/activate/*, so "there is no way to activate an account" was a
 *     missing doorway, not a missing feature.
 *
 * THE PASSWORD NEVER TOUCHES THIS APP'S PHP. The form is handled by app.js,
 * which posts straight to /api/v1/auth/login and keeps the bearer token in
 * sessionStorage. No <form action>: a native submit would send the password to
 * this server and turn a token API into a password-handling one.
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
            <h2><?= $isAm ? 'መግቢያ' : 'Sign in' ?></h2>

            <p><?= $isAm
                ? 'ወደ የእርስዎ የተማሪ መለያ ለመግባት የተጠቃሚ ስምዎን እና የይለፍ ቃልዎን ያስገቡ።'
                : 'Enter your username and password to sign in to your student account.' ?></p>

            <form id="login-form" novalidate>

                <label for="username"><?= $isAm ? 'የተጠቃሚ ስም' : 'Username' ?></label>
                <input id="username" name="username" type="text"
                       placeholder="<?= $isAm ? 'ለምሳሌ FK-1001' : 'e.g. FK-1001' ?>"
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       required>

                <label for="password"><?= $isAm ? 'የይለፍ ቃል' : 'Password' ?></label>
                <input id="password" name="password" type="password"
                       placeholder="<?= $isAm ? 'የይለፍ ቃልዎን ያስገቡ' : 'Enter your password' ?>"
                       autocomplete="current-password" required>

                <p class="fk-auth-msg" id="login-error" role="alert" aria-live="polite" data-kind="error" hidden></p>

                <button class="fk-btn" type="submit" id="login-submit">
                    <?= $isAm ? 'ግባ' : 'Sign in' ?>
                </button>
            </form>

            <noscript>
                <p class="fk-auth-msg" data-kind="info" style="margin-top:14px">
                    <?= $isAm
                        ? 'ይህ ገጽ ጃቫስክሪፕት ይፈልጋል። እባክዎ በአሳሽዎ ውስጥ ያብሩት።'
                        : 'This page needs JavaScript. Please enable it in your browser.' ?>
                </p>
            </noscript>
        </div>

        <p class="fk-auth-foot">
            <?= $isAm ? 'የይለፍ ቃል ከረሳዎት' : 'Forgot your password?' ?>
            <a href="/members/activate"><?= $isAm ? 'መለያዎን ያንቁ' : 'Activate your account' ?></a>
            <?= $isAm ? 'ወይም የትምህርት ቤቱን ቢሮ ያግኙ።' : 'or contact the accounts office.' ?>
        </p>
    </main>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'መግቢያ' : 'Sign in',
    'active' => '',
    'chromeless' => true,
]);
