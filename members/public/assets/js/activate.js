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

    /* --- server errors, in the reader's language --------------------------
       The API answers with ONE fixed English sentence per machine `code`, and
       that is deliberate: `not_verified` is identical for an unknown ID, a
       wrong first name and a wrong phone so the endpoint cannot be used to
       discover who is registered. We must not add detail.

       But this page is Amharic, and it used to print the server's English raw
       -- "The request is invalid." on a screen where every other sentence is
       Amharic. The server owns the CONTRACT; the client owns the words. So we
       translate by `code` and never by the English text (which may be
       reworded at any time), and an unknown code gets our generic sentence
       rather than echoing anything back.

       Nothing here names a cause. "not_verified" stays vague on purpose. */
    var SERVER_COPY = {
        not_verified: [
            "መረጃዎን ከመዝገባችን ጋር ማረጋገጥ አልቻልንም። መለያውንና የመጀመሪያ ስምዎን ይመልከቱ፣ ወይም የቤተ ክርስቲያኑን ቢሮ ያግኙ።",
            "We could not verify these details against our member records. Check the ID number and first name, or contact the church office."
        ],
        invalid_request: [
            "የተላከው መረጃ ትክክል አይደለም። እባክዎ ገጹን አድሰው እንደገና ይሞክሩ።",
            "Something about the request was not accepted. Please reload the page and try again."
        ],
        rate_limited: [
            "ሙከራዎች በጣም ብዙ ሆነዋል። ጥቂት ጊዜ ወስደው እንደገና ይሞክሩ።",
            "Too many attempts. Please wait a while and try again."
        ],
        weak_password: [
            "የይለፍ ቃሉ ጠንካራ አይደለም። ረዘም ያለ የይለፍ ቃል ይምረጡ።",
            "That password is too weak. Please choose a longer one."
        ],
        temporarily_unavailable: [
            "አገልግሎቱ ለጊዜው አይገኝም። እባክዎ ትንሽ ቆይተው ይሞክሩ።",
            "The service is unavailable right now. Please try again shortly."
        ],
        activation_pending: [
            "መለያዎ በመሣሪያ ማዋቀር ላይ ይጠብቃል። ማንቃትን በስልክዎ ያጠናቁ።",
            "Your account is waiting for device setup. Finish activation on your phone."
        ],
        grant_invalid: [
            "የማንቃት ጊዜው አልፏል። እባክዎ እንደገና ይጀምሩ።",
            "Your activation session has expired. Please start again."
        ],
        grant_expired: [
            "የማንቃት ጊዜው አልፏል። ኮድ እንዲታደስ ቢሮውን ያግኙ።",
            "Your activation session expired. Ask the office to issue a new code."
        ],
        grant_consumed: [
            "ይህ የማንቃት ጊዜ አስቀድሞ ጥቅም ላይ ውሏል። እባክዎ እንደገና ይጀምሩ።",
            "This activation session was already used. Please start again."
        ],
        grant_revoked: [
            "የማንቃት ጊዜው ተሰርዟል። ኮድ እንዲታደስ ቢሮውን ያግኙ።",
            "Your activation session was revoked. Ask the office to issue a new code."
        ],
        code_invalid: [
            "ኮዱ ልክ ያልሆነ ወይም ጊዜው ያለፈበት ነው።",
            "The code is invalid or expired."
        ],
        account_exists: [
            "ለዚህ ሰው መለያ አስቀድሞ አለ። ጉዳዩ ለቢሮው ተልኳል።",
            "An account already exists for this person. The office has been notified."
        ],
        idempotent_conflict: [
            "ይህ ጥያቄ አስቀድሞ ተልኳል። እባክዎ እንደገና ይጀምሩ።",
            "This request was already sent. Please start again."
        ],
        network_denied: [
            "ማንቃት ከዚህ አውታረ መረብ አይፈቀድም።",
            "Activation is not available from this network."
        ],
        username_unavailable: [
            "ይህ የመግቢያ ስም አስቀድሞ ጥቅም ላይ ውሏል። ሌላ ይምረጡ።",
            "That username is already in use. Please choose another."
        ]
    };

    /* The sentence to show for a failed call. Reads `error.code` only: the
       server may reword its English at any time, and matching on prose would
       silently fall back to English the moment it does. */
    function serverMessage(error, fallbackAm, fallbackEn) {
        if (error && error.status === 0) {
            return pick(
                "ከኢንተርኔት ጋር መገናኘት አልተቻለም። ግንኙነትዎን ይመልከቱ።",
                "You appear to be offline. Check your connection and try again."
            );
        }
        var entry = error && error.code ? SERVER_COPY[error.code] : null;
        if (entry) { return pick(entry[0], entry[1]); }
        // Unknown code: our own generic sentence, never the server's text.
        return pick(fallbackAm, fallbackEn);
    }

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
           with it deliberately.

           THE FORMAT IS NOT OURS TO CHOOSE. ActivationService::
           establishCredentials validates it with

               /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

           and answers anything else with invalid_request -- "The request is
           invalid." This used to emit 32 bare hex characters (no hyphens), so
           EVERY activation died on the final step with a message that reads
           like a malformed form, after the member had already chosen a
           password. Keep the hyphens: they are what the server wants. */
        try {
            var bytes = new Uint8Array(16);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(bytes);
                var hex = Array.prototype.map.call(bytes, function (b) {
                    return ("0" + b.toString(16)).slice(-2);
                }).join("");
                return hex.slice(0, 8) + "-" + hex.slice(8, 12) + "-"
                    + hex.slice(12, 16) + "-" + hex.slice(16, 20) + "-"
                    + hex.slice(20);
            }
        } catch (e) { /* fall through */ }
        return fallbackUuidV4();
    }

    /* Same shape, from Math.random, for the (rare) browser with no
       crypto.getRandomValues. Weaker randomness, but a well-formed key: a
       malformed one is rejected outright and blocks activation completely,
       which is strictly worse than a key with less entropy. */
    function fallbackUuidV4() {
        var hex = "";
        for (var i = 0; i < 16; i++) {
            hex += ("0" + Math.floor(Math.random() * 256).toString(16)).slice(-2);
        }
        return hex.slice(0, 8) + "-" + hex.slice(8, 12) + "-"
            + hex.slice(12, 16) + "-" + hex.slice(16, 20) + "-"
            + hex.slice(20);
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
                showMsg("#request-msg", "error", serverMessage(error,
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
                    showMsg("#verify-msg", "error", pick(
                        "ኮዱ ተረጋግጦ ማለፊያ አልተሰጠም። እባክዎ በኋላ ይሞክሩ።",
                        "The code was accepted but no activation session was issued. Please try again."
                    ));
                    return;
                }

                /* One key per grant, reused on retry so a replay stays a replay. */
                state.idempotencyKey = makeIdempotencyKey();
                show("#step-credentials");
            } catch (error) {
                showMsg("#verify-msg", "error", serverMessage(error,
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
                showMsg("#credentials-msg", "error", serverMessage(error,
                    "የማንቃት ጊዜው አልተሳካም። እባክዎ እንደገና ይጀምሩ።",
                    "Your activation could not be completed. Please start again."
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
