/* =====================================================================
   ፈለገ ቅዱሳን — Members App: API client
   =====================================================================
   One module owns every network call. Pages never call fetch() directly.

   WHY THAT MATTERS
     - The bearer token has exactly one place it is read and one place it is
       written. A leak audit is four functions, not every page.
     - The API's envelope ({status, data, message}) is unwrapped once, so no
       page re-implements the error mapping and gets a different answer.
     - A 401 in any page means the same thing: the session is gone. Handling
       that centrally is the difference between "signed out everywhere" and
       "one page keeps showing a stale spinner forever".

   The API is /api/v1 — the platform's existing one. This app adds no endpoints.
   ===================================================================== */
(function () {
    "use strict";

    var API_BASE = "/api/v1";
    var TOKEN_KEY = "fk_members_token";
    var REFRESH_KEY = "fk_members_refresh";

    /* --- token storage ---------------------------------------------------
       sessionStorage, not localStorage: the token dies with the tab. A shared
       phone in a church hall must not stay signed in after the browser closes.
       sessionStorage is also not sent automatically with requests the way a
       cookie is, which removes CSRF from the token path entirely. */
    function getToken() {
        try { return window.sessionStorage.getItem(TOKEN_KEY); } catch (e) { return null; }
    }
    function setTokens(access, refresh) {
        try {
            if (access) { window.sessionStorage.setItem(TOKEN_KEY, access); }
            if (refresh) { window.sessionStorage.setItem(REFRESH_KEY, refresh); }
        } catch (e) { /* private mode: stay usable, just not persistent */ }
    }
    function clearTokens() {
        try {
            window.sessionStorage.removeItem(TOKEN_KEY);
            window.sessionStorage.removeItem(REFRESH_KEY);
        } catch (e) { /* ignore */ }
    }

    /* --- error type ------------------------------------------------------
       A plain object would make "is this a 401 or a validation failure?"
       a guess at every call site. */
    function ApiError(status, code, message, details) {
        this.name = "ApiError";
        this.status = status;
        this.code = code || "API_ERROR";
        this.message = message || "Something went wrong.";
        this.details = details || {};
    }
    ApiError.prototype = Object.create(Error.prototype);
    ApiError.prototype.constructor = ApiError;

    /* --- one request -----------------------------------------------------
       Redirects to sign-in on 401 rather than letting each page decide. Note
       the exception: a 401 from the login call itself is a wrong password,
       not an expired session, so callers can opt out with {noRedirect:true}. */
    async function request(path, options) {
        options = options || {};
        var headers = { "Accept": "application/json" };
        if (options.body !== undefined) {
            headers["Content-Type"] = "application/json";
        }
        var token = getToken();
        if (token) { headers["Authorization"] = "Bearer " + token; }

        var response;
        try {
            response = await fetch(API_BASE + path, {
                method: options.method || "GET",
                headers: headers,
                body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
                credentials: "omit",
                cache: "no-store",
                redirect: "error"
            });
        } catch (networkError) {
            throw new ApiError(0, "NETWORK", "You appear to be offline. Check your connection and try again.");
        }

        var payload = null;
        try { payload = await response.json(); } catch (e) { payload = null; }

        if (!response.ok) {
            var message = (payload && (payload.message || payload.error)) || defaultMessage(response.status);
            var code = (payload && payload.code) || null;
            if (response.status === 401 && !options.noRedirect) {
                clearTokens();
                window.location.replace("/members/login?next=" + encodeURIComponent(window.location.pathname));
            }
            throw new ApiError(response.status, code, message, payload && payload.data);
        }

        /* Envelope: {status:"success", data:{...}} or a bare object. Both are
           accepted because some long-standing endpoints return a bare body and
           forcing one shape would mean changing the API for the app's sake. */
        if (payload && typeof payload === "object" && "data" in payload && "status" in payload) {
            return payload.data;
        }
        return payload;
    }

    function defaultMessage(status) {
        if (status === 403) { return "You do not have access to this."; }
        if (status === 404) { return "We could not find that."; }
        if (status === 429) { return "Too many attempts. Please wait a moment."; }
        if (status >= 500) { return "The school system is busy. Please try again shortly."; }
        return "Something went wrong.";
    }

    window.FKApi = {
        get: function (path, options) { return request(path, options); },
        post: function (path, body, options) {
            var opts = options || {};
            opts.method = "POST";
            opts.body = body === undefined ? {} : body;
            return request(path, opts);
        },
        token: getToken,
        setTokens: setTokens,
        clearTokens: clearTokens,
        ApiError: ApiError
    };
})();
