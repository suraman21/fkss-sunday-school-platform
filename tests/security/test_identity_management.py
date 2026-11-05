"""Regression tests for the Identity & Codes management system (v2).

Guards the approved ANALYSIS/08 contract:
  V1  codes are {PREFIX}-{random unique 5-digit tail}; students A-76392,
      staff DEDHT-98798; free positions lead the prefix (D first);
  V2  one central parser (parse/isStudentCode/isStaffCode) — no ad-hoc
      strpos/REGEXP shape heuristics anywhere;
  V3  positions may be free (NULL department); reserved letters guarded;
  V4  Super Admin UI: no member search/editor; single-flight buttons;
  V5  registration & member edit use the position picker (no hard-coded
      is_* checkboxes); legacy flags derived (strangler pattern);
  V6  edu-dept teacher flows converge flags ⇄ positions ⇄ codes;
  V7  migration engine shared by CLI + web runner, idempotent;
  V8  under6 remains fully removed; sql/019 & sql/020 idempotent;
  V9  strict-mode (PHP >= 8.1 mysqli throws) safety preserved.
"""

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PHP = shutil.which("php")


class IdentityManagementV2Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        def rd(rel):
            return (ROOT / rel).read_text(encoding="utf-8")
        cls.api_identity = rd("admin/api_identity.php")
        cls.api_migration = rd("admin/api_identity_migration.php")
        cls.cli_tool = rd("admin/tools/migrate_identity_codes.php")
        cls.engine = rd("admin/backend/services/IdentityMigrationService.php")
        cls.identity = rd("admin/backend/services/IdentityCodeService.php")
        cls.sync = rd("admin/backend/services/PositionSyncService.php")
        cls.code_format = rd("admin/backend/services/MemberCodeFormat.php")
        cls.category = rd("admin/backend/services/MemberCategory.php")
        cls.type_service = rd("admin/backend/services/MemberTypeService.php")
        cls.sql_019 = rd("sql/019_member_types_and_under6_removal.sql")
        cls.sql_020 = rd("sql/020_identity_v2.sql")
        cls.schema_baseline = rd("sql/legacy/database_schema.sql")
        cls.super_admin = rd("admin/dashboards/super-admin.php")
        cls.js = rd("admin/js/super_admin.js")
        cls.section = rd("admin/dashboards/sections/identity_codes_section.php")
        cls.hr_dept = rd("admin/dashboards/hr-dept.php")
        cls.register = rd("admin/hr_register_member.php")
        cls.edit_member = rd("admin/info_manage_member.php")
        cls.member_sync = rd("admin/backend/member_sync.php")
        cls.workflow = rd("admin/backend/workflow.php")
        cls.member_page = rd("member.php")
        cls.print_member = rd("admin/print_member.php")
        cls.card_template = rd("admin/id_cards/id_card_template_layout.php")

    # ── V1: format contract ─────────────────────────────────────────
    def test_engine_defines_v2_regexes_and_random_tails(self):
        self.assertIn("STUDENT_REGEX = '/^[ABC]-[1-9][0-9]{4}$/'", self.identity)
        self.assertIn("TAIL_MIN = 10000", self.identity)
        self.assertIn("random_int(self::TAIL_MIN, self::TAIL_MAX)", self.identity)
        self.assertNotIn("GET_LOCK", self.identity)
        self.assertNotIn("member_code_sequences", self.identity)

    def test_no_sequential_student_allocation_anywhere(self):
        for name, src in (("register", self.register), ("identity api", self.api_identity)):
            with self.subTest(file=name):
                self.assertNotIn("allocateStudentHeld", src)
                self.assertNotIn("SUBSTRING(member_code, 2)", src)

    def test_compose_prefix_order_free_first_then_dept(self):
        if not PHP:
            self.skipTest("php CLI not available")
        script = (
            "require '{r}/admin/backend/services/IdentityCodeService.php';"
            "use App\\Services\\IdentityCodeService;"
            "echo IdentityCodeService::composePrefix(['D'],'ED','H',['T']), '|';"
            "echo IdentityCodeService::composePrefix(['D'],null,null,['T']), '|';"
            "echo IdentityCodeService::composePrefix([],'ED','N',['T']), '|';"
            "$p = IdentityCodeService::parse('A-76392');"
            "echo $p['kind'], ':', $p['letter'], '|';"
            "$q = IdentityCodeService::parse('DEDHT-98798');"
            "echo $q['kind'], ':', $q['prefix'], '|';"
            "var_dump(IdentityCodeService::parse('A12') === null);"
        ).format(r=ROOT)
        result = subprocess.run([PHP, "-r", script], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(
            result.stdout,
            "DEDHT|DT|EDNT|student:A|staff:DEDHT|bool(true)\n",
        )

    # ── V2: central parser only ─────────────────────────────────────
    def test_no_adhoc_shape_heuristics(self):
        for name, src in (
            ("api_identity", self.api_identity),
            ("web runner", self.api_migration),
            ("cli runner", self.cli_tool),
            ("MemberCodeFormat", self.code_format),
        ):
            with self.subTest(file=name):
                self.assertNotIn("strpos($old, '-')", src)
                self.assertNotIn("REGEXP CONCAT", src)

    def test_member_code_format_uses_parser(self):
        self.assertIn("IdentityCodeService::parse", self.code_format)
        self.assertIn("font-size:0.72em", self.code_format)

    # ── V3: free positions ──────────────────────────────────────────
    def test_sql_020_makes_department_optional_and_adds_legacy_flag(self):
        self.assertIn("MODIFY `department_id` INT NULL", self.sql_020)
        self.assertIn("legacy_flag", self.sql_020)
        self.assertIn("information_schema", self.sql_020.lower())

    def test_api_allows_free_positions_and_reserved_letters(self):
        self.assertIn("?: null", self.api_identity)
        self.assertIn("RESERVED_FREE_CODES", self.api_identity)
        self.assertIn("const RESERVED_FREE_CODES = ['N', 'A', 'B', 'C']", self.identity)

    # ── V4: super admin UI ──────────────────────────────────────────
    def test_section_has_no_member_editor(self):
        self.assertNotIn("idc-pane-members", self.section)
        self.assertNotIn("identity_search", self.section)
        self.assertNotIn("identity_search", self.api_identity)
        self.assertNotIn("update_member_identity", self.api_identity)

    def test_section_single_flight_and_modal_ux(self):
        self.assertIn("async function busy(", self.section)
        self.assertIn("aria-busy", self.section)
        self.assertIn("idcModalWrap", self.section)
        self.assertNotIn("confirm(", self.section)
        self.assertNotIn("prompt(", self.section)
        # Typed confirmation for renumbering: the old "type RENUMBER" modal was
        # replaced by the shared 3-step danger dialog (DC-3), and the phrase is now
        # also checked on the server (api_identity_migration.php, identity.renumber).
        self.assertRegex(self.section, r"phrase:\s*'RENUMBER CODES'")
        self.assertIn("DangerConfirm", self.section)

    def test_shell_js_allowlist_covers_php_sections(self):
        match = re.search(r"var ALLOWED = \{([^}]*)\}", self.js)
        js_sections = set(re.findall(r"(\w+):\s*1", match.group(1)))
        php_match = re.search(r"\$saAllowedSections = \[([^\]]*)\]", self.super_admin)
        php_sections = set(re.findall(r"'(\w+)'", php_match.group(1)))
        self.assertFalse(php_sections - js_sections)

    # ── V5: position-driven forms ───────────────────────────────────
    def test_registration_form_uses_position_picker(self):
        self.assertIn('name="position_ids[]"', self.hr_dept)
        self.assertNotIn("roleFlagsSection", self.hr_dept)
        self.assertNotIn("is_dept_head_1", self.hr_dept)
        self.assertIn("PositionSyncService::catalogue", self.hr_dept)

    def test_registration_backend_applies_positions(self):
        self.assertIn("PositionSyncService::applyPositions", self.register)
        self.assertIn("$position_ids", self.register)
        self.assertNotIn("isset($_POST['is_teacher']", self.register)

    def test_member_edit_form_uses_picker_and_derives_flags(self):
        self.assertIn('name="position_ids[]"', self.edit_member)
        self.assertIn("PositionSyncService::applyPositions", self.edit_member)
        self.assertNotIn("name=\"is_teacher\"", self.edit_member)
        self.assertNotIn("is_teacher=?", self.edit_member)

    def test_flags_are_derived_from_positions(self):
        self.assertIn("function deriveFlags", self.sync)
        self.assertIn("legacy_flag", self.sync)
        self.assertIn("self::deriveFlags($conn, $memberId)", self.sync)
        self.assertNotIn("syncMemberType", self.sync)

    # ── V6: edu-dept convergence ────────────────────────────────────
    def test_teacher_flows_converge_positions(self):
        self.assertIn("syncPositionFromFlag", self.member_sync)
        self.assertIn("syncPositionFromFlag", self.workflow)
        self.assertIn("function syncPositionFromFlag", self.sync)

    # ── V7: shared idempotent migration engine ──────────────────────
    def test_runners_share_engine(self):
        self.assertIn("IdentityMigrationService::renumberAll", self.api_migration)
        self.assertIn("IdentityMigrationService::renumberAll", self.cli_tool)
        self.assertIn("function renumberAll", self.engine)
        # idempotency: already-correct prefixes are skipped
        self.assertIn("=== $expected", self.engine)
        self.assertIn("legacy_member_code", self.engine)

    # ── V8: under6 still gone ───────────────────────────────────────
    def test_under6_still_removed(self):
        self.assertNotIn("=== 'under6'", self.category)
        self.assertNotIn("'under6'", self.schema_baseline)
        self.assertIn("under6", self.sql_019)

    # ── progressive rollout: code works before sql/020 runs ────────
    def test_legacy_flag_sql_is_feature_detected(self):
        # Every statement touching the 020 column must be guarded by the
        # schema probe, otherwise pre-migration deployments throw 1054.
        self.assertIn("function hasLegacyFlag", self.sync)
        self.assertIn("if (!self::hasLegacyFlag($conn))", self.sync)
        self.assertIn("PositionSyncService::hasLegacyFlag($conn)", self.api_identity)
        self.assertIn("NULL AS legacy_flag", self.api_identity)

    def test_free_position_save_gated_with_clear_message_pre_020(self):
        self.assertIn("function departmentNullable", self.sync)
        self.assertIn("require sql/020", self.api_identity)

    def test_ui_surfaces_list_errors_and_loads_in_parallel(self):
        self.assertIn("tableError(", self.section)
        self.assertIn("if (!loaded.departments) renderDepartments();", self.section)
        self.assertIn("loadTab(idcActiveTab.dataset.idctab)", self.section)

    def test_tab_navigation_reactivates_target_pane(self):
        # Regression: a refactor once deactivated all panes without
        # re-activating the clicked one, blanking the section body on
        # every in-section navigation.
        self.assertIn(
            "$('idc-pane-' + btn.dataset.idctab).classList.add('active');",
            self.section,
        )

    # ── V9: strict-mode safety preserved ────────────────────────────
    def test_save_actions_handle_thrown_duplicate_keys(self):
        for needle in ("mysqli_sql_exception", "1062"):
            self.assertIn(needle, self.api_identity)
        self.assertNotIn("ASC NULLS FIRST", self.api_identity)

    def test_member_type_save_tolerates_missing_table(self):
        self.assertIn("catch (\\Throwable $error)", self.type_service)

    # ── display surfaces ────────────────────────────────────────────
    def test_display_surfaces_adopt_v2_format(self):
        self.assertIn("MemberCodeFormat::html", self.card_template)
        self.assertIn("MemberCodeFormat::html", self.member_page)
        self.assertIn("MemberCodeFormat::html", self.print_member)

    # ── lint the touched files ──────────────────────────────────────
    def test_php_syntax(self):
        if not PHP:
            self.skipTest("php CLI not available")
        files = [
            "admin/api_identity.php",
            "admin/api_identity_migration.php",
            "admin/tools/migrate_identity_codes.php",
            "admin/backend/services/IdentityCodeService.php",
            "admin/backend/services/IdentityMigrationService.php",
            "admin/backend/services/PositionSyncService.php",
            "admin/backend/services/MemberCodeFormat.php",
            "admin/backend/services/MemberTypeService.php",
            "admin/backend/member_sync.php",
            "admin/backend/workflow.php",
            "admin/dashboards/super-admin.php",
            "admin/dashboards/sections/identity_codes_section.php",
            "admin/dashboards/hr-dept.php",
            "admin/hr_register_member.php",
            "admin/info_manage_member.php",
            "member.php",
            "admin/print_member.php",
            "admin/id_cards/id_card_template_layout.php",
        ]
        for rel in files:
            with self.subTest(file=rel):
                result = subprocess.run([PHP, "-l", str(ROOT / rel)], capture_output=True, text=True)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
