"""Danger confirmation system — static + PHP checks (no server/DB needed).

Plan: docs/planning/DANGER_CONFIRMATION_RESEARCH_PLAN_2026-10-21.md
"""
import os
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(rel):
    return (ROOT / rel).read_text(encoding='utf-8', errors='replace')


# Files fully migrated to DangerConfirm: no native confirm()/prompt() allowed.
MIGRATED = [
    'admin/js/edu_timetable.js',
    'admin/js/school_calendar.js',
    # DC-2 Education + School Admin
    'admin/dashboards/edu_dept.php',
    'admin/dashboards/school_admin.php',
    'frontend/js/education_teachers.js',
    'frontend/js/education_assignments.js',
    # DC-3 Super Admin / HR / Information
    'admin/dashboards/super-admin.php',
    'admin/users.php',
    'admin/js/danger_user_forms.js',
    'admin/js/app_release.js',
    'admin/system_health.php',
    'admin/dashboards/sections/identity_codes_section.php',
    'admin/dashboards/ai_assistant.php',
    'admin/dashboards/ai_chatbot_widget.php',
    'admin/dashboards/hr-dept.php',
    'admin/dashboards/info-dept.php',
    'admin/js/hr_data_sync.js',
    'admin/js/family_link.js',
    'admin/js/new-members.js',
    'admin/js/id-card-directory.js',
    'admin/id_cards/view_id_card.php',
    'admin/components/profile_tab_section.php',
    'admin/js/reports/reports_v2.js',
    'admin/js/attendance_analytics_hub.js',
    # DC-4 Finance, Material, Groups, Mezmur, CMS, teachers/takers, shared
    'admin/dashboards/finance_department.php',
    'frontend/js/finance.js',
    'admin/dashboards/material_department.php',
    'admin/groups.php',
    'admin/dashboards/groups.php',
    'frontend/js/mezmur.js',
    'admin/js/content_editor.js',
    'admin/lesson_attendance.php',
    'admin/dashboards/teacher.php',
    'admin/dashboards/attendance_taker.php',
    'admin/js/attendance-sheet.js',
    'admin/js/department_status.js',
    'admin/js/comm.js',
]

# (endpoint file, phrase key) — HIGH actions that must be enforced server-side.
SERVER_ENFORCED = [
    ('admin/api_timetable.php', 'timetable.remove_day'),
    ('admin/api_timetable.php', 'timetable.delete_period'),
    ('admin/api_timetable.php', 'timetable.copy_bell'),
    # DC-2
    ('admin/api_education.php', 'education.delete_class'),
    ('admin/api_education.php', 'education.delete_term'),
    ('admin/api_subjects.php', 'subjects.delete_subject'),
    ('admin/api_subjects.php', 'subjects.apply_template'),
    # DC-3
    ('admin/backend/user-delete.php', 'users.delete'),
    ('admin/api_test_seed.php', 'test_seed.clear'),
    ('admin/api_app_release.php', 'app_release.delete_apk'),
    ('admin/api_app_release.php', 'app_release.force_update'),
    ('admin/api_import_members.php', 'members.import'),
    ('admin/api_guardians.php', 'guardians.link_all'),
    ('admin/api_identity_migration.php', 'identity.renumber'),
    # DC-4
    ('admin/api_finance.php', 'finance.delete_transaction'),
    ('admin/backend/groups_api.php', 'groups.delete_group'),
]

NATIVE = re.compile(r'(?<![\w.$])(?:window\.)?(confirm|prompt)\s*\(')


def strip_comments(js):
    js = re.sub(r'/\*.*?\*/', '', js, flags=re.S)
    return re.sub(r'(^|[^:\\])//[^\n]*', r'\1', js)


def php_phrases():
    src = read('admin/backend/danger_confirm.php')
    return dict(re.findall(r"'([a-z_]+\.[a-z_]+)'\s*=>\s*'([A-Z ]+)'", src))


