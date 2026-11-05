"""Security and contract tests for the Super Admin App Release Management subsystem."""
import json
import os
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class AppReleaseManagementTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.access_control = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")
        cls.api_app_release = (ROOT / "admin/api_app_release.php").read_text(encoding="utf-8")
        cls.app_release_manager = (
            ROOT / "admin/backend/services/AppReleaseManager.php"
        ).read_text(encoding="utf-8")
        cls.super_admin_dashboard = (
            ROOT / "admin/dashboards/super-admin.php"
        ).read_text(encoding="utf-8")
        cls.app_release_section = (
            ROOT / "admin/dashboards/sections/app_release_section.php"
        ).read_text(encoding="utf-8")
        cls.app_release_js = (ROOT / "admin/js/app_release.js").read_text(encoding="utf-8")

    def test_access_control_restricts_api_to_super_admin_only(self):
        self.assertIn("'api_app_release.php' => ['super_admin']", self.access_control)
        self.assertIn("($_SESSION['admin_role'] ?? '') !== 'super_admin'", self.api_app_release)
        self.assertIn("http_response_code(403)", self.api_app_release)

    def test_api_enforces_csrf_and_structured_actions(self):
        self.assertIn("validateCsrf", self.api_app_release)
        self.assertIn("case 'get_release':", self.api_app_release)
        self.assertIn("case 'save_config':", self.api_app_release)
        self.assertIn("case 'upload_chunk':", self.api_app_release)
        self.assertIn("case 'assemble_chunks':", self.api_app_release)
        self.assertIn("case 'cancel_upload':", self.api_app_release)
        self.assertIn("case 'upload_apk':", self.api_app_release)
        self.assertIn("case 'delete_apk':", self.api_app_release)

    def test_service_validates_apk_extension_and_chunk_integrity(self):
        self.assertIn("MAX_APK_BYTES = 536870912", self.app_release_manager)
        self.assertIn("handleChunkUpload", self.app_release_manager)
        self.assertIn("assembleChunks", self.app_release_manager)
        self.assertIn("cleanupOldChunks", self.app_release_manager)
        self.assertIn("pathinfo($originalFilename, PATHINFO_EXTENSION)", self.app_release_manager)
        self.assertIn("$ext !== 'apk'", self.app_release_manager)

    def test_dashboard_embeds_app_release_tab_and_scripts(self):
        self.assertIn("'app_release'", self.super_admin_dashboard)
        self.assertIn("data-section=\"app_release\"", self.super_admin_dashboard)
        self.assertIn("app_release_section.php", self.super_admin_dashboard)
        self.assertIn("app_release.js", self.super_admin_dashboard)
        self.assertIn("AppReleaseUI", self.app_release_js)

    def test_upload_state_elements_exist_in_section(self):
        self.assertIn("id=\"upload-apk-input\"", self.app_release_section)
        self.assertIn("id=\"upload-idle-state\"", self.app_release_section)
        self.assertIn("id=\"upload-stage-state\"", self.app_release_section)
        self.assertIn("id=\"upload-progress-state\"", self.app_release_section)
        self.assertIn("id=\"upload-assembling-state\"", self.app_release_section)
        self.assertIn("id=\"upload-success-state\"", self.app_release_section)
        self.assertIn("id=\"upload-error-state\"", self.app_release_section)
        self.assertIn("id=\"upload-progress-fill\"", self.app_release_section)

    def test_js_engine_handles_chunking_retries_and_resilience(self):
        # Bounded binary transfers, verified server-truth resume and ACK-gated
        # assembly. Executable scheduling/error contracts live in
        # test_apk_upload_reliability.py and tests/js/. Legacy base64 remains
        # server-compatible, not an automatic auth/WAF bypass in the client.
        self.assertIn("PART_SIZE = 512 * 1024", self.app_release_js)
        self.assertIn("POOL_SIZE = 4", self.app_release_js)
        self.assertIn("PART_DEADLINE_MS = 45000", self.app_release_js)
        self.assertIn("MAX_PART_RETRIES = 8", self.app_release_js)
        self.assertIn("WATCHDOG_MS = 60000", self.app_release_js)
        self.assertIn("workerLoop", self.app_release_js)
        self.assertIn("sendPart", self.app_release_js)
        self.assertIn("AbortController", self.app_release_js)
        self.assertNotIn("chunk_b64", self.app_release_js)
        self.assertIn("ctx.count === ctx.total", self.app_release_js)
        self.assertIn("Object.keys(ctx.pending).length === 0", self.app_release_js)
        self.assertIn("fingerprint", self.app_release_js)
        self.assertIn("upload_status", self.app_release_js)
        self.assertIn("assembleChunksOnServer", self.app_release_js)
        self.assertIn("cancelUpload", self.app_release_js)
        self.assertIn("startWatchdog", self.app_release_js)
        # the stalled-at-0% class of bug must stay impossible: deadline abort
        # and the watchdog banner are the two guards against silent freezes.
        self.assertIn("timed out; saved parts are retained", self.app_release_js)
        self.assertIn("No verified part for", self.app_release_js)

    def test_no_external_cdn_dependency_for_upload_engine(self):
        # The engine must be fully self-contained: a blocked/slow CDN must
        # never be able to stall an upload again.
        self.assertNotIn("resumable.min.js", self.super_admin_dashboard)
        self.assertNotIn("new Resumable(", self.app_release_js)

    def test_releases_storage_protection_rule(self):
        self.assertIn("Options -Indexes", self.app_release_manager)
        self.assertIn("Deny from all", self.app_release_manager)

    def test_upload_diagnostics_and_resume_endpoints(self):
        # Server-side half of the hardened upload engine: preflight env
        # report, server-truth chunk status, resumable.js GET chunk test and
        # the base64 emergency transport must all stay wired.
        self.assertIn("case 'upload_preflight'", self.api_app_release)
        self.assertIn("case 'upload_status'", self.api_app_release)
        self.assertIn("chunk_b64", self.api_app_release)
        self.assertIn("resumableChunkNumber", self.api_app_release)
        self.assertIn("getPreflightInfo", self.app_release_manager)
        self.assertIn("uploadStatus", self.app_release_manager)
        self.assertIn("chunkExists", self.app_release_manager)
        self.assertIn("uploadErrorText", self.app_release_manager)
        # parallel part uploads must not serialize (or freeze) behind the
        # PHP session lock — the lock is dropped right after auth/CSRF.
        self.assertIn("session_write_close", self.api_app_release)


if __name__ == "__main__":
    unittest.main()
