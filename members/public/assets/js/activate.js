/* =====================================================================
   ፈለገ ቅዱሳን — Members App: account activation
   =====================================================================
   Drives the ACM ceremony that already exists on the API:

     POST /acm/activate/request      {member_code, first_name, phone?}
     POST /acm/activate/verify       {otp, reference, installation_id?}
     POST /acm/activate/credentials  {grant, password, idempotency_key}

   WHY THIS LIVES IN THE BROWSER AND NOT THE SERVER
     The password must not pass through this app's PHP, for the same reason the
     login form does not: /api/v1 already owns password handling, hashing, rate
     limiting and audit. A second PHP path that accepts a password would be a
     second place to get that right.

   WHAT THIS FILE MUST NOT DO
     Explain a failure. The API returns ONE identical message for an unknown ID,
     a wrong first name and a wrong phone, on purpose, so the endpoint cannot be
     used to discover which member IDs are real. Interpreting it -- "no such
     member" -- would rebuild exactly the oracle the API removed. Every message
     shown here is the server's own text.

   THE IDEMPOTENCY KEY
     /acm/activate/credentials takes one so a double-submit (flaky connection,
     impatient double-tap) replays instead of failing or double-provisioning.
     It is generated once per grant and reused for every retry of that grant,
     which is what makes the replay a replay rather than a second attempt.
   ===================================================================== */
