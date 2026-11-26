/* =========================================
   FELEGE KIDUSAN - shared session helper (Stage 1)
   - If the server says "please sign in" (401), go to the login page.
   - If the server says the student must choose a new password, go to that page.
   - Pressing any #logout-button also ends the SERVER session.
   Pages can set  window.FK_NO_AUTO_REDIRECT = true  to handle this themselves.
   ========================================= */
(function () {
    var nativeFetch = window.fetch.bind(window);

    function pathOf(input) {
        var url = typeof input === "string" ? input : (input && input.url) || "";
        try {
            return new URL(url, window.location.origin).pathname;
        } catch (e) {
            return url.split("?")[0];
        }
    }

    window.fetch = async function (input, init) {
        var response = await nativeFetch(input, init);

        try {
            var path = pathOf(input);
            var isApi = path.indexOf("/api/") === 0;
            var isLogin = path === "/api/auth/login" || path === "/api/auth/staff-login";

            if (isApi && !isLogin && !window.FK_NO_AUTO_REDIRECT) {
                if (response.status === 401) {
                    sessionStorage.removeItem("student");
                    window.location.href = "login.html";
                } else if (response.status === 403) {
                    var body = await response.clone().json().catch(function () { return null; });
                    if (body && body.code === "PASSWORD_CHANGE_REQUIRED") {
                        window.location.href = "change-password.html";
                    }
                }
            }
        } catch (e) { /* never block the original response */ }

        return response;
    };

    document.addEventListener("click", function (event) {
        var button = event.target.closest ? event.target.closest("#logout-button") : null;
        if (button) {
            nativeFetch("/api/auth/logout", { method: "POST", keepalive: true }).catch(function () {});
        }
    }, true);
})();
