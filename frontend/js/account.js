(function () {
    'use strict';

    var API_URL = '/admin/api_settings.php?action=';
    var MAX_IMAGE_BYTES = 4 * 1024 * 1024;
    var MIN_IMAGE_DIMENSION = 64;
    var MAX_IMAGE_DIMENSION = 4096;
    var MAX_IMAGE_PIXELS = 12000000;
    var ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    var RESERVED_USERNAMES = ['admin', 'administrator', 'root', 'system', 'support', 'api', 'null'];
    var COMMON_PASSWORDS = ['123456789012', 'adminadmin12', 'changeme1234', 'letmeinletmein', 'password1234', 'qwertyuiop12'];

    var state = {
        canonical: null,
        accountContext: null,
        contextEpoch: 0,
        contextRefreshPending: false,
        sessionUnavailable: false,
        draftGeneration: 0,
        passwordDraftGeneration: 0,
        loginCount: 0,
        mutationBusy: false,
        profileSaving: false,
        loadSequence: 0,
        imageSelectionGeneration: 0,
        imageRenderGeneration: 0,
        pendingImage: null,
        pendingPreviewUrl: null,
        visiblePreviewUrl: null,
        returnFocus: null,
        authChannel: null
    };

    var el = {};

    function byId(name) {
        return document.getElementById(name);
    }

    function cacheElements() {
        [
            'accountApp', 'accountOffline', 'accountGlobalAlert', 'accountAnnouncer',
            'accountHeaderInitials', 'accountHeaderName', 'profileImage', 'profileInitials',
            'summaryName', 'summaryUsername', 'summaryRole', 'summaryStatus', 'summaryEmail',
            'summaryCreated', 'summaryLastLogin', 'accountStatusDot', 'profileImageInput',
            'chooseImageButton', 'chooseImageText', 'removeImageButton', 'imageStatus',
            'profileForm', 'profileFieldset', 'profileFullName', 'profileUsername', 'profileEmail',
            'profileCurrentPassword', 'profilePasswordRequired', 'identityPasswordGroup',
            'profileFormAlert', 'profileDraftState', 'saveProfileButton', 'resetProfileButton',
            'openPasswordButton', 'imageConfirmDialog', 'imageUploadForm', 'imagePreview',
            'imageDialogError', 'confirmImageButton', 'removeImageDialog', 'removeImageError',
            'confirmRemoveImageButton', 'passwordDialog', 'passwordForm', 'passwordCurrent',
            'passwordNew', 'passwordConfirm', 'passwordCurrentError', 'passwordNewError',
            'passwordConfirmError', 'passwordFormAlert', 'passwordCharacterCount',
            'passwordByteCount', 'savePasswordButton'
        ].forEach(function (name) { el[name] = byId(name); });
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function ApiError(status, payload, retryAfter, handled) {
        this.name = 'ApiError';
        this.status = status || 0;
        this.payload = payload && typeof payload === 'object' ? payload : {};
        this.code = typeof this.payload.code === 'string' ? this.payload.code : '';
        this.reason = typeof this.payload.reason === 'string' ? this.payload.reason : this.code;
        this.retryAfter = retryAfter || '';
        this.handled = !!handled;
    }
    ApiError.prototype = Object.create(Error.prototype);

    function isQuietError(error) {
        return !!(error && (error.handled || error.code === 'REQUEST_SUPERSEDED'));
    }

    function updateCsrfToken(token) {
        if (typeof token !== 'string' || !/^[a-f0-9]{64}$/.test(token)) return;
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', token);
        if (window.APP) window.APP.csrf = token;
    }

    function clearCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', '');
        if (window.APP) window.APP.csrf = '';
    }

    function acceptAccountContext(marker, payload) {
        if (typeof marker !== 'string' || !/^[a-f0-9]{64}$/.test(marker)) {
            throw new ApiError(502, { code: 'ACCOUNT_CONTEXT_INVALID' }, '');
        }
        if (state.accountContext === null) {
            state.accountContext = marker;
            state.sessionUnavailable = false;
            updateCsrfToken(payload && payload.csrf_token);
            broadcastAccountContext(marker);
            return true;
        }
        if (!hashLikeEquals(state.accountContext, marker)) {
            handleAccountContextMismatch();
            return false;
        }
        updateCsrfToken(payload && payload.csrf_token);
        return true;
    }

    function hashLikeEquals(left, right) {
        if (left.length !== right.length) return false;
        var difference = 0;
        for (var index = 0; index < left.length; index += 1) {
            difference |= left.charCodeAt(index) ^ right.charCodeAt(index);
        }
        return difference === 0;
    }

    async function request(action, options) {
        var settings = options || {};
        var requestEpoch = state.contextEpoch;
        var headers = { 'Accept': 'application/json' };
        var init = {
            method: settings.method || 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: headers
        };
        if (state.accountContext) {
            // Context precondition only; the server still derives ownership
            // exclusively from the authenticated session.
            headers['X-Account-Context'] = state.accountContext;
        }
        if (init.method === 'POST') {
            headers['X-CSRF-TOKEN'] = csrfToken();
            if (settings.formData) {
                init.body = settings.formData;
            } else {
                headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(settings.body || {});
            }
        }

        var response;
        try {
            response = await fetch(API_URL + encodeURIComponent(action), init);
        } catch (networkError) {
            if (requestEpoch !== state.contextEpoch) {
                throw new ApiError(0, { code: 'REQUEST_SUPERSEDED' }, '', true);
            }
            throw new ApiError(0, { code: 'NETWORK_ERROR' }, '');
        }

        var payload = {};
        try {
            payload = await response.json();
        } catch (parseError) {
            payload = {};
        }
        if (requestEpoch !== state.contextEpoch) {
            throw new ApiError(0, { code: 'REQUEST_SUPERSEDED' }, '', true);
        }
        if (!response.ok || payload.status !== 'success') {
            var failure = new ApiError(response.status, payload, response.headers.get('Retry-After') || '');
            if (response.status === 401) {
                handleUnauthorized(failure, true);
                failure.handled = true;
            } else if (failure.code === 'ACCOUNT_CONTEXT_CHANGED') {
                handleAccountContextMismatch();
                failure.handled = true;
            }
            throw failure;
        }
        if (!acceptAccountContext(payload.account_context, payload)) {
            throw new ApiError(409, { code: 'ACCOUNT_CONTEXT_CHANGED' }, '', true);
        }
        return payload;
    }

    function announce(message) {
        el.accountAnnouncer.textContent = '';
        window.setTimeout(function () { el.accountAnnouncer.textContent = message; }, 20);
    }

    function showGlobal(kind, message, focus) {
        el.accountGlobalAlert.dataset.kind = kind || 'error';
        el.accountGlobalAlert.textContent = message;
        el.accountGlobalAlert.hidden = false;
        if (focus) {
            el.accountGlobalAlert.focus();
        }
        announce(message);
    }

    function clearGlobal() {
        el.accountGlobalAlert.hidden = true;
        el.accountGlobalAlert.textContent = '';
        delete el.accountGlobalAlert.dataset.kind;
    }

    function setFormMessage(node, message, kind) {
        node.textContent = message || '';
        node.hidden = !message;
        if (message) node.dataset.kind = kind || 'error';
        else delete node.dataset.kind;
    }

    function friendlyError(error, context) {
        if (!(error instanceof ApiError)) {
            return 'Something went wrong. Please try again.';
        }
        if (error.status === 0) {
            return 'Network error. Check your connection and try again; your draft is still here.';
        }
        if (error.status === 400) {
            return 'The request could not be processed. Review the form and try again.';
        }
        if (error.status === 401) {
            return 'Your session expired. Account drafts and secrets were cleared; sign in again.';
        }
        if (error.status === 403) {
            return 'Your security token expired or this action is not allowed. Reload the page before trying again.';
        }
        var reason = error.reason || error.code;
        if (reason === 'PROFILE_CONFLICT') {
            return 'Your profile changed elsewhere. The latest account details were loaded and your draft was preserved.';
        }
        if (reason === 'USERNAME_TAKEN') {
            return 'That username is already in use.';
        }
        if (reason === 'EMAIL_TAKEN') {
            return 'That email address is already in use.';
        }
        if (error.status === 409) {
            return 'The account changed before this request completed. Reload the page and try again.';
        }
        if (error.status === 413) {
            return 'The selected image is too large. Choose an image no larger than 4 MB.';
        }
        if (error.status === 415) {
            return 'That image type is not supported. Choose a JPEG, PNG, or WebP image.';
        }
        if (error.status === 429) {
            return error.retryAfter
                ? 'Too many attempts. Try again after ' + error.retryAfter + ' seconds.'
                : 'Too many attempts. Wait a while before trying again.';
        }
        if (error.status >= 500) {
            return context === 'image'
                ? 'Profile image storage is temporarily unavailable. Your current image was not changed.'
                : 'The service is temporarily unavailable. Your draft was preserved; please try again.';
        }
        if (error.status === 422) {
            return 'Some information was rejected. Review the highlighted fields and try again.';
        }
        return 'The request could not be completed. Please try again.';
    }

    function displayRole(role) {
        return String(role || 'account').split('_').map(function (part) {
            return part ? part.charAt(0).toUpperCase() + part.slice(1) : '';
        }).join(' ');
    }

    function initials(name) {
        var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return '?';
        var value = parts[0].charAt(0);
        if (parts.length > 1) value += parts[parts.length - 1].charAt(0);
        return value.toLocaleUpperCase();
    }

    function formatDate(value, emptyText) {
        if (!value) return emptyText || '—';
        var normalized = String(value).replace(' ', 'T');
        if (typeof WBWSCalendar !== 'undefined' && typeof WBWSCalendar.formatDate === 'function') {
            return WBWSCalendar.formatDate(normalized, 'long');
        }
        var date = new Date(normalized);
        if (Number.isNaN(date.getTime())) return '—';
        return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' }).format(date);
    }

    function safeImageUrl(profileImage) {
        if (!profileImage || !profileImage.present || typeof profileImage.url !== 'string') return null;
        try {
            var url = new URL(profileImage.url, window.location.origin);
            var expected = new URL(el.accountApp.dataset.profileImageUrl || '/admin/profile_image.php', window.location.origin);
            if (url.origin !== window.location.origin || url.pathname !== expected.pathname) return null;
            if (profileImage.version) url.searchParams.set('v', String(profileImage.version));
            return url.href;
        } catch (badUrl) {
            return null;
        }
    }

    function showInitials() {
        el.profileImage.hidden = true;
        el.profileInitials.hidden = false;
    }

    function revokeVisiblePreview() {
        if (state.visiblePreviewUrl) {
            URL.revokeObjectURL(state.visiblePreviewUrl);
            state.visiblePreviewUrl = null;
        }
    }

    function renderImage(profileImage, force) {
        var renderGeneration = ++state.imageRenderGeneration;
        var url = safeImageUrl(profileImage);
        if (!url) {
            if (!profileImage || !profileImage.present) {
                revokeVisiblePreview();
                el.profileImage.removeAttribute('src');
                delete el.profileImage.dataset.version;
                showInitials();
            }
            return;
        }
        var version = String(profileImage.version || 'present');
        if (!force && el.profileImage.dataset.version === version && !el.profileImage.hidden) return;

        var loader = new Image();
        loader.onload = function () {
            if (renderGeneration !== state.imageRenderGeneration) return;
            el.profileImage.src = url;
            el.profileImage.dataset.version = version;
            el.profileImage.hidden = false;
            el.profileInitials.hidden = true;
            revokeVisiblePreview();
            el.imageStatus.textContent = '';
        };
        loader.onerror = function () {
            if (renderGeneration !== state.imageRenderGeneration) return;
            if (el.profileImage.hidden) showInitials();
            el.imageStatus.textContent = 'The private image could not be displayed right now.';
        };
        loader.src = url;
    }

    function showConfirmedPreview(previewUrl, version) {
        if (!previewUrl) return;
        revokeVisiblePreview();
        state.visiblePreviewUrl = previewUrl;
        el.profileImage.src = previewUrl;
        el.profileImage.dataset.version = String(version || 'confirmed-preview');
        el.profileImage.hidden = false;
        el.profileInitials.hidden = true;
    }

    function renderCanonical(options) {
        if (!state.canonical) return;
        var profile = state.canonical;
        var name = profile.full_name || profile.username || 'Account';
        var letters = initials(name);
        el.summaryName.textContent = name;
        el.summaryUsername.textContent = '@' + (profile.username || '—');
        el.summaryRole.textContent = displayRole(profile.role);
        el.summaryStatus.textContent = profile.is_active ? 'Active' : 'Inactive';
        el.summaryStatus.classList.toggle('is-active', !!profile.is_active);
        el.summaryStatus.classList.toggle('is-inactive', !profile.is_active);
        el.accountStatusDot.classList.toggle('is-active', !!profile.is_active);
        el.summaryEmail.textContent = profile.email || 'Not provided';
        el.summaryCreated.textContent = formatDate(profile.created_at, '—');
        el.summaryLastLogin.textContent = formatDate(profile.last_login, 'Never');
        el.profileInitials.textContent = letters;
        el.accountHeaderInitials.textContent = letters;
        el.accountHeaderName.textContent = name;
        el.chooseImageText.textContent = profile.profile_image && profile.profile_image.present ? 'Replace image' : 'Choose image';

        if (options && options.previewUrl) {
            showConfirmedPreview(options.previewUrl, profile.profile_image && profile.profile_image.version);
        }
        renderImage(profile.profile_image, !!(options && options.forceImage));
        reconcileVisibleIdentity(profile);
        updateAvailability();
    }

    function reconcileVisibleIdentity(profile) {
        if (window.APP && window.APP.user) {
            window.APP.user.name = profile.full_name || profile.username || '';
            window.APP.user.username = profile.username || '';
            window.APP.user.initials = initials(profile.full_name || profile.username || '');
        }
        document.querySelectorAll('[data-user-name]').forEach(function (node) {
            node.textContent = profile.full_name || profile.username || '';
        });
        document.querySelectorAll('[data-user-initials]').forEach(function (node) {
            node.textContent = initials(profile.full_name || profile.username || '');
        });
    }

    function populateDraft() {
        if (!state.canonical) return;
        el.profileFullName.value = state.canonical.full_name || '';
        el.profileUsername.value = state.canonical.username || '';
        el.profileEmail.value = state.canonical.email || '';
        el.profileCurrentPassword.value = '';
        clearProfileErrors();
        updateDraftState();
    }

    function normalizeUsername(value) {
        return String(value || '').trim().toLowerCase();
    }

    function normalizeEmail(value) {
        return String(value || '').trim().toLowerCase();
    }

    function detailDraftIsDirty() {
        if (!state.canonical) return false;
        return el.profileFullName.value !== (state.canonical.full_name || '')
            || el.profileUsername.value !== (state.canonical.username || '')
            || el.profileEmail.value !== (state.canonical.email || '');
    }

    function hasSensitiveDraft() {
        return !!(el.profileCurrentPassword.value || el.passwordCurrent.value || el.passwordNew.value || el.passwordConfirm.value);
    }

    function identityDraftChanged() {
        if (!state.canonical) return false;
        return normalizeUsername(el.profileUsername.value) !== (state.canonical.username || '')
            || normalizeEmail(el.profileEmail.value) !== (state.canonical.email || '');
    }

    function hasUnsavedWork() {
        return detailDraftIsDirty() || hasSensitiveDraft() || !!state.pendingImage;
    }

    function markDraftChanged() {
        state.draftGeneration += 1;
        updateDraftState();
    }

    function updateDraftState() {
        var dirty = detailDraftIsDirty();
        var identityChanged = identityDraftChanged();
        el.profileDraftState.textContent = dirty ? 'Unsaved draft' : 'Up to date';
        el.profileDraftState.classList.toggle('is-dirty', dirty);
        el.identityPasswordGroup.classList.toggle('is-required', identityChanged);
        el.profilePasswordRequired.hidden = !identityChanged;
        el.profileCurrentPassword.required = identityChanged;
        updateAvailability();
    }

    function setMutationBusy(busy) {
        state.mutationBusy = busy;
        updateAvailability();
    }

    function updateAvailability() {
        if (!state.canonical) {
            el.profileFieldset.disabled = true;
        } else {
            el.profileFieldset.disabled = !!state.profileSaving;
        }
        var unavailable = !state.canonical || state.mutationBusy || !navigator.onLine;
        document.querySelectorAll('[data-mutation]').forEach(function (button) {
            button.disabled = unavailable;
        });
        el.saveProfileButton.disabled = unavailable || !detailDraftIsDirty();
        el.resetProfileButton.disabled = !state.canonical || state.profileSaving || !detailDraftIsDirty();
        el.removeImageButton.disabled = unavailable || !(state.canonical && state.canonical.profile_image && state.canonical.profile_image.present);
        el.profileImageInput.disabled = unavailable;
        el.accountOffline.hidden = navigator.onLine;
    }

    function fieldError(input, errorNode, message) {
        input.setAttribute('aria-invalid', message ? 'true' : 'false');
        errorNode.textContent = message || '';
    }

    function clearProfileErrors() {
        fieldError(el.profileFullName, byId('profileFullNameError'), '');
        fieldError(el.profileUsername, byId('profileUsernameError'), '');
        fieldError(el.profileEmail, byId('profileEmailError'), '');
        fieldError(el.profileCurrentPassword, byId('profileCurrentPasswordError'), '');
        setFormMessage(el.profileFormAlert, '');
    }

    function validateProfileDraft() {
        clearProfileErrors();
        var valid = true;
        var name = el.profileFullName.value.trim();
        var username = normalizeUsername(el.profileUsername.value);
        var email = normalizeEmail(el.profileEmail.value);
        el.profileFullName.value = name;
        el.profileUsername.value = username;
        el.profileEmail.value = email;

        if (!name || Array.from(name).length > 100 || /[\u0000-\u001F\u007F]/u.test(name)) {
            fieldError(el.profileFullName, byId('profileFullNameError'), 'Enter a full name of 1–100 characters without control characters.');
            valid = false;
        }
        if (username.length < 3 || username.length > 50
            || !/^[a-z0-9][a-z0-9_.]*[a-z0-9]$/.test(username)
            || /[._]{2}/.test(username)) {
            fieldError(el.profileUsername, byId('profileUsernameError'), 'Use 3–50 lowercase letters, numbers, dots, or underscores; begin and end with a letter or number and do not repeat punctuation.');
            valid = false;
        } else if (RESERVED_USERNAMES.indexOf(username) !== -1) {
            fieldError(el.profileUsername, byId('profileUsernameError'), 'That username is reserved.');
            valid = false;
        }
        if (email && (!el.profileEmail.validity.valid || new TextEncoder().encode(email).length > 100)) {
            fieldError(el.profileEmail, byId('profileEmailError'), 'Enter a valid email address no longer than 100 bytes.');
            valid = false;
        }
        if (identityDraftChanged() && !el.profileCurrentPassword.value) {
            fieldError(el.profileCurrentPassword, byId('profileCurrentPasswordError'), 'Enter your current password to change your username or email.');
            valid = false;
        }
        if (!valid) {
            var firstInvalid = el.profileForm.querySelector('[aria-invalid="true"]');
            if (firstInvalid) firstInvalid.focus();
        }
        return valid;
    }

    function mapProfileError(error) {
        var reason = error.reason || error.code;
        if (reason === 'USERNAME_TAKEN') {
            fieldError(el.profileUsername, byId('profileUsernameError'), 'That username is already in use.');
            el.profileUsername.focus();
            return true;
        }
        if (reason === 'USERNAME_INVALID' || reason === 'USERNAME_RESERVED') {
            fieldError(el.profileUsername, byId('profileUsernameError'), reason === 'USERNAME_RESERVED' ? 'That username is reserved.' : 'This username does not meet the required format.');
            el.profileUsername.focus();
            return true;
        }
        if (reason === 'EMAIL_TAKEN') {
            fieldError(el.profileEmail, byId('profileEmailError'), 'That email address is already in use.');
            el.profileEmail.focus();
            return true;
        }
        if (reason === 'EMAIL_INVALID') {
            fieldError(el.profileEmail, byId('profileEmailError'), 'Enter a valid email address.');
            el.profileEmail.focus();
            return true;
        }
        if (reason === 'FULL_NAME_INVALID') {
            fieldError(el.profileFullName, byId('profileFullNameError'), 'Enter a full name of 1–100 valid characters.');
            el.profileFullName.focus();
            return true;
        }
        if (reason === 'CURRENT_PASSWORD_INCORRECT') {
            fieldError(el.profileCurrentPassword, byId('profileCurrentPasswordError'), 'Current password is incorrect.');
            el.profileCurrentPassword.focus();
            return true;
        }
        return false;
    }

    function broadcastAccountContext(marker) {
        if (!state.authChannel) return;
        try {
            state.authChannel.postMessage({ type: 'account-context', marker: marker });
        } catch (ignored) {}
    }

    function setupAuthChannel() {
        if (typeof window.BroadcastChannel !== 'function') return;
        try {
            state.authChannel = new window.BroadcastChannel('ssms-profile-auth-context');
            state.authChannel.addEventListener('message', function (event) {
                var message = event && event.data && typeof event.data === 'object' ? event.data : {};
                if (message.type === 'account-context'
                    && state.accountContext
                    && typeof message.marker === 'string'
                    && !hashLikeEquals(state.accountContext, message.marker)) {
                    handleAccountContextMismatch();
                } else if (message.type === 'auth-changed') {
                    handleAccountContextMismatch();
                } else if (message.type === 'session-ended'
                    && (!message.marker || message.marker === state.accountContext)) {
                    handleUnauthorized(new ApiError(401, { code: 'AUTHENTICATION_REQUIRED' }, ''), false);
                }
            });
        } catch (ignored) {
            state.authChannel = null;
        }
    }

    function closeAccountDialogs() {
        state.returnFocus = null;
        [el.imageConfirmDialog, el.removeImageDialog, el.passwordDialog].forEach(function (dialog) {
            if (!dialog) return;
            if (dialog.open && typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        });
    }

    function clearAccountSpecificState(statusText, sessionUnavailable) {
        state.contextEpoch += 1;
        state.loadSequence += 1;
        state.draftGeneration += 1;
        state.passwordDraftGeneration += 1;
        state.imageSelectionGeneration += 1;
        state.imageRenderGeneration += 1;
        state.contextRefreshPending = false;
        state.sessionUnavailable = !!sessionUnavailable;
        state.canonical = null;
        state.accountContext = null;
        state.loginCount = 0;
        state.profileSaving = false;
        state.mutationBusy = false;
        clearCsrfToken();

        closeAccountDialogs();
        cleanupPendingImage(false);
        revokeVisiblePreview();
        resetPasswordForm();
        el.profileFullName.value = '';
        el.profileUsername.value = '';
        el.profileEmail.value = '';
        el.profileCurrentPassword.value = '';
        clearProfileErrors();
        el.profileImage.removeAttribute('src');
        delete el.profileImage.dataset.version;
        el.imagePreview.removeAttribute('src');
        el.imageStatus.textContent = '';
        showInitials();

        el.summaryName.textContent = '—';
        el.summaryUsername.textContent = '@—';
        el.summaryRole.textContent = 'Role';
        el.summaryStatus.textContent = 'Status';
        el.summaryEmail.textContent = '—';
        el.summaryCreated.textContent = '—';
        el.summaryLastLogin.textContent = '—';
        el.accountHeaderName.textContent = '—';
        el.accountHeaderInitials.textContent = '?';
        el.profileInitials.textContent = '?';
        el.profileDraftState.textContent = statusText || 'Loading…';
        el.accountApp.setAttribute('aria-busy', 'true');

        if (window.APP && window.APP.user) {
            window.APP.user.id = null;
            window.APP.user.name = '';
            window.APP.user.username = '';
            window.APP.user.initials = '';
        }
        updateDraftState();
        updateAvailability();
    }

    function scheduleFreshAccountLoad() {
        if (state.contextRefreshPending || !navigator.onLine) return;
        state.contextRefreshPending = true;
        window.setTimeout(function () {
            loadProfile({ preserveDraft: false }).finally(function () {
                state.contextRefreshPending = false;
            });
        }, 0);
    }

    function handleAccountContextMismatch() {
        clearAccountSpecificState('Account changed', false);
        showGlobal('warning', 'The signed-in account changed. Previous account drafts and passwords were cleared.', true);
        scheduleFreshAccountLoad();
    }

    function handleUnauthorized(error, broadcast) {
        var previousContext = state.accountContext;
        clearAccountSpecificState('Session ended', true);
        showGlobal('error', friendlyError(error, 'profile'), true);
        if (broadcast && state.authChannel) {
            try {
                state.authChannel.postMessage({ type: 'session-ended', marker: previousContext });
            } catch (ignored) {}
        }
    }

    async function loadProfile(options) {
        var settings = options || {};
        var sequence = ++state.loadSequence;
        var requestDraftGeneration = state.draftGeneration;
        var requestContextEpoch = state.contextEpoch;
        if (!state.canonical) {
            el.accountApp.setAttribute('aria-busy', 'true');
            el.profileDraftState.textContent = 'Loading…';
        }
        try {
            var payload = await request('profile_get');
            if (sequence !== state.loadSequence || requestContextEpoch !== state.contextEpoch) return false;
            var preserveCurrentDraft = !!settings.preserveDraft
                || requestDraftGeneration !== state.draftGeneration
                || hasUnsavedWork();
            state.canonical = payload.user;
            state.loginCount = Number(payload.login_count || 0);
            renderCanonical({ forceImage: !preserveCurrentDraft });
            if (!preserveCurrentDraft) populateDraft();
            el.accountApp.setAttribute('aria-busy', 'false');
            updateAvailability();
            return true;
        } catch (error) {
            if (sequence !== state.loadSequence || isQuietError(error)) return false;
            el.accountApp.setAttribute('aria-busy', 'false');
            showGlobal('error', friendlyError(error, 'profile'), true);
            updateAvailability();
            return false;
        }
    }

    async function reloadConflict(messageNode, context) {
        var refreshed = await loadProfile({ preserveDraft: true });
        if (!refreshed) {
            if (messageNode) {
                setFormMessage(messageNode, 'Your draft was preserved, but the latest profile could not be loaded. Check your connection and try again.', 'error');
            }
            return;
        }
        var message = friendlyError(new ApiError(409, { code: 'PROFILE_CONFLICT' }, ''), context);
        if (messageNode) setFormMessage(messageNode, message, 'warning');
        showGlobal('warning', message, false);
    }

    async function submitProfile(event) {
        event.preventDefault();
        clearGlobal();
        if (!state.canonical || state.mutationBusy || !validateProfileDraft()) return;
        if (!detailDraftIsDirty()) return;

        var body = { profile_version: state.canonical.profile_version };
        if (el.profileFullName.value !== (state.canonical.full_name || '')) {
            body.full_name = el.profileFullName.value;
        }
        if (el.profileUsername.value !== (state.canonical.username || '')) {
            body.username = el.profileUsername.value;
        }
        if (el.profileEmail.value !== (state.canonical.email || '')) {
            body.email = el.profileEmail.value;
        }
        if (identityDraftChanged() && el.profileCurrentPassword.value) {
            body.current_password = el.profileCurrentPassword.value;
        }

        state.profileSaving = true;
        state.loadSequence += 1;
        setMutationBusy(true);
        try {
            var payload = await request('profile_update', { method: 'POST', body: body });
            state.canonical = payload.user;
            state.draftGeneration += 1;
            renderCanonical();
            populateDraft();
            setFormMessage(el.profileFormAlert, 'Profile updated successfully.', 'success');
            announce('Profile updated successfully.');
        } catch (error) {
            var reason = error.reason || error.code;
            if (reason === 'PROFILE_CONFLICT') {
                await reloadConflict(el.profileFormAlert, 'profile');
            } else if (!isQuietError(error) && !mapProfileError(error)) {
                setFormMessage(el.profileFormAlert, friendlyError(error, 'profile'), 'error');
            }
        } finally {
            state.profileSaving = false;
            setMutationBusy(false);
        }
    }

    function resetProfileDraft() {
        populateDraft();
        announce('Profile draft discarded.');
    }

    function cleanupPendingImage(markGeneration) {
        var hadPendingImage = !!(state.pendingImage || state.pendingPreviewUrl);
        state.imageSelectionGeneration += 1;
        state.pendingImage = null;
        if (state.pendingPreviewUrl) {
            URL.revokeObjectURL(state.pendingPreviewUrl);
            state.pendingPreviewUrl = null;
        }
        if (hadPendingImage && markGeneration !== false) {
            state.draftGeneration += 1;
        }
        el.profileImageInput.value = '';
        el.imagePreview.removeAttribute('src');
        setFormMessage(el.imageDialogError, '');
    }

    function openDialog(dialog, returnFocus) {
        state.returnFocus = returnFocus || document.activeElement;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
    }

    function closeDialog(dialog) {
        if (dialog.open && typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
        if (state.returnFocus && typeof state.returnFocus.focus === 'function') state.returnFocus.focus();
        state.returnFocus = null;
    }

    function inspectSelectedImage(file) {
        return new Promise(function (resolve, reject) {
            if (!file || ALLOWED_IMAGE_TYPES.indexOf(file.type) === -1) {
                reject('Choose a JPEG, PNG, or WebP image.');
                return;
            }
            if (file.size <= 0 || file.size > MAX_IMAGE_BYTES) {
                reject('Choose an image no larger than 4 MB.');
                return;
            }
            var previewUrl = URL.createObjectURL(file);
            var image = new Image();
            image.onload = function () {
                var width = image.naturalWidth;
                var height = image.naturalHeight;
                if (!width || !height || width < MIN_IMAGE_DIMENSION || height < MIN_IMAGE_DIMENSION) {
                    URL.revokeObjectURL(previewUrl);
                    reject('Image dimensions must be at least 64 × 64 pixels.');
                    return;
                }
                if (width > MAX_IMAGE_DIMENSION || height > MAX_IMAGE_DIMENSION || width * height > MAX_IMAGE_PIXELS) {
                    URL.revokeObjectURL(previewUrl);
                    reject('Image dimensions must be at most 4096 × 4096 pixels and 12 megapixels.');
                    return;
                }
                resolve(previewUrl);
            };
            image.onerror = function () {
                URL.revokeObjectURL(previewUrl);
                reject('The selected file could not be read as an image.');
            };
            image.src = previewUrl;
        });
    }

    async function selectImage(event) {
        var file = event.target.files && event.target.files[0];
        if (!file) return;
        clearGlobal();
        cleanupPendingImage();
        var selectionGeneration = ++state.imageSelectionGeneration;
        state.draftGeneration += 1;
        try {
            var previewUrl = await inspectSelectedImage(file);
            if (selectionGeneration !== state.imageSelectionGeneration) {
                URL.revokeObjectURL(previewUrl);
                return;
            }
            state.pendingImage = file;
            state.pendingPreviewUrl = previewUrl;
            el.imagePreview.src = previewUrl;
            openDialog(el.imageConfirmDialog, el.chooseImageButton);
            el.confirmImageButton.focus();
        } catch (message) {
            if (selectionGeneration !== state.imageSelectionGeneration) return;
            el.profileImageInput.value = '';
            showGlobal('error', String(message), true);
        }
    }

    async function uploadImage(event) {
        event.preventDefault();
        if (!state.canonical || !state.pendingImage || state.mutationBusy) return;
        clearGlobal();
        setFormMessage(el.imageDialogError, '');
        var formData = new FormData();
        formData.append('image', state.pendingImage);
        formData.append('profile_version', state.canonical.profile_version);
        formData.append('csrf_token', csrfToken());

        state.loadSequence += 1;
        setMutationBusy(true);
        try {
            var payload = await request('profile_image_upload', { method: 'POST', formData: formData });
            var previewUrl = state.pendingPreviewUrl;
            state.pendingPreviewUrl = null;
            state.canonical.profile_image = payload.data.profile_image;
            state.canonical.profile_version = payload.data.profile_version;
            renderCanonical({ previewUrl: previewUrl, forceImage: true });
            closeDialog(el.imageConfirmDialog);
            cleanupPendingImage();
            el.imageStatus.textContent = 'Profile image updated.';
            announce('Profile image updated.');
        } catch (error) {
            if ((error.reason || error.code) === 'PROFILE_CONFLICT') {
                await reloadConflict(el.imageDialogError, 'image');
            } else if (!isQuietError(error)) {
                setFormMessage(el.imageDialogError, friendlyError(error, 'image'), 'error');
            }
        } finally {
            setMutationBusy(false);
        }
    }

    function askToRemoveImage() {
        if (!state.canonical || !state.canonical.profile_image || !state.canonical.profile_image.present) return;
        setFormMessage(el.removeImageError, '');
        openDialog(el.removeImageDialog, el.removeImageButton);
        el.confirmRemoveImageButton.focus();
    }

    async function removeImage() {
        if (!state.canonical || state.mutationBusy) return;
        clearGlobal();
        setFormMessage(el.removeImageError, '');
        state.loadSequence += 1;
        setMutationBusy(true);
        try {
            var payload = await request('profile_image_remove', {
                method: 'POST',
                body: { profile_version: state.canonical.profile_version }
            });
            state.canonical.profile_image = payload.data.profile_image;
            state.canonical.profile_version = payload.data.profile_version;
            renderCanonical({ forceImage: true });
            closeDialog(el.removeImageDialog);
            el.imageStatus.textContent = 'Profile image removed.';
            announce('Profile image removed.');
        } catch (error) {
            if ((error.reason || error.code) === 'PROFILE_CONFLICT') {
                await reloadConflict(el.removeImageError, 'image');
            } else if (!isQuietError(error)) {
                setFormMessage(el.removeImageError, friendlyError(error, 'image'), 'error');
            }
        } finally {
            setMutationBusy(false);
        }
    }

    function passwordMetrics() {
        var password = el.passwordNew.value;
        var characters = Array.from(password).length;
        var bytes = new TextEncoder().encode(password).length;
        el.passwordCharacterCount.textContent = characters + (characters === 1 ? ' character' : ' characters');
        el.passwordByteCount.textContent = bytes + ' / 72 bytes';
        el.passwordCharacterCount.classList.toggle('is-valid', characters >= 12);
        el.passwordCharacterCount.classList.toggle('is-invalid', password.length > 0 && characters < 6);
        el.passwordByteCount.classList.toggle('is-invalid', bytes > 72);
        el.passwordByteCount.classList.toggle('is-valid', bytes > 0 && bytes <= 72);
        return { characters: characters, bytes: bytes };
    }

    function passwordPolicyErrors(password) {
        var metrics = { characters: Array.from(password).length, bytes: new TextEncoder().encode(password).length };
        var errors = [];
        if (!password) errors.push('New password is required.');
        if (password && metrics.characters < 12) errors.push('Password must be at least 6 characters.');
        if (metrics.bytes > 72) errors.push('Password is too long (maximum 72 UTF-8 bytes).');
        if (password.indexOf('\u0000') !== -1 || /^\s+$/u.test(password)) errors.push('Password contains invalid content.');
        if (COMMON_PASSWORDS.indexOf(password.toLocaleLowerCase()) !== -1) errors.push('Choose a less common password.');
        return errors;
    }

    function clearPasswordErrors() {
        fieldError(el.passwordCurrent, el.passwordCurrentError, '');
        fieldError(el.passwordNew, el.passwordNewError, '');
        fieldError(el.passwordConfirm, el.passwordConfirmError, '');
        setFormMessage(el.passwordFormAlert, '');
    }

    function resetPasswordForm() {
        el.passwordForm.reset();
        clearPasswordErrors();
        passwordMetrics();
    }

    function openPasswordDialog() {
        resetPasswordForm();
        openDialog(el.passwordDialog, el.openPasswordButton);
        el.passwordCurrent.focus();
    }

    function validatePasswordForm() {
        clearPasswordErrors();
        var valid = true;
        var policyErrors = passwordPolicyErrors(el.passwordNew.value);
        if (!el.passwordCurrent.value) {
            fieldError(el.passwordCurrent, el.passwordCurrentError, 'Enter your current password.');
            valid = false;
        }
        if (policyErrors.length) {
            fieldError(el.passwordNew, el.passwordNewError, policyErrors.join(' '));
            valid = false;
        }
        if (!el.passwordConfirm.value || el.passwordConfirm.value !== el.passwordNew.value) {
            fieldError(el.passwordConfirm, el.passwordConfirmError, 'New passwords do not match.');
            valid = false;
        }
        if (el.passwordCurrent.value && el.passwordNew.value === el.passwordCurrent.value) {
            fieldError(el.passwordNew, el.passwordNewError, 'New password must be different from the current password.');
            valid = false;
        }
        if (!valid) {
            var firstInvalid = el.passwordForm.querySelector('[aria-invalid="true"]');
            if (firstInvalid) firstInvalid.focus();
        }
        return valid;
    }

    function mapPasswordError(error) {
        var reason = error.reason || error.code;
        if (reason === 'CURRENT_PASSWORD_INCORRECT') {
            fieldError(el.passwordCurrent, el.passwordCurrentError, 'Current password is incorrect.');
            el.passwordCurrent.focus();
            return true;
        }
        if (reason === 'PASSWORD_CONFIRMATION_MISMATCH') {
            fieldError(el.passwordConfirm, el.passwordConfirmError, 'New passwords do not match.');
            el.passwordConfirm.focus();
            return true;
        }
        if (reason === 'NEW_PASSWORD_MUST_DIFFER') {
            fieldError(el.passwordNew, el.passwordNewError, 'New password must be different from the current password.');
            el.passwordNew.focus();
            return true;
        }
        if (reason === 'PASSWORD_POLICY_FAILED') {
            fieldError(el.passwordNew, el.passwordNewError, passwordPolicyErrors(el.passwordNew.value).join(' ') || 'The new password does not meet the password policy.');
            el.passwordNew.focus();
            return true;
        }
        return false;
    }

    async function submitPassword(event) {
        event.preventDefault();
        if (state.mutationBusy || !validatePasswordForm()) return;
        clearGlobal();
        var requestPasswordDraftGeneration = state.passwordDraftGeneration;
        state.loadSequence += 1;
        setMutationBusy(true);
        try {
            await request('password_change', {
                method: 'POST',
                body: {
                    current_password: el.passwordCurrent.value,
                    new_password: el.passwordNew.value,
                    confirm_password: el.passwordConfirm.value
                }
            });
            if (requestPasswordDraftGeneration === state.passwordDraftGeneration) {
                resetPasswordForm();
                closeDialog(el.passwordDialog);
                showGlobal('success', 'Password changed successfully.', false);
            } else {
                setFormMessage(el.passwordFormAlert, 'Password changed successfully. Newer entries were left untouched.', 'success');
                announce('Password changed successfully. Newer password entries were left untouched.');
            }
        } catch (error) {
            if (!isQuietError(error) && !mapPasswordError(error)) {
                setFormMessage(el.passwordFormAlert, friendlyError(error, 'password'), 'error');
            }
        } finally {
            setMutationBusy(false);
        }
    }

    function closeRequestedDialog(button) {
        var dialog = byId(button.getAttribute('data-close-dialog'));
        if (dialog) closeDialog(dialog);
    }

    function bindEvents() {
        el.profileForm.addEventListener('submit', submitProfile);
        [el.profileFullName, el.profileUsername, el.profileEmail, el.profileCurrentPassword].forEach(function (input) {
            input.addEventListener('input', markDraftChanged);
        });
        [el.passwordCurrent, el.passwordNew, el.passwordConfirm].forEach(function (input) {
            input.addEventListener('input', function () {
                state.draftGeneration += 1;
                state.passwordDraftGeneration += 1;
                if (input === el.passwordNew) passwordMetrics();
            });
        });
        el.profileUsername.addEventListener('blur', function () {
            el.profileUsername.value = normalizeUsername(el.profileUsername.value);
            updateDraftState();
        });
        el.profileEmail.addEventListener('blur', function () {
            el.profileEmail.value = normalizeEmail(el.profileEmail.value);
            updateDraftState();
        });
        el.resetProfileButton.addEventListener('click', resetProfileDraft);
        el.profileImageInput.addEventListener('change', selectImage);
        el.imageUploadForm.addEventListener('submit', uploadImage);
        el.removeImageButton.addEventListener('click', askToRemoveImage);
        el.confirmRemoveImageButton.addEventListener('click', removeImage);
        el.openPasswordButton.addEventListener('click', openPasswordDialog);
        el.passwordForm.addEventListener('submit', submitPassword);

        document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
            button.addEventListener('click', function () { closeRequestedDialog(button); });
        });

        el.chooseImageButton.addEventListener('click', function () {
            if (!el.chooseImageButton.disabled) el.profileImageInput.click();
        });

        [el.imageConfirmDialog, el.removeImageDialog, el.passwordDialog].forEach(function (dialog) {
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog && !state.mutationBusy) closeDialog(dialog);
            });
        });
        el.imageConfirmDialog.addEventListener('close', cleanupPendingImage);
        el.imageConfirmDialog.addEventListener('cancel', function (event) {
            if (state.mutationBusy) event.preventDefault();
        });
        el.removeImageDialog.addEventListener('cancel', function (event) {
            if (state.mutationBusy) event.preventDefault();
        });
        el.passwordDialog.addEventListener('cancel', function (event) {
            if (state.mutationBusy) event.preventDefault();
        });
        el.passwordDialog.addEventListener('close', resetPasswordForm);

        window.addEventListener('online', function () {
            updateAvailability();
            announce('Connection restored. You can save your changes.');
            if (!state.canonical && !state.sessionUnavailable) loadProfile();
        });
        window.addEventListener('offline', function () {
            updateAvailability();
            announce('You are offline. Drafts are preserved.');
        });
        window.addEventListener('beforeunload', function (event) {
            if (!hasUnsavedWork()) return;
            event.preventDefault();
            event.returnValue = '';
        });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible'
                && !state.mutationBusy
                && navigator.onLine) {
                loadProfile({ preserveDraft: !!state.canonical && hasUnsavedWork() });
            }
        });
    }

    function exposeTestHooks() {
        if (typeof window.__SSMS_ACCOUNT_TEST_HOOK__ !== 'function') return;
        window.__SSMS_ACCOUNT_TEST_HOOK__({
            state: state,
            elements: el,
            ApiError: ApiError,
            request: request,
            loadProfile: loadProfile,
            submitProfile: submitProfile,
            submitPassword: submitPassword,
            selectImage: selectImage,
            inspectSelectedImage: inspectSelectedImage,
            cleanupPendingImage: cleanupPendingImage,
            handleAccountContextMismatch: handleAccountContextMismatch,
            handleUnauthorized: handleUnauthorized
        });
    }

    function init() {
        cacheElements();
        API_URL = (el.accountApp.dataset.profileApi || '/admin/api_settings.php') + '?action=';
        bindEvents();
        setupAuthChannel();
        updateAvailability();
        passwordMetrics();
        loadProfile();
        exposeTestHooks();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
