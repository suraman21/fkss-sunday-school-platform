<?php
/**
 * Common authenticated self-service account page.
 *
 * All profile mutations remain in the established Phase 2 web controller.
 * This page never accepts or emits a target-account identifier.
 */
require_once __DIR__ . '/config.php';

requireAuth();

$supportedProfileRoles = [
    'super_admin',
    'school_admin',
    'info_dept',
    'edu_dept',
    'finance_dept',
    'material_dept',
    'mezmur_dept',
    'hr_dept',
    'teacher',
    'attendance_taker',
    'mezmur_attendance_taker',
    'hr_attendance_taker',
    'content_editor',
];
if (!in_array((string)($_SESSION['admin_role'] ?? ''), $supportedProfileRoles, true)) {
    http_response_code(403);
    echo 'This account role is not supported.';
    exit;
}

$pageTitle = 'My Account';
$pageScript = 'account';
$bodyClass = 'page-account';
$accountCss = ROOT_PATH . '/admin/css/account.css';
$extraHead = '<link rel="stylesheet" href="/admin/css/account.css?v=' . rawurlencode((string)filemtime($accountCss)) . '">';

// Ensure the CSRF token exists before the document and JavaScript bootstrap are emitted.
generateCsrfToken();

ob_start();
?>
<main class="account-shell" id="accountApp" aria-busy="true"
      data-profile-api="<?= e(ssms_app_url('admin/api_settings.php')) ?>"
      data-profile-image-url="<?= e(ssms_app_url('admin/profile_image.php')) ?>">
    <header class="account-topbar">
        <a class="account-back-link" href="<?= e(ssms_app_url('admin/dashboard.php')) ?>">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            <span>Dashboard</span>
        </a>
        <div class="account-topbar-title">
            <span class="account-kicker">Self-service account</span>
            <h1>My Profile</h1>
        </div>
        <div class="account-header-identity" aria-label="Current account">
            <span class="account-header-avatar" id="accountHeaderInitials" aria-hidden="true">…</span>
            <span id="accountHeaderName">Loading…</span>
        </div>
    </header>

    <div class="account-offline" id="accountOffline" role="status" hidden>
        <i class="fa-solid fa-wifi" aria-hidden="true"></i>
        You are offline. Your drafts are preserved; reconnect to save changes.
    </div>
    <div class="account-alert" id="accountGlobalAlert" role="alert" tabindex="-1" hidden></div>
    <div class="sr-only" id="accountAnnouncer" aria-live="polite" aria-atomic="true"></div>

    <div class="account-grid">
        <section class="account-card account-summary" aria-labelledby="profileSummaryTitle">
            <div class="account-avatar-wrap">
                <div class="account-avatar" id="accountAvatar">
                    <img id="profileImage" alt="" hidden>
                    <span id="profileInitials" aria-hidden="true">…</span>
                </div>
                <span class="account-status-dot" id="accountStatusDot" aria-hidden="true"></span>
            </div>
            <h2 id="profileSummaryTitle"><span id="summaryName">Loading profile…</span></h2>
            <p class="account-username" id="summaryUsername">@…</p>
            <div class="account-badges">
                <span class="account-badge" id="summaryRole">Role</span>
                <span class="account-badge account-badge-status" id="summaryStatus">Status</span>
            </div>

            <dl class="account-facts">
                <div><dt>Email</dt><dd id="summaryEmail">—</dd></div>
                <div><dt>Member since</dt><dd id="summaryCreated">—</dd></div>
                <div><dt>Last login</dt><dd id="summaryLastLogin">—</dd></div>
            </dl>

            <div class="account-image-actions">
                <button class="account-button account-button-secondary" type="button" id="chooseImageButton" data-mutation disabled>
                    <i class="fa-solid fa-camera" aria-hidden="true"></i>
                    <span id="chooseImageText">Choose image</span>
                </button>
                <input class="sr-only" type="file" id="profileImageInput" accept="image/jpeg,image/png,image/webp" aria-label="Select profile image" tabindex="-1" disabled>
                <button class="account-button account-button-danger-link" type="button" id="removeImageButton" data-mutation disabled>
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i> Remove
                </button>
            </div>
            <p class="account-help">JPEG, PNG, or WebP. Maximum 4 MB and 12 megapixels; neither dimension may exceed 4096 pixels. Images are stored privately.</p>
            <p class="account-inline-status" id="imageStatus" role="status"></p>
        </section>

        <section class="account-card account-editor" aria-labelledby="editProfileTitle">
            <div class="account-section-heading">
                <div>
                    <span class="account-kicker">Personal details</span>
                    <h2 id="editProfileTitle">Edit profile</h2>
                </div>
                <span class="account-draft-state" id="profileDraftState">Loading…</span>
            </div>

            <form id="profileForm" novalidate>
                <fieldset id="profileFieldset" disabled>
                    <div class="account-field">
                        <label for="profileFullName">Full name <span aria-hidden="true">*</span></label>
                        <input id="profileFullName" name="full_name" type="text" maxlength="100" autocomplete="name" required aria-describedby="profileFullNameHelp profileFullNameError">
                        <p class="account-help" id="profileFullNameHelp">1–100 characters.</p>
                        <p class="account-field-error" id="profileFullNameError"></p>
                    </div>

                    <div class="account-field-row">
                        <div class="account-field">
                            <label for="profileUsername">Username <span aria-hidden="true">*</span></label>
                            <input id="profileUsername" name="username" type="text" minlength="3" maxlength="50" inputmode="text" autocomplete="username" autocapitalize="none" spellcheck="false" required aria-describedby="profileUsernameHelp profileUsernameError">
                            <p class="account-help" id="profileUsernameHelp">3–50 lowercase letters, numbers, dots, or underscores; punctuation cannot repeat.</p>
                            <p class="account-field-error" id="profileUsernameError"></p>
                        </div>

                        <div class="account-field">
                            <label for="profileEmail">Email</label>
                            <input id="profileEmail" name="email" type="email" maxlength="100" autocomplete="email" aria-describedby="profileEmailHelp profileEmailError">
                            <p class="account-help" id="profileEmailHelp">Optional. Used only where enabled by the school.</p>
                            <p class="account-field-error" id="profileEmailError"></p>
                        </div>
                    </div>

                    <div class="account-field account-current-password" id="identityPasswordGroup">
                        <label for="profileCurrentPassword">Current password <span id="profilePasswordRequired" hidden>(required)</span></label>
                        <input id="profileCurrentPassword" name="current_password" type="password" maxlength="4096" autocomplete="current-password" aria-describedby="profileCurrentPasswordHelp profileCurrentPasswordError">
                        <p class="account-help" id="profileCurrentPasswordHelp">Required only when changing your username or email.</p>
                        <p class="account-field-error" id="profileCurrentPasswordError"></p>
                    </div>

                    <div class="account-form-alert" id="profileFormAlert" role="alert" hidden></div>
                    <div class="account-form-actions">
                        <button class="account-button account-button-primary" type="submit" id="saveProfileButton" data-mutation disabled>
                            <i class="fa-solid fa-check" aria-hidden="true"></i>
                            Save profile
                        </button>
                        <button class="account-button account-button-quiet" type="button" id="resetProfileButton" disabled>Discard changes</button>
                    </div>
                </fieldset>
            </form>
        </section>

        <section class="account-card account-security" aria-labelledby="securityTitle">
            <div class="account-section-heading">
                <div>
                    <span class="account-kicker">Security</span>
                    <h2 id="securityTitle">Password</h2>
                </div>
                <i class="fa-solid fa-shield-halved account-security-icon" aria-hidden="true"></i>
            </div>
            <p>Use a unique password with at least 6 characters and no more than 72 UTF-8 bytes.</p>
            <button class="account-button account-button-secondary" type="button" id="openPasswordButton" data-mutation disabled>
                <i class="fa-solid fa-key" aria-hidden="true"></i>
                Change password
            </button>
        </section>
    </div>