class DangerConfirmComponent(unittest.TestCase):
    def test_component_core_rules(self):
        js = read('admin/js/danger_confirm.js')
        for needle, why in [
            ("'role', 'alertdialog'", 'alertdialog role'),
            ("'aria-modal', 'true'", 'aria-modal'),
            ("aria-describedby", 'described by body'),
            ('data-dcx-act="cancel"', 'cancel button'),
            ("ev.key === 'Escape'", 'Esc cancels'),
            ("autocomplete=\"off\"", 'no autocomplete on phrase'),
            ("I have read and understand these effects", 'step-2 acknowledgement (GitHub pattern)'),
            ("STEP ' + step + ' OF ' + steps", 'step indicator'),
            ("if (open) return Promise.resolve(false)", 'never stacks dialogs'),
            ("Working…", 'double-submit guard'),
        ]:
            self.assertIn(needle, js, why)
        self.assertIsNone(NATIVE.search(strip_comments(js)), 'component must not use native popups')
        # Initial focus is Cancel on non-typing steps (Primer rule).
        self.assertRegex(js, r"q\('\[data-dcx-act=\"cancel\"\]'\)")

    def test_phrases_are_plain_english_capitals(self):
        ph = php_phrases()
        self.assertGreaterEqual(len(ph), 10)
        for k, v in ph.items():
            self.assertRegex(v, r'^[A-Z]+( [A-Z]+)*$', k)


