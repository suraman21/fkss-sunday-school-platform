"""ACM S7 lifecycle pins: suspend/reactivate guards, order, audit, console.

Static pins (no PHP runtime in CI sandbox): 089 history-table
shape, policy verbs, member/parent-only target guard, revoke-
first order, fail-closed without a revoker, in-transaction
state verification under lock, mandatory reason caps,
reviewer-visible acm.-prefixed audit, POST-only policy-gated
console cases, person-page buttons with an inline reason (no
native dialogs), and the mobile route provably untouched.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SQL89 = ROOT / "sql" / "089_acm_account_suspensions.sql"
PROV = ROOT / "acm" / "src" / "Accounts" / "AccountProvisioner.php"
POLICY = ROOT / "acm" / "src" / "Policy" / "AcmPolicy.php"
API = ROOT / "admin" / "api_acm.php"
PERSON = ROOT / "admin" / "acm_person.php"
MOBILE = ROOT / "api" / "v1" / "routes" / "acm.php"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


def suspend_body() -> str:
    s = read(PROV)
    return s.split("public function suspend")[1].split("public function reactivate")[0]


def reactivate_body() -> str:
    s = read(PROV)
    return s.split("public function reactivate")[1].split("private function isSuspendedState")[0]


# ---- 089 schema: history table only ----

def test_s7_migration_creates_history_table_idempotently():
    s = read(SQL89)
    code = "\n".join(ln for ln in s.splitlines() if not ln.lstrip().startswith("--"))
    assert "CREATE TABLE IF NOT EXISTS `acm_account_suspensions`" in s
    assert "FOREIGN KEY" not in code
    assert "`action` ENUM('suspended','reactivated') NOT NULL" in s
    assert "`account_type` ENUM('member','parent') NOT NULL" in s
    assert "`reason` VARCHAR(2000) NOT NULL" in s
    assert "KEY `idx_acm_susp_user` (`user_id`)" in s
    assert "KEY `idx_acm_susp_created` (`created_at`)" in s
    # One table, nothing else: no seeds, no ENUM widening.
    assert code.count("CREATE TABLE") == 1
    assert "INSERT INTO" not in code
    assert "MODIFY COLUMN" not in code


# ---- policy verbs + target guard ----

def test_s7_policy_grants_lifecycle_verbs_to_the_office():
    s = read(POLICY)
    assert "'account'  => ['view', 'suspend', 'reactivate']" in s
    # Deny-by-default intact (unknown => false, super_admin bypass).
    assert "?? null" in s and "return false" in s
    assert "if ($role === 'super_admin')" in s


def test_s7_only_member_and_parent_accounts_are_lifecycle_targets():
    s = read(PROV)
    assert "$row['role'] !== 'member' && $row['role'] !== 'parent'" in s
    assert "Staff and officer accounts are managed in user administration." in s


# ---- revoke-first order + fail-closed ----

def test_s7_suspend_revokes_before_opening_the_transaction():
    body = suspend_body()
    revoke = body.find("($this->sessionRevoker)($userId)")
    assert revoke > 0
    assert revoke < body.find("beginTransaction")
    # ... and after every guard (policy, reason, revoker, target).
    assert body.find("requireLifecycleVerb($ctx, 'suspend')") < revoke
    assert body.find("cleanReason($reason)") < revoke
    assert body.find("$this->sessionRevoker === null") < revoke
    assert body.find("suspendableTarget($userId)") < revoke


def test_s7_suspend_fails_closed_without_a_revoker():
    assert "?callable $sessionRevoker = null" in read(PROV)  # S4/S6 precedent
    body = suspend_body()
    assert "$this->sessionRevoker === null" in body
    assert "Account suspension is unavailable." in body
    # Console wires the mysqli revoker (fails closed without $conn).
    s = read(API)
    assert "$consoleSessionRevoker = null" in s
    # S8 appends the device revoker (3rd arg); the session revoker
    # wiring this pin guards is unchanged.
    assert "new AccountProvisioner($pdo, $consoleSessionRevoker, $consoleDeviceRevoker)" in s
    assert "MysqliCredentialRepository($conn))->revokeAllRefreshSessions($userId)" in s


# ---- state verification under lock ----

def test_s7_state_is_verified_inside_the_transaction_under_lock():
    for body, verb in ((suspend_body(), "suspended"), (reactivate_body(), "reactivated")):
        txn = body.find("beginTransaction")
        assert txn > 0
        assert body.find("lockedLink($userId)", txn) > txn
        assert "FOR UPDATE" in read(PROV)
        assert body.find("insertHistory($userId", txn) > txn
        assert body.find("->commit()", txn) > txn
        assert "inTransaction" in body  # guarded rollback (audit rule)


def test_s7_suspend_and_reactivate_use_conditional_flips():
    s = read(PROV)
    # (SQL spans concatenated PHP strings with quote escapes; pin
    # the de-escaped SQL text, which is what actually runs.)
    flat = s.replace(chr(92), "")
    assert "SET status = 'suspended'" in flat and "AND status = 'active'" in flat
    assert "SET is_active = 0 WHERE id = :u AND is_active = 1" in flat
    assert "SET status = 'active'" in flat and "AND status = 'suspended'" in flat
    assert "SET is_active = 1 WHERE id = :u AND is_active = 0" in flat


def test_s7_reactivate_converges_suspended_and_drifted_states():
    s = read(PROV)
    helper = s.split("private function isSuspendedState")[1].split("private function suspendableTarget")[0]
    assert "=== 'suspended'" in helper
    assert "=== 'active'" in helper and "is_active" in helper  # drifted: active link + gated bit
    # Pending/revoked links are never suspended states.
    assert "pending" not in helper and "revoked" not in helper


def test_s7_reason_is_mandatory_and_capped():
    s = read(PROV)
    helper = s.split("private function cleanReason")[1].split("private function linkRow")[0]
    assert "trim($reason)" in helper
    assert "Reason is required." in helper
    assert "mb_strlen($reason) > 2000" in helper
    assert "Reason is too long" in helper


# ---- reviewer-visible audit + console cases ----

def test_s7_audit_actions_carry_the_acm_prefix():
    s = read(API)
    assert s.count("'acm.account.suspended'") == 1
    assert s.count("'acm.account.reactivated'") == 1
    assert "'username' => $result['username']" in s
    assert "'reason' => trim((string)($_POST['reason'] ?? ''))" in s
    assert "'user', $result['user_id']" in s


def test_s7_console_cases_are_post_only_and_policy_gated():
    s = read(API)
    for case, verb in (("account_suspend", "suspend"), ("account_reactivate", "reactivate")):
        body = s.split(f"case '{case}'")[1].split("case '")[0]
        assert "$method !== 'POST'" in body
        assert f"AcmPolicy::can($role, AcmPolicy::RES_ACCOUNT, '{verb}')" in body
        assert "new AccountContext($actorId, $role)" in body
    assert "new AccountContext($actorId, $role)" in s  # never from client input


def test_s7_person_page_buttons_use_an_inline_reason():
    s = read(PERSON)
    assert 'id="acmLifecycleReason"' in s and 'maxlength="2000"' in s
    assert 'data-acm-lifecycle="account_suspend"' in s
    assert 'data-acm-lifecycle="account_reactivate"' in s
    assert "data-acm-user=" in s
    assert "Reason is required." in s  # client-side pre-check mirrors the service
    assert "confirm(" not in s and "prompt(" not in s  # no native dialogs
    assert "$canSuspend" in s and "$canReactivate" in s
    assert "['member', 'parent']" in s  # buttons on member/parent rows only


# ---- mobile surface untouched ----

def test_s7_mobile_route_is_untouched():
    s = read(MOBILE)
    assert "suspend" not in s
    assert "reactivat" not in s
    assert "account_suspend" not in s and "account_reactivate" not in s
