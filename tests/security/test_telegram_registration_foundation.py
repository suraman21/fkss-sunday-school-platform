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
