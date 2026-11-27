<?php
/**
 * Account activation — the doorway that was missing.
 *
 * THE FEATURE ALREADY EXISTED; THE SCREEN DID NOT.
 *   /api/v1/acm already implements the whole ACM ceremony: activate/request,
 *   activate/verify, activate/credentials and devices/register. A member with no
 *   credentials had no way to reach any of it. This page adds that way.
 *
 * THE THREE STEPS, AND WHY THEY ARE IN THIS ORDER
 *   1. REQUEST  — the member states their ID number and first name. The office
 *                 reviews it and gives them a six-digit code. Nothing can skip
 *                 this: the code does not exist until a person issues it.
 *   2. VERIFY   — code + the reference returned in step 1 → a 15-minute grant.
 *   3. CREDENTIALS — grant + password → an account sitting at
 *                 account_pending_device.
 *
 * STEP 3 IS NOT THE END. The account is not signable-in until a device is
 * registered, and that step needs WebCrypto signing (or the app). This page
 * says so plainly rather than implying activation is finished. Promising
 * "you can sign in now" and then returning a 403 would be a lie the API
 * already has words for.
 *
 * WHAT THIS PAGE NEVER SHOWS
 *   The API answers every refusal identically — unknown ID, wrong name, wrong
 *   phone — so that it cannot be used to discover who is registered. This page
 *   must not add detail the server withheld, so it shows the server's message
 *   verbatim and never interprets it.
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
            <h2><?= $isAm ? 'መለያ ማንቃት' : 'Activate your account' ?></h2>

            <!-- ============================================================
                 STEP 1 — request
                 ============================================================ -->
            <div id="step-request">
                <p><?= $isAm
                    ? 'የተማሪ መለያዎን እና የመጀመሪያ ስምዎን ያስገቡ። ቢሮው ካረጋገጠ በኋላ የስድስት አሃዝ ኮድ ይሰጥዎታል።'
                    : 'Enter your student ID and first name. The office will check them and give you a six-digit code.' ?></p>

                <form id="activate-request-form" novalidate>
                    <label for="member-code"><?= $isAm ? 'የተማሪ መለያ' : 'Student ID' ?></label>
                    <input id="member-code" name="member_code" type="text"
                           placeholder="<?= $isAm ? 'ለምሳሌ FK-1001' : 'e.g. FK-1001' ?>"
                           autocomplete="username" autocapitalize="characters" spellcheck="false" required>

                    <label for="first-name"><?= $isAm ? 'የመጀመሪያ ስም' : 'First name' ?></label>
                    <input id="first-name" name="first_name" type="text"
                           autocomplete="given-name" spellcheck="false" required>

                    <label for="phone-optional">
                        <?= $isAm ? 'ስልክ ቁጥር (አማራጭ)' : 'Phone number (optional)' ?>
                    </label>
                    <input id="phone-optional" name="phone" type="tel"
                           inputmode="tel" autocomplete="tel">

                    <p class="fk-auth-msg" id="request-msg" role="alert" aria-live="polite" style="display:none"></p>

                    <button class="fk-btn" type="submit" id="request-submit">
                        <?= $isAm ? 'ጥያቄ ይላኩ' : 'Send request' ?>
                    </button>
                </form>
            </div>

            <!-- ============================================================
                 STEP 1 DONE — show the reference, ask for the code
                 ============================================================ -->
            <div id="step-verify" hidden>
                <p><?= $isAm
                    ? 'ጥያቄዎ ተቀብሏል። ቢሮው ኮድ ሲሰጥዎ ከታች ያስገቡት።'
                    : 'Your request was received. When the office gives you a code, enter it below.' ?></p>

                <div class="fk-auth-msg" data-kind="info" style="margin-bottom:6px">
                    <div style="font-size:13px;opacity:.85"><?= $isAm ? 'የጥያቄ ቁጥርዎ' : 'Your reference' ?></div>
                    <div id="request-ref" style="font-family:var(--fk-font);font-size:19px;font-weight:700;letter-spacing:.5px"></div>
                </div>

                <form id="activate-verify-form" novalidate>
                    <label for="otp"><?= $isAm ? 'የስድስት አሃዝ ኮድ' : 'Six-digit code' ?></label>
                    <input id="otp" name="otp" type="text" inputmode="numeric"
                           autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}"
                           placeholder="000000" style="letter-spacing:.4em;text-align:center" required>

                    <p class="fk-auth-msg" id="verify-msg" role="alert" aria-live="polite" style="display:none"></p>

                    <button class="fk-btn" type="submit" id="verify-submit">
                        <?= $isAm ? 'ኮዱን አረጋግጥ' : 'Check code' ?>
                    </button>
                </form>
            </div>

            <!-- ============================================================
                 STEP 3 — choose a password
                 ============================================================ -->
            <div id="step-credentials" hidden>
                <p><?= $isAm
                    ? 'ኮዱ ተረጋግጧል። አሁን የይለፍ ቃል ይምረጡ።'
                    : 'Code confirmed. Now choose a password.' ?></p>

                <form id="activate-credentials-form" novalidate>
                    <label for="new-password"><?= $isAm ? 'አዲስ የይለፍ ቃል' : 'New password' ?></label>
                    <input id="new-password" name="password" type="password"
                           autocomplete="new-password" minlength="8" required>

                    <label for="confirm-password"><?= $isAm ? 'የይለፍ ቃሉን ይድገሙ' : 'Confirm password' ?></label>
                    <input id="confirm-password" name="confirm" type="password"
                           autocomplete="new-password" minlength="8" required>

                    <p class="fk-help" style="text-align:left;margin-top:6px">
                        <?= $isAm ? 'ቢያንስ 8 ፊደሎች።' : 'At least 8 characters.' ?></p>

                    <p class="fk-auth-msg" id="credentials-msg" role="alert" aria-live="polite" style="display:none"></p>

                    <button class="fk-btn" type="submit" id="credentials-submit">
                        <?= $isAm ? 'የይለፍ ቃል አስቀምጥ' : 'Save password' ?>
                    </button>
                </form>
            </div>

            <!-- ============================================================
                 STEP 4 — not finished yet
                 ============================================================ -->
            <div id="step-done" hidden>
                <p class="fk-auth-msg" data-kind="ok" style="text-align:left">
                    <?= $isAm
                        ? 'የይለፍ ቃልዎ ተቀምጧል።'
                        : 'Your password has been saved.' ?>
                </p>
                <p><?= $isAm
                    ? 'አሁንም መለያዎን በስልክ ማጠናቀቅ ይኖርብዎታል። መለያ ከመጠቀም በፊት መሣሪያው መመዝገብ አለበት — ይህም በስልክ መተግበሪያው ይከናወናል።'
                    : 'You still need to finish setup on your phone. A device must be registered before the account can be used, and that step happens in the mobile app.' ?></p>
                <a class="fk-btn gold" href="/members/login">
                    <?= $isAm ? 'ወደ መግቢያ' : 'Back to sign in' ?>
                </a>
            </div>
        </div>

        <p class="fk-auth-foot">
            <a href="/members/login"><?= $isAm ? 'ወደ መግቢያ ተመለስ' : 'Back to sign in' ?></a>
        </p>
    </main>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'መለያ ማንቃት' : 'Activate your account',
    'active' => '',
    'chromeless' => true,
    'activatePage' => true,
]);
