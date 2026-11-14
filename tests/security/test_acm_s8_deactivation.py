"""ACM S8 deactivation pins: terminal revoke, order, staff guards, console.

Static pins (no PHP runtime in CI sandbox): 090 ENUM append-only
shape, super_admin-only deactivate (deliberate matrix absence),
member/parent-only target guard, pending/revoked refusals,
devices-before-sessions-before-transaction order, fail-closed
without revokers, in-transaction state verification under lock,
reviewer-visible acm.-prefixed audit, POST-only policy-gated
console case, person-page button with type-username confirm (no
native dialogs), staff-writer guards (delete/toggle/save), the
mobile route provably untouched, and zero new wire codes.
"""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
SQL90 = ROOT / "sql" / "090_acm_deactivation_events.sql"
PROV = ROOT / "acm" / "src" / "Accounts" / "AccountProvisioner.php"
POLICY = ROOT / "acm" / "src" / "Policy" / "AcmPolicy.php"
API = ROOT / "admin" / "api_acm.php"
PERSON = ROOT / "admin" / "acm_person.php"
MOBILE = ROOT / "api" / "v1" / "routes" / "acm.php"
TOGGLE = ROOT / "admin" / "backend" / "user-toggle.php"
SAVE = ROOT / "admin" / "backend" / "user-save.php"
DELETE = ROOT / "admin" / "backend" / "user-delete.php"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


def deactivate_body() -> str:
    s = read(PROV)
    return s.split("public function deactivate")[1].split("private function requireDeactivatable")[0]


# ---- 090 schema: ENUM append only ----

def test_s8_migration_appends_deactivated_last_and_nothing_else():
    s = read(SQL90)
    code = "\n".join(ln for ln in s.splitlines() if not ln.lstrip().startswith("--"))
    assert "CREATE TABLE" not in code.upper()
    assert "deactivated" in code
    # Existing order preserved, new value LAST (084/088 precedent).
    m = re.search(r"ENUM\(''suspended'',''reactivated'',''deactivated''\)", code)
    assert m is not None
    # Re-runnable guard + no seeds.
    assert "information_schema" in code
    assert "NOT LIKE '%deactivated%'" in code
    assert "INSERT" not in code.upper()


# ---- policy: super_admin-only via deliberate matrix absence ----

def test_s8_deactivate_absent_from_office_matrix_with_pin_comment():
    s = read(POLICY)
    assert "super_admin" in s  # the bypass line exists
    line = next(ln for ln in s.splitlines() if "'account'" in ln and "=>" in ln)
    assert "deactivate" not in line  # the verb row: view/suspend/reactivate only
    assert "S8" in s and "super_admin-only" in s


# ---- provisioner: guards, order, transaction ----

def test_s8_deactivate_requires_verb_reason_and_both_revokers():
    b = deactivate_body()
    assert 'requireLifecycleVerb($ctx, \'deactivate\')' in b
    assert "cleanReason($reason)" in b
    assert "$this->sessionRevoker === null || $this->deviceRevoker === null" in b
    assert "Account deactivation is unavailable." in b
    # Guard order: verb+reason+revokers BEFORE the target read.
    assert b.index("sessionRevoker === null") < b.index("suspendableTarget($userId)")


def test_s8_deactivate_target_roles_and_state_refusals():
    b = deactivate_body()
    assert "suspendableTarget($userId)" in b  # existence + member/parent-only
    assert "requireDeactivatable((string)$target['status'])" in b
    assert "requireDeactivatable((string)$locked['status'])" in b  # under lock too
    g = read(PROV).split("private function requireDeactivatable")[1].split("private function isSuspendedState")[0]
    assert "Account is already deactivated." in g
    assert "Pending accounts finish activation or cancel first." in g
    assert "Account is not active." in g


def test_s8_deactivate_revokes_devices_then_sessions_then_transacts():
    b = deactivate_body()
    assert "($this->deviceRevoker)($userId, $reason)" in b
    assert "($this->sessionRevoker)($userId)" in b
    assert "$this->pdo->beginTransaction();" in b
    assert "lockedLink($userId)" in b
    assert b.index("deviceRevoker)($userId") < b.index("sessionRevoker)($userId")
    assert b.index("sessionRevoker)($userId") < b.index("beginTransaction()")


