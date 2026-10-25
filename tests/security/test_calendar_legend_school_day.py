"""
School Calendar legend: meaning of the school-view day count.

In the school view (no class selected) a school day shows "N classes meet",
where N is the number of active classes whose saved timetable working days
include that weekday (SchoolCalendarService::weekdayClassCounts). The legend
must say so. The class view uses its own day labels and keeps its legend text.

Behaviour tests run the real admin/js/wbws-calendar.js and
admin/js/school_calendar.js in Node with a minimal DOM stub (no browser, no
network); a static pin covers the page's asset version.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
EC_JS = ROOT / "admin/js/wbws-calendar.js"
JS = ROOT / "admin/js/school_calendar.js"
PAGE = ROOT / "admin/school_calendar.php"

SCHOOL_TEXT = "School day — number of classes whose saved working days include this weekday"
OLD_TEXT = "School day — classes meet per the timetable"
UNCHANGED = [
    "School day (makeup class)",
    "Holiday / vacation",
    "Day Off",
    "Neutral — no class meets",
    "Click a day (today or later) to mark or reopen a Day Off.",
]

HARNESS = r"""
var fs = require('fs');
var els = {};
function el(id) {
  if (!els[id]) {
    els[id] = { id: id, innerHTML: '', textContent: '', value: '', handlers: {},
      addEventListener: function (t, f) { this.handlers[t] = f; },
      insertAdjacentHTML: function (p, h) { this.innerHTML += h; },
      querySelector: function () { return null; },
      querySelectorAll: function () { return []; },
      appendChild: function () {}, setAttribute: function () {}, classList: { add: function () {}, remove: function () {} } };
  }
  return els[id];
}
var document = {
  readyState: 'complete',
  getElementById: el,
  querySelector: function () { return el('_q'); },
  querySelectorAll: function () { return []; },
  addEventListener: function () {},
  createElement: function () { return el('_c' + Math.random()); },
  body: { appendChild: function () {} }
};
var window = { document: document, innerWidth: 1200, innerHeight: 800, addEventListener: function () {},
  SCHOOL_CALENDAR_CFG: { api: '/admin/api_school_calendar.php', canWrite: false, today: '2026-10-26' } };
globalThis.window = window; globalThis.document = document;
globalThis.fetch = function (url) {
  var action = (/action=([a-z_]+)/.exec(url) || [])[1];
  var body = { status: 'success' };
  if (action === 'classes') body.classes = [{ id: 12, name: 'Grade 7(18+)' }];
  if (action === 'overview') { body.school_days = {}; body.holidays = []; body.day_offs = [];
    body.unconfigured_classes = []; body.weekday_class_counts = {}; }
  if (action === 'class_days') body.days = {};
  return Promise.resolve({ status: 200, json: function () { return Promise.resolve(body); } });
};
function run(file) { (new Function('window', 'document', 'global', fs.readFileSync(file, 'utf8')))(window, document, window); }
run(process.argv[1]);
run(process.argv[2]);
var tick = function () { return new Promise(function (r) { setTimeout(r, 20); }); };
var out = {};
tick().then(tick).then(function () {
  out.school = el('sc-legend').innerHTML;
  out.schoolTitle = el('sc-gridtitle').textContent;
  var sel = el('sc-class'); sel.value = '12'; sel.handlers.change();
  return tick();
}).then(tick).then(function () {
  out.cls = el('sc-legend').innerHTML;
  out.clsTitle = el('sc-gridtitle').textContent;
  process.stdout.write(JSON.stringify(out));
});
"""


def run_page() -> dict:
    proc = subprocess.run(["node", "-e", HARNESS, str(EC_JS), str(JS)],
                          capture_output=True, text=True, timeout=30)
    if proc.returncode != 0:
        raise AssertionError("node failed: " + proc.stderr[-2000:])
    return json.loads(proc.stdout)


@unittest.skipUnless(shutil.which("node"), "node is required")
class CalendarLegendTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.out = run_page()

    def test_harness_reached_both_views(self):
        self.assertTrue(self.out["schoolTitle"].endswith("school view"), self.out["schoolTitle"])
        self.assertTrue(self.out["clsTitle"].endswith("class view"), self.out["clsTitle"])

    def test_school_view_explains_the_day_count(self):
        legend = self.out["school"]
        self.assertTrue(SCHOOL_TEXT in legend, "school-view legend lacks the day-count meaning")
        self.assertFalse(OLD_TEXT in legend, "school-view legend still shows the old text")

    def test_class_view_keeps_its_legend(self):
        legend = self.out["cls"]
        self.assertTrue(OLD_TEXT in legend, "class-view legend changed")
        self.assertFalse(SCHOOL_TEXT in legend, "school-view wording leaked into the class view")

    def test_other_legend_items_unchanged(self):
        for view in ("school", "cls"):
            for item in UNCHANGED:
                self.assertTrue(item in self.out[view], f"{view} legend lost: {item}")
            self.assertEqual(self.out[view].count("<span"), 6, f"{view} legend item count changed")


class CalendarAssetVersionTests(unittest.TestCase):
    def test_page_loads_the_new_script_version(self):
        page = PAGE.read_text(encoding="utf-8")
        self.assertTrue("$assetVer = '20261026lg';" in page, "school_calendar.php asset version not bumped")


if __name__ == "__main__":
    unittest.main()