class DangerConfirmMigration(unittest.TestCase):
    def test_migrated_files_have_no_native_popups(self):
        for rel in MIGRATED:
            hits = [m.group(0) for m in NATIVE.finditer(strip_comments(read(rel)))]
            self.assertEqual(hits, [], rel)

    def test_pages_using_dialog_load_component(self):
        """Every PHP page that loads a script calling DangerConfirm (or defines it
        inline) must also load admin/js/danger_confirm.js."""
        users = set()
        for base in ('admin', 'frontend'):
            for p in (ROOT / base).rglob('*.js'):
                if 'vendor' in p.parts or p.name.endswith('.min.js') or p.name == 'danger_confirm.js':
                    continue
                txt = p.read_text(encoding='utf-8', errors='replace')
                # scripts that load the component themselves on demand are fine anywhere
                if 'DangerConfirm' in txt and 'danger_confirm.js' not in txt:
                    users.add(p.name)
        self.assertTrue(users, 'no module uses DangerConfirm')
        missing = []
        for p in list((ROOT / 'admin').rglob('*.php')) + list((ROOT / 'frontend').rglob('*.php')):
            if 'vendor' in p.parts:
                continue
            src = p.read_text(encoding='utf-8', errors='replace')
            loads = [u for u in users if re.search(r'src="[^"]*/' + re.escape(u) + r'[?"]', src)]
            inline = 'DangerConfirm.ask' in src
            if (loads or inline) and 'danger_confirm.js' not in src:
                missing.append(p)
        # Partials (sections/components) are fine when every page that includes
        # them loads the component.
        still = []
        for p in missing:
            includers = [q for q in (ROOT / 'admin').rglob('*.php')
                         if q != p and re.search(r"include[^;]*['\"/]" + re.escape(p.name) + r"['\"]", q.read_text(encoding='utf-8', errors='replace'))]
            if not includers or any('danger_confirm.js' not in q.read_text(encoding='utf-8', errors='replace') for q in includers):
                still.append(str(p.relative_to(ROOT)) + ' (included by: ' + ', '.join(str(q.relative_to(ROOT)) for q in includers) + ')')
        self.assertEqual(still, [])

    def test_js_phrases_match_server(self):
        ph = php_phrases()
        for rel, key in SERVER_ENFORCED:
            phrase = ph[key]
            found = any(("'" + phrase + "'") in read(f) for f in MIGRATED + [
                p for p in (
                    'admin/dashboards/edu_dept.php', 'admin/dashboards/school_admin.php',
                    'admin/dashboards/super-admin.php', 'admin/js/family_link.js', 'admin/js/hr_data_sync.js',
                    'admin/js/app_release.js', 'admin/dashboards/finance_department.php',
                    'frontend/js/finance.js', 'admin/dashboards/groups.php', 'admin/groups.php')
                if (ROOT / p).exists()])
            self.assertTrue(found, f'{key}: dialog phrase {phrase!r} not found in any page')

    def test_dc3_user_delete_forms_carry_csrf(self):
        # F9: both delete-user forms post to backend/user-delete.php, which calls
        # requireCsrf(); without the token every delete was refused.
        for rel in ('admin/dashboards/super-admin.php', 'admin/users.php'):
            src = read(rel)
            i = src.index('user-delete.php')
            self.assertIn('csrfField()', src[i:i + 900], rel)

    def test_dc3_conditional_server_rules(self):
        rel = read('admin/api_app_release.php')
        self.assertIn('$__risky', rel)            # force update only when force/min changes
        imp = read('admin/api_import_members.php')
        self.assertRegex(imp, r"if \(!\$dryRun\) \{\s*dc_require_phrase\('members.import'\)")
        mig = read('admin/api_identity_migration.php')
        self.assertRegex(mig, r"if \(\$mode === 'execute'\) \{[^}]*dc_require_phrase\('identity.renumber'\)")

    def test_dc4_no_native_popups_anywhere(self):
        # After DC-4 no web screen uses the browser's confirm()/prompt().
        bad = []
        for base in ('admin', 'frontend'):
            for p in (ROOT / base).rglob('*'):
                if p.suffix not in ('.php', '.js') or 'vendor' in p.parts or p.name.endswith('.min.js') or p.name == 'danger_confirm.js':
                    continue
                for i, line in enumerate(p.read_text(encoding='utf-8', errors='replace').splitlines(), 1):
                    t = line.strip()
                    if t.startswith(('//', '*', '/*', '<!--')):
                        continue
                    if re.search(r"(^|[^.\w$])(window\.)?(confirm|prompt)\s*\(", line):
                        bad.append(f'{p.relative_to(ROOT)}:{i}')
        self.assertEqual(bad, [])

    def test_dc4_group_delete_is_atomic_and_previewed(self):
        api = read('admin/backend/groups_api.php')
        i = api.index("$action === 'delete_group'")
        body = api[i:i + 2600]
        self.assertIn('begin_transaction', body)
        self.assertIn('rollback', body)
        self.assertIn("$action === 'impact_delete_group'", api)

    def test_dc4_attendance_resave_guard(self):
        sheet = read('admin/js/attendance-sheet.js')
        self.assertIn('confirmReplace', sheet)
        self.assertIn("phrase: 'REPLACE ATTENDANCE'", sheet)
        for rel in ('admin/dashboards/teacher.php', 'admin/dashboards/attendance_taker.php'):
            src = read(rel)
            self.assertIn('AttendanceSheet.snapshot(', src, rel)
            self.assertIn('AttendanceSheet.confirmReplace(', src, rel)

    def test_frontend_shell_loads_component(self):
        # frontend/js/*.js page scripts are loaded by name through the shared layout
        self.assertIn('danger_confirm.js', read('frontend/layouts/base.php'))

    def test_high_dialogs_send_phrase(self):
        for rel in ('admin/dashboards/edu_dept.php', 'admin/dashboards/school_admin.php'):
            self.assertIn('confirm_phrase', read(rel), rel)

    def test_dc2_server_guards(self):
        src = read('admin/api_education.php')
        for needle in ("case 'impact_delete_class'", "case 'impact_delete_term'", "'term_in_use'"):
            self.assertIn(needle, src)
        # impact previews are read-only GET actions, never in the POST-only list
        post_line = re.search(r'\$requirePostActions\s*=\s*\[(.*?)\];', src, re.S)
        if post_line:
            self.assertNotIn('impact_delete', post_line.group(1))

    def test_server_enforcement_present(self):
        for rel, key in SERVER_ENFORCED:
            src = read(rel)
            self.assertIn("danger_confirm.php", src, rel)
            direct = f"dc_require_phrase('{key}'" in src or f"dc_phrase_ok('{key}'" in src
            # or via an action => key map that is passed to dc_require_phrase()
            mapped = re.search(r"=>\s*'" + re.escape(key) + r"'", src) and re.search(r'dc_require_phrase\(\$__dc\w+\[\$action\]\)', src)
            self.assertTrue(direct or mapped, f'{rel} must enforce {key}')


