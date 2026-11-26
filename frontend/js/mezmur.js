/**
 * ════════════════════════════════════════════════════════════
 * Mezmur Department (መዝሙር ክፍል) — front-end controller
 *   • Overview • Hymn Library • Attendance (section-first,
 *     teachers/edu workflow clone) • Analytics • Takers
 *
 * Phase-5 performance model (big-company pattern):
 *   1. LAZY TABS — each section loads its data on first
 *      activation only; nothing fires on DOMContentLoaded except
 *      the active tab.
 *   2. BATCHED OVERVIEW — one `action=overview` round trip for
 *      every overview widget (BFF pattern), not 8 parallel GETs.
 *   3. BOUNDED GETS — every read races a 12 s timeout so a
 *      skeleton can NEVER animate forever; timeout renders the
 *      error state with Retry.
 *
 * Pure UI layer for frontend/pages/mezmur_dept.php. Every data
 * access goes through /backend/api/mezmur.php (shim →
 * admin/api_mezmur.php) which re-validates session, role, CSRF,
 * rate-limits and every input server-side.
 *
 * Security conventions (same as finance.js):
 *   - All dynamic values HTML-escaped via esc() before innerHTML;
 *     free-form long text uses textContent only.
 *   - All mutations are POST (CSRF auto-appended by window.api).
 *   - All lists are server-side paginated (scale-safe).
 * ════════════════════════════════════════════════════════════
 */
