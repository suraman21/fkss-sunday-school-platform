"""ACM S5 enforcement pins: binding flag, DPoP proofs, rotation order, wire.

Static pins (no PHP runtime in CI sandbox): 086 schema shape,
fail-closed guards, proof-verified-before-consume order, bound/linked
invariant, reject-only failure semantics (no family revoke, no audit
row on proof failure), login binding + response flag, wire codes,
and console enforcement visibility.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SQL86 = ROOT / "sql" / "086_acm_refresh_binding.sql"
CRYPTO = ROOT / "acm" / "src" / "Device" / "DeviceCrypto.php"
PROOFS = ROOT / "acm" / "src" / "Device" / "DeviceRefreshProofs.php"
DEVICES = ROOT / "acm" / "src" / "Device" / "DeviceService.php"
REFRESH = ROOT / "admin" / "backend" / "services" / "RefreshTokenService.php"
AUTH = ROOT / "api" / "v1" / "routes" / "auth.php"
PAGE = ROOT / "admin" / "acm_ticket.php"
MANIFEST = ROOT / "acm" / "autoload.php"
PHPUNIT = ROOT / "acm" / "tests" / "RefreshProofTest.php"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


# ---- 086 schema ----

def test_s5_migration_is_idempotent_and_fk_free():
    s = read(SQL86)
    code = "\n".join(ln for ln in s.splitlines() if not ln.lstrip().startswith("--"))
    assert "FOREIGN KEY" not in code
    assert "CREATE TABLE IF NOT EXISTS `acm_refresh_proof_jtis`" in s
    assert "PRIMARY KEY (`jti`)" in s
    assert "KEY `idx_acm_proof_jti_expiry` (`expires_at`)" in s
    # Bound flag is information_schema-guarded, NOT NULL DEFAULT 0
    # (pre-S5 rows read unbound), and carries NO index (rotation
    # reads it from the already-loaded session row).
    assert "information_schema" in s
    assert "ADD COLUMN `acm_bound` TINYINT(1) NOT NULL DEFAULT 0" in s
    assert "ADD INDEX" not in code
    # Proof windows are ops-tunable seeds (owner decision #16).
    assert "'acm.refresh_proof_skew_seconds', '300'" in s
    assert "'acm.refresh_proof_jti_ttl_seconds', '900'" in s


def test_s5_migration_touches_no_ticket_vocabulary():
    s = read(SQL86)
    # Proof failures are unauthenticated 401 noise and are never
    # audited (same precedent as invalid-token 401s; only reuse is).
    assert "acm_ticket_events" not in s


# ---- DPoP crypto policy (pure) ----

def test_s5_dpop_parser_is_strict_and_registered_key_only():
    s = read(CRYPTO)
    assert "function parseDpopProof(string $proof, string $expectedAlg)" in s
    assert "function validateDpopClaims(array $parsed, int $now, int $skewSeconds)" in s
    assert "function verifyDpopSignature(" in s
    # Header alg must equal the REGISTERED alg (no none-alg, no
    # confusion); the embedded jwk is shape-checked but NEVER
    # trusted (private material rejected outright).
    assert "$alg !== $expectedAlg" in s
    assert "array_key_exists('d', $jwk)" in s
    # Freshness binds both directions; htu pins the refresh route
    # (host deliberately not pinned -- beta/prod/test bases differ).
    assert "abs($delta) <= $skewSeconds" in s
    assert "DPOP_HTU_PATH_SUFFIX = '/auth/refresh-token'" in s
    # Caps mirror the S4 budgets.
    assert "MAX_DPOP_PROOF_BYTES = 4096" in s
    assert "DPOP_JTI_MAX_CHARS = 128" in s


def test_s5_verifier_runs_in_rotation_transaction():
    s = read(PROOFS)
    assert "function verifyForRotation(" in s
    assert "function rotationVerifier(\\mysqli $db): callable" in s
    # Active devices only; key read + jti claim share the caller's
    # mysqli handle (single transaction: a proof is consumed iff
    # its rotation commits).
    assert "!== 'active'" in s
    assert "acm_refresh_proof_jtis (jti, device_id, expires_at)" in s
    # Duplicate jti is a replay in BOTH mysqli error modes (silent
    # errno check + STRICT exception catch -- the mode is global).
    assert "RESULT_REPLAY" in s
    assert "mysqli_sql_exception" in s
    # Misconfigured windows fail SAFE to compiled defaults, loudly.
    assert "ttl < 2 * $skew" in s


# ---- rotation order + invariant + failure semantics ----

def test_s5_proof_branch_sits_between_account_check_and_consume():
    s = read(REFRESH)
    account = s.index("$user = $account['user'];")
    branch = s.index("S5: bound families require a DPoP proof")
    consume = s.index("SET consumed_at=CURRENT_TIMESTAMP")
    assert account < branch < consume
    # Bound reads are probe-guarded (pre-086 reads unbound, S4
    # precedent); rotation inherits the flag like the link.
    assert "boundColumnAvailable()" in s
    assert "$inheritBound" in s


def test_s5_bound_requires_link_and_never_revokes_on_failure():
    s = read(REFRESH)
    # Invariant enforced LOUDLY at issue (a silent downgrade to
    # unbound would lie about the family's protection).
    assert "Bound families require a device link." in s
    assert "return ['state' => 'proof_required']" in s
    assert "return ['state' => 'proof_invalid']" in s
    # Reject-only (#17): the bound branch rolls back but never
    # revokes the family (a stolen token must not brick it).
    branch = s[s.index("S5: bound families require a DPoP proof"):
               s.index("$issued = $this->issueWithinTransaction(\n                $user,\n                (string)$session['family_id'],")]
    assert "revokeFamily" not in branch
    assert branch.count("$this->database->rollback();" ) >= 2


def test_s5_bound_rotation_touches_last_seen_post_commit():
    s = read(REFRESH)
    touch = "UPDATE acm_devices SET last_seen = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1"
    assert touch in s
    # Post-commit (after the rotation commit, before cleanup) and
    # best-effort: a touch failure must never undo rotation.
    assert s.index("$this->database->commit();\n            if ($inheritBound) {") < s.index(touch)


def test_s5_unbound_and_legacy_paths_ignore_proofs():
    s = read(REFRESH)
    legacy = s[s.index("private function exchangeLegacy("):s.index("Caller owns transaction boundaries")]
    assert "bound" not in legacy.lower()
    assert "dpop" not in legacy.lower()
    # Expired replay claims are swept by the sampled bounded
    # cleanup (same never-breaks-auth contract as the rest).
    assert "acm_refresh_proof_jtis" in s


# ---- routes: login binding + refresh mapping ----

def test_s5_login_binds_on_assertion_and_tells_the_app():
    s = read(AUTH)
    assert "$acmDeviceId, $acmDeviceId !== null" in s
    assert "'device_bound' => $acmDeviceId !== null" in s


def test_s5_refresh_maps_proof_states_without_auditing():
    s = read(AUTH)
    assert "HTTP_DPOP" in s
    assert "DeviceRefreshProofs::rotationVerifier($conn)" in s
    assert "'code' => 'DEVICE_PROOF_REQUIRED'" in s
    assert "'code' => 'DEVICE_PROOF_INVALID'" in s
    # Only login + token-reuse audit (proof failures are noise).
    assert s.count("logApiAction(") == 2


# ---- console visibility + packaging ----

def test_s5_console_shows_bound_families_without_hiding_rows():
    s = read(DEVICES)
    assert "function boundFamilyCounts(int $userId): array" in s
    assert "s.acm_bound = 1" in s
    p = read(PAGE)
    assert "$boundFamilyCounts" in p
    assert "?> bound</span>" in p


def test_s5_verifier_ships_in_manifest_and_class_loading():
    assert "/src/Device/DeviceRefreshProofs.php" in read(MANIFEST)
    loading = read(ROOT / "acm" / "tests" / "ClassLoadingTest.php")
    assert "Device\\DeviceRefreshProofs::class" in loading
    t = read(PHPUNIT)
    assert "testEs256ProofRoundTrip" in t
    assert "testRs256ProofRoundTrip" in t
    assert "testClaimsEnforceRouteMethodAndFreshness" in t
