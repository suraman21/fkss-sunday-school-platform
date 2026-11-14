"""ACM S10 staff enrollment pins: request table, grant mint, resolver,
complete branch, console cases, closure, audit vocabulary.

Static pins (no PHP runtime in CI sandbox): the 091 request table
keyed on users.id with the locked status enum, the staff_enroll
grant operation (additive to REGISTRATION_OPERATIONS, never reset),
0-sentinel minting, the resolver's approved+live+active checks, the
completeFull staff branch (no ticket calls, no is_active flip,
request consumed), the beginFull ticket_id-0 rule (staff MUST be 0,
families MUST be >0), issuance-class console policy + network gate,
show-once token handling (fingerprint-only audit), and the
delete/toggle/save closure statements.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MIG = ROOT / "sql" / "091_acm_staff_enrollment_requests.sql"
REQ = ROOT / "acm" / "src" / "Enrollment" / "StaffEnrollmentRequestService.php"
ACT = ROOT / "acm" / "src" / "Activation" / "ActivationService.php"
DEV = ROOT / "acm" / "src" / "Device" / "DeviceService.php"
MOBILE = ROOT / "api" / "v1" / "routes" / "acm.php"
CONSOLE = ROOT / "admin" / "api_acm.php"
PAGE = ROOT / "admin" / "acm_staff_enroll.php"
DELETE = ROOT / "admin" / "backend" / "user-delete.php"
TOGGLE = ROOT / "admin" / "backend" / "user-toggle.php"
SAVE = ROOT / "admin" / "backend" / "user-save.php"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


# ---- 091 table ----

def test_s10_request_table_keyed_on_users_with_locked_enums():
    s = read(MIG)
    assert "CREATE TABLE IF NOT EXISTS `acm_staff_enrollment_requests`" in s
    assert "`user_id` INT UNSIGNED NOT NULL" in s
    body = s.split("CREATE TABLE")[1].split(";")[0]
    assert "acm_persons" not in body  # staff have no persons rows
    assert "person_id" not in body
    assert "'requested','approved','rejected','canceled','expired','consumed'" in s
    assert "MODIFY `operation` ENUM('activation','device_add','reset','staff_enroll')" in s


# ---- request service ----

def test_s10_request_validates_live_staff_and_dupes():
    s = read(REQ)
    assert "function assertEnrollableStaff(" in s
    assert "SELECT is_active, role FROM users WHERE id = :u LIMIT 1" in s
    assert "Staff account is deactivated." in s
    assert "User is not a staff account." in s
    assert "AND status = 'requested' LIMIT 1" in s
    assert "An open enrollment request already exists" in s


def test_s10_decide_is_atomic_with_no_self_approval():
    s = read(REQ)
    body = s.split("public function decide(")[1].split("public function cancel(")[0]
    assert "FOR UPDATE" in body
    assert "Requester cannot approve their own request." in body
    assert "This request has expired." in body
    assert "$grantMinter($req)" in body or "$grantMinter(" in body
    assert "Request was decided concurrently." in body


# ---- grant mint + resolver ----

def test_s10_grant_mint_uses_zero_sentinels():
    s = read(ACT)
    body = s.split("function mintStaffEnrollGrant(")[1].split("/**")[0]
    assert "'id' => 0, 'ticket_id' => 0, 'person_id' => 0" in body
    assert "'staff_enroll'" in body
    assert "Enrollment request is not approvable." in body


def test_s10_resolver_requires_approved_live_request_and_live_staff():
    s = read(ACT)
    body = s.split("function resolveStaffGrant(")[1].split("function grantByFingerprint(")[0]
    assert "acm_staff_enrollment_requests r" in body
    assert "LEFT JOIN users u ON u.id = r.user_id" in body
    assert "r.grant_id = :g" in body
    assert "(string)$row['status'] !== 'approved'" in body
    assert "staff_active" in body
    assert "'ticket_id' => 0" in body


def test_s10_mint_grant_returns_id_for_request_link():
    s = read(ACT)
    assert "'id' => $id," in s  # decide() stores grant_id on the request


# ---- device service ----

def test_s10_registration_operations_add_staff_enroll_only():
    s = read(DEV)
    assert "REGISTRATION_OPERATIONS = ['activation', 'device_add', 'staff_enroll']" in s
    assert "'reset'" not in s.split("REGISTRATION_OPERATIONS")[1].split(";")[0]


def test_s10_begin_grant_rule_pins_ticket_zero_shape():
    s = read(DEV)
    body = s.split("$grantId = null;")[1].split("$grantId = (int)$grant['id'];")[0]
    assert "($grant['operation'] ?? '') === 'staff_enroll'" in body
    assert "(!$staffGrant && (int)$grant['ticket_id'] <= 0)" in body
    assert "($staffGrant && (int)$grant['ticket_id'] !== 0)" in body


def test_s10_complete_branch_consumes_request_without_ticket_or_flip():
    s = read(DEV)
    assert "function lockedStaffRequest(" in s
    lock = s.split("function lockedStaffRequest(")[1].split("function lockedGrant(")[0]
    assert "FOR UPDATE" in lock
    assert "AND status = 'approved'" in lock
    branch = s.split("$operation === 'staff_enroll' && $grant !== null")[1].split("if ($grant !== null)")[0]
    assert "SET status = 'consumed'" in branch
    assert "markActivated" not in branch
    assert "markDeviceRegistered" not in branch
    assert "SET is_active" not in branch  # no account flip (comment text excluded by shape)


# ---- routes + console ----

def test_s10_mobile_request_endpoint_is_self_only_session_authed():
    s = read(MOBILE)
    assert "$acmPath === 'staff/enroll/request'" in s
    body = s.split("$acmPath === 'staff/enroll/request'")[1].split("ok([")[0]
    assert "apiRequireAuth()" in body
    assert "$staffReqCtx->userId" in body


def test_s10_console_cases_use_issuance_policy_network_gate_and_fp_audit():
    s = read(CONSOLE)
    assert "case 'staff_enroll_queue':" in s
    assert "case 'staff_enroll_request':" in s
    assert "case 'staff_enroll_decide':" in s
    assert "case 'staff_enroll_cancel':" in s
    decide = s.split("case 'staff_enroll_decide':")[1].split("case 'staff_enroll_cancel':")[0]
    assert "RES_CODE, 'issue'" in decide
    assert "gatesIssuance()" in decide
    assert "acm.staff_enroll." in decide
    assert "substr((string)($result['grant']['fingerprint'] ?? ''), 0, 6)" in decide
    assert "PLAIN" not in decide.upper() or "Plaintext travels ONLY in this response" in decide
    p = read(PAGE)
    assert "acmApi('staff_enroll_decide'" in p
    assert "ONE-TIME ENROLLMENT TOKEN" in p
    assert "Self-filed" in p


# ---- #38 closure ----

def test_s10_delete_toggle_save_close_devices_sessions_requests_grants():
    stmts = [
        "UPDATE acm_devices SET status = 'revoked' WHERE user_id = ",
        "UPDATE api_refresh_sessions SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP) WHERE user_id = ",
        "UPDATE acm_staff_enrollment_requests SET status = 'canceled' WHERE user_id = ",
        "UPDATE acm_activation_grants g JOIN acm_staff_enrollment_requests r ON r.grant_id = g.id",
    ]
    for f in (DELETE, TOGGLE, SAVE):
        s = read(f)
        for stmt in stmts:
            assert stmt in s, (f.name, stmt[:40])
    t = read(TOGGLE)
    assert "if ($newStatus === 0)" in t  # 1→0 only; 0→1 revives nothing
    sv = read(SAVE)
    assert "if ($isActive === 0 && $wasActive)" in sv