</main>

<dialog class="account-dialog" id="imageConfirmDialog" aria-labelledby="imageDialogTitle">
    <form id="imageUploadForm" novalidate>
        <div class="account-dialog-header">
            <div>
                <span class="account-kicker">Preview</span>
                <h2 id="imageDialogTitle">Use this profile image?</h2>
            </div>
            <button class="account-icon-button" type="button" data-close-dialog="imageConfirmDialog" aria-label="Close image preview"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <img class="account-image-preview" id="imagePreview" alt="Selected profile image preview">
        <p class="account-form-alert" id="imageDialogError" role="alert" hidden></p>
        <div class="account-dialog-actions">
            <button class="account-button account-button-quiet" type="button" data-close-dialog="imageConfirmDialog">Cancel</button>
            <button class="account-button account-button-primary" type="submit" id="confirmImageButton" data-mutation>
                <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i> Upload image
            </button>
        </div>
    </form>
</dialog>

<dialog class="account-dialog" id="removeImageDialog" aria-labelledby="removeImageTitle">
    <div class="account-dialog-header">
        <div>
            <span class="account-kicker">Confirmation</span>
            <h2 id="removeImageTitle">Remove profile image?</h2>
        </div>
        <button class="account-icon-button" type="button" data-close-dialog="removeImageDialog" aria-label="Close removal confirmation"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <p>Your current image will remain visible unless removal succeeds.</p>
    <p class="account-form-alert" id="removeImageError" role="alert" hidden></p>
    <div class="account-dialog-actions">
        <button class="account-button account-button-quiet" type="button" data-close-dialog="removeImageDialog">Cancel</button>
        <button class="account-button account-button-danger" type="button" id="confirmRemoveImageButton" data-mutation>Remove image</button>
    </div>
