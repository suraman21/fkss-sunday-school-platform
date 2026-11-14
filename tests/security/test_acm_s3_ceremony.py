"""ACM S3 ceremony pins: purpose-bound codes, grants, credentials, signal.

Static pins (no PHP runtime in CI sandbox): schema shape, atomicity
markers, purpose isolation, no-secret handling, error-code stability,
and endpoint wiring for the activation ceremony.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SQL82 = ROOT / "sql" / "082_acm_activation_ceremony.sql"
ACMSRC = ROOT / "acm" / "src"
SVC = ACMSRC / "Activation" / "ActivationService.php"
ALPHA = ACMSRC / "Activation" / "CodeAlphabet.php"
EXC = ACMSRC / "Activation" / "ActivationException.php"
IDEM = ACMSRC / "Activation" / "IdempotencyStore.php"
TICKETS = ACMSRC / "Accounts" / "AcmTicketService.php"
SIGNAL = ACMSRC / "OfficeNetwork" / "OfficeNetworkSignal.php"
POLICY = ACMSRC / "Policy" / "AcmPolicy.php"
API = ROOT / "admin" / "api_acm.php"
PAGE = ROOT / "admin" / "acm_ticket.php"
MOBILE = ROOT / "api" / "v1" / "routes" / "acm.php"
ROUTER = ROOT / "api" / "v1" / "index.php"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


# ---- 082 schema ----

def test_s3_migration_is_idempotent_and_fk_free():
    s = read(SQL82)
    code = "\n".join(ln for ln in s.splitlines() if not ln.lstrip().startswith("--"))
    assert "FOREIGN KEY" not in code
    for table in ("acm_activation_codes", "acm_activation_grants",
                  "acm_activation_attempts", "acm_idempotency_keys",
                  "acm_office_networks"):
        assert f"CREATE TABLE IF NOT EXISTS `{table}`" in s, table
    # ENUM widening is guarded (re-runnable) and append-only.
    assert "information_schema" in s
    assert "column_type NOT LIKE '%activation_failed%'" in s


def test_s3_migration_appends_states_without_reordering():
    s = read(SQL82)
    # The ALTER sits inside a quoted string (doubled quotes) — normalize first.
    m = re.search(r"MODIFY COLUMN `status` ENUM\((.*?)\) NOT NULL",
                  s.replace("''", "'"))
    assert m, "status widen missing"
    values = [v.strip("'") for v in m.group(1).split("','")]
    legacy = ['draft', 'submitted', 'in_review', 'changes_requested',
              'approved', 'rejected', 'provisioned', 'archived']
    assert values[:8] == legacy  # existing order + indexes kept
    for new in ('code_issued', 'grant_verified', 'account_pending_device',
                'active', 'canceled', 'expired', 'revoked',
                'activation_failed'):
        assert new in values, new


def test_s3_migration_stores_hashes_and_keys_not_secrets():
    s = read(SQL82)
    assert "`secret_hash`" in s and "`grant_hash`" in s
    assert "UNIQUE KEY `uniq_acm_code_selector`" in s
    assert "UNIQUE KEY `uniq_acm_grant_fp`" in s
    assert "PRIMARY KEY (`idem_key`)" in s
    assert "PRIMARY KEY (`selector`,`ip`)" in s
    # No plaintext secret/token column anywhere in the ceremony tables.
    assert re.search(r"`secret`|`token`|`password`", s) is None
    # Enforcement flag seeded OFF (D3.7: until CIDRs recorded + tested).
    assert "'acm.office_network_enforced', '0'" in s
    assert "'acm.trusted_proxies', ''" in s


# ---- code alphabet (D3.2) ----

def test_code_alphabet_excludes_ambiguous_chars():
    s = read(ALPHA)
    m = re.search(r"ALPHABET = '([A-Za-z0-9]+)'", s)
    assert m
    alphabet = m.group(1)
    assert len(alphabet) == 30 and len(set(alphabet)) == 30
    for ambiguous in "0O1IL":
        assert ambiguous not in alphabet, ambiguous
    assert "SELECTOR_LEN = 6" in s and "SECRET_LEN = 8" in s
    assert "random_bytes" in s  # rejection sampling over random_bytes
    assert "prefixFor" in s and "=> 'A'" in s and "=> 'D'" in s and "=> 'R'" in s


# ---- error codes (D4.3) ----

def test_error_codes_are_stable_machine_strings():
    s = read(EXC)
    expected = {
        "CODE_INVALID": "code_invalid",
        "CODE_EXPIRED": "code_expired",
        "CODE_CONSUMED": "code_consumed",
        "PURPOSE_MISMATCH": "purpose_mismatch",
        "GRANT_INVALID": "grant_invalid",
        "GRANT_EXPIRED": "grant_expired",
        "GRANT_CONSUMED": "grant_consumed",
        "GRANT_REVOKED": "grant_revoked",
        "ACCOUNT_EXISTS": "account_exists",
        "RATE_LIMITED": "rate_limited",
        "IDEMPOTENT_CONFLICT": "idempotent_conflict",
        "NETWORK_DENIED": "network_denied",
        "TICKET_STATE": "ticket_state",
        "WEAK_PASSWORD": "weak_password",
        "INVALID_REQUEST": "invalid_request",
    }
    for const, value in expected.items():
        assert f"public const {const} " in s and f"= '{value}';" in s, const
    # The seam still throws InvalidArgumentException (S2 contract holds).
    assert "extends \\InvalidArgumentException" in s
    assert "function machineCode()" in s and "function httpStatus()" in s


# ---- issue (D3.2/D4.4) ----

def test_issue_is_atomic_with_reissue_invalidation():
    s = read(SVC)
    # PHP single-quoted SQL escapes quotes (SET status = \'x\') — unescape.
    body = s.split("public function issueCode")[1].split("public function verifyCode")[0]
    body = body.replace("\\'", "'")
    assert "beginTransaction" in body and "commit()" in body and "rollBack" in body
    assert "FOR UPDATE" in body  # ticket + previous code locked
    assert "status = 'revoked'" in body  # previous active code dies
    assert "reissue_of" in body
    assert "revokeCeremonyGrants" in body  # no second live grant
    assert "password_hash($secret, PASSWORD_DEFAULT)" in body  # hash-only
    assert "markCodeIssued" in body
    # Plaintext leaves only via the IssuedCode return.
    assert "return new IssuedCode($selector, $secret" in body


def test_issue_is_permission_and_state_gated():
    s = read(SVC)
    body = s.split("public function issueCode")[1].split("public function verifyCode")[0]
    assert "AcmPolicy::can($ctx->role, AcmPolicy::RES_CODE, 'issue')" in body
    assert "ISSUABLE_STATUSES" in body
    assert "TICKET_STATE" in body


# ---- verify (D3.3/D4.5) ----

def test_verify_fails_generic_everywhere_no_oracle():
    s = read(SVC)
    body = s.split("public function verifyCode")[1].split("public function establishCredentials")[0]
    # Every code-validity failure funnels through the one generic failure.
    assert body.count("verifyFailed()") >= 3  # + failRedemption below
    fail = s.split("private function failRedemption")[1].split("private function lockedCode")[0]
    assert "throw ActivationException::verifyFailed();" in fail
    for specific in ("CODE_EXPIRED", "CODE_CONSUMED", "PURPOSE_MISMATCH",
                     "GRANT_INVALID", "ACCOUNT_EXISTS"):
        assert specific not in body, specific
    # Purpose column is enforced (request purpose vs stored purpose).
    assert "$code['purpose'] !== $purpose->value" in body


def test_verify_throttles_pair_and_ticket_before_burn():
    s = read(SVC)
    assert "PAIR_LIMIT = 3" in s and "PAIR_WINDOW_MIN = 15" in s
    assert "TICKET_VELOCITY_LIMIT = 30" in s
    assert "acm_activation_attempts" in s
    assert "ON DUPLICATE KEY UPDATE" in s  # lazy pair window
    assert "password_verify($secret" in s
    # Burn is a status update, never a delete.
    assert "DELETE FROM acm_activation_codes" not in s
    assert "'code_burned'" in s


def test_verify_consumes_code_and_mints_grant_in_one_transaction():
    s = read(SVC)
    body = s.split("public function verifyCode")[1].split("public function establishCredentials")[0]
    body = body.replace("\\'", "'")
    assert "status = 'consumed', consumed_at = NOW()" in body
    assert "rowCount() !== 1" in body  # race loser fails generic
    assert "mintGrant($code, $purpose->value)" in body
    mint = s.split("private function mintGrant")[1].split("private function grantByFingerprint")[0]
    assert "random_bytes(32)" in mint  # grant entropy
    assert "password_hash($token, PASSWORD_DEFAULT)" in mint  # hash-only
    assert "substr(hash('sha256', $token), 0, 12)" in mint  # fingerprint lookup
    assert "markGrantVerified" in body
    assert body.count("beginTransaction") == 1 and "commit()" in body


def test_time_comparisons_stay_in_sql():
    for path in (SVC, IDEM):
        s = read(path)
        assert "gmdate" not in s and "strtotime" not in s
        # Word-boundary: getForUpdate() must not trip this pin.
        assert re.search(r"\bdate\s*\(", s) is None
        assert re.search(r"\btime\s*\(", s) is None
        assert "expires_at > NOW()" in s


# ---- credentials (D3.3/D3.4) ----

def test_grant_is_bound_checked_and_consumed_atomically():
    s = read(SVC)
    body = s.split("public function establishCredentials")[1].split("public function codeStatus")[0]
    assert "password_verify($grantToken" in body
    assert "$grant['operation'] !== CodePurpose::Activation->value" in body
    assert "GRANT_REVOKED" in body and "GRANT_CONSUMED" in body and "GRANT_EXPIRED" in body
    assert "consumed_at IS NULL" in body and "revoked_at IS NULL" in body
    assert "rowCount() !== 1" in body  # single-use: one winner


def test_idempotency_is_checked_before_consumption():
    s = read(SVC)
    body = s.split("public function establishCredentials")[1].split("public function codeStatus")[0]
    assert body.index("findValid($idempotencyKey)") < body.index("SET consumed_at = NOW()")
    # ...and before the consumed/expired verdicts: after a success the
    # grant IS consumed, so an uncertain-response retry must replay.
    assert body.index("findValid($idempotencyKey)") < body.index("GRANT_CONSUMED, 409")
    assert body.index("findValid($idempotencyKey)") < body.index("GRANT_EXPIRED, 410")
    assert "hash_equals((string)$prior['grant_fingerprint'], $fingerprint)" in body
    assert "IDEMPOTENT_CONFLICT" in body
    assert "IDEMPOTENCY_TTL_H = 48" in s  # retained >= 24 h
    # Key format is a UUID (D3.3).
    assert "idempotency key must be a UUID" in body


def test_password_uses_policy_single_field_and_repo_hash():
    s = read(SVC)
    assert "PasswordPolicy::errors($password)" in s  # reused, never reimplemented
    assert "WEAK_PASSWORD" in s
    assert "password_hash($password, PASSWORD_DEFAULT)" in s
    m = read(MOBILE)
    assert "acmStrictBody(['grant', 'password', 'idempotency_key']" in m
    assert "'confirm'" not in m  # confirmation is app-UI-only


def test_existing_account_blocks_without_merge_or_duplicate():
    s = read(SVC)
    body = s.split("public function establishCredentials")[1].split("public function codeStatus")[0]
    assert "hasAccount((int)$ticket['person_id'], $accountType)" in body
    assert "failActivation" in body and "ACCOUNT_EXISTS" in body
    assert "Never auto-merge" in s


def test_provisioning_commits_with_grant_and_transition():
    s = read(SVC)
    # S6: completeRecovery sits between establishCredentials and
    # codeStatus; the single-txn pin covers establishCredentials only.
    body = s.split("public function establishCredentials")[1].split("public function completeRecovery")[0]
    assert body.count("beginTransaction") == 1
    assert "->provision(" in body
    assert "markAccountPendingDevice" in body
    assert "->save(" in body  # idempotency row in the same txn


# ---- office-network signal (D3.7) ----

def test_proxy_resolution_is_allowlisted_header_only():
    s = read(SIGNAL)
    assert "HTTP_CF_CONNECTING_IP" in s
    assert "HTTP_X_FORWARDED_FOR" not in s  # no blind XFF trust
    assert "REMOTE_ADDR" in s
    assert "acm.trusted_proxies" in s
    assert "return null" in s  # unknown, never guessed


def test_enforcement_defaults_off_and_fails_closed():
    s = read(SIGNAL)
    assert "acm.office_network_enforced') === '1'" in s  # missing = OFF
    assert "acm_office_networks" in s and "active = 1" in s
    body = s.split("public function allows(")[1].split("public function activeNetworks")[0]
    assert body.count("return false") >= 2  # unknown IP + no match
    assert "ipInCidr" in s and "inet_pton" in s  # v4 + v6


def test_redemption_is_gated_issuance_is_deploy_policy():
    s = read(SVC)
    assert "$this->network->allows($meta->clientIp)" in s
    assert "networkDenied()" in s
    assert "acm.office_network_issue') === '1'" in read(SIGNAL)
    a = read(API)
    assert "gatesIssuance()" in a


# ---- ticket lifecycle ----

def test_ticket_transitions_are_conditional_updates():
    s = read(TICKETS)
    for states in ("ISSUABLE_STATUSES", "TERMINAL_STATUSES", "CANCELABLE_STATUSES"):
        assert f"public const {states}" in s, states
    assert "AND status IN (" in s  # WHERE clause IS the state machine
    assert "markCodeIssued" in s and "markGrantVerified" in s
    assert "markAccountPendingDevice" in s and "markExpired" in s
    assert "failActivation" in s
    # S4 resume path is defined now (S4 calls it after device proof).
    assert "markActivated" in s and "'account_pending_device'], 'active'" in s


def test_cancel_needs_reason_and_cascades_in_one_transaction():
    s = read(TICKETS)
    body = s.split("public function cancel(")[1].split("public function revokeCeremony(")[0]
    assert "cancel reason (3-2000 chars)" in body
    assert "TERMINAL_STATUSES" in body
    assert "revokeCeremony($id, 'ticket_canceled')" in body
    assert "beginTransaction" in body and "commit()" in body
    assert "requireCeremonyTables" in body  # clean pre-082 failure


def test_reject_revokes_ceremony_material():
    s = read(TICKETS)
    assert "revokeCeremony($id, 'ticket_rejected')" in s


# ---- no secrets in logs/audit/API ----

def test_no_secret_logging_or_persistence():
    for path in (SVC, MOBILE, API):
        s = read(path)
        assert "error_log" not in s or path in (MOBILE, API)
    # S6: the mobile route's error_log calls are static secret-free
    # text only (audit-failure notice) -- no variables, no secret words.
    for line in read(MOBILE).splitlines():
        if "error_log(" in line:
            assert "$" not in line.split("error_log(", 1)[1]
            lowered = line.lower()
            assert "password" not in lowered and "secret" not in lowered
            assert "grant" not in lowered and "token" not in lowered
    s = read(SVC)
    assert "var_dump" not in s and "print_r" not in s
    # Event/audit metadata carries selectors + truncated fingerprints only.
    assert "substr($grant['fingerprint'], 0, 6)" in s or \
        "substr($fingerprint, 0, 6)" in s
    assert "'secret' =>" not in s and "'grant' =>" not in s.replace(
        "'grant' => $grant->token", "")


def test_mobile_route_hides_internals_behind_codes():
    s = read(MOBILE)
    assert "acmStrictBody" in s and "Unknown field" in s  # strict mode
    assert "isApiRateLimited('acm_verify', 30)" in s
    assert "isApiRateLimited('acm_credentials', 10)" in s
    assert "machineCode()" in s and "'code' =>" in s
    assert "RequestMeta($clientIp" in s
    # The route owns the wire contract: no exception message reaches
    # err(); every mobile-reachable code has an allowlisted message.
    assert "getMessage()" not in s
    assert "function acmWireError(" in s
    for code in ("code_invalid", "grant_invalid", "grant_expired",
                 "grant_consumed", "grant_revoked", "account_exists",
                 "rate_limited", "idempotent_conflict", "network_denied",
                 "ticket_state", "weak_password", "invalid_request"):
        assert f"'{code}' =>" in s or f"=== '{code}'" in s, code
    assert "PasswordPolicy::errors($password)" in s  # weak_password recompute
    r = read(ROUTER)
    assert "'acm'" in r and "acm.php" in r


# ---- console ----

def test_console_exposes_ceremony_actions_with_policy():
    s = read(API)
    assert "case 'code_issue':" in s and "RES_CODE, 'issue'" in s
    assert "case 'code_status':" in s and "RES_CODE, 'view'" in s
    assert "case 'ticket_cancel':" in s and "RES_TICKET, 'cancel'" in s
    p = read(POLICY)
    assert "'cancel'" in p and "'issue'" in p and "'view'" in p


def test_ticket_page_shows_code_once_then_masks():
    s = read(PAGE)
    assert "acmCodeOnce" in s
    assert "textContent" in s  # no HTML injection from code material
    assert "code_issue" in s and "ticket_cancel" in s
    assert "Approve &amp; provision" not in s  # cutover wording
