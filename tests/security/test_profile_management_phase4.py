from pathlib import Path
import re
import shutil
import subprocess

import pytest


ROOT = Path(__file__).resolve().parents[2]
ACCOUNT_PAGE = ROOT / "admin" / "account.php"
ACCOUNT_JS = ROOT / "frontend" / "js" / "account.js"
ACCOUNT_CSS = ROOT / "admin" / "css" / "account.css"
ACCOUNT_LINK = ROOT / "admin" / "components" / "account_link.php"


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def test_common_account_surface_and_assets_exist():
    for path in (ACCOUNT_PAGE, ACCOUNT_JS, ACCOUNT_CSS, ACCOUNT_LINK):
        assert path.is_file(), path

    page = text(ACCOUNT_PAGE)
    assert "frontend/layouts/base.php" in page
    assert "pageScript = 'account'" in page
    assert "admin/css/account.css" in page
    assert "requireAuth();" in page


def test_every_supported_authenticated_role_reaches_one_common_page():
    page = text(ACCOUNT_PAGE)
    expected_roles = {
        "super_admin", "school_admin", "info_dept", "edu_dept",
        "finance_dept", "material_dept", "mezmur_dept", "hr_dept",
        "teacher", "attendance_taker", "mezmur_attendance_taker",
        "hr_attendance_taker", "content_editor",
    }
    declared = set(re.findall(r"^\s+'([a-z_]+)',\s*$", page, re.MULTILINE))
    assert declared == expected_roles

    dashboard = text(ROOT / "admin" / "dashboard.php")
    launcher = text(ACCOUNT_LINK)
    assert "register_shutdown_function(static function" not in dashboard
    assert dashboard.count("components/account_link.php") == 1  # not-ready page only

    # Each direct legacy dashboard that has no integrated account surface must
    # render the shared launcher inside its own body. The shared department
    # taker file independently covers both taker roles.
    launcher_dashboards = {
        "super_admin": "admin/dashboards/super-admin.php",
        "school_admin": "admin/dashboards/school_admin.php",
        "edu_dept": "admin/dashboards/edu_dept.php",
        "material_dept": "admin/dashboards/material_department.php",
        "teacher": "admin/dashboards/teacher.php",
        "attendance_taker": "admin/dashboards/attendance_taker.php",
        "mezmur_attendance_taker": "admin/dashboards/dept_taker.php",
        "hr_attendance_taker": "admin/dashboards/dept_taker.php",
        "content_editor": "admin/dashboards/content_editor.php",
    }
    for role, relative in launcher_dashboards.items():
        source = text(ROOT / relative)
        assert source.count("components/account_link.php") == 1, role
        assert source.rindex("components/account_link.php") < source.rindex("</body>"), role

    # Existing integrated surfaces remain canonical and do not receive a
    # second floating launcher.
    for role, relative in {
        "hr_dept": "admin/dashboards/hr-dept.php",
        "info_dept": "admin/dashboards/info-dept.php",
    }.items():
        source = text(ROOT / relative)
        assert source.count("ssms_app_url('admin/account.php')") == 1, role
        assert "components/account_link.php" not in source, role
        assert "settings" in source.lower(), role

    for role, shell in {
        "finance_dept": "frontend/pages/finance_dept.php",
        "mezmur_dept": "frontend/pages/mezmur_dept.php",
    }.items():
        shell_source = text(ROOT / shell)
        assert shell_source.count("ssms_app_url('admin/account.php')") == 2, role
        assert "components/account_link.php" not in shell_source, role
        assert "school-nav-link" in shell_source
        assert "school-bottom-account-link" in shell_source
    components_css = text(ROOT / "themes" / "components.css")
    assert ".school-bottom-account-link" in components_css
    assert "ssms_app_url('admin/account.php')" in launcher
    assert "isLoggedIn()" in launcher


def test_phase4_uses_only_the_established_self_service_controller_contract():
    js = text(ACCOUNT_JS)
    page = text(ACCOUNT_PAGE)
    expected_actions = {
        "profile_get", "profile_update", "password_change",
        "profile_image_upload", "profile_image_remove",
    }
    requested = set(re.findall(r"request\('([a-z_]+)'", js))
    assert requested == expected_actions
    assert "admin/api_settings.php" in js
    assert "data-profile-api" in page
    assert "credentials: 'same-origin'" in js
    assert "X-CSRF-TOKEN" in js
    assert "csrf_token" in js

    combined = js + "\n" + page
    for forbidden in (
        "user_id", "owner_id", "target_user_id", "profile_image_path",
        "localStorage", "sessionStorage", "Bearer ", "Authorization",
    ):
        assert forbidden not in combined


def test_profile_drafts_versions_conflicts_and_identity_reconciliation_are_explicit():
    js = text(ACCOUNT_JS)
    assert js.count("profile_version") >= 6
    assert "detailDraftIsDirty" in js
    assert "preserveDraft" in js
    assert "PROFILE_CONFLICT" in js
    assert "reloadConflict" in js
    assert "beforeunload" in js
    assert "window.APP.user.name" in js
    assert "window.APP.user.username" in js
    assert "[data-user-name]" in js
    assert "[data-user-initials]" in js


