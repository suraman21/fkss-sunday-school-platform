"""ACM S6 recovery pins: reset purpose, operation binding, crash order, wire.

Static pins (no PHP runtime in CI sandbox): 088 append-only ENUM,
reset opened in service + console, operation binding in BOTH
directions (reset-at-activation refused, activation-at-recovery
refused), validate-before-consume order, idempotency-before-verdicts,
crash-continuation markers, idempotent closer, single honest audit
action, strict wire + gates, officer=issued_by attribution, ACTIVE-
only resolution, and zero new wire error codes.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SQL88 = ROOT / "sql" / "088_acm_recovery_events.sql"
SVC = ROOT / "acm" / "src" / "Activation" / "ActivationService.php"
TICKETS = ROOT / "acm" / "src" / "Accounts" / "AcmTicketService.php"
MOBILE = ROOT / "api" / "v1" / "routes" / "acm.php"
API = ROOT / "admin" / "api_acm.php"
PAGE = ROOT / "admin" / "acm_ticket.php"

# Every machine code completeRecovery may raise. A new code here is a
# wire-contract change and must fail until the contract is reviewed.
RECOVERY_CODES = {
    "GRANT_INVALID", "GRANT_REVOKED", "GRANT_CONSUMED", "GRANT_EXPIRED",
    "IDEMPOTENT_CONFLICT", "TICKET_STATE", "WEAK_PASSWORD", "INVALID_REQUEST",
}


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


def recovery_body() -> str:
    s = read(SVC)
    return s.split("public function completeRecovery")[1].split("private function recoveryResult")[0]


# ---- 088 schema: ENUM append only ----

def test_s6_migration_appends_recovered_last_and_nothing_else():
    s = read(SQL88)
    code = "\n".join(ln for ln in s.splitlines() if not ln.lstrip().startswith("--"))
    assert "CREATE TABLE" not in code
    assert "FOREIGN KEY" not in code
    # Guarded, idempotent re-run (084 precedent): no-op when present.
    assert "information_schema" in s
    assert "column_type NOT LIKE '%recovered%'" in s
    # Append-only: recovered lands AFTER device_revoked (last), the
    # pre-S6 tail order is preserved, existing indexes survive.
    assert "''activation_failed'',''device_registered'',''device_revoked'',''recovered'') NOT NULL" in s


# ---- reset purpose opened (service + console) ----

def test_s6_issue_code_opens_reset_but_keeps_unknown_closed():
    s = read(SVC)
    gate = s.split("public function issueCode")[1].split("public function verifyCode")[0]
    assert "CodePurpose::Reset" in gate
    assert "not available in this increment" in gate  # unknown futures stay closed


def test_s6_console_issues_and_displays_reset():
    assert "$purposeRaw !== 'reset'" in read(API)
    assert "CodePurpose::Reset" in read(API)
    assert '<option value="reset">' in read(PAGE)


# ---- operation binding, both directions ----

def test_s6_operation_binding_rejects_cross_purpose_grants():
    s = read(SVC)
    activation = s.split("public function establishCredentials")[1].split(
        "public function completeRecovery")[0]
    assert "!== CodePurpose::Activation->value" in activation  # reset grant at activation
    assert "GRANT_INVALID" in activation
    body = recovery_body()
    assert "!== CodePurpose::Reset->value" in body  # activation/device_add grant at recovery
    assert "no" in body and "oracle" in body  # generic message, documented


# ---- validate-before-consume + idem-before-verdicts ----

def test_s6_password_validated_before_any_grant_burn():
    body = recovery_body()
    consume = body.find("UPDATE acm_activation_grants SET consumed_at")
    assert consume > 0
    assert body.find("hash_equals($newPassword, $confirmPassword)") not in (-1,)
    assert body.find("hash_equals($newPassword, $confirmPassword)") < consume
    assert body.find("PasswordPolicy::errors($newPassword)") < consume


def test_s6_idempotency_checked_before_consumed_and_expired_verdicts():
    body = recovery_body()
    prior = body.find("findValid($idempotencyKey)")
    assert prior > 0
    assert prior < body.find("GRANT_CONSUMED")
    assert prior < body.find("GRANT_EXPIRED")
    assert "IDEMPOTENT_CONFLICT" in body


# ---- crash continuation + idempotent closer ----

def test_s6_same_key_same_grant_replays_or_resumes():
    body = recovery_body()
    assert "hasRecoveredEvent($ticketId, $fpShort)" in body
    assert "recoveryResult($grant, $prior, true)" in body  # replay path
    assert "$resuming = true" in body  # resume path
    assert "'23000'" in body  # concurrent-winner net
    assert "findValid($idempotencyKey)" in body
    assert "markRecovered($ticketId" in body  # PDO-B close


def test_s6_closer_is_idempotent_for_same_fingerprint():
    s = read(TICKETS)
    closer = s.split("public function markRecovered")[1].split("public function markExpired")[0]
    assert "LOCATE(:m, e.meta)" in closer  # literal fp match, no LIKE semantics
    assert "return;" in closer  # already-recovered replay converges
    assert "transition($id, ['grant_verified'], 'active', 0, 'recovered'" in closer


def test_s6_password_writer_is_injected_and_fail_closed():
    s = read(SVC)
    assert "?callable $passwordResetter = null" in s  # S4 revoker precedent
    body = recovery_body()
    assert "$this->passwordResetter === null" in body
    assert body.find("$this->passwordResetter === null") < body.find(
        "UPDATE acm_activation_grants SET consumed_at")  # 503 before any burn
    assert "($this->passwordResetter)(" in body
    # The service never writes passwords itself: no hash, no users UPDATE.
    assert "password_hash(" not in body
    assert "UPDATE users" not in body


# ---- ACTIVE-only resolution + honest officer attribution ----

def test_s6_recovery_resolves_active_accounts_only():
    body = recovery_body()
    assert "userIdFor((int)$ticket['person_id'], $accountType)" in body
    assert "userIsActive($userId)" in body
    assert "SELECT is_active FROM users WHERE id = :u LIMIT 1" in read(SVC)
    assert "TICKET_STATE" in body  # pending/suspended/deactivated refuse


def test_s6_officer_attribution_comes_from_the_code_issuer():
    assert "c.issued_by AS id" in read(SVC)  # codeIssuer reads the code row
    body = recovery_body()
    assert "codeIssuer((int)$grant['code_id'])" in body
    assert "(int)$issuer['id'], (int)$resolution['user_id'], $newPassword" in body
    assert "fromTrustedAuthorization($officerId)" in read(MOBILE)
    assert "'officer_id' =>" in read(MOBILE) or '"officer_id"' in read(MOBILE) or \
        "$recovered['officer_id']" in read(MOBILE)


# ---- wire: strict bodies, caps, gates, audit, zero new codes ----

def test_s6_mobile_endpoints_are_strict_capped_and_gated():
    s = read(MOBILE)
    assert "$acmAction === 'reset/verify'" in s
    assert "$acmAction === 'reset/credentials'" in s
    assert "acmStrictBody(['selector', 'secret', 'purpose'])" in s  # default cap 512
    assert "acmStrictBody(['grant', 'new_password', 'confirm_password', 'idempotency_key'], 4096)" in s
    assert "isApiRateLimited('acm_reset_verify', 30)" in s
    assert "isApiRateLimited('acm_reset_credentials', 10)" in s
    assert "verifyCode(" in s and "CodePurpose::Reset" in s
    assert "completeRecovery(" in s
    assert "acmWireError($e, $body['new_password'])" in s  # policy text recomputed


def test_s6_exactly_one_honest_audit_action():
    s = read(MOBILE)
    assert s.count("'PASSWORD_RECOVERED'") == 1  # quoted = code use (comment is bare)
    assert "!$recovered['replayed']" in s  # replays never re-audit
    assert "'operation' => 'acm_credential_recovery'" in s
    assert "'ticket_id' => (int)$recovered['ticket_id']" in s
    assert "'grant_fp' =>" in s and "'...'" in s  # truncated fingerprint, S3 shape
    assert "recordTrusted(" in s
    assert "'user'," in s and "(int)$recovered['user_id']" in s  # target = recovered user


def test_s6_zero_new_wire_error_codes():
    body = recovery_body()
    raised = set(re.findall(r"ActivationException::([A-Z_]+)", body))
    assert raised, "pin must observe raised codes"
    assert raised <= RECOVERY_CODES, f"new wire code: {raised - RECOVERY_CODES}"
    s = read(MOBILE)
    verify = s.split("$acmAction === 'reset/verify'")[1].split(
        "$acmAction === 'reset/credentials'")[0]
    # Next endpoint matches on $acmPath (S4 three-level paths), not $acmAction.
    creds = s.split("$acmAction === 'reset/credentials'")[1].split("$acmPath ===")[0]
    literal = set(re.findall(r"'code' => '([a-z_]+)'", verify + creds))
    assert literal <= {"rate_limited", "code_invalid", "invalid_request"}, literal


def test_s6_reset_uses_the_shared_administrator_primitive():
    s = read(MOBILE)
    assert "resetPasswordByAdministrator(" in s
    assert "AuthorizedAdministratorIdentity::fromTrustedAuthorization($officerId)" in s
    assert "MysqliCredentialRepository($conn)" in s
