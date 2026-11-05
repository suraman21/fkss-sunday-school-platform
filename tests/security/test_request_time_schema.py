"""Regression checks for deployment-owned application schema."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]

# These are explicit maintenance/export programs, not normal request bootstrap.
DDL_TEXT_ALLOWLIST = {
    # This domain service serializes schema into an operator-requested backup;
    # it does not execute CREATE/ALTER statements against the live database.
    "admin/backend/services/BackupService.php",
    # Explicit admin-triggered maintenance endpoint (POST + CSRF + role gate
    # + write rate limit) whose DDL is built only from class constants (no
    # user input). It exists to close the schema drift that repeatedly broke
    # production (legacy tables never upgraded by CREATE TABLE IF NOT EXISTS,
    # migrations lagging the cron code pull). Not a normal request path.
    "admin/backend/services/MezmurSchemaReconciler.php",
}


# Read-only metadata text, NOT a whole-file DDL exemption. Keep every other
# CREATE/ALTER occurrence in this service subject to the normal guard. The
# result key and regex inspect a definition; neither is an executable command.
PREPARATION_METADATA_READER = "admin/backend/services/AttendancePreparationReadBoundary.php"
PREPARATION_METADATA_TEXT = (
    "SHOW CREATE TABLE",
    "['Create Table']",
    "'/^CREATE TABLE\\s/Di'",
)


def contains_runtime_ddl_text(source, relative):
    if relative == PREPARATION_METADATA_READER:
        for literal in PREPARATION_METADATA_TEXT:
            source = source.replace(literal, "READ_ONLY_METADATA_TEXT")
    source = source.upper()
    return "CREATE TABLE" in source or "ALTER TABLE" in source


class RequestTimeSchemaTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = (
            ROOT / "sql/013_application_schema_completion.sql"
        ).read_text(encoding="utf-8")

    def test_normal_application_paths_do_not_execute_ddl(self):
        offenders = []
        for path in ROOT.rglob("*.php"):
            relative = path.relative_to(ROOT).as_posix()
            if (
                relative.startswith("vendor/")
                or relative.startswith("admin/vendor/")
                # Archived legacy PHP migrations (RFC 0001 step 2b).
                or relative.startswith("sql/legacy/php-migrations/")
                # Test tooling builds throwaway fixture schemas (e.g. the
                # communication e2e runner) — never a production request path.
                or relative.startswith("tests/")
                or relative in DDL_TEXT_ALLOWLIST
            ):
                continue
            source = path.read_text(encoding="utf-8", errors="replace")
            if contains_runtime_ddl_text(source, relative):
                offenders.append(relative)
        self.assertEqual([], offenders)

    def test_metadata_reader_exception_cannot_hide_runtime_ddl(self):
        source = (ROOT / PREPARATION_METADATA_READER).read_text(encoding="utf-8")
        self.assertFalse(contains_runtime_ddl_text(source, PREPARATION_METADATA_READER))
        for statement in (
            "$conn->query('CREATE TABLE injected (id INT)');",
            "$conn->query('ALTER TABLE injected ADD n INT');",
            "$conn->query('SHOW CREATE TABLE known; CREATE TABLE injected (id INT)');",
        ):
            self.assertTrue(contains_runtime_ddl_text(source + statement, PREPARATION_METADATA_READER))
        self.assertTrue(contains_runtime_ddl_text(source, "unrelated.php"))

    def test_hot_services_keep_contract_hooks_without_schema_queries(self):
        services = [
            "admin/backend/services/AssignmentService.php",
            "admin/backend/services/SubmissionService.php",
            "admin/backend/services/TimetableService.php",
            "admin/backend/services/GalleryService.php",
            "admin/backend/services/IdCardLayout.php",
            "admin/backend/services/EnrollmentService.php",
        ]
        for relative in services:
            source = (ROOT / relative).read_text(encoding="utf-8")
            for statement in ("CREATE TABLE", "ALTER TABLE", "SHOW TABLES", "SHOW COLUMNS", "SHOW INDEX"):
                self.assertNotIn(statement, source, relative)

    def test_completion_migration_owns_removed_runtime_tables(self):
        for table in (
            "system_settings",
            "ai_chat_history",
            "ai_provider_configs",
            "dept_settings",
            "academic_terms",
            "class_enrollments",
            "wbws_groups",
            "wbws_group_leaders",
            "wbws_group_members",
            "wbws_audit_log",
            "academic_records",
            "attendance",
            "attendance_summary",
            "grade_submissions",
        ):
            self.assertIn(f"CREATE TABLE IF NOT EXISTS `{table}`", self.migration)

    def test_legacy_debug_schema_endpoint_is_removed(self):
        self.assertFalse((ROOT / "admin/scratch_get_cols.php").exists())
        groups = (ROOT / "admin/backend/groups_api.php").read_text(encoding="utf-8")
        self.assertNotIn("action === 'diagnose'", groups)
        self.assertNotIn("csrf_token_preview", groups)
        self.assertNotIn("session_id()", groups)

    def test_archive_and_branding_compatibility_are_migrated(self):
        for column in (
            "archived_at",
            "archived_by",
            "archive_reason",
            "archive_notes",
            "archive_type",
            "restored_at",
            "restored_by",
            "thumb_path",
            "assessment_id",
            "submission_id",
        ):
            self.assertIn(f"'{column}'", self.migration)
        self.assertIn("'card_bg', 'ID Card Background'", self.migration)


if __name__ == "__main__":
    unittest.main()
