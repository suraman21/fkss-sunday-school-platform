/**
 * ============================================================================
 * Education Timetable builder (web) — layout approved by the owner on
 * 2026‑10‑20 from the interactive demo (eduid‑style):
 *
 *     Class selector → Working days (Cancel / Save days) → Bell schedule
 *     (per class: + Add period, edit, delete, copy from class) → Weekly grid
 *     (click a cell: subject, teacher, room → Save / Clear) → Makeup classes.
 *
 * Mounts into #sec-timetable of admin/dashboards/edu_dept.php and talks only
 * to admin/api_timetable.php. All dates shown are Ethiopian (WBWSCalendar);
 * the weekday grid itself carries no dates. No framework, no CDN.
 * ============================================================================
 */
(function (global) {
  'use strict';

  var API = '/admin/api_timetable.php';
  var DAYS = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };
  var DAY_ORDER = [1, 2, 3, 4, 5, 6, 7];
  var COLORS = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#0891b2', '#15803d', '#9333ea', '#b45309', '#0f766e', '#be123c'];

  function esc(v) {
    if (v === null || v === undefined) return '';
    var d = document.createElement('div');
    d.textContent = String(v);
    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function csrf() { return typeof global.CSRF_TOKEN === 'string' ? global.CSRF_TOKEN : ''; }
  function parseJson(r) {
    return r.text().then(function (t) {
      try { return JSON.parse(t); } catch (e) { return { status: 'error', message: 'Invalid server response' }; }
    });
  }
  function get(params) {
    var q = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    return fetch(API + '?' + q, { credentials: 'same-origin' }).then(parseJson);
  }
  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', csrf());
    Object.keys(data || {}).forEach(function (k) {
      var v = data[k];
      if (Array.isArray(v)) { v.forEach(function (x) { fd.append(k + '[]', x); }); }
      else if (v !== undefined && v !== null) fd.append(k, v);
    });
    return fetch(API + '?action=' + encodeURIComponent(action), { method: 'POST', body: fd, credentials: 'same-origin' }).then(parseJson);
  }
  function notify(msg, kind) {
    if (typeof global.toast === 'function') { try { global.toast(msg, kind === 'err' ? 'err' : (kind === 'warn' ? 'w' : 'ok')); return; } catch (e) { /* fall through */ } }
    if (kind === 'err') alert(msg);
  }
  // Shared danger dialog (admin/js/danger_confirm.js). If it failed to load
  // we refuse rather than fall back to a browser popup.
  function danger(opts) {
    if (global.DangerConfirm && typeof global.DangerConfirm.ask === 'function') return global.DangerConfirm.ask(opts);
    notify('The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.', 'err');
    return Promise.resolve(false);
  }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); }
  function serverFail(d, fallback) { return new Error((d && d.message) || fallback); }
  function ecDate(iso, fmt) {
    if (!iso) return '';
    try {
      if (global.WBWSCalendar && typeof global.WBWSCalendar.formatDate === 'function') {
        return global.WBWSCalendar.formatDate(iso, fmt || 'long');
      }
    } catch (e) { /* ignore */ }
    return iso;
  }
  function toMin(t) { var p = String(t || '0:0').split(':'); return (+p[0]) * 60 + (+p[1]); }
  function nowMin() { var d = new Date(); return d.getHours() * 60 + d.getMinutes(); }
  function localIso() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function todayIso() { var d = new Date().getDay(); return d === 0 ? 7 : d; }
  function subjectLabel(s) { return s ? (s.name_en ? s.name + ' (' + s.name_en + ')' : s.name) : ''; }
  function className(c) { return c ? (c.class_name_en ? c.class_name + ' — ' + c.class_name_en : c.class_name) : ''; }

  var CSS = ''
    + '.tt-wrap{max-width:1240px}'
    + '.tt-head{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}'
    + '.tt-head h2{font-size:1.35rem;margin:0 0 .2rem;color:#0f172a}'
    + '.tt-head p{margin:0;color:#64748b;font-size:.85rem}'
    + '.tt-head .tt-cls{margin-left:auto;display:flex;gap:8px;align-items:center}'
    + '.tt-head select{padding:9px 12px;border-radius:10px;border:1px solid #e5e7eb;font:inherit;background:#fff;min-width:220px;font-weight:600}'
    + '.tt-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 18px;margin-bottom:16px}'
    + '.tt-ct{display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap}'
    + '.tt-ct h3{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;margin:0;font-weight:700}'
    + '.tt-ct .tt-act{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}'
    + '.tt-d{color:#64748b;margin:0 0 12px;font-size:.82rem}'
    + '.tt-btn{border:1px solid #e5e7eb;background:#fff;padding:8px 14px;border-radius:9px;cursor:pointer;font:inherit;font-weight:600;font-size:.8rem;color:#0f172a}'
    + '.tt-btn:disabled{opacity:.5;cursor:not-allowed}'
    + '.tt-btn.p{background:#2563eb;color:#fff;border-color:#2563eb}'
    + '.tt-btn.ghost{border-color:transparent;color:#64748b}'
    + '.tt-btn.sm{padding:5px 9px;font-size:.74rem}'
    + '.tt-btn.danger{color:#dc2626}'
    + '.tt-days{display:flex;gap:8px;flex-wrap:wrap}'
    + '.tt-day{padding:8px 14px;border-radius:9px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-weight:600;font-size:.8rem;font-family:inherit}'
    + '.tt-day.on{background:#2563eb;color:#fff;border-color:#2563eb}'
    + '.tt-note{color:#f97316;font-size:.72rem;margin-top:10px}'
    + '.tt-chips{display:flex;gap:8px;flex-wrap:wrap}'
    + '.tt-pchip{border:1px solid #e5e7eb;border-radius:9px;padding:7px 10px;display:flex;gap:8px;align-items:center;font-size:.8rem;background:#fff}'
    + '.tt-pchip b{font-weight:600}.tt-pchip span{color:#64748b}'
    + '.tt-pchip.brk{background:#fef3c7;border-color:#fcd34d}'
    + '.tt-pchip button{border:0;background:none;cursor:pointer;color:#94a3b8;padding:0 2px;font-size:.8rem}'
    + '.tt-pchip button:hover{color:#2563eb}'
    + '.tt-form{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;background:#f8fafc;border:1px dashed #e5e7eb;border-radius:10px;padding:12px;margin-top:12px}'
    + '.tt-form label,.tt-modal label{display:flex;flex-direction:column;gap:4px;font-size:.66rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em}'
    + '.tt-form input,.tt-form select,.tt-modal input,.tt-modal select{padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;font:inherit;background:#fff;font-size:.82rem;text-transform:none;letter-spacing:0;color:#0f172a}'
    + '.tt-err{background:#fee2e2;color:#dc2626;padding:9px 12px;border-radius:8px;font-size:.8rem;margin-top:8px;flex-basis:100%}'
    + '.tt-warn{background:#fef3c7;color:#b45309;padding:9px 12px;border-radius:8px;font-size:.8rem;margin:0 0 12px}'
    + '.tt-grid{width:100%;border-collapse:separate;border-spacing:0}'
    + '.tt-grid th{font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:#64748b;text-align:left;padding:8px 10px;border-bottom:1px solid #e5e7eb;font-weight:700;background:#fff}'
    + '.tt-grid th.today{color:#2563eb}'
    + '.tt-grid td{padding:6px;vertical-align:top}'
    + '.tt-grid td.per{width:120px;padding-top:10px}'
    + '.tt-grid td.per b{display:block;font-size:.82rem}.tt-grid td.per small{color:#64748b}'
    + '.tt-cell{border:1px solid #e5e7eb;border-radius:8px;min-height:50px;padding:6px 8px;cursor:pointer;color:#cbd5e1;font-size:1rem;background:#fff;font-family:inherit;width:100%;text-align:left}'
    + '.tt-cell:hover{border-color:#2563eb;background:#eff6ff}'
    + '.tt-cell.brk{background:#fef3c7;border-color:#fde68a;color:#b45309;font-size:.74rem;cursor:default;text-align:center;padding-top:15px}'
    + '.tt-cell.ro{cursor:default}.tt-cell.ro:hover{border-color:#e5e7eb;background:#fff}'
    + '.tt-les{font-size:.74rem;color:#0f172a;border-left:4px solid;padding-left:7px}'
    + '.tt-les b{display:block;font-size:.8rem}.tt-les span{color:#64748b}'
    + '.tt-les.now{background:#fef9c3;border-radius:4px}'
    + '.tt-mk{display:flex;gap:12px;align-items:center;border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;margin-top:8px;flex-wrap:wrap}'
    + '.tt-mk .dt{min-width:190px;font-weight:600;font-size:.82rem}.tt-mk .dt small{display:block;color:#64748b;font-weight:400}'
    + '.tt-mk .body{font-size:.82rem}.tt-mk .body .muted{display:block;color:#64748b;font-size:.75rem}'
    + '.tt-muted{color:#64748b;font-size:.8rem}'
    + '.tt-pill{background:#eff6ff;color:#2563eb;border-radius:999px;padding:3px 10px;font-size:.72rem;font-weight:600}'
    + '.tt-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;z-index:9000}'
    + '.tt-modal{background:#fff;border-radius:14px;padding:20px;width:440px;max-width:94vw;max-height:92vh;overflow:auto}'
    + '.tt-modal h4{margin:0 0 2px;font-size:1rem;color:#0f172a}'
    + '.tt-modal .sub{color:#64748b;font-size:.8rem;margin-bottom:12px;display:block}'
    + '.tt-modal label{margin-bottom:10px}'
    + '.tt-modal .foot{display:flex;justify-content:space-between;align-items:center;margin-top:8px;gap:8px}'
    + '.tt-printhead{display:none}'
    + '@media print{body *{visibility:hidden}#sec-timetable,#sec-timetable *{visibility:visible}#sec-timetable{position:absolute;left:0;top:0;width:100%}'
    + '.tt-noprint{display:none!important}.tt-card{border:0;padding:0}.tt-printhead{display:block;margin-bottom:10px}.tt-printhead h3{margin:0;font-size:1.1rem;letter-spacing:0;text-transform:none;color:#0f172a}'
    + '.tt-cell{color:transparent}.tt-les{color:#0f172a;-webkit-print-color-adjust:exact;print-color-adjust:exact}@page{size:A4 landscape;margin:12mm}}';

  function EduTimetable() {
    this.root = null;
    this.classes = [];
    this.classId = 0;
    this.yearName = '';
    this.readonly = false;
    this.setup = null;        // response of class_setup
    this.pendingDays = null;  // unsaved working-day selection
    this.periodForm = null;   // null | {id?:number}
    this.makeupForm = false;
    this.busy = false;
    this.booted = false;
  }

  EduTimetable.prototype.boot = function () {
    var self = this;
    this.root = document.getElementById('sec-timetable');
    if (!this.root) return;
    if (!document.getElementById('tt-css')) {
      var st = document.createElement('style'); st.id = 'tt-css'; st.textContent = CSS; document.head.appendChild(st);
    }
    if (this.booted) { if (this.classId) this.loadClass(); return; }
    this.booted = true;
    this.root.innerHTML = '<div class="tt-wrap"><p class="tt-muted">Loading timetable…</p></div>';
    get({ action: 'classes' }).then(function (d) {
      if (d.status !== 'success') { self.root.innerHTML = '<div class="tt-card"><div class="tt-err">' + esc(d.message || 'Could not load classes.') + '</div></div>'; return; }
      self.classes = d.classes || [];
      self.yearName = d.year_name || '';
      self.readonly = !!d.readonly;
      var remembered = 0;
      try { remembered = parseInt(localStorage.getItem('edu_tt_class') || '0', 10); } catch (e) { remembered = 0; }
      var pick = self.classes.filter(function (c) { return c.id === remembered; })[0] || self.classes[0];
      self.classId = pick ? pick.id : 0;
      if (!self.classId) { self.root.innerHTML = '<div class="tt-card"><p class="tt-muted">No active classes. Create classes first.</p></div>'; return; }
      self.loadClass();
    });
  };

  EduTimetable.prototype.selectClass = function (id) {
    this.classId = +id || 0;
    this.pendingDays = null; this.periodForm = null; this.makeupForm = false;
    try { localStorage.setItem('edu_tt_class', String(this.classId)); } catch (e) { /* ignore */ }
    this.loadClass();
  };

  EduTimetable.prototype.loadClass = function () {
    var self = this;
    if (!this.classId) return;
    return get({ action: 'class_setup', class_id: this.classId }).then(function (d) {
      if (d.status !== 'success') { notify(d.message || 'Could not load the class timetable.', 'err'); return; }
      self.setup = d;
      self.render();
    });
  };

  EduTimetable.prototype.cls = function () {
    var id = this.classId;
    return this.classes.filter(function (c) { return c.id === id; })[0] || null;
  };
  EduTimetable.prototype.subject = function (id) {
    return (this.setup && this.setup.subjects || []).filter(function (s) { return s.id === +id; })[0] || null;
  };
  EduTimetable.prototype.teacher = function (id) {
    return (this.setup && this.setup.teachers || []).filter(function (t) { return t.id === +id; })[0] || null;
  };
  EduTimetable.prototype.period = function (id) {
    return (this.setup && this.setup.periods || []).filter(function (p) { return p.id === +id; })[0] || null;
  };
  EduTimetable.prototype.entry = function (pid, day) {
    return (this.setup && this.setup.entries || []).filter(function (e) { return e.period_id === pid && e.day_of_week === day; })[0] || null;
  };
  EduTimetable.prototype.color = function (subjectId) {
    var subs = this.setup && this.setup.subjects || [];
    for (var i = 0; i < subs.length; i++) if (subs[i].id === +subjectId) return COLORS[i % COLORS.length];
    return '#94a3b8';
  };

  // ───────────────────────── render ─────────────────────────
  EduTimetable.prototype.render = function () {
    var s = this.setup, c = this.cls(), self = this;
    if (!s || !c) return;
    var days = this.pendingDays || s.working_days;
    var dirty = !!this.pendingDays && this.pendingDays.join(',') !== s.working_days.join(',');
    var ro = this.readonly;
    // No saved row: the days shown are only the default, and attendance is
    // blocked until they are saved, so Save must be offered even unchanged.
    var unsaved = s.days_saved === false && !ro;
    var h = '<div class="tt-wrap">';
    h += '<div class="tt-head tt-noprint"><div><h2>Timetable</h2><p>Select a class, set its working days and bell schedule, then fill each day. Teachers see their schedule on their dashboard automatically.</p></div>';
    h += '<div class="tt-cls"><span class="tt-pill">' + esc(this.yearName) + '</span><select data-act="select-class">' + this.classes.map(function (x) {
      return '<option value="' + x.id + '"' + (x.id === self.classId ? ' selected' : '') + '>' + esc(className(x)) + '</option>';
    }).join('') + '</select></div></div>';
    if (ro) h += '<div class="tt-warn">You are viewing a past academic year — the timetable is read‑only.</div>';

    // Working days
    h += '<div class="tt-card tt-noprint"><div class="tt-ct"><h3>Working days — ' + esc(c.class_name_en || c.class_name) + '</h3><div class="tt-act">'
      + (dirty ? '<button class="tt-btn ghost" data-act="cancel-days">Cancel</button>' : '')
      + (dirty || unsaved ? '<button class="tt-btn p" data-act="save-days">Save days</button>' : '')
      + '</div></div>'
      + (unsaved ? '<div class="tt-warn">Working days are not saved yet. Attendance is blocked for this class until you press Save days.</div>' : '')
      + '<p class="tt-d">' + (days.length ? days.map(function (d) { return DAYS[d].slice(0, 3); }).join(' · ') : 'No days selected') + ' — the days this class meets. The grid below shows only these.</p>'
      + '<div class="tt-days">' + DAY_ORDER.map(function (d) {
        return '<button type="button" class="tt-day' + (days.indexOf(d) >= 0 ? ' on' : '') + '" data-act="toggle-day" data-day="' + d + '"' + (ro ? ' disabled' : '') + '>' + DAYS[d] + '</button>';
      }).join('') + '</div>'
      + '<div class="tt-note">Removing a day permanently clears any lessons already scheduled on it.</div></div>';

    // Bell schedule
    h += '<div class="tt-card tt-noprint"><div class="tt-ct"><h3>Bell schedule — ' + esc(c.class_name_en || c.class_name) + '</h3><div class="tt-act">'
      + (ro ? '' : '<button class="tt-btn sm" data-act="copy-bell">Copy from class…</button><button class="tt-btn p" data-act="add-period">+ Add period</button>')
      + '</div></div><div class="tt-chips">'
      + (s.periods.length ? s.periods.map(function (p) {
        return '<div class="tt-pchip' + (p.is_break ? ' brk' : '') + '"><b>' + (p.is_break ? '☕ ' : '') + esc(p.label) + '</b><span>' + esc(p.start_time) + '–' + esc(p.end_time) + '</span>'
          + (ro ? '' : '<button type="button" title="Edit" data-act="edit-period" data-id="' + p.id + '">✎</button><button type="button" title="Delete" data-act="del-period" data-id="' + p.id + '">🗑</button>') + '</div>';
      }).join('') : '<span class="tt-muted">No periods yet — add the first one.</span>')
      + '</div>' + (this.periodForm ? this.renderPeriodForm() : '') + '</div>';

    // Weekly grid
    h += '<div class="tt-card"><div class="tt-printhead"><h3>Timetable — ' + esc(className(c)) + '</h3><div class="tt-muted">Academic year ' + esc(this.yearName) + ' · Printed ' + esc(ecDate(localIso(), 'long')) + '</div></div>'
      + '<div class="tt-ct tt-noprint"><h3>Weekly grid</h3><div class="tt-act"><span class="tt-muted">' + s.entries.length + ' lesson' + (s.entries.length === 1 ? '' : 's') + '</span><button class="tt-btn sm" data-act="print">🖨 Print / PDF</button></div></div>'
      + (s.working_days.length && s.periods.length ? this.renderGrid() : '<p class="tt-muted">Select at least one working day and add a period to start.</p>')
      + '</div>';

    // Makeup classes
    h += '<div class="tt-card tt-noprint"><div class="tt-ct"><h3>Makeup / extra classes</h3><div class="tt-act">' + (ro ? '' : '<button class="tt-btn p" data-act="toggle-makeup">+ Add makeup class</button>') + '</div></div>'
      + '<p class="tt-d">One‑off lessons on a specific date (e.g. covering a holiday) — separate from the weekly grid. Teachers see them on that day.</p>'
      + (this.makeupForm ? this.renderMakeupForm() : '')
      + (s.makeups.length ? s.makeups.map(function (m) {
        var sub = self.subject(m.subject_id);
        return '<div class="tt-mk"><div class="dt">' + esc(ecDate(m.lesson_date, 'long')) + '<small>' + esc(DAYS[m.day_of_week] || '') + ' · ' + esc(m.start_time) + '–' + esc(m.end_time) + '</small></div>'
          + '<div class="body"><b>' + esc(sub ? sub.name : m.subject_name) + '</b> · ' + esc(m.teacher_name) + (m.room ? ' · ' + esc(m.room) : '') + (m.reason ? '<span class="muted">' + esc(m.reason) + '</span>' : '') + '</div>'
          + (ro ? '' : '<button class="tt-btn sm ghost danger" style="margin-left:auto" data-act="del-makeup" data-id="' + m.id + '">Remove</button>') + '</div>';
      }).join('') : '<p class="tt-muted" style="margin:0">No upcoming makeup classes.</p>')
      + '</div>';
    h += '</div>';
    this.root.innerHTML = h;
    this.bind();
    if (global.WBWSCalendar && typeof global.WBWSCalendar.refreshPickers === 'function') {
      try { global.WBWSCalendar.refreshPickers(); } catch (e) { /* ignore */ }
    }
  };

  EduTimetable.prototype.renderGrid = function () {
    var s = this.setup, self = this, tIso = todayIso();
    var h = '<table class="tt-grid"><thead><tr><th>Period</th>' + s.working_days.map(function (d) {
      return '<th' + (d === tIso ? ' class="today"' : '') + '>' + DAYS[d] + (d === tIso ? ' · today' : '') + '</th>';
    }).join('') + '</tr></thead><tbody>';
    s.periods.forEach(function (p) {
      h += '<tr><td class="per"><b>' + esc(p.label) + '</b><small>' + esc(p.start_time) + '–' + esc(p.end_time) + '</small></td>';
      s.working_days.forEach(function (d) {
        if (p.is_break) { h += '<td><div class="tt-cell brk">Break</div></td>'; return; }
        var e = self.entry(p.id, d);
        var inner = '+';
        if (e) {
          var now = d === tIso && nowMin() >= toMin(p.start_time) && nowMin() < toMin(p.end_time);
          inner = '<div class="tt-les' + (now ? ' now' : '') + '" style="border-color:' + self.color(e.subject_id) + '"><b>' + esc(e.subject_name) + '</b><span>' + esc(e.teacher_name) + (e.room ? ' · ' + esc(e.room) : '') + '</span></div>';
        }
        h += '<td><button type="button" class="tt-cell' + (self.readonly ? ' ro' : '') + '" data-act="cell" data-pid="' + p.id + '" data-day="' + d + '">' + inner + '</button></td>';
      });
      h += '</tr>';
    });
    return h + '</tbody></table>';
  };

  EduTimetable.prototype.renderPeriodForm = function () {
    var s = this.setup, p = this.periodForm.id ? this.period(this.periodForm.id) : null;
    var lessons = s.periods.filter(function (x) { return !x.is_break; }).length;
    var last = s.periods[s.periods.length - 1];
    return '<div class="tt-form" id="tt-period-form">'
      + '<label>Label<input id="tt-pl" maxlength="50" value="' + esc(p ? p.label : 'Period ' + (lessons + 1)) + '" style="width:140px"></label>'
      + '<label>Start time<input id="tt-ps" type="time" value="' + esc(p ? p.start_time : (last ? last.end_time : '08:00')) + '"></label>'
      + '<label>End time<input id="tt-pe" type="time" value="' + esc(p ? p.end_time : '') + '"></label>'
      + '<label>Type<select id="tt-pb"><option value="0"' + (p && p.is_break ? '' : ' selected') + '>Lesson</option><option value="1"' + (p && p.is_break ? ' selected' : '') + '>Break</option></select></label>'
      + '<button class="tt-btn p" data-act="save-period">' + (p ? 'Update' : 'Save period') + '</button>'
      + '<button class="tt-btn ghost" data-act="cancel-period">Cancel</button><div id="tt-perr"></div></div>';
  };

  EduTimetable.prototype.renderMakeupForm = function () {
    var s = this.setup;
    var iso = localIso();
    return '<div class="tt-form" id="tt-makeup-form">'
      + '<label>Date<input id="tt-mkd" type="date" value="' + iso + '" min="' + iso + '"></label>'
      + '<label>Start<input id="tt-mks" type="time" value="14:00"></label>'
      + '<label>End<input id="tt-mke" type="time" value="14:45"></label>'
      + '<label>Subject<select id="tt-mksub"><option value="">— Select —</option>' + s.subjects.map(function (x) { return '<option value="' + x.id + '">' + esc(subjectLabel(x)) + '</option>'; }).join('') + '</select></label>'
      + '<label>Teacher<select id="tt-mkt"><option value="">— Select —</option>' + s.teachers.map(function (t) { return '<option value="' + t.id + '">' + esc(t.name) + (t.assigned ? '' : ' (not assigned to this class)') + '</option>'; }).join('') + '</select></label>'
      + '<label>Room<input id="tt-mkr" maxlength="50" style="width:100px"></label>'
      + '<label>Reason<input id="tt-mkreason" maxlength="255" placeholder="e.g. holiday cover" style="width:170px"></label>'
      + '<button class="tt-btn p" data-act="save-makeup">Save</button><button class="tt-btn ghost" data-act="toggle-makeup">Cancel</button><div id="tt-mkerr"></div></div>';
  };

  // ───────────────────────── events ─────────────────────────
  EduTimetable.prototype.bind = function () {
    var self = this;
    var sel = this.root.querySelector('[data-act="select-class"]');
    if (sel) sel.addEventListener('change', function () { self.selectClass(this.value); });
    this.root.querySelectorAll('[data-act]').forEach(function (el) {
      if (el.tagName === 'SELECT') return;
      el.addEventListener('click', function (ev) {
        ev.preventDefault();
        self.handle(this.getAttribute('data-act'), this);
      });
    });
  };

  EduTimetable.prototype.handle = function (act, el) {
    var self = this;
    switch (act) {
      case 'toggle-day': return this.toggleDay(+el.getAttribute('data-day'));
      case 'cancel-days': this.pendingDays = null; return this.render();
      case 'save-days': return this.saveDays();
      case 'add-period': this.periodForm = {}; this.render(); return this.focus('tt-pl');
      case 'edit-period': this.periodForm = { id: +el.getAttribute('data-id') }; this.render(); return this.focus('tt-pl');
      case 'cancel-period': this.periodForm = null; return this.render();
      case 'save-period': return this.savePeriod();
      case 'del-period': return this.deletePeriod(+el.getAttribute('data-id'));
      case 'copy-bell': return this.copyBellDialog();
      case 'cell': if (!this.readonly) this.openCell(+el.getAttribute('data-pid'), +el.getAttribute('data-day')); return;
      case 'print': return global.print();
      case 'toggle-makeup': this.makeupForm = !this.makeupForm; return this.render();
      case 'save-makeup': return this.saveMakeup();
      case 'del-makeup': return this.deleteMakeup(+el.getAttribute('data-id'));
      default: return;
    }
  };
  EduTimetable.prototype.focus = function (id) { var el = document.getElementById(id); if (el) el.focus(); };
  EduTimetable.prototype.val = function (id) { var el = document.getElementById(id); return el ? String(el.value || '').trim() : ''; };
  EduTimetable.prototype.showErr = function (boxId, msg) { var b = document.getElementById(boxId); if (b) b.innerHTML = '<div class="tt-err">' + esc(msg) + '</div>'; else notify(msg, 'err'); };

  // Working days
  EduTimetable.prototype.toggleDay = function (d) {
    if (this.readonly) return;
    var cur = (this.pendingDays || this.setup.working_days).slice();
    var i = cur.indexOf(d);
    if (i >= 0) cur.splice(i, 1); else cur.push(d);
    cur.sort(function (a, b) { return a - b; });
    this.pendingDays = cur;
    this.render();
  };
  EduTimetable.prototype.saveDays = function () {
    var self = this, days = this.pendingDays || this.setup.working_days.slice();
    var removed = this.setup.working_days.filter(function (d) { return days.indexOf(d) < 0; });
    var finish = function (d) {
      self.pendingDays = null; notify(d.message || 'Working days saved.'); return self.loadClass();
    };
    if (!removed.length) {
      return post('save_days', { class_id: this.classId, days: days }).then(function (d) {
        if (d.status !== 'success') { notify(d.message || 'Could not save working days.', 'err'); return; }
        return finish(d);
      });
    }
    var names = removed.map(function (d) { return DAYS[d]; });
    var list = names.join(', ');
    var lessons = this.setup.entries.filter(function (e) { return removed.indexOf(e.day_of_week) >= 0; });
    var cname = className(this.cls());
    var consequences = [];
    if (lessons.length) {
      removed.forEach(function (d) {
        var k = lessons.filter(function (e) { return e.day_of_week === d; }).length;
        if (k) consequences.push(plural(k, 'scheduled lesson') + ' on ' + DAYS[d] + ' will be deleted.');
      });
    } else {
      consequences.push('No lessons are scheduled on ' + list + ' yet, so no lessons will be deleted.');
    }
    consequences.push('Teachers can no longer take attendance for this class on ' + list + ' (attendance is only allowed on working days).');
    if (!days.length) consequences.push('This class will have NO working days, so attendance will be blocked for it completely.');
    return danger({
      tier: 'high',
      title: 'Remove ' + list + ' from ' + cname + '?',
      object: cname,
      summary: cname + ' will no longer meet on ' + list + '.',
      intent: removed.length === 1 ? 'I want to remove this day' : 'I want to remove these days',
      consequences: consequences,
      keeps: ['Attendance already saved on past ' + list + ' stays in history.', 'You can add the day back later, but deleted lessons must be entered again.'],
      irreversible: lessons.length > 0,
      phrase: 'REMOVE DAY',
      confirmLabel: removed.length === 1 ? 'Remove ' + names[0] : 'Remove ' + removed.length + ' days',
      onConfirm: function (res) {
        return post('save_days', { class_id: self.classId, days: days, confirm_phrase: res.phrase }).then(function (d) {
          if (d.status !== 'success') throw serverFail(d, 'Could not save working days.');
          return d;
        });
      }
    }).then(function (res) { if (res) return finish(res.result || {}); });
  };

  // Bell schedule
  EduTimetable.prototype.savePeriod = function () {
    var self = this;
    var payload = {
      class_id: this.classId, id: this.periodForm && this.periodForm.id ? this.periodForm.id : 0,
      label: this.val('tt-pl'), start_time: this.val('tt-ps'), end_time: this.val('tt-pe'), is_break: this.val('tt-pb')
    };
    if (!payload.label || !payload.start_time || !payload.end_time) return this.showErr('tt-perr', 'Label, start and end time are required.');
    if (toMin(payload.end_time) <= toMin(payload.start_time)) return this.showErr('tt-perr', 'End time must be after start time.');
    var send = function () {
      return post('save_period', payload).then(function (d) {
        if (d.status !== 'success') { self.showErr('tt-perr', d.message || 'Could not save period.'); return; }
        self.periodForm = null; notify(d.message || 'Period saved.'); return self.loadClass();
      });
    };
    // LOW: changing the time of a period that already has lessons.
    var old = payload.id ? this.period(+payload.id) : null;
    var n = old ? this.setup.entries.filter(function (e) { return e.period_id === old.id; }).length : 0;
    var timeChanged = !!old && (String(old.start_time).slice(0, 5) !== String(payload.start_time).slice(0, 5)
      || String(old.end_time).slice(0, 5) !== String(payload.end_time).slice(0, 5));
    if (!old || !n || !timeChanged) return send();
    return danger({
      tier: 'low',
      title: 'Change the time of ' + old.label + '?',
      summary: old.label + ' moves from ' + old.start_time + '–' + old.end_time + ' to ' + payload.start_time + '–' + payload.end_time + '.',
      consequences: [plural(n, 'scheduled lesson') + ' in this period will use the new time.'],
      confirmLabel: 'Save new time'
    }).then(function (res) { if (res) return send(); });
  };
  EduTimetable.prototype.deletePeriod = function (id) {
    var self = this, p = this.period(id);
    if (!p) return;
    var lessons = this.setup.entries.filter(function (e) { return e.period_id === id; });
    var cname = className(this.cls());
    var consequences = ['“' + p.label + '” (' + p.start_time + '–' + p.end_time + ') is removed from the bell schedule of ' + cname + '.'];
    if (lessons.length) {
      var dayNames = lessons.map(function (e) { return DAYS[e.day_of_week]; }).filter(function (v, i, a) { return a.indexOf(v) === i; });
      consequences.push(plural(lessons.length, 'scheduled lesson') + ' in this period will be deleted (' + dayNames.join(', ') + ').');
    } else {
      consequences.push('No lessons are scheduled in this period.');
    }
    return danger({
      tier: lessons.length ? 'high' : 'medium',
      title: 'Delete ' + p.label + ' from ' + cname + '?',
      object: p.label + ' · ' + p.start_time + '–' + p.end_time,
      summary: 'This removes the period from the class bell schedule.',
      intent: 'I want to delete this period',
      consequences: consequences,
      irreversible: true,
      phrase: 'DELETE PERIOD',
      confirmLabel: 'Delete ' + p.label,
      onConfirm: function (res) {
        return post('delete_period', { class_id: self.classId, period_id: id, confirm_phrase: res.phrase }).then(function (d) {
          if (d.status !== 'success') throw serverFail(d, 'Could not delete period.');
          return d;
        });
      }
    }).then(function (res) { if (res) { notify((res.result && res.result.message) || 'Period deleted.'); return self.loadClass(); } });
  };
  EduTimetable.prototype.copyBellDialog = function () {
    var self = this;
    var others = this.classes.filter(function (x) { return x.id !== self.classId; });
    if (!others.length) { notify('There is no other class to copy from.', 'err'); return; }
    this.modal('<h4>Copy bell schedule</h4><span class="sub">Copies the working days and periods of another class into ' + esc(className(this.cls())) + '. Existing periods and lessons of this class will be replaced.</span>'
      + '<label>From class<select id="tt-cf">' + others.map(function (x) { return '<option value="' + x.id + '">' + esc(className(x)) + '</option>'; }).join('') + '</select></label><div id="tt-cferr"></div>'
      + '<div class="foot"><span></span><span><button class="tt-btn ghost" data-m="close">Cancel</button> <button class="tt-btn p" data-m="ok">Continue</button></span></div>',
      function () {
        var fromId = +self.val('tt-cf');
        var from = others.filter(function (x) { return x.id === fromId; })[0];
        var cname = className(self.cls()), fname = className(from);
        var nP = (self.setup.periods || []).length, nE = (self.setup.entries || []).length;
        var consequences = [];
        consequences.push(nP ? 'All ' + plural(nP, 'period') + ' of ' + cname + ' will be replaced by the periods of ' + fname + '.' : cname + ' gets the periods of ' + fname + '.');
        consequences.push(nE ? 'All ' + plural(nE, 'scheduled lesson') + ' of ' + cname + ' will be deleted. You must enter the lessons again.' : 'No lessons are scheduled for ' + cname + ' yet.');
        consequences.push('The working days of ' + cname + ' become the same as ' + fname + '. Attendance follows the new working days.');
        self.closeModal();
        danger({
          tier: 'high',
          title: 'Replace the timetable of ' + cname + '?',
          object: fname + '  →  ' + cname,
          summary: 'Copies the bell schedule and working days of ' + fname + ' into ' + cname + '.',
          intent: 'I want to replace this schedule',
          consequences: consequences,
          keeps: [fname + ' itself is not changed.'],
          irreversible: nE > 0,
          phrase: 'REPLACE SCHEDULE',
          confirmLabel: 'Replace schedule',
          onConfirm: function (res) {
            return post('copy_bell', { class_id: self.classId, from_class_id: fromId, confirm_phrase: res.phrase }).then(function (d) {
              if (d.status !== 'success') throw serverFail(d, 'Could not copy.');
              return d;
            });
          }
        }).then(function (res) { if (res) { notify((res.result && res.result.message) || 'Copied.'); self.loadClass(); } });
        return Promise.resolve(false);
      });
  };

  // Cells
  EduTimetable.prototype.openCell = function (pid, day) {
    var self = this, p = this.period(pid), e = this.entry(pid, day) || {}, s = this.setup;
    if (!p || p.is_break) return;
    var subjOpts = s.subjects.map(function (x) { return '<option value="' + x.id + '"' + (e.subject_id === x.id ? ' selected' : '') + '>' + esc(subjectLabel(x)) + '</option>'; }).join('');
    var tOpts = s.teachers.map(function (t) { return '<option value="' + t.id + '"' + (e.teacher_id === t.id ? ' selected' : '') + '>' + esc(t.name) + (t.assigned ? '' : ' (not assigned to this class)') + '</option>'; }).join('');
    this.modal('<h4>' + esc(className(this.cls())) + ' · ' + DAYS[day] + ' · ' + esc(p.label) + '</h4><span class="sub">' + esc(p.start_time) + '–' + esc(p.end_time) + '</span>'
      + '<label>Subject<select id="tt-cs"><option value="">— Select subject —</option>' + subjOpts + '</select></label>'
      + '<label>Teacher<select id="tt-ct"><option value="">— Select teacher —</option>' + tOpts + '</select></label>'
      + '<label>Room (optional)<input id="tt-cr" maxlength="50" value="' + esc(e.room || '') + '" placeholder="e.g. Room 2"></label><div id="tt-cerr"></div>'
      + '<div class="foot"><button class="tt-btn ghost danger" data-m="clear"' + (e.id ? '' : ' disabled') + '>Clear</button><span><button class="tt-btn ghost" data-m="close">Cancel</button> <button class="tt-btn p" data-m="ok">Save</button></span></div>',
      function () {
        var sid = self.val('tt-cs'), tid = self.val('tt-ct');
        if (!sid || !tid) { self.showErr('tt-cerr', 'Subject and teacher are required.'); return Promise.resolve(false); }
        return post('save_entry', { class_id: self.classId, period_id: pid, day_of_week: day, subject_id: sid, teacher_id: tid, room: self.val('tt-cr') }).then(function (d) {
          if (d.status !== 'success') { self.showErr('tt-cerr', d.message || 'Could not save lesson.'); return false; }
          notify(d.message || 'Lesson saved.', d.warning ? 'warn' : 'ok'); self.loadClass(); return true;
        });
      },
      function () {
        var subj = self.subject(e.subject_id);
        return danger({
          tier: 'low',
          title: 'Clear this lesson?',
          summary: DAYS[day] + ' · ' + p.label + ' (' + p.start_time + '–' + p.end_time + ')' + (subj ? ' · ' + subjectLabel(subj) : '') + ' becomes an empty slot.',
          confirmLabel: 'Clear lesson'
        }).then(function (res) {
          if (!res) return false;
          return post('clear_entry', { class_id: self.classId, period_id: pid, day_of_week: day }).then(function (d) {
            if (d.status !== 'success') { self.showErr('tt-cerr', d.message || 'Could not clear lesson.'); return false; }
            notify('Lesson cleared.'); self.loadClass(); return true;
          });
        });
      });
    // Pre-fill the teacher from teacher_assignments when a subject is chosen.
    var cs = document.getElementById('tt-cs'), ct = document.getElementById('tt-ct');
    if (cs && ct) cs.addEventListener('change', function () {
      var sid = +cs.value; if (!sid) return;
      var match = s.teachers.filter(function (t) { return t.assigned && t.subjects.indexOf(sid) >= 0; })[0]
        || (s.teachers.filter(function (t) { return t.assigned; }).length === 1 ? s.teachers.filter(function (t) { return t.assigned; })[0] : null);
      if (match) ct.value = String(match.id);
    });
  };

  // Makeup classes
  EduTimetable.prototype.saveMakeup = function () {
    var self = this;
    var payload = {
      class_id: this.classId, lesson_date: this.val('tt-mkd'), start_time: this.val('tt-mks'), end_time: this.val('tt-mke'),
      subject_id: this.val('tt-mksub'), teacher_id: this.val('tt-mkt'), room: this.val('tt-mkr'), reason: this.val('tt-mkreason')
    };
    if (!payload.lesson_date) return this.showErr('tt-mkerr', 'Date is required.');
    if (!payload.subject_id || !payload.teacher_id) return this.showErr('tt-mkerr', 'Subject and teacher are required.');
    if (toMin(payload.end_time) <= toMin(payload.start_time)) return this.showErr('tt-mkerr', 'End time must be after start time.');
    return post('save_makeup', payload).then(function (d) {
      if (d.status !== 'success') { self.showErr('tt-mkerr', d.message || 'Could not save makeup class.'); return; }
      self.makeupForm = false; notify(d.message || 'Makeup class added.'); return self.loadClass();
    });
  };
  EduTimetable.prototype.deleteMakeup = function (id) {
    var self = this;
    var m = (this.setup.makeups || []).filter(function (x) { return +x.id === +id; })[0] || {};
    var when = m.lesson_date ? ecDate(m.lesson_date, 'long') : 'this date';
    return danger({
      tier: 'medium',
      title: 'Remove the makeup class on ' + when + '?',
      object: when + (m.start_time ? ' · ' + m.start_time + '–' + m.end_time : ''),
      summary: 'The extra class day for ' + className(this.cls()) + ' is cancelled.',
      intent: 'I want to remove this makeup class',
      consequences: ['The makeup class on ' + when + ' is removed from the timetable.',
        'Teachers can no longer take attendance on ' + when + ' unless it is a normal working day.'],
      keeps: ['Attendance that was already saved is kept.'],
      phrase: 'REMOVE MAKEUP',
      confirmLabel: 'Remove makeup class',
      onConfirm: function () {
        return post('delete_makeup', { makeup_id: id }).then(function (d) {
          if (d.status !== 'success') throw serverFail(d, 'Could not remove.');
          return d;
        });
      }
    }).then(function (res) { if (res) { notify('Makeup class removed.'); return self.loadClass(); } });
  };

  // Minimal modal: ok() / clear() return a promise resolving true to close.
  EduTimetable.prototype.modal = function (html, onOk, onClear) {
    this.closeModal();
    var ov = document.createElement('div'); ov.className = 'tt-overlay'; ov.id = 'tt-overlay';
    ov.innerHTML = '<div class="tt-modal" role="dialog" aria-modal="true">' + html + '</div>';
    document.body.appendChild(ov);
    var self = this;
    ov.addEventListener('click', function (ev) { if (ev.target === ov) self.closeModal(); });
    ov.querySelectorAll('[data-m]').forEach(function (b) {
      b.addEventListener('click', function (ev) {
        ev.preventDefault();
        var m = b.getAttribute('data-m');
        if (m === 'close') return self.closeModal();
        var fn = m === 'clear' ? onClear : onOk;
        if (!fn) return;
        b.disabled = true;
        Promise.resolve(fn()).then(function (ok) { if (ok) self.closeModal(); else b.disabled = false; });
      });
    });
    var first = ov.querySelector('select,input'); if (first) first.focus();
    if (global.WBWSCalendar && typeof global.WBWSCalendar.refreshPickers === 'function') {
      try { global.WBWSCalendar.refreshPickers(); } catch (e) { /* ignore */ }
    }
  };
  EduTimetable.prototype.closeModal = function () { var ov = document.getElementById('tt-overlay'); if (ov) ov.remove(); };

  global.EduTimetable = EduTimetable;
  if (!global.EduTimetableInstance) global.EduTimetableInstance = new EduTimetable();
}(typeof window !== 'undefined' ? window : this));
