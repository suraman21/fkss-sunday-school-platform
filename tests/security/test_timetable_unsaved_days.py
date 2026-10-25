"""
Timetable working days that were never saved.

When a class has no row in `timetable_class_days`, the builder displays the
default days (Saturday + Sunday), but the class-day attendance rule treats the
class as not configured and blocks attendance. The page must say that the days
are not saved and offer "Save days" for the days shown, so a class that really
meets Saturday + Sunday can be configured in one click.

Behaviour tests run the real admin/js/edu_timetable.js in Node with a minimal
DOM stub (no browser, no network); static pins cover the server contract.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
JS = ROOT / "admin/js/edu_timetable.js"
SERVICE = ROOT / "admin/backend/services/TimetableService.php"
DASHBOARD = ROOT / "admin/dashboards/edu_dept.php"

HARNESS = r"""
var fs = require('fs');
var src = fs.readFileSync(process.argv[1], 'utf8');
var cfg = JSON.parse(process.argv[2]);
var posts = [];
var root = { innerHTML: '', querySelector: function () { return null; },
             querySelectorAll: function () { return []; }, addEventListener: function () {} };
var document = {
  getElementById: function (id) { return id === 'sec-timetable' ? root : null; },
  createElement: function () {
    var o = {};
    Object.defineProperty(o, 'textContent', { set: function (v) {
      this.innerHTML = String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); } });
    return o;
  },
  head: { appendChild: function () {} }, body: { appendChild: function () {} },
  querySelectorAll: function () { return []; }
};
var window = { document: document, toast: function () {} };
globalThis.fetch = function (url, opts) {
  if (opts && opts.method === 'POST') {
    posts.push({ action: opts.body.get('action'), days: opts.body.getAll('days[]').map(Number) });
  }
  var setup = cfg.setup;
  return Promise.resolve({ text: function () {
    return Promise.resolve(JSON.stringify(opts && opts.method === 'POST'
      ? { status: 'success', message: 'Working days saved.' }
      : Object.assign({ status: 'success' }, setup)));
  } });
};
(new Function('window', 'document', src))(window, document);
var tt = new window.EduTimetable();
tt.root = root;
tt.classes = [{ id: 12, class_name: 'Class', class_name_en: 'Grade 7(18+)' }];
tt.classId = 12;
tt.yearName = '2019';
tt.readonly = !!cfg.readonly;
tt.setup = cfg.setup;
tt.render();
var out = {
  save: root.innerHTML.indexOf('data-act="save-days"') >= 0,
  cancel: root.innerHTML.indexOf('data-act="cancel-days"') >= 0,
  warning: root.innerHTML.indexOf('Working days are not saved yet') >= 0,
  posts: posts
};
if (!cfg.press) { console.log(JSON.stringify(out)); }
else {
  Promise.resolve(tt.saveDays()).then(function () {
    out.posts = posts; console.log(JSON.stringify(out));
  }, function (e) { out.error = String(e); out.posts = posts; console.log(JSON.stringify(out)); });
}
"""


def setup(days_saved=None):
    s = {
        "working_days": [6, 7],
        "periods": [{"id": 1, "label": "Period 1", "start_time": "11:00", "end_time": "12:00", "is_break": 0}],
        "entries": [], "teachers": [], "subjects": [], "makeups": [],
    }
    if days_saved is not None:
        s["days_saved"] = days_saved
    return s


def run_page(cfg: dict) -> dict:
    r = subprocess.run(["node", "-e", HARNESS, str(JS), json.dumps(cfg)],
                       capture_output=True, text=True, timeout=30)
    if r.returncode != 0:
        raise AssertionError("node failed: " + r.stderr)
    return json.loads(r.stdout.strip().splitlines()[-1])


class UnsavedWorkingDaysPage(unittest.TestCase):
    def test_unsaved_days_show_warning_and_save_button(self):
        out = run_page({"setup": setup(days_saved=False)})
        self.assertTrue(out["save"], "Save days must be offered when the days are not saved")
        self.assertTrue(out["warning"], "the page must say the days are not saved")
        self.assertFalse(out["cancel"], "nothing to cancel before the user clicks a day")

    def test_saved_days_look_exactly_as_before(self):
        out = run_page({"setup": setup(days_saved=True)})
        self.assertFalse(out["save"])
        self.assertFalse(out["warning"])

    def test_missing_flag_keeps_old_behaviour(self):
        out = run_page({"setup": setup(days_saved=None)})
        self.assertFalse(out["save"])
        self.assertFalse(out["warning"])

    def test_past_year_is_read_only(self):
        out = run_page({"setup": setup(days_saved=False), "readonly": True})
        self.assertFalse(out["save"])

    def test_pressing_save_without_clicking_saves_the_days_shown(self):
        out = run_page({"setup": setup(days_saved=False), "press": True})
        self.assertNotIn("error", out)
        self.assertEqual(out["posts"], [{"action": "save_days", "days": [6, 7]}])


class UnsavedWorkingDaysServer(unittest.TestCase):
    def test_class_setup_reports_whether_days_are_saved(self):
        php = SERVICE.read_text(encoding="utf-8")
        setup_body = php.split("public static function classSetup", 1)[1].split("public static function workingDays", 1)[0]
        self.assertIn("'days_saved'       => self::daysSaved($conn, $classId, $yearId)", setup_body)
        m = re.search(r"private static function daysSaved\(.*?\n    }\n", php, re.S)
        self.assertIsNotNone(m, "daysSaved() must exist")
        self.assertIn("FROM timetable_class_days WHERE class_id = ? AND academic_year_id = ?", m.group(0))
        # The display default is unchanged: unsaved classes still show Saturday + Sunday.
        self.assertIn("public const DEFAULT_DAYS = [6, 7];", php)

    def test_dashboard_loads_the_new_script_version(self):
        self.assertTrue('/admin/js/edu_timetable.js?v=20261025wd"' in DASHBOARD.read_text(encoding="utf-8"),
                        "edu_dept.php must load edu_timetable.js?v=20261025wd so browsers fetch the new script")


if __name__ == "__main__":
    unittest.main()
