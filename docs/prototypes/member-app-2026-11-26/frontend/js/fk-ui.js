/* =========================================
   FELEGE KIDUSAN - shared student chrome
   Header (language, notifications, profile, settings), six-link navigation,
   phone bottom bar, floating assistant window, permission + password dialogs.
   Pages put <header id="fk-header"></header> in the body; the quiz page keeps
   its own old header, which is hidden and replaced by this one.
   All text is inserted with textContent (never innerHTML) except the static icon paths.
   ========================================= */
(function () {
    "use strict";

    var FK = window.FK = window.FK || {};
    var dict = { am: {}, en: {} };

    /* ---------- language ---------- */
    FK.lang = function () { return sessionStorage.getItem("language") === "en" ? "en" : "am"; };
    FK.setLang = function (l) { sessionStorage.setItem("language", l === "en" ? "en" : "am"); window.location.reload(); };
    FK.addStrings = function (s) {
        ["am", "en"].forEach(function (l) {
            Object.keys((s && s[l]) || {}).forEach(function (k) { dict[l][k] = s[l][k]; });
        });
    };
    FK.t = function (key, vars) {
        var s = dict[FK.lang()][key];
        if (s === undefined) { s = dict.en[key]; }
        if (s === undefined) { s = key; }
        if (vars) { Object.keys(vars).forEach(function (k) { s = s.split("{" + k + "}").join(vars[k]); }); }
        return s;
    };
    FK.applyI18n = function (root) {
        root = root || document;
        document.documentElement.lang = FK.lang();
        Array.prototype.forEach.call(root.querySelectorAll("[data-i18n]"), function (n) { n.textContent = FK.t(n.getAttribute("data-i18n")); });
        Array.prototype.forEach.call(root.querySelectorAll("[data-i18n-placeholder]"), function (n) { n.setAttribute("placeholder", FK.t(n.getAttribute("data-i18n-placeholder"))); });
        Array.prototype.forEach.call(root.querySelectorAll("[data-i18n-label]"), function (n) { n.setAttribute("aria-label", FK.t(n.getAttribute("data-i18n-label"))); });
    };

    FK.addStrings({
        am: {
            "nav.home": "መነሻ", "nav.lessons": "ትምህርቶቼ", "nav.tasks": "ተግባራት", "nav.quiz": "Quiz Me", "nav.exam": "የፈተና ዝግጅት", "nav.progress": "እድገቴ", "nav.main": "ዋና ምናሌ",
            "common.loading": "በመጫን ላይ…", "common.error": "መረጃውን ማምጣት አልተቻለም። እባክዎ እንደገና ይሞክሩ።", "common.retry": "እንደገና ሞክር", "common.back": "ተመለስ", "common.language": "ቋንቋ", "common.close": "ዝጋ",
            "bell.title": "ማስታወቂያዎች", "bell.none": "ምንም አዲስ ማስታወቂያ የለም።", "bell.markAll": "ሁሉንም እንደተነበቡ አድርግ",
            "me.title": "የግል መረጃ", "me.id": "የተማሪ መታወቂያ", "me.grade": "ክፍል", "me.class": "ክፍለ ጊዜ", "me.attendance": "የተገኝነት መዝገብ",
            "att.present": "ተገኝቷል", "att.absent": "ቀርቷል", "att.late": "ዘግይቷል", "att.permission": "በፈቃድ", "att.percent": "የተገኝነት መጠን", "att.none": "እስካሁን የተመዘገበ የተገኝነት መዝገብ የለም።",
            "perm.ask": "ፈቃድ ጠይቅ", "perm.title": "የፈቃድ ጥያቄ", "perm.date": "ቀን", "perm.reason": "ምክንያት", "perm.send": "ላክ", "perm.sent": "ጥያቄዎ ተልኳል።", "perm.history": "ያለፉ ጥያቄዎች", "perm.none": "እስካሁን ጥያቄ አልላኩም።",
            "perm.pending": "በመጠባበቅ ላይ", "perm.approved": "ተፈቅዷል", "perm.declined": "ተቀባይነት አላገኘም",
            "set.title": "ቅንብሮች", "set.password": "የይለፍ ቃል ቀይር", "set.logout": "ውጣ",
            "pw.current": "አሁን ያለው የይለፍ ቃል", "pw.new": "አዲስ የይለፍ ቃል (ቢያንስ 8 ፊደላት)", "pw.confirm": "አዲሱን የይለፍ ቃል ያረጋግጡ", "pw.save": "አስቀምጥ", "pw.mismatch": "አዲሶቹ የይለፍ ቃሎች አይመሳሰሉም።", "pw.ok": "የይለፍ ቃልዎ ተቀይሯል።", "pw.fail": "ማስቀመጥ አልተቻለም።",
            "as.title": "ጠይቀኝ", "as.mode": "ይህ ረዳት መምህርዎ በከፈቱልዎ ትምህርቶች ውስጥ ብቻ ይፈልጋል። የውጭ AI ሞዴል ገና አልተገናኘም።", "as.topic": "ርዕስ", "as.all": "ሁሉም የተከፈቱ ትምህርቶች", "as.placeholder": "ጥያቄዎን ይጻፉ…", "as.send": "ላክ",
            "as.explain": "ትምህርቱን በቀላል አብራራ", "as.revise": "እንድከልስ እርዳኝ", "as.practice": "የልምምድ ጥያቄዎች ስጠኝ", "as.pickLesson": "ይህን ለመጠቀም መጀመሪያ አንድ ትምህርት ይምረጡ።", "as.thinking": "በመፈለግ ላይ…",
            "as.openLesson": "ትምህርቱን ክፈት", "as.practiceLink": "ጥያቄዎቹን በ Quiz Me ይሞክሩ", "as.err": "መልስ ማግኘት አልተቻለም። እባክዎ እንደገና ይሞክሩ።", "as.empty": "ጥያቄ ይጻፉ ወይም ከላይ ካሉት አንዱን ይምረጡ።", "as.clear": "ውይይቱን አጥፋ"
        },
        en: {
            "nav.home": "Home", "nav.lessons": "Lessons", "nav.tasks": "Tasks", "nav.quiz": "Quiz Me", "nav.exam": "Exam Prep", "nav.progress": "Progress", "nav.main": "Main menu",
            "common.loading": "Loading…", "common.error": "Could not load the information. Please try again.", "common.retry": "Try again", "common.back": "Back", "common.language": "Language", "common.close": "Close",
            "bell.title": "Notifications", "bell.none": "No new announcements.", "bell.markAll": "Mark all as read",
            "me.title": "My details", "me.id": "Student ID", "me.grade": "Grade", "me.class": "Class", "me.attendance": "Attendance",
            "att.present": "Present", "att.absent": "Absent", "att.late": "Late", "att.permission": "Permission", "att.percent": "Attendance rate", "att.none": "No attendance has been recorded yet.",
            "perm.ask": "Ask for permission", "perm.title": "Permission request", "perm.date": "Date", "perm.reason": "Reason", "perm.send": "Send", "perm.sent": "Your request has been sent.", "perm.history": "Your requests", "perm.none": "You have not sent any request yet.",
            "perm.pending": "Waiting", "perm.approved": "Approved", "perm.declined": "Declined",
            "set.title": "Settings", "set.password": "Change password", "set.logout": "Sign out",
            "pw.current": "Current password", "pw.new": "New password (at least 8 characters)", "pw.confirm": "Confirm new password", "pw.save": "Save", "pw.mismatch": "The new passwords do not match.", "pw.ok": "Your password has been changed.", "pw.fail": "Could not save.",
            "as.title": "Ask me", "as.mode": "This assistant searches only the lessons your teacher has opened for you. No external AI model is connected yet.", "as.topic": "Topic", "as.all": "All opened lessons", "as.placeholder": "Type your question…", "as.send": "Send",
            "as.explain": "Explain this lesson simply", "as.revise": "Help me revise", "as.practice": "Give me practice questions", "as.pickLesson": "Choose a lesson first to use this.", "as.thinking": "Searching…",
            "as.openLesson": "Open lesson", "as.practiceLink": "Try these questions in Quiz Me", "as.err": "Could not get an answer. Please try again.", "as.empty": "Type a question or pick one of the buttons above.", "as.clear": "Clear conversation"
        }
    });

    /* ---------- helpers ---------- */
    FK.el = function (tag, attrs, children) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === "class") { n.className = attrs[k]; }
            else if (k === "text") { n.textContent = attrs[k]; }
            else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) { n.setAttribute(k, attrs[k] === true ? "" : attrs[k]); }
        });
        (children || []).forEach(function (c) {
            if (c === null || c === undefined) { return; }
            n.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
        });
        return n;
    };

    FK.svg = function (name) {
        var paths = {
            home: '<path d="M3 11l9-8 9 8M5 10v10h14V10M10 20v-6h4v6"/>',
            book: '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2V5zM4 19a2 2 0 0 1 2-2h13"/>',
            tasks: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M8.5 12.5l2 2 4-4"/>',
            quiz: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 1-1 1.7M12 17h.01"/>',
            target: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
            chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
            user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
            bell: '<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15zM10 21h4"/>',
            gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
            close: '<path d="M6 6l12 12M18 6L6 18"/>',
            send: '<path d="M4 12l16-8-6 16-2.5-6.5z"/>',
            lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
            check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
            play: '<path d="M8 5l11 7-11 7z"/>',
            star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
            trophy: '<path d="M8 4h8v5a4 4 0 0 1-8 0zM8 6H5a3 3 0 0 0 3 4M16 6h3a3 3 0 0 1-3 4M12 13v4M8.5 20h7"/>'
        };
        var s = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        s.setAttribute("viewBox", "0 0 24 24"); s.setAttribute("width", "24"); s.setAttribute("height", "24");
        s.setAttribute("fill", "none"); s.setAttribute("stroke", "currentColor"); s.setAttribute("stroke-width", "2");
        s.setAttribute("stroke-linecap", "round"); s.setAttribute("stroke-linejoin", "round"); s.setAttribute("aria-hidden", "true");
        s.innerHTML = paths[name] || "";          // static icon paths defined above; never user data
        return s;
    };

    FK.api = async function (path, options) {
        options = options || {};
        var init = { method: options.method || "GET", headers: {} };
        if (options.body !== undefined) { init.headers["Content-Type"] = "application/json"; init.body = JSON.stringify(options.body); }
        try {
            var res = await fetch(path, init);
            var data = await res.json().catch(function () { return {}; });
            return { ok: res.ok && data.success !== false, status: res.status, data: data };
        } catch (e) {
            return { ok: false, status: 0, data: { message: FK.t("common.error") } };
        }
    };

    var mePromise = null;
    FK.me = function () {
        if (!mePromise) {
            mePromise = FK.api("/api/auth/me").then(function (r) {
                if (r.ok && r.data.user) {
                    if (r.data.user.role !== "student") { window.location.href = "staff.html"; return null; }
                    return r.data.user;
                }
                return null;
            });
        }
        return mePromise;
    };

    FK.date = function (value) {
        if (!value) { return ""; }
        var s = String(value);
        var d = new Date(s.length <= 10 ? s + "T00:00:00Z" : s.replace(" ", "T") + "Z");
        if (isNaN(d.getTime())) { return s; }
        try { return d.toLocaleDateString(FK.lang() === "en" ? "en-GB" : "am-ET", { year: "numeric", month: "short", day: "numeric", timeZone: "Africa/Addis_Ababa" }); }
        catch (e) { return d.toLocaleDateString(); }
    };

    FK.message = function (container, text, kind, retry) {
        container.textContent = "";
        var box = FK.el("div", { class: "fk-state " + (kind || "") }, [FK.el("p", { text: text })]);
        if (retry) {
            var b = FK.el("button", { type: "button", class: "fk-btn ghost", text: FK.t("common.retry") });
            b.addEventListener("click", retry); box.appendChild(b);
        }
        container.appendChild(box);
    };

    FK.ready = function (fn) { if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", fn); } else { fn(); } };

    // Renders the lesson text format (blank-line separated blocks: "## heading", "- item", "> quotation", else paragraph).
    FK.renderRichText = function (text, into) {
        var blocks = String(text || "").split(/\n\s*\n/), list = null;
        blocks.forEach(function (b) {
            b = b.trim(); if (!b) { return; }
            if (b.indexOf("- ") === 0) {
                if (!list) { list = FK.el("ul", { class: "fk-bullets" }); into.appendChild(list); }
                list.appendChild(FK.el("li", { text: b.slice(2) })); return;
            }
            list = null;
            if (b.indexOf("## ") === 0) { into.appendChild(FK.el("h3", { class: "fk-rt-h", text: b.slice(3) })); }
            else if (b.indexOf("> ") === 0) { into.appendChild(FK.el("blockquote", { class: "fk-rt-quote", text: b.slice(2) })); }
            else { into.appendChild(FK.el("p", { text: b })); }
        });
    };

    /* ---------- navigation ---------- */
    var NAV = [
        { href: "student-dashboard.html", icon: "home", key: "nav.home", match: ["student-dashboard.html", "index.html"] },
        { href: "lessons.html", icon: "book", key: "nav.lessons", match: ["lessons.html", "lesson.html"] },
        { href: "tasks.html", icon: "tasks", key: "nav.tasks", match: ["tasks.html"] },
        { href: "quiz-me.html", icon: "quiz", key: "nav.quiz", match: ["quiz-me.html"] },
        { href: "exam-prep.html", icon: "target", key: "nav.exam", match: ["exam-prep.html"] },
        { href: "progress.html", icon: "chart", key: "nav.progress", match: ["progress.html"] }
    ];
    function page() { var p = window.location.pathname.split("/").pop(); return p === "" ? "index.html" : p; }

    function buildNav(bottom) {
        var nav = FK.el("nav", { class: bottom ? "fk-bottom-nav" : "", "aria-label": FK.t("nav.main") });
        NAV.forEach(function (i) {
            var a = bottom
                ? FK.el("a", { href: i.href }, [FK.el("span", { class: "fk-ico" }, [FK.svg(i.icon)]), FK.el("span", { class: "fk-lbl", text: FK.t(i.key) })])
                : FK.el("a", { href: i.href }, [FK.svg(i.icon), FK.el("span", { text: FK.t(i.key) })]);
            if (i.match.indexOf(page()) !== -1) { a.setAttribute("aria-current", "page"); }
            nav.appendChild(a);
        });
        return nav;
    }

    /* ---------- popups (one open at a time) ---------- */
    var layer, openName = null, panels = {}, buttons = {};

    function closePanels() {
        Object.keys(panels).forEach(function (k) { panels[k].hidden = true; if (buttons[k]) { buttons[k].setAttribute("aria-expanded", "false"); } });
        openName = null;
    }
    function togglePanel(name, onOpen) {
        if (openName === name) { closePanels(); return; }
        closePanels();
        panels[name].hidden = false; buttons[name].setAttribute("aria-expanded", "true"); openName = name;
        if (onOpen) { onOpen(); }
    }

    function openModal(title, content) {
        var overlay = FK.el("div", { class: "fk-modal", role: "dialog", "aria-modal": "true", "aria-label": title });
        var box = FK.el("div", { class: "fk-modal-box" });
        var head = FK.el("div", { class: "fk-modal-head" }, [FK.el("h2", { text: title })]);
        var x = FK.el("button", { type: "button", class: "fk-iconbtn", "aria-label": FK.t("common.close") }, [FK.svg("close")]);
        head.appendChild(x); box.appendChild(head); box.appendChild(content); overlay.appendChild(box);
        function close() { overlay.remove(); document.removeEventListener("keydown", onKey); }
        function onKey(e) { if (e.key === "Escape") { close(); } }
        x.addEventListener("click", close);
        overlay.addEventListener("click", function (e) { if (e.target === overlay) { close(); } });
        document.addEventListener("keydown", onKey);
        document.body.appendChild(overlay);
        var first = box.querySelector("input,textarea,button.fk-btn"); if (first) { first.focus(); }
        return close;
    }

    function field(id, label, input) { return FK.el("div", { class: "fk-field" }, [FK.el("label", { "for": id, text: label }), input]); }

    function openPasswordDialog() {
        var form = FK.el("form", { autocomplete: "off" });
        var msg = FK.el("p", { class: "fk-inline-msg", role: "alert" });
        var save = FK.el("button", { type: "submit", class: "fk-btn", text: FK.t("pw.save") });
        form.appendChild(field("pw-cur", FK.t("pw.current"), FK.el("input", { id: "pw-cur", type: "password", autocomplete: "current-password", required: true })));
        form.appendChild(field("pw-new", FK.t("pw.new"), FK.el("input", { id: "pw-new", type: "password", autocomplete: "new-password", minlength: "8", required: true })));
        form.appendChild(field("pw-conf", FK.t("pw.confirm"), FK.el("input", { id: "pw-conf", type: "password", autocomplete: "new-password", minlength: "8", required: true })));
        form.appendChild(save); form.appendChild(msg);
        form.addEventListener("submit", async function (e) {
            e.preventDefault();
            var cur = document.getElementById("pw-cur").value, nw = document.getElementById("pw-new").value, cf = document.getElementById("pw-conf").value;
            if (nw !== cf) { msg.textContent = FK.t("pw.mismatch"); msg.className = "fk-inline-msg error"; return; }
            save.disabled = true; msg.textContent = "";
            var r = await FK.api("/api/auth/change-password", { method: "POST", body: { current_password: cur, new_password: nw } });
            save.disabled = false;
            if (r.ok) { msg.textContent = FK.t("pw.ok"); msg.className = "fk-inline-msg ok"; form.reset(); }
            else { msg.textContent = r.data.message || FK.t("pw.fail"); msg.className = "fk-inline-msg error"; }
        });
        openModal(FK.t("set.password"), form);
    }

    function openPermissionDialog() {
        var wrap = FK.el("div");
        var form = FK.el("form", { autocomplete: "off" });
        var msg = FK.el("p", { class: "fk-inline-msg", role: "alert" });
        var today = new Date().toISOString().slice(0, 10);
        var date = FK.el("input", { id: "pr-date", type: "date", min: today, value: today, required: true });
        var reason = FK.el("textarea", { id: "pr-reason", rows: "3", maxlength: "500", required: true });
        var send = FK.el("button", { type: "submit", class: "fk-btn", text: FK.t("perm.send") });
        form.appendChild(field("pr-date", FK.t("perm.date"), date));
        form.appendChild(field("pr-reason", FK.t("perm.reason"), reason));
        form.appendChild(send); form.appendChild(msg);
        var history = FK.el("div", { class: "fk-perm-list" });
        wrap.appendChild(form);
        wrap.appendChild(FK.el("h3", { class: "fk-rt-h", text: FK.t("perm.history") }));
        wrap.appendChild(history);

        async function loadHistory() {
            var r = await FK.api("/api/permissions");
            history.textContent = "";
            if (!r.ok) { history.appendChild(FK.el("p", { class: "fk-task-meta", text: FK.t("common.error") })); return; }
            if (!r.data.requests.length) { history.appendChild(FK.el("p", { class: "fk-task-meta", text: FK.t("perm.none") })); return; }
            r.data.requests.forEach(function (q) {
                history.appendChild(FK.el("div", { class: "fk-attempt" }, [
                    FK.el("div", {}, [FK.el("strong", { text: FK.date(q.request_date) }), FK.el("div", { class: "fk-task-meta", text: q.reason }), q.review_note ? FK.el("div", { class: "fk-task-meta", text: q.review_note }) : null]),
                    FK.el("span", { class: "fk-pill " + (q.status === "approved" ? "pass" : (q.status === "declined" ? "fail" : "wait")), text: FK.t("perm." + q.status) })
                ]));
            });
        }
        form.addEventListener("submit", async function (e) {
            e.preventDefault(); send.disabled = true; msg.textContent = "";
            var r = await FK.api("/api/permissions", { method: "POST", body: { request_date: date.value, reason: reason.value.trim() } });
            send.disabled = false;
            if (r.ok) { msg.textContent = FK.t("perm.sent"); msg.className = "fk-inline-msg ok"; reason.value = ""; loadHistory(); }
            else { msg.textContent = r.data.message || FK.t("common.error"); msg.className = "fk-inline-msg error"; }
        });
        openModal(FK.t("perm.title"), wrap);
        loadHistory();
    }

    /* ---------- panels ---------- */
    function buildBellPanel(badge) {
        var p = FK.el("div", { class: "fk-pop", hidden: true, role: "region", "aria-label": FK.t("bell.title") });
        panels.bell = p;
        async function load() {
            p.textContent = "";
            p.appendChild(FK.el("div", { class: "fk-pop-head" }, [FK.el("strong", { text: FK.t("bell.title") })]));
            var r = await FK.api("/api/notifications");
            if (!r.ok || !r.data.notifications.length) { p.appendChild(FK.el("p", { class: "fk-empty", text: r.ok ? FK.t("bell.none") : FK.t("common.error") })); return; }
            var all = FK.el("button", { type: "button", class: "fk-linkbtn", text: FK.t("bell.markAll") });
            all.addEventListener("click", async function () { await FK.api("/api/notifications/read-all", { method: "POST", body: {} }); setBadge(0); load(); });
            p.firstChild.appendChild(all);
            r.data.notifications.forEach(function (n) {
                var item = FK.el("button", { type: "button", class: "fk-note-item" + (n.is_read ? "" : " unread") }, [
                    FK.el("strong", { text: n.title }), n.body ? FK.el("span", { text: n.body }) : null, FK.el("small", { text: FK.date(n.created_at) })
                ]);
                item.addEventListener("click", async function () {
                    if (!n.is_read) { n.is_read = true; item.classList.remove("unread"); await FK.api("/api/notifications/" + n.id + "/read", { method: "POST", body: {} }); refreshBadge(); }
                });
                p.appendChild(item);
            });
        }
        p.__load = load; return p;
    }
    function setBadge(n) {
        var b = document.getElementById("fk-bell-badge"); if (!b) { return; }
        b.textContent = n > 9 ? "9+" : String(n); b.hidden = n <= 0;
    }
    async function refreshBadge() { var r = await FK.api("/api/notifications"); if (r.ok) { setBadge(r.data.unread); } }

    function buildProfilePanel() {
        var p = FK.el("div", { class: "fk-pop", hidden: true, role: "region", "aria-label": FK.t("me.title") });
        panels.profile = p;
        p.__load = async function () {
            p.textContent = "";
            var u = await FK.me(); if (!u) { return; }
            p.appendChild(FK.el("div", { class: "fk-pop-head" }, [FK.el("strong", { text: u.name })]));
            var dl = FK.el("dl", { class: "fk-dl", style: "padding:0 16px" });
            [["me.id", u.id], ["me.grade", u.grade], ["me.class", u["class"] || "—"]].forEach(function (x) { dl.appendChild(FK.el("dt", { text: FK.t(x[0]) })); dl.appendChild(FK.el("dd", { text: x[1] })); });
            p.appendChild(dl);
            var box = FK.el("div", { class: "fk-att" }, [FK.el("strong", { text: FK.t("me.attendance") })]);
            p.appendChild(box);
            var r = await FK.api("/api/attendance");
            if (!r.ok || r.data.total === 0) { box.appendChild(FK.el("p", { class: "fk-task-meta", text: r.ok ? FK.t("att.none") : FK.t("common.error") })); }
            else {
                var g = FK.el("div", { class: "fk-att-grid" });
                ["present", "absent", "late", "permission"].forEach(function (k) { g.appendChild(FK.el("div", {}, [FK.el("b", { text: String(r.data.counts[k]) }), FK.el("small", { text: FK.t("att." + k) })])); });
                box.appendChild(g);
                box.appendChild(FK.el("p", { class: "fk-task-meta", text: FK.t("att.percent") + ": " + r.data.percent + "%" }));
            }
            var ask = FK.el("button", { type: "button", class: "fk-btn gold block", style: "margin:12px 16px;width:calc(100% - 32px)", text: FK.t("perm.ask") });
            ask.addEventListener("click", function () { closePanels(); openPermissionDialog(); });
            p.appendChild(ask);
        };
        return p;
    }

    function buildSettingsPanel() {
        var p = FK.el("div", { class: "fk-pop", hidden: true, role: "region", "aria-label": FK.t("set.title") });
        panels.settings = p;
        p.appendChild(FK.el("div", { class: "fk-pop-head" }, [FK.el("strong", { text: FK.t("set.title") })]));
        var pw = FK.el("button", { type: "button", class: "fk-menuitem", text: FK.t("set.password") });
        pw.addEventListener("click", function () { closePanels(); openPasswordDialog(); });
        var out = FK.el("button", { type: "button", id: "logout-button", class: "fk-menuitem", text: FK.t("set.logout") });
        out.addEventListener("click", async function () {
            out.disabled = true;
            await FK.api("/api/auth/logout", { method: "POST", body: {} });
            sessionStorage.removeItem("student");
            window.location.href = "login.html";
        });
        p.appendChild(pw); p.appendChild(out);
        return p;
    }

    /* ---------- header ---------- */
    function iconButton(name, label, icon) {
        var b = FK.el("button", { type: "button", class: "fk-iconbtn", "aria-label": label, "aria-haspopup": "true", "aria-expanded": "false" }, [FK.svg(icon)]);
        buttons[name] = b; return b;
    }

    function buildHeader(host) {
        host.className = "dashboard-header"; host.textContent = "";
        layer = FK.el("div", { class: "fk-pop-layer" });

        var lang = FK.el("div", { class: "fk-lang-toggle", role: "group", "aria-label": FK.t("common.language") });
        [["am", "አማ"], ["en", "EN"]].forEach(function (p) {
            var b = FK.el("button", { type: "button", text: p[1], lang: p[0], "aria-pressed": FK.lang() === p[0] ? "true" : "false" });
            b.addEventListener("click", function () { if (FK.lang() !== p[0]) { FK.setLang(p[0]); } });
            lang.appendChild(b);
        });

        var bell = iconButton("bell", FK.t("bell.title"), "bell");
        var badge = FK.el("span", { class: "fk-badge", id: "fk-bell-badge", hidden: true, "aria-hidden": "true" }); bell.appendChild(badge);
        var avatar = FK.el("button", { type: "button", class: "profile-avatar", "aria-label": FK.t("me.title"), "aria-haspopup": "true", "aria-expanded": "false" }, [FK.svg("user")]);
        buttons.profile = avatar;
        var gear = iconButton("settings", FK.t("set.title"), "gear");

        var bellPanel = buildBellPanel(badge), profilePanel = buildProfilePanel(), settingsPanel = buildSettingsPanel();
        [bellPanel, profilePanel, settingsPanel].forEach(function (p) { layer.appendChild(p); });

        bell.addEventListener("click", function (e) { e.stopPropagation(); togglePanel("bell", bellPanel.__load); });
        avatar.addEventListener("click", function (e) { e.stopPropagation(); togglePanel("profile", profilePanel.__load); });
        gear.addEventListener("click", function (e) { e.stopPropagation(); togglePanel("settings"); });
        document.addEventListener("click", function (e) { if (openName && !layer.contains(e.target)) { closePanels(); } });
        document.addEventListener("keydown", function (e) { if (e.key === "Escape") { closePanels(); } });

        host.appendChild(FK.el("a", { class: "logo", href: "student-dashboard.html", text: "ፈለገ ቅዱሳን" }));
        host.appendChild(FK.el("div", { class: "header-actions" }, [lang, bell, avatar, gear]));
        host.appendChild(buildNav(false));
        document.body.appendChild(layer);

        FK.me().then(function (u) { if (u && u.name) { avatar.textContent = Array.from(u.name.trim())[0] || ""; avatar.setAttribute("title", u.name); } });
        refreshBadge();
    }

    /* ---------- floating assistant ---------- */
    function buildAssistant() {
        var KEY = "fk_chat_v2", convo = [];
        try { convo = JSON.parse(sessionStorage.getItem(KEY) || "[]"); } catch (e) { convo = []; }
        function save() { try { sessionStorage.setItem(KEY, JSON.stringify(convo.slice(-30))); } catch (e) {} }

        var fab = FK.el("button", { type: "button", class: "fk-fab", "aria-label": FK.t("as.title"), "aria-expanded": "false", text: "🕊️" });
        var win = FK.el("section", { class: "fk-chat-win", hidden: true, role: "dialog", "aria-label": FK.t("as.title") });
        var closeBtn = FK.el("button", { type: "button", class: "fk-iconbtn", "aria-label": FK.t("common.close") }, [FK.svg("close")]);
        var head = FK.el("div", { class: "fk-chat-head" }, [FK.el("strong", { text: "🕊️ " + FK.t("as.title") }), closeBtn]);
        var note = FK.el("p", { class: "fk-chat-note", text: FK.t("as.mode") });
        var topic = FK.el("select", { id: "fk-topic", "aria-label": FK.t("as.topic") });
        topic.appendChild(FK.el("option", { value: "0", text: FK.t("as.all") }));
        var chips = FK.el("div", { class: "fk-chips", style: "margin:8px 0" });
        var log = FK.el("div", { class: "fk-chat", "aria-live": "polite" });
        var input = FK.el("input", { type: "text", maxlength: "500", placeholder: FK.t("as.placeholder"), "aria-label": FK.t("as.placeholder") });
        var send = FK.el("button", { type: "submit", class: "fk-btn", "aria-label": FK.t("as.send") }, [FK.svg("send")]);
        var form = FK.el("form", { class: "fk-chat-form", autocomplete: "off" }, [input, send]);
        win.appendChild(head); win.appendChild(FK.el("div", { class: "fk-chat-body" }, [note, topic, chips, log])); win.appendChild(form);

        function bubble(m) {
            var b = FK.el("div", { class: "fk-msg " + (m.role === "user" ? "user" : "bot") }, [FK.el("div", { text: m.text })]);
            (m.sources || []).forEach(function (s) {
                var box = FK.el("div", { class: "fk-source" }, [FK.el("strong", { text: s.title })]);
                if (s.snippet) { box.appendChild(FK.el("div", { text: s.snippet })); }
                box.appendChild(s.type === "question" ? FK.el("a", { href: "quiz-me.html", text: FK.t("as.practiceLink") }) : FK.el("a", { href: "lesson.html?id=" + s.lesson_id, text: FK.t("as.openLesson") }));
                b.appendChild(box);
            });
            return b;
        }
        function render() {
            log.textContent = "";
            if (!convo.length) { log.appendChild(FK.el("p", { class: "fk-task-meta", text: FK.t("as.empty") })); return; }
            convo.forEach(function (m) { log.appendChild(bubble(m)); });
            var body = win.querySelector(".fk-chat-body"); body.scrollTop = body.scrollHeight;
        }
        async function ask(mode, text) {
            var lessonId = parseInt(topic.value || "0", 10);
            if (mode !== "ask" && !lessonId) { convo.push({ role: "bot", text: FK.t("as.pickLesson") }); save(); render(); return; }
            convo.push({ role: "user", text: mode === "ask" ? text : FK.t("as." + mode) }); save(); render();
            log.appendChild(FK.el("div", { class: "fk-msg bot fk-typing", text: FK.t("as.thinking") }));
            send.disabled = true;
            var r = await FK.api("/api/assistant/ask", { method: "POST", body: { mode: mode, question: text || "", lesson_id: lessonId, lang: FK.lang() } });
            send.disabled = false;
            convo.push(r.ok ? { role: "bot", text: r.data.answer, sources: r.data.sources } : { role: "bot", text: r.data.message || FK.t("as.err") });
            save(); render();
        }
        [["explain", "as.explain"], ["revise", "as.revise"], ["practice", "as.practice"]].forEach(function (c) {
            var b = FK.el("button", { type: "button", class: "fk-chip", text: FK.t(c[1]) });
            b.addEventListener("click", function () { ask(c[0], ""); }); chips.appendChild(b);
        });
        var clear = FK.el("button", { type: "button", class: "fk-chip", text: FK.t("as.clear") });
        clear.addEventListener("click", function () { convo = []; save(); render(); }); chips.appendChild(clear);
        form.addEventListener("submit", function (e) { e.preventDefault(); var t = input.value.trim(); if (t) { input.value = ""; ask("ask", t); } });

        var loaded = false;
        function open() {
            win.hidden = false; fab.setAttribute("aria-expanded", "true"); render(); input.focus();
            if (!loaded) {
                loaded = true;
                FK.api("/api/learning/path").then(function (p) {
                    if (!p.ok) { return; }
                    p.data.subjects.forEach(function (s) { s.units.forEach(function (u) { u.lessons.forEach(function (l) {
                        if (l.state !== "locked") { topic.appendChild(FK.el("option", { value: String(l.lesson_id), text: l.lesson_number + ". " + l.title })); }
                    }); }); });
                });
            }
        }
        function close() { win.hidden = true; fab.setAttribute("aria-expanded", "false"); fab.focus(); }
        fab.addEventListener("click", function () { if (win.hidden) { open(); } else { close(); } });
        closeBtn.addEventListener("click", close);
        win.addEventListener("keydown", function (e) { if (e.key === "Escape") { close(); } });
        document.body.appendChild(fab); document.body.appendChild(win);
    }

    /* ---------- start ---------- */
    window.showLoginMessage = function (text, kind) {
        var box = document.getElementById("login-message");
        if (!box) { window.alert(text); return; }
        box.textContent = text || ""; box.className = "fk-message " + (kind || "error");
    };

    function init() {
        var host = document.getElementById("fk-header");
        var oldHeader = !host ? document.querySelector("header.dashboard-header") : null;
        if (oldHeader) {                       // quiz page: replace its old header, keep its scripts working
            host = FK.el("header", { id: "fk-header" });
            oldHeader.parentNode.insertBefore(host, oldHeader);
            oldHeader.setAttribute("aria-hidden", "true"); oldHeader.style.display = "none";
        }
        if (host) {
            buildHeader(host);
            document.body.appendChild(buildNav(true)); document.querySelector(".dashboard-header ~ .fk-bottom-nav, body > .fk-bottom-nav");
            document.body.classList.add("fk-has-bottom-nav");
            document.body.appendChild(FK.el("div", { class: "fk-church", "aria-hidden": "true" }));
            buildAssistant();
        }
        FK.applyI18n();
    }
    FK.ready(init);
})();