def test_s8_deactivate_flips_are_conditional_and_locked():
    b = deactivate_body()
    assert "SET status = 'revoked'" in b
    assert "status IN ('active','suspended')" in b
    assert "SET is_active = 0 WHERE id = :u AND is_active = 1" in b
    assert "'deactivated'" in b and "insertHistory(" in b
    assert "inTransaction()" in b and "rollBack()" in b
    # Verified under FOR UPDATE (lockedLink), never a blind flip.
    assert "FOR UPDATE" in read(PROV)


def test_s8_reactivate_still_refuses_revoked_links():
    s = read(PROV)
    g = s.split("private function isSuspendedState")[1].split("private function suspendableTarget")[0]
    assert "'suspended'" in g and "'active'" in g
    assert "'revoked'" not in g  # revoked never matches: terminal means terminal


# ---- console case + audit ----

def test_s8_console_case_is_post_only_policy_gated_and_audited():
    s = read(API)
    case = s.split("case 'account_deactivate'")[1].split("default:")[0]
    assert "Method not allowed." in case
    assert "AcmPolicy::can($role, AcmPolicy::RES_ACCOUNT, 'deactivate')" in case
    assert "$provisioner->deactivate(" in case
    assert "acm_audit($pdo, 'acm.account.deactivated'" in case
    assert "'devices' => $result['devices']" in case
    assert "acm_ok(['deactivated' => true" in case


def test_s8_console_device_closure_loops_idempotent_revoke():
    s = read(API)
    assert "$consoleDeviceRevoker = null;" in s
    assert "if ($consoleRevoker !== null)" in s  # fails closed without a handle
    assert "listForUser($userId)" in s
    assert "revokeWithReport($officer" in s
    assert "new AccountProvisioner($pdo, $consoleSessionRevoker, $consoleDeviceRevoker)" in s


# ---- person page: super_admin-only button + type-username confirm ----

def test_s8_person_page_deactivate_button_and_confirm():
    s = read(PERSON)
    assert "AcmPolicy::can($role, 'account', 'deactivate')" in s
    assert 'data-acm-lifecycle="account_deactivate"' in s
    assert "data-acm-confirm" in s and "acmDeactivateConfirm" in s
    assert "Type the username to confirm deactivation." in s
    assert "confirm(" not in s and "prompt(" not in s  # inline only, no native dialogs


# ---- staff guards (#26): delete / toggle / save ----

def test_s8_delete_refuses_acm_managed_users():
    s = read(DELETE)
    assert "SELECT status FROM acm_person_accounts WHERE user_id" in s
    assert "Deactivate this account in the ACM console first" in s
    assert "42S02" in s and "42S22" in s  # pre-ACM tolerant
    # Unexpected guard failures rethrow into the outer catch
    # (rollback + "no changes") -- fail closed, never proceed.
    assert "No changes were made" in s


def test_s8_toggle_refuses_resurrecting_revoked_links():
    s = read(TOGGLE)
    assert "if ($newStatus === 1)" in s  # 0->1 only; 1->0 stays unguarded
    assert "SELECT status FROM acm_person_accounts WHERE user_id" in s
    assert "=== 'revoked'" in s
    assert "cannot be re-enabled; start a fresh activation." in s
    assert "42S02" in s and "42S22" in s


def test_s8_save_refuses_resurrecting_revoked_links():
    s = read(SAVE)
    assert "(int)$prevRow['is_active'] === 0 && (int)$isActive === 1" in s
    assert "SELECT status FROM acm_person_accounts WHERE user_id" in s
    assert "=== 'revoked'" in s
    assert "cannot be re-enabled; start a fresh activation." in s
    assert "42S02" in s and "42S22" in s


# ---- untouched surface: mobile + wire codes ----

def test_s8_mobile_route_and_ceremonies_untouched():
    assert "deactivat" not in read(MOBILE).lower()
    prov = read(PROV)
    # No new wire vocabulary anywhere in S8 code.
    for needle in ("DEVICE_PROOF_", "PASSWORD_", "TICKET_STATE", "grant"):
        assert needle not in deactivate_body(), needle
    assert "DELETE FROM" not in prov  # step 14 owns purges; nothing hard-deleted
