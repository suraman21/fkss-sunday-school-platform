"""ACM S2 contract pins: PSR-4 package, shims, contracts, CI wiring.

Static pins (no PHP runtime in CI sandbox). The behavioral proof —
same matrix, same methods, old names aliasing the new implementation —
runs as PHPUnit in the acm-contracts CI job.
"""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ACMSRC = ROOT / "acm" / "src"
SHIMDIR = ROOT / "admin" / "backend" / "services" / "Acm"
CALLERS = [ROOT / "admin" / n for n in (
    "api_acm.php", "acm_overview.php", "acm_queue.php", "acm_ticket.php",
    "acm_person.php", "acm_office.php", "acm_audit.php",
)]
MOVES = {
    "Policy/AcmPolicy.php": "SSMS\\Acm\\Policy",
    "Identity/AcmPersonService.php": "SSMS\\Acm\\Identity",
    "Accounts/AcmTicketService.php": "SSMS\\Acm\\Accounts",
    "Office/AcmOfficeService.php": "SSMS\\Acm\\Office",
}
SHIMS = {
    "AcmPolicy.php": "SSMS\\Acm\\Policy\\AcmPolicy",
    "AcmPersonService.php": "SSMS\\Acm\\Identity\\AcmPersonService",
    "AcmTicketService.php": "SSMS\\Acm\\Accounts\\AcmTicketService",
    "AcmOfficeService.php": "SSMS\\Acm\\Office\\AcmOfficeService",
}


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


# ---- moves ----

def test_services_moved_with_correct_namespaces():
    for rel, ns in MOVES.items():
        s = read(ACMSRC / rel)
        assert f"namespace {ns};" in s, rel


def test_moved_ticket_service_imports_person_service():
    s = read(ACMSRC / "Accounts" / "AcmTicketService.php")
    assert "use SSMS\\Acm\\Identity\\AcmPersonService;" in s


def test_moved_files_keep_no_legacy_namespace():
    for rel in MOVES:
        s = read(ACMSRC / rel)
        assert "namespace App\\Services\\Acm;" not in s, rel


# ---- shims ----

def test_shims_alias_and_hold_no_logic():
    for name, fqcn in SHIMS.items():
        s = read(SHIMDIR / name)
        assert "acm/autoload.php" in s, name
        assert "class_alias" in s, name
        assert fqcn in s, name
        assert "function " not in s, name  # no logic by design
        assert "->prepare(" not in s, name


# ---- callers ----

def test_callers_use_new_names_and_single_loader():
    for caller in CALLERS:
        s = read(caller)
        assert "use SSMS\\Acm\\" in s, caller.name
        assert "acm/autoload.php" in s, caller.name
        assert "use App\\Services\\Acm\\" not in s, caller.name
        assert "backend/services/Acm/" not in s, caller.name


def test_no_legacy_acm_use_anywhere_outside_shims():
    for path in (ROOT / "admin").rglob("*.php"):
        if path.parent == SHIMDIR:
            continue
        assert "use App\\Services\\Acm\\" not in read(path), path.name


def test_layout_uses_new_policy_fqn():
    s = read(ROOT / "admin" / "acm_layout.php")
    assert "\\SSMS\\Acm\\Policy\\AcmPolicy::roleLabel" in s


# ---- loader + composer ----

def test_package_loader_is_an_explicit_manifest():
    s = read(ROOT / "acm" / "autoload.php")
    # Repo constitution: no spl_autoload_register outside vendor/.
    assert "spl_autoload_register" not in s
    assert "vendor/autoload.php" in s
    assert "SSMS_ACM_AUTOLOAD" in s  # idempotent
    # Every package file is explicitly required — the manifest cannot
    # silently drop a class.
    for src in sorted((ROOT / "acm" / "src").rglob("*.php")):
        rel = "/" + src.relative_to(ROOT / "acm" / "src").as_posix()
        assert rel in s, rel


def test_package_loader_orders_contracts_before_services():
    s = read(ROOT / "acm" / "autoload.php")
    # implements/extends targets must be DECLARED before the classes
    # that name them: the tracked vendor/ predates the SSMS mapping,
    # so composer cannot resolve them on demand (S4 login-seam fatal,
    # 2026-11-13: Authorizer not found via routes/auth.php).
    last_contract = max(s.index(f"/src/Contracts/{n}.php") for n in (
        "Authorizer", "PersonDirectory", "DeviceRegistry",
        "ActivationService", "AuditTrail"))
    first_service = min(s.index(f"/src/{n}.php") for n in (
        "Policy/PolicyAuthorizer", "Device/DeviceService",
        "Activation/ActivationService"))
    assert last_contract < first_service


def test_composer_declares_psr4_and_phpunit():
    composer = json.loads(read(ROOT / "composer.json"))
    assert composer["autoload"]["psr-4"]["SSMS\\Acm\\"] == "acm/src/"
    assert "phpunit/phpunit" in composer["require-dev"]
    assert composer["config"]["platform"]["php"].startswith("8.2")


# ---- contracts ----

def test_contract_interfaces_exist_with_expected_methods():
    contracts = {
        "Authorizer.php": ["function can(", "function decide("],
        "PersonDirectory.php": ["function resolveByMemberCode("],
        "DeviceRegistry.php": ["function beginRegistration(",
                               "function completeRegistration(",
                               "function revoke("],
        "ActivationService.php": ["function issueCode(",
                                  "function verifyCode(",
                                  "function establishCredentials("],
        "AuditTrail.php": ["function record("],
    }
    for name, methods in contracts.items():
        s = read(ACMSRC / "Contracts" / name)
        assert "namespace SSMS\\Acm\\Contracts;" in s, name
        assert "interface " in s, name
        for method in methods:
            assert method in s, f"{name}: {method}"


def test_value_types_exist():
    for name in ("AccountContext.php", "ResourceRef.php",
                 "AuthorizationDecision.php", "CodePurpose.php",
                 "IssuedCode.php", "ActivationGrant.php", "RequestMeta.php",
                 "AccountRef.php", "PersonRef.php", "DeviceRef.php",
                 "RegistrationChallenge.php", "AuditEvent.php"):
        assert (ACMSRC / "Contracts" / name).is_file(), name


def test_policy_authorizer_delegates_to_matrix():
    s = read(ACMSRC / "Policy" / "PolicyAuthorizer.php")
    assert "implements Authorizer" in s
    assert "AcmPolicy::can(" in s
    assert "acm.matrix.allow" in s and "acm.matrix.deny" in s
    assert "matrix.deny" in s


# ---- phpunit + CI ----

def test_phpunit_bootstrap_and_tests_exist():
    assert (ROOT / "acm" / "phpunit.xml").is_file()
    assert (ROOT / "acm" / "tests" / "bootstrap.php").is_file()
    assert (ROOT / "acm" / "tests" / "ClassLoadingTest.php").is_file()
    assert (ROOT / "acm" / "tests" / "PolicyParityTest.php").is_file()
    boot = read(ROOT / "acm" / "tests" / "bootstrap.php")
    assert "acm/autoload.php" in boot or "/autoload.php" in boot


def test_ci_runs_acm_contracts_job():
    workflow = read(ROOT / ".github" / "workflows" / "backend-checks.yml")
    assert "acm-contracts:" in workflow
    assert "acm/phpunit.xml" in workflow
    assert "PSR-4 smoke" in workflow