def test_username_email_and_password_policies_match_backend_boundaries():
    js = text(ACCOUNT_JS)
    page = text(ACCOUNT_PAGE)
    assert "/^[a-z0-9][a-z0-9_.]*[a-z0-9]$/" in js
    assert "/[._]{2}/" in js
    assert "RESERVED_USERNAMES" in js
    assert "maxlength=\"50\"" in page
    assert "maxlength=\"100\"" in page
    assert "identityDraftChanged()" in js
    assert "current_password" in js
    assert "characters < 6" in js
    assert "bytes > 72" in js
    assert "COMMON_PASSWORDS" in js
    assert "TextEncoder" in js


def test_private_image_flow_keeps_current_image_until_mutation_succeeds():
    js = text(ACCOUNT_JS)
    page = text(ACCOUNT_PAGE)
    image_route = text(ROOT / "admin" / "profile_image.php")
    assert "data-profile-image-url" in page
    assert "admin/profile_image.php" in page
    assert "same-origin" in js
    assert "MAX_IMAGE_BYTES = 4 * 1024 * 1024" in js
    assert "MIN_IMAGE_DIMENSION = 64" in js
    assert "width < MIN_IMAGE_DIMENSION || height < MIN_IMAGE_DIMENSION" in js
    assert "MAX_IMAGE_DIMENSION = 4096" in js
    assert "MAX_IMAGE_PIXELS = 12000000" in js
    assert "inspectSelectedImage" in js
    assert "imageConfirmDialog" in page
    assert "showConfirmedPreview" in js
    upload_flow = js[js.index("async function uploadImage"):js.index("function askToRemoveImage")]
    assert upload_flow.index("await request('profile_image_upload'") < upload_flow.index("renderCanonical({ previewUrl: previewUrl")
    remove_flow = js[js.index("async function removeImage"):js.index("function passwordMetrics")]
    assert remove_flow.index("await request('profile_image_remove'") < remove_flow.index("state.canonical.profile_image = payload.data.profile_image")
    assert "Cache-Control: private, no-store" in image_route
    assert "X-Content-Type-Options: nosniff" in image_route


def test_required_http_and_network_failures_have_safe_client_messages():
    js = text(ACCOUNT_JS)
    for status in (400, 401, 403, 409, 413, 415, 422, 429, 500):
        if status == 500:
            assert "error.status >= 500" in js
        else:
            assert f"error.status === {status}" in js
    assert "NETWORK_ERROR" in js
    assert "USERNAME_TAKEN" in js
    assert "EMAIL_TAKEN" in js
    assert "CURRENT_PASSWORD_INCORRECT" in js
    assert "PASSWORD_POLICY_FAILED" in js
    assert "textContent = message" in js
    assert "innerHTML" not in js
    assert "console.log" not in js
    assert "console.error" not in js


def test_accessible_responsive_controls_and_field_errors_are_wired():
    page = text(ACCOUNT_PAGE)
    css = text(ACCOUNT_CSS)
    js = text(ACCOUNT_JS)
    assert page.count("<dialog") == 3
    assert "aria-live=\"polite\"" in page
    assert "role=\"alert\"" in page
    assert "<label" in page
    assert "aria-describedby" in page
    assert "focus-visible" in css
    assert "@media (max-width: 580px)" in css
    assert "prefers-reduced-motion" in css
    assert "navigator.onLine" in js
    assert "accountOffline" in js

    page_ids = set(re.findall(r'id="([A-Za-z0-9_-]+)"', page))
    referenced_ids = set(re.findall(r"byId\('([A-Za-z0-9_-]+)'\)", js))
    assert referenced_ids <= page_ids


def test_legacy_hr_and_info_profile_mutators_are_replaced_by_common_page_links():
    for relative in ("admin/dashboards/hr-dept.php", "admin/dashboards/info-dept.php"):
        dashboard = text(ROOT / relative)
        assert "COMMON ACCOUNT PAGE" in dashboard
        assert "ssms_app_url('admin/account.php')" in dashboard
        assert "function loadProfile()" not in dashboard
        assert "function saveProfile()" not in dashboard
        assert "function changePassword()" not in dashboard
        assert "profUsername" not in dashboard
        assert "pwdCurrent" not in dashboard


