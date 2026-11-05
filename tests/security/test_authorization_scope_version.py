"""Static contract tests for migration 048 and live authorization scope.

Runtime trigger/API integration is a staging MariaDB gate; these tests ensure the
reviewed source keeps the additive schema, token claims, compatibility window,
and fail-closed response contract wired together.
"""
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]


class AuthorizationScopeVersionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = (ROOT / "sql/048_user_authorization_scope.sql").read_text(
            encoding="utf-8"
        )
        cls.schema = (ROOT / "sql/legacy/database_schema.sql").read_text(encoding="utf-8")
        cls.auth = (ROOT / "api/v1/core/auth.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/auth.php").read_text(encoding="utf-8")
        cls.refresh = (
            ROOT / "admin/backend/services/RefreshTokenService.php"
        ).read_text(encoding="utf-8")
        cls.env_example = (ROOT / "env.example.php").read_text(encoding="utf-8")

    def test_migration_and_canonical_schema_add_monotonic_column(self):
        definition = (
            "`authorization_version` bigint(20) UNSIGNED NOT NULL DEFAULT 1"
        )
        self.assertIn(definition, self.schema)
        self.assertRegex(
            self.migration,
            r"ADD COLUMN IF NOT EXISTS `authorization_version`\s+"
            r"BIGINT UNSIGNED NOT NULL DEFAULT 1",
        )
        self.assertIn("SET `authorization_version` = 1", self.migration)
        self.assertNotRegex(
            self.migration,
            r"(?i)\b(?:DELETE\s+FROM|TRUNCATE\s+(?:TABLE\s+)?|DROP\s+TABLE)\b",
        )

    def test_migration_fails_closed_when_assignment_hardening_is_missing(self):
        self.assertIn("ssms_require_authorization_scope_prereqs", self.migration)
        self.assertIn("information_schema.COLUMNS", self.migration)
        for column in (
            "teacher_id",
            "class_id",
            "subject_id",
            "academic_year_id",
            "is_active",
            "is_primary",
            "is_class_teacher",
            "assignment_role",
        ):
            self.assertIn(f"'{column}'", self.migration)
        self.assertIn("SIGNAL SQLSTATE '45000'", self.migration)
        self.assertIn("assignment hardening migration 006", self.migration)

    def test_trigger_set_is_recreated_repeat_safely(self):
        names = (
            "trg_users_authorization_bu",
            "trg_teacher_assignments_authorization_ai",
            "trg_teacher_assignments_authorization_au",
            "trg_teacher_assignments_authorization_ad",
        )
        for name in names:
            self.assertIn(f"DROP TRIGGER IF EXISTS `{name}`", self.migration)
            self.assertEqual(self.migration.count(f"CREATE TRIGGER `{name}`"), 1)
        self.assertIn("DELIMITER $$", self.migration)
        self.assertTrue(self.migration.rstrip().endswith("DELIMITER ;"))

    def test_user_trigger_advances_only_role_or_active_scope_automatically(self):
        body = self.migration[
            self.migration.index("CREATE TRIGGER `trg_users_authorization_bu`") :
            self.migration.index(
                "DROP TRIGGER IF EXISTS `trg_teacher_assignments_authorization_ai`"
            )
        ]
        self.assertIn("OLD.`role` <=> NEW.`role`", body)
        self.assertIn("OLD.`is_active` <=> NEW.`is_active`", body)
        self.assertIn("OLD.`authorization_version` + 1", body)
        self.assertIn("GREATEST", body)
        # Assignment-trigger version updates do not cause a second automatic
        # bump when role/active are unchanged.
        self.assertIn("OLD.`authorization_version`", body)
        self.assertIn("NEW.`authorization_version`", body)

    def test_assignment_triggers_cover_insert_delete_and_scope_changes(self):
        self.assertRegex(
            self.migration,
            r"AFTER INSERT ON `teacher_assignments`[\s\S]*?"
            r"WHERE `id` = NEW\.`teacher_id`",
        )
        self.assertRegex(
            self.migration,
            r"AFTER DELETE ON `teacher_assignments`[\s\S]*?"
            r"WHERE `id` = OLD\.`teacher_id`",
        )
        update_start = self.migration.index(
            "CREATE TRIGGER `trg_teacher_assignments_authorization_au`"
        )
        update_end = self.migration.index(
            "DROP TRIGGER IF EXISTS `trg_teacher_assignments_authorization_ad`"
        )
        update = self.migration[update_start:update_end]
        for column in (
            "teacher_id",
            "class_id",
            "subject_id",
            "academic_year_id",
            "is_active",
            "is_primary",
            "is_class_teacher",
            "assignment_role",
        ):
            self.assertIn(f"OLD.`{column}` <=> NEW.`{column}`", update)
        self.assertIn("WHERE `id` IN (OLD.`teacher_id`, NEW.`teacher_id`)", update)

    def test_access_and_refresh_tokens_carry_authorization_version(self):
        self.assertGreaterEqual(
            self.auth.count("'av' => max(1, (int)$authorizationVersion)"), 2
        )
        self.assertIn("function createToken(", self.auth)
        self.assertIn("function createRefreshToken(", self.auth)
        self.assertIn("$authorizationVersion", self.auth)

    def test_login_reads_and_returns_current_version(self):
        self.assertRegex(
            self.route,
            r"SELECT id, username, email, full_name, role, password_hash, "
            r"is_active, authorization_version\s+FROM users",
        )
        self.assertIn("$user['authorization_version']", self.route)
        self.assertIn("'authorization_version' => max(1", self.route)

    def test_refresh_reloads_version_under_lock_and_returns_safe_user(self):
        self.assertIn(
            "SELECT id, username, full_name, role, is_active, authorization_version FROM users",
            self.refresh,
        )
        self.assertIn("LIMIT 1 FOR UPDATE", self.refresh)
        self.assertIn("$user['authorization_version']", self.refresh)
        refresh_block = self.route[self.route.index("$action === 'refresh-token'") :]
        self.assertIn("'user' => [", refresh_block)
        self.assertIn("'authorization_version' => max(1", refresh_block)

    def test_capable_requests_revalidate_authoritative_scope_by_primary_key(self):
        self.assertIn("function apiRevalidateAuthorizationScope", self.auth)
        self.assertIn(
            "SELECT role, is_active, authorization_version, username, full_name",
            self.auth,
        )
        self.assertIn("return apiRevalidateAuthorizationScope($payload)", self.auth)
        self.assertIn("PROFILE_CLAIMS_CHANGED", self.auth)
        self.assertIn("claims_refresh_required", self.auth)
        self.assertIn("hash_equals($currentRole", self.auth)
        self.assertIn("$currentVersion !== (int)$payload['av']", self.auth)
        self.assertNotIn("$payload['rol'] = $currentRole", self.auth)
        self.assertNotIn("$payload['av'] = $currentVersion", self.auth)

    def test_live_revalidation_has_typed_fail_closed_outcomes(self):
        for code in (
            "ACCOUNT_REMOVED",
            "ACCOUNT_DISABLED",
            "AUTH_SCOPE_REFRESH_REQUIRED",
            "AUTH_SCOPE_CHANGED",
            "AUTH_REVALIDATION_UNAVAILABLE",
        ):
            self.assertIn(f"'code' => '{code}'", self.auth)
        self.assertRegex(
            self.auth,
            r"Authorization could not be revalidated[\s\S]*?503[\s\S]*?"
            r"AUTH_REVALIDATION_UNAVAILABLE",
        )
        for code in (
            "ACCOUNT_REMOVED",
            "ACCOUNT_DISABLED",
            "AUTH_SCOPE_REFRESH_REQUIRED",
            "AUTH_SCOPE_CHANGED",
        ):
            self.assertRegex(
                self.auth,
                rf"err\([^;]*401,[\s\S]*?'code' => '{code}'",
            )

    def test_build_24_gate_and_deployment_deadline_are_explicit(self):
        self.assertIn("define('API_AUTHZ_SCOPE_CLIENT_BUILD', 24)", self.auth)
        self.assertIn("API_AUTHZ_LEGACY_COMPAT_UNTIL", self.auth)
        self.assertIn("PHP_INT_MAX", self.auth)
        self.assertIn("HTTP_X_APP_BUILD", self.auth)
        self.assertRegex(
            self.auth,
            r"\$build >= API_AUTHZ_SCOPE_CLIENT_BUILD\s*\|\|\s*"
            r"time\(\) > \(int\)API_AUTHZ_LEGACY_COMPAT_UNTIL",
        )
        self.assertIn("define('API_AUTHZ_LEGACY_COMPAT_UNTIL', 1)", self.env_example)
        self.assertIn("spoofed old X-App-Build", self.env_example)

    def test_legacy_compatibility_short_circuits_before_database_read(self):
        method = self.auth[
            self.auth.index("function apiRevalidateAuthorizationScope") :
            self.auth.index("/**\n * Authenticate the current request")
        ]
        self.assertIn("bool $requireCurrentScope = false", method)
        compatibility = method.index("if (!$requireCurrentScope && !apiAuthorizationScopeEnforcedForClient())")
        query = method.index("SELECT role, is_active, authorization_version")
        self.assertLess(compatibility, query)
        self.assertIn("return $payload;", method[compatibility:query])


if __name__ == "__main__":
    unittest.main()
