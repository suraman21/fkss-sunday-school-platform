"""Static safety contracts for review-only Telegram registration."""
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class TelegramRegistrationFoundationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.webhook = (ROOT / "api/telegram-webhook.php").read_text(encoding="utf-8")
        cls.service = (ROOT / "admin/backend/services/TelegramRegistrationService.php").read_text(encoding="utf-8")
        cls.duplicate = (ROOT / "admin/backend/services/TelegramDuplicateCandidateService.php").read_text(encoding="utf-8")
        cls.registry = (ROOT / "admin/backend/services/TelegramFormRegistry.php").read_text(encoding="utf-8")
        cls.migration = (ROOT / "sql/080_telegram_registration_foundation.sql").read_text(encoding="utf-8")
        cls.phase2_migration = (ROOT / "sql/081_telegram_registration_phase2_interview.sql").read_text(encoding="utf-8")
        cls.correction_migration = (ROOT / "sql/082_telegram_registration_corrections.sql").read_text(encoding="utf-8")

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
        self.assertNotIn("system_role'], ['super_admin'", self.service)

    def test_phase_two_interview_can_resume_without_destroying_draft(self):
        self.assertIn("$text === '/resume'", self.service)
        self.assertIn("private function resume(", self.service)
        self.assertIn("private function editAnswer(", self.service)
        self.assertIn("private function editAnswer(", self.service)
        begin = self.service[self.service.index("private function begin"):self.service.index("private function profileChoice")]
        self.assertNotIn("cancelActive", begin)
        self.assertIn("ያልተጠናቀቀ ምዝገባ አለ", begin)
        self.assertIn("edit_return_to_review=0", self.service)
        self.assertIn("TelegramFormRegistry::label($key)", self.service)
        self.assertIn("edit_return_to_review", self.phase2_migration)
        self.assertIn("information_schema.columns", self.phase2_migration)

    def test_controlled_fields_use_allowlists_and_bounded_active_sections(self):
        self.assertIn("TelegramFormRegistry::choices", self.service)
        self.assertIn("WHERE is_active=1 ORDER BY level_order,class_name,id LIMIT 50", self.service)
        self.assertIn("private function validateSection", self.service)
        self.assertIn("Choose one of the available options.", self.registry)
        self.assertNotIn("SELECT DISTINCT current_section FROM members", self.service)

    def test_change_requests_reopen_only_selected_answers_with_history(self):
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("correction_fields", self.correction_migration)
        self.assertIn("DROP INDEX uniq_tg_session_decision", self.correction_migration)
        self.assertIn("revision_number=revision_number+1", api)
        self.assertIn("correction_fields=NULL", self.service)
        self.assertIn("Use /resume to correct", api)
        self.assertIn("expires_at=DATE_ADD(NOW(),INTERVAL 24 HOUR)", api)
        self.assertIn("['/cancel','❌ Cancel','❌ ሰርዝ']", self.service)
        self.assertIn('id="correctionFieldPicker"', page)
        self.assertIn("correction_fields',JSON.stringify", page)
        self.assertIn("TelegramFormRegistry::label", api)
        self.assertIn("ORDER BY r.id DESC LIMIT 50", api)
        self.assertIn('id="reviewHistory"', page)
        self.assertIn("j.reviews.map", page)

    def test_approval_preflight_is_fail_closed_without_creating_members(self):
        readiness = (ROOT / "admin/backend/services/TelegramApprovalReadinessService.php").read_text(encoding="utf-8")
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("status']??'')!=='submitted'", readiness)
        self.assertIn("is_enabled=1 AND is_required=1", readiness)
        self.assertIn("duplicate==='likely'", readiness)
        self.assertIn("class_name=? AND is_active=1 LIMIT 1", readiness)
        self.assertNotRegex(readiness, r"INSERT\s+INTO\s+members")
        self.assertIn("approval_readiness", api)
        self.assertIn('id="approvalReadiness"', page)
        self.assertIn("Approval is enabled only when all blocking preflight checks pass.", page)

    def test_hr_approval_is_transactional_idempotent_and_duplicate_locked(self):
        approval = (ROOT / "admin/backend/services/TelegramMemberApprovalService.php").read_text(encoding="utf-8")
        migration = (ROOT / "sql/083_telegram_registration_approval.sql").read_text(encoding="utf-8")
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("FOR UPDATE", approval)
        self.assertIn("acquireStrongIdentityLock", approval)
        self.assertIn("findStrongMatch", approval)
        self.assertIn("INSERT INTO members", approval)
        self.assertIn("MemberCanonicalIdentityService::refresh", approval)
        self.assertIn("created_member_id", approval)
        self.assertIn("already_approved'=>true", approval)
        self.assertIn("UNIQUE INDEX uniq_tg_created_member", migration)
        self.assertIn("case 'review_approve'", api)
        self.assertIn("Only HR may approve", api)

    def test_transfer_profile_and_clean_review_queue_are_supported(self):
        transfer = (ROOT / "sql/085_telegram_transfer_registration.sql").read_text(encoding="utf-8")
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        approval = (ROOT / "admin/backend/services/TelegramMemberApprovalService.php").read_text(encoding="utf-8")
        self.assertIn("can_transfer", transfer)
        self.assertIn("'waiting','direct','transfer'", transfer)
        self.assertIn("$profile==='transfer'?'transfer':'direct'", approval)
        self.assertIn("s.status IN ('submitted','approved')", api)
        self.assertIn('data-profile="transfer"', page)
        self.assertIn("name=\"can_transfer\"", page)
        self.assertIn("dialog-close", page)
        self.assertIn("aria-label','ዝጋ'", page)

    def test_amharic_identity_policy_and_full_size_editor(self):
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        approval = (ROOT / "admin/backend/services/TelegramMemberApprovalService.php").read_text(encoding="utf-8")
        self.assertIn("'script'=>'ethiopic'", self.registry)
        self.assertIn("በአማርኛ ፊደል ብቻ", self.registry)
        for key in ["guardian_phone2", "alt_phone_number", "block_number", "work_profession", "guardian_house"]:
            self.assertIn(f"'{key}'", self.registry)
            self.assertIn(f"'{key}'=>", approval)
        self.assertIn('class="editor-workspace"', page)
        self.assertIn("height:94vh", page)
        self.assertIn("የቦት ጥያቄ", page)
        self.assertIn("body.set('title_en',formEditor.elements.namedItem('title_am').value)", page)

    def test_desktop_sidebar_and_readable_approval_queue(self):
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("grid-template-columns:240px minmax(0,1fr)", page)
        self.assertIn("position:sticky;top:18px", page)
        self.assertIn("class=\"sessionSelect\"", page)
        for alias in ["member_name", "baptismal_name", "dob_ec", "current_section"]:
            self.assertIn(alias, api)
        self.assertIn("an.field_key='full_name_am'", api)

    def test_analytics_are_bounded_and_aggregated(self):
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("case 'analytics'", api)
        self.assertIn("max(7,min(365", api)
        self.assertIn("GROUP BY profile_key", api)
        self.assertIn("ORDER BY total DESC LIMIT 20", api)

    def test_analytics_dashboard_has_period_filters_and_safe_rendering(self):
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn('data-tab="analytics"', page)
        self.assertIn('id="analyticsDays"', page)
        self.assertIn("async function loadAnalytics", page)
        self.assertIn("registration_types", page)
        self.assertIn("duplicate_attention", page)

    def test_batch_approval_is_bounded_and_reports_skips(self):
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("case 'review_batch_approve'", api)
        self.assertIn("count($ids)>50", api)
        self.assertIn("'approved'=>$approved,'skipped'=>$skipped", api)
        self.assertIn('id="batchApprove"', page)
        self.assertIn('id="batchDialog"', page)
        self.assertIn("review_batch_approve", page)

    def test_bot_primary_flow_is_button_driven_and_amharic(self):
        self.assertIn("'⏭️ ዝለል'", self.service)
        self.assertIn("'⬅️ ወደ ኋላ'", self.service)
        self.assertIn("'📋 መረጃውን አሳይ'", self.service)
        self.assertIn("'❌ ሰርዝ'", self.service)
        self.assertIn("'▶️ ቀጥል'", self.service)
        self.assertNotIn("/new — start", self.service)

    def test_approval_queue_filters_are_allowlisted_bounded_and_debounced(self):
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("['waiting','direct','transfer']", api)
        self.assertIn("['not_checked','none','possible','likely','error']", api)
        self.assertIn("mb_substr($search,0,80", api)
        self.assertIn("an.value_text LIKE ?", api)
        self.assertIn('id="sessionProfile"', page)
        self.assertIn('id="sessionDuplicate"', page)
        self.assertIn("setTimeout(()=>loadSessions", page)

    def test_queue_sort_and_pagination_are_bounded(self):
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("$sortMap=['newest'", api)
        self.assertIn("$limit+1", api)
        self.assertIn("min(100000", api)
        self.assertIn("'has_more'=>$hasMore", api)
        self.assertIn('id="sessionSort"', page)
        self.assertIn('id="sessionPrev"', page)
        self.assertIn('id="sessionNext"', page)

    def test_bot_questions_do_not_duplicate_english_prompts(self):
        self.assertNotIn("$field['prompt_en']", self.service)
        self.assertNotIn("skip this optional question", self.service)
        self.assertIn("ይህ ጥያቄ አማራጭ ነው", self.service)
        self.assertIn("የምዝገባ ዓይነት ይምረጡ።", self.service)

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
        self.assertIn("['super_admin','hr_dept']", api)
        self.assertIn("validateCsrf", api)
        self.assertIn("Only HR may manage operators.", api)
        self.assertIn("Super Admin access required.", api)
        self.assertIn("Only super administrators may run this operation.", api)
        self.assertIn("SELECT id FROM users WHERE id=? AND is_active=1", api)
        self.assertNotIn("TELEGRAM_BOT_TOKEN]", api)
        self.assertNotIn("TELEGRAM_WEBHOOK_SECRET]", api)
        for action in ["overview", "operators", "forms", "sessions", "audit", "operator_save", "canonical_backfill"]:
            self.assertIn(f"case '{action}'", api)

    def test_admin_console_exposes_role_safe_registration_surfaces(self):
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("['super_admin','hr_dept']", page)
        self.assertIn("generateCsrfToken()", page)
        self.assertIn("credentials:'same-origin'", page)
        for label in ["Overview", "Operators", "Forms", "Sessions & reviews", "Audit"]:
            self.assertIn(label, page)
        self.assertIn("Approve and create record", page)
        self.assertNotIn("TELEGRAM_BOT_TOKEN", page)
        self.assertNotIn("TELEGRAM_WEBHOOK_SECRET", page)
        self.assertNotIn("Credentials are deployment-local", page)
        self.assertNotIn("Development, FKSS and WBSS", page)
        self.assertNotIn("above public_html", page)
        self.assertIn('id="previewForm"', page)
        self.assertIn('id="formPreviewDialog"', page)
        self.assertIn("['choice','dynamic_choice'].includes(definition.input)", page)
        self.assertIn("'male'=>'ወንድ / Male'", self.registry)
        self.assertIn("'education_level' => ['max'=>60", self.registry)
        self.assertIn("'spiritual_education' => ['max'=>100", self.registry)
        self.assertIn("'current_section' => ['max'=>100", self.registry)

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
        self.assertIn("Use the protected approval action to approve a draft.", api)
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

    def test_role_split_and_authorized_dashboards(self):
        for path in ["admin/dashboards/super-admin.php", "admin/dashboards/hr-dept.php"]:
            page = (ROOT / path).read_text(encoding="utf-8")
            self.assertIn('/admin/telegram_registration.php', page, path)
            self.assertIn('Telegram Registration', page, path)
        school = (ROOT / "admin/dashboards/school_admin.php").read_text(encoding="utf-8")
        self.assertNotIn('/admin/telegram_registration.php', school)
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("$superActions=['overview','bot_config','bot_config_save','feature_toggle','bot_verify','webhook_status','webhook_set','canonical_backfill']", api)
        self.assertIn("$hrActions=['overview','operators','eligible_users','forms'", api)

    def test_bot_token_setup_stays_outside_webroot_and_database(self):
        service = (ROOT / "admin/backend/services/TelegramBotConfigService.php").read_text(encoding="utf-8")
        config = (ROOT / "config.php").read_text(encoding="utf-8")
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("dirname($this->publicRoot).'/.telegram_env.php'", service)
        self.assertIn("@chmod($temp,0600)", service)
        self.assertNotIn("password_verify", service)
        self.assertNotIn("current_password", service + api)
        self.assertIn("random_bytes(48)", service)
        self.assertNotRegex(service, r"INSERT\s+INTO")
        self.assertIn("dirname(__DIR__) . '/.telegram_env.php'", config)
        self.assertIn("case 'bot_config_save'", api)
        self.assertNotIn("'token'=>$token", service)

    def test_super_admin_can_verify_and_activate_bounded_webhook(self):
        service = (ROOT / "admin/backend/services/TelegramBotApiService.php").read_text(encoding="utf-8")
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("CURLOPT_CONNECTTIMEOUT=>5", service)
        self.assertIn("CURLOPT_TIMEOUT=>12", service)
        self.assertIn("CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS", service)
        self.assertIn("'allowed_updates'=>json_encode(['message'])", service)
        self.assertIn("'max_connections'=>'10'", service)
        self.assertIn("secret_token", service)
        self.assertNotIn("error_log", service)
        for action in ["bot_verify", "webhook_status", "webhook_set"]:
            self.assertIn(f"case '{action}'", api)
        self.assertIn("Activate HTTPS webhook", page)
        self.assertIn("Verify bot token", page)

    def test_hr_operator_management_allows_any_active_account(self):
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("case 'eligible_users'", api)
        self.assertIn("WHERE is_active=1 ORDER BY full_name,id LIMIT 100", api)
        self.assertNotIn("is_active=1 AND role IN ('super_admin','school_admin','hr_dept')", api)
        self.assertIn("ORDER BY full_name,id LIMIT 100", api)
        self.assertIn("status='cancelled',lock_version=lock_version+1", api)
        self.assertIn('id="linkedUserSelect"', page)
        self.assertIn("loadEligibleUsers", page)
        self.assertIn("editOperator", page)
        self.assertIn("Active system account", page)
        self.assertNotIn("active HR/admin", page)

    def test_duplicate_refinement_is_live_and_cap_is_explicit(self):
        duplicate = (ROOT / "admin/backend/services/TelegramDuplicateCandidateService.php").read_text(encoding="utf-8")
        registration = (ROOT / "admin/backend/services/TelegramRegistrationService.php").read_text(encoding="utf-8")
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("findWithMeta", duplicate)
        self.assertIn("'capped'=>$plausibleCount>$displayLimit", duplicate)
        for key in ["full_name_am", "dob_ec", "phone_number", "baptismal_name", "gender"]:
            self.assertIn(key, registration)
        self.assertIn("More plausible candidates exist than the safe display limit", registration)
        self.assertIn("ይህ ጥያቄ አማራጭ ነው", registration)
        self.assertIn("candidate_cap_reached", api)
        self.assertIn("results are never silently expanded", page)

    def test_review_decisions_notify_operator_without_rolling_back_decision(self):
        bot_api = (ROOT / "admin/backend/services/TelegramBotApiService.php").read_text(encoding="utf-8")
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("public function sendMessage", bot_api)
        self.assertIn("strlen($text)>3500", bot_api)
        review = api[api.index("case 'review_decide'"):api.index("case 'audit'")]
        self.assertLess(review.index("$conn->commit()"), review.index("$botApi->sendMessage"))
        self.assertIn("Use /resume to correct the requested answers", review)
        self.assertIn("operator_notified", review)
        self.assertIn("notification_warning", review)
        self.assertIn("Telegram review notification failed", review)
        self.assertIn("result.notification_warning", page)

    def test_console_has_immediate_loading_success_and_error_feedback(self):
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn('id="networkActivity"', page)
        self.assertIn('id="toastRegion"', page)
        self.assertIn("function notify", page)
        self.assertIn("networkBusy(true)", page)
        self.assertIn("controller.abort(),20000", page)
        self.assertIn("trigger.disabled=true", page)
        self.assertIn("aria-busy", page)
        self.assertIn("prefers-reduced-motion:reduce", page)
        self.assertIn("notify('Telegram operator access was saved.')", page)
        self.assertIn("Webhook active", page)
        self.assertNotIn("Confirm your Super Admin password", page)

    def test_super_admin_feature_toggle_is_managed_and_audited(self):
        service = (ROOT / "admin/backend/services/TelegramBotConfigService.php").read_text(encoding="utf-8")
        gate = (ROOT / "admin/backend/services/FeatureGate.php").read_text(encoding="utf-8")
        api = (ROOT / "admin/api_telegram_registration.php").read_text(encoding="utf-8")
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        self.assertIn("setFeatureEnabled", service)
        self.assertIn("TELEGRAM_REGISTRATION_MANAGED_ENABLED", service)
        self.assertIn("TELEGRAM_REGISTRATION_MANAGED_ENABLED", gate)
        self.assertIn("case 'feature_toggle'", api)
        self.assertIn("'feature_enabled':'feature_disabled'", api)
        self.assertIn('id="featureToggle"', page)
        self.assertIn("Enable Telegram registration", page)
        self.assertIn("aria-pressed", page)

    def test_canonical_backfill_has_the_pair_hash_method_it_calls(self):
        normalizer = (ROOT / "admin/backend/services/AmharicIdentityNormalizer.php").read_text(encoding="utf-8")
        canonical = (ROOT / "admin/backend/services/MemberCanonicalIdentityService.php").read_text(encoding="utf-8")
        self.assertIn("public static function pairHash", normalizer)
        self.assertIn("AmharicIdentityNormalizer::pairHash", canonical)
        self.assertIn("self::pairHash($student, $father)", normalizer)

    def test_rendered_console_javascript_parses_for_super_admin_and_hr(self):
        page = (ROOT / "admin/telegram_registration.php").read_text(encoding="utf-8")
        start = page.index("<script>", page.index("danger_confirm.js")) + len("<script>")
        script = page[start:page.index("</script>", start)]

        hr = script.replace("<?=json_encode($csrf)?>", "'csrf'").replace("<?=json_encode($canManage)?>", "true")
        hr = hr.replace("<?php if(!$isSetup):?>", "").replace("<?php if($canManage):?>", "")
        hr = hr.replace("<?php endif;?><?php endif;?>", "")
        hr = "\n".join(line for line in hr.splitlines() if "<?php if($role==='super_admin'):?>" not in line)

        rendered = []
        skip = False
        for line in script.splitlines():
            if line.startswith("<?php if(!$isSetup):?>"):
                skip = True
                continue
            if skip:
                if line.endswith("<?php endif;?><?php endif;?>"):
                    skip = False
                continue
            if line.startswith("<?php if($role==='super_admin'):?>"):
                line = line.replace("<?php if($role==='super_admin'):?>", "", 1)
                line = line.removesuffix("<?php endif;?>")
            rendered.append(line)
        super_admin = "\n".join(rendered).replace("<?=json_encode($csrf)?>", "'csrf'")
        super_admin = super_admin.replace("<?=json_encode($canManage)?>", "false")

        for role, javascript in [("hr", hr), ("super_admin", super_admin)]:
            with tempfile.NamedTemporaryFile("w", suffix=".js", encoding="utf-8") as handle:
                handle.write(javascript)
                handle.flush()
                result = subprocess.run(["node", "--check", handle.name], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, f"{role} console JavaScript failed to parse: {result.stderr}")

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