def test_account_context_is_server_issued_on_login_and_every_canonical_response():
    login = text(ROOT / "admin" / "backend" / "login.php")
    api = text(ROOT / "admin" / "api_settings.php")
    js = text(ACCOUNT_JS)

    assert "$_SESSION['PROFILE_ACCOUNT_CONTEXT'] = bin2hex(random_bytes(32));" in login
    assert "$_SESSION['PROFILE_ACCOUNT_CONTEXT']" in api
    assert "hash_equals($settingsAccountContext, $submittedContext)" in api
    assert "'code' => 'ACCOUNT_CONTEXT_CHANGED'" in api
    assert api.count("'account_context' => $settingsAccountContext") == 5
    assert "X-Account-Context" in js
    assert "handleAccountContextMismatch" in js
    assert "handleUnauthorized" in js
    assert "clearAccountSpecificState" in js

    # The marker is a stale-page precondition, never a browser-selected owner.
    assert "$adminId = (int)($_SESSION['admin_id'] ?? 0);" in api
    for forbidden in ("target_user_id", "owner_id", "X-Account-Owner"):
        assert forbidden not in js
    context_guard = api[api.index("$profileMutationActions"):api.index("// CSRF protection")]
    assert "$adminId" not in context_guard
    assert "target_user_id" not in context_guard


def test_cross_tab_auth_signals_are_ephemeral_and_do_not_use_browser_storage():
    account = text(ACCOUNT_JS)
    admin_login = text(ROOT / "admin" / "index.php")
    frontend_login = text(ROOT / "frontend" / "pages" / "login.php")
    combined = account + admin_login + frontend_login

    assert combined.count("BroadcastChannel('ssms-profile-auth-context')") >= 3
    assert "auth-changed" in combined
    assert "session-ended" in combined
    assert "localStorage" not in combined
    assert "sessionStorage" not in combined


def test_conflict_dispatch_and_generations_are_explicit_before_generic_409():
    js = text(ACCOUNT_JS)
    friendly = js[js.index("function friendlyError"):js.index("function initials")]
    assert friendly.index("PROFILE_CONFLICT") < friendly.index("error.status === 409")
    assert friendly.index("USERNAME_TAKEN") < friendly.index("error.status === 409")
    assert friendly.index("EMAIL_TAKEN") < friendly.index("error.status === 409")
    assert "requestDraftGeneration" in js
    assert "requestContextEpoch" in js
    assert "selectionGeneration" in js
    assert "renderGeneration" in js
    assert "state.draftGeneration += 1" in js
    assert js.count("state.loadSequence += 1") >= 5


def test_exact_pixel_boundary_and_storage_exception_source_paths_are_covered():
    image_service = text(ROOT / "admin" / "backend" / "services" / "ProfileImageService.php")
    image_route = text(ROOT / "admin" / "profile_image.php")
    fixture = text(ROOT / "tests" / "fixtures" / "profile_management_phase1.fixture")

    assert "public const MIN_DIMENSION = 64;" in image_service
    for boundary in ("[63, 64]", "[64, 63]", "[63, 63]", "[64, 64]"):
        assert boundary in fixture
    assert "public const MAX_PIXELS = 12000000;" in image_service
    assert "pngHeaderWithDimensions(4000, 3000)" in fixture
    assert "pngHeaderWithDimensions(4001, 3000)" in fixture
    assert "ProfileImagePersistenceException('Profile image schema is unavailable.')" in image_route
    assert "ProfileImageStorageException" not in image_route
    assert "final class ProfileImagePersistenceException" in image_service


def _relative_luminance(hex_color: str) -> float:
    channels = [int(hex_color[index:index + 2], 16) / 255 for index in (1, 3, 5)]
    linear = [value / 12.92 if value <= 0.04045 else ((value + 0.055) / 1.055) ** 2.4 for value in channels]
    return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2]


def _contrast(first: str, second: str) -> float:
    lighter, darker = sorted((_relative_luminance(first), _relative_luminance(second)), reverse=True)
    return (lighter + 0.05) / (darker + 0.05)


def test_account_surfaces_and_semantic_text_remain_readable_in_dark_and_light_modes():
    css = text(ACCOUNT_CSS)
    assert "--school-card" not in css
    assert css.count("var(--school-bg-alt, #1e293b)") >= 4
    assert "--account-muted: var(--school-text-muted, #94a3b8);" in css
    assert "--account-muted: var(--school-text-dim, #64748b);" in css
    assert ".page-account.light-mode .account-kicker" in css
    assert "color: var(--school-primary, #047857);" in css

    dark_surface = "#1e293b"
    light_surface = "#ffffff"
    for foreground in ("#94a3b8", "#fca5a5", "#4ade80", "#fbbf24"):
        assert _contrast(foreground, dark_surface) >= 4.5
    for foreground in ("#64748b", "#b91c1c", "#166534", "#92400e", "#047857", "#600000"):
        assert _contrast(foreground, light_surface) >= 4.5


@pytest.mark.skipif(shutil.which("node") is None, reason="Node.js is not available")
def test_account_runtime_races_conflicts_cleanup_and_mutation_locking():
    result = subprocess.run(
        [shutil.which("node"), str(ROOT / "tests" / "js" / "account_runtime_test.js")],
        cwd=ROOT,
        text=True,
        capture_output=True,
        timeout=30,
        check=False,
    )
    assert result.returncode == 0, result.stdout + result.stderr
    assert "account runtime behavior: ok" in result.stdout