</dialog>

<dialog class="account-dialog" id="passwordDialog" aria-labelledby="passwordDialogTitle">
    <form id="passwordForm" novalidate>
        <div class="account-dialog-header">
            <div>
                <span class="account-kicker">Security</span>
                <h2 id="passwordDialogTitle">Change password</h2>
            </div>
            <button class="account-icon-button" type="button" data-close-dialog="passwordDialog" aria-label="Close password dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>

        <div class="account-field">
            <label for="passwordCurrent">Current password</label>
            <input id="passwordCurrent" type="password" maxlength="4096" autocomplete="current-password" required aria-describedby="passwordCurrentError">
            <p class="account-field-error" id="passwordCurrentError"></p>
        </div>
        <div class="account-field">
            <label for="passwordNew">New password</label>
            <input id="passwordNew" type="password" autocomplete="new-password" required aria-describedby="passwordPolicy passwordNewError">
            <div class="account-password-meter" id="passwordPolicy">
                <span id="passwordCharacterCount">0 characters</span>
                <span id="passwordByteCount">0 / 72 bytes</span>
            </div>
            <p class="account-field-error" id="passwordNewError"></p>
        </div>
        <div class="account-field">
            <label for="passwordConfirm">Confirm new password</label>
            <input id="passwordConfirm" type="password" autocomplete="new-password" required aria-describedby="passwordConfirmError">
            <p class="account-field-error" id="passwordConfirmError"></p>
        </div>
        <div class="account-form-alert" id="passwordFormAlert" role="alert" hidden></div>
        <div class="account-dialog-actions">
            <button class="account-button account-button-quiet" type="button" data-close-dialog="passwordDialog">Cancel</button>
            <button class="account-button account-button-primary" type="submit" id="savePasswordButton" data-mutation>Change password</button>
        </div>
    </form>
</dialog>
<?php
$bodyContent = ob_get_clean();
require ROOT_PATH . '/frontend/layouts/base.php';
