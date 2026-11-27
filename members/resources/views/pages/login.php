<?php
/**
 * Login screen.
 *
 * THE PASSWORD NEVER TOUCHES THIS APP'S PHP. The form is handled by app.js,
 * which posts straight to /api/v1/auth/login and stores the returned bearer
 * token in sessionStorage. That is why there is no <form action> here: a
 * native form submit would send the password to this server and turn a
 * token-based API into a password-handling one.
 *
 * It is a <form> element anyway, so Enter submits and browsers offer password
 * managers — with JS disabled, the no-JS notice explains the situation instead
 * of presenting a dead button.
 */
declare(strict_types=1);

$lang = $_COOKIE['members_lang'] ?? 'am';
$isAm = $lang !== 'en';

ob_start();
?>
<div class="fk-welcome" style="justify-content:flex-start;padding-top:8vh">
    <div class="fk-logo" aria-hidden="true">ፈ</div>
    <h1 style="font-size:clamp(24px,6vw,32px)"><?= $isAm ? 'መግቢያ' : 'Sign in' ?></h1>

    <div class="fk-card" style="width:100%;max-width:420px;text-align:left">
        <form id="login-form" novalidate>
            <div class="fk-field">
                <label for="username"><?= $isAm ? 'የተጠቃሚ ስም' : 'Username' ?></label>
                <input class="fk-input" id="username" name="username" type="text"
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       required aria-describedby="username-help">
                <span class="fk-help" id="username-help">
                    <?= $isAm ? 'የተሰጠዎት የተጠቃሚ ስም' : 'The username the office gave you' ?>
                </span>
            </div>

            <div class="fk-field">
                <label for="password"><?= $isAm ? 'የይለፍ ቃል' : 'Password' ?></label>
                <input class="fk-input" id="password" name="password" type="password"
                       autocomplete="current-password" required>
            </div>

            <p class="fk-error" id="login-error" role="alert" hidden></p>

            <button class="fk-btn block" type="submit" id="login-submit">
                <?= $isAm ? 'ግባ' : 'Sign in' ?>
            </button>
        </form>

        <noscript>
            <p class="fk-note" style="margin-top:.8rem">
                <?= $isAm
                    ? 'ይህ ገጽ ጃቫስክሪፕት ይፈልጋል። እባክዎ በአሳሽዎ ውስጥ ያብሩት።'
                    : 'This page needs JavaScript. Please enable it in your browser.' ?>
            </p>
        </noscript>
    </div>

    <p class="fk-help">
        <?= $isAm ? 'የይለፍ ቃል ከረሳዎት የትምህርት ቤቱን ቢሮ ያግኙ።'
                  : 'If you forgot your password, contact the accounts office.' ?>
    </p>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'መግቢያ' : 'Sign in',
    'active' => '',
    'chromeless' => true,
]);