(function () {
    "use strict";

    function $(sel, root) { return (root || document).querySelector(sel); }

    function isAm() { return document.documentElement.lang !== "en"; }
    function pick(am, en) { return isAm() ? am : (en || am); }

    /* --- message boxes --------------------------------------------------
       One helper, so every step shows text the same way and none of them
       accidentally unsticks the "the server decides the words" rule. */
    function showMsg(sel, kind, text) {
        var box = $(sel);
        if (!box) { return; }
        box.textContent = text;
        box.setAttribute("data-kind", kind);
        box.style.display = text ? "block" : "none";
    }
    function hideMsg(sel) { showMsg(sel, "error", ""); }

    function busy(button, on, label) {
        if (!button) { return; }
        if (on) {
            button.dataset.label = button.textContent;
            button.disabled = true;
            button.textContent = "…";
        } else {
            button.disabled = false;
            if (label) { button.textContent = label; }
            else if (button.dataset.label) { button.textContent = button.dataset.label; }
        }
    }

    /* --- step visibility ------------------------------------------------ */
    function show(stepId) {
        ["#step-request", "#step-verify", "#step-credentials", "#step-done"].forEach(function (sel) {
            var node = $(sel);
            if (node) { node.hidden = (sel !== stepId); }
        });
        var target = $(stepId);
        if (target && typeof target.scrollIntoView === "function") {
            target.scrollIntoView({ block: "nearest" });
        }
    }

    /* --- installation id -------------------------------------------------
       The API takes an optional installation_id on verify. We do not have one:
       it is issued by the mobile app, and guessing one would be worse than
       omitting it (the endpoint treats absent as "no device context", but a
       wrong value would bind a grant to a device that does not exist). So it is
       deliberately not sent. */
    var state = { reference: "", grant: "", idempotencyKey: "" };

    function makeIdempotencyKey() {
        /* Random, not derived from anything: it only has to be unique per
           submission and unguessable enough that another client cannot collide
           with it deliberately. */
        try {
            var bytes = new Uint8Array(16);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(bytes);
                return Array.prototype.map.call(bytes, function (b) {
                    return ("0" + b.toString(16)).slice(-2);
                }).join("");
            }
        } catch (e) { /* fall through */ }
        return "k" + Date.now().toString(36) + Math.random().toString(36).slice(2, 18);
    }

    /* --- step 1: request ------------------------------------------------ */
    function bindRequest() {
        var form = $("#activate-request-form");
        if (!form) { return; }

        form.addEventListener("submit", async function (event) {
            event.preventDefault();
            hideMsg("#request-msg");

            var code = ($("#member-code").value || "").trim();
            var firstName = ($("#first-name").value || "").trim();
            var phone = ($("#phone-optional").value || "").trim();

            if (code === "" || firstName === "") {
                showMsg("#request-msg", "error", pick(
                    "እባክዎ የተማሪ መለያውን እና የመጀመሪያ ስምዎን ያስገቡ።",
                    "Please enter your student ID and first name."
                ));
                return;
            }

            var submit = $("#request-submit");
            busy(submit, true);

            try {
                var body = { member_code: code, first_name: firstName, account_type: "member" };
                if (phone !== "") { body.phone = phone; }

                var data = await window.FKApi.post("/acm/activate/request", body, { noRedirect: true });

                /* 202 with a reference. The API returns the SAME body for a
                   duplicate and for "not verified", so the absence of an error
                   does not prove the ID was recognised -- and we must not imply
                   that it does. */
                state.reference = (data && data.request_ref) || "";
                var refNode = $("#request-ref");
                if (refNode) { refNode.textContent = state.reference || "—"; }

                show("#step-verify");
            } catch (error) {
                showMsg("#request-msg", "error", (error && error.message) || pick(
                    "ጥያቄውን መላክ አልተቻለም። እባክዎ በኋላ ይሞክሩ።",
                    "The request could not be sent. Please try again later."
                ));
            } finally {
                busy(submit, false);
            }
        });
    }

    /* --- step 2: verify -------------------------------------------------- */
    function bindVerify() {
        var form = $("#activate-verify-form");
        if (!form) { return; }

        form.addEventListener("submit", async function (event) {
            event.preventDefault();
            hideMsg("#verify-msg");

            var otp = ($("#otp").value || "").replace(/\s+/g, "");

            if (!/^[0-9]{6}$/.test(otp)) {
                showMsg("#verify-msg", "error", pick(
                    "ኮዱ ስድስት አሃዝ መሆን አለበት።",
                    "The code must be six digits."
                ));
                return;
            }
            if (state.reference === "") {
                /* The reference is the scope of the comparison: without it the
                   code cannot be checked against the right request. Bouncing to
                   step 1 is honest; sending a request that is certain to fail
                   is not. */
                showMsg("#verify-msg", "error", pick(
                    "የጥያቄ ቁጥር አልተገኘም። እባክዎ እንደገና ይጀምሩ።",
                    "Your reference is missing. Please start again."
                ));
                return;
            }

            var submit = $("#verify-submit");
            busy(submit, true);

            try {
                var data = await window.FKApi.post("/acm/activate/verify",
                    { otp: otp, reference: state.reference },
                    { noRedirect: true });

                state.grant = (data && data.grant) || "";
                if (state.grant === "") {
                    throw new window.FKApi.ApiError(500, "NO_GRANT", pick(
                        "ኮዱ ተረጋግጦ ማለፊያ አልተሰጠም። እባክዎ በኋላ ይሞክሩ።",
                        "The code was accepted but no activation session was issued. Please try again."
                    ));
                }

                /* One key per grant, reused on retry so a replay stays a replay. */
                state.idempotencyKey = makeIdempotencyKey();
                show("#step-credentials");
            } catch (error) {
                showMsg("#verify-msg", "error", (error && error.message) || pick(
                    "ኮዱን ማረጋገጥ አልተቻለም።",
                    "The code could not be checked."
                ));
                $("#otp").focus();
                $("#otp").select();
            } finally {
                busy(submit, false);
            }
        });
    }

    /* --- step 3: credentials --------------------------------------------- */
    function bindCredentials() {
        var form = $("#activate-credentials-form");
        if (!form) { return; }

        form.addEventListener("submit", async function (event) {
            event.preventDefault();
            hideMsg("#credentials-msg");

            var password = $("#new-password").value || "";
            var confirm = $("#confirm-password").value || "";

            if (password.length < 8) {
                showMsg("#credentials-msg", "error", pick(
                    "የይለፍ ቃል ቢያንስ 8 ፊደሎች መሆን አለበት።",
                    "The password must be at least 8 characters."
                ));
                return;
            }
            /* Confirmation is a UI concern only -- the API takes one value
               (design D3.4). Checking it here is the only place it can be
               checked. */
            if (password !== confirm) {
                showMsg("#credentials-msg", "error", pick(
                    "የይለፍ ቃሎቹ አይመሳሰሉም።",
                    "The two passwords do not match."
                ));
                return;
            }
            if (state.grant === "" || state.idempotencyKey === "") {
                showMsg("#credentials-msg", "error", pick(
                    "የማንቃት ጊዜው አልፏል። እባክዎ እንደገና ይጀምሩ።",
                    "Your activation session has expired. Please start again."
                ));
                return;
            }

            var submit = $("#credentials-submit");
            busy(submit, true);

            try {
                await window.FKApi.post("/acm/activate/credentials", {
                    grant: state.grant,
                    password: password,
                    idempotency_key: state.idempotencyKey
                }, { noRedirect: true });

                /* Do not claim the account is usable. The API's own response
                   says the account sits at account_pending_device, and login
                   will refuse until a device is registered. */
                state.grant = "";
                state.idempotencyKey = "";
                $("#new-password").value = "";
                $("#confirm-password").value = "";
                show("#step-done");
            } catch (error) {
                showMsg("#credentials-msg", "error", (error && error.message) || pick(
                    "የይለፍ ቃሉን ማስቀመጥ አልተቻለም።",
                    "The password could not be saved."
                ));
            } finally {
                busy(submit, false);
            }
        });
    }

    function start() {
        if (!$("#step-request")) { return; }
        bindRequest();
        bindVerify();
        bindCredentials();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }
})();
