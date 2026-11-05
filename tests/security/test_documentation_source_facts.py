"""Source-only documentation consistency checks; no database or network.

These pin corrected facts and evidence boundaries, not exhaustive source,
API-field, authorization, deployment or user-manual verification.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def text(path):
    return (ROOT / path).read_text(encoding="utf-8")


def test_numbered_migration_inventory_excludes_comment_words():
    migrations = sorted((ROOT / "sql").glob("[0-9][0-9][0-9]_*.sql"))
    targets = set()
    for path in migrations:
        source = re.sub(r"/\*.*?\*/", "", path.read_text(encoding="utf-8"), flags=re.S)
        source = re.sub(r"(?m)^\s*--.*$", "", source)
        targets.update(re.findall(
            r"\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)",
            source, re.I,
        ))
    doc = text("docs/DATABASE.md")
    assert f"**{len(migrations)} numbered migration files**" in doc
    assert f"**{len(targets)} distinct `CREATE TABLE` target names**" in doc
    assert "only" not in targets
    row = next(line for line in doc.splitlines() if line.startswith("| `026_hr_attendance.sql`"))
    assert "hr_attendance, hr_attendance_audit, hr_submissions |" in row
    assert ", only" not in row


def test_openapi_exists_and_operation_count_is_not_an_absence_claim():
    spec = text("docs/api/openapi.yaml")
    operations = re.findall(r"^    (?:get|post|patch|put|delete|head|options):", spec, re.M)
    overview = text("docs/API.md")
    assert "openapi: 3.1.0" in spec
    assert f"{len(operations)} listed operations" in overview
    assert "No OpenAPI specification exists" not in overview
    assert "most bodies are summarised" in overview
    assert "Web chunk-upload recovery does not mean mobile APK downloads support resume" in overview


def test_legacy_authorization_compatibility_is_not_documented_as_universal_revocation():
    source = text("api/v1/core/auth.php")
    assert "function apiAuthorizationScopeEnforcedForClient()" in source
    assert "define('API_AUTHZ_LEGACY_COMPAT_UNTIL', PHP_INT_MAX)" in source
    for path in ("docs/API.md", "docs/architecture/TECHNICAL_DESIGN.md", "docs/product/PRD.md"):
        body = text(path)
        assert "compatibility" in body.lower(), path
    assert "API_AUTHZ_LEGACY_COMPAT_UNTIL" in text("docs/API.md")


def test_deployment_boundary_preserves_beta_isolation_and_separate_authorization():
    branching = text("BRANCHING.md")
    assert "never push `dev` to the production repository" in branching
    assert "May intentionally lag development" in branching
    assert "owner acceptance" in branching.lower()
    decision = text("docs/adr/0021-development-acceptance-before-production.md")
    assert "separate explicit owner decision" in decision
    for old in ("0001-php-monolith-on-shared-hosting.md", "0014-branching-main-dev.md"):
        assert "0021-development-acceptance-before-production.md" in text("docs/adr/" + old)
    design = text("docs/architecture/TECHNICAL_DESIGN.md")
    assert 'gh -->|"same commits"|' not in design
    assert "a red CI gate" not in design
    assert "probes database tables" in design


def test_release_guidance_preserves_signer_device_and_private_diagnostics_gates():
    release = text("Mobile/wbws_flutter_app/docs/RELEASE.md")
    for boundary in (
        "DEPENDENCY NOT VERIFIED", "certificate continuity", "restart from zero",
        "not guaranteed free of personal or sensitive data", "authorized administrator",
        "installer launch", "separate owner authorization", "unsent work",
    ):
        assert boundary.lower() in release.lower(), boundary
    assert "Stack traces only — no user data" not in release
    assert "works on every phone" not in release.lower()


def test_product_mobile_role_and_recovery_boundaries_are_explicit():
    prd = text("docs/product/PRD.md")
    for role in ("finance_dept", "material_dept"):
        row = next(line for line in prd.splitlines() if line.startswith("|") and f"(`{role}`)" in line)
        assert "Website-only" in row
    assert "410 `HR_ATTENDANCE_RETIRED`" in prd
    assert "Preserving logout" in prd
    assert "local_data_cleanup_service.dart" not in prd
    assert "offline_recovery_service.dart" not in prd
    assert "not complete" in prd


def test_operational_safety_corrections_keep_required_runbook_contract():
    runbook = text("docs/audits/DEPLOYMENT_RUNBOOK.md")
    assert "not a current release authorization" in runbook
    assert "find . -type d -exec chmod" not in runbook
    assert "find . -type f -exec chmod" not in runbook
    assert "-p<password>" not in runbook
    for boundary in (
        "031 -> 032 -> 033", "DEPLOYMENT BLOCKER", "39 / 42", "42 / 42",
        "sql/preflight/uniqueness_preflight.sql", "scripts/restore_production_dump.sh",
        "PROFILE_PRIVATE_STORAGE_PATH", "profile_image_cleanup.php",
        '"lock_acquired":true', "not a fresh-install sequence",
    ):
        assert boundary in runbook, boundary
    incident = text("docs/INCIDENT_RESPONSE.md")
    assert "owner acceptance" in incident
    assert "separate production authorization" in incident
    assert "Preserve unsent mobile work" in incident
    assert "Never request that staff paste raw reports into public issues" in text("Mobile/wbws_flutter_app/docs/RELEASE.md")


def test_retained_source_copy_and_partial_manual_are_not_hidden_as_completed():
    catalog = text("docs/DOCUMENTATION_CATALOG.md")
    assert "not complete" in catalog
    assert "Complete unique-knowledge retirement review remains unfinished" in catalog
    assert (ROOT / "Mobile/code_dump.txt").is_file()
    lifecycle = text("docs/DOCUMENT_LIFECYCLE.md")
    assert "6/30" in lifecycle
    assert "remaining differences were not fully reviewed" in lifecycle
    assert "INCOMPLETE — ADDITIONAL WORK REQUIRED" in text("docs/user_manual/COVERAGE.md")
