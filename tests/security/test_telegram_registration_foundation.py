"""Static safety contracts for review-only Telegram registration."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]


class TelegramRegistrationFoundationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.webhook = (ROOT / "api/telegram-webhook.php").read_text(encoding="utf-8")
        cls.service = (ROOT / "admin/backend/services/TelegramRegistrationService.php").read_text(encoding="utf-8")
        cls.duplicate = (ROOT / "admin/backend/services/TelegramDuplicateCandidateService.php").read_text(encoding="utf-8")
        cls.migration = (ROOT / "sql/080_telegram_registration_foundation.sql").read_text(encoding="utf-8")

    def test_feature_is_fail_closed(self):
        school = (ROOT / "school_config.php").read_text(encoding="utf-8")
        gate = (ROOT / "admin/backend/services/FeatureGate.php").read_text(encoding="utf-8")
        self.assertIn("'telegram_registration' => 'FEATURE_TELEGRAM_REGISTRATION'", gate)
        self.assertRegex(school, r"FEATURE_TELEGRAM_REGISTRATION'\)\) define\('FEATURE_TELEGRAM_REGISTRATION', false\)")

    def test_webhook_authenticates_before_reading_body(self):
        secret = self.webhook.index("hash_equals((string)TELEGRAM_WEBHOOK_SECRET")
        body = self.webhook.index("file_get_contents('php://input'")
        self.assertLess(secret, body)
        self.assertIn("HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN", self.webhook)
        self.assertIn("TELEGRAM_WEBHOOK_MAX_BYTES", self.webhook)

    def test_webhook_is_idempotent_and_private_chat_only(self):
        self.assertIn("INSERT IGNORE INTO telegram_processed_updates", self.webhook)
        self.assertIn("['chat']['type']??'')!=='private'", self.webhook)
        self.assertIn("JSON_BIGINT_AS_STRING", self.webhook)

    def test_authorization_uses_numeric_id_and_linked_active_user(self):
        self.assertIn("WHERE tb.telegram_user_id=?", self.service)
        self.assertIn("empty($actor['linked_user_id'])", self.service)
        self.assertIn("empty($actor['system_user_active'])", self.service)
        self.assertNotIn("WHERE tb.username", self.service)

    def test_submission_never_inserts_member(self):
        submit = self.service[self.service.index("private function submitReview"):]
        self.assertIn("status='submitted'", submit)
        self.assertNotRegex(submit, r"INSERT\s+INTO\s+members")
        self.assertNotRegex(self.webhook, r"INSERT\s+INTO\s+members")

    def test_duplicate_lookup_is_bounded_and_index_blocked(self):
        self.assertIn("$candidateLimit = 50", self.duplicate)
        self.assertIn("min(10, $displayLimit)", self.duplicate)
        self.assertNotIn("LIKE '%", self.duplicate)
        self.assertIn("name_pair_hash=?", self.duplicate)
        self.assertIn("phone_suffix9=?", self.duplicate)
        self.assertIn("dob_ec_year=?", self.duplicate)

    def test_secrets_are_not_in_schema_or_source_defaults(self):
        combined = self.migration + self.service + self.webhook
        self.assertNotRegex(combined, r"(?i)CREATE TABLE[^;]+bot_token")
        self.assertNotRegex(combined, r"(?i)CREATE TABLE[^;]+webhook_secret")
        self.assertIn("defined('TELEGRAM_BOT_TOKEN')", self.webhook)

    def test_member_canonical_keys_have_bounded_backfill_and_write_hook(self):
        canonical = (ROOT / "admin/backend/services/MemberCanonicalIdentityService.php").read_text(encoding="utf-8")
        registration = (ROOT / "admin/hr_register_member.php").read_text(encoding="utf-8")
        self.assertIn("max(1,min(500,$limit))", canonical)
        self.assertIn("name_norm_version IS NULL OR name_norm_version<>", canonical)
        self.assertIn("ORDER BY id LIMIT {$limit}", canonical)
        self.assertIn("MemberCanonicalIdentityService::refresh($conn, (int)$newId)", registration)
        self.assertIn("phone_number", canonical)
        self.assertNotIn("UPDATE members SET student_name=", canonical)

    def test_admin_api_is_role_and_csrf_protected(self):
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("['super_admin','school_admin','hr_dept']", api)
        self.assertIn("validateCsrf", api)
        self.assertIn("Only administrators may manage operators.", api)
        self.assertIn("Only super administrators may run this operation.", api)
        self.assertIn("role IN ('super_admin','school_admin','hr_dept')", api)
        self.assertNotIn("TELEGRAM_BOT_TOKEN]", api)
        self.assertNotIn("TELEGRAM_WEBHOOK_SECRET]", api)
        for action in ["overview", "operators", "forms", "sessions", "audit", "operator_save", "canonical_backfill"]:
            self.assertIn(f"case '{action}'", api)

    def test_admin_console_exposes_safe_phase_one_surfaces(self):
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("['super_admin','school_admin','hr_dept']", page)
        self.assertIn("generateCsrfToken()", page)
        self.assertIn("credentials:'same-origin'", page)
        for label in ["Overview", "Operators", "Forms", "Sessions & reviews", "Audit"]:
            self.assertIn(label, page)
        self.assertIn("Phase 1 does not create members", page)
        self.assertNotIn("TELEGRAM_BOT_TOKEN", page)
        self.assertNotIn("TELEGRAM_WEBHOOK_SECRET", page)
        self.assertNotIn("approve member", page.lower())

    def test_form_builder_versions_and_validates_closed_registry(self):
        service = (ROOT / "admin/backend/services/TelegramFormAdminService.php").read_text(encoding="utf-8")
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("TelegramFormRegistry::exists($key)", service)
        self.assertIn("Published or retired versions are immutable.", service)
        self.assertIn("Full name and gender must be enabled and required.", service)
        self.assertIn("FOR UPDATE", service)
        self.assertIn("begin_transaction()", service)
        for action in ["form_registry", "form_detail", "form_create", "form_save", "form_publish"]:
            self.assertIn(f"case '{action}'", api)
        self.assertIn("fields_json", api)
        self.assertIn("New waiting draft", page)
        self.assertIn("New direct draft", page)
        self.assertIn("Publish this form version?", page)
        self.assertIn("DangerConfirm.ask", page)

    def test_review_ui_has_no_approval_or_member_creation_path(self):
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("case 'session_detail'", api)
        self.assertIn("case 'review_decide'", api)
        self.assertIn("['rejected','changes_requested','duplicate']", api)
        self.assertIn("Approval is unavailable in review-only phase.", api)
        self.assertIn("FOR UPDATE", api)
        self.assertIn("candidate_cap'=>10", api)
        self.assertIn("Request changes", page)
        self.assertIn("Mark duplicate", page)
        self.assertNotIn('value="approved"', page)
        review_case = api[api.index("case 'review_decide'"):api.index("case 'audit'")]
        self.assertNotRegex(review_case, r"INSERT\s+INTO\s+members")

    def test_runtime_is_serialized_limited_and_retained_in_batches(self):
        registration = (ROOT / "admin/backend/services/TelegramRegistrationService.php").read_text(encoding="utf-8")
        retention = (ROOT / "admin/backend/services/TelegramRetentionService.php").read_text(encoding="utf-8")
        cron = (ROOT / "admin/backend/cron_telegram_retention.php").read_text(encoding="utf-8")
        self.assertIn("SELECT GET_LOCK(?,1)", registration)
        self.assertIn("SELECT RELEASE_LOCK(?)", registration)
        self.assertIn("daily_registration_limit", registration)
        self.assertIn("created_at>=CURRENT_DATE", registration)
        self.assertIn("status='expired'", registration)
        self.assertIn("max(10,min(2000,$batch))", retention)
        self.assertIn("LIMIT {$batch}", retention)
        self.assertIn("TELEGRAM_PROCESSED_UPDATE_RETENTION_DAYS", retention)
        self.assertIn("TELEGRAM_CANCELLED_SESSION_RETENTION_DAYS", retention)
        self.assertIn("TELEGRAM_AUDIT_RETENTION_DAYS", retention)
        self.assertIn("PHP_SAPI!=='cli'", cron)
        self.assertNotIn("TELEGRAM_BOT_TOKEN", retention + cron)

    def test_authorized_dashboards_link_the_console(self):
        for path in ["admin/dashboards/super-admin.php", "admin/dashboards/school_admin.php", "admin/dashboards/hr-dept.php"]:
            page = (ROOT / path).read_text(encoding="utf-8")
            self.assertIn('/admin/telegram_registration.php', page, path)
            self.assertIn('Telegram Registration', page, path)

    def test_schema_has_review_and_audit_boundaries(self):
        for table in [
            "telegram_bot_users", "telegram_form_profiles", "telegram_form_fields",
            "telegram_registration_sessions", "telegram_registration_answers",
            "telegram_processed_updates", "telegram_registration_reviews", "telegram_audit_log",
        ]:
            self.assertIn(f"CREATE TABLE IF NOT EXISTS `{table}`", self.migration)
        self.assertIn("Phase 1 is review-only", self.migration)


if __name__ == "__main__":
    unittest.main()
