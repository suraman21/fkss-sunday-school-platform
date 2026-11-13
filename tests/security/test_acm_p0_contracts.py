"""ACM P0 contract pins: schema, services, API, console guards.

Static pins (no PHP runtime in CI sandbox): every rule below is a
load-bearing security or layering contract for the ACM console.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SQL = ROOT / "sql" / "079_acm_p0_foundation.sql"
SVC = ROOT / "admin" / "backend" / "services" / "Acm"
API = ROOT / "admin" / "api_acm.php"
PAGES = [ROOT / "admin" / n for n in (
    "acm_overview.php", "acm_queue.php", "acm_ticket.php", "acm_person.php",
)]
LAYOUT = ROOT / "admin" / "acm_layout.php"
GUARD = ROOT / "admin" / "access_control.php"
ROUTER = ROOT / "admin" / "dashboard.php"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


# ---- schema ----

def test_migration_is_idempotent_and_fk_free():
    s = read(SQL)
    for table in ("acm_persons", "acm_person_accounts",
                  "acm_activation_tickets", "acm_ticket_events"):
        assert f"CREATE TABLE IF NOT EXISTS `{table}`" in s, table
    assert "RE-RUNNABLE" in s
    code = "\n".join(ln for ln in s.splitlines() if not ln.lstrip().startswith("--"))
    assert "FOREIGN KEY" not in code  # application-enforced, like 069
    assert re.search(r"`evidence` TEXT", s)
    assert re.search(r"`meta` TEXT", s)
    assert not re.search(r"`\w+` JSON", s)  # no JSON column type (shared hosting)


def test_migration_touches_no_existing_table():
    s = read(SQL)
    assert "ALTER TABLE" not in s
    assert s.count("CREATE TABLE") == 4


# ---- policy ----

def test_policy_deny_by_default_and_p0_roles():
    s = read(SVC / "AcmPolicy.php")
    assert "return false;" in s
    # S1: one accounts_office seat; old seat names are gone from policy.
    assert "'accounts_office'" in s
    assert "'registrar'" not in s and "'account_activator'" not in s
    assert "consoleRoles" in s
    # Office holds the union of lifecycle rights (maker-checker moved to
    # user-ID comparison in the ticket service, pinned in S1 tests).
    assert "'create'" in s and "'approve'" in s
    # Governance deny-list: office holds zero office/approver/reviewer/
    # attest rights (empty sets, not missing keys).
    for res in ("office", "approver", "reviewer", "attest"):
        assert re.search(r"'" + res + r"'\s*=>\s*\[\]", s), res


# ---- services ----

def test_services_use_prepared_statements_only():
    for name in ("AcmPersonService.php", "AcmTicketService.php"):
        s = read(SVC / name)
        assert "->prepare(" in s, name
        assert "$_GET" not in s and "$_POST" not in s, name  # no superglobals
        # No interpolated variables straight into SQL strings.
        for m in re.finditer(r"prepare\(\s*['\"](.*?)['\"]", s, re.S):
            assert "$" not in m.group(1), f"{name}: {m.group(1)[:60]}"


def test_ticket_state_machine_pins():
    s = read(SVC / "AcmTicketService.php")
    assert "FOR UPDATE" in s  # decide/claim are row-locked
    assert "beginTransaction" in s and "rollBack" in s
    assert "Maker-checker" in s or "maker-checker" in s
    assert "submitter_id'] === $userId" in s or 'submitter_id"] === $userId' in s
    assert "password_hash(bin2hex(random_bytes(32))" in s  # unusable P0 credential
    assert "acm_person_accounts" in s  # provision links person<->user


def test_no_token_or_password_keys_in_audit_details():
    s = read(API)
    forbidden = ("password", "token", "hash", "secret", "csrf_token")
    for m in re.finditer(r"acm_audit\(\$pdo,\s*'([^']+)',\s*\[(.*?)\]",
                         s, re.S):
        keys = m.group(2).lower()
        for bad in forbidden:
            assert bad not in keys, f"{m.group(1)} leaks {bad}"


# ---- API ----

def test_api_requires_session_role_csrf_and_policy():
    s = read(API)
    assert "isLoggedIn()" in s
    assert "AcmPolicy::consoleRoles()" in s
    assert "validateCsrf" in s
    assert "AcmPolicy::can(" in s
    assert "InvalidArgumentException" in s and "http_response_code" in s


def test_api_exposes_p0_actions_only():
    s = read(API)
    for action in ("overview", "queue", "ticket", "person_search", "person",
                   "person_create", "person_update", "ticket_create",
                   "claim", "release", "note", "decide"):
        assert f"case '{action}':" in s, action
    assert "default:" in s


# ---- pages + chrome ----

def test_pages_guard_and_escape():
    for page in PAGES:
        s = read(page)
        assert "ACM_BOOT" in s, page.name
        assert "isLoggedIn()" in s, page.name
        assert "AcmPolicy::consoleRoles()" in s, page.name
        assert "e(" in s, page.name  # output escaping helper used
        # No raw superglobal output anywhere on ACM pages.
        assert "echo $_GET" not in s, page.name
        assert "echo $_POST" not in s, page.name
        assert "<?= $_GET" not in s, page.name
        assert "<?= $_POST" not in s, page.name


def test_layout_is_include_only_and_csrf_wired():
    s = read(LAYOUT)
    assert "defined('ACM_BOOT')" in s
    assert 'meta name="csrf-token"' in s or "csrf-token" in s
    assert "acmApi" in s
    assert "/admin/logout.php" in s


def test_ticket_page_hides_decide_from_submitter():
    s = read(ROOT / "admin" / "acm_ticket.php")
    assert "$isMine" in s
    assert "Maker" in s or "maker" in s
    assert "acmVerified" in s  # duplicate-warning confirmation checkbox


# ---- router + guard wiring ----

def test_role_map_covers_all_acm_scripts():
    s = read(GUARD)
    for name in ("api_acm.php", "acm_overview.php", "acm_queue.php",
                 "acm_ticket.php", "acm_person.php", "acm_office.php",
                 "acm_audit.php", "acm_layout.php"):
        pattern = re.compile(re.escape(f"'{name}'") + r"\s*=>\s*\[([^\]]+)\]")
        m = pattern.search(s)
        assert m, name
        roles = m.group(1)
        assert "'super_admin'" in roles, name
        assert "'accounts_office'" in roles, name
        assert "'registrar'" not in roles and "'account_activator'" not in roles, name
        assert "'member'" not in roles and "'parent'" not in roles, name


def test_dashboard_routes_new_roles_to_acm():
    s = read(ROUTER)
    assert "case 'accounts_office':" in s
    assert "case 'registrar':" not in s
    assert "case 'account_activator':" not in s
    assert "acm_overview.php" in s


def test_seats_creatable_via_ui_but_member_parent_are_not():
    save = read(ROOT / "admin" / "backend" / "user-save.php")
    block = save.split("$validRoles = [")[1].split("];")[0]
    assert "'accounts_office'" in block
    assert "'registrar'" not in block and "'account_activator'" not in block
    assert "'member'" not in block and "'parent'" not in block
    users = read(ROOT / "admin" / "users.php")
    assert "'accounts_office' => 'Accounts Office (ACM)'" in users


def test_inline_dashboard_dropdown_and_toggle_cover_acm_seats():
    dash = read(ROOT / "admin" / "dashboards" / "super-admin.php")
    assert "'accounts_office' => 'Accounts Office (ACM)'" in dash
    assert "'registrar'" not in dash and "'account_activator'" not in dash
    toggle = read(ROOT / "admin" / "backend" / "user-toggle.php")
    super_row = next(ln for ln in toggle.splitlines() if "'super_admin' =>" in ln)
    assert "'accounts_office'" in super_row
    assert "'registrar'" not in toggle and "'account_activator'" not in toggle
    school_row = next(ln for ln in toggle.splitlines() if "'school_admin' =>" in ln)
    assert "'accounts_office'" not in school_row


def test_console_uses_school_admin_theme_with_modes():
    css = read(ROOT / "admin" / "css" / "acm.css")
    assert '[data-theme="light"]' in css
    assert "--ac:" in css and "--sb:" in css and "--bright:" in css
    layout = read(LAYOUT)
    assert "toggleTheme()" in layout
    assert "wbws-theme" in layout  # shared key: mode follows across consoles
    for page in PAGES + [LAYOUT]:
        s = read(page)
        assert "super_admin.css" not in s, page.name
        assert 'class="eth"' not in s, page.name