@unittest.skipUnless(shutil.which('php'), 'php CLI not installed')
class DeployCacheBust(unittest.TestCase):
    # Audit 2026-10-22: scripts changed for the danger dialogs must load under a
    # NEW URL, or browsers keep the old script (1 h cache) that sends no phrase.
    def test_changed_scripts_have_new_versions(self):
        hr = read('admin/dashboards/hr-dept.php')
        info = read('admin/dashboards/info-dept.php')
        for tag in ['department_status.js?v=<?= SSMS_ASSET_VER ?>-20261022r', 'family_link.js?v=<?= SSMS_ASSET_VER ?>-20261022u',
                    'new-members.js?v=20261022r', 'id-card-directory.js?v=20261022r', 'hr_data_sync.js?v=20261022r']:
            self.assertIn(tag, hr)
        self.assertIn('department_status.js?v=<?= SSMS_ASSET_VER ?>-20261022r', info)
        self.assertIn("return '73.7';", read('admin/components/notification_center.php'))
        self.assertIn("$assetVer = '20261022r';", read('admin/reports_v2.php'))
        self.assertIn("-20261022t", read('admin/dashboards/sections/attendance_analytics_hub.php'))


class DangerConfirmPhp(unittest.TestCase):
    def run_php(self, code):
        out = subprocess.run(['php', '-r', code], capture_output=True, text=True, cwd=str(ROOT))
        return out.stdout.strip()

    def test_helper_behaviour(self):
        inc = "require 'admin/backend/danger_confirm.php';"
        self.assertEqual(self.run_php(inc + "echo dc_phrase_ok('timetable.remove_day',['confirm_phrase'=>'  remove   day ']) ? 'Y':'N';"), 'Y')
        self.assertEqual(self.run_php(inc + "echo dc_phrase_ok('timetable.remove_day',['confirm_phrase'=>'REMOVE']) ? 'Y':'N';"), 'N')
        self.assertEqual(self.run_php(inc + "echo dc_phrase_ok('timetable.remove_day',[]) ? 'Y':'N';"), 'N')
        self.assertEqual(self.run_php(inc + "echo dc_phrase_ok('nope.key',['confirm_phrase'=>'X']) ? 'Y':'N';"), 'N')
        body = self.run_php(inc + "$_POST=[]; dc_require_phrase('education.delete_class'); echo 'NOT-REACHED';")
        self.assertNotIn('NOT-REACHED', body)
        self.assertIn('confirm_phrase_required', body)
        self.assertIn('DELETE CLASS', body)

    def test_array_phrase_is_refused_not_a_type_error(self):
        # Audit 2026-10-22: confirm_phrase[]=... must give a clean refusal (409),
        # never a PHP TypeError (500).
        inc = "require 'admin/backend/danger_confirm.php';"
        out = self.run_php(inc + "try { echo dc_phrase_ok('groups.delete_group',['confirm_phrase'=>['DELETE GROUP']]) ? 'Y':'N'; } catch (Throwable $e) { echo 'ERR'; }")
        self.assertEqual(out, 'N')

    def test_php_lint(self):
        for rel in ['admin/backend/danger_confirm.php'] + sorted({r for r, _ in SERVER_ENFORCED}):
            r = subprocess.run(['php', '-l', rel], capture_output=True, text=True, cwd=str(ROOT))
            self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


if __name__ == '__main__':
    unittest.main()
