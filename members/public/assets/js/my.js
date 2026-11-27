/* =====================================================================
   ፈለገ ቅዱሳን — Members App: my profile, attendance, grades
   =====================================================================
   Binds /api/v1/my/* to the profile, attendance and grades pages, and paints
   the home dashboard summary.

   UI/UX follows the prototype's dashboard: card grid, emoji section headings,
   big stat numbers, gold progress bars. The prototype's markup is not reused
   (it restyled HTML that no longer exists) but its SHAPES are: fk-card,
   fk-stat, fk-bar, fk-pill, fk-empty.

   Same rule as learning.js: this file renders. The server decides what the
   member may see, and every number shown here came from the API.
   ===================================================================== */
(function () {
    "use strict";

    function $(sel, root) { return (root || document).querySelector(sel); }

    function isAm() { return document.documentElement.lang !== "en"; }

    /* Text in the reader's language, falling back to Amharic rather than to an
       empty heading. */
    function pick(am, en) { return (!isAm() && en) ? en : (am || en || ""); }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }

    function card(title) {
        var wrap = el("div", "fk-card");
        if (title) { wrap.appendChild(el("h3", null, title)); }
        return wrap;
    }

    function emptyState(message) {
        return el("p", "fk-empty", message);
    }

    function errorCard(error, retry) {
        var box = el("div", "fk-card fk-state error");
        box.appendChild(el("p", null, (error && error.message) || "Could not load this."));
        if (typeof retry === "function") {
            var button = el("button", "fk-btn ghost", isAm() ? "እንደገና ይሞክሩ" : "Try again");
            button.type = "button";
            button.addEventListener("click", retry);
            box.appendChild(button);
        }
        return box;
    }

    /* Department status is the "active on education / mezmur?" answer. */
    function departmentPill(status) {
        var pill = el("span", "fk-pill");
        var active = status === "active";
        pill.textContent = active
            ? (isAm() ? "ንቁ" : "active")
            : (isAm() ? "ንቁ አይደለም" : "inactive");
        pill.style.color = active ? "var(--fk-ok)" : "var(--fk-bad)";
        return pill;
    }

    /* ---------------------------------------------------------------
       Profile
       --------------------------------------------------------------- */
    async function renderProfile() {
        var root = $("#profile-root");
        if (!root) { return; }

        try {
            var data = await window.FKApi.get("/my/profile");
            var p = data && data.profile;
            if (!p) { throw new Error(isAm() ? "መረጃው አልተገኘም።" : "Profile not found."); }

            var frag = document.createDocumentFragment();

            // ---- identity card ----
            var head = card();
            var row = el("div", "fk-row");
            var avatar = el("div", "fk-ring");
            avatar.style.width = "84px";
            avatar.style.height = "84px";
            avatar.style.setProperty("--p", "0");
            var initial = el("span", "fk-stat", (p.full_name_am || p.student_name || "?").trim().charAt(0));
            initial.style.fontSize = "30px";
            avatar.appendChild(initial);
            row.appendChild(avatar);

            var who = el("div");
            who.appendChild(el("h2", null, pick(p.full_name_am, p.full_name_en)));
            if (p.full_name_en && isAm()) {
                who.appendChild(el("p", "fk-help", p.full_name_en));
            }
            var codeLine = el("p");
            codeLine.appendChild(el("span", "fk-pill", p.member_code));
            who.appendChild(codeLine);
            row.appendChild(who);
            head.appendChild(row);
            frag.appendChild(head);

            // ---- department status: the direct question ----
            var deptCard = card(isAm() ? "🏛 የመምሪያ ሁኔታ" : "🏛 Department status");
            var ds = data.departments || {};
            var deptRow = el("div", "fk-row");
            deptRow.style.gap = "22px";
            [
                ["edu", isAm() ? "ትምህርት" : "Education"],
                ["mezmur", isAm() ? "መዝሙር" : "Mezmur"]
            ].forEach(function (pair) {
                var cell = el("div");
                cell.appendChild(el("p", "fk-help", pair[1]));
                cell.appendChild(departmentPill(ds[pair[0]] || "active"));
                deptRow.appendChild(cell);
            });
            deptCard.appendChild(deptRow);
            if (data.departments_source === "default") {
                deptCard.appendChild(el("p", "fk-help",
                    isAm()
                        ? "ከመምሪያ የተከለከሉ መሆንዎን የሚያሳይ መዝገብ የለም።"
                        : "No department record suspends you, so both are active."));
            }
            frag.appendChild(deptCard);

            // ---- details ----
            var details = card(isAm() ? "📋 የእኔ መረጃ" : "📋 My information");
            var list = el("dl", "fk-list");

            function entry(label, value) {
                if (value === null || value === undefined || value === "") { return; }
                var item = el("div", "fk-row");
                item.style.justifyContent = "space-between";
                item.style.gap = "12px";
                item.appendChild(el("dt", "fk-help", label));
                item.appendChild(el("dd", null, value));
                item.querySelector("dd").style.margin = "0";
                item.querySelector("dd").style.textAlign = "right";
                list.appendChild(item);
            }

            entry(isAm() ? "የአባት ስም" : "Father's name", p.father_name);
            entry(isAm() ? "የአያት ስም" : "Grandfather's name", p.grandfather_name);
            entry(isAm() ? "የክርስትና ስም" : "Christian name", p.christian_name);
            entry(isAm() ? "ጾታ" : "Gender",
                p.gender === "male" ? (isAm() ? "ወንድ" : "male")
                    : p.gender === "female" ? (isAm() ? "ሴት" : "female") : p.gender);
            entry(isAm() ? "የትውልድ ቀን" : "Date of birth", p.date_of_birth);
            entry(isAm() ? "ዕድሜ" : "Age", p.age);
            entry(isAm() ? "ክፍል" : "Section", p.current_section);
            entry(isAm() ? "የትምህርት ደረጃ" : "Education level", p.education_level);
            entry(isAm() ? "የአባልነት ዓይነት" : "Member type", p.member_type);
            entry(isAm() ? "ስልክ" : "Phone", p.contact && p.contact.phone);
            entry(isAm() ? "ኢሜይል" : "Email", p.contact && p.contact.email);

            details.appendChild(list);

            var roles = p.roles || {};
            var activeRoles = [];
            if (roles.teacher) { activeRoles.push(isAm() ? "አስተማሪ" : "teacher"); }
            if (roles.staff) { activeRoles.push(isAm() ? "ሠራተኛ" : "staff"); }
            if (roles.committee) { activeRoles.push(isAm() ? "ኮሚቴ" : "committee"); }
            if (roles.volunteer) { activeRoles.push(isAm() ? "በጎ ፈቃደኛ" : "volunteer"); }
            if (activeRoles.length > 0) {
                var roleLine = el("p", "fk-help", (isAm() ? "ኃላፊነት: " : "Roles: ") + activeRoles.join(" · "));
                roleLine.style.marginTop = "10px";
                details.appendChild(roleLine);
            }
            frag.appendChild(details);

            var note = el("p", "fk-help",
                isAm()
                    ? "ይህ መረጃ ከትምህርት ቤቱ መዝገብ የተገኘ ነው። ስህተት ካለ ቢሮውን ያግኙ።"
                    : "This comes from the school's register. If something is wrong, contact the office.");
            note.style.marginTop = "12px";
            frag.appendChild(note);

            root.replaceChildren(frag);
        } catch (error) {
            root.replaceChildren(errorCard(error, renderProfile));
        }
    }

    /* ---------------------------------------------------------------
       Attendance
       --------------------------------------------------------------- */
    function statusLabel(status) {
        var map = {
            present: { am: "ተገኝቷል", en: "present" },
            absent: { am: "አልተገኘም", en: "absent" },
            late: { am: "ዘግይቷል", en: "late" },
            excused: { am: "ፈቃድ", en: "excused" },
            holiday: { am: "በዓል", en: "holiday" }
        };
        var entry = map[status];
        return entry ? (isAm() ? entry.am : entry.en) : status;
    }

    function statusColor(status) {
        if (status === "present") { return "var(--fk-ok)"; }
        if (status === "late") { return "var(--fk-gold-deep)"; }
        if (status === "absent") { return "var(--fk-bad)"; }
        return "var(--fk-muted)";
    }

    async function renderAttendance() {
        var root = $("#attendance-root");
        if (!root) { return; }

        try {
            var data = await window.FKApi.get("/my/attendance");
            var summary = data.summary || {};
            var records = data.records || [];
            var frag = document.createDocumentFragment();

            var card1 = card(isAm() ? "📊 ማጠቃለያ" : "📊 Summary");
            if (summary.sessions === 0 || summary.percent === null) {
                card1.appendChild(emptyState(
                    isAm() ? "እስካሁን የመገኘት መዝገብ የለም።"
                           : "No attendance has been recorded for you yet."));
            } else {
                card1.appendChild(el("p", "fk-stat", summary.percent + "%"));
                var bar = el("div", "fk-bar");
                var fill = el("span");
                fill.style.width = Math.max(0, Math.min(100, summary.percent)) + "%";
                bar.appendChild(fill);
                card1.appendChild(bar);
                card1.appendChild(el("p", "fk-help",
                    (isAm() ? "የተገኙት " : "Attended ") + summary.attended + " / " + summary.sessions));

                var chips = el("div", "fk-chips");
                [["present", summary.present], ["late", summary.late],
                 ["absent", summary.absent], ["excused", summary.excused]].forEach(function (pair) {
                    if (!pair[1]) { return; }
                    var chip = el("span", "fk-chip", statusLabel(pair[0]) + " " + pair[1]);
                    chip.style.cursor = "default";
                    chip.style.color = statusColor(pair[0]);
                    chips.appendChild(chip);
                });
                card1.appendChild(chips);
            }
            frag.appendChild(card1);

            if (records.length > 0) {
                var card2 = card(isAm() ? "📅 የቅርብ ጊዜ" : "📅 Recent");
                var list = el("ul", "fk-list");
                records.slice(0, 60).forEach(function (record) {
                    var item = el("li", "fk-row");
                    item.style.justifyContent = "space-between";
                    item.appendChild(el("span", null, record.date));
                    var pill = el("span", "fk-pill", statusLabel(record.status));
                    pill.style.color = statusColor(record.status);
                    item.appendChild(pill);
                    list.appendChild(item);
                });
                card2.appendChild(list);
                if (records.length > 60) {
                    card2.appendChild(el("p", "fk-help",
                        isAm() ? "የቅርብ 60 ቀናት ብቻ ታይተዋል።" : "Showing the most recent 60 days."));
                }
                frag.appendChild(card2);
            }

            root.replaceChildren(frag);
        } catch (error) {
            root.replaceChildren(errorCard(error, renderAttendance));
        }
    }

    /* ---------------------------------------------------------------
       Grades
       --------------------------------------------------------------- */
    var ASSESSMENT_LABEL = {
        test: { am: "ፈተና", en: "test" },
        midterm: { am: "መካከለኛ", en: "midterm" },
        final: { am: "የመጨረሻ", en: "final" },
        assignment: { am: "ተግባር", en: "assignment" },
        participation: { am: "ተሳትፎ", en: "participation" },
        project: { am: "ፕሮጀክት", en: "project" }
    };

    function assessmentLabel(type) {
        var entry = ASSESSMENT_LABEL[type];
        return entry ? (isAm() ? entry.am : entry.en) : type;
    }

    async function renderGrades() {
        var root = $("#grades-root");
        if (!root) { return; }

        try {
            var data = await window.FKApi.get("/my/grades");
            var records = data.records || [];
            var summary = data.summary || {};
            var frag = document.createDocumentFragment();

            var card1 = card(isAm() ? "📈 አጠቃላይ" : "📈 Overall");
            if (records.length === 0) {
                card1.appendChild(emptyState(
                    isAm() ? "እስካሁን ውጤት አልተመዘገበም።"
                           : "No grades have been recorded for you yet."));
            } else if (summary.average_percent !== null) {
                card1.appendChild(el("p", "fk-stat", summary.average_percent + "%"));
                card1.appendChild(el("p", "fk-help",
                    isAm() ? ("ከ " + summary.count + " ውጤቶች")
                           : ("across " + summary.count + " results")));
            }
            frag.appendChild(card1);

            if (records.length > 0) {
                var card2 = card(isAm() ? "📝 ውጤቶች" : "📝 Results");
                var list = el("ul", "fk-list");
                records.forEach(function (record) {
                    var item = el("li");
                    var top = el("div", "fk-row");
                    top.style.justifyContent = "space-between";

                    var left = el("div");
                    left.appendChild(el("strong", null, record.subject || (isAm() ? "ትምህርት" : "Subject")));
                    left.appendChild(el("p", "fk-help", assessmentLabel(record.assessment_type)));

                    var right = el("div");
                    right.style.textAlign = "right";
                    var score = el("div", "fk-stat");
                    score.style.fontSize = "22px";
                    score.textContent = record.percent !== null
                        ? record.percent + "%"
                        : (isAm() ? "በመጠባበቅ" : "pending");
                    right.appendChild(score);
                    if (record.grade_letter) {
                        right.appendChild(el("span", "fk-pill", record.grade_letter));
                    }

                    top.appendChild(left);
                    top.appendChild(right);
                    item.appendChild(top);

                    if (record.score !== null && record.max_score) {
                        var bar = el("div", "fk-bar");
                        var fill = el("span");
                        fill.style.width = Math.max(0, Math.min(100, record.percent || 0)) + "%";
                        bar.appendChild(fill);
                        bar.style.marginTop = "8px";
                        item.appendChild(bar);
                        item.appendChild(el("p", "fk-help",
                            record.score + " / " + record.max_score));
                    }

                    list.appendChild(item);
                });
                card2.appendChild(list);
                frag.appendChild(card2);
            }

            root.replaceChildren(frag);
        } catch (error) {
            // A 409 means this database has not run the schema migration. That
            // is not the student's problem and not a bug in the page.
            if (error && error.code === "GRADES_UNAVAILABLE") {
                var box = card();
                box.appendChild(emptyState(
                    isAm() ? "የውጤት ክፍሉ ገና አልተዘጋጀም።"
                           : "Grades are not switched on for this school yet."));
                root.replaceChildren(box);
                return;
            }
            root.replaceChildren(errorCard(error, renderGrades));
        }
    }

    /* ---------------------------------------------------------------
       Home dashboard summary (the prototype's card grid)
       --------------------------------------------------------------- */
    async function renderHomeSummary() {
        var root = $("#home-summary");
        if (!root) { return; }

        try {
            var results = await Promise.all([
                window.FKApi.get("/my/profile").catch(function () { return null; }),
                window.FKApi.get("/my/attendance").catch(function () { return null; })
            ]);
            var profile = results[0];
            var attendance = results[1];
            var frag = document.createDocumentFragment();

            var grid = el("div", "fk-grid two");

            // Department status card — the question asked most often.
            var dept = card(isAm() ? "🏛 የመምሪያ ሁኔታ" : "🏛 Department status");
            var ds = (profile && profile.departments) || null;
            if (ds) {
                var r = el("div", "fk-row");
                r.style.gap = "20px";
                [["edu", isAm() ? "ትምህርት" : "Education"],
                 ["mezmur", isAm() ? "መዝሙር" : "Mezmur"]].forEach(function (pair) {
                    var cell = el("div");
                    cell.appendChild(el("p", "fk-help", pair[1]));
                    cell.appendChild(departmentPill(ds[pair[0]] || "active"));
                    r.appendChild(cell);
                });
                dept.appendChild(r);
            } else {
                dept.appendChild(emptyState(isAm() ? "መረጃው አልተገኘም።" : "Not available right now."));
            }
            grid.appendChild(dept);

            // Attendance card.
            var att = card(isAm() ? "📊 የመገኘት ሁኔታ" : "📊 Attendance");
            var summary = attendance && attendance.summary;
            if (summary && summary.percent !== null) {
                att.appendChild(el("p", "fk-stat", summary.percent + "%"));
                var bar = el("div", "fk-bar");
                var fill = el("span");
                fill.style.width = Math.max(0, Math.min(100, summary.percent)) + "%";
                bar.appendChild(fill);
                att.appendChild(bar);
            } else {
                att.appendChild(emptyState(isAm() ? "እስካሁን መዝገብ የለም።" : "Nothing recorded yet."));
            }
            var attLink = el("a", "fk-btn ghost", isAm() ? "ዝርዝር" : "Details");
            attLink.setAttribute("href", "/members/attendance");
            att.appendChild(attLink);
            grid.appendChild(att);

            frag.appendChild(grid);
            root.replaceChildren(frag);
        } catch (error) {
            root.replaceChildren();
        }
    }

    function start() {
        renderProfile();
        renderAttendance();
        renderGrades();
        renderHomeSummary();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }
})();
