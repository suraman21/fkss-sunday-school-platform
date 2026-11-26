<?php
/**
 * Universal Profile Tab Component — Sunday School Management System (FKSS / WBWS)
 *
 * Renders an in-dashboard profile view & editor styled strictly with the official
 * SSMS Brand Design System: Clean background, Deep Maroon accents (#800000), and Warm Gold/Yellow (#f59e0b).
 * Adapts dynamically to light, dark, and toggleable dashboard color schemes.
 *
 * Embedded directly inside any dashboard (<main>) as a first-class tab.
 */

if (!function_exists('renderProfileTabSection')) {
    /**
     * @param string $sectionId The HTML ID for the section container (e.g. 'sec-profile', 'section-profile')
     * @param string $sectionClass The CSS class for dashboard tabs (e.g. 'sec', 'section', 'cs', 'content-section')
     * @param bool $isActive Whether this section should be visible initially
     * @param string $tagName Container HTML tag ('section' or 'div')
     */
    function renderProfileTabSection(string $sectionId = 'sec-profile', string $sectionClass = 'sec', bool $isActive = false, string $tagName = 'section'): void {
        $activeClass = $isActive ? ' act active' : '';
        $apiUrl = function_exists('ssms_app_url') ? ssms_app_url('admin/api_settings.php') : '/admin/api_settings.php';
        $imageUrl = function_exists('ssms_app_url') ? ssms_app_url('admin/profile_image.php') : '/admin/profile_image.php';
        $csrfToken = function_exists('generateCsrfToken') ? generateCsrfToken() : ($_SESSION['csrf_token'] ?? '');
        $safeTag = ($tagName === 'div') ? 'div' : 'section';
        ?>
        <<?= $safeTag ?> id="<?= htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8') ?>" class="<?= htmlspecialchars($sectionClass . $activeClass, ENT_QUOTES, 'UTF-8') ?> wbws-profile-container">
            <style>
                .wbws-profile-container {
                    display: none;
                    width: 100%;
                    box-sizing: border-box;
                    --pt-card-bg: var(--card, var(--surface, #ffffff));
                    --pt-text: var(--bright, var(--text, #1e293b));
                    --pt-muted: var(--dim, var(--text-dim, var(--dm, #64748b)));
                    --pt-border: var(--border, var(--line, #e2e8f0));
                    --pt-input-bg: var(--inp-bg, var(--surface, #ffffff));
                    --pt-input-border: var(--inp-border, var(--border, #cbd5e1));
                }
                .wbws-profile-container.active, .wbws-profile-container.act, .wbws-profile-container.show {
                    display: block !important;
                }
                .wbws-profile-card {
                    background: var(--pt-card-bg);
                    color: var(--pt-text);
                    border: 1px solid var(--pt-border);
                    border-radius: 16px;
                    padding: 1.5rem;
                    box-shadow: 0 1px 3px rgba(0,0,0,.05);
                }
                .wbws-profile-inp {
                    background: var(--pt-input-bg) !important;
                    color: var(--pt-text) !important;
                    border: 1px solid var(--pt-input-border) !important;
                    border-radius: 10px;
                    padding: .6rem .85rem;
                    font-size: .85rem;
                    width: 100%;
                    box-sizing: border-box;
                    outline: none;
                    transition: border-color .15s ease, box-shadow .15s ease;
                }
                .wbws-profile-inp:focus {
                    border-color: #d97706 !important;
                    box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.15);
                }
                .wbws-profile-lbl {
                    display: block;
                    font-size: .75rem;
                    font-weight: 600;
                    color: var(--pt-muted);
                    margin-bottom: .3rem;
                }
            </style>

            <!-- Header -->
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
                <div>
                    <span style="font-size:.7rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#d97706;display:block;margin-bottom:.2rem">Self-Service Account</span>
                    <h2 style="font-size:1.35rem;font-weight:700;color:var(--pt-text);margin:0;display:flex;align-items:center;gap:.5rem">
                        <i class="fa-solid fa-user-gear" style="color:#800000"></i> My Profile
                    </h2>
                    <p style="font-size:.78rem;color:var(--pt-muted);margin-top:.2rem">Manage your personal information, live profile photo, and security credentials.</p>
                </div>
                <div id="tabProfileHeaderBadge" style="display:flex;align-items:center;gap:.6rem;background:var(--pt-card-bg);padding:.4rem .85rem;border-radius:12px;border:1px solid var(--pt-border);box-shadow:0 1px 3px rgba(0,0,0,.04)">
                    <span id="tabProfileHeaderDot" style="width:8px;height:8px;border-radius:50%;background:#10b981"></span>
                    <span id="tabProfileHeaderName" style="font-size:.82rem;font-weight:600;color:var(--pt-text)">Loading…</span>
                </div>
            </div>

            <!-- Global Alert -->
            <div id="tabProfileGlobalAlert" style="display:none;padding:.75rem 1rem;border-radius:12px;font-size:.82rem;margin-bottom:1rem;line-height:1.4"></div>

            <!-- 2-Column Grid -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem;align-items:start">
                
                <!-- Left Column: Identity & Avatar Card -->
                <div class="wbws-profile-card" style="text-align:center">
                    <div style="position:relative;width:7.5rem;height:7.5rem;margin:0 auto 1rem">
                        <div id="tabProfileAvatarWrap" style="width:100%;height:100%;border-radius:50%;overflow:hidden;border:3px solid #fde68a;background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 4px 14px rgba(245,158,11,.25);display:flex;align-items:center;justify-content:center">
                            <img id="tabProfileAvatarImg" src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;display:none" onerror="this.style.display='none';document.getElementById('tabProfileAvatarInitials').style.display='flex';">
                            <span id="tabProfileAvatarInitials" style="font-size:2rem;font-weight:700;color:#fff;display:flex">…</span>
                        </div>
                        <span id="tabProfileStatusDot" style="position:absolute;bottom:4px;right:4px;width:1.1rem;height:1.1rem;border:3px solid var(--pt-card-bg);border-radius:50%;background:#10b981"></span>
                    </div>

                    <h3 id="tabProfileDisplayName" style="font-size:1.15rem;font-weight:700;color:var(--pt-text);margin:0 0 .2rem">Loading…</h3>
                    <p id="tabProfileDisplayUsername" style="font-size:.82rem;color:var(--pt-muted);margin:0 0 .75rem">@…</p>

                    <div style="display:flex;justify-content:center;gap:.4rem;flex-wrap:wrap;margin-bottom:1.25rem">
                        <span id="tabProfileRoleBadge" style="display:inline-block;padding:.25rem .7rem;border-radius:99px;font-size:.68rem;font-weight:700;text-transform:uppercase;background:rgba(217,119,6,0.15);color:#d97706;border:1px solid rgba(217,119,6,0.3)">Role</span>
                        <span id="tabProfileStatusBadge" style="display:inline-block;padding:.25rem .7rem;border-radius:99px;font-size:.68rem;font-weight:700;text-transform:uppercase;background:rgba(16,185,129,0.15);color:#10b981;border:1px solid rgba(16,185,129,0.3)">Active</span>
                    </div>

                    <div style="border-top:1px solid var(--pt-border);border-bottom:1px solid var(--pt-border);padding:.75rem 0;margin-bottom:1.25rem;text-align:left;font-size:.78rem">
                        <div style="display:flex;justify-content:space-between;padding:.35rem 0"><span style="color:var(--pt-muted)">Email</span><span id="tabProfileFactEmail" style="font-weight:600;color:var(--pt-text)">—</span></div>
                        <div style="display:flex;justify-content:space-between;padding:.35rem 0"><span style="color:var(--pt-muted)">Member Since</span><span id="tabProfileFactCreated" style="font-weight:600;color:var(--pt-text)">—</span></div>
                        <div style="display:flex;justify-content:space-between;padding:.35rem 0"><span style="color:var(--pt-muted)">Last Login</span><span id="tabProfileFactLogin" style="font-weight:600;color:var(--pt-text)">—</span></div>
                    </div>

                    <div style="display:flex;justify-content:center;gap:.5rem;flex-wrap:wrap">
                        <button type="button" id="tabProfileChooseBtn" class="btn" style="background:rgba(217,119,6,0.12);color:#d97706;border:1px solid rgba(217,119,6,0.3);font-size:.75rem;padding:.45rem .85rem;border-radius:8px;cursor:pointer;font-weight:600">
                            <i class="fa-solid fa-camera"></i> Change Photo
                        </button>
                        <input type="file" id="tabProfileFileInput" accept="image/jpeg,image/png,image/webp" style="display:none">
                        <button type="button" id="tabProfileRemoveBtn" class="btn" style="background:rgba(239,68,68,0.12);color:#ef4444;border:1px solid rgba(239,68,68,0.3);font-size:.75rem;padding:.45rem .85rem;border-radius:8px;cursor:pointer;font-weight:600" disabled>
                            <i class="fa-solid fa-trash-can"></i> Remove
                        </button>
                    </div>
                    <p style="font-size:.68rem;color:var(--pt-muted);margin-top:.6rem">JPEG, PNG, or WebP up to 4 MB.</p>
                </div>

                <!-- Right Column: Personal Details & Password Management -->
                <div style="display:flex;flex-direction:column;gap:1.25rem">
                    
                    <!-- Edit Profile Card -->
                    <div class="wbws-profile-card">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.15rem;border-bottom:1px solid var(--pt-border);padding-bottom:.75rem">
                            <div>
                                <span style="font-size:.68rem;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.08em;display:block">Personal Details</span>
                                <h3 style="font-size:1.1rem;font-weight:700;color:var(--pt-text);margin:0">Edit Profile</h3>
                            </div>
                            <span id="tabProfileDraftState" style="font-size:.72rem;color:var(--pt-muted)">Synced</span>
                        </div>

                        <form id="tabProfileForm" novalidate>
                            <div style="margin-bottom:1rem">
                                <label class="wbws-profile-lbl" for="tabProfileFullName">Full Name <span style="color:#ef4444">*</span></label>
                                <input type="text" id="tabProfileFullName" name="full_name" class="wbws-profile-inp" maxlength="100" required placeholder="Enter your full name">
                                <p id="tabProfileFullNameErr" style="font-size:.7rem;color:#ef4444;margin-top:.25rem;display:none"></p>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem;margin-bottom:1rem">
                                <div>
                                    <label class="wbws-profile-lbl" for="tabProfileUsername">Username <span style="color:#ef4444">*</span></label>
                                    <input type="text" id="tabProfileUsername" name="username" class="wbws-profile-inp" minlength="3" maxlength="50" required autocapitalize="none" spellcheck="false">
                                    <p id="tabProfileUsernameErr" style="font-size:.7rem;color:#ef4444;margin-top:.25rem;display:none"></p>
                                </div>
                                <div>
                                    <label class="wbws-profile-lbl" for="tabProfileEmail">Email Address</label>
                                    <input type="email" id="tabProfileEmail" name="email" class="wbws-profile-inp" maxlength="100" placeholder="Optional">
                                    <p id="tabProfileEmailErr" style="font-size:.7rem;color:#ef4444;margin-top:.25rem;display:none"></p>
                                </div>
                            </div>

                            <div id="tabProfilePasswordConfirmWrap" style="display:none;margin-bottom:1rem;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.25);padding:.85rem;border-radius:10px">
                                <label class="wbws-profile-lbl" for="tabProfileCurrentPassword" style="color:#ef4444">
                                    <i class="fa-solid fa-lock"></i> Current Password <span style="color:#ef4444">*</span>
                                </label>
                                <p style="font-size:.7rem;color:var(--pt-muted);margin:0 0 .4rem">Changing username or email requires verifying your current password.</p>
                                <input type="password" id="tabProfileCurrentPassword" name="current_password" class="wbws-profile-inp" style="border-color:#f87171!important" autocomplete="current-password">
                                <p id="tabProfileCurrentPasswordErr" style="font-size:.7rem;color:#ef4444;margin-top:.25rem;display:none"></p>
                            </div>

                            <div style="display:flex;justify-content:flex-end;gap:.5rem;align-items:center;margin-top:1.25rem">
                                <button type="button" id="tabProfileDiscardBtn" class="btn" style="background:transparent;color:var(--pt-muted);border:1px solid var(--pt-border);padding:.5rem 1rem;font-size:.8rem;border-radius:8px;cursor:pointer" disabled>Discard</button>
                                <button type="submit" id="tabProfileSaveBtn" class="btn" style="background:linear-gradient(135deg,#d97706,#b45309);color:#fff;border:none;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;border-radius:8px;box-shadow:0 2px 6px rgba(217,119,6,.25);cursor:pointer">
                                    <i class="fa-solid fa-check"></i> Save Profile
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Security & Credentials Card -->
                    <div class="wbws-profile-card">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem">
                            <div>
                                <span style="font-size:.68rem;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.08em;display:block">Security & Credentials</span>
                                <h3 style="font-size:1.1rem;font-weight:700;color:var(--pt-text);margin:0">Account Password</h3>
                            </div>
                            <i class="fa-solid fa-shield-halved" style="color:#d97706;font-size:1.25rem"></i>
                        </div>
                        <p style="font-size:.78rem;color:var(--pt-muted);margin:0 0 1rem;line-height:1.4">Use a strong, unique password with at least 6 characters to keep your account safe.</p>
                        <button type="button" id="tabProfileOpenPasswordBtn" class="btn" style="background:var(--pt-card-bg);color:var(--pt-text);border:1px solid var(--pt-border);padding:.5rem 1rem;font-size:.8rem;font-weight:600;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,.05);cursor:pointer">
                            <i class="fa-solid fa-key" style="color:#d97706"></i> Change Password
                        </button>
                    </div>

                </div>

            </div>

            <!-- Image Crop / Confirm Modal -->
            <div id="tabProfileImageModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(3px)">
                <div class="wbws-profile-card" style="max-width:420px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,.25)">
                    <h3 style="font-size:1.1rem;font-weight:700;color:var(--pt-text);margin:0 0 1rem">Confirm Profile Photo</h3>
                    <div style="width:160px;height:160px;margin:0 auto 1.25rem;border-radius:50%;overflow:hidden;border:4px solid #fde68a;background:rgba(0,0,0,0.1)">
                        <img id="tabProfileImagePreview" src="" alt="Preview" style="width:100%;height:100%;object-fit:cover">
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:.5rem">
                        <button type="button" onclick="document.getElementById('tabProfileImageModal').style.display='none'" class="btn" style="background:transparent;color:var(--pt-muted);border:1px solid var(--pt-border);padding:.5rem 1rem;border-radius:8px;font-size:.8rem;cursor:pointer">Cancel</button>
                        <button type="button" id="tabProfileConfirmUploadBtn" class="btn" style="background:#d97706;color:#fff;border:none;padding:.5rem 1.25rem;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer">Upload & Save</button>
                    </div>
                </div>
            </div>

            <!-- Password Change Modal -->
            <div id="tabProfilePasswordModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(3px)">
                <div class="wbws-profile-card" style="max-width:460px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,.25)">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.15rem;border-bottom:1px solid var(--pt-border);padding-bottom:.75rem">
                        <h3 style="font-size:1.1rem;font-weight:700;color:var(--pt-text);margin:0;display:flex;align-items:center;gap:.5rem">
                            <i class="fa-solid fa-key" style="color:#d97706"></i> Change Password
                        </h3>
                        <button type="button" onclick="document.getElementById('tabProfilePasswordModal').style.display='none'" style="background:none;border:none;color:var(--pt-muted);font-size:1.25rem;cursor:pointer">&times;</button>
                    </div>
                    <form id="tabProfilePasswordForm" novalidate>
                        <div id="tabProfilePasswordAlert" style="display:none;padding:.6rem .85rem;border-radius:8px;font-size:.78rem;margin-bottom:1rem"></div>
                        <div style="margin-bottom:1rem">
                            <label class="wbws-profile-lbl" for="tabPwdCurrent">Current Password <span style="color:#ef4444">*</span></label>
                            <input type="password" id="tabPwdCurrent" class="wbws-profile-inp" required autocomplete="current-password">
                        </div>
                        <div style="margin-bottom:1rem">
                            <label class="wbws-profile-lbl" for="tabPwdNew">New Password <span style="color:#ef4444">*</span></label>
                            <input type="password" id="tabPwdNew" class="wbws-profile-inp" minlength="6" maxlength="72" required autocomplete="new-password">
                            <div style="display:flex;justify-content:space-between;font-size:.68rem;color:var(--pt-muted);margin-top:.25rem">
                                <span id="tabPwdLen">0 characters</span>
                                <span id="tabPwdBytes">0 / 72 bytes</span>
                            </div>
                        </div>
                        <div style="margin-bottom:1.25rem">
                            <label class="wbws-profile-lbl" for="tabPwdConfirm">Confirm New Password <span style="color:#ef4444">*</span></label>
                            <input type="password" id="tabPwdConfirm" class="wbws-profile-inp" minlength="6" maxlength="72" required autocomplete="new-password">
                        </div>
                        <div style="display:flex;justify-content:flex-end;gap:.5rem">
                            <button type="button" onclick="document.getElementById('tabProfilePasswordModal').style.display='none'" class="btn" style="background:transparent;color:var(--pt-muted);border:1px solid var(--pt-border);padding:.5rem 1rem;border-radius:8px;font-size:.8rem;cursor:pointer">Cancel</button>
                            <button type="submit" id="tabPwdSubmitBtn" class="btn" style="background:linear-gradient(135deg,#d97706,#b45309);color:#fff;border:none;padding:.5rem 1.25rem;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer">Change Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </<?= $safeTag ?>>

        <script>
        (function() {
            let _profData = null;
            let _accountContext = null;
            let _csrfToken = <?= json_encode($csrfToken) ?>;
            let _pendingImageFile = null;

            function getCsrf() {
                if (_csrfToken) return _csrfToken;
                const meta = document.querySelector('meta[name="csrf-token"]');
                if (meta && meta.getAttribute('content')) return meta.getAttribute('content');
                if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) return CSRF_TOKEN;
                if (typeof csrfToken !== 'undefined' && csrfToken) return csrfToken;
                if (typeof CSRF !== 'undefined' && CSRF) return CSRF;
                if (window.CSRF_TOKEN) return window.CSRF_TOKEN;
                if (window.APP && window.APP.csrf) return window.APP.csrf;
                return '';
            }

            function setCsrf(token) {
                if (typeof token !== 'string' || !/^[a-f0-9]{64}$/.test(token)) return;
                _csrfToken = token;
                const meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) meta.setAttribute('content', token);
                if (window.APP) window.APP.csrf = token;
                if (typeof CSRF_TOKEN !== 'undefined') window.CSRF_TOKEN = token;
            }

            function showAlert(msg, type) {
                const a = document.getElementById('tabProfileGlobalAlert');
                if (!a) return;
                if (!msg) { a.style.display = 'none'; return; }
                a.style.display = 'block';
                a.textContent = msg;
                if (type === 'success') {
                    a.style.background = 'rgba(16,185,129,0.12)'; a.style.color = '#10b981'; a.style.border = '1px solid rgba(16,185,129,0.3)';
                } else {
                    a.style.background = 'rgba(239,68,68,0.12)'; a.style.color = '#ef4444'; a.style.border = '1px solid rgba(239,68,68,0.3)';
                }
            }

            function toastMsg(msg, type) {
                showAlert(msg, type);
                setTimeout(() => { showAlert('', ''); }, 4000);
            }

            function getInitials(name) {
                if (!name) return 'U';
                const parts = name.trim().split(/\s+/);
                if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
                return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
            }

            function updateGlobalUserIdentity(u) {
                if (!u) return;
                const fullName = u.full_name || u.name || '';
                const username = u.username || '';
                const inits = getInitials(fullName);

                // Update sidebar user cards & header badges
                document.querySelectorAll('.ssms-card-name, .school-user-name, .mob-title h1').forEach(el => {
                    if (el.classList.contains('ssms-card-name') || el.classList.contains('school-user-name')) {
                        el.textContent = fullName;
                    }
                });
                document.querySelectorAll('.ssms-card-avatar-initials, [data-user-initials]').forEach(el => {
                    el.textContent = inits;
                });
                
                // Update header badge
                const hb = document.getElementById('tabProfileHeaderName');
                if (hb) hb.textContent = fullName;

                // Update live sidebar/header images
                if (u.profile_image && u.profile_image.present) {
                    const cacheBust = u.profile_image.version ? '?v=' + encodeURIComponent(u.profile_image.version) : '?t=' + Date.now();
                    const newSrc = '<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>' + cacheBust;
                    document.querySelectorAll('.ssms-card-avatar-img, .mob-avatar img').forEach(img => {
                        img.src = newSrc;
                        img.style.display = 'block';
                        if (img.nextElementSibling) img.nextElementSibling.style.display = 'none';
                    });
                }
            }

            function renderProfile(u) {
                if (!u) return;
                const inits = getInitials(u.full_name);
                
                // Left Column elements
                const dn = document.getElementById('tabProfileDisplayName');
                if (dn) dn.textContent = u.full_name || 'User';
                const du = document.getElementById('tabProfileDisplayUsername');
                if (du) du.textContent = '@' + (u.username || '');
                const rb = document.getElementById('tabProfileRoleBadge');
                if (rb) rb.textContent = (u.role || 'user').replace('_', ' ');
                const sb = document.getElementById('tabProfileStatusBadge');
                if (sb) sb.textContent = u.status || 'Active';
                const fe = document.getElementById('tabProfileFactEmail');
                if (fe) fe.textContent = u.email || '—';
                const fc = document.getElementById('tabProfileFactCreated');
                if (fc) fc.textContent = u.created_at || '—';
                const fl = document.getElementById('tabProfileFactLogin');
                if (fl) fl.textContent = u.last_login_at || '—';

                // Avatar
                const img = document.getElementById('tabProfileAvatarImg');
                const ini = document.getElementById('tabProfileAvatarInitials');
                const remBtn = document.getElementById('tabProfileRemoveBtn');
                if (ini) ini.textContent = inits;

                if (u.profile_image && u.profile_image.present) {
                    const cacheBust = u.profile_image.version ? '?v=' + encodeURIComponent(u.profile_image.version) : '?t=' + Date.now();
                    if (img) {
                        img.src = '<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>' + cacheBust;
                        img.style.display = 'block';
                    }
                    if (ini) ini.style.display = 'none';
                    if (remBtn) remBtn.disabled = false;
                } else {
                    if (img) img.style.display = 'none';
                    if (ini) ini.style.display = 'flex';
                    if (remBtn) remBtn.disabled = true;
                }

                // Form fields
                const ifn = document.getElementById('tabProfileFullName');
                if (ifn) ifn.value = u.full_name || '';
                const iun = document.getElementById('tabProfileUsername');
                if (iun) iun.value = u.username || '';
                const iem = document.getElementById('tabProfileEmail');
                if (iem) iem.value = u.email || '';

                updateGlobalUserIdentity(u);
            }

            async function loadTabProfile() {
                try {
                    const token = getCsrf();
                    const headers = { 'Accept': 'application/json' };
                    if (token) headers['X-CSRF-TOKEN'] = token;
                    if (_accountContext) headers['X-Account-Context'] = _accountContext;

                    const r = await fetch('<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>?action=profile_get', {
                        credentials: 'same-origin',
                        headers: headers
                    });
                    const d = await r.json();
                    if (d.status === 'success' && d.user) {
                        _profData = d.user;
                        if (d.account_context) _accountContext = d.account_context;
                        if (d.csrf_token) setCsrf(d.csrf_token);
                        renderProfile(d.user);
                    }
                } catch (e) {
                    console.error('Error loading profile tab:', e);
                }
            }

            function checkDirty() {
                if (!_profData) return;
                const fn = (document.getElementById('tabProfileFullName')?.value || '').trim();
                const un = (document.getElementById('tabProfileUsername')?.value || '').trim();
                const em = (document.getElementById('tabProfileEmail')?.value || '').trim();

                const isDirty = (fn !== (_profData.full_name || '')) ||
                                (un !== (_profData.username || '')) ||
                                (em !== (_profData.email || ''));

                const identityChanged = (un !== (_profData.username || '')) || (em !== (_profData.email || ''));

                const draftState = document.getElementById('tabProfileDraftState');
                if (draftState) {
                    draftState.textContent = isDirty ? 'Unsaved changes' : 'Synced';
                    draftState.style.color = isDirty ? '#d97706' : 'var(--pt-muted)';
                }

                const discardBtn = document.getElementById('tabProfileDiscardBtn');
                if (discardBtn) discardBtn.disabled = !isDirty;

                const pwdConfirmWrap = document.getElementById('tabProfilePasswordConfirmWrap');
                if (pwdConfirmWrap) {
                    pwdConfirmWrap.style.display = identityChanged ? 'block' : 'none';
                }
            }

            ['tabProfileFullName', 'tabProfileUsername', 'tabProfileEmail'].forEach(id => {
                document.getElementById(id)?.addEventListener('input', checkDirty);
            });

            document.getElementById('tabProfileDiscardBtn')?.addEventListener('click', () => {
                if (_profData) renderProfile(_profData);
                const pwdInput = document.getElementById('tabProfileCurrentPassword');
                if (pwdInput) pwdInput.value = '';
                checkDirty();
            });

            // Submit Profile Form
            document.getElementById('tabProfileForm')?.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!_profData) return;
                showAlert('', '');

                const fn = (document.getElementById('tabProfileFullName')?.value || '').trim();
                const un = (document.getElementById('tabProfileUsername')?.value || '').trim();
                const em = (document.getElementById('tabProfileEmail')?.value || '').trim();
                const cp = (document.getElementById('tabProfileCurrentPassword')?.value || '').trim();

                if (!fn) { showAlert('Full name is required.', 'error'); return; }
                if (!un || un.length < 3) { showAlert('Username must be at least 3 characters.', 'error'); return; }

                const identityChanged = un !== (_profData.username || '') || em !== (_profData.email || '');
                if (identityChanged && !cp) {
                    showAlert('Please enter your current password to confirm username or email changes.', 'error');
                    return;
                }

                const token = getCsrf();
                const payload = {
                    full_name: fn,
                    username: un,
                    email: em || null,
                    profile_version: _profData.profile_version
                };
                if (identityChanged) payload.current_password = cp;

                const saveBtn = document.getElementById('tabProfileSaveBtn');
                if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…'; }

                const headers = {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token
                };
                if (_accountContext) {
                    headers['X-Account-Context'] = _accountContext;
                }

                try {
                    const r = await fetch('<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>?action=profile_update', {
                        method: 'POST',
                        headers: headers,
                        body: JSON.stringify(payload),
                        credentials: 'same-origin'
                    });
                    const d = await r.json();
                    if (d.status === 'success' && d.user) {
                        _profData = d.user;
                        if (d.account_context) _accountContext = d.account_context;
                        if (d.csrf_token) setCsrf(d.csrf_token);
                        renderProfile(d.user);
                        const pwdInput = document.getElementById('tabProfileCurrentPassword');
                        if (pwdInput) pwdInput.value = '';
                        toastMsg('Profile updated successfully!', 'success');
                    } else if (d.code === 'ACCOUNT_CONTEXT_CHANGED' || d.code === 'PROFILE_CONFLICT') {
                        // Resync fresh context and retry once
                        await loadTabProfile();
                        showAlert(d.message || 'Account context synchronized. Please try saving again.', 'error');
                    } else {
                        showAlert(d.message || 'Could not update profile.', 'error');
                    }
                } catch (err) {
                    console.error('Save error:', err);
                    showAlert('Network error while saving profile.', 'error');
                } finally {
                    if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> Save Profile'; }
                    checkDirty();
                }
            });

            // Image pick & upload
            const fileInp = document.getElementById('tabProfileFileInput');
            document.getElementById('tabProfileChooseBtn')?.addEventListener('click', () => {
                if (fileInp) fileInp.click();
            });

            fileInp?.addEventListener('change', (e) => {
                const file = e.target.files?.[0];
                if (!file) return;
                if (file.size > 4 * 1024 * 1024) {
                    showAlert('Image is too large. Maximum size is 4 MB.', 'error');
                    return;
                }
                _pendingImageFile = file;
                const reader = new FileReader();
                reader.onload = (re) => {
                    const prev = document.getElementById('tabProfileImagePreview');
                    if (prev) prev.src = re.target.result;
                    const modal = document.getElementById('tabProfileImageModal');
                    if (modal) modal.style.display = 'flex';
                };
                reader.readAsDataURL(file);
            });

            document.getElementById('tabProfileConfirmUploadBtn')?.addEventListener('click', async () => {
                if (!_pendingImageFile || !_profData) return;
                const modal = document.getElementById('tabProfileImageModal');
                const token = getCsrf();
                const fd = new FormData();
                fd.append('image', _pendingImageFile);
                fd.append('profile_version', _profData.profile_version);
                fd.append('csrf_token', token);

                const headers = {
                    'X-CSRF-TOKEN': token
                };
                if (_accountContext) {
                    headers['X-Account-Context'] = _accountContext;
                }

                try {
                    const r = await fetch('<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>?action=profile_image_upload', {
                        method: 'POST',
                        headers: headers,
                        body: fd,
                        credentials: 'same-origin'
                    });
                    const d = await r.json();
                    if (d.status === 'success') {
                        if (d.account_context) _accountContext = d.account_context;
                        if (d.csrf_token) setCsrf(d.csrf_token);
                        if (modal) modal.style.display = 'none';
                        toastMsg('Profile image updated!', 'success');
                        loadTabProfile();
                    } else {
                        alert(d.message || 'Image upload failed.');
                    }
                } catch (e) {
                    alert('Error uploading image.');
                }
            });

            // Danger confirmation (LOW). Loads admin/js/danger_confirm.js on demand.
            const _dcAskLazy = function (o) { return new Promise(function (res) { function go() { window.DangerConfirm.ask(o).then(res); } if (window.DangerConfirm && window.DangerConfirm.ask) return go(); var sc = document.createElement('script'); sc.src = '/admin/js/danger_confirm.js?v=20261022dc4'; sc.onload = function () { window.DangerConfirm ? go() : res(false); }; sc.onerror = function () { res(false); }; document.head.appendChild(sc); }); };
            document.getElementById('tabProfileRemoveBtn')?.addEventListener('click', async () => {
                if (!_profData) return;
                if (!(await _dcAskLazy({ tier: 'low', title: 'Remove your profile photo?', summary: 'Your photo is deleted. Your initials are shown instead until you upload a new one.', confirmLabel: 'Remove photo' }))) return;
                const token = getCsrf();
                const headers = {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token
                };
                if (_accountContext) {
                    headers['X-Account-Context'] = _accountContext;
                }

                try {
                    const r = await fetch('<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>?action=profile_image_remove', {
                        method: 'POST',
                        headers: headers,
                        body: JSON.stringify({
                            profile_version: _profData.profile_version
                        }),
                        credentials: 'same-origin'
                    });
                    const d = await r.json();
                    if (d.status === 'success') {
                        if (d.account_context) _accountContext = d.account_context;
                        if (d.csrf_token) setCsrf(d.csrf_token);
                        toastMsg('Profile image removed.', 'success');
                        loadTabProfile();
                    } else {
                        showAlert(d.message || 'Could not remove image.', 'error');
                    }
                } catch (e) {
                    showAlert('Network error removing image.', 'error');
                }
            });

            // Password Modal
            document.getElementById('tabProfileOpenPasswordBtn')?.addEventListener('click', () => {
                const modal = document.getElementById('tabProfilePasswordModal');
                if (modal) {
                    modal.style.display = 'flex';
                    document.getElementById('tabProfilePasswordForm')?.reset();
                    document.getElementById('tabProfilePasswordAlert').style.display = 'none';
                    document.getElementById('tabPwdLen').textContent = '0 characters';
                    document.getElementById('tabPwdBytes').textContent = '0 / 72 bytes';
                }
            });

            document.getElementById('tabPwdNew')?.addEventListener('input', function() {
                const val = this.value || '';
                const len = Array.from(val).length;
                const bytes = new TextEncoder().encode(val).length;
                document.getElementById('tabPwdLen').textContent = len + ' characters';
                document.getElementById('tabPwdBytes').textContent = bytes + ' / 72 bytes';
            });

            document.getElementById('tabProfilePasswordForm')?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const cur = (document.getElementById('tabPwdCurrent')?.value || '').trim();
                const np = (document.getElementById('tabPwdNew')?.value || '');
                const conf = (document.getElementById('tabPwdConfirm')?.value || '');
                const alertEl = document.getElementById('tabProfilePasswordAlert');

                if (!cur || !np || !conf) {
                    alertEl.textContent = 'All fields are required.';
                    alertEl.style.display = 'block';
                    alertEl.style.background = 'rgba(239,68,68,0.12)';
                    alertEl.style.color = '#ef4444';
                    return;
                }
                if (np !== conf) {
                    alertEl.textContent = 'New passwords do not match.';
                    alertEl.style.display = 'block';
                    alertEl.style.background = 'rgba(239,68,68,0.12)';
                    alertEl.style.color = '#ef4444';
                    return;
                }
                if (np.length < 6) {
                    alertEl.textContent = 'Password must be at least 6 characters.';
                    alertEl.style.display = 'block';
                    alertEl.style.background = 'rgba(239,68,68,0.12)';
                    alertEl.style.color = '#ef4444';
                    return;
                }

                const btn = document.getElementById('tabPwdSubmitBtn');
                if (btn) { btn.disabled = true; btn.textContent = 'Changing…'; }

                const token = getCsrf();
                const headers = {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token
                };
                if (_accountContext) {
                    headers['X-Account-Context'] = _accountContext;
                }

                try {
                    const r = await fetch('<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>?action=password_change', {
                        method: 'POST',
                        headers: headers,
                        body: JSON.stringify({
                            current_password: cur,
                            new_password: np,
                            confirm_password: conf
                        }),
                        credentials: 'same-origin'
                    });
                    const d = await r.json();
                    if (d.status === 'success') {
                        if (d.account_context) _accountContext = d.account_context;
                        if (d.csrf_token) setCsrf(d.csrf_token);
                        document.getElementById('tabProfilePasswordModal').style.display = 'none';
                        toastMsg('Password changed successfully!', 'success');
                    } else {
                        alertEl.textContent = d.message || 'Could not change password.';
                        alertEl.style.display = 'block';
                        alertEl.style.background = 'rgba(239,68,68,0.12)';
                        alertEl.style.color = '#ef4444';
                    }
                } catch (err) {
                    alertEl.textContent = 'Network error changing password.';
                    alertEl.style.display = 'block';
                    alertEl.style.background = 'rgba(239,68,68,0.12)';
                    alertEl.style.color = '#ef4444';
                } finally {
                    if (btn) { btn.disabled = false; btn.textContent = 'Change Password'; }
                }
            });

            // Initial load
            loadTabProfile();
            window.loadTabProfile = loadTabProfile;
        })();
        </script>
        <?php
    }
}
