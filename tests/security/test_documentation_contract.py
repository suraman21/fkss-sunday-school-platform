"""Documentation/copy regressions, not proof of all-workflow manual coverage.

Keep the source queue honest, preserve recovery warnings, and prevent the
resolved CI setup failure from reappearing as current onboarding guidance.
All checks read repository files only; no server or school database is used.
"""
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MANUAL = ROOT / "docs/user_manual"


def text(path):
    return (ROOT / path).read_text(encoding="utf-8")


def test_mobile_source_queue_lists_every_tracked_screen_directory_source():
    tracked = subprocess.run(
        ["git", "ls-files", "Mobile/wbws_flutter_app/lib/screens/*.dart"],
        cwd=ROOT, capture_output=True, text=True, check=True,
    ).stdout.splitlines()
    listed = re.findall(
        r"\]\(\.\./\.\./(Mobile/wbws_flutter_app/lib/screens/[^)]+\.dart)\)",
        (MANUAL / "COVERAGE.md").read_text(encoding="utf-8"),
    )
    assert len(tracked) >= 45, "source discovery unexpectedly shrank"
    assert len(listed) == len(set(listed)), "duplicate source-queue rows"
    assert set(listed) == set(tracked), "update source review/coverage for screen changes"


def test_manual_admits_unfinished_domain_and_runtime_coverage():
    coverage = (MANUAL / "COVERAGE.md").read_text(encoding="utf-8")
    assert "INCOMPLETE — ADDITIONAL WORK REQUIRED" in coverage
    assert "discovery counts" in coverage
    assert "not a statement that every listed file is a reachable screen" in coverage
    assert "No complete all-button catalog" in coverage
    for chapter in ("ACCOUNT_AND_DEVICE.md", "APP_UPDATES.md", "SYNC_AND_RECOVERY.md"):
        body = (MANUAL / chapter).read_text(encoding="utf-8")
        assert "**Baseline:**" in body
        assert "**Verification:**" in body
        assert "Source and verification reference" in body
        assert "COVERAGE.md" in body


def test_mobile_update_manual_does_not_promise_unimplemented_resume():
    body = (MANUAL / "APP_UPDATES.md").read_text(encoding="utf-8")
    assert "failed mobile downloads restart from zero" in body.lower()
    assert "does **not** guarantee saved-progress resume" in body
    assert "Opening the installer does **not** establish" in body
    assert "signature/package conflict" in body


def test_account_manual_preserves_explicit_logout_choices_and_scope():
    body = (MANUAL / "SYNC_AND_RECOVERY.md").read_text(encoding="utf-8")
    for label in ("Keep work & sign out", "Discard permanently", "Cancel", "Recover work"):
        assert label in body
    assert "does not delete an accepted server record" in body
    assert "same account" in body
    assert "Shared hymn operations are intentionally retained" in body


def test_current_entry_docs_do_not_reintroduce_the_resolved_red_gate():
    stale = (
        r"currently fails on `main` because 79",
        r"79 explained skips are expected today",
        r"gate is \*\*red\*\* because 79",
        r"CI gate is red and 79",
    )
    for path in ("README.md", "CONTRIBUTING.md", "docs/ONBOARDING.md"):
        body = text(path)
        for pattern in stale:
            assert not re.search(pattern, body), (path, pattern)
    # Historical reports can retain old failures; do not erase their outcomes.
    evidence = text("docs/reports/DOCUMENTATION_BASELINE_2026-10-28.md")
    assert "7209278b44dd35f0e2cf9ec94d198ee968bb10dc" in evidence
    assert len(re.findall(r"actions/runs/\d+/job/\d+", evidence)) == 8


def test_diagnostic_copy_warns_about_raw_logs_instead_of_guaranteeing_no_pii():
    source = text("Mobile/wbws_flutter_app/lib/screens/profile/profile_screen.dart")
    start = source.index("'Review the report before sharing")
    warning = source[start:source.index("style:", start)]
    assert "privately" in warning
    assert "authorized administrator" in warning
    assert "sensitive information" in warning
    assert "no personal data" not in warning
    manual = (MANUAL / "ACCOUNT_AND_DEVICE.md").read_text(encoding="utf-8")
    assert "Review/redact the report" in manual


def test_retirement_does_not_remove_the_required_migration_restore_runbook():
    for retired in ("docs/ci_cd/ARCHITECTURE.md", "docs/ci_cd/ci.yml.txt"):
        assert not (ROOT / retired).exists()
    runbook = text("docs/audits/DEPLOYMENT_RUNBOOK.md")
    for safeguard in (
        "031 -> 032 -> 033", "sql/preflight/uniqueness_preflight.sql",
        "DEPLOYMENT BLOCKER", "scripts/restore_production_dump.sh",
        "Do NOT tick", "39 / 42", "42 / 42",
    ):
        assert safeguard in runbook
    assert "not a fresh-install sequence" in runbook.lower().replace("’", "'")
