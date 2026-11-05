"""Source-only navigation/trace regressions, not full manual/runtime acceptance.

No network, database, credentials, app execution or dependency changes.
Tests protect cited paths/actions and preserve explicit incomplete coverage.
They do not prove the accuracy of every sentence or the absence of UI bugs.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(path):
    return (ROOT / path).read_text(encoding="utf-8")


def test_developer_entry_points_are_discoverable():
    index = read("docs/README.md")
    for path in (
        "DEVELOPER_GUIDE.md", "CHANGE_IMPACT.md", "QUALITY_GATES.md",
        "architecture/SECURITY_MODEL.md", "../AGENTS.md",
    ):
        assert f"]({path})" in index, path
    onboarding = read("docs/ONBOARDING.md")
    assert "](DEVELOPER_GUIDE.md#2-worked-exercise-record-a-paid-member-fee)" in onboarding
    assert "](../AGENTS.md)" in onboarding


def test_root_agent_guide_has_source_configured_markdown_deny_rule():
    # Apache configuration evidence only, not a live host 403 test.
    rules = re.findall(r"^\s*RewriteRule\s+(\S+)\s+-\s+\[F,L\]", read(".htaccess"), re.M)
    assert any(re.search(rule, "AGENTS.md") for rule in rules)
    assert (ROOT / "AGENTS.md").is_file()


def test_finance_documented_active_route_still_matches_source():
    router = read("admin/dashboard.php")
    branch = router.split("case 'finance_dept':", 1)[1].split("break;", 1)[0]
    assert "header('Location: /frontend/pages/finance_dept.php')" in branch
    shim = read("backend/api/finance.php")
    assert "require_once __DIR__ . '/../../admin/api_finance.php'" in shim
    guide = read("docs/DEVELOPER_GUIDE.md")
    for path in (
        "../admin/dashboard.php", "../frontend/pages/finance_dept.php",
        "../frontend/js/finance.js", "../frontend/js/core.js",
        "../backend/api/finance.php", "../admin/api_finance.php",
    ):
        assert f"]({path})" in guide


def test_materials_documented_route_and_transport_still_match_source():
    branch = read("admin/dashboard.php").split("case 'material_dept':", 1)[1].split("break;", 1)[0]
    assert "require __DIR__ . '/dashboards/material_department.php'" in branch
    ui = read("admin/dashboards/material_department.php")
    assert "const API='/admin/api_material.php'" in ui
    assert "fd.append('csrf_token',CSRF)" in ui
    assert "material_department.php" in read("docs/user_manual/MATERIALS.md")


def test_new_manual_action_references_exist_in_real_handlers():
    for chapter, handler in (
        ("FINANCE.md", "admin/api_finance.php"),
        ("MATERIALS.md", "admin/api_material.php"),
    ):
        body = read("docs/user_manual/" + chapter)
        reference = body.split("## Core control-to-handler reference", 1)[1]
        table_rows = "\n".join(line for line in reference.splitlines() if line.startswith("|"))
        documented = set(re.findall(r"→\s*`([a-z_]+)`", table_rows))
        actions = set(re.findall(r"case\s+'([a-z_]+)'\s*:", read(handler)))
        assert len(documented) >= 5, chapter
        assert documented <= actions, (chapter, documented - actions)
        assert "no browser/device acceptance" in body, chapter
        assert "not" in body and "exhaustive" in body, chapter


def test_mobile_department_notices_remain_website_only():
    for domain, title, chapter in (
        ("finance", "Finance stays on the website", "FINANCE.md"),
        ("material", "Materials stay on the website", "MATERIALS.md"),
    ):
        source = read(f"Mobile/wbws_flutter_app/lib/screens/{domain}/{domain}_home.dart")
        assert title in source
        assert "UseWebsiteNote(" in source
        assert title in read("docs/user_manual/" + chapter)


def test_selected_ledger_contract_form_keys_match_action_source():
    document = read("docs/api/LEDGER_ACTIONS.md")
    for heading, handler, action in (
        ("2. Finance", "admin/api_finance.php", "save_fee"),
        ("3. Materials", "admin/api_material.php", "add_transaction"),
        ("4. Materials", "admin/api_material.php", "update_request"),
    ):
        section = document.split("## " + heading, 1)[1].split("\n## ", 1)[0]
        fields = set(re.findall(r"^\| `([a-z_]+)` \|", section, re.M))
        branch = read(handler).split("case '" + action + "':", 1)[1].split("\ncase '", 1)[0]
        posted = set(re.findall(r"\$_POST\['([a-z_]+)'\]", branch))
        # Dispatch and CSRF are checked before the individual switch branch.
        assert fields == posted | {"action", "csrf_token"}, (action, fields, posted)
    assert "](api/LEDGER_ACTIONS.md)" in read("docs/README.md")


def test_exhaustive_manual_remains_active_and_incomplete():
    coverage = read("docs/user_manual/COVERAGE.md")
    assert "INCOMPLETE — ADDITIONAL WORK REQUIRED" in coverage
    assert "active requested work, not deferred" in coverage
    for chapter in ("FINANCE.md", "MATERIALS.md"):
        assert f"]({chapter})" in read("docs/user_manual/README.md")
        assert f"]({chapter})" in coverage
    assert "shared controls and browser acceptance pending" in coverage
    catalog = read("docs/DOCUMENTATION_CATALOG.md")
    assert "not deferred" in catalog
    assert "not complete" in catalog
