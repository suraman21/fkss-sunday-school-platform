/* =====================================================================
   ፈለገ ቅዱሳን — Members App: learning pages
   =====================================================================
   Binds the lessons list and the lesson reader to /api/v1/learning.

   RULE: this file renders and navigates. It never decides whether a lesson is
   open. The API answers `open` on every lesson, and a locked lesson is 403 from
   the server if opened directly. A client that "helpfully" computed its own
   unlock state would be wrong the moment a teacher granted an unlock, and it
   would be the kind of wrong that looks like a bug report about the app.

   No inline handlers: the CSP forbids them, so everything is delegated from
   data-* attributes.
   ===================================================================== */
(function () {
    "use strict";

    function $(sel, root) { return (root || document).querySelector(sel); }

    function toast(msg) {
        if (window.FKUi) { window.FKUi.toast(msg); }
    }

    /* Text in the reader's language, from the API's dual-language fields.
       Falls back to Amharic: a title in the wrong language is readable, an
       empty heading is not. */
    function pick(am, en) {
        var lang = document.documentElement.lang;
        if (lang === "en" && en) { return en; }
        return am || en || "";
    }

    function esc(value) {
        var div = document.createElement("div");
        div.textContent = value === null || value === undefined ? "" : String(value);
        return div.innerHTML;
    }

    /* Build DOM rather than HTML strings. Every value here comes from the
       database; innerHTML with escaped strings is one missed esc() away from
       an injection, and building nodes makes that impossible. */
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }

    var LOCK_TEXT = {
        previous_unit_incomplete: {
            am: "የቀደመውን ክፍል ጨርሰው አልቀውም።",
            en: "Finish the previous unit first."
        },
        previous_quiz_not_passed: {
            am: "የቀደመውን ክፍል ፈተና ማለፍ ይኖርብዎታል።",
            en: "Pass the previous unit's quiz first."
        },
        awaiting_teacher_unlock: {
            am: "አስተማሪዎ ይህን ክፍል እስኪከፍት ይጠብቁ።",
            en: "Waiting for your teacher to open this unit."
        }
    };

    function lockMessage(reason) {
        var entry = LOCK_TEXT[reason];
        if (!entry) { return ""; }
        return document.documentElement.lang === "en" ? entry.en : entry.am;
    }

    /* ---------------------------------------------------------------
       Lessons list
       --------------------------------------------------------------- */
    async function renderPath() {
        var root = $("#path-root");
        if (!root) { return; }

        try {
            var data = await window.FKApi.get("/learning/path");
            var subjects = (data && data.subjects) || [];
            var summary = (data && data.summary) || null;

            if (subjects.length === 0) {
                root.replaceChildren();
                var empty = el("div", "fk-card");
                empty.appendChild(el("p", "fk-empty",
                    document.documentElement.lang === "en"
                        ? "No subjects have been published for your class yet."
                        : "ለክፍልዎ እስካሁን ትምህርት አልተለቀቀም።"));
                root.appendChild(empty);
                return;
            }

            var frag = document.createDocumentFragment();

            if (summary) {
                var bar = el("div", "fk-card");
                bar.style.marginBottom = "18px";
                bar.appendChild(el("h3", null,
                    document.documentElement.lang === "en" ? "Your progress" : "የእርስዎ ውጤት"));
                bar.appendChild(el("p", "fk-stat", summary.percent + "%"));
                var track = el("div", "fk-bar");
                var fill = el("span");
                fill.style.width = Math.max(0, Math.min(100, summary.percent)) + "%";
                track.appendChild(fill);
                bar.appendChild(track);
                bar.appendChild(el("p", "fk-help",
                    summary.lessons_completed + " / " + summary.lessons_total + " " +
                    (document.documentElement.lang === "en" ? "lessons complete" : "ትምህርቶች ተጠናቅቀዋል")));
                frag.appendChild(bar);
            }

            subjects.forEach(function (subject) {
                var head = el("h2", "fk-section-title", pick(subject.name_am, subject.name_en));
                frag.appendChild(head);

                (subject.units || []).forEach(function (unit) {
                    var unitHead = el("div", "fk-unit-head");
                    var title = el("div");
                    title.appendChild(el("strong", null,
                        (document.documentElement.lang === "en" ? "Unit " : "ክፍል ") + unit.number));
                    title.appendChild(document.createTextNode(" · " + pick(unit.title_am, unit.title_en)));
                    unitHead.appendChild(title);
                    unitHead.appendChild(el("span", "fk-pill",
                        unit.open
                            ? (document.documentElement.lang === "en" ? "open" : "ክፍት")
                            : (document.documentElement.lang === "en" ? "locked" : "ተቆልፏል")));
                    frag.appendChild(unitHead);

                    var list = el("div", "fk-list");
                    (unit.lessons || []).forEach(function (lesson) {
                        var row = el("a", "fk-node");
                        row.setAttribute("href", "/members/lesson/" + lesson.id);
                        row.style.textDecoration = "none";
                        row.style.marginTop = "10px";

                        var label = el("span", null,
                            lesson.number + ". " + pick(lesson.title_am, lesson.title_en));
                        row.appendChild(label);

                        if (lesson.completed) {
                            var done = el("span", "fk-pill");
                            done.textContent = document.documentElement.lang === "en" ? "done" : "ተጠናቋል";
                            done.style.marginLeft = "8px";
                            done.style.color = "var(--fk-ok)";
                            row.appendChild(done);
                        } else if (!lesson.open) {
                            row.setAttribute("aria-disabled", "true");
                            row.setAttribute("tabindex", "-1");
                            var lock = el("span", "fk-pill");
                            lock.textContent = document.documentElement.lang === "en" ? "locked" : "ተቆልፏል";
                            lock.style.marginLeft = "8px";
                            row.appendChild(lock);
                        }
                        list.appendChild(row);
                    });
                    if ((unit.lessons || []).length === 0) {
                        list.appendChild(el("p", "fk-empty",
                            document.documentElement.lang === "en"
                                ? "No lessons in this unit yet."
                                : "በዚህ ክፍል ውስጥ እስካሁን ትምህርት የለም።"));
                    }
                    frag.appendChild(list);

                    if (unit.locked_reason) {
                        var note = el("p", "fk-help", lockMessage(unit.locked_reason));
                        note.style.marginTop = "8px";
                        frag.appendChild(note);
                    }
                });
            });

            root.replaceChildren(frag);
        } catch (error) {
            root.replaceChildren();
            var box = el("div", "fk-card fk-state error");
            box.appendChild(el("p", null, error.message || "Could not load your lessons."));
            var retry = el("button", "fk-btn ghost", "Try again");
            retry.type = "button";
            retry.addEventListener("click", renderPath);
            box.appendChild(retry);
            root.appendChild(box);
        }
    }

    /* ---------------------------------------------------------------
       Lesson reader
       --------------------------------------------------------------- */
    async function renderLesson() {
        var root = $("#lesson-root");
        if (!root) { return; }
        var lessonId = root.getAttribute("data-lesson-id");
        if (!lessonId) { return; }

        try {
            var data = await window.FKApi.get("/learning/lessons/" + encodeURIComponent(lessonId));
            var lesson = data && data.lesson;
            if (!lesson) { throw new Error("Lesson not found."); }

            var crumbs = el("p", "fk-crumbs", pick(lesson.subject.name_am, lesson.subject.name_am) +
                " · " + pick(lesson.unit.title_am, lesson.unit.title_am));
            var h1 = el("h1", "fk-title", pick(lesson.title_am, lesson.title_en));
            var body = el("div", "fk-lesson-text", lesson.body_am || "");
            body.style.whiteSpace = "pre-wrap";

            var wrap = el("div", "fk-card");
            wrap.appendChild(crumbs);
            wrap.appendChild(h1);
            if (lesson.est_minutes) {
                wrap.appendChild(el("p", "fk-help", lesson.est_minutes +
                    (document.documentElement.lang === "en" ? " min read" : " ደቂቃ ንባብ")));
            }
            wrap.appendChild(body);

            var actions = el("div", "fk-btn-row");
            actions.style.marginTop = "18px";

            var alreadyDone = data.progress && data.progress.status === "completed";
            if (!alreadyDone) {
                var doneBtn = el("button", "fk-btn", 
                    document.documentElement.lang === "en" ? "Mark complete" : "ተጠናቋል");
                doneBtn.type = "button";
                doneBtn.setAttribute("data-complete-lesson", lesson.id);
                actions.appendChild(doneBtn);
            } else {
                var doneNote = el("p", "fk-note",
                    document.documentElement.lang === "en"
                        ? "You have completed this lesson."
                        : "ይህን ትምህርት ጨርሰዋል።");
                actions.appendChild(doneNote);
            }

            var back = el("a", "fk-btn ghost",
                document.documentElement.lang === "en" ? "Back to lessons" : "ወደ ትምህርቶች");
            back.setAttribute("href", "/members/lessons");
            actions.appendChild(back);

            root.replaceChildren(wrap, actions);
        } catch (error) {
            root.replaceChildren();
            var box = el("div", "fk-card fk-state error");

            if (error && error.code === "LESSON_LOCKED") {
                box.appendChild(el("p", null,
                    document.documentElement.lang === "en"
                        ? "This lesson is not open yet. Finish the earlier lessons, or ask your teacher to unlock it."
                        : "ይህ ትምህርት ገና አልተከፈተም።"));
            } else {
                box.appendChild(el("p", null, error.message || "Could not load this lesson."));
            }

            var backBtn = el("a", "fk-btn ghost",
                document.documentElement.lang === "en" ? "Back to lessons" : "ወደ ትምህርቶች");
            backBtn.setAttribute("href", "/members/lessons");
            box.appendChild(backBtn);
            root.appendChild(box);
        }
    }

    /* ---------------------------------------------------------------
       Mark complete (delegated)
       --------------------------------------------------------------- */
    document.addEventListener("click", async function (event) {
        var target = event.target;
        if (!target || !target.closest) { return; }
        var button = target.closest("[data-complete-lesson]");
        if (!button) { return; }

        event.preventDefault();
        var lessonId = button.getAttribute("data-complete-lesson");
        button.disabled = true;

        try {
            var result = await window.FKApi.post(
                "/learning/lessons/" + encodeURIComponent(lessonId) + "/complete"
            );
            toast(result && result.already_completed
                ? (document.documentElement.lang === "en" ? "Already complete." : "ቀድሞም ተጠናቋል።")
                : (document.documentElement.lang === "en" ? "Lesson complete." : "ትምህርቱ ተጠናቋል።"));

            button.replaceWith(el("p", "fk-note",
                document.documentElement.lang === "en"
                    ? "You have completed this lesson."
                    : "ይህን ትምህርት ጨርሰዋል።"));
        } catch (error) {
            button.disabled = false;
            toast(error.message || "Could not save. Try again.");
        }
    });

    /* --------------------------------------------------------------- */
    function start() {
        renderPath();
        renderLesson();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }
})();
