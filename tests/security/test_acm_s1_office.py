"""ACM S1 contract pins: office model, designations, attestation.

Static pins (no PHP runtime in CI sandbox): mode-aware maker-checker,
solo designee enforcement, reviewer independence, and the completed
seat rename (old names survive only in migration 080 + docs).
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SQL80 = ROOT / "sql" / "080_acm_office_model.sql"
SVC = ROOT / "admin" / "backend" / "services" / "Acm"
API = ROOT / "admin" / "api_acm.php"
OFFICE_PAGE = ROOT / "admin" / "acm_office.php"
AUDIT_PAGE = ROOT / "admin" / "acm_audit.php"
TICKET_PAGE = ROOT / "admin" / "acm_ticket.php"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


# ---- migration 080 ----

def test_s1_migration_is_idempotent_and_fk_free():
    s = read(SQL80)
    for table in ("acm_office_approvers", "acm_office_reviewers",
                  "acm_office_attestations", "acm_office_mode_history"):
        assert f"CREATE TABLE IF NOT EXISTS `{table}`" in s, table
    assert "RE-RUNNABLE" in s
    code = "\n".join(ln for ln in s.splitlines() if not ln.lstrip().startswith("--"))
    assert "FOREIGN KEY" not in code
    assert not re.search(r"`\w+` JSON", s)
    # self_approved column is added behind an information_schema guard.
    assert "information_schema.COLUMNS" in s
    assert "self_approved" in s


def test_s1_migration_seeds_settings_and_renames_seats():
    s = read(SQL80)
    assert "acm.office_mode" in s and "'separated'" in s
    assert "acm.office_network_enforced" in s
    assert "acm.max_accounts_per_device" in s and "'5'" in s
    assert "SET `role` = 'accounts_office'" in s
    assert "old_roles_remaining" in s  # verification SELECT ships with it


# ---- office service ----

def test_office_service_uses_prepared_statements_and_no_superglobals():
    s = read(SVC / "AcmOfficeService.php")
    assert "->prepare(" in s
    assert "$_GET" not in s and "$_POST" not in s
    # Dynamic table names are constrained to two literals.
    assert "acm_office_approvers" in s and "acm_office_reviewers" in s
    assert "assertDesigneeTable" in s


def test_office_mode_change_requires_reason_date_and_staffing():
    s = read(SVC / "AcmOfficeService.php")
    assert "function setMode" in s
    assert "Only a Super Admin can change the office mode." in s
    assert "YYYY-MM-DD" in s
    assert "countActiveOfficers" in s
    assert "at least two active officers" in s
    assert "acm_office_mode_history" in s
    assert "beginTransaction" in s and "rollBack" in s


def test_designations_require_super_admin_reason_effective():
    s = read(SVC / "AcmOfficeService.php")
    for fn in ("designateApprover", "revokeApprover",
               "designateReviewer", "revokeReviewer"):
        assert f"function {fn}" in s, fn
    assert "Only a Super Admin can designate." in s
    assert "Only active accounts can be designated." in s
    assert "already designated" in s


def test_attestation_enforces_independence_and_snapshot_hash():
    s = read(SVC / "AcmOfficeService.php")
    assert "function attest" in s
    assert "isActiveReviewer" in s
    assert "hasOfficeActionsInMonth" in s
    assert "you cannot attest it" in s
    assert "hash_equals" in s
    assert "Regenerate and review again" in s
    assert "REPORT_SCHEMA" in s
    # Corrections append; nothing is edited.
    assert "'correction'" in s and "supersedes_id" in s


# ---- mode-aware decide ----

def test_decide_is_mode_aware_with_staffing_floor():
    s = read(SVC / "AcmTicketService.php")
    assert "array $office = []" in s
    assert "Unknown office mode." in s
    # Separated keeps the P0 submitter rule + staffing floor.
    assert "switch the office to solo mode" in s
    assert "the submitter cannot decide this ticket" in s
    # Solo is designee-only; self-decision needs a reason + flag.
    assert "only a designated approver can decide" in s
    assert "Solo self-approval needs a recorded reason" in s
    assert "self_approved" in s


def test_decide_self_flag_never_touches_separated_path():
    s = read(SVC / "AcmTicketService.php")
    # Dynamic SET fragment: the column is referenced only when set.
    assert "$selfSql" in s
    assert "pre-migration" in s


# ---- API wiring ----

def test_api_exposes_office_actions():
    s = read(API)
    for action in ("office_mode_get", "office_mode_set",
                   "approvers_list", "approvers_designate", "approvers_revoke",
                   "reviewers_list", "reviewers_designate", "reviewers_revoke",
                   "office_users", "office_audit", "office_attest"):
        assert f"case '{action}':" in s, action


def test_api_solo_has_no_super_admin_shortcut():
    s = read(API)
    # Governed break-glass: designate first (reasoned + audited), then decide.
    assert "'is_designee' => $office->isActiveApprover($actorId)" in s
    assert "governed break-glass" in s


def test_api_office_users_is_super_admin_only_and_capped():
    s = read(API)
    assert "case 'office_users':" in s
    block = s.split("case 'office_users':")[1].split("case 'office_audit':")[0]
    assert "isSuperAdmin" in block
    assert "LIMIT 20" in block


def test_attest_request_names_fingerprint_not_hash():
    s = read(API)
    assert "report_fingerprint" in s
    # ...so the P0 audit-leak pin (no hash keys in audit details) holds.


# ---- pages ----

def test_office_and_audit_pages_guard_and_escape():
    for page in (OFFICE_PAGE, AUDIT_PAGE):
        s = read(page)
        assert "ACM_BOOT" in s, page.name
        assert "isLoggedIn()" in s, page.name
        assert "AcmPolicy::consoleRoles()" in s, page.name
        assert "e(" in s or "esc(" in s, page.name
        assert "echo $_GET" not in s, page.name
        assert "<?= $_GET" not in s, page.name


def test_ticket_page_shows_solo_state():
    s = read(TICKET_PAGE)
    assert "AcmOfficeService" in s
    assert "$officeMode" in s and "$soloSelf" in s
    assert "Solo self-approval" in s
    assert "self-approved" in s


# ---- rename completeness ----

def test_old_seat_names_survive_only_in_migration_and_docs():
    hits = []
    for path in list((ROOT / "admin").rglob("*.php")) + list((ROOT / "sql").glob("0*.sql")):
        if path.name == "080_acm_office_model.sql":
            continue
        text = path.read_text(encoding="utf-8")
        if "'registrar'" in text or "'account_activator'" in text or '"registrar"' in text:
            hits.append(str(path.relative_to(ROOT)))
    assert hits == [], hits
