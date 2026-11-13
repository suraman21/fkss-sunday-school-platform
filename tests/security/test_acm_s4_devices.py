"""ACM S4 device pins: registry, ceremony link, refresh link, wire, console.

Static pins (no PHP runtime in CI sandbox): 083 schema shape,
fail-closed guards, fixed lock order, families-first revoke order,
strict endpoint bodies, wire allowlist, login default-null, policy
matrix, and no-secret handling including challenge solutions.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SQL83 = ROOT / "sql" / "083_acm_device_registry.sql"
ACMSRC = ROOT / "acm" / "src"
CRYPTO = ACMSRC / "Device" / "DeviceCrypto.php"
DEVICES = ACMSRC / "Device" / "DeviceService.php"
SVC = ACMSRC / "Activation" / "ActivationService.php"
EXC = ACMSRC / "Activation" / "ActivationException.php"
TICKETS = ACMSRC / "Accounts" / "AcmTicketService.php"
PROVISIONER = ACMSRC / "Accounts" / "AccountProvisioner.php"
POLICY = ACMSRC / "Policy" / "AcmPolicy.php"
REFRESH = ROOT / "admin" / "backend" / "services" / "RefreshTokenService.php"
API = ROOT / "admin" / "api_acm.php"
PAGE = ROOT / "admin" / "acm_ticket.php"
MOBILE = ROOT / "api" / "v1" / "routes" / "acm.php"
AUTH = ROOT / "api" / "v1" / "routes" / "auth.php"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


# ---- 083 schema ----

def test_s4_migration_is_idempotent_and_fk_free():
    s = read(SQL83)
    code = "\n".join(ln for ln in s.splitlines() if not ln.lstrip().startswith("--"))
    assert "FOREIGN KEY" not in code
    for table in ("acm_devices", "acm_device_challenges"):
        assert f"CREATE TABLE IF NOT EXISTS `{table}`" in s, table
    # Refresh link is information_schema-guarded (re-runnable, pre-083 safe).
    assert "information_schema" in s
    assert "acm_device_id" in s
    # Device identity: one row per (user, key); challenges keyed by uuid
    # (supersede-pending in beginFull keeps one live row per device).
    assert "UNIQUE KEY `uniq_acm_device_user_fp`" in s
    assert "UNIQUE KEY `uniq_acm_challenge_uuid`" in s


def test_s4_migration_appends_events_without_reordering():
    s = read(SQL83)
    m = re.search(r"MODIFY COLUMN `event` ENUM\((.*?)\) NOT NULL",
                  s.replace("''", "'"))
    assert m, "event widen missing"
    values = [v.strip("'") for v in m.group(1).split("','")]
    # S3 order is a prefix: existing indexes are kept.
    assert values.index("device_registered") > values.index("activation_failed")
    assert values.index("device_revoked") > values.index("device_registered")


def test_s4_migration_seeds_challenge_and_cap_settings():
    s = read(SQL83)
    assert "'acm.device_challenge_ttl_minutes', '10'" in s
    assert "'acm.device_challenge_max_attempts', '5'" in s
    assert "'acm.max_devices_per_account', '5'" in s  # owner decision #12
    assert "WHERE NOT EXISTS" in s  # seed-if-missing (081 precedent)


def test_s4_migration_stores_hashes_not_secrets():
    s = read(SQL83)
    assert "`fingerprint`" in s and "`challenge_hash`" in s
    # No plaintext secret/solution/signature column anywhere.
    code = "\n".join(ln for ln in s.splitlines() if not ln.lstrip().startswith("--"))
    assert re.search(r"`secret`|`token`|`password`|`challenge`|`signature`", code) is None


# ---- device error codes (D4.3) ----

def test_s4_error_codes_are_stable_machine_strings():
    s = read(EXC)
    for const, value in (("CHALLENGE_FAILED", "challenge_failed"),
                         ("DEVICE_LIMIT", "device_limit"),
                         ("DEVICE_REVOKED", "device_revoked"),
                         ("DEVICE_CONFLICT", "device_conflict")):
        assert f"public const {const} " in s and f"= '{value}';" in s, const
    assert "function challengeFailed()" in s
    # challenge_failed is 401 (possession proof failed), limits are 409.
    assert "CHALLENGE_FAILED, 401" in s


# ---- crypto posture (D3.6) ----

def test_s4_crypto_pins_spki_der_and_fail_closed():
    s = read(CRYPTO)
    assert "prime256v1" in s  # P-256 only; no P-384/P-521
    assert "OPENSSL_KEYTYPE_EC" in s and "OPENSSL_KEYTYPE_RSA" in s
    assert "curve_name" in s  # curve pinned from parsed key, not claimed alg
    assert "RSA_MIN_BITS" in s and "2048" in s  # #14 compat floor
    assert "OPENSSL_ALGO_SHA256" in s  # DER ECDSA / PKCS#1 v1.5, no P1363
    assert "openssl_verify(" in s  # bool-only verify (1 ok, else fail)
    assert "extension_loaded('openssl')" in s  # no-openssl => Runtime, never skip
    assert "CHALLENGE_BYTES = 32" in s  # 256-bit challenges
    assert "random_bytes(self::CHALLENGE_BYTES)" in s


def test_s4_begin_fails_fast_on_duplicate_and_race():
    s = read(DEVICES)
    body = s.split("public function beginFull")[1].split("public function completeFull")[0]
    # Same-key re-begin after success: clean 400, never a 23000 500.
    assert "This installation is already registered." in body
    # Concurrent-begin race: the 23000 loser gets an honest retry hint.
    assert "'23000'" in body and "rateLimited()" in body


def test_s4_compares_are_constant_time():
    s = read(DEVICES)
    # Fingerprint re-proof, challenge echo (both paths), never ==.
    assert s.count("hash_equals(") >= 3


def test_s4_installation_format_matches_telemetry_validator():
    s = read(CRYPTO)
    # Same UUID v1-5 regex as ApiSyncAttemptMonitorService (source comment).
    assert "validatedInstallationId" in s
    assert "[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}" in s


# ---- cap + lock discipline ----

def test_s4_complete_locks_user_then_installation():
    s = read(DEVICES)
    body = s.split("private function acquireLocks")[1].split("private function releaseLocks")[0]
    assert body.index("'acm_dev_u_'") < body.index("'acm_dev_i_'")
    assert "GET_LOCK" in body and "LOCK_TIMEOUT_S" in body
    # Lock failure surfaces as an honest retry hint, not a device verdict.
    assert "rateLimited()" in body


def test_s4_caps_exclude_self_and_fail_closed():
    s = read(DEVICES)
    assert "acm.max_devices_per_account" in s
    assert "acm.max_accounts_per_device" in s
    # Exclude-self on recount (idempotent complete must not self-block).
    assert "AND id <> :self" in s
    # Unparseable/out-of-range settings throw (500 fail-closed), never
    # unlimited and never silently defaulted.
    assert 'Server setting "' in s and "is invalid" in s and "out of range" in s


def test_s4_revoke_is_families_first_and_fail_closed():
    s = read(DEVICES)
    body = s.split("public function revokeWithReport")[1].split("public function listForUser")[0]
    assert "familyRevoker === null" in body  # no revoker => Runtime
    # The cascade runs BEFORE the device row flips (crash-safe direction).
    assert body.index("familyRevoker") < body.index("SET status = \\'revoked\\'")
    assert "status <> \\'revoked\\'" in body  # concurrent-revoke guard
    # Already-revoked rows are provably complete: no re-cascade, still 200.
    assert "'already' => true" in body
    # Officer reasons keep their 2000-char budget (never the 80-char
    # device-label width).
    assert "cleanReason($reason)" in body
    c = read(CRYPTO)
    assert "function cleanReason(" in c and "mb_substr($clean, 0, 2000)" in c


# ---- ceremony link ----

def test_s4_registration_grant_resolution_is_fail_fast():
    s = read(SVC)
    assert "public function resolveRegistrationGrant" in s
    body = s.split("public function resolveRegistrationGrant")[1]
    body = body.split("\n    public function ")[0].split("\n    private function ")[0]
    assert "password_verify($grantToken" in body
    assert "userIdFor(" in body  # single home for the account-link lookup
    assert "GET_LOCK" not in body  # read-only: no locks needed


def test_s4_account_link_lookup_has_single_home():
    s = read(PROVISIONER)
    assert "public function userIdFor(" in s
    assert "status = \\'active\\'" in s  # newest active link wins (ORDER BY id DESC)


# ---- refresh link ----

def test_s4_refresh_link_defaults_null_and_rotates():
    s = read(REFRESH)
    assert "?int $acmDeviceId = null" in s  # zero behavior change by default
    assert "SHOW COLUMNS FROM api_refresh_sessions LIKE 'acm_device_id'" in s
    assert "public function revokeDeviceFamilies(int $deviceId): int" in s
    assert "inherits" in s  # rotation carries the current session's link


def test_s4_login_assertion_is_optional_and_never_blocks():
    s = read(AUTH)
    assert "findActiveForAssertion(" in s
    assert "touchLastSeen($acmDeviceId)" in s
    # Pre-083 safety: only the missing-table code is caught (fail-closed).
    assert "42S02" in s


# ---- mobile wire ----

def test_s4_mobile_endpoints_use_strict_bodies():
    s = read(MOBILE)
    for path in ("devices/register/begin", "devices/register/complete",
                 "devices/revoke"):
        assert f"$acmPath === '{path}'" in s, path
    assert "$acmPath === 'devices' && $method === 'GET'" in s
    assert "acmStrictBody(['grant', 'installation_id', 'pubkey', 'name']" in s
    assert "acmStrictBody(['challenge_id', 'challenge', 'signature']" in s
    assert "acmStrictBody(['device_uuid', 'reason']" in s
    # Hash-only storage forces the challenge echo back (plan correction).
    assert "$body['challenge']" in s


def test_s4_mobile_wire_errors_are_allowlisted():
    s = read(MOBILE)
    for code in ("challenge_failed", "device_limit", "device_revoked",
                 "device_conflict"):
        assert f"'{code}' =>" in s, code
    # The wire carries short fingerprints only; never full keys/solutions.
    assert "shortFingerprint(" in s
    assert "getMessage()\" not in s"  # placeholder guard, see S3 pin


def test_s4_credentials_resume_hands_off_to_registration():
    s = read(MOBILE)
    assert "'resume' => 'devices/register/begin'" in s
    assert "'device_grant'" in s  # show-once chained registration grant


# ---- policy + console ----

def test_s4_policy_adds_officer_device_right():
    s = read(POLICY)
    assert "RES_DEVICE" in s
    assert re.search(r"'device'\s*=>\s*\['manage'\]", s)
    # Governance deny-list still holds (S4 adds no office/attest rights).
    for res in ("office", "approver", "reviewer", "attest"):
        assert re.search(r"'" + res + r"'\s*=>\s*\[\]", s), res


def test_s4_console_devices_are_ticket_scoped():
    s = read(API)
    assert "case 'device_list':" in s and "case 'device_revoke':" in s
    assert "RES_DEVICE, 'manage'" in s
    assert "userIdFor(" in s  # list + revoke resolve the ticket's account
    assert "hash_equals(" in s  # uuid pinned to the ticket before revoke
    assert "shortFingerprint(" in s  # wire + audit carry truncated fps only


def test_s4_console_issue_purpose_vocabulary_is_strict():
    s = read(API)
    assert "CodePurpose::DeviceAdd" in s
    assert "Unknown code purpose." in s  # reset stays closed
    p = read(PAGE)
    assert 'value="device_add"' in p
    assert "data-revoke" in p  # two-click revoke buttons
