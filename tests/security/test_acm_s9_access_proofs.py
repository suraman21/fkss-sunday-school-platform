"""ACM S9 per-request proof pins: claims, verifier, choke point, wire codes.

Static pins (no PHP runtime in CI sandbox): generalized claim
validation (method + path + token hash, host deliberately
unpinnned per S5 precedent), the request verifier entry point
reusing the rotation pipeline (no forked crypto), the single
bounded session+binding read, the apiRequireAuth enforce-iff-
bound branch, the S5-shaped wire codes (REQUIRED + INVALID,
replay collapsed, no oracle), the dedicated replay-store sweep,
rotation byte-identical, and zero DDL.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CRYPTO = ROOT / "acm" / "src" / "Device" / "DeviceCrypto.php"
PROOFS = ROOT / "acm" / "src" / "Device" / "DeviceRefreshProofs.php"
REFRESH = ROOT / "admin" / "backend" / "services" / "RefreshTokenService.php"
GATE = ROOT / "api" / "v1" / "core" / "auth.php"
MOBILE = ROOT / "api" / "v1" / "routes" / "acm.php"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


def request_body() -> str:
    s = read(PROOFS)
    return s.split("public function verifyForRequest(")[1].split("private function sweepExpiredJtis")[0]


# ---- rotation untouched ----

def test_s9_rotation_claims_and_entry_point_byte_identical():
    s = read(CRYPTO)
    assert "function validateDpopClaims(array $parsed, int $now, int $skewSeconds)" in s
    rot = s.split("function validateDpopClaims")[1].split("per-request proof")[0]
    assert "'ath'" not in rot  # rotation proofs carry no token hash
    v = read(PROOFS)
    call = v.split("function verifyForRotation(")[1].split("public function verifyForRequest(")[0]
    assert "DeviceCrypto::validateDpopClaims($parsed, $now, $skewSeconds)" in call
    assert "validateDpopRequestClaims" not in call


def test_s9_parser_extracts_optional_ath_without_breaking_rotation():
    s = read(CRYPTO)
    assert "'ath' => $ath" in s
    assert "A-Za-z0-9_-" in s  # base64url shape cap
    # Absent ath stays null (rotation proofs have none); malformed
    # ath kills the proof the same way malformed siblings do.
    body = s.split("function parseDpopProof")[1].split("function validateDpopClaims")[0]
    assert "$payload['ath'] ?? null" in body


# ---- per-request claims: method + path + token hash ----

def test_s9_request_claims_pin_method_path_and_token_hash():
    s = read(CRYPTO)
    assert "function validateDpopRequestClaims(" in s
    assert "string $expectedHtm" in s
    assert "string $expectedHtuPath" in s
    assert "string $expectedAth" in s
    body = s.split("function validateDpopRequestClaims")[1].split("function normalizeDpopPath")[0]
    assert "($parsed['htm'] ?? null) !== $expectedHtm" in body
    assert "normalizeDpopPath((string)($parts['path'] ?? ''))" in body
    assert "hash_equals($expectedAth, $ath)" in body
    assert "abs($delta) <= $skewSeconds" in body
    # Host deliberately NOT pinned (S5 proxy precedent); scheme is.
    assert "'http'" in body and "'https'" in body
    assert "$parts['host']" not in body


def test_s9_path_fold_is_trailing_slash_insensitive():
    s = read(CRYPTO)
    body = s.split("function normalizeDpopPath")[1].split("function verifyDpopSignature")[0]
    assert "ltrim($path, '/')" in body and "rtrim($folded, '/')" in body


# ---- verifier: same pipeline, token hash, sweep ----

def test_s9_request_verifier_reuses_the_pipeline():
    b = request_body()
    assert "activeDeviceKey($db, $deviceId)" in b
    assert "DeviceCrypto::parseDpopProof($proof, $device['alg'])" in b
    assert "DeviceCrypto::validateDpopRequestClaims(" in b
    assert "DeviceCrypto::verifyDpopSignature($device['spki']" in b
    assert "$this->claimJti($db, $parsed['jti'], $deviceId, $jtiTtlSeconds)" in b
    # Registered key only (never the proof's embedded jwk) -- the
    # same guarantee as rotation, same helpers, no forked crypto.
    assert "function requestVerifier(\\mysqli $db): callable" in read(PROOFS)


def test_s9_ath_is_sha256_of_the_presented_token():
    b = request_body()
    assert "DeviceCrypto::base64UrlEncode(hash('sha256', $accessToken, true))" in b


def test_s9_sweep_is_sampled_bounded_and_never_breaks_auth():
    s = read(PROOFS)
    body = s.split("private function sweepExpiredJtis")[1].split("public static function requestVerifier")[0]
    assert "random_int(1, 100)" in body
    assert "DELETE FROM acm_refresh_proof_jtis" in body
    assert "expires_at < CURRENT_TIMESTAMP LIMIT 1000" in body
    assert "catch (\\Throwable" in body
    # Swept only after a successful claim (failed proofs claim nothing).
    assert "$this->sweepExpiredJtis($db);" in request_body()


# ---- session read: one bounded lookup, pre-086 truthful ----

def test_s9_triple_accessor_extends_liveness_without_changing_it():
    s = read(REFRESH)
    assert "function accessSessionIsActive(\\mysqli $database, int $userId, string $familyId, string $sessionId): bool" in s
    assert "function accessSessionProofBinding(\\mysqli $database, int $userId, string $familyId, string $sessionId): ?array" in s
    assert "function proofBindingAvailable(\\mysqli $database): bool" in s  # bare-PHP single backslash (S9 review catch)
    sql = s.split("private const ACCESS_SESSION_PROOF_SQL")[1].split(";")[0]
    assert "SELECT session_id, acm_bound, acm_device_id FROM api_refresh_sessions" in sql
    assert "revoked_at IS NULL AND expires_at>CURRENT_TIMESTAMP" in sql
    body = s.split("function accessSessionProofBinding")[1].split("function proofBindingAvailable")[0]
    # Bound-without-link is corrupt: inactive, never silently unbound.
    assert "if ($bound && $deviceId <= 0)" in body
    # Pre-086: liveness-only fallback, unbound (ground truth).
    assert "'bound' => false, 'device_id' => 0" in body


# ---- choke point: read-always, enforce-iff-bound ----

def test_s9_gate_reads_proof_and_enforces_iff_bound():
    s = read(GATE)
    assert "HTTP_DPOP" in s
    assert "accessSessionProofBinding(" in s
    assert "if ($sessionBinding['bound'])" in s
    # Read is unconditional (before the branch); enforcement is inside.
    assert s.index("$_SERVER['HTTP_DPOP']") < s.index("if ($sessionBinding['bound'])")
    # Unbound rows fall through to scope revalidation with no proof
    # code in between (byte-identical bearer path).
    branch = s.split("if ($sessionBinding['bound'])")[1].split("return apiRevalidateAuthorizationScope($payload);")[0]
    assert "requestVerifier($conn)" in branch
    assert "REQUEST_URI" in branch and "REQUEST_METHOD" in branch


def test_s9_wire_codes_are_required_plus_invalid_with_collapsed_replay():
    s = read(GATE)
    assert "'code' => 'ACCESS_PROOF_REQUIRED'" in s
    assert "'code' => 'ACCESS_PROOF_INVALID'" in s
    # Both are 401s (adjacency: the status sits with the call).
    for code in ("ACCESS_PROOF_REQUIRED", "ACCESS_PROOF_INVALID"):
        at = s.index(code)
        assert ", 401," in s[max(0, at - 120):at]
    assert "ACCESS_PROOF_REPLAY" not in s  # #33: no oracle
    assert s.count("ACCESS_PROOF_") == 2  # exactly two codes
    # Verifier failures fail closed as 503 (never skip verification).
    assert "'code' => 'AUTH_SERVICE_UNAVAILABLE'" in s


def test_s9_login_refresh_and_logout_never_gate():
    auth_route = read(ROOT / "api" / "v1" / "routes" / "auth.php")
    # Issuing routes never gate (only the /verify introspection
    # endpoint does -- and bound families proving there is right).
    pre_verify = auth_route.split("if ($action === 'verify'")[0]
    assert "apiRequireAuth()" not in pre_verify
    assert "apiRequireAuth()" in auth_route
    # Refresh keeps S5's own proof states (no per-request codes
    # emitted here; the device_bound doc comment names them only).
    assert "'code' => 'ACCESS_PROOF_" not in auth_route
    mobile = read(MOBILE)
    assert "ACCESS_PROOF_" not in mobile  # ceremony has its own auth


# ---- untouched surface: web sessions, DDL ----

def test_s9_web_sessions_and_schema_untouched():
    assert "ACCESS_PROOF_" not in read(ROOT / "admin" / "api_acm.php")
    # S9 added zero DDL. Re-based at S10 (legit: S10 owns 091 by
    # plan §3B build order) — the pin now guards the NEXT number.
    assert not list(ROOT.glob("sql/092_*.sql"))  # zero DDL beyond S10 (absence pinned)