(function () {
    'use strict';

    var PAGE_SIZE = 25;
    var GET_TIMEOUT = 12000; // ms — skeletons may never outlive this

    // ── shared helpers ─────────────────────────────────────────
    function $(id) { return document.getElementById(id); }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function fmtDate(s, fmt) {
        if (!s) return '—';
        if (typeof WBWSCalendar !== 'undefined' && typeof WBWSCalendar.formatDate === 'function') {
            return WBWSCalendar.formatDate(s, fmt || 'long');
        }
        var d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d.getTime())) return esc(s);
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    // EC date + 24h time for timestamps (exports / lists); empty → ''.
    function fmtDateTime(s) {
        if (!s) return '';
        var iso = String(s).replace(' ', 'T');
        if (typeof WBWSCalendar !== 'undefined' && typeof WBWSCalendar.formatDateTime === 'function') {
            return WBWSCalendar.formatDateTime(iso);
        }
        return String(s);
    }

    function sectionBadge(sectionName) {
        if (!sectionName || sectionName === '—') {
            return '<span class="section-badge section-badge-other">—</span>';
        }
        var s = String(sectionName).trim();
        if (s.indexOf('ህጻናት') !== -1 || s.indexOf('ህፃናት') !== -1 || s === 'A' || s.toLowerCase() === 'children') {
            return '<span class="section-badge section-badge-a"><span class="section-badge-dot"></span>ህጻናት (A) <span style="opacity:0.75;font-size:0.7rem;font-weight:normal">7–13</span></span>';
        }
        if (s.indexOf('ማዕከላዊ') !== -1 || s.indexOf('ማእከላዊ') !== -1 || s === 'B' || s.toLowerCase() === 'intermediate' || s.toLowerCase() === 'middle') {
            return '<span class="section-badge section-badge-b"><span class="section-badge-dot"></span>ማዕከላዊያን (B) <span style="opacity:0.75;font-size:0.7rem;font-weight:normal">14–17</span></span>';
        }
        if (s.indexOf('ወጣት') !== -1 || s === 'C' || s.toLowerCase() === 'youth' || s.toLowerCase() === 'parish') {
            return '<span class="section-badge section-badge-c"><span class="section-badge-dot"></span>ወጣቶች (C) <span style="opacity:0.75;font-size:0.7rem;font-weight:normal">18+</span></span>';
        }
        return '<span class="section-badge section-badge-other">' + esc(s) + '</span>';
    }

    function showError(el, msg) {
        el.textContent = msg || '';
        el.classList.toggle('is-hidden', !msg);
    }

    function pctLabel(v) { return v == null ? '—' : (Math.round(v * 10) / 10) + '%'; }

    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    // ── in-system confirm dialog (P31: the system never uses browser
    //    popups — confirm/prompt/alert are replaced by styled UI) ──
    var sysDialogCb = null;
    // Danger confirmation (DC-4): routed through the shared 3-step dialog
    // (admin/js/danger_confirm.js). `opts` sets the tier; without it the call
    // is LOW. HIGH/MEDIUM never fall back to a weaker one-click dialog.
    function sysConfirm(body, onYes, opts) {
        if (window.DangerConfirm && window.DangerConfirm.ask) {
            var o = opts || { tier: 'low', title: body, confirmLabel: 'Continue' };
            if (!o.title) o.title = body;
            window.DangerConfirm.ask(o).then(function (r) { if (r && onYes) onYes(r); });
            return;
        }
        if (opts && opts.tier && opts.tier !== 'low') { window.toast('The confirmation dialog did not load. Refresh the page (Ctrl+F5) and try again.', 'e'); return; }
        var el = $('mzSysDialog');
        if (!el) { window.toast('Confirmation is unavailable. Please refresh the page.', 'e'); return; }
        $('mzSysDialogBody').textContent = body;
        sysDialogCb = onYes || null;
        openModalF('mzSysDialog');
    }
    // Inline editors behave like established desktop software: Enter
    // commits, Escape abandons (focus ring already global).
    function initInlineKeys() {
        document.addEventListener('keydown', function (e) {
            var t = e.target;
            if (!t || !t.id) return;
            var isEdit = ['mzMgrEditName', 'mzMgrSubName'].indexOf(t.id) >= 0;
            if (!isEdit) return;
            if (e.key === 'Enter') {
                e.preventDefault();
                var row = t.closest('.mz-mgr-edit');
                if (row) { var btn = row.querySelector('.btn-primary'); if (btn) btn.click(); }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                mgrCancel();
            }
        });
    }

    function initSysDialog() {
        var el = $('mzSysDialog');
        if (!el || el.dataset.p31) return;
        el.dataset.p31 = '1';
        $('mzSysDialogYes').addEventListener('click', function () {
            var cb = sysDialogCb; sysDialogCb = null;
            closeModalF('mzSysDialog');
            if (cb) cb();
        });
        $('mzSysDialogNo').addEventListener('click', function () {
            sysDialogCb = null;
            closeModalF('mzSysDialog');
        });
    }

    // ── schema reconciliation (one-click migration) ──────────
    function migrateSchema() {
        sysConfirm('Align the mezmur database schema with the current code? This is safe to run at any time.', migrateRun,
            { tier: 'low', title: 'Update the mezmur database structure?', summary: 'Aligns the mezmur tables with the current code. Safe to run at any time; no hymns are removed.', confirmLabel: 'Run update' });
    }
    function migrateRun() {
        apiPost({ action: 'migrate' }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Schema sync failed.', 'e'); return; }
            var applied = (d.applied || []).length;
            var failed = Object.keys(d.failed || {}).length;
            window.toast(d.message || ('Schema synced (' + applied + ' change(s)).'), failed ? 'e' : 's');
            if (failed) console.error('mezmur migrate failures:', d.failed);
            if (typeof Mezmur.loadOverview === 'function') Mezmur.loadOverview();
        }).catch(function (err) {
            window.toast(((err && err.message) || 'Connection error.') + staleHint(err), 'e');
        });
    }

    // ── bounded API access (the skeleton-forever fix) ─────────
    //
    // P43 — DATABASE FLOOD PROTECTION.
    //
    // The debounce alone was not enough. At 160ms a continuous typist
    // issues ~6 requests/second = ~375/minute, which EXCEEDS the
    // server's own 240 reads/minute limit — one fast user could rate-
    // limit themselves out of their own dashboard. Each search also
    // costs several queries (word candidates + COUNT + page), so the
    // real database load was several times the request count.
    //
    // Three layers, the same pattern Google/Algolia use for
    // search-as-you-type:
    //   1. IN-FLIGHT DEDUPE — identical concurrent queries share one
    //      promise instead of hitting the server twice.
    //   2. ABORT SUPERSEDED — when a newer keystroke arrives, the older
    //      request is cancelled, so the server stops working on an
    //      answer nobody will read. This is the big one: without it the
    //      backend computes a full result set for every intermediate
    //      keystroke.
    //   3. CLIENT RATE CAP — a hard ceiling below the server's, so the
    //      UI degrades into a short wait instead of a 429 error.
    var inflight = {};          // query -> { promise, controller }
    var rateWindow = [];        // timestamps of recent GETs
    var RATE_MAX = 90;          // per minute, well under the server's 240
    var RATE_WINDOW_MS = 60000;

    function rateAllows() {
        var now = Date.now();
        // Drop timestamps outside the sliding window.
        while (rateWindow.length && now - rateWindow[0] > RATE_WINDOW_MS) {
            rateWindow.shift();
        }
        if (rateWindow.length >= RATE_MAX) return false;
        rateWindow.push(now);
        return true;
    }

    /** Cancel any in-flight GET whose query is not `keep`. Called when a
     *  newer keystroke supersedes older ones. */
    function abortStaleGets(keep) {
        Object.keys(inflight).forEach(function (k) {
            if (k === keep) return;
            try { if (inflight[k].controller) inflight[k].controller.abort(); } catch (e) {}
            delete inflight[k];
        });
    }

    function apiGet(q, opts) {
        opts = opts || {};
        // Layer 1: an identical request is already running — reuse it
        // rather than asking the database the same question twice.
        if (inflight[q]) return inflight[q].promise;

        if (!rateAllows()) {
            return Promise.reject(new Error(
                'Searching too quickly. Pause for a moment and try again.'));
        }

        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var p = controller ? window.api.get('mezmur.php?' + q, { signal: controller.signal }) : window.api.get('mezmur.php?' + q);

        var wrapped = new Promise(function (resolve, reject) {
            var done = false;
            var timer = setTimeout(function () {
                if (done) return;
                done = true;
                try { if (controller) controller.abort(); } catch (e) {}
                reject(new Error('The server took too long to answer. Check your connection and retry.'));
            }, GET_TIMEOUT);
            p.then(function (d) {
                if (!done) { done = true; clearTimeout(timer); resolve(d); }
            }).catch(function (e) {
                if (!done) {
                    done = true; clearTimeout(timer);
                    // An abort is a deliberate cancellation, not a
                    // failure — never surface it as an error to the user.
                    if (e && (e.name === 'AbortError' || /abort/i.test(e.message || ''))) {
                        var quiet = new Error('aborted');
                        quiet.aborted = true;
                        reject(quiet);
                    } else {
                        reject(e);
                    }
                }
            });
        });

        // Always clear the slot, success or failure, or a failed query
        // would be permanently un-retryable.
        var release = function () { if (inflight[q] && inflight[q].promise === wrapped) delete inflight[q]; };
        wrapped.then(release, release);

        inflight[q] = { promise: wrapped, controller: controller };
        if (opts.supersede) abortStaleGets(q);
        return wrapped;
    }

    var POST_TIMEOUT = 20000; // ms — a save can never hang the UI past this

    function apiPost(data) {
        var p = window.api.post('mezmur.php', data);
        return new Promise(function (resolve, reject) {
            var done = false;
            var timer = setTimeout(function () {
                if (!done) { done = true; reject(new Error('The server took too long to answer. Your changes may not have been saved — check the list before saving again.')); }
            }, POST_TIMEOUT);
            p.then(function (d) {
                // Every mutation (save, set_status, catalog, migrate,
                // submission review) lands here — drop cached list results.
                if (d && d.status === 'success') listCache = {};
                if (!done) { done = true; clearTimeout(timer); resolve(d); }
            }).catch(function (e) {
                if (!done) { done = true; clearTimeout(timer); reject(e); }
            });
        });
    }

    /**
     * Stale-deployment hint. The current server marks every mezmur
     * response with server_meta; generic server errors with no marker
     * almost always mean the deployed backend is behind the web/app
     * build. Give an actionable message instead of a scary dead end.
     */
    function staleHint(err) {
        // P43: this used to append
        //   "the server backend may be outdated — ask the administrator
        //    to pull the latest code and run sql/024_mezmur_submissions.sql"
        // to ordinary TIMEOUTS. Two problems:
        //   1. It leaked internal deployment detail (file paths, schema
        //      names, the fact that migrations exist) to every user —
        //      useful reconnaissance for an attacker, and alarming for a
        //      person who just has slow Wi-Fi.
        //   2. It was almost always WRONG. A transient timeout is a
        //      network hiccup, not a stale deployment, which is why a
        //      reload makes it disappear.
        // Operators still get the detail — via the ping endpoint and the
        // console — but users get a calm, accurate sentence.
        return '';
    }

    /**
     * P43: is this failure worth one silent automatic retry?
     *
     * A transient timeout or network blip is the common case on mobile
     * data. Retrying once, quietly, turns most of these into a normal
     * result the user never sees — which is why the page "works after a
     * reload". We do the reload for them.
     *
     * Deliberately NOT retried: HTTP 4xx (the request itself is wrong),
     * and anything on a POST (it may have already been applied).
     */
    function isTransient(err) {
        var m = (err && err.message) || '';
        return /took too long|failed to fetch|network|connection error|load failed/i.test(m);
    }

    // ── shared state renderers (skeleton / empty / error) ─────
    function skeletonRows(n) {
        var r = '';
        for (var i = 0; i < n; i++) {
            r += '<tr><td colspan="9"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>';
        }
        return r;
    }

    function emptyState(icon, title, text, ctaHtml) {
        return '<div class="empty-state"><i class="fa-solid ' + icon + '"></i>' +
            '<div class="state-title">' + esc(title) + '</div>' +
            '<p class="state-text">' + esc(text) + '</p>' + (ctaHtml || '') + '</div>';
    }

    function errorState(msg, retryCall) {
        return '<div class="error-state"><i class="fa-solid fa-triangle-exclamation"></i>' +
            '<div class="state-title">Something went wrong</div>' +
            '<p class="state-text">' + esc(msg || 'Connection error.') + '</p>' +
            '<button class="btn-secondary btn-sm" onclick="' + retryCall + '"><i class="fa-solid fa-rotate-right"></i> Retry</button></div>';
    }

    function rateTone(rate) { return rate == null ? '' : rate >= 90 ? 'ok' : rate >= 70 ? 'warn' : 'bad'; }

    function rateChip(rate) {
        if (rate == null) return '<span class="text-dim">—</span>';
        return '<span class="rate-chip ' + rateTone(rate) + '">' + rate + '%</span>';
    }

    function rateBar(rate) {
        var w = rate == null ? 0 : Math.max(0, Math.min(100, rate));
        return '<div class="rate-bar ' + rateTone(rate) + '"><div class="rate-bar-track"><div class="rate-bar-fill" style="width:' + w + '%"></div></div><span class="rate-num">' + (rate == null ? '—' : rate + '%') + '</span></div>';
    }

    function deltaHtml(cur, prev, unit) {
        if (prev == null || cur == null) return '';
        var d = Math.round((cur - prev) * 10) / 10;
        if (d === 0) return '<span class="stat-delta flat">— same as last month</span>';
        var up = d > 0;
        return '<span class="stat-delta ' + (up ? 'up' : 'down') + '"><i class="fa-solid fa-arrow-trend-' + (up ? 'up' : 'down') + '"></i> ' + Math.abs(d) + (unit || '') + ' vs last month</span>';
    }

    var STATUS_META = {
        draft:             { label: 'Draft',           badge: 'badge-inactive' },
        incomplete:        { label: 'Draft',           badge: 'badge-inactive' },
        submitted:         { label: 'Submitted',       badge: 'badge-info' },
        approved:          { label: 'Approved',        badge: 'badge-active' },
        rejected:          { label: 'Rejected',        badge: 'badge-warning' },
        revision_needed:   { label: 'Needs revision',  badge: 'badge-warning' }
    };

    function statusChip(status) {
        var m = STATUS_META[status] || { label: status || '—', badge: 'badge-inactive' };
        return '<span class="badge ' + m.badge + '">' + esc(m.label) + '</span>';
    }

    // ── modal focus management (Esc to close, focus return) ──
    var _modalTrigger = null;
    function openModalF(id, focusSelector) {
        _modalTrigger = document.activeElement;
        modal(id, true);
        setTimeout(function () {
            var el = document.querySelector('#' + id + ' ' + (focusSelector || 'input, select, textarea, button'));
            if (el && el.focus) el.focus();
        }, 60);
    }
    function closeModalF(id) {
        modal(id, false);
        if (_modalTrigger && _modalTrigger.focus) { _modalTrigger.focus(); _modalTrigger = null; }
    }
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelectorAll('.school-modal.show');
        if (open.length) closeModalF(open[open.length - 1].id);
    });

    // ══════════════════════════════════════════════════════════
    // LYRIC TIMING EDITOR (P44) — tap-to-sync karaoke authoring
    //
    // The database columns, the LRC validator/canonicaliser, and the
    // karaoke renderers in BOTH players already existed. What did not
    // exist was any way to produce a timestamp, so lyrics_synced was
    // always NULL and the feature never ran. This is the authoring half.
    //
    // Interaction model is the industry standard (MiniLyrics, Musixmatch
    // and every LRC tool): play the audio and stamp each line as it is
    // sung. Hand-typing timestamps is not a real option.
    // ══════════════════════════════════════════════════════════
    var sync = { id: 0, lines: [], idx: 0, audio: null, dur: 0, raf: 0 };

    // ── P46: OFFLINE-DURABLE SAVING ───────────────────────────
    //
    // Timing a hymn is 10-20 minutes of careful work. Losing it to a
    // dropped request is unacceptable, and on a poor connection that is
    // the NORMAL case, not the edge case.
    //
    // The pattern every offline-first product uses (Google Docs, Notion,
    // Figma): never let the network be the only copy.
    //   1. WRITE LOCAL FIRST — every stamp is persisted to localStorage
    //      immediately, so a crash, reload or dead connection loses at
    //      most the keystroke in flight.
    //   2. QUEUE THE SAVE — a failed save stays queued, not discarded.
    //   3. RETRY WITH BACKOFF — 1s, 2s, 4s, 8s, capped, with jitter so
    //      many clients do not retry in lockstep.
    //   4. FLUSH ON RECONNECT — the browser's `online` event drains the
    //      queue without the user doing anything.
    //   5. TELL THE TRUTH — the UI always says whether work is saved,
    //      pending, or failed. Never a silent "maybe".
    //
    // Safe to retry: an LRC save is a full REPLACE of one column keyed by
    // hymn id. Applying it twice is identical to applying it once, so no
    // idempotency token is needed (unlike, say, an attendance insert).
    var DRAFT_KEY = 'mz_lrc_draft_v1';
    var QUEUE_KEY = 'mz_lrc_queue_v1';

    function lsGet(k, fallback) {
        try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : fallback; }
        catch (e) { return fallback; }
    }
    function lsSet(k, v) {
        try { localStorage.setItem(k, JSON.stringify(v)); return true; }
        catch (e) { return false; } // quota or private mode — degrade, never throw
    }

    /** Persist the in-progress timings for one hymn. */
    function draftSave() {
        if (!sync.id) return;
        var all = lsGet(DRAFT_KEY, {});
        all[sync.id] = {
            lines: sync.lines.map(function (l) { return { text: l.text, t: l.t }; }),
            idx: sync.idx,
            at: Date.now()
        };
        lsSet(DRAFT_KEY, all);
    }
    function draftLoad(id) {
        var all = lsGet(DRAFT_KEY, {});
        return all[id] || null;
    }
    function draftClear(id) {
        var all = lsGet(DRAFT_KEY, {});
        delete all[id];
        lsSet(DRAFT_KEY, all);
    }

    /** Durable outbox: saves that have not been acknowledged yet. */
    function queueAdd(id, lrc) {
        var q = lsGet(QUEUE_KEY, {});
        q[id] = { lrc: lrc, at: Date.now(), tries: 0 };
        lsSet(QUEUE_KEY, q);
        paintSyncState();
    }
    function queueDrop(id) {
        var q = lsGet(QUEUE_KEY, {});
        delete q[id];
        lsSet(QUEUE_KEY, q);
        paintSyncState();
    }
    function queuePending() { return Object.keys(lsGet(QUEUE_KEY, {})).length; }

    var flushTimer = null;
    /**
     * Drain the outbox. Exponential backoff with jitter — the standard
     * AWS/Google retry curve. Never gives up permanently: the entry
     * survives a reload and is retried again on the next `online` event
     * or the next flush.
     */
    function queueFlush() {
        if (flushTimer) { clearTimeout(flushTimer); flushTimer = null; }
        var q = lsGet(QUEUE_KEY, {});
        var ids = Object.keys(q);
        if (!ids.length) return;
        if (typeof navigator !== 'undefined' && navigator.onLine === false) {
            paintSyncState();
            return; // no point burning a request while offline
        }
        var id = ids[0];
        var item = q[id];
        apiPost({ action: 'lyrics_synced_save', id: id, lrc: item.lrc })
            .then(function (d) {
                if (d && d.status === 'success') {
                    queueDrop(id);
                    draftClear(id);
                    window.toast(d.message || 'Synced lyrics saved.', 's');
                    if (Object.keys(lsGet(QUEUE_KEY, {})).length) queueFlush();
                    return;
                }
                // A rejection from the SERVER (bad LRC, missing schema,
                // no permission) will never succeed on retry — surface it
                // and stop, keeping the draft so nothing is lost.
                queueDrop(id);
                window.toast((d && d.message) || 'The server rejected the timings.', 'e');
                paintSyncState();
            })
            .catch(function () {
                // Network/timeout — keep it and back off.
                var qq = lsGet(QUEUE_KEY, {});
                if (!qq[id]) return;
                qq[id].tries = (qq[id].tries || 0) + 1;
                lsSet(QUEUE_KEY, qq);
                var n = Math.min(qq[id].tries, 5);
                var delay = Math.pow(2, n) * 1000;
                delay = delay / 2 + Math.random() * (delay / 2); // jitter
                paintSyncState();
                flushTimer = setTimeout(queueFlush, delay);
            });
    }

    /** One honest line about where the user's work currently lives. */
    function paintSyncState() {
        var el = $('mzSyncSaveState');
        if (!el) return;
        var pending = queuePending();
        var offline = (typeof navigator !== 'undefined' && navigator.onLine === false);
        if (pending && offline) {
            el.textContent = 'Saved on this device — will upload when you are back online.';
            el.className = 'mz-sync-state is-pending';
        } else if (pending) {
            el.textContent = 'Saved on this device — uploading…';
            el.className = 'mz-sync-state is-pending';
        } else {
            el.textContent = 'All timings saved to the server.';
            el.className = 'mz-sync-state is-ok';
        }
    }

    if (typeof window !== 'undefined') {
        window.addEventListener('online', function () { queueFlush(); });
        window.addEventListener('offline', function () { paintSyncState(); });
    }



    /** mm:ss.mmm — the canonical LRC stamp the server expects. */
    function lrcStamp(sec) {
        if (!isFinite(sec) || sec < 0) sec = 0;
        var ms = Math.round(sec * 1000);
        var m = Math.floor(ms / 60000);
        var ss = Math.floor((ms % 60000) / 1000);
        var mmm = ms % 1000;
        return '[' + String(m).padStart(2, '0') + ':' + String(ss).padStart(2, '0') +
               '.' + String(mmm).padStart(3, '0') + ']';
    }
    function clockText(sec) {
        if (!isFinite(sec) || sec < 0) sec = 0;
        var m = Math.floor(sec / 60);
        var r = sec - m * 60;
        return m + ':' + (r < 10 ? '0' : '') + r.toFixed(1);
    }

    /** Parse existing LRC back into rows so re-editing keeps prior work. */
    function parseExistingLrc(src) {
        var out = [];
        String(src || '').split(/\r?\n/).forEach(function (raw) {
            var m = raw.match(/^\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]\s*(.*)$/);
            if (!m) return;
            var t = (+m[1]) * 60 + (+m[2]) + parseInt(String(m[3] || '0').padEnd(3, '0'), 10) / 1000;
            out.push({ text: m[4], t: t });
        });
        return out;
    }

    /** Static lyrics -> timeable rows. Blank lines and [Section] markers
     *  are dropped: the server rejects non-timestamp lines, and timing a
     *  blank line would be meaningless. */
    function lyricRows(text) {
        return String(text || '').split(/\r?\n/)
            .map(function (l) { return l.trim(); })
            .filter(function (l) { return l !== '' && !/^\[.+\]$/.test(l); })
            .map(function (l) { return { text: l, t: null }; });
    }

    function syncOpen() {
        var id = parseInt(($('mzAudioHymnId') || {}).value, 10) || 0;
        if (!id) { window.toast('Open a hymn first.', 'e'); return; }
        sync.id = id;
        sync.idx = 0;
        syncErr('');
        $('mzSyncHymnId').value = id;

        apiGet('action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (!d || d.status !== 'success' || !d.item) {
                window.toast((d && d.message) || 'Could not load the hymn.', 'e');
                return;
            }
            var h = d.item;
            $('mzSyncHymnName').textContent = h.title || '';
            var existing = parseExistingLrc(h.lyrics_synced);
            var rows = lyricRows(h.lyrics);
            if (!rows.length) {
                window.toast('This hymn has no lyrics to time yet. Add lyrics first.', 'e');
                return;
            }
            // Re-open with previous timings applied where the text still
            // matches, so editing is incremental rather than all-or-nothing.
            if (existing.length) {
                rows.forEach(function (r, i) {
                    if (existing[i] && existing[i].text === r.text) r.t = existing[i].t;
                });
            }
            // P46: a local draft beats the server copy — it is newer by
            // definition (it exists because a save had not completed).
            // Restoring it is what makes a dropped connection a pause
            // rather than a loss.
            var draft = draftLoad(id);
            if (draft && draft.lines && draft.lines.length === rows.length) {
                var sameText = draft.lines.every(function (d2, i) { return d2.text === rows[i].text; });
                if (sameText) {
                    var timedCount = draft.lines.filter(function (d2) { return d2.t != null; }).length;
                    rows = draft.lines.map(function (d2) { return { text: d2.text, t: d2.t }; });
                    if (timedCount) {
                        window.toast('Restored ' + timedCount + ' unsaved timing(s) from this device.', 'i');
                    }
                }
            }
            sync.lines = rows;
            sync.idx = draft && typeof draft.idx === 'number'
                ? Math.min(draft.idx, rows.length) : 0;
            renderSyncLines();
            openModalF('mzSyncModal', '#mzSyncPlay');
            paintSyncState();
            syncLoadAudio(id);
        }).catch(function (err) {
            window.toast((err && err.message) || 'Could not load the hymn.', 'e');
        });
    }

    function syncLoadAudio(id) {
        var a = $('mzSyncAudio');
        if (!a) return;
        sync.audio = a;
        apiGet('action=audio_stream&id=' + encodeURIComponent(id)).then(function (s2) {
            if (!s2 || s2.status !== 'success' || !s2.url) {
                syncErr('No audio is attached yet. Upload audio first — timings are meaningless without it.');
                return;
            }
            a.src = s2.url;
            a.load();
        }).catch(function () {
            syncErr('Could not load the audio for this hymn.');
        });
    }

    function syncErr(msg) {
        var el = $('mzSyncError');
        if (!el) return;
        el.textContent = msg || '';
        el.classList.toggle('is-hidden', !msg);
    }

    function renderSyncLines() {
        var box = $('mzSyncLines');
        if (!box) return;
        box.innerHTML = sync.lines.map(function (l, i) {
            var stamped = l.t != null;
            return '<div class="mz-sync-line' + (i === sync.idx ? ' is-current' : '') +
                   (stamped ? ' is-stamped' : '') + '" data-i="' + i + '">' +
                   '<button type="button" class="mz-sync-time" onclick="Mezmur.syncSeekTo(' + i + ')" ' +
                   'title="Jump to this time">' + (stamped ? clockText(l.t) : '—') + '</button>' +
                   '<span class="mz-sync-text amharic">' + esc(l.text) + '</span>' +
                   '<span class="mz-sync-nudge">' +
                   '<button type="button" onclick="Mezmur.syncNudge(' + i + ',-0.2)" aria-label="Earlier by 0.2 seconds"' +
                   (stamped ? '' : ' disabled') + '>−</button>' +
                   '<button type="button" onclick="Mezmur.syncNudge(' + i + ',0.2)" aria-label="Later by 0.2 seconds"' +
                   (stamped ? '' : ' disabled') + '>+</button>' +
                   '</span></div>';
        }).join('');
        var cur = box.querySelector('.mz-sync-line.is-current');
        if (cur && cur.scrollIntoView) cur.scrollIntoView({ block: 'center' });
        var done = sync.lines.filter(function (l) { return l.t != null; }).length;
        var st = $('mzSyncStatus');
        if (st) st.textContent = done + ' of ' + sync.lines.length + ' lines timed.';
    }

    function syncPlayPause() {
        var a = sync.audio;
        if (!a || !a.src) { syncErr('No audio loaded.'); return; }
        if (a.paused) { a.play().catch(function () { syncErr('Playback was blocked by the browser.'); }); }
        else { a.pause(); }
    }

    /** Stamp the current line at the current playback position. */
    function syncStamp() {
        var a = sync.audio;
        if (!a || !a.src) { syncErr('Load audio before stamping.'); return; }
        if (sync.idx >= sync.lines.length) return;
        sync.lines[sync.idx].t = a.currentTime;
        sync.idx = Math.min(sync.idx + 1, sync.lines.length);
        draftSave();   // P46: survive a crash/reload/dead connection
        renderSyncLines();
    }

    function syncBack() {
        if (sync.idx <= 0) return;
        sync.idx--;
        sync.lines[sync.idx].t = null; // re-timing this line
        draftSave();
        renderSyncLines();
    }

    function syncNudge(i, delta) {
        if (!sync.lines[i] || sync.lines[i].t == null) return;
        sync.lines[i].t = Math.max(0, sync.lines[i].t + delta);
        draftSave();
        renderSyncLines();
    }

    function syncSeekTo(i) {
        var a = sync.audio;
        if (!a || sync.lines[i] == null || sync.lines[i].t == null) return;
        a.currentTime = sync.lines[i].t;
        sync.idx = i;
        renderSyncLines();
    }

    function syncReset() {
        var hymnId = sync.id;
        sysConfirm('Clear every timing for this hymn?', function () {
            if (sync.id !== hymnId) return;
            sync.lines.forEach(function (l) { l.t = null; });
            sync.idx = 0;
            draftSave();
            renderSyncLines();
        });
    }

    function syncSave(confirmed) {
        var timed = sync.lines.filter(function (l) { return l.t != null; });
        if (!timed.length && confirmed !== true) {
            // Saving with nothing timed means "remove timings" — make that
            // explicit rather than silently wiping.
            var hymnId = sync.id;
            sysConfirm('No lines are timed. Remove synced lyrics and fall back to static lyrics?', function () {
                if (sync.id === hymnId) syncSave(true);
            }, { tier: 'medium', title: 'Remove the synced lyrics?', summary: 'No lines are timed, so saving removes the timings.', intent: 'I want to remove the timings',
                 consequences: ['The saved line timings for this hymn are removed from the server.', 'The app shows plain lyrics instead of synced (karaoke-style) lyrics.'],
                 keeps: ['The lyrics text itself is kept.', 'You can time the lines again later.'], phrase: 'REMOVE TIMINGS', confirmLabel: 'Remove timings' });
            return;
        }
        var lrc = timed
            .slice()
            .sort(function (a, b) { return a.t - b.t; })
            .map(function (l) { return lrcStamp(l.t) + ' ' + l.text; })
            .join('\n');

        // P46: queue FIRST, then attempt. The work is durable the moment
        // the button is pressed — if the request dies, the entry stays in
        // the outbox and retries with backoff, and survives a reload.
        // This is why a poor connection can no longer cost 20 minutes of
        // timing work.
        draftSave();
        queueAdd(sync.id, lrc);
        window.toast('Timings saved on this device — uploading…', 'i');
        queueFlush();
        syncClose();
    }

    function syncClose() {
        var a = sync.audio;
        if (a) { try { a.pause(); } catch (e) {} a.removeAttribute('src'); a.load(); }
        if (sync.raf) { cancelAnimationFrame(sync.raf); sync.raf = 0; }
        closeModalF('mzSyncModal');
    }

    /** Keyboard: Space stamps, arrows nudge the audio, Backspace steps
     *  back. Only while the editor is open, and never while typing. */
    document.addEventListener('keydown', function (e) {
        var modalEl = $('mzSyncModal');
        if (!modalEl || !modalEl.classList.contains('show')) return;
        var t = e.target;
        var tag = t && t.tagName ? t.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) return;
        var a = sync.audio;
        if (e.key === ' ') { e.preventDefault(); syncStamp(); }
        else if (e.key === 'Backspace') { e.preventDefault(); syncBack(); }
        else if (e.key === 'ArrowLeft' && a) { e.preventDefault(); a.currentTime = Math.max(0, a.currentTime - 2); }
        else if (e.key === 'ArrowRight' && a) { e.preventDefault(); a.currentTime = a.currentTime + 2; }
        else if (e.key === 'Enter') { e.preventDefault(); syncPlayPause(); }
    });

    // Clock + seek bar, driven by the audio element itself.
    function initSyncAudioControls() {
        // P46: drain anything left queued by a previous session — the
        // user may have closed the tab mid-upload, or been offline.
        if (queuePending()) { paintSyncState(); queueFlush(); }
        var a = $('mzSyncAudio');
        if (!a) return;
        a.addEventListener('timeupdate', function () {
            var c = $('mzSyncClock');
            if (c) c.textContent = clockText(a.currentTime);
            var sk = $('mzSyncSeek');
            if (sk && isFinite(a.duration) && a.duration > 0 && document.activeElement !== sk) {
                sk.value = String(Math.round((a.currentTime / a.duration) * 1000));
            }
        });
        a.addEventListener('play', function () {
            var l = $('mzSyncPlayLabel'); if (l) l.textContent = 'Pause';
        });
        a.addEventListener('pause', function () {
            var l = $('mzSyncPlayLabel'); if (l) l.textContent = 'Play';
        });
        var sk = $('mzSyncSeek');
        if (sk) {
            sk.addEventListener('input', function () {
                if (!isFinite(a.duration) || a.duration <= 0) return;
                a.currentTime = (Number(sk.value) / 1000) * a.duration;
            });
        }
    }

    // ══════════════════════════════════════════════════════════
    // LAZY TAB LOADING — data fetches on first tab activation
    // ══════════════════════════════════════════════════════════
    var tabLoaded = { overview: false, library: false, attendance: false, analytics: false, takers: false };

    function loadTab(name) {
        if (name === 'overview' && !tabLoaded.overview) {
            tabLoaded.overview = true;
            loadOverview();
        } else if (name === 'library' && !tabLoaded.library) {
            tabLoaded.library = true;
            loadStats();
            loadList();
        } else if (name === 'catalog') {
            loadCatalog();
            renderCatalogManager();
        } else if (name === 'attendance' && !tabLoaded.attendance) {
            tabLoaded.attendance = true;
            loadSections();
            loadSubSectionOptions();
            loadSubmissions();
        } else if (name === 'analytics' && !tabLoaded.analytics) {
            tabLoaded.analytics = true;
            ensureAnalyticsSections();
        } else if (name === 'takers' && !tabLoaded.takers) {
            tabLoaded.takers = true;
            loadTakers();
        }
    }

    // Wrap core.js's switchSection so every activation (sidebar,
    // bottom nav, quick tiles, session restore) lazy-loads its tab.
    var _origSwitch = window.switchSection;
    if (typeof _origSwitch === 'function') {
        window.switchSection = function (name) {
            _origSwitch(name);
            loadTab(name);
        };
    }

    // ══════════════════════════════════════════════════════════
    // MODULE 0 — OVERVIEW (ONE batched request)
    // ══════════════════════════════════════════════════════════
    function loadOverview() {
        var hour = new Date().getHours();
        var greet = hour < 12 ? 'Good Morning' : hour < 17 ? 'Good Afternoon' : 'Good Evening';
        var name = ((window.APP || {}).user || {}).name || '';
        $('mzGreeting').textContent = greet + (name ? ', ' + name.split(' ')[0] : '') + ' 🎵';

        // Attendance alerting Phase 2: open Mezmur warnings (department-scoped on the server).
        try {
            fetch('/admin/api_attendance_warnings.php?action=counts', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (w) {
                    var el = $('mzOvWarnings'), sub = $('mzOvWarningsSub');
                    if (!el) return;
                    if (w.status !== 'success' || !w.available || !w.counts || !w.counts.mezmur) { el.textContent = '—'; if (sub) sub.textContent = 'Not available yet'; return; }
                    var c = w.counts.mezmur;
                    el.textContent = c.total;
                    if (sub) sub.textContent = (c.critical ? c.critical + ' critical · ' : '') + c.consecutive + ' consecutive · ' + c.low_month + ' low month';
                }).catch(function () {});
        } catch (e) { /* card is informational only */ }

        // Attendance alerting Phase 4: current EC month roll-up (Mezmur only, server-scoped). Labels arrive in the Ethiopian calendar.
        try {
            fetch('/admin/api_attendance_monthly.php?action=summary&department=mezmur', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (w) {
                    var el = $('mzOvMonthly'), sub = $('mzOvMonthlySub');
                    if (!el) return;
                    if (w.status !== 'success' || !w.available || !w.summary) { el.textContent = '—'; if (sub) sub.textContent = 'Not available yet'; return; }
                    var s = w.summary;
                    el.textContent = s.below;
                    if (sub) sub.textContent = s.month_label_am + ' · ' + s.members + ' members' + (s.avg_rate != null ? ' · avg ' + s.avg_rate + '%' : '');
                }).catch(function () {});
        } catch (e) { /* card is informational only */ }

        apiGet('action=overview').then(function (d) {
            if (d.status !== 'success') {
                var msg = d.message || 'Unable to load the overview.';
                $('mzOvRecentDays').innerHTML = '<tr><td colspan="3">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
                $('mzOvRecentHymns').innerHTML = '<tr><td colspan="3">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
                $('mzOvQueue').innerHTML = '<tr><td colspan="5">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
                return;
            }

            $('mzOvHymns').textContent = d.hymns_total != null ? d.hymns_total : '—';
            $('mzOvMembers').textContent = d.members != null ? d.members : '—';
            $('mzOvTakers').textContent = (d.takers_active || 0) + ' / ' + (d.takers_total || 0);

            var m = d.month || {}, pv = d.prev_month || {};
            $('mzOvDays').textContent = m.days != null ? m.days : '—';
            $('mzOvRate').textContent = m.rate != null ? m.rate + '%' : '—';
            $('mzOvDaysDelta').innerHTML = deltaHtml(m.days, pv.days, '');
            $('mzOvRateDelta').innerHTML = (m.rate != null && pv.rate != null) ? deltaHtml(m.rate, pv.rate, ' pts') : '';

            // recent attendance days (no program labels — raw data)
            var body = $('mzOvRecentDays');
            var days = d.recent_days || [];
            if (!days.length) {
                body.innerHTML = '<tr><td colspan="3">' + emptyState('fa-calendar-check', 'No attendance yet this month', 'Open the Attendance tab and record your first day.') + '</td></tr>';
            } else {
                body.innerHTML = days.map(function (x) {
                    var rate = x.marked > 0 ? Math.round(x.attended * 1000 / x.marked) / 10 : null;
                    return '<tr class="clickable-row" onclick="Mezmur.jumpToDate(\'' + esc(x.attendance_date) + '\')" tabindex="0">' +
                        '<td class="nowrap">' + fmtDate(x.attendance_date) + '</td>' +
                        '<td>' + x.attended + '/' + x.marked + '</td>' +
                        '<td>' + rateChip(rate) + '</td></tr>';
                }).join('');
            }

            // recent hymns
            var hb = $('mzOvRecentHymns');
            var hymns = d.recent_hymns || [];
            if (!hymns.length) {
                hb.innerHTML = '<tr><td colspan="3">' + emptyState('fa-music', 'No hymns yet', 'Add the first hymn to start the library.') + '</td></tr>';
            } else {
                hb.innerHTML = hymns.map(function (h) {
                    return '<tr class="clickable-row" onclick="Mezmur.view(' + h.id + ')" tabindex="0">' +
                        '<td>' + esc(h.title) + '</td>' +
                        '<td>' + (h.category ? '<span class="badge badge-info">' + esc(h.category) + '</span>' : '<span class="text-dim">—</span>') + '</td>' +
                        '<td class="nowrap text-dim">' + fmtDate(h.updated_at) + '</td></tr>';
                }).join('');
            }

            // review queue preview
            var qb = $('mzOvQueue');
            var packets = d.recent_packets || [];
            if (!packets.length) {
                qb.innerHTML = '<tr><td colspan="5">' + emptyState('fa-inbox', 'Queue is empty', 'Packets saved or submitted by takers appear here for review.') + '</td></tr>';
            } else {
                qb.innerHTML = packets.map(function (p) {
                    return '<tr class="clickable-row" onclick="Mezmur.gotoAttendance()" tabindex="0">' +
                        '<td class="nowrap">' + fmtDate(p.attendance_date) + '</td>' +
                        '<td class="amharic">' + esc(p.section) + '</td>' +
                        '<td>' + p.member_count + '</td>' +
                        '<td>' + statusChip(p.status) + '</td>' +
                        '<td class="nowrap text-dim">' + fmtDate(p.updated_at) + '</td></tr>';
                }).join('');
            }
        }).catch(function (err) {
            var msg = ((err && err.message) || 'Connection error.') + staleHint(err);
            $('mzOvRecentDays').innerHTML = '<tr><td colspan="3">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
            $('mzOvRecentHymns').innerHTML = '<tr><td colspan="3">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
            $('mzOvQueue').innerHTML = '<tr><td colspan="5">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
        });
    }

    function gotoAttendance() { window.switchSection('attendance'); }
    function jumpToDate(date) {
        att.viewDate = date;
        window.switchSection('attendance');
        ensureViewSections();
        $('mzSessionListView').style.display = 'none';
        $('mzSheetView').style.display = 'block';
        $('mzSheetTitle').textContent = 'Attendance — ' + fmtDate(date);
        $('mzSheetMeta').textContent = 'Pick a section and press View to inspect the recorded sheet.';
        $('mzSheetBody').innerHTML = emptyState('fa-eye', 'Read-only view', 'Select a section above to see the recorded marks for this day.');
        $('mzSheetSummary').innerHTML = '';
        renderSheetStatus('');
    }

    /** Populate the read-only section selector (cached per tab visit). */
    function ensureViewSections() {
        var sel = $('mzViewSection');
        if (sel && sel.options.length <= 1) loadSections();
    }

    function viewSheet() {
        var section = $('mzViewSection').value;
        if (!section) { window.toast('Pick a section first.', 'e'); return; }
        var date = att.viewDate || todayStr();
        loadSheet(date, section);
    }

    function quickReview() {
        window.switchSection('attendance');
        loadSubmissions();
    }
    function quickTake() { window.switchSection('attendance'); }
    function quickLibrary() { window.switchSection('library'); }
    function quickAnalytics() { window.switchSection('analytics'); }
    function quickTakers() { window.switchSection('takers'); }

    // ══════════════════════════════════════════════════════════
    // MODULE 1 — HYMN LIBRARY
    // ══════════════════════════════════════════════════════════
    var lib = { page: 1, totalPages: 1, total: 0, search: '', category: '', status: 'active', length: '', language: '', categoryId: 0, zemarianId: 0, loading: false, seq: 0,
        // Phase 3 paging: per page (remembered), Load more appends the next page.
        firstPage: 1, shown: 0, appending: false,
        perPage: (function () { var v = 0; try { v = parseInt(localStorage.getItem('fkss_pp_mz_lib'), 10); } catch (e) { v = 0; } return [25, 50, 100].indexOf(v) >= 0 ? v : PAGE_SIZE; })() };

    /** Keystroke cache (P22): identical search queries are answered from
     *  memory instead of re-hitting the server; every successful mutation
     *  (all of which travel through apiPost) drops it. Bounded to 10 keys. */
    var listCache = {};
    function cachePut(key, data) {
        var keys = Object.keys(listCache);
        if (keys.length >= 10) delete listCache[keys[0]];
        listCache[key] = data;
    }

    function loadStats() {
        return apiGet('action=stats').then(function (d) {
            if (d.status !== 'success') return;
            $('mzStatTotal').textContent = d.total != null ? d.total : '—';
            $('mzStatActive').textContent = d.active != null ? d.active : '—';
            $('mzStatCategories').textContent = d.categories != null ? d.categories : '—';

            var sel = $('mzCategoryFilter'), cur = sel.value;
            sel.innerHTML = '<option value="">All categories</option>' +
                (d.category_list || []).map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('');
            sel.value = cur;
            var catOpts = $('mzCategoryOptions');
            if (catOpts) catOpts.innerHTML = (d.category_list || []).map(function (c) { return '<option value="' + esc(c) + '">'; }).join('');

            populateSectionSelect($('mzAnSection'), d.section_list || []);
        }).catch(function () { /* stats non-critical */ });
    }

    function populateSectionSelect(sel, sections) {
        if (!sel) return;
        var cur = sel.value;
        sel.innerHTML = '<option value="">All sections</option>' +
            sections.map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('');
        sel.value = cur;
    }

    function loadList() {
        var seq = ++lib.seq; // as-you-type: only the latest response renders
        var tb = $('mzTbody');
        var append = lib.appending === true;  // set only by libMore()
        lib.appending = false;
        var q = 'action=list&page=' + encodeURIComponent(lib.page) + '&per_page=' + lib.perPage +
            '&search=' + encodeURIComponent(lib.search) + '&category=' + encodeURIComponent(lib.category) +
            '&length=' + encodeURIComponent(lib.length) + '&language=' + encodeURIComponent(lib.language) +
            '&category_id=' + encodeURIComponent(lib.categoryId || '') + '&zemarian_id=' + encodeURIComponent(lib.zemarianId || '') +
            '&status=' + encodeURIComponent(lib.status);
        // Cached answer (same query typed again): render immediately, no
        // server round trip — the seq guard above still applies.
        if (listCache[q]) {
            applyList(seq, tb, listCache[q], append);
            return;
        }
        if (!append) tb.innerHTML = skeletonRows(6);
        // supersede: this keystroke cancels every older in-flight query.
        apiGet(q, { supersede: true }).catch(function (err) {
            // P43: ONE silent retry for a transient failure. This is what
            // the user was doing manually by reloading the page; doing it
            // automatically (after a short backoff, so we do not hammer a
            // struggling server) turns most blips into a normal result
            // they never see. Aborts and hard errors fall straight
            // through.
            if (err && err.aborted) throw err;
            if (seq !== lib.seq) throw err;
            if (!isTransient(err)) throw err;
            return new Promise(function (res) { setTimeout(res, 600); })
                .then(function () { return apiGet(q); });
        }).then(function (d) {
            if (!d) return;
            if (seq === lib.seq && d.status === 'success') cachePut(q, d);
            applyList(seq, tb, d, append);
        }).catch(function (err) {
            // P43: an aborted request was cancelled BY US because the
            // user kept typing. It is not a failure and must never paint
            // an error over results that are about to arrive.
            if (err && err.aborted) return;
            if (seq !== lib.seq) return;
            var msg = ((err && err.message) || 'Connection error.') + staleHint(err);
            if (append) { lib.page--; window.toast(msg, 'e'); renderLibPagination(); return; }  // keep rows shown
            tb.innerHTML = '<tr><td colspan="6">' + errorState(msg, 'Mezmur.libReload()') + '</td></tr>';
        });
    }

    function applyList(seq, tb, d, append) {
        if (seq !== lib.seq) return;
        if (d.status !== 'success') {
            if (append) { lib.page--; window.toast(d.message || 'Unable to load more hymns.', 'e'); renderLibPagination(); return; }
            tb.innerHTML = '<tr><td colspan="6">' + errorState(d.message || 'Unable to load hymns.', 'Mezmur.libReload()') + '</td></tr>';
            return;
        }
        lib.totalPages = d.total_pages || 1;
        lib.total = d.total || 0;
        if (append && (d.items || []).length) {
            // renderHymnRows paints the whole tbody; keep the rows already shown in front.
            var before = tb.innerHTML;
            renderHymnRows(d.items);
            tb.innerHTML = before + tb.innerHTML;
            lib.lastItems = (lib.lastItems || []).concat(d.items);
            lib.shown += d.items.length;
        } else {
            lib.firstPage = lib.page;
            lib.lastItems = d.items || [];
            lib.shown = lib.lastItems.length;
            renderHymnRows(d.items || []);
        }
        renderLibPagination();
        announceResults();
    }

    /** Escape then wrap the user's search tokens in <mark>. */
    /** P42: tell screen readers what the search produced.
     *
     * The table repainted silently, so a non-sighted user had no way to
     * know whether a query matched anything — the only feedback was
     * visual. role="status" + aria-live="polite" waits for a pause in
     * speech, so it informs without interrupting typing. */
    function announceResults() {
        var el = $('mzSearchStatus');
        if (!el) return;
        var n = lib.total || 0;
        var msg;
        if (!lib.search) {
            msg = n + ' hymn' + (n === 1 ? '' : 's') + ' listed.';
        } else if (n === 0) {
            msg = 'No hymns match “' + lib.search + '”.';
        } else {
            msg = n + ' hymn' + (n === 1 ? '' : 's') + ' match “' + lib.search + '”.';
        }
        // Only speak on change, or the same string is re-announced on
        // every keystroke.
        if (el.textContent !== msg) el.textContent = msg;
    }

    /** P42: fold Amharic homophones exactly like the server's
     *  foldAmharic(), so the letters the server MATCHED are the letters
     *  we highlight. Without this the server returns a ፀሐይ row for a
     *  ጸሀይ query and the row renders with nothing marked, which reads
     *  as "why is this here?". Length-preserving: one syllable maps to
     *  one, so offsets into the original string stay valid. */
    var MZ_FOLD = (function () {
        var fams = [
            ['ሀሁሂሃሄህሆ', ['ሐሑሒሓሔሕሖ', 'ኀኁኂኃኄኅኆ']],
            ['ሰሱሲሳሴስሶ', ['ሠሡሢሣሤሥሦ']],
            ['ጸጹጺጻጼጽጾ', ['ፀፁፂፃፄፅፆ']],
            ['አኡኢኣኤእኦ', ['ዐዑዒዓዔዕዖ']]
        ];
        var m = {};
        fams.forEach(function (f) {
            var canon = Array.from(f[0]);
            f[1].forEach(function (variant) {
                Array.from(variant).forEach(function (ch, i) {
                    if (canon[i]) m[ch] = canon[i];
                });
            });
        });
        return function (s) {
            var out = '';
            for (var i = 0; i < s.length; i++) {
                var c = s.charAt(i);
                out += (m[c] || c);
            }
            return out;
        };
    })();

    /** Escape then wrap the user's search tokens in <mark>. Highlight: <mark>$1</mark> */
    function hi(text) {
        var raw = text == null ? '' : String(text);
        if (!lib.search) return esc(raw);
        var toks = lib.search.split(/\s+/).filter(function (t) { return t.length >= 2; });
        if (!toks.length) return esc(raw);

        // Match against the FOLDED text, then slice the ORIGINAL so the
        // user still sees the real spelling inside <mark>.
        var folded = MZ_FOLD(raw.toLowerCase());
        var re = new RegExp('(' + toks.map(function (t) {
            return MZ_FOLD(t.toLowerCase()).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }).join('|') + ')', 'g');

        var out = '', last = 0, m;
        while ((m = re.exec(folded)) !== null) {
            if (m[0] === '') { re.lastIndex++; continue; }
            out += esc(raw.slice(last, m.index))
                + '<mark>' + esc(raw.slice(m.index, m.index + m[0].length)) + '</mark>';
            last = m.index + m[0].length;
        }
        return out + esc(raw.slice(last));
    }

    /** MZ-15: render every category the hymn carries (server attaches the
     *  join rows); the legacy single string stays as the fallback. */
    function catBadges(h) {
        var cats = h.categories || [];
        if (cats.length) {
            var extra = cats.length > 3 ? ' <span class="text-dim" style="font-size:.7rem">+' + (cats.length - 3) + '</span>' : '';
            return cats.slice(0, 3).map(function (c) {
                return '<span class="badge badge-info">' + esc(c.name) + '</span>';
            }).join(' ') + extra;
        }
        return h.category ? '<span class="badge badge-info">' + esc(h.category) + '</span>' : '—';
    }

    /** P66 hymn art: the fallback gradient for a hymn without a cover
     *  image. Same chain as the Flutter app (art image → name-hash
     *  gradient) so the web console and the member app always agree
     *  on what a hymn "looks like". */
    function hymnCoverGradient(h) {
        return PICK_GRADIENTS[hashCode(String(h.title || h.id || '')) % PICK_GRADIENTS.length];
    }

    /** P66: list thumbnail — 44px button (click = set/replace art).
     *  Renders the server's 160px rendition; without art, the
     *  name-hash gradient + first letter keeps every row scannable. */
    function hymnArtCell(h) {
        var ready = h.art_status === 'ready' && h.art_url_small;
        var style = ready
            ? ' style="background-image:url(\'' + h.art_url_small + '\')"'
            : ' style="background:linear-gradient(135deg,' + hymnCoverGradient(h).join(',') + ')"';
        var label = ready ? '' : esc(String(h.title || '?').trim().charAt(0));
        return '<button class="mz-art-btn" title="' + (ready ? 'Replace cover art' : 'Set cover art') + '"' +
            ' aria-label="Cover art for ' + esc(h.title) + '"' + style +
            ' onclick="Mezmur.mgrArt(' + Number(h.id) + ')">' + label + '</button>';
    }

    /** MZ-12: reset every library filter and reload (empty-state recovery). */
    function clearFilters() {
        lib.search = ''; lib.category = ''; lib.length = ''; lib.language = '';
        lib.categoryId = 0; lib.zemarianId = 0; lib.page = 1;
        ['mzSearch', 'mzCategoryFilter', 'mzZemarianFilter', 'mzLengthFilter', 'mzLanguageFilter'].forEach(function (id) {
            var el = $(id); if (el) el.value = '';
        });
        loadList();
    }

    function renderHymnRows(items) {
        var tb = $('mzTbody');
        if (!items.length) {
            // MZ-12: distinguish "filtered to nothing" from "library empty"
            // (Carbon/NNG empty-state pattern: reflect what was applied and
            // offer a recovery action). Every active filter counts.
            var filtered = !!(lib.search || lib.category || lib.length || lib.language || lib.categoryId || lib.zemarianId || lib.status === 'archived');
            tb.innerHTML = '<tr><td colspan="7">' + (filtered
                ? emptyState('fa-magnifying-glass', 'No matches', 'No hymns match your current search or filters.',
                    '<button class="btn-secondary btn-sm" onclick="Mezmur.clearFilters()"><i class="fa-solid fa-filter-circle-xmark"></i> Clear filters</button>')
                : emptyState('fa-music', 'No hymns yet', 'Start the library by adding the first hymn.',
                    '<button class="btn-primary btn-sm" onclick="Mezmur.openAdd()"><i class="fa-solid fa-plus"></i> Add Hymn</button>')) + '</td></tr>';
            return;
        }
        tb.innerHTML = items.map(function (h) {
            var archived = h.status === 'archived';
            var playing = window.MezmurPlayer && window.MezmurPlayer.currentId && Number(window.MezmurPlayer.currentId()) === Number(h.id);
            return '<tr data-hymn="' + h.id + '" class="' + (archived ? 'mz-archived' : '') + (playing ? ' mz-is-playing' : '') + '">' +
                '<td style="padding:.65rem .75rem">' + hymnArtCell(h) + '</td>' +
                '<td style="padding:.65rem .75rem;font-weight:600;color:var(--school-text-bright)">' + hi(h.title) +
                (h.snippet ? '<div class="text-dim" style="font-size:.72rem;font-weight:400;margin-top:2px">' + hi(h.snippet) + '</div>' : '') + '</td>' +
                '<td style="padding:.65rem .75rem">' + catBadges(h) + '</td>' +
                '<td style="padding:.65rem .75rem;color:var(--school-text-dim)">' + fmtDate(h.updated_at) + '</td>' +
                '<td style="padding:.65rem .75rem;text-align:right;white-space:nowrap">' +
                (h.audio_status === 'ready'
                    ? '<button class="btn-secondary btn-sm" title="Play" style="color:var(--school-success,#22c55e)" onclick="Mezmur.audioPlay(' + h.id + ')"><i class="fa-solid fa-circle-play"></i></button> ' +
                      '<button class="btn-secondary btn-sm" title="Manage audio" onclick="Mezmur.audioManage(' + h.id + ')"><i class="fa-solid fa-headphones"></i></button> '
                    : '<button class="btn-secondary btn-sm" title="' + (h.audio_status === 'pending' ? 'Finish audio upload' : 'Attach audio') + '" onclick="Mezmur.audioManage(' + h.id + ')"><i class="fa-solid fa-headphones"></i></button> ') +
                '<button class="btn-secondary btn-sm" title="View" onclick="Mezmur.view(' + h.id + ')"><i class="fa-solid fa-eye"></i></button> ' +
                '<button class="btn-secondary btn-sm" title="Edit" onclick="Mezmur.openEdit(' + h.id + ')"><i class="fa-solid fa-pen"></i></button> ' +
                (archived
                    ? '<button class="btn-secondary btn-sm" title="Restore" onclick="Mezmur.setStatus(' + h.id + ',\'active\')"><i class="fa-solid fa-rotate-left"></i></button>'
                    : '<button class="btn-secondary btn-sm" title="Archive" onclick="Mezmur.setStatus(' + h.id + ',\'archived\')"><i class="fa-solid fa-box-archive"></i></button>') +
                '</td></tr>';
        }).join('');
    }

    function renderLibPagination() {
        var el = $('mzPagination');
        if (!el) return;
        var dim = 'color:var(--school-text-dim);font-size:.8rem';
        var start = lib.total ? (lib.firstPage - 1) * lib.perPage + 1 : 0;
        var end = Math.min(lib.total, start + lib.shown - 1);
        var left = Math.max(0, lib.total - end);
        var sizes = [25, 50, 100].map(function (n) { return '<option value="' + n + '"' + (n === lib.perPage ? ' selected' : '') + '>' + n + '</option>'; }).join('');
        el.innerHTML =
            '<span id="mzLibRange" style="' + dim + '">' + (lib.total ? 'Showing ' + start + '\u2013' + end + ' of ' + lib.total + ' hymn' + (lib.total === 1 ? '' : 's') : '0 hymns') + '</span>' +
            '<span style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">' +
              '<button type="button" class="btn-secondary btn-sm" id="mzLibPrev" ' + (lib.firstPage <= 1 ? 'disabled' : '') + ' onclick="Mezmur.libPage(' + (lib.firstPage - 1) + ')" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></button>' +
              '<span style="' + dim + '">Page ' + (lib.firstPage === lib.page ? lib.page : lib.firstPage + '\u2013' + lib.page) + ' of ' + lib.totalPages + '</span>' +
              '<button type="button" class="btn-secondary btn-sm" id="mzLibNext" ' + (lib.page >= lib.totalPages ? 'disabled' : '') + ' onclick="Mezmur.libPage(' + (lib.page + 1) + ')" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></button>' +
              '<label style="' + dim + ';display:flex;gap:.3rem;align-items:center">Per page <select id="mzLibSize" class="school-input" style="width:auto;padding:.2rem .4rem" onchange="Mezmur.libPerPage(this.value)">' + sizes + '</select></label>' +
              '<button type="button" class="btn-secondary btn-sm" id="mzLibMore" ' + (left <= 0 ? 'disabled' : '') + ' onclick="Mezmur.libMore()">' +
                (left > 0 ? 'Load more (' + left + ' left)' : (lib.total ? '\u2713 All ' + lib.total + ' shown' : 'Load more')) + '</button>' +
            '</span>';
    }

    var catalog = { categories: [], zemarians: [], tab: 'categories' };
    var browseMode = 'all';

    /** P37: the filter toolbar is a one-way data flow (the pattern
     *  Google/Meta-scale admin tables use): user events and shortcuts
     *  write ONLY to `lib` (the single source of truth); rendering is
     *  a pure function of (catalog, lib); reconcileFilters() is the
     *  ONE place a stale filter may be dropped — and it says so with
     *  a toast, so the list can never appear to "filter by itself".
     *  (History: P31 removed renderCatalogList but left its call —
     *  the throw was swallowed by an empty catch, so the dropdowns
     *  silently never populated for five patches. Dead calls are now
     *  caught by the behavioral test, not just syntax checks.) */
    function loadCatalog() {
        var catsP = apiGet('action=categories').then(function (d) {
            return d && d.status === 'success' ? (d.items || []) : null;
        });
        var zemsP = apiGet('action=zemarians').then(function (d) {
            return d && d.status === 'success' ? (d.items || []) : null;
        });
        // P43: RETURN the promise. Callers could not previously wait for
        // the refresh, so a repaint scheduled after loadCatalog() ran
        // against stale state — part of why a new row appeared only
        // after a tab switch.
        return Promise.allSettled([catsP, zemsP]).then(function (res) {
            if (res[0].status === 'fulfilled' && res[0].value) catalog.categories = res[0].value;
            if (res[1].status === 'fulfilled' && res[1].value) catalog.zemarians = res[1].value;
            renderCatalogBoxes();
            renderFilterSelects();
            if (reconcileFilters()) loadList();
        }).catch(function (e) {
            console.error('catalog refresh failed', e); // never swallowed silently
        });
    }

    /** Render the filter toolbar from state — PURE: no mutations, no
     *  reloads. Options list active rows only (hidden taxonomy rows
     * cannot filter); the selected value always mirrors `lib`, so the
     * dropdowns can never lie about what is applied. */
    function renderFilterSelects() {
        var sel = $('mzZemarianFilter');
        if (sel) {
            var zems = (catalog.zemarians || []).filter(function (z) {
                return Number(z.is_active) === 1;
            });
            sel.innerHTML = '<option value="">All singers</option>' + zems.map(function (z) {
                return '<option value="' + Number(z.id) + '">' + esc(z.name) +
                    ' (' + (z.hymn_count || 0) + ')</option>';
            }).join('');
            sel.value = lib.zemarianId > 0 ? String(lib.zemarianId) : '';
        }
        var csel = $('mzCategoryFilter');
        if (csel) {
            var cats = catalog.categories || [];
            var mains = cats.filter(function (c) { return c.parent_id == null && Number(c.is_active) === 1; });
            var html = '<option value="">All categories</option>';
            mains.forEach(function (m) {
                var subs = cats.filter(function (c) {
                    return c.parent_id != null && c.parent_id === m.id && Number(c.is_active) === 1;
                });
                if (subs.length) {
                    html += '<optgroup label="' + esc(m.name) + ' (' + (m.hymn_count_total || 0) + ')">';
                    html += '<option value="' + Number(m.id) + '">All of ' + esc(m.name) + '</option>';
                    subs.forEach(function (sb) {
                        html += '<option value="' + Number(sb.id) + '">' + esc(sb.name) + ' (' + (sb.hymn_count || 0) + ')</option>';
                    });
                    html += '</optgroup>';
                } else {
                    html += '<option value="' + Number(m.id) + '">' + esc(m.name) + ' (' + (m.hymn_count_total || m.hymn_count || 0) + ')</option>';
                }
            });
            csel.innerHTML = html;
            csel.value = lib.categoryId > 0 ? String(lib.categoryId) : '';
        }
    }

    function activeCategory(id) {
        return (catalog.categories || []).some(function (c) {
            return Number(c.id) === Number(id) && Number(c.is_active) === 1;
        });
    }
    function activeZemarian(id) {
        return (catalog.zemarians || []).some(function (z) {
            return Number(z.id) === Number(id) && Number(z.is_active) === 1;
        });
    }

    /** The ONLY place a filter may be dropped automatically: when the
     *  row it points at no longer exists / was hidden. Always visible
     *  (toast) — a silent change is what made filtering look broken. */
    function reconcileFilters() {
        var dropped = [];
        if (lib.categoryId > 0 && !activeCategory(lib.categoryId)) {
            lib.categoryId = 0; lib.category = '';
            dropped.push('category');
        }
        if (lib.zemarianId > 0 && !activeZemarian(lib.zemarianId)) {
            lib.zemarianId = 0;
            dropped.push('singer');
        }
        if (!dropped.length) return false;
        renderFilterSelects();
        window.toast('Filter cleared — the ' + dropped.join(' and ') +
            ' you had selected is no longer available.', 'i');
        return true;
    }



    /** P23: hidden catalog entries stay pickable (a hymn may legitimately
     *  carry one) but are labelled — web and mobile curators now see the
     *  same truth instead of an invisible difference. */
    function catLabel(i) {
        var hidden = Number(i.is_active) !== 1;
        return esc(i.name) + (hidden ? ' <span class="text-dim" style="font-size:.68rem">(hidden)</span>' : '');
    }

    // ── P30 taxonomy pickers (dropdown panels; two-level for categories) ──
    var PICK_GRADIENTS = [
        ['#5A1212', '#D4AF37'], ['#4f46e5', '#7c3aed'], ['#0ea5e9', '#2563eb'],
        ['#059669', '#0d9488'], ['#d97706', '#dc2626'], ['#db2777', '#9333ea']
    ];
    function hashCode(str) {
        var h = 0;
        for (var i = 0; i < str.length; i++) { h = ((h << 5) - h + str.charCodeAt(i)) | 0; }
        return Math.abs(h);
    }
    function thumbHtml(item) {
        var img = item.image_url ? ' style="background-image:url(\'' + item.image_url + '\')"' : '';
        var label = item.image_url ? '' : esc((item.name || '?').trim().charAt(0));
        if (!img) {
            var g = PICK_GRADIENTS[hashCode(String(item.name || '')) % PICK_GRADIENTS.length];
            img = ' style="background:linear-gradient(135deg,' + g[0] + ',' + g[1] + ')"';
        }
        return '<span class="mz-thumb"' + img + ' aria-hidden="true">' + label + '</span>';
    }
    function pickItemHtml(box, item, checked) {
        // P36 audit: MAIN categories carry hymn_count_total (rolled-up);
        // singers have neither parent_id nor hymn_count_total — they fell
        // through to a hardcoded 0. Fall back to their own hymn_count.
        var count = item.parent_id == null
            ? (item.hymn_count_total != null ? item.hymn_count_total : (item.hymn_count || 0))
            : (item.hymn_count || 0);
        return '<label class="mz-pick-item"><input type="checkbox" value="' + Number(item.id) + '"' +
            (checked ? ' checked' : '') + '> ' + thumbHtml(item) +
            '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(item.name) + '</span>' +
            '<span class="text-dim" style="font-size:.7rem">' + count + '</span></label>';
    }
    function renderCatalogBoxes(selCats, selZem) {
        var zbox = $('mzZemariansBox');
        if (zbox) {
            var zems = catalog.zemarians || [];
            zbox.innerHTML = zems.map(function (z) {
                var checked = selZem && selZem.some(function (x) { return String(x.id) === String(z.id); });
                return pickItemHtml(zbox, z, checked);
            }).join('') || '<span class="text-dim" style="font-size:.75rem">No singers yet — add one first.</span>';
            updatePickBtn('mzZemPickBtn', 'mzZemariansBox', 'Select singers…');
        }
    }

    function updatePickBtn(btnId, boxId, placeholder) {
        var btn = $(btnId), box = $(boxId);
        if (!btn || !box) return;
        var picked = [];
        box.querySelectorAll('input:checked').forEach(function (cb) {
            var item = cb.closest('.mz-pick-item');
            var name = item ? item.querySelector('span[style*="flex"]') : null;
            picked.push(name ? name.textContent.trim() : '#' + cb.value);
        });
        btn.innerHTML = '';
        btn.appendChild(document.createTextNode(picked.length
            ? picked.slice(0, 3).join(', ') + (picked.length > 3 ? ' +' + (picked.length - 3) : '')
            : placeholder));
    }
    function initPickPanels() {
        [['mzZemPickBtn', 'mzZemariansBox']].forEach(function (pair) {
            var btn = $(pair[0]), box = $(pair[1]);
            if (!btn || !box || btn.dataset.p30) return;
            btn.dataset.p30 = '1';
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                box.classList.toggle('is-hidden');
            });
            box.addEventListener('click', function (e) {
                e.stopPropagation();
                setTimeout(function () { updatePickBtn(pair[0], pair[1], pair[0] === 'mzCatPickBtn' ? 'Select categories…' : 'Select singers…'); }, 0);
            });
        });
        document.addEventListener('click', function () {
            ['mzCategoriesBox', 'mzZemariansBox'].forEach(function (id) {
                var el = $(id); if (el) el.classList.add('is-hidden');
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            ['mzCategoriesBox', 'mzZemariansBox'].forEach(function (id) {
                var el = $(id); if (el) el.classList.add('is-hidden');
            });
        });
    }

    function checkedIds(boxId) {
        var ids = [];
        var box = document.getElementById(boxId);
        if (!box) return ids;
        box.querySelectorAll('input:checked').forEach(function (cb) {
            var id = parseInt(cb.value, 10);
            if (id > 0) ids.push(id);
        });
        return ids;
    }

    // ── browse tabs (All / Categories / Zemarians) ──
    /** View-modal shortcuts write state, then the SAME pipeline the
     *  dropdowns use renders it — one path, one truth. A shortcut to
     *  a since-hidden row reconciles visibly instead of filtering
     *  invisibly. */
    function applyFilterState() {
        reconcileFilters();
        renderFilterSelects();
        loadList();
    }
    function browseCategory(id) {
        lib.categoryId = id; lib.zemarianId = 0; lib.page = 1;
        applyFilterState();
    }
    function browseZemarian(id) {
        lib.zemarianId = id; lib.categoryId = 0; lib.page = 1;
        applyFilterState();
    }

    // ── standalone catalog manager (P31: its own section; every edit
    //    is INLINE — no popups, no browser dialogs) ──
    var mgr = { tab: 'categories', edit: null, uploading: 0, open: {} };

    function hymnFormHasDraft() {
        var modal = $('mzHymnModal');
        if (!modal || modal.classList.contains('is-hidden')) return false;
        var t = ($('mzTitle').value || '').trim();
        var body = ($('mzLyrics').value || '').trim();
        return !!(t || body);
    }
    function openCatalog(kind) {
        // navigate to the standalone Catalog section; an open hymn form
        // must close for it — but never silently discard a draft.
        var go = function () {
            closeModalF('mzHymnModal');
            var nav = document.querySelector('.school-nav-link[data-section="catalog"]');
            if (nav) nav.click(); else loadTab('catalog');
            mgrTab(kind || 'categories');
        };
        if (hymnFormHasDraft()) {
            sysConfirm('Leave the hymn form? Unsaved changes will be lost.', go, { tier: 'low', title: 'Leave the hymn form?', summary: 'Unsaved changes will be lost.', confirmLabel: 'Leave without saving' });
        } else {
            go();
        }
    }
    function mgrTab(kind) {
        mgr.tab = kind;
        var c = $('mzMgrCatTabBtn'), z = $('mzMgrZemTabBtn');
        if (c) { c.classList.toggle('active', kind === 'categories'); c.setAttribute('aria-pressed', kind === 'categories' ? 'true' : 'false'); }
        if (z) { z.classList.toggle('active', kind === 'zemarians'); z.setAttribute('aria-pressed', kind === 'zemarians' ? 'true' : 'false'); }
        $('mzMgrCats').classList.toggle('is-hidden', kind !== 'categories');
        $('mzMgrZems').classList.toggle('is-hidden', kind === 'categories');
        renderCatalogManager();
    }

    function mgrCats() { return (catalog.categories || []); }
    function mgrMains() { return mgrCats().filter(function (c) { return c.parent_id == null; }); }
    function mgrSubsOf(id) { return mgrCats().filter(function (c) { return c.parent_id != null && c.parent_id === id; }); }

    function gradOf(item) {
        // P32: an admin-pinned gradient wins; otherwise the automatic
        // name-hashed palette.
        if (item && item.gradient_start && item.gradient_end) {
            return [item.gradient_start, item.gradient_end];
        }
        return PICK_GRADIENTS[hashCode(String((item && item.name) || '')) % PICK_GRADIENTS.length];
    }

    function mgrThumb(item, cls) {
        var img = item.image_url ? ' style="background-image:url(\'' + item.image_url + '\')"' : '';
        var label = item.image_url ? '' : esc((item.name || '?').trim().charAt(0));
        if (!img) {
            var g = gradOf(item);
            img = ' style="background:linear-gradient(135deg,' + g[0] + ',' + g[1] + ')"';
        }
        return '<span class="' + (cls || 'mz-thumb') + '"' + img + ' aria-hidden="true">' + label + '</span>';
    }

    function mgrNameCell(item, editing, isSub) {
        if (editing) {
            // Categories carry a single name (no translation column);
            // only singers have the Amharic name field.
            return '<div class="mz-mgr-edit">' +
                '<input id="mzMgrEditName" class="school-input" maxlength="50" value="' + esc(item.name) + '">' +
                '<button id="mzMgrEditSave" class="btn-primary btn-sm" onclick="Mezmur.mgrSave(' + item.id + ')" aria-label="Save name"><i class="fa-solid fa-check"></i></button> ' +
                '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrCancel()"><i class="fa-solid fa-xmark"></i></button></div>';
        }
        var hidden = Number(item.is_active) !== 1;
        return '<div class="mz-mgr-name">' + mgrThumb(item, 'mz-mgr-thumb') +
            '<span class="mz-mgr-namelabel"' + (hidden ? ' style="opacity:.5"' : '') + '>' + esc(item.name) +
            (hidden ? ' <span class="text-dim" style="font-size:.68rem">(hidden)</span>' : '') + '</span></div>';
    }

    function renderCatalogManager() {
        var rows = $('mzMgrCatRows');
        if (rows) {
            var html = '';
            // P34: mains only — a chevron expands a main's subs inline
            // (short list; managing/navigating stays easy at any size).
            mgrMains().forEach(function (m) {
                var editing = mgr.edit === 'cat:' + m.id;
                var count = m.hymn_count_total || 0;
                var subs = mgrSubsOf(m.id);
                var open = !!mgr.open[m.id] || mgr.edit === 'addsub:' + m.id;
                var chev = '<button class="btn-secondary btn-sm mz-cmgr-exp' + (open ? ' open' : '') + '"' +
                    ' title="' + (open ? 'Collapse' : 'Expand') + ' sub-categories"' +
                    ' aria-expanded="' + (open ? 'true' : 'false') + '"' +
                    ' aria-label="Sub-categories of ' + esc(m.name) + '"' +
                    ' onclick="Mezmur.mgrToggleOpen(' + m.id + ')"><i class="fa-solid fa-chevron-right"></i></button> ';
                var nameHtml = editing ? mgrNameCell(m, editing, false)
                    : '<div class="mz-mgr-name">' + chev + mgrThumb(m, 'mz-mgr-thumb') +
                      '<span class="mz-mgr-namelabel">' + esc(m.name) +
                      (Number(m.is_active) !== 1 ? ' <span class="text-dim" style="font-size:.68rem">(hidden)</span>' : '') +
                      (subs.length ? ' <span class="text-dim" style="font-size:.7rem">· ' + subs.length + ' sub' + (subs.length === 1 ? '' : 's') + '</span>' : '') +
                      '</span></div>';
                html += '<tr' + (mgr.uploading === m.id ? ' class="mz-mgr-busy"' : '') + '>' +
                    '<td></td>' +
                    '<td>' + nameHtml + '</td>' +
                    '<td class="text-dim">' + count + '</td>' +
                    '<td>' + mgrSortButtons('cat', m.id) + '</td>' +
                    '<td>' + mgrCatActions(m) + '</td></tr>';
                if (!open) return; // collapsed: subs hidden until asked
                mgrSubsOf(m.id).forEach(function (sb) {
                    var editingSub = mgr.edit === 'cat:' + sb.id;
                    html += '<tr class="mz-mgr-sub"' + (mgr.uploading === sb.id ? ' style="opacity:.45"' : '') + '>' +
                        '<td>' + mgrThumb(sb, 'mz-mgr-thumb') + '</td>' +
                        '<td>' + mgrNameCell(sb, editingSub, true) + '</td>' +
                        '<td class="text-dim">' + (sb.hymn_count || 0) + '</td>' +
                        '<td>' + mgrSortButtons('sub', sb.id) + '</td>' +
                        '<td>' + mgrCatActions(sb, m.id) + '</td></tr>';
                });
                // inline "add sub" row (opened per main)
                if (mgr.edit === 'addsub:' + m.id) {
                    html += '<tr class="mz-mgr-sub"><td></td><td colspan="4"><div class="mz-mgr-edit">' +
                        '<input id="mzMgrSubName" class="school-input" maxlength="50" placeholder="New sub-category name…">' +
                        '<button id="mzMgrSubAdd" class="btn-primary btn-sm" onclick="Mezmur.mgrAddSub(' + m.id + ')"><i class="fa-solid fa-check"></i> Add</button> ' +
                        '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrCancel()"><i class="fa-solid fa-xmark"></i></button></div></td></tr>';
                }
            });
            rows.innerHTML = html || '<tr><td colspan="5" class="text-dim" style="padding:.9rem .75rem">No categories yet — add the first main category above.</td></tr>';
            afterMgrRender('mzMgrEditName');
        }
        var zrows = $('mzMgrZemRows');
        if (zrows) {
            zrows.innerHTML = (catalog.zemarians || []).map(function (z) {
                var editing = mgr.edit === 'zem:' + z.id;
                var hidden = Number(z.is_active) !== 1;
                var nameCell = editing
                    ? '<div class="mz-mgr-edit">' +
                      '<input id="mzMgrEditName" class="school-input amharic" maxlength="100" value="' + esc(z.name) + '">' +
                      '<button id="mzMgrEditSave" class="btn-primary btn-sm" onclick="Mezmur.mgrSave(' + z.id + ')" aria-label="Save name"><i class="fa-solid fa-check"></i></button> ' +
                      '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrCancel()"><i class="fa-solid fa-xmark"></i></button></div>'
                    : '<span style="' + (hidden ? 'opacity:.5;' : '') + 'font-size:.84rem;font-weight:600">' + esc(z.name) +
                      (hidden ? ' <span class="text-dim" style="font-size:.68rem">(hidden)</span>' : '') + '</span>';
                return '<tr' + (mgr.uploading === z.id ? ' class="mz-mgr-busy"' : '') + '><td>' + mgrThumb(z, 'mz-mgr-thumb') + '</td>' +
                    '<td>' + nameCell + '</td>' +
                    '<td class="text-dim">' + (z.hymn_count || 0) + '</td>' +
                    '<td>' +
                    (editing ? '' :
                    '<button class="btn-secondary btn-sm" title="Rename" onclick="Mezmur.mgrEdit(' + z.id + ')"><i class="fa-solid fa-pen"></i></button> ' +
                    '<button class="btn-secondary btn-sm" title="Set cover image" onclick="Mezmur.mgrImage(' + z.id + ', true)"><i class="fa-solid fa-image"></i></button> ' +
                    (z.image_url ? '<button class="btn-secondary btn-sm" title="Remove cover image" onclick="Mezmur.mgrRemoveZemImage(' + z.id + ')"><i class="fa-solid fa-hide"></i></button> ' : '') +
                    '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrToggle(' + z.id + ')">' + (hidden ? 'Show' : 'Hide') + '</button>') +
                    '</td></tr>';
            }).join('') || '<tr><td colspan="4" class="text-dim" style="padding:.9rem .75rem">No singers yet — add the first one above.</td></tr>';
            afterMgrRender('mzMgrEditName');
        }
    }
    function afterMgrRender(inputId) {
        var el = $(inputId);
        if (el) { el.focus(); el.select(); }
    }
    function mgrSortButtons(kind, id) {
        return '<div style="display:flex;gap:.25rem">' +
            '<button class="btn-secondary btn-sm" title="Move up" onclick="Mezmur.mgrSort(' + id + ',-1)"><i class="fa-solid fa-arrow-up"></i></button> ' +
            '<button class="btn-secondary btn-sm" title="Move down" onclick="Mezmur.mgrSort(' + id + ',1)"><i class="fa-solid fa-arrow-down"></i></button></div>';
    }
    function mgrCatActions(item, mainId) {
        if (mgr.edit === 'cat:' + item.id) return '';
        var hidden = Number(item.is_active) !== 1;
        var isMain = item.parent_id == null;
        return '<div style="display:flex;gap:.25rem;flex-wrap:wrap">' +
            '<button class="btn-secondary btn-sm" title="Rename" onclick="Mezmur.mgrEdit(' + item.id + ')"><i class="fa-solid fa-pen"></i></button> ' +
            '<button class="btn-secondary btn-sm" title="Set cover image" onclick="Mezmur.mgrImage(' + item.id + ')"><i class="fa-solid fa-image"></i></button> ' +
            '<button class="btn-secondary btn-sm" title="Cover color" onclick="Mezmur.mgrColors(' + item.id + ')"><i class="fa-solid fa-palette"></i></button> ' +
            (isMain && mainId === undefined
                ? '<button class="btn-secondary btn-sm" title="Add sub-category" onclick="Mezmur.mgrAddSubOpen(' + item.id + ')"><i class="fa-solid fa-plus"></i> Sub</button> ' : '') +
            '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrToggle(' + item.id + ')">' + (hidden ? 'Show' : 'Hide') + '</button></div>';
    }
    function mgrEdit(id) { mgr.edit = 'cat:' + id; if (mgrIsZem(id)) mgr.edit = 'zem:' + id; renderCatalogManager(); }
    function mgrIsZem(id) {
        return (catalog.zemarians || []).some(function (z) { return Number(z.id) === Number(id); }) &&
               !mgrCats().some(function (c) { return Number(c.id) === Number(id); });
    }
    function mgrCancel() { mgr.edit = null; renderCatalogManager(); }
    function mgrAddSubOpen(mainId) {
        mgr.edit = 'addsub:' + mainId;
        mgr.open[mainId] = true; // adding reveals the pane
        renderCatalogManager();
    }
    function mgrToggleOpen(id) {
        mgr.open[id] = !mgr.open[id];
        renderCatalogManager();
    }
    /**
     * P43: run a catalog mutation with REAL feedback.
     *
     * Every add/rename handler used to end `.catch(function () {})` —
     * errors were swallowed entirely. Combined with the fact that
     * loadCatalog() refreshes state but does NOT re-render the catalog
     * manager, the result was the reported symptom: you click Add,
     * nothing visibly happens, and the row only appears after switching
     * tabs and back. Success was invisible and failure was silent.
     *
     * This gives every mutation the four states a user needs:
     *   pending  — the button disables and says "Saving…", so a second
     *              click cannot double-submit
     *   success  — a toast, and an IMMEDIATE re-render
     *   failure  — the real server message, and the input is preserved
     *              so nothing typed is lost
     *   always   — the button is restored, even on an exception
     */
    function mgrMutate(btn, payload, okMsg, onOk) {
        var restore = null;
        if (btn) {
            restore = btn.innerHTML;
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
        }
        var release = function () {
            if (!btn) return;
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            btn.innerHTML = restore;
        };
        return apiPost(payload).then(function (d) {
            if (!d || d.status !== 'success') {
                // Surface what the server actually said (duplicate name,
                // permission, validation) instead of a blank freeze.
                window.toast((d && d.message) || 'Could not save. Please try again.', 'e');
                release();
                return false;
            }
            window.toast(okMsg, 's');
            if (typeof onOk === 'function') onOk();
            release();
            // Refresh state AND repaint the manager — loadCatalog alone
            // does not repaint it, which is why the row used to appear
            // only after a tab switch.
            return loadCatalog().then(function () { renderCatalogManager(); });
        }).catch(function (err) {
            window.toast((err && err.message) || 'Network error — nothing was saved.', 'e');
            release();
            return false;
        });
    }

    function mgrSave(id) {
        var name = ($('mzMgrEditName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        var isZem = mgr.edit === 'zem:' + id;
        var payload = isZem
            ? { action: 'save_zemarian', id: id, name: name, name_am: name } // P35: one Amharic name
            : { action: 'save_category', id: id, name: name, parent_id: mgrParentOf(id) };
        mgrMutate(
            document.getElementById('mzMgrEditSave'),
            payload,
            'Renamed to “' + name + '”.',
            function () { mgr.edit = null; }
        );
    }
    function mgrParentOf(id) {
        var found = mgrCats().filter(function (c) { return Number(c.id) === Number(id); })[0];
        return found && found.parent_id != null ? found.parent_id : '';
    }
    function mgrAddMain() {
        var name = ($('mzMgrMainName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        mgrMutate(
            document.getElementById('mzMgrMainAdd'),
            { action: 'save_category', name: name },
            'Category “' + name + '” added.',
            function () { var f = $('mzMgrMainName'); if (f) f.value = ''; }
        );
    }
    function mgrAddSub(mainId) {
        var name = ($('mzMgrSubName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        mgrMutate(
            document.getElementById('mzMgrSubAdd'),
            { action: 'save_category', name: name, parent_id: mainId },
            'Sub-category “' + name + '” added.',
            function () { mgr.edit = null; var f = $('mzMgrSubName'); if (f) f.value = ''; }
        );
    }
    function mgrAddZem() {
        var name = ($('mzMgrZemName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        // P35: singers carry ONE name, written in Amharic — stored in
        // both name (canonical display/filter field) and name_am.
        mgrMutate(
            document.getElementById('mzMgrZemAdd'),
            { action: 'save_zemarian', name: name, name_am: name },
            'Singer “' + name + '” added.',
            function () { var f = $('mzMgrZemName'); if (f) f.value = ''; }
        );
    }
    function mgrToggle(id) {
        var isZem = (catalog.zemarians || []).some(function (z) { return Number(z.id) === Number(id); }) &&
                    !mgrCats().some(function (c) { return Number(c.id) === Number(id); });
        var list = isZem ? catalog.zemarians : mgrCats();
        var found = (list || []).filter(function (i) { return Number(i.id) === Number(id); })[0];
        var active = found ? Number(found.is_active) === 1 : true;
        var payload = isZem
            ? { action: 'zemarian_status', id: id, active: active ? 0 : 1 }
            : { action: 'category_status', id: id, active: active ? 0 : 1 };
        if (!active) { mgrMutate(null, payload, 'Shown.'); return; }
        // Danger confirmation (MEDIUM) when hiding; showing again needs none.
        var what = isZem ? 'singer' : 'category';
        var nm = (found && found.name) || ('this ' + what);
        sysConfirm('Hide ' + nm + '?', function () { mgrMutate(null, payload, 'Hidden.'); },
            { tier: 'medium', title: 'Hide the ' + what + ' \u201c' + nm + '\u201d?', object: nm, summary: 'It is hidden, not deleted.', intent: 'I want to hide this ' + what,
              consequences: ['It disappears from the mobile app and the public hymn lists.', isZem ? 'Hymns by this singer are no longer grouped under them in the app.' : 'Hymns in this category are no longer listed under it in the app.'],
              keeps: ['No hymns are deleted.', 'You can show it again at any time.'], phrase: isZem ? 'HIDE SINGER' : 'HIDE CATEGORY', confirmLabel: 'Hide ' + what });
    }
    function mgrSort(id, dir) {
        // swap sort_order with the adjacent sibling in the same level
        var found = mgrCats().filter(function (c) { return Number(c.id) === Number(id); })[0];
        if (!found) return;
        var siblings = found.parent_id == null ? mgrMains() : mgrSubsOf(found.parent_id);
        var idx = siblings.findIndex(function (c) { return Number(c.id) === Number(id); });
        var other = siblings[idx + dir];
        if (!other) { window.toast('Already at the ' + (dir < 0 ? 'top' : 'bottom') + '.', 'i'); return; }
        var a = found.sort_order || 0, b = other.sort_order || 0;
        var done = 0;
        function fin() { done++; if (done >= 2) loadCatalog(); }
        apiPost({ action: 'save_category', id: found.id, name: found.name, parent_id: found.parent_id == null ? '' : found.parent_id, sort_order: b === a ? a + dir : b }).then(fin).catch(fin);
        apiPost({ action: 'save_category', id: other.id, name: other.name, parent_id: other.parent_id == null ? '' : other.parent_id, sort_order: a === b ? b - dir : a }).then(fin).catch(fin);
    }
    var imgPick = { id: 0, file: null, url: '', kind: 'cat' };

    // ── P66 hymn art (per-hymn cover image, Streaming-style) ──────
    // Set/replace + remove reuse the SAME hardened image dialog the
    // category/singer covers use (preview → POST); only the action
    // name and the post-refresh differ.
    function mgrArt(id) {
        mgrImage(id, false, true);
    }

    function mgrArtRemove(id) {
        sysConfirm('Remove this hymn\'s cover art?', function () {
            apiPost({ action: 'art_remove', id: id }).then(function (d) {
                if (d.status !== 'success') { window.toast(d.message || 'Failed.', 'e'); return; }
                window.toast(d.message || 'Cover art removed.', 's');
                refreshAfterArtChange(id);
            }).catch(function () { window.toast('Connection error.', 'e'); });
        }, { tier: 'low', title: 'Remove this hymn\'s cover art?', summary: 'The app shows the default artwork instead.', confirmLabel: 'Remove cover art' });
    }

    /** One refresh point after any art mutation: reload the hymn
     *  table, and if the view modal is open for THIS hymn, re-render
     *  its hero so the change is visible immediately. */
    function refreshAfterArtChange(id) {
        loadList();
        var modalEl = $('mzViewModal');
        if (modalEl && modalEl.classList.contains('show') && Number(viewArtId) === Number(id)) {
            viewHymn(id);
        }
    }

    function mgrRemoveZemImage(id) {
        sysConfirm('Remove the singer\'s cover image?', function () {
            apiPost({ action: 'zemarian_image_remove', id: id }).then(function (d) {
                if (d.status !== 'success') { window.toast(d.message || 'Failed.', 'e'); return; }
                window.toast(d.message || 'Cover image removed.', 's');
                loadCatalog();
            }).catch(function () {});
        }, { tier: 'low', title: 'Remove the singer\'s cover image?', summary: 'The default image is shown instead.', confirmLabel: 'Remove image' });
    }

    function mgrImage(id, zem, art) {
        var input = $('mzMgrFile');
        if (!input) return;
        input.value = '';
        input.onchange = function () {
            var file = input.files && input.files[0];
            if (!file) return;
            // P66: hymn art accepts up to 4 MB (the server re-encodes it
            // to three square JPEG renditions anyway); category/singer
            // covers keep their 2 MB budget.
            var capMb = art ? 4 : 2;
            if (file.size > capMb * 1024 * 1024) {
                window.toast('Image is larger than ' + capMb + ' MB.', 'e');
                return;
            }
            // P32: a real preview BEFORE the upload leaves the device.
            imgPick = { id: id, file: file, url: URL.createObjectURL(file), kind: art ? 'art' : (zem ? 'zem' : 'cat') };
            $('mzImgPreviewImg').src = imgPick.url;
            $('mzImgMeta').textContent = file.name + ' · ' +
                (file.size / 1024).toFixed(0) + ' KB · ' + (file.type || 'image');
            openModalF('mzImageDialog');
        };
        input.click();
    }
    function initImageDialog() {
        var el = $('mzImageDialog');
        if (!el || el.dataset.p32) return;
        el.dataset.p32 = '1';
        $('mzImgUpload').addEventListener('click', function () {
            var file = imgPick.file;
            if (!file) { closeModalF('mzImageDialog'); return; }
            var id = imgPick.id;
            var kind = imgPick.kind; // captured BEFORE the reset below
            closeModalF('mzImageDialog');
            URL.revokeObjectURL(imgPick.url);
            imgPick = { id: 0, file: null, url: '', kind: 'cat' };
            if (kind === 'art') {
                // P66 hymn art: no catalog row to gray out — the hymn
                // table refreshes when the POST settles.
                var fda = new FormData();
                fda.append('action', 'art_upload');
                fda.append('id', id);
                fda.append('image', file);
                apiPost(fda).then(function (d) {
                    if (d.status !== 'success') { window.toast(d.message || 'Upload failed.', 'e'); return; }
                    window.toast(d.message || 'Cover art updated.', 's');
                    refreshAfterArtChange(id);
                }).catch(function () { window.toast('Upload failed — connection error.', 'e'); });
                return;
            }
            mgr.uploading = id;
            renderCatalogManager();
            var fd = new FormData();
            fd.append('action', kind === 'zem' ? 'zemarian_image' : 'category_image');
            fd.append('id', id);
            fd.append('image', file);
            apiPost(fd).then(function (d) {
                mgr.uploading = 0;
                if (d.status !== 'success') { window.toast(d.message || 'Upload failed.', 'e'); renderCatalogManager(); return; }
                window.toast(d.message || 'Image updated.', 's');
                loadCatalog();
            }).catch(function () { mgr.uploading = 0; renderCatalogManager(); });
        });
        $('mzImgCancel').addEventListener('click', function () {
            URL.revokeObjectURL(imgPick.url);
            imgPick = { id: 0, file: null, url: '', kind: 'cat' };
            closeModalF('mzImageDialog');
        });
    }

    // ── cover color dialog (P32: gradient picker + live preview) ──
    var colorPick = { id: 0, name: '', start: '', end: '', auto: false, hasImage: false, startOp: 100, endOp: 100 };

    // '#rrggbb' + optional alpha pair from a picked opacity (P33).
    function withAlpha(hex, op) {
        hex = String(hex || '#000000').slice(0, 7);
        if (!op || op >= 100) return hex;
        var a = Math.round(255 * op / 100).toString(16).padStart(2, '0');
        return hex + a;
    }
    function opOf(v) {
        v = String(v || '');
        return /^#[0-9a-fA-F]{8}$/.test(v)
            ? Math.round(parseInt(v.slice(7, 9), 16) * 100 / 255)
            : 100;
    }

    function mgrColors(id) {
        var found = mgrCats().filter(function (c) { return Number(c.id) === Number(id); })[0];
        if (!found) return;
        var auto = PICK_GRADIENTS[hashCode(String(found.name || '')) % PICK_GRADIENTS.length];
        colorPick = {
            id: id,
            name: String(found.name || ''),
            start: String(found.gradient_start || auto[0]).slice(0, 7),
            end: String(found.gradient_end || auto[1]).slice(0, 7),
            auto: !found.gradient_start && !found.gradient_end,
            hasImage: !!found.image_url,
            startOp: opOf(found.gradient_start),
            endOp: opOf(found.gradient_end)
        };
        $('mzColorPreviewName').textContent = colorPick.name;
        renderSwatches();
        refreshColorPreview();
        openModalF('mzColorDialog');
    }
    function renderSwatches() {
        var box = $('mzSwatches');
        box.innerHTML = PICK_GRADIENTS.map(function (g, i) {
            var sel = !colorPick.auto && g[0] === colorPick.start && g[1] === colorPick.end;
            return '<button type="button" class="mz-swatch' + (sel ? ' sel' : '') + '"' +
                ' style="background:linear-gradient(135deg,' + g[0] + ',' + g[1] + ')"' +
                ' aria-label="Preset ' + (i + 1) + '"' + (sel ? ' aria-pressed="true"' : ' aria-pressed="false"') +
                ' data-gs="' + g[0] + '" data-ge="' + g[1] + '"></button>';
        }).join('');
        Array.prototype.forEach.call(box.querySelectorAll('.mz-swatch'), function (b) {
            b.addEventListener('click', function () {
                colorPick.start = b.getAttribute('data-gs');
                colorPick.end = b.getAttribute('data-ge');
                colorPick.auto = false;
                renderSwatches();
                refreshColorPreview();
            });
        });
    }
    function refreshColorPreview() {
        var p = $('mzColorPreview');
        var grad = colorPick.auto
            ? PICK_GRADIENTS[hashCode(colorPick.name) % PICK_GRADIENTS.length]
            : [colorPick.start, colorPick.end];
        // P33: the checkerboard sits UNDER the (possibly transparent)
        // gradient, so opacity is visible — exactly like design tools.
        p.classList.add('mz-checker');
        p.style.setProperty('--mz-gs', withAlpha(grad[0], colorPick.auto ? 100 : colorPick.startOp));
        p.style.setProperty('--mz-ge', withAlpha(grad[1], colorPick.auto ? 100 : colorPick.endOp));
        if (colorPick.auto) p.setAttribute('data-auto', '1');
        else { p.removeAttribute('data-auto'); }
        $('mzGradStart').value = grad[0];
        $('mzGradEnd').value = grad[1];
        $('mzGradStartOp').value = colorPick.auto ? 100 : colorPick.startOp;
        $('mzGradEndOp').value = colorPick.auto ? 100 : colorPick.endOp;
        $('mzGradStartOpV').textContent = (colorPick.auto ? 100 : colorPick.startOp) + '%';
        $('mzGradEndOpV').textContent = (colorPick.auto ? 100 : colorPick.endOp) + '%';
        $('mzColorNote').textContent = colorPick.hasImage
            ? 'A cover image is set — the gradient shows only after the image is removed.'
            : 'This gradient shows wherever the category appears without a cover image.';
        $('mzRemoveImg').style.display = colorPick.hasImage ? '' : 'none';
    }
    function initColorDialog() {
        var el = $('mzColorDialog');
        if (!el || el.dataset.p32) return;
        el.dataset.p32 = '1';
        $('mzGradStart').addEventListener('input', function () {
            colorPick.start = this.value; colorPick.auto = false; renderSwatches(); refreshColorPreview();
        });
        $('mzGradEnd').addEventListener('input', function () {
            colorPick.end = this.value; colorPick.auto = false; renderSwatches(); refreshColorPreview();
        });
        $('mzGradStartOp').addEventListener('input', function () {
            colorPick.startOp = Number(this.value); colorPick.auto = false; refreshColorPreview();
        });
        $('mzGradEndOp').addEventListener('input', function () {
            colorPick.endOp = Number(this.value); colorPick.auto = false; refreshColorPreview();
        });
        $('mzGradAuto').addEventListener('click', function () {
            colorPick.auto = true;
            var g = PICK_GRADIENTS[hashCode(colorPick.name) % PICK_GRADIENTS.length];
            colorPick.start = g[0]; colorPick.end = g[1];
            colorPick.startOp = 100; colorPick.endOp = 100;
            renderSwatches(); refreshColorPreview();
        });
        $('mzGradSave').addEventListener('click', function () {
            closeModalF('mzColorDialog');
            apiPost({
                action: 'save_category', id: colorPick.id, name: colorPick.name,
                parent_id: mgrParentOf(colorPick.id),
                gradient_start: colorPick.auto ? '' : withAlpha(colorPick.start, colorPick.startOp),
                gradient_end: colorPick.auto ? '' : withAlpha(colorPick.end, colorPick.endOp)
            }).then(function (d) {
                if (d.status !== 'success') { window.toast(d.message || 'Could not save the color.', 'e'); return; }
                window.toast(d.message || 'Cover color saved.', 's');
                loadCatalog();
            }).catch(function () {});
        });
        $('mzRemoveImg').addEventListener('click', function () {
            sysConfirm('Remove the cover image? The gradient will show instead.', function () {
                closeModalF('mzColorDialog');
                apiPost({ action: 'category_image_remove', id: colorPick.id }).then(function (d) {
                    if (d.status !== 'success') { window.toast(d.message || 'Failed.', 'e'); return; }
                    window.toast(d.message || 'Cover image removed.', 's');
                    loadCatalog();
                }).catch(function () {});
            }, { tier: 'low', title: 'Remove the cover image?', summary: 'The colour gradient is shown instead.', confirmLabel: 'Remove image' });
        });
    }

    // ── hymn form: cascading category -> sub-category selects ──
    function populateHymnCats(selectedCatId) {
        var mainSel = $('mzHymnMainCat'), subSel = $('mzHymnSubCat');
        if (!mainSel || !subSel) return;
        var mains = mgrMains();
        var keepMain = mainSel.value;
        mainSel.innerHTML = '<option value="">— Select category —</option>' + mains.map(function (m) {
            return '<option value="' + Number(m.id) + '">' + esc(m.name) + '</option>';
        }).join('');
        // preselect from the edited hymn's link (sub -> its parent; main -> itself)
        if (selectedCatId) {
            var linked = mgrCats().filter(function (c) { return Number(c.id) === Number(selectedCatId); })[0];
            if (linked) {
                keepMain = linked.parent_id != null ? String(linked.parent_id) : String(linked.id);
            }
        }
        if (keepMain) mainSel.value = keepMain;
        hymnSubOptions(keepMain, selectedCatId);
    }
    function hymnSubOptions(mainId, selectId) {
        var subSel = $('mzHymnSubCat');
        if (!subSel) return;
        if (!mainId) {
            subSel.innerHTML = '<option value="">Select a category first…</option>';
            subSel.disabled = true;
            return;
        }
        var subs = mgrSubsOf(Number(mainId));
        if (!subs.length) {
            // a main with no subs: the hymn files under the main itself
            subSel.innerHTML = '<option value="' + Number(mainId) + '">No sub-categories — use the main category</option>';
            subSel.value = String(mainId);
            subSel.disabled = true;
            return;
        }
        subSel.disabled = false;
        subSel.innerHTML = '<option value="">— Select sub-category —</option>' + subs.map(function (sb) {
            return '<option value="' + Number(sb.id) + '">' + esc(sb.name) + '</option>';
        }).join('');
        subSel.value = selectId ? String(selectId) : subs.length === 1 ? String(subs[0].id) : '';
    }
    function onHymnMainChange() { hymnSubOptions($('mzHymnMainCat') ? $('mzHymnMainCat').value : '', null); }
    function selectedCategoryIds() {
        var subSel = $('mzHymnSubCat');
        var v = subSel ? parseInt(subSel.value, 10) : 0;
        return v > 0 ? [v] : [];
    }

    function clearHymnForm() {
        $('mzHymnId').value = '0'; $('mzTitle').value = '';
        setEditorMarkup('');
        populateHymnCats(null);
        $('mzLength').value = 'long'; $('mzLanguage').value = 'amharic';
        renderCatalogBoxes([], []);
        showError($('mzModalError'), '');
    }

    function openAdd() {
        clearHymnForm();
        $('mzModalTitle').innerHTML = '<i class="fa-solid fa-music"></i> Add Hymn';
        openModalF('mzHymnModal', '#mzTitle');
    }

    function openEdit(id) {
        clearHymnForm();
        $('mzModalTitle').innerHTML = '<i class="fa-solid fa-pen"></i> Edit Hymn';
        apiGet('action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load this hymn.', 'e'); return; }
            var h = d.item;
            $('mzHymnId').value = h.id; $('mzTitle').value = h.title || '';
            setEditorMarkup(h.lyrics || '');
            $('mzLength').value = h.length || 'long'; $('mzLanguage').value = h.language || 'amharic';
            renderCatalogBoxes(h.categories || [], h.zemarians || []);
            populateHymnCats((h.categories || [])[0] && h.categories[0].id);
            openModalF('mzHymnModal', '#mzTitle');
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    function saveHymn() {
        var title = $('mzTitle').value.trim();
        if (!title) { showError($('mzModalError'), 'Title is required.'); $('mzTitle').focus(); return; }
        if (!selectedCategoryIds().length) {
            showError($('mzModalError'), 'Choose a category and sub-category for the hymn.');
            return;
        }
        var btn = $('mzSaveBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
        apiPost({
            action: 'save', id: $('mzHymnId').value, title: title,
            categories: selectedCategoryIds(),
            zemarians: checkedIds('mzZemariansBox'),
            length: $('mzLength').value, language: $('mzLanguage').value,
            lyrics: $('mzLyrics').value
        }).then(function (d) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Hymn';
            if (d.status !== 'success') { showError($('mzModalError'), d.message || 'Unable to save the hymn.'); return; }
            closeModalF('mzHymnModal');
            window.toast(d.message || 'Hymn saved.', 's');
            loadStats(); loadList();
        }).catch(function (err) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Hymn';
            showError($('mzModalError'), ((err && err.message) || 'Connection error.') + staleHint(err));
        });
    }

    /** P24: styled lyrics rendering. Plain text is stored;
     *  [Section] lines become headers, **bold** / *italic* become emphasis.
     *  ESCAPE FIRST — everything after that only adds our own safe tags. */
    // ── P30 visual lyrics editor: type styled text, store the same
    //    portable markup (**bold**, *italic*, __underline__, [Section])
    //    so old data and every existing client keep working as-is. ──
    function markupToHtml(src) {
        var txt = String(src == null ? '' : src);
        if (!txt.trim()) return '';
        return txt.split(/\r?\n/).map(function (line) {
            var m = line.match(/^\[(.+)\]$/);
            if (m) return '<div class="mz-ed-sec">' + esc(m[1]) + '</div>';
            var body = esc(line)
                .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
                .replace(/__(.+?)__/g, '<u>$1</u>')
                .replace(/\*(.+?)\*/g, '<i>$1</i>');
            return '<div>' + (body || '<br>') + '</div>';
        }).join('');
    }
    function editorToMarkup(ed) {
        var lines = [], buf = [];
        function flush() { lines.push(buf.join('')); buf = []; }
        function walk(node) {
            if (node.nodeType === 3) { buf.push(node.textContent); return; }
            if (node.nodeName === 'BR') { flush(); return; }
            if (node.classList && node.classList.contains('mz-ed-sec')) {
                flush();
                var t = (node.textContent || '').trim();
                lines.push(t ? '[' + t + ']' : '');
                return;
            }
            var tag = node.nodeName;
            var open = (tag === 'B' || tag === 'STRONG') ? '**' : (tag === 'I' || tag === 'EM') ? '*' : (tag === 'U') ? '__' : '';
            if (open) buf.push(open);
            node.childNodes.forEach(walk);
            if (open) buf.push(open);
        }
        Array.prototype.forEach.call(ed.childNodes, function (n) { walk(n); flush(); });
        // collapse trailing empties
        while (lines.length && lines[lines.length - 1] === '') lines.pop();
        return lines.join('\n');
    }
    function setEditorMarkup(txt) {
        var ed = $('mzEditor');
        if (!ed) return;
        ed.innerHTML = markupToHtml(txt);
        ed.dataset.empty = ed.textContent.trim() ? '' : '1';
        $('mzLyrics').value = txt == null ? '' : String(txt);
    }
    function syncEditor() {
        var ed = $('mzEditor');
        if (!ed) return;
        $('mzLyrics').value = editorToMarkup(ed);
        ed.dataset.empty = ed.textContent.trim() ? '' : '1';
    }
    function initLyricsEditor() {
        var ed = $('mzEditor');
        if (!ed || ed.dataset.p30) return;
        ed.dataset.p30 = '1';
        ed.addEventListener('input', syncEditor);
        // paste as plain text — styling comes from the toolbar only
        ed.addEventListener('paste', function (e) {
            e.preventDefault();
            var txt = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, txt);
        });
        document.querySelectorAll('.mz-ed-btn').forEach(function (btn) {
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep selection
            btn.addEventListener('click', function () {
                ed.focus();
                var cmd = btn.getAttribute('data-cmd');
                if (cmd === 'section') {
                    toggleSecPop(true); // in-system popover, never a browser prompt
                } else {
                    document.execCommand(cmd, false, null);
                }
                syncEditor();
            });
        });
    }

    // ── section-header popover (P31: replaces the browser prompt) ──
    function toggleSecPop(show) {
        var pop = $('mzSecPop');
        if (!pop) return;
        pop.classList.toggle('is-hidden', !show);
        if (show) { var i = $('mzSecPopInput'); i.value = ''; i.focus(); }
    }
    function initSecPop() {
        var pop = $('mzSecPop');
        if (!pop || pop.dataset.p31) return;
        pop.dataset.p31 = '1';
        var insert = function () {
            var label = $('mzSecPopInput').value.trim();
            toggleSecPop(false);
            if (!label) return;
            $('mzEditor').focus();
            document.execCommand('insertHTML', false,
                '</div><div class="mz-ed-sec">' + esc(label) + '</div><div><br></div>');
            syncEditor();
        };
        $('mzSecPopOk').addEventListener('click', insert);
        $('mzSecPopInput').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); insert(); }
            if (e.key === 'Escape') toggleSecPop(false);
        });
        $('mzSecPopCancel').addEventListener('click', function () { toggleSecPop(false); });
    }

    function renderLyrics(src) {
        var txt = esc(src == null ? '' : String(src));
        if (!txt) return '<div style="font-size:.9rem;opacity:.65;font-style:italic">(No lyrics recorded)</div>';
        var out = [], buf = [];
        function flush() {
            if (!buf.length) return;
            out.push('<div style="font-size:.95rem;line-height:2;white-space:pre-wrap">' + buf.join('<br>') + '</div>');
            buf = [];
        }
        txt.split(/\r?\n/).forEach(function (raw) {
            var line = raw.trim();
            if (line === '') { flush(); return; }
            var m = line.match(/^\[(.+)\]$/);
            if (m) {
                flush();
                out.push('<div style="margin-top:18px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;font-size:.78rem;color:var(--school-primary,#4f46e5)">' + m[1] + '</div>');
                return;
            }
            // bold first, then italic on what remains
            buf.push(line
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/__(.+?)__/g, '<u>$1</u>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>'));
        });
        flush();
        return out.join('');
    }

    // P66: the hymn the view modal is currently showing (art actions
    // in the hero need to know it without inline id injection).
    var viewArtId = 0;

    /** P66: hero banner for the view modal — the hymn's 640px art
     *  rendition (or its fallback gradient) with the title overlaid,
     *  plus Set/Remove art buttons (edit parity with the mobile app;
     *  the server enforces roles either way). */
    function renderViewArt(h) {
        viewArtId = Number(h.id) || 0;
        var el = $('mzViewArt');
        if (!el) return;
        var bg;
        if (h.art_status === 'ready' && h.art_url) {
            bg = 'background-image:url(\'' + h.art_url + '\')';
        } else {
            var g = hymnCoverGradient(h);
            bg = 'background:linear-gradient(135deg,' + g[0] + ',' + g[1] + ')';
        }
        el.setAttribute('style', bg);
        el.innerHTML =
            '<div class="mz-view-art-title amharic">' + esc(h.title) + '</div>' +
            '<div class="mz-view-art-actions">' +
            '<button class="btn-secondary btn-sm" onclick="Mezmur.viewArtSet()"><i class="fa-solid fa-image"></i> ' +
            (h.art_status === 'ready' ? 'Replace art' : 'Set art') + '</button>' +
            (h.art_status === 'ready'
                ? '<button class="btn-secondary btn-sm" onclick="Mezmur.viewArtRemove()"><i class="fa-solid fa-trash-can"></i> Remove</button>'
                : '') +
            '</div>';
    }

    function viewArtSet() { if (viewArtId) mgrArt(viewArtId); }
    function viewArtRemove() { if (viewArtId) mgrArtRemove(viewArtId); }

    function viewHymn(id) {
        apiGet('action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load this hymn.', 'e'); return; }
            var h = d.item, meta = '';
            if (h.category) meta += '<span class="badge badge-active">' + esc(h.category) + '</span>';
            if (h.status === 'archived') meta += '<span class="badge badge-inactive">Archived</span>';
            $('mzViewTitle').textContent = h.title;
            renderViewArt(h);
            $('mzViewMeta').innerHTML = meta;
            $('mzViewLyrics').innerHTML = renderLyrics(h.lyrics);
            openModalF('mzViewModal', null);
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    function setHymnStatus(id, status) {
        var label = status === 'archived' ? 'archive' : 'restore';
        var dcOpts = status === 'archived'
            ? { tier: 'medium', title: 'Archive this hymn?', summary: 'The hymn is taken out of use.', intent: 'I want to archive this hymn',
                consequences: ['The hymn is hidden from the mobile app, hymn lists and playlists.', 'Singers and members can no longer find or play it.'],
                keeps: ['Lyrics, audio and timings are kept.', 'You can restore it later from the Archived filter.'], phrase: 'ARCHIVE HYMN', confirmLabel: 'Archive hymn' }
            : { tier: 'low', title: 'Restore this hymn?', summary: 'The hymn appears again in the app and the hymn lists.', confirmLabel: 'Restore hymn' };
        sysConfirm('Are you sure you want to ' + label + ' this hymn?', function () {
        apiPost({ action: 'set_status', id: id, status: status }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Action failed.', 'e'); return; }
            window.toast(d.message || 'Done.', 's');
            loadStats(); loadList();
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
        }, dcOpts);
    }

    // ══════════════════════════════════════════════════════════
    // MODULE 2 — ATTENDANCE (section-first, teacher/edu clone)
    // ══════════════════════════════════════════════════════════
    var att = {
        page: 1, totalPages: 1, date: null, section: null, sheet: null,
        marks: {}, notes: {}, order: [], focusIdx: -1, dirty: false,
        packetStatus: '', reviewNote: ''
    };

    // ── section selector ([Section ▾] like teachers' [Class ▾]) ─
    function loadSections() {
        apiGet('action=sections').then(function (d) {
            if (d.status !== 'success') return;
            var sel = $('mzViewSection');
            if (!sel) return;
            var cur = sel.value;
            sel.innerHTML = '<option value="">Select section…</option>' +
                (d.items || []).map(function (s) {
                    var n = (s.members != null ? s.members : s.count);
                    var label = s.section;
                    if (s.section === 'ህጻናት') label = 'ህጻናት (A · 7–13)';
                    else if (s.section === 'ማዕከላዊያን') label = 'ማዕከላዊያን (B · 14–17)';
                    else if (s.section === 'ወጣቶች') label = 'ወጣቶች (C · 18+)';
                    return '<option value="' + esc(s.section) + '">' + esc(label) + (n != null ? ' · ' + n : '') + '</option>';
                }).join('');
            if (cur) sel.value = cur;
        }).catch(function () { /* retried on tab re-entry */ });
    }

    function loadSubSectionOptions() {
        var sel = $('mzSubSection');
        if (!sel) return;
        apiGet('action=sections').then(function (d) {
            if (d.status !== 'success' || !d.items) return;
            var cur = sel.value;
            sel.innerHTML = '<option value="">All sections</option>' +
                d.items.map(function (s) {
                    var n = (s.members != null ? s.members : s.count);
                    var label = s.section;
                    if (s.section === 'ህጻናት') label = 'ህጻናት (A · 7–13)';
                    else if (s.section === 'ማዕከላዊያን') label = 'ማዕከላዊያን (B · 14–17)';
                    else if (s.section === 'ወጣቶች') label = 'ወጣቶች (C · 18+)';
                    return '<option value="' + esc(s.section) + '">' + esc(label) + (n != null ? ' · ' + n : '') + '</option>';
                }).join('');
            if (cur) sel.value = cur;
        }).catch(function () { });
    }

    // ── days list ─────────────────────────────────────────────
    function loadDays(page) {
        var tb = $('mzSessTbody');
        if (!tb) return; // day-history card retired; submissions inbox + Insights cover it
        att.page = page || 1;
        tb.innerHTML = skeletonRows(5);
        var q = 'action=days_list&page=' + att.page + '&per_page=' + PAGE_SIZE +
            '&from=' + encodeURIComponent($('mzSessFrom').value || '') + '&to=' + encodeURIComponent($('mzSessTo').value || '');
        apiGet(q).then(function (d) {
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="5">' + errorState(d.message || 'Unable to load days.', 'Mezmur.loadDays()') + '</td></tr>';
                return;
            }
            att.totalPages = d.total_pages || 1;
            renderDayRows(d.items || []);
            var pg = $('mzSessPagination');
            pg.innerHTML = att.totalPages <= 1
                ? '<span class="text-dim">' + (d.total || 0) + ' attendance day' + ((d.total || 0) === 1 ? '' : 's') + '</span><span></span>'
                : '<button class="btn-secondary btn-sm" ' + (att.page <= 1 ? 'disabled' : '') + ' onclick="Mezmur.sessPage(' + (att.page - 1) + ')" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></button>' +
                  '<span class="text-dim">Page ' + att.page + ' of ' + att.totalPages + '</span>' +
                  '<button class="btn-secondary btn-sm" ' + (att.page >= att.totalPages ? 'disabled' : '') + ' onclick="Mezmur.sessPage(' + (att.page + 1) + ')" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></button>';
        }).catch(function (err) {
            tb.innerHTML = '<tr><td colspan="5">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.loadDays()') + '</td></tr>';
        });
    }

    function renderDayRows(items) {
        var tb = $('mzSessTbody');
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="5">' + emptyState('fa-calendar-check', 'No attendance yet',
                'Pick a section and date above and press Take Attendance to record your first day.') + '</td></tr>';
            return;
        }
        tb.innerHTML = items.map(function (d) {
            var rate = d.marked > 0 ? Math.round(d.attended * 1000 / d.marked) / 10 : null;
            return '<tr>' +
                '<td class="nowrap">' + fmtDate(d.attendance_date) + '</td>' +
                '<td>' + d.marked + '</td>' +
                '<td class="text-ok"><b>' + d.attended + '</b></td>' +
                '<td>' + rateBar(rate) + '</td>' +
                '<td class="nowrap"><button class="btn-primary btn-sm" onclick="Mezmur.jumpToDate(\'' + esc(d.attendance_date) + '\')">' +
                '<i class="fa-solid fa-eye"></i> ' + (d.marked > 0 ? 'Review' : 'View') + '</button></td></tr>';
        }).join('');
    }

    // ── open / load sheet (section-scoped) ────────────────────
    // (attendance taking was removed from the department dashboard;
    //  takers record sheets in the mobile app. openDay kept as a thin
    //  alias for viewSheet so old bookmarks/quotes never fatal.)
    function openDay() { viewSheet(); }

    function loadSheet(date, section) {
        $('mzSheetBody').innerHTML = skeletonRows(8);
        $('mzSessionListView').style.display = 'none';
        $('mzSheetView').style.display = 'block';
        renderSheetStatus('');
        apiGet('action=sheet&date=' + encodeURIComponent(date) + '&section=' + encodeURIComponent(section)).then(function (d) {
            if (d.status !== 'success' || !d.members) { window.toast(d.message || 'Unable to load the sheet.', 'e'); closeSheet(true); return; }
            att.sheet = d;
            att.date = date;
            att.section = section;
            att.marks = {};
            att.notes = {};
            att.order = [];
            att.focusIdx = -1;
            att.dirty = false;
            att.packetStatus = d.submission_status || '';
            att.reviewNote = d.review_notes || '';
            d.members.forEach(function (m) {
                att.marks[m.id] = m.mark || '';
                att.notes[m.id] = m.notes || '';
                att.order.push(m.id);
            });
            $('mzSheetTitle').textContent = 'Attendance — ' + section;
            $('mzSheetMeta').textContent = fmtDate(date) + ' • ' + att.order.length + ' members';
            var pm = $('mzPrintMeta');
            if (pm) pm.textContent = section + ' • ' + fmtDate(date) + ' • ' + att.order.length + ' members';
            renderSheet();
            renderSheetStatus(att.packetStatus);
            updateSheetSummary();
        }).catch(function (err) { window.toast(((err && err.message) || 'Connection error.') + staleHint(err), 'e'); closeSheet(true); });
    }


    function renderSheetStatus(status) {
        var el = $('mzSheetStatus');
        if (!el) return;
        if (!status) { el.classList.add('is-hidden'); el.innerHTML = ''; return; }
        var html = '';
        if (status === 'submitted') {
            html = '<div class="mz-banner info"><i class="fa-solid fa-paper-plane"></i><span><b>Submitted.</b> Waiting for the Mezmur department to review. You can still save corrections until it is approved.</span></div>';
        } else if (status === 'approved') {
            html = '<div class="mz-banner ok"><i class="fa-solid fa-circle-check"></i><span><b>Approved</b> by the Mezmur department.</span></div>';
        } else if (status === 'rejected') {
            html = '<div class="mz-banner bad"><i class="fa-solid fa-circle-xmark"></i><span><b>Rejected</b> by the Mezmur department.' + (att.reviewNote ? ' ' + esc(att.reviewNote) : '') + '</span></div>';
        } else if (status === 'revision_needed') {
            html = '<div class="mz-banner warn"><i class="fa-solid fa-rotate-left"></i><span><b>Returned for correction.</b> ' +
                (att.reviewNote ? esc(att.reviewNote) : 'Fix the sheet and submit it again.') + '</span></div>';
        } else if (status === 'draft') {
            html = '<div class="mz-banner info"><i class="fa-solid fa-file-pen"></i><span><b>Draft</b> — saved locally and visible to the department. Submit when the sheet is final.</span></div>';
        }
        el.innerHTML = html;
        el.classList.toggle('is-hidden', !html);
    }

    // ── sheet rendering (batched) ─────────────────────────────
    function renderSheet() {
        if (!att.sheet) return;
        var members = att.sheet.members || [];
        var html = '';
        if (!members.length) {
            html = emptyState('fa-users', 'No active members in this section', 'Assign members to this section to start recording attendance.');
        } else {
            html = '<div class="group"><div class="group-body">';
            members.forEach(function (m) { html += memberRow(m); });
            html += '</div></div>';
        }
        $('mzSheetBody').innerHTML = html;
    }

    /** Read-only roster row: the department INSPECTS recorded sheets;
     *  marking lives exclusively in the mobile app (takers). */
    function memberRow(m) {
        var mark = att.marks[m.id] || '';
        var note = att.notes[m.id] || '';
        var chip = mark
            ? '<span class="rate-chip ' + (mark === 'present' || mark === 'late' ? 'ok' : mark === 'excused' ? 'warn' : 'bad') + '">' +
              mark.charAt(0).toUpperCase() + mark.slice(1) + '</span>'
            : '<span class="text-dim">not marked</span>';
        var warn = m.warning && m.warning.kind
            ? ' <span class="rate-chip warn" title="Open attendance warning">' +
              (m.warning.kind === 'low_month' ? 'Low month' : (m.warning.stage || 'warning')) + '</span>'
            : '';
        return '<div class="member-row">' +
            '<div class="member-name">' + esc(m.student_name) + ' ' + esc(m.father_name || '') + warn +
            '<div class="text-dim" style="font-size:.68rem">' + (note ? esc(note) : '') + '</div></div>' +
            chip + '</div>';
    }


    function unmarkedCount() {
        var n = 0;
        Object.keys(att.marks).forEach(function (id) { if (!att.marks[id]) n++; });
        return n;
    }

    function updateSheetSummary() {
        var p = 0, l = 0, a = 0, e = 0, u = 0;
        Object.keys(att.marks).forEach(function (id) {
            if (att.marks[id] === 'present') p++;
            else if (att.marks[id] === 'late') l++;
            else if (att.marks[id] === 'absent') a++;
            else if (att.marks[id] === 'excused') e++;
            else u++;
        });
        var marked = p + l + a + e;
        var rate = marked > 0 ? Math.round((p + l) * 1000 / marked) / 10 : 0;
        $('mzSheetSummary').innerHTML =
            '<b>' + marked + '</b> marked' + (u > 0 ? ' • <span class="text-warn"><b>' + u + '</b> unmarked</span>' : '') +
            ' • <span class="text-ok"><b>' + p + '</b> present</span>' +
            ' • <span class="text-warn"><b>' + l + '</b> late</span>' +
            ' • <span class="text-bad"><b>' + a + '</b> absent</span>' +
            ' • <span style="color:var(--school-info)"><b>' + e + '</b> excused</span>' +
            ' • rate <b>' + rate + '%</b>';
    }



    function closeSheet(force) {
        att.sheet = null;
        att.focusIdx = -1;
        $('mzSheetView').style.display = 'none';
        $('mzSessionListView').style.display = 'block';
    }

    // ── review inbox (department) — edu Submissions workflow clone ─
    var _allPackets = [];

    function switchSubTab(tab) {
        ['draft', 'submitted', 'approved', 'insights'].forEach(function (t) {
            var id = 'mzSubTab' + (t === 'insights' ? 'Insights' : t.charAt(0).toUpperCase() + t.slice(1));
            var b = $(id);
            if (!b) return;
            b.classList.toggle('active', t === tab);
            b.setAttribute('aria-selected', t === tab ? 'true' : 'false');
        });
        var hid = $('mzSubTabStatus');
        if (tab === 'insights') {
            $('mzSubmissionsList').classList.add('is-hidden');
            if ($('mzSubPagination')) $('mzSubPagination').classList.add('is-hidden');
            $('mzSubInsights').classList.remove('is-hidden');
            loadSubInsights();
            return;
        }
        $('mzSubmissionsList').classList.remove('is-hidden');
        if ($('mzSubPagination')) $('mzSubPagination').classList.remove('is-hidden');
        $('mzSubInsights').classList.add('is-hidden');
        hid.value = tab;
        loadSubmissions();
    }

    // Phase 3 paging: range, Previous/Next, per page (remembered) and Load more.
    // The inbox grows every day; the old fixed per_page=100 cut it off silently.
    var SUB_SIZES = [25, 50, 100];
    var sub = { firstPage: 1, page: 1, totalPages: 1, total: 0, shown: 0, busy: false, seq: 0,
        perPage: (function () { var v = 0; try { v = parseInt(localStorage.getItem('fkss_pp_mz_subs'), 10); } catch (e) { v = 0; } return SUB_SIZES.indexOf(v) >= 0 ? v : 50; })() };

    function subRowHtml(p) {
                var result = p.present_count + 'P / ' + p.late_count + 'L / ' + p.absent_count + 'A' + (p.excused_count ? ' / ' + p.excused_count + 'E' : '');
                var returned = p.status === 'revision_needed' && p.reviewer_name
                    ? '<div class="text-dim" style="font-size:.68rem;margin-top:2px"><i class="fa-solid fa-arrow-rotate-left"></i> ' + esc(p.reviewer_name) +
                      (p.review_notes ? ': ' + esc(String(p.review_notes).length > 60 ? String(p.review_notes).slice(0, 60) + '…' : p.review_notes) : '') + '</div>'
                    : '';
                var actions = '<button class="btn-secondary btn-sm" title="Open packet" onclick="Mezmur.viewPacket(' + p.id + ')"><i class="fa-solid fa-eye"></i></button> ' +
                    '<button class="btn-secondary btn-sm" title="Review" onclick="Mezmur.openReview(' + p.id + ')"><i class="fa-solid fa-gavel"></i></button>';
                if (p.status === 'submitted') {
                    actions += ' <button class="btn-primary btn-sm" title="Approve now" onclick="Mezmur.quickDecision(' + p.id + ',\'approved\')"><i class="fa-solid fa-check"></i></button>';
                }
                return '<tr>' +
                    '<td class="nowrap">' + fmtDate(p.attendance_date) + '</td>' +
                    '<td>' + sectionBadge(p.section) + '</td>' +
                    '<td>' + esc(p.taker_name || '—') + '</td>' +
                    '<td>' + p.member_count + '</td>' +
                    '<td style="font-weight:600;font-size:.78rem">' + result + '</td>' +
                    '<td>' + statusChip(p.status) + returned + '</td>' +
                    '<td class="nowrap text-dim">' + fmtDate(p.updated_at) + '</td>' +
                    '<td class="nowrap">' + actions + '</td>' +
                    '</tr>';
}

    /** opts: none = page 1 (filters/tabs) · {keep:true} = reload the pages in view
     *  (after a decision) · {page:n} = go to page n · {append:true} = Load more. */
    function loadSubmissions(opts) {
        opts = opts || {};
        var tb = $('mzSubTbody');
        if (!tb) return;
        var append = opts.append === true;
        var page = append ? sub.page + 1 : (opts.keep ? sub.firstPage : (opts.page || 1));
        var perPage = sub.perPage;
        // After a decision, keep what was on screen. Rows added with Load more can be re-fetched
        // in one request only from page 1 (the server's offset is page x size); otherwise the
        // first page in view is reloaded.
        if (opts.keep && sub.firstPage === 1 && sub.page > 1) perPage = Math.min(100, sub.perPage * sub.page);
        if (append && (sub.busy || sub.page >= sub.totalPages)) return;
        var seq = ++sub.seq;
        sub.busy = true;
        if (!append) tb.innerHTML = skeletonRows(5);
        var status = $('mzSubTabStatus').value || 'draft';
        var q = 'action=submissions_list&page=' + page + '&per_page=' + perPage + '&status=' + encodeURIComponent(status);
        var sec = $('mzSubSection') ? $('mzSubSection').value : '';
        var from = $('mzSubFrom') ? $('mzSubFrom').value : '';
        var to = $('mzSubTo') ? $('mzSubTo').value : '';
        if (sec) q += '&section=' + encodeURIComponent(sec);
        if (from) q += '&from=' + encodeURIComponent(from);
        if (to) q += '&to=' + encodeURIComponent(to);
        apiGet(q).then(function (d) {
            if (seq !== sub.seq) return;            // a newer request replaced this one
            sub.busy = false;
            if (d.status !== 'success') {
                if (append) { window.toast(d.message || 'Unable to load more submissions.', 'e'); return; }
                tb.innerHTML = '<tr><td colspan="8">' + errorState(d.message || 'Unable to load submissions.', 'Mezmur.loadSubmissions()') + '</td></tr>';
                renderSubPager();
                return;
            }
            var items = d.items || [];
            var total = Number(d.total) || 0;
            var srvPage = Number(d.page) || page;
            if (append && srvPage !== page) { loadSubmissions({ keep: true }); return; }  // list shrank under us
            if (!append && !items.length && total > 0 && page > 1) { loadSubmissions({ page: Number(d.total_pages) || 1 }); return; }
            renderSubStats(d.stats || {});
            sub.total = total;
            if (perPage === sub.perPage) {
                sub.totalPages = Number(d.total_pages) || 1;
            } else {                                // a "keep" reload fetched several pages at once
                sub.totalPages = Math.max(1, Math.ceil(total / sub.perPage));
            }
            if (append) {
                sub.page = srvPage;
                sub.shown += items.length;
                _allPackets = _allPackets.concat(items);
                tb.insertAdjacentHTML('beforeend', items.map(subRowHtml).join(''));
                renderSubPager();
                return;
            }
            sub.firstPage = perPage === sub.perPage ? srvPage : page;
            sub.page = perPage === sub.perPage ? srvPage : Math.min(sub.totalPages, page + Math.ceil(items.length / sub.perPage) - 1);
            sub.shown = items.length;
            _allPackets = items;
            renderSubPager();
            if (!_allPackets.length) {
                var empty = status === 'draft'
                    ? 'No drafts yet. When a taker taps Save, the unfinished sheet appears here.'
                    : (status === 'approved'
                        ? 'No approved packets yet.'
                        : 'No submitted sheets yet. Submit is used when the section sheet is complete.');
                tb.innerHTML = '<tr><td colspan="8">' + emptyState('fa-inbox', status === 'draft' ? 'No drafts' : (status === 'approved' ? 'Nothing approved' : 'Nothing submitted'), empty) + '</td></tr>';
                return;
            }
            tb.innerHTML = _allPackets.map(subRowHtml).join('');
        }).catch(function (err) {
            if (seq !== sub.seq) return;
            sub.busy = false;
            if (append) { window.toast((err && err.message) || 'Connection error.', 'e'); return; }
            tb.innerHTML = '<tr><td colspan="8">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.loadSubmissions()') + '</td></tr>';
        });
    }

    function renderSubPager() {
        var el = $('mzSubPagination');
        if (!el) return;
        var start = sub.total ? (sub.firstPage - 1) * sub.perPage + 1 : 0;
        var end = Math.min(sub.total, start + sub.shown - 1);
        var left = Math.max(0, sub.total - end);
        var dim = 'color:var(--school-text-dim);font-size:.8rem';
        var sizes = SUB_SIZES.map(function (n) { return '<option value="' + n + '"' + (n === sub.perPage ? ' selected' : '') + '>' + n + '</option>'; }).join('');
        el.innerHTML =
            '<span id="mzSubRange" style="' + dim + '">' + (sub.total ? 'Showing ' + start + '\u2013' + end + ' of ' + sub.total : '0 packets') + '</span>' +
            '<span style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">' +
              '<button type="button" class="btn-secondary btn-sm" id="mzSubPrev" ' + (sub.firstPage <= 1 ? 'disabled' : '') + ' onclick="Mezmur.subPage(-1)" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></button>' +
              '<span style="' + dim + '">Page ' + (sub.firstPage === sub.page ? sub.page : sub.firstPage + '\u2013' + sub.page) + ' of ' + sub.totalPages + '</span>' +
              '<button type="button" class="btn-secondary btn-sm" id="mzSubNext" ' + (sub.page >= sub.totalPages ? 'disabled' : '') + ' onclick="Mezmur.subPage(1)" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></button>' +
              '<label style="' + dim + ';display:flex;gap:.3rem;align-items:center">Per page <select id="mzSubSize" class="school-input" style="width:auto;padding:.2rem .4rem" onchange="Mezmur.subPerPage(this.value)">' + sizes + '</select></label>' +
              '<button type="button" class="btn-secondary btn-sm" id="mzSubMore" ' + (left <= 0 ? 'disabled' : '') + ' onclick="Mezmur.subMore()">' +
                (left > 0 ? 'Load more (' + left + ' left)' : (sub.total ? '\u2713 All ' + sub.total + ' shown' : 'Load more')) + '</button>' +
            '</span>';
    }

    function subPage(delta) {
        var target = delta < 0 ? sub.firstPage - 1 : sub.page + 1;
        if (target < 1 || target > sub.totalPages) return;
        loadSubmissions({ page: target });
    }
    function subMore() { loadSubmissions({ append: true }); }
    function subPerPage(v) {
        var n = parseInt(v, 10);
        if (SUB_SIZES.indexOf(n) < 0) return;
        sub.perPage = n;
        try { localStorage.setItem('fkss_pp_mz_subs', String(n)); } catch (e) { /* private mode */ }
        loadSubmissions();
    }

    function renderSubStats(st) {
        var row = $('mzSubStatsRow');
        if (!row) return;
        var today = st.today_packets
            ? (st.today_present || 0) + ' P · ' + (st.today_absent || 0) + ' A · ' + (st.today_late || 0) + ' L'
            : '—';
        row.innerHTML =
            '<div class="sub-stat" style="background:linear-gradient(135deg,#2563eb,#3b82f6)"><b>' + (st.drafts || 0) + '</b><span>Drafts (not finished)</span></div>' +
            '<div class="sub-stat" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><b>' + (st.submitted || 0) + '</b><span>Submitted (needs review)</span></div>' +
            '<div class="sub-stat" style="background:linear-gradient(135deg,#059669,#10b981)"><b>' + (st.approved || 0) + '</b><span>Approved</span></div>' +
            '<div class="sub-stat" style="background:linear-gradient(135deg,#7c3aed,#6366f1)"><b style="font-size:1rem">' + today + '</b><span>Today\u2019s marks (' + (st.today_packets || 0) + ' sheets)</span></div>';
    }

    function quickDecision(id, decision) {
        // Approve straight from the table. Anything else (reject /
        // return) goes through the review modal so a note explains
        // the decision to the taker.
        if (decision !== 'approved') { openReview(id); return; }
        sysConfirm('Approve this attendance packet?', function () { quickApprove(id, decision); }, reviewOpts('approved'));
    }
    // Danger confirmation tiers for review decisions (MEDIUM approve/reject, LOW return).
    function reviewOpts(decision) {
        if (decision === 'approved') return { tier: 'medium', title: 'Approve this attendance packet?', summary: 'Approval makes the packet final.', intent: 'I want to approve this packet',
            consequences: ['The attendance in this packet becomes FINAL and counts in reports and warnings.', 'The taker can no longer edit it.'],
            keeps: ['Other packets are not affected.'], phrase: 'APPROVE', confirmLabel: 'Approve packet' };
        if (decision === 'rejected') return { tier: 'medium', title: 'Reject this attendance packet?', summary: 'Rejecting dismisses the packet.', intent: 'I want to reject this packet',
            consequences: ['The packet is dismissed and its attendance is not used.', 'The taker sees your reason.'],
            keeps: ['The packet stays in the history.'], phrase: 'REJECT', confirmLabel: 'Reject packet' };
        return { tier: 'low', title: 'Return this packet for correction?', summary: 'The taker can edit it again and sees your reason.', confirmLabel: 'Return for correction' };
    }
    function quickApprove(id, decision) {
        apiPost({ action: 'submission_review', submission_id: id, new_status: decision, notes: '' }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Unable to record the decision.', 'e'); return; }
            window.toast(d.message || 'Approved.', 's');
            loadSubmissions({ keep: true });
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    // Printable QR tiles for the selected section (Phase 8 QR attendance).
    // GET-only governed endpoint; the section picker doubles as the guard.
    function printQrRoster() {
        var sec = $('mzSubSection') ? $('mzSubSection').value : '';
        if (!sec) { window.toast('Pick a section first.', 'e'); return; }
        window.open('/admin/api_qr_roster.php?dept=mezmur&section=' + encodeURIComponent(sec), '_blank');
    }

    function exportSubmissions() {
        if (!_allPackets.length) { window.toast('Nothing to export on this tab.', 'e'); return; }
        var head = ['Date', 'Section', 'Taker', 'Members', 'Present', 'Late', 'Absent', 'Excused', 'Status', 'Updated'];
        var rows = _allPackets.map(function (p) {
            return [p.attendance_date ? fmtDate(p.attendance_date, 'medium') : '', p.section || '', p.taker_name || '', p.member_count || 0,
                p.present_count || 0, p.late_count || 0, p.absent_count || 0, p.excused_count || 0,
                p.status_label || p.status || '', fmtDateTime(p.updated_at)];
        });
        if (window.XLSX) {
            var ws = XLSX.utils.aoa_to_sheet([head].concat(rows));
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Submissions');
            XLSX.writeFile(wb, 'FKSS_Mezmur_Submissions.xlsx');
        } else {
            var csv = '\ufeff' + head.join(',') + '\n' + rows.map(function (r) {
                return r.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',');
            }).join('\n');
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'mezmur-submissions-' + todayStr() + '.csv';
            document.body.appendChild(a); a.click(); a.remove();
        }
        window.toast('Submissions exported.', 's');
    }

    function loadSubInsights() {
        var box = $('mzSubInsights');
        if (!box) return;
        box.innerHTML = skeletonRows(3);
        // Last 14 attendance days (existing bounded action) + packet
        // status distribution — the same shape edu's Insights tab uses.
        apiGet('action=days_list&per_page=14').then(function (d) {
            var days = (d && d.items) || [];
            var html = '<div class="toolbar"><div class="toolbar-title"><h3 class="school-card-title"><i class="fa-solid fa-chart-line"></i> Last 14 attendance days</h3></div></div>';
            if (!days.length) {
                html += emptyState('fa-calendar-check', 'No attendance days yet', 'Recorded days appear here once takers submit sheets.');
            } else {
                html += '<div class="table-shell"><table><thead><tr><th>Date</th><th>Marked</th><th>Attended</th><th>Rate</th></tr></thead><tbody>' +
                    days.map(function (x) {
                        var marked = Number(x.marked || 0), attended = Number(x.attended || 0);
                        var rate = marked > 0 ? Math.round(attended * 1000 / marked) / 10 : null;
                        return '<tr><td class="nowrap">' + fmtDate(x.attendance_date) + '</td><td>' + marked + '</td><td>' + attended + '</td><td>' + rateBar(rate) + '</td></tr>';
                    }).join('') + '</tbody></table></div>';
            }
            html += '<p class="text-dim" style="margin-top:.75rem;font-size:.75rem">Full member / section / trend analytics live in the Analytics section.</p>';
            box.innerHTML = html;
        }).catch(function (err) {
            box.innerHTML = errorState((err && err.message) || 'Connection error.', 'Mezmur.loadSubInsights()');
        });
    }

    function loadSubSectionOptions() {
        var sel = $('mzSubSection');
        if (!sel || sel.dataset.loaded) return;
        apiGet('action=sections').then(function (d) {
            var items = (d && d.items) || [];
            items.forEach(function (x) {
                var name = x.section || x.name;
                if (!name) return;
                var opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name + (x.members != null ? ' (' + x.members + ')' : '');
                sel.appendChild(opt);
            });
            sel.dataset.loaded = '1';
        }).catch(function () { /* filter stays optional */ });
    }

    // Phase 3: per-packet history list (EC timestamps come from the server).
    function packetHistoryHtml(events) {
        events = events || [];
        if (!events.length) return '';
        return '<div class="packet-history-title"><i class="fa-solid fa-clock-rotate-left"></i> History</div>' +
            '<ul class="packet-history">' + events.map(function (ev) {
                return '<li><span class="ph-when">' + esc(ev.created_ec || '') + '</span>' +
                    '<b>' + esc(ev.label || ev.event || '') + '</b>' +
                    (ev.actor_name ? '<span>· ' + esc(ev.actor_name) + '</span>' : '') +
                    (ev.reason ? '<span class="ph-reason">— ' + esc(ev.reason) + '</span>' : '') + '</li>';
            }).join('') + '</ul>';
    }

    var _reviewMeta = {};
    function openReview(id) {
        apiGet('action=submission_detail&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load the packet.', 'e'); return; }
            var p = d.item;
            _reviewMeta = p;
            $('mzRvId').value = p.id;
            $('mzRvMeta').innerHTML =
                '<span class="badge badge-info">' + fmtDate(p.attendance_date) + '</span>' +
                '<span class="badge badge-active amharic">' + esc(p.section) + '</span>' +
                '<span class="text-dim">by ' + esc(p.taker_name || '—') + '</span>' +
                statusChip(p.status) +
                // Phase 3: an approved packet can be reopened / rejected with a
                // reason (department only; the server re-checks the role).
                (p.status === 'approved'
                    ? '<p class="packet-reopen-note">This packet is approved and counts for attendance. Reopening or rejecting it needs a reason and immediately recalculates streaks and warnings for its members.</p>'
                    : '') +
                packetHistoryHtml(p.events);
            // Q-1 (2026-10-19): return / reject / reopen are department-only
            // decisions; admins only approve. Hide what the server would
            // refuse so the modal never offers a dead end.
            var deptOnly = ((window.APP || {}).role || '') === 'mezmur_dept';
            if (!deptOnly && p.status === 'approved') {
                window.toast('Only the Mezmur department can reopen or reject an approved packet.', 'e');
                return;
            }
            Array.prototype.forEach.call($('mzRvDecision').options, function (opt) {
                if (opt.value !== 'approved') { opt.disabled = !deptOnly; opt.hidden = !deptOnly; }
            });
            // A previously returned packet keeps its decision context.
            $('mzRvDecision').value = (p.status === 'submitted' || !deptOnly) ? 'approved' : 'revision_needed';
            $('mzRvNotes').value = '';
            showError($('mzRvError'), '');
            openModalF('mzReviewModal', '#mzRvDecision');
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    function submitReview(confirmed) {
        var id = parseInt($('mzRvId').value, 10);
        var decision = $('mzRvDecision').value;
        var notes = $('mzRvNotes').value.trim();
        if (!id) return;
        if (decision !== 'approved' && notes.length < 3) {
            showError($('mzRvError'), 'Write a short reason so the taker knows what to fix.');
            $('mzRvNotes').focus();
            return;
        }
        if (confirmed !== true) { sysConfirm('Record this decision?', function () { submitReview(true); }, reviewOpts(decision)); return; }
        var btn = $('mzRvSaveBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Recording…';
        apiPost({ action: 'submission_review', submission_id: id, new_status: decision, notes: notes }).then(function (d) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-gavel"></i> Record Decision';
            if (d.status !== 'success') { showError($('mzRvError'), d.message || 'Unable to record the decision.'); return; }
            closeModalF('mzReviewModal');
            window.toast(d.message || 'Decision recorded.', 's');
            loadSubmissions({ keep: true });
        }).catch(function (err) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-gavel"></i> Record Decision';
            showError($('mzRvError'), (err && err.message) || 'Connection error.');
        });
    }

    function viewPacket(id) {
        apiGet('action=submission_detail&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load the packet.', 'e'); return; }
            var p = d.item;
            $('mzPacketTitle').innerHTML = '<i class="fa-solid fa-list-check"></i> ' + esc(p.section) + ' — ' + fmtDate(p.attendance_date);
            $('mzPacketMeta').innerHTML =
                statusChip(p.status) +
                '<span class="text-dim">by ' + esc(p.taker_name || '—') + '</span>' +
                '<span class="text-dim">' + p.member_count + ' members • ' +
                p.present_count + ' present • ' + p.late_count + ' late • ' +
                p.absent_count + ' absent • ' + p.excused_count + ' excused</span>';
            var rows = p.rows || [];
            var body = '<div class="table-shell"><table><thead><tr><th>#</th><th>Member</th><th>Code</th><th>Status</th><th>Note</th></tr></thead><tbody>';
            if (!rows.length) {
                body += '<tr><td colspan="5">' + emptyState('fa-users', 'No rows', 'No attendance rows are attached to this packet.') + '</td></tr>';
            } else {
                body += rows.map(function (r, i) {
                    return '<tr><td class="text-dim">' + (i + 1) + '</td>' +
                        '<td><b>' + esc(r.student_name) + '</b> ' + esc(r.father_name || '') + '</td>' +
                        '<td class="text-dim">' + esc(r.member_code || '—') + '</td>' +
                        '<td>' + esc(r.status) + '</td>' +
                        '<td class="text-dim">' + esc(r.notes || '—') + '</td></tr>';
                }).join('');
            }
            body += '</tbody></table></div>';
            body += packetHistoryHtml(p.events);
            $('mzPacketBody').innerHTML = body;
            openModalF('mzPacketModal', null);
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    // ══════════════════════════════════════════════════════════
    // MODULE 3 — ANALYTICS (no program filter — raw attendance)
    // ══════════════════════════════════════════════════════════
    var AN_SIZES = [25, 50, 100];
    var an = { page: 1, firstPage: 1, total: 0, totalPages: 1, shown: 0, busy: false, sort: 'rate', dir: 'desc', lastMembers: [], sessionsHeld: 0,
        perPage: (function () { var v = 0; try { v = parseInt(localStorage.getItem('fkss_pp_mz_an'), 10); } catch (e) { v = 0; } return AN_SIZES.indexOf(v) >= 0 ? v : PAGE_SIZE; })() };

    function ensureAnalyticsSections() {
        var sel = $('mzAnSection');
        if (sel && sel.options.length <= 1) loadStats();
    }

    function anParams(page) {
        return 'action=analytics_members&with_total=1&page=' + (page || an.page) + '&per_page=' + an.perPage +
            '&sort=' + encodeURIComponent(an.sort) + '&dir=' + an.dir +
            '&section=' + encodeURIComponent($('mzAnSection').value || '') +
            '&from=' + encodeURIComponent($('mzAnFrom').value || '') +
            '&to=' + encodeURIComponent($('mzAnTo').value || '') +
            '&search=' + encodeURIComponent($('mzAnSearch').value.trim()) +
            '&min_rate=' + encodeURIComponent($('mzAnMinRate').value || '') +
            '&min_attended=' + encodeURIComponent($('mzAnMinAtt').value || '');
    }

    function runAnalytics(page) {
        an.page = page || 1;
        var tb = $('mzAnTbody');
        tb.innerHTML = skeletonRows(8);

        var pMembers = apiGet(anParams(an.page));
        var pSections = apiGet(anParams(1).replace('action=analytics_members', 'action=analytics_sections'));
        var pTrends = apiGet('action=analytics_trends' +
            '&from=' + encodeURIComponent($('mzAnFrom').value || '') + '&to=' + encodeURIComponent($('mzAnTo').value || ''));

        Promise.all([pMembers, pSections, pTrends]).then(function (res) {
            var dm = res[0], ds = res[1], dt = res[2];
            if (dm.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="7">' + errorState(dm.message || 'Unable to analyze.', 'Mezmur.runAnalytics(' + an.page + ')') + '</td></tr>';
                return;
            }
            an.lastMembers = dm.items || [];
            an.sessionsHeld = dm.sessions_held || 0;
            $('mzAnHeld').textContent = dm.sessions_held != null ? dm.sessions_held : '—';
            // Phase 3: the card shows every matching member, not just this page.
            $('mzAnMembers').textContent = dm.total != null ? dm.total : (dm.items || []).length;

            // average rate across ranked members (only those with data)
            var withRate = (dm.items || []).filter(function (m) { return m.rate != null; });
            var avg = withRate.length ? withRate.reduce(function (s, m) { return s + m.rate; }, 0) / withRate.length : null;
            $('mzAnAvgRate').textContent = pctLabel(avg);

            renderAnRows(dm);
            renderSectionCards(ds.status === 'success' ? ds.items || [] : []);
            renderTrend(dt.status === 'success' ? dt.items || [] : []);
        }).catch(function (err) {
            tb.innerHTML = '<tr><td colspan="7">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.runAnalytics(' + an.page + ')') + '</td></tr>';
        });
    }

    function renderAnRows(dm, append) {
        var tb = $('mzAnTbody');
        var items = dm.items || [];
        an.total = dm.total != null ? Number(dm.total) : items.length;
        an.totalPages = Number(dm.total_pages) || 1;
        if (!append) { an.firstPage = Number(dm.page) || an.page; an.shown = 0; }
        an.page = Number(dm.page) || an.page;
        an.shown += items.length;
        if (!items.length && !append) {
            tb.innerHTML = '<tr><td colspan="7">' + emptyState('fa-filter-circle-xmark', 'No members match', 'Adjust the filters above and press Analyze again.') + '</td></tr>';
            $('mzAnPagination').innerHTML = '';
            return;
        }
        var startRank = (dm.page - 1) * an.perPage;
        var rowsHtml = items.map(function (m, i) {
            return '<tr>' +
                '<td class="text-dim">' + (startRank + i + 1) + '</td>' +
                '<td><b>' + esc(m.student_name) + '</b> ' + esc(m.father_name || '') +
                (m.member_code ? '<div class="text-dim">' + esc(m.member_code) + '</div>' : '') + '</td>' +
                '<td data-section="' + esc(m.section) + '">' + sectionBadge(m.section) + '</td>' +
                '<td><b>' + m.attended + '</b> / ' + m.sessions_held +
                ' <span class="text-dim">(' + pctLabel(m.sessions_held > 0 ? m.attended * 100 / m.sessions_held : null) + ')</span></td>' +
                '<td>' + rateBar(m.rate) + '</td>' +
                '<td class="text-bad">' + m.absent +
                ' <span class="text-dim">(' + pctLabel(m.absent_rate) + ')</span></td>' +
                '<td class="text-dim nowrap">' + fmtDate(m.last_attended) + '</td></tr>';
        }).join('');
        if (append) tb.insertAdjacentHTML('beforeend', rowsHtml); else tb.innerHTML = rowsHtml;
        updateSortHeaders();
        renderAnPager();
    }

    function renderAnPager() {
        var el = $('mzAnPagination');
        if (!el) return;
        var start = an.total ? (an.firstPage - 1) * an.perPage + 1 : 0;
        var end = Math.min(an.total, start + an.shown - 1);
        var left = Math.max(0, an.total - end);
        var dim = 'color:var(--school-text-dim);font-size:.8rem';
        var sizes = AN_SIZES.map(function (n) { return '<option value="' + n + '"' + (n === an.perPage ? ' selected' : '') + '>' + n + '</option>'; }).join('');
        el.innerHTML =
            '<span id="mzAnRange" style="' + dim + '">' + (an.total ? 'Showing ' + start + '\u2013' + end + ' of ' + an.total + ' members' : '0 members') + '</span>' +
            '<span style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">' +
              '<button type="button" class="btn-secondary btn-sm" id="mzAnPrev" ' + (an.firstPage <= 1 ? 'disabled' : '') + ' onclick="Mezmur.runAnalytics(' + (an.firstPage - 1) + ')" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></button>' +
              '<span style="' + dim + '">Page ' + (an.firstPage === an.page ? an.page : an.firstPage + '\u2013' + an.page) + ' of ' + an.totalPages + '</span>' +
              '<button type="button" class="btn-secondary btn-sm" id="mzAnNext" ' + (an.page >= an.totalPages ? 'disabled' : '') + ' onclick="Mezmur.runAnalytics(' + (an.page + 1) + ')" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></button>' +
              '<label style="' + dim + ';display:flex;gap:.3rem;align-items:center">Per page <select id="mzAnSize" class="school-input" style="width:auto;padding:.2rem .4rem" onchange="Mezmur.anPerPage(this.value)">' + sizes + '</select></label>' +
              '<button type="button" class="btn-secondary btn-sm" id="mzAnMore" ' + (left <= 0 ? 'disabled' : '') + ' onclick="Mezmur.anMore()">' +
                (left > 0 ? 'Load more (' + left + ' left)' : (an.total ? '\u2713 All ' + an.total + ' shown' : 'Load more')) + '</button>' +
            '</span>';
    }

    /** Load more: append the next page of ranked members (rank numbers continue). */
    function anMore() {
        if (an.busy || an.page >= an.totalPages) return;
        an.busy = true;
        apiGet(anParams(an.page + 1)).then(function (dm) {
            an.busy = false;
            if (dm.status !== 'success') { window.toast(dm.message || 'Unable to load more members.', 'e'); return; }
            an.lastMembers = an.lastMembers.concat(dm.items || []);
            renderAnRows(dm, true);
        }).catch(function (err) { an.busy = false; window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    function anPerPage(v) {
        var n = parseInt(v, 10);
        if (AN_SIZES.indexOf(n) < 0) return;
        an.perPage = n;
        try { localStorage.setItem('fkss_pp_mz_an', String(n)); } catch (e) { /* private mode */ }
        runAnalytics(1);
    }

    function renderSectionCards(items) {
        var el = $('mzSectionCards');
        if (!items.length) { el.innerHTML = emptyState('fa-layer-group', 'No section data', 'No attendance falls inside this window.'); return; }
        el.innerHTML = items.map(function (s) {
            return '<div class="school-card">' +
                '<div class="page-head" style="margin-bottom:.6rem"><div>' + sectionBadge(s.section) + '</div>' + rateChip(s.rate) + '</div>' +
                rateBar(s.rate) +
                '<div class="text-dim mt-1" style="font-size:.82rem;line-height:1.5">' +
                '<b>' + s.members + '</b> members • <b>' + s.sessions_held + '</b> days<br>' +
                '<span class="text-ok"><b>' + s.present + '</b> present (' + pctLabel(s.present_pct) + ')</span> • ' +
                '<span class="text-warn"><b>' + s.late + '</b> late (' + pctLabel(s.late_pct) + ')</span><br>' +
                '<span class="text-bad"><b>' + s.absent + '</b> absent (' + pctLabel(s.absent_pct) + ')</span>' +
                '</div></div>';
        }).join('');
    }

    function renderTrend(items) {
        var el = $('mzTrendBody');
        if (!items.length) { el.innerHTML = emptyState('fa-chart-line', 'No attendance in this window', 'Record attendance days to see the monthly trend.'); return; }
        var max = Math.max.apply(null, items.map(function (t) { return t.rate == null ? 0 : t.rate; }).concat([1]));
        el.innerHTML = '<div class="trend-wrap">' +
            items.map(function (t) {
                var h = Math.max(6, Math.round((t.rate == null ? 0 : t.rate) / max * 110));
                return '<div class="trend-col">' +
                    '<div class="trend-col-rate">' + pctLabel(t.rate) + '</div>' +
                    '<div class="trend-col-bar" style="height:' + h + 'px"></div>' +
                    '<div class="trend-col-month">' + esc(t.month) + '</div>' +
                    '<div class="trend-col-sub">' + t.sessions + ' days • ' + t.attended + '/' + t.marks + '</div>' +
                    '</div>';
            }).join('') + '</div>';
    }

    function sortBy(col) {
        if (an.sort === col) { an.dir = an.dir === 'desc' ? 'asc' : 'desc'; }
        else { an.sort = col; an.dir = col === 'name' || col === 'section' ? 'asc' : 'desc'; }
        runAnalytics(1);
    }

    function updateSortHeaders() {
        var map = { name: 1, section: 2, attended: 3, rate: 4, absent: 5, last_attended: 6 };
        document.querySelectorAll('#section-analytics .th-sortable').forEach(function (th, idx) {
            var col = Object.keys(map).filter(function (k) { return map[k] === idx + 1; })[0];
            th.classList.remove('sort-asc', 'sort-desc');
            if (col === an.sort) {
                th.classList.add(an.dir === 'asc' ? 'sort-asc' : 'sort-desc');
                th.setAttribute('aria-sort', an.dir === 'asc' ? 'ascending' : 'descending');
            } else {
                th.setAttribute('aria-sort', 'none');
            }
        });
    }

    function exportCsv() {
        if (!an.lastMembers.length) { window.toast('Run an analysis first.', 'e'); return; }
        var head = ['Member', 'Code', 'Section', 'Sessions Held', 'Present', 'Late', 'Absent', 'Attended', 'Rate %', 'Absent %', 'Last Attended'];
        var rows = an.lastMembers.map(function (m) {
            return [
                m.student_name + ' ' + (m.father_name || ''), m.member_code || '', m.section,
                m.sessions_held, m.present, m.late, m.absent, m.attended,
                m.rate == null ? '' : m.rate, m.absent_rate == null ? '' : m.absent_rate,
                m.last_attended ? fmtDate(m.last_attended, 'medium') : ''
            ].map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',');
        });
        var csv = '\ufeff' + head.join(',') + '\n' + rows.join('\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'mezmur-analytics-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(a); a.click(); a.remove();
        window.toast('CSV exported (current page).', 's');
    }

    // ══════════════════════════════════════════════════════════
    // MODULE 4 — ATTENDANCE TAKERS
    // ══════════════════════════════════════════════════════════
    function loadTakers() {
        var tb = $('mzTakerTbody');
        tb.innerHTML = skeletonRows(4);
        window.api.get('/admin/api_dept_takers.php?action=list').then(function (d) {
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="6">' + errorState(d.message || 'Unable to load takers.', 'Mezmur.reloadTakers()') + '</td></tr>';
                return;
            }
            var items = d.items || [];
            takersCache = items;
            if (!items.length) {
                tb.innerHTML = '<tr><td colspan="6">' + emptyState('fa-user-shield', 'No mezmur takers yet', 'Create a mezmur attendance taker so a trusted member can record section attendance from the mobile app. These accounts belong to the mezmur department only.', '<button class="btn-primary btn-sm" onclick="Mezmur.openTakerModal()"><i class="fa-solid fa-user-plus"></i> Add Taker</button>') + '</td></tr>';
                return;
            }
            tb.innerHTML = items.map(function (t) {
                return '<tr>' +
                    '<td><b>' + esc(t.full_name || t.username) + '</b></td>' +
                    '<td class="text-dim">' + esc(t.username) + '</td>' +
                    '<td class="text-dim">' + esc(t.role_label || 'Mezmur Attendance Taker') + '</td>' +
                    '<td class="text-dim nowrap">' + fmtDate(t.created_at) + '</td>' +
                    '<td>' + (t.is_active ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-inactive">Disabled</span>') + '</td>' +
                    '<td class="nowrap">' +
                    '<button class="btn-secondary btn-sm" onclick="Mezmur.toggleTaker(' + t.id + ')">' + (t.is_active ? '<i class="fa-solid fa-ban"></i> Disable' : '<i class="fa-solid fa-check"></i> Enable') + '</button>' +
                    '</td></tr>';
            }).join('');
        }).catch(function (err) {
            tb.innerHTML = '<tr><td colspan="6">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.reloadTakers()') + '</td></tr>';
        });
    }

    function openTakerModal() {
        $('mzTkName').value = ''; $('mzTkUser').value = ''; $('mzTkPass').value = '';
        showError($('mzTkError'), '');
        openModalF('mzTakerModal', '#mzTkName');
    }

    function createTaker() {
        var name = $('mzTkName').value.trim(), user = $('mzTkUser').value.trim(), pass = $('mzTkPass').value;
        if (!name || !user || !pass) { showError($('mzTkError'), 'All fields are required.'); return; }
        if (pass.length < 6) { showError($('mzTkError'), 'Password must be at least 6 characters.'); return; }
        var btn = $('mzTkSaveBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating…';
        // Governed department-taker endpoint: the server re-checks
        // that mezmur_dept may only create mezmur_attendance_taker
        // accounts, and runs advanced username validation.
        window.api.post('/admin/api_dept_takers.php', {
            action: 'create', role: 'mezmur_attendance_taker',
            full_name: name, username: user, password: pass
        }).then(function (d) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Create Account';
            if (d.status !== 'success') { showError($('mzTkError'), d.message || 'Unable to create the account.'); return; }
            closeModalF('mzTakerModal');
            window.toast('Mezmur attendance taker created.', 's');
            loadTakers();
        }).catch(function (err) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Create Account';
            showError($('mzTkError'), (err && err.message) || 'Connection error.');
        });
    }

    var takersCache = [];
    function toggleTaker(id, confirmed) {
        if (confirmed !== true) {
            var t = takersCache.filter(function (x) { return Number(x.id) === Number(id); })[0] || {};
            var nm = t.full_name || t.username || 'this taker';
            sysConfirm(t.is_active ? 'Disable ' + nm + '?' : 'Enable ' + nm + '?', function () { toggleTaker(id, true); }, t.is_active
                ? { tier: 'medium', title: 'Disable the attendance taker ' + nm + '?', object: nm, summary: 'The account is switched off, not deleted.', intent: 'I want to disable this taker',
                    consequences: ['This person can no longer log in or record Mezmur attendance.', 'Packets they already submitted stay for review.'],
                    keeps: ['The account and its history are kept.', 'You can enable it again at any time.'], phrase: 'DISABLE TAKER', confirmLabel: 'Disable taker' }
                : { tier: 'low', title: 'Enable ' + nm + '?', summary: 'This person can log in and record Mezmur attendance again.', confirmLabel: 'Enable taker' });
            return;
        }
        window.api.post('/admin/api_dept_takers.php', { action: 'toggle', user_id: id }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Action failed.', 'e'); return; }
            window.toast(d.message || 'Done.', 's');
            loadTakers();
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    // ── wiring ─────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════
    // P0 AUDIO MANAGER — attach / play / remove hymn audio.
    // The upload is a DIRECT browser PUT to a short-lived presigned
    // Cloudflare R2 URL (two-phase): PHP only hands out the signed
    // URL and later verifies the object. Shared-hosting upload limits
    // never apply because the bytes never cross PHP.
    // ══════════════════════════════════════════════════════════
    var audioMgr = { id: 0, file: null };

    function openAudio(id) {
        apiGet('action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load this hymn.', 'e'); return; }
            var h = d.item;
            audioMgr.id = h.id;
            audioMgr.file = null;
            $('mzAudioHymnId').value = h.id;
            $('mzAudioHymnName').textContent = h.title || '';
            var meta = [];
            if (h.categories && h.categories.length) meta.push(h.categories.map(function (c) { return c.name; }).join(', '));
            if (h.zemarians && h.zemarians.length) meta.push(h.zemarians.map(function (z) { return z.name; }).join(', '));
            $('mzAudioMeta').textContent = meta.join(' · ');

            var status = h.audio_status || 'none';
            var player = $('mzAudioPlayer');
            var pWrap = $('mzAudioPlayerWrap');
            var err = $('mzAudioErr');
            err.classList.add('is-hidden'); err.textContent = '';
            $('mzAudioFile').value = '';
            var listenBtn = $('mzAudioListenBtn');
            if (listenBtn) listenBtn.classList.add('is-hidden');
            // Always detach the previous object so a stale src cannot
            // sit at 0:00/0:00 after the modal reopens.
            try { player.pause(); } catch (e) {}
            player.removeAttribute('src');
            player.load();

            if (status === 'ready') {
                pWrap.classList.add('is-hidden');
                if (listenBtn) listenBtn.classList.remove('is-hidden');
                $('mzAudioPickLabel').textContent = 'Replace audio';
                $('mzAudioState').textContent = 'Ready · ' + (h.audio_format || '').toUpperCase() +
                    (h.audio_size ? ' · ' + fmtBytes(h.audio_size) : '') +
                    (h.audio_duration_s ? ' · ' + fmtDur(h.audio_duration_s) : '') +
                    ' — listen in the Mezmur player (lyrics, queue, next/previous).';
                $('mzAudioRemoveBtn').classList.remove('is-hidden');
                // P44: timings are only meaningful against real audio.
                var sb = $('mzAudioSyncBtn'); if (sb) sb.classList.remove('is-hidden');
            } else if (status === 'pending') {
                pWrap.classList.add('is-hidden');
                $('mzAudioPickLabel').textContent = 'Upload audio (choose the file)';
                $('mzAudioState').textContent = 'A previous upload was started but never finished, so it was discarded — choosing the file starts a fresh upload (there is no resume).';
                $('mzAudioRemoveBtn').classList.remove('is-hidden');
                var sb2 = $('mzAudioSyncBtn'); if (sb2) sb2.classList.add('is-hidden');
            } else {
                pWrap.classList.add('is-hidden');
                $('mzAudioPickLabel').textContent = 'Choose audio file…';
                $('mzAudioState').textContent = 'No audio yet. Pick an mp3 / m4a / ogg / wav (up to 15 MB) — it is uploaded straight to the media CDN.';
                $('mzAudioRemoveBtn').classList.add('is-hidden');
                var sb3 = $('mzAudioSyncBtn'); if (sb3) sb3.classList.add('is-hidden');
            }
            openModalF('mzAudioModal');
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    /** Bind a playable URL onto the modal <audio> and start loading. */
    function bindAudioSrc(url) {
        var player = $('mzAudioPlayer');
        if (!player || !url) return;
        player.pause();
        player.src = url;
        player.load();
    }

    /**
     * Mint a short-lived signed GET against the R2 API host. Upload
     * already proved that host works; the public custom-domain URL
     * often 403s (bucket not public / domain not connected), which
     * is why the native player sat at 0:00/0:00 on a Ready hymn.
     */
    function attachAudioStream(h) {
        if (!audioMgr.id) return;
        apiGet('action=audio_stream&id=' + encodeURIComponent(audioMgr.id)).then(function (s) {
            if (s.status === 'success' && s.url) {
                bindAudioSrc(s.url);
                $('mzAudioState').textContent = 'Ready · ' + (h.audio_format || '').toUpperCase() +
                    (h.audio_size ? ' · ' + fmtBytes(h.audio_size) : '') +
                    (h.audio_duration_s ? ' · ' + fmtDur(h.audio_duration_s) : '') +
                    ' — press play.';
                return;
            }
            if (h.audio_url) {
                bindAudioSrc(h.audio_url);
                $('mzAudioState').textContent = 'Ready · ' + (h.audio_format || '').toUpperCase() +
                    ' — using the public media URL.';
                return;
            }
            audioErr(s.message || 'Could not get a playback URL. Re-upload the file, or ask the administrator to check the R2 media settings.');
        }).catch(function (e) {
            if (h.audio_url) { bindAudioSrc(h.audio_url); return; }
            audioErr(((e && e.message) || 'Could not get a playback URL.') + ' Check your connection and retry.');
        });
    }

    function pickAudio() {
        var input = $('mzAudioFile');
        if (!input) return;
        input.click();
    }

    function fmtBytes(b) {
        b = parseInt(b, 10) || 0;
        return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : (b / 1024).toFixed(0) + ' KB';
    }
    function fmtDur(s) {
        s = parseInt(s, 10) || 0;
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }

    function audioErr(msg) {
        var err = $('mzAudioErr');
        err.textContent = msg; err.classList.remove('is-hidden');
    }

    function uploadAudio(file) {
        if (!audioMgr.id) return;
        var err = $('mzAudioErr');
        err.classList.add('is-hidden'); err.textContent = '';
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        var allowed = ['mp3', 'm4a', 'ogg', 'wav', 'aac', 'opus'];
        if (allowed.indexOf(ext) === -1) { audioErr('Unsupported audio format. Allowed: mp3, m4a, ogg, wav, aac, opus.'); return; }
        if (file.size <= 0 || file.size > 15 * 1024 * 1024) { audioErr('Audio must be between 1 byte and 15 MB.'); return; }

        apiPost({ action: 'audio_presign', hymn_id: audioMgr.id, ext: ext, size: file.size }).then(function (d) {
            if (d.status !== 'success' || !d.upload_url) {
                audioErr(d.message || 'Could not start the upload. If this persists, the R2 media settings may be missing on the server.');
                return;
            }
            var pw = $('mzAudioProgressWrap');
            var bar = $('mzAudioProgressBar');
            var lbl = $('mzAudioProgressLabel');
            pw.classList.remove('is-hidden');
            bar.style.width = '0%'; lbl.textContent = 'Uploading… 0%';

            var xhr = new XMLHttpRequest();
            xhr.open('PUT', d.upload_url, true);
            // Content-Type is SIGNED into the presigned URL, so it must be
            // the server-chosen value, not file.type. Sending anything else
            // makes storage reject the PUT. (Content-Length is signed too,
            // but browsers set it from the real body and forbid overriding
            // it — which is what pins the reserved size.)
            xhr.setRequestHeader('Content-Type', d.content_type || file.type || 'application/octet-stream');
            xhr.upload.onprogress = function (e) {
                if (e.lengthComputable) {
                    var pct = Math.round((e.loaded / e.total) * 100);
                    bar.style.width = pct + '%';
                    lbl.textContent = 'Uploading… ' + pct + '%';
                }
            };
            xhr.onload = function () {
                pw.classList.add('is-hidden');
                if (xhr.status < 200 || xhr.status >= 300) {
                    audioErr('Upload to the media CDN failed (HTTP ' + xhr.status + '). If you are on a private/old browser this may be a browser CORS rule on the bucket — ask the administrator to allow this site in the bucket CORS settings.');
                    return;
                }
                // Phase 2: verify the object landed and mark the hymn ready.
                apiPost({ action: 'audio_confirm', hymn_id: audioMgr.id }).then(function (c) {
                    if (c.status !== 'success') { audioErr(c.message || 'The file uploaded but could not be confirmed. Please retry.'); return; }
                    window.toast('Audio attached — streaming from the CDN.', 's');
                    loadList(); loadStats();
                    openAudio(audioMgr.id);
                    audioPlay(audioMgr.id);
                }).catch(function (e) {
                    audioErr(((e && e.message) || 'Could not confirm the upload.') + ' The file may still be on storage — press the audio button again to check.');
                });
            };
            xhr.onerror = function () {
                pw.classList.add('is-hidden');
                audioErr('Upload failed — network error while talking to the media CDN. Check your connection and retry.');
            };
            xhr.send(file);
        }).catch(function (e) {
            audioErr(((e && e.message) || 'Connection error.') + ' The server may be outdated — ask the administrator to pull the latest code.');
        });
    }

    function initAudioDialog() {
        var el = $('mzAudioModal');
        if (!el || el.dataset.p0) return;
        el.dataset.p0 = '1';
        var player = $('mzAudioPlayer');
        // Save measured duration when the player can read it (once per open).
        player.addEventListener('loadedmetadata', function () {
            var a = this;
            var s = Math.round(a.duration || 0);
            if (s > 0 && audioMgr.id) {
                apiPost({ action: 'audio_set_duration', hymn_id: audioMgr.id, duration_s: s });
            }
        });
        // Surface a real reason instead of a silent 0:00/0:00 player.
        player.addEventListener('error', function () {
            var code = (player.error && player.error.code) || 0;
            var map = {
                1: 'Playback was aborted.',
                2: 'Network error talking to the media host. The file may not be publicly reachable — try Replace audio.',
                3: 'This file could not be decoded. Re-encode as AAC M4A (ffmpeg -c:a aac -b:a 96k -movflags +faststart) and upload again.',
                4: 'No supported audio source. Re-upload as mp3 or m4a.'
            };
            audioErr(map[code] || 'Playback failed. Re-upload the file as mp3 or m4a.');
        });
        $('mzAudioFile').addEventListener('change', function () {
            var file = this.files && this.files[0];
            if (!file) return;
            if (!audioMgr.id) { this.value = ''; return; }
            uploadAudio(file);
        });
        var listenBtn = $('mzAudioListenBtn');
        if (listenBtn) listenBtn.addEventListener('click', function () {
            if (audioMgr.id) audioPlay(audioMgr.id);
        });
    }

    function audioPlay(id) {
        var queue = (lib.lastItems || []).filter(function (h) {
            return h && h.audio_status === 'ready';
        });
        if (window.MezmurPlayer && typeof window.MezmurPlayer.play === 'function') {
            if (Number(window.MezmurPlayer.currentId()) === Number(id)) {
                window.MezmurPlayer.toggle();
                return;
            }
            window.MezmurPlayer.play(id, queue);
            return;
        }
        openAudio(id);
    }

    function removeAudio() {
        if (!audioMgr.id) return;
        var dcAudio = { tier: 'medium', title: 'Remove the audio from this hymn?', summary: 'The recording is deleted, not just hidden.', intent: 'I want to remove the audio',
            consequences: ['The audio file is DELETED from the media CDN. This cannot be undone.', 'The hymn returns to lyrics-only in the app; playing and downloading stop.'],
            keeps: ['Lyrics and timings are kept.', 'You can upload a new audio file later.'], irreversible: true, phrase: 'REMOVE AUDIO', confirmLabel: 'Remove audio' };
        sysConfirm('Remove the audio from this hymn? The file is deleted from the media CDN and the hymn returns to lyrics-only.', function () {
            apiPost({ action: 'audio_remove', hymn_id: audioMgr.id }).then(function (d) {
                if (d.status !== 'success') { window.toast(d.message || 'Could not remove audio.', 'e'); return; }
                window.toast(d.message || 'Audio removed.', 's');
                loadList(); loadStats();
                openAudio(audioMgr.id);
            }).catch(function () {});
        }, dcAudio);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var debounce = null;
        $('mzSearch').addEventListener('input', function () {
            clearTimeout(debounce);
            var v = this.value;
            // P43: 250ms, raised from 160ms. Nielsen's 0.1s "instant"
            // threshold applies to FEEDBACK, not to the network round
            // trip — and the input echoes the keystroke instantly either
            // way. 160ms let a continuous typist issue ~375 requests a
            // minute, above the server's own 240/min ceiling; 250ms plus
            // request-supersession keeps a burst of typing to roughly one
            // real query, which is what actually protects the database.
            debounce = setTimeout(function () {
                var t = v.trim();
                // P42: the old rule was `if (t.length === 1) return;`,
                // which ABANDONED the reload. Typing one character (or
                // deleting back to one) left the previous result list
                // frozen on screen with no indication it was stale — the
                // list said one thing, the search box another.
                //
                // The server still ignores 1-char queries (an unindexed
                // '%x%' scan over every hymn), so we do not send one.
                // Instead we treat it as "no filter yet" and show the
                // unfiltered list, which is honest and self-consistent.
                lib.search = (t.length === 1) ? '' : t;
                lib.page = 1;
                loadList();
            }, 160);
        });
        $('mzCategoryFilter').addEventListener('change', function () {
            lib.category = '';
            lib.categoryId = parseInt(this.value, 10) || 0;
            lib.page = 1; loadList();
        });
        $('mzStatusFilter').addEventListener('change', function () { lib.status = this.value; lib.page = 1; loadList(); });
        $('mzZemarianFilter').addEventListener('change', function () {
            lib.zemarianId = parseInt(this.value, 10) || 0; lib.page = 1; loadList();
        });
        $('mzHymnMainCat').addEventListener('change', onHymnMainChange);
        initPickPanels();
        initLyricsEditor();
        initSysDialog();
        initImageDialog();
        initAudioDialog();
        initSyncAudioControls();
        if (window.MezmurPlayer && typeof window.MezmurPlayer.init === 'function') {
            window.MezmurPlayer.init({
                get: apiGet,
                post: apiPost,
                toast: function (m, t) { window.toast(m, t); }
            });
        }
        initColorDialog();
        initInlineKeys();
        initSecPop();
        setEditorMarkup('');
        populateHymnCats(null);
        $('mzLengthFilter').addEventListener('change', function () { lib.length = this.value; lib.page = 1; loadList(); });
        $('mzLanguageFilter').addEventListener('change', function () { lib.language = this.value; lib.page = 1; loadList(); });
        loadCatalog();


        // Lazy loading: fetch only what the user is actually looking at.
        // core.js may restore the last-used section before this runs;
        // whichever section is active now gets its (single) load.
        var active = document.querySelector('.school-section.active');
        var name = active ? (active.getAttribute('data-section') || 'overview') : 'overview';
        loadTab(name);
    });

    // Public API (inline onclick handlers in the shell)
    window.Mezmur = {
        // overview
        loadOverview: loadOverview,
        migrateSchema: migrateSchema, reloadTakers: loadTakers, libReload: loadList,
        quickTake: quickTake, quickReview: quickReview, quickLibrary: quickLibrary, quickAnalytics: quickAnalytics, quickTakers: quickTakers,
        gotoAttendance: gotoAttendance, jumpToDate: jumpToDate,
        // library
        openAdd: openAdd, openEdit: openEdit, save: saveHymn, view: viewHymn, setStatus: setHymnStatus,
        // P0 audio
        audioManage: openAudio, audioPlay: audioPlay, audioPick: pickAudio, audioRemove: removeAudio,
        closeAudio: function () { closeModalF('mzAudioModal'); },
        clearFilters: clearFilters,
        browseCategory: browseCategory, browseZemarian: browseZemarian,
        openCatalog: openCatalog,
        mgrTab: mgrTab, mgrAddMain: mgrAddMain, mgrAddSubOpen: mgrAddSubOpen, mgrAddSub: mgrAddSub, mgrAddZem: mgrAddZem,
        mgrEdit: mgrEdit, mgrSave: mgrSave, mgrCancel: mgrCancel, mgrToggle: mgrToggle, mgrSort: mgrSort, mgrImage: mgrImage,
        mgrToggleOpen: mgrToggleOpen, mgrRemoveZemImage: mgrRemoveZemImage,
        // P66 hymn art
        mgrArt: mgrArt, mgrArtRemove: mgrArtRemove, viewArtSet: viewArtSet, viewArtRemove: viewArtRemove,
        mgrColors: mgrColors, closeColorDialog: function () { closeModalF('mzColorDialog'); }, closeImageDialog: function () { URL.revokeObjectURL(imgPick.url); imgPick = { id: 0, file: null, url: '' }; closeModalF('mzImageDialog'); },
        closeModal: function () { closeModalF('mzHymnModal'); },
        // P44 lyric timing editor
        syncOpen: syncOpen, syncClose: syncClose, syncSave: syncSave,
        syncStamp: syncStamp, syncPlayPause: syncPlayPause,
        syncNudge: syncNudge, syncSeekTo: syncSeekTo, syncReset: syncReset,
        closeView: function () { closeModalF('mzViewModal'); },
        anMore: anMore, anPerPage: anPerPage,
        libPage: function (p) { if (p >= 1 && p <= lib.totalPages) { lib.page = p; loadList(); } },
        libMore: function () { if (lib.page < lib.totalPages) { lib.page++; lib.appending = true; loadList(); } },
        libPerPage: function (v) {
            var n = parseInt(v, 10);
            if ([25, 50, 100].indexOf(n) < 0) return;
            lib.perPage = n;
            try { localStorage.setItem('fkss_pp_mz_lib', String(n)); } catch (e) { /* private mode */ }
            lib.page = 1; loadList();
        },
        // attendance
        loadDays: function () { loadDays(1); },
        sessPage: function (p) { loadDays(p); },
        openDay: openDay, viewSheet: viewSheet, quickReview: quickReview,
        closeSheet: function () { closeSheet(false); },
        // review inbox
        loadSubmissions: function () { loadSubmissions(); }, subPage: subPage, subMore: subMore, subPerPage: subPerPage,
        switchSubTab: switchSubTab, quickDecision: quickDecision,
        printQrRoster: printQrRoster,
        exportSubmissions: exportSubmissions, openReview: openReview, submitReview: submitReview, viewPacket: viewPacket,
        // analytics
        runAnalytics: runAnalytics, sortBy: sortBy, exportCsv: exportCsv,
        // takers
        openTakerModal: openTakerModal, createTaker: createTaker, toggleTaker: toggleTaker
    };
})();
