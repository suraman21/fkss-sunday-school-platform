/* =====================================================================
   ፈለገ ቅዱሳን — Members App: page behaviour
   =====================================================================
   The CSP disallows inline scripts, so every handler lives here and is bound
   with event delegation from data-* attributes. That means a template can
   never smuggle a handler in, and no handler is lost when a fragment is
   re-rendered.

   Loaded with `defer`, and the API module is a plain <script> before it, so
   window.FKApi is always defined by the time this runs.
   ===================================================================== */
(function () {
    "use strict";

    var LANG_COOKIE = "members_lang";

    /* --- tiny helpers -------------------------------------------------- */
    function $(selector, root) { return (root || document).querySelector(selector); }
    function $$(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function toast(message) {
        var el = $("#toast");
        if (!el) { return; }
        el.textContent = message;
        el.hidden = false;
        window.clearTimeout(toast._timer);
        toast._timer = window.setTimeout(function () { el.hidden = true; }, 3400);
    }

    function showError(selector, message) {
        var el = $(selector);
        if (!el) { return; }
        el.textContent = message;
        el.hidden = false;
    }
    function hideError(selector) {
        var el = $(selector);
        if (el) { el.hidden = true; }
    }

    /* --- language -------------------------------------------------------
       A cookie, not localStorage: the server reads it while rendering so the
       first paint is already in the right language. A localStorage-only
       switch would flash the other language on every page load. */
    function applyLanguage(lang) {
        document.cookie = LANG_COOKIE + "=" + encodeURIComponent(lang)
            + "; path=/; max-age=31536000; samesite=lax"
            + (window.location.protocol === "https:" ? "; secure" : "");
        window.location.reload();
    }

    /* --- sign out -------------------------------------------------------
       Clears the local token FIRST, then asks the API to revoke the session.
       Order matters: if the network call fails, the device is still signed
       out locally rather than left holding a live token. */
    function signOut() {
        var token = window.FKApi.token();
        window.FKApi.clearTokens();
        if (!token) { window.location.replace("/members/login"); return; }
        fetch("/api/v1/auth/logout", {
            method: "POST",
            headers: { "Authorization": "Bearer " + token, "Accept": "application/json" },
            keepalive: true
        }).catch(function () { /* the local wipe already happened */ })
          .then(function () { window.location.replace("/members/login"); });
    }

    /* --- login ---------------------------------------------------------- */
    function bindLogin() {
        var form = $("#login-form");
        if (!form) { return; }

        form.addEventListener("submit", async function (event) {
            event.preventDefault();
            hideError("#login-error");

            var username = ($("#username").value || "").trim();
            var password = $("#password").value || "";
            var submit = $("#login-submit");

            if (username === "" || password === "") {
                showError("#login-error", "Please enter your username and password.");
                return;
            }

            submit.disabled = true;
            submit.textContent = "…";

            try {
                /* No redirect on 401: here it means "wrong password", not
                   "your session expired", and bouncing to the login page the
                   user is already on would look like nothing happened. */
                var data = await window.FKApi.post("/auth/login",
                    { username: username, password: password },
                    { noRedirect: true });

                var token = data && (data.access_token || data.token);
                var refresh = data && (data.refresh_token || null);
                if (!token) {
                    throw new window.FKApi.ApiError(500, "NO_TOKEN",
                        "The school system did not return a session. Please try again.");
                }

                window.FKApi.setTokens(token, refresh);

                var role = (data.user && data.user.role) || data.role || "";
                var next = new URLSearchParams(window.location.search).get("next");
                var dest = next && next.indexOf("/members/") === 0 ? next : "/members/home";

                /* A parent lands on the same home for now; the API decides
                   what they can see. Role-specific shells come with the
                   family feature, not before it. */
                void role;
                window.location.replace(dest);
            } catch (error) {
                var msg = error && error.message ? error.message : "Sign-in failed.";
                /* A 403 with a known code means credentials were right but the
                   account cannot sign in yet — say which step is missing
                   rather than repeating "wrong password". */
                /* The API sends `activation_pending` (lowercase, from
                   err(..., ['code' => 'activation_pending'])) — this used to
                   test for ACCOUNT_PENDING_ACTIVATION, which never matched, so
                   the one message that could have explained the situation was
                   never shown. Both are accepted so a future rename does not
                   silently disable it again. */
                var pendingCode = error && (error.code || "").toLowerCase();
                if (error && error.status === 403
                        && (pendingCode === "activation_pending"
                            || pendingCode === "account_pending_activation")) {
                    msg = document.documentElement.lang === "en"
                        ? "Your account is not finished setting up. Complete device setup on your phone, then sign in."
                        : "መለያዎ ገና አልተጠናቀቀም። በስልክዎ ላይ ማጠናቀቅ ያስፈልጋል።";
                }
                showError("#login-error", msg);
                $("#password").focus();
                $("#password").select();
            } finally {
                submit.disabled = false;
                submit.textContent = document.documentElement.lang === "en" ? "Sign in" : "ግባ";
            }
        });
    }

    /* --- global delegation ---------------------------------------------- */
    document.addEventListener("click", function (event) {
        var target = event.target;
        if (!target || !target.closest) { return; }

        var langButton = target.closest("[data-lang]");
        if (langButton) {
            event.preventDefault();
            applyLanguage(langButton.getAttribute("data-lang"));
            return;
        }

        if (target.closest("[data-action='logout']")) {
            event.preventDefault();
            signOut();
        }
    });

    /* --- signed-in guard -------------------------------------------------
       A cheap client-side check that avoids painting an empty dashboard for a
       signed-out visitor. It is NOT the access control — the API refuses every
       call without a token, and that is the real boundary. */
    function guard() {
        var needsAuth = document.body.getAttribute("data-requires-auth") === "1";
        if (!needsAuth) { return; }
        if (!window.FKApi.token()) {
            window.location.replace("/members/login?next=" + encodeURIComponent(window.location.pathname));
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () { bindLogin(); guard(); });
    } else {
        bindLogin();
        guard();
    }

    window.FKUi = { toast: toast, showError: showError, hideError: hideError };
})();
