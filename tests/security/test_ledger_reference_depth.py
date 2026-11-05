"""Verify the manually authored ledger references against their cited source.

Read-only reconciliation; no generated prose, database, server or school data.
Executed PHP helper cases are deliberately separate from source-only checks.
This is not a semantic audit of every sentence or live HTTP/schema acceptance.
"""
import ast
import json
from pathlib import Path
import re
import shutil
import subprocess

import pytest

ROOT = Path(__file__).resolve().parents[2]
TABLES = (
    "finance_categories", "finance_transactions", "finance_budgets", "finance_member_fees",
    "material_categories", "material_items", "material_transactions", "material_requests",
)


def text(path):
    return (ROOT / path).read_text(encoding="utf-8")


def cases(source):
    pieces = re.split(r"(?m)^case '([a-z_]+)':", source)
    return dict(zip(pieces[1::2], pieces[2::2]))


@pytest.mark.parametrize("module,reference", [
    ("finance", "FINANCE_CONTRACT.md"), ("material", "MATERIALS_CONTRACT.md"),
])
def test_every_handler_action_and_business_field_has_a_reference(module, reference):
    handler = cases(text(f"admin/api_{module}.php"))
    document = text("docs/api/" + reference)
    headings = re.split(r"(?m)^## `([a-z_]+)`\s*$", document)
    sections = dict(zip(headings[1::2], headings[2::2]))
    assert len(handler) == 10
    assert set(sections) == set(handler)
    for action, branch in handler.items():
        fields = set(re.findall(r"\$_(?:POST|GET)\['([a-z_]+)'\]", branch))
        for field in fields:
            assert f"`{field}`" in sections[action], (module, action, field)
        table = re.search(r"(?m)^\| Field \|[^\n]*\n\|[-| ]+\|\n((?:\|[^\n]*\n)+)", sections[action])
        if table:
            documented_fields = set()
            for row in table[1].splitlines():
                documented_fields.update(re.findall(r"`([a-z_]+)`", row.split("|")[1]))
            assert documented_fields == fields, (module, action, documented_fields, fields)
    assert "not" in document.lower() and "schema" in document.lower()


@pytest.mark.parametrize("module,start,end", [
    ("finance", "## 3. Finance", "## 4. Materials"),
    ("material", "## 4. Materials", "## 5. Shared"),
])
def test_permission_matrix_reconciles_actions_and_post_restrictions(module, start, end):
    source = text(f"admin/api_{module}.php")
    part = text("docs/api/LEDGER_PERMISSIONS.md").split(start, 1)[1].split(end, 1)[0]
    rows = [line.split("|")[1:3] for line in part.splitlines() if re.match(r"^\| `[a-z_]+` \|", line)]
    actions = {row[0].strip().strip("`") for row in rows}
    writes = set(ast.literal_eval(re.search(r"requirePostActions\(\$action, (\[[^;]+\])\);", source)[1]))
    documented_writes = {row[0].strip().strip("`") for row in rows if row[1].strip() == "POST required"}
    assert actions == set(cases(source))
    assert writes == documented_writes


@pytest.mark.parametrize("table", TABLES)
def test_column_declarations_match_both_legacy_definitions_exactly(table):
    pattern = r'"CREATE TABLE IF NOT EXISTS `' + table + r'` \(\n(.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"'
    declarations = []
    for area in ("admin", "backend"):
        source = text(f"sql/legacy/php-migrations/{area}/004_add_finance_material_tables.php")
        body = re.search(pattern, source, re.S)
        assert body, table
        columns = dict(re.findall(r"^\s*`([^`]+)` (.*?)(?:,)?$", body[1], re.M))
        declarations.append({k: v.rstrip(",") for k, v in columns.items()})
    assert declarations[0] == declarations[1]
    doc = text("docs/database/LEDGER_COLUMNS.md")
    sections = re.split(r"(?m)^## \d+\. `([^`]+)`\s*$", doc)
    section = dict(zip(sections[1::2], sections[2::2]))[table].split("\n## ", 1)[0]
    documented = dict(re.findall(r"^\| `([^`]+)` \| `([^`]+)` \|$", section, re.M))
    assert documented == declarations[0], (table, documented, declarations[0])


def test_documented_fk_names_and_delete_actions_are_literal_source():
    doc = text("docs/database/LEDGER_COLUMNS.md")
    source = text("sql/003_production_hardening.sql")
    for name, action in (
        ("fk_fin_member", "SET NULL"), ("fk_fee_member", "CASCADE"),
        ("fk_mi_category", "SET NULL"), ("fk_mt_item", "CASCADE"),
    ):
        assert f"`{name}`" in doc
        assert re.search(r"ADD CONSTRAINT `" + name + r"`[^;]+ON DELETE " + action, source)
    assert "not a dump of an applied database" in doc
    assert "comments" in doc


def test_real_and_shim_role_allowlists_match_documented_sets():
    source = text("admin/access_control.php")
    doc = text("docs/api/LEDGER_PERMISSIONS.md")
    for names, expected in (
        (("api_finance.php", "finance.php"), {"super_admin", "school_admin", "finance_dept"}),
        (("api_material.php", "material.php"), {"super_admin", "school_admin", "material_dept"}),
    ):
        for name in names:
            actual = set(ast.literal_eval(re.search(r"'" + re.escape(name) + r"'\s*=>\s*(\[[^\]]+\])", source)[1]))
            assert actual == expected
        for role in expected:
            assert f"`{role}`" in doc


def test_typed_confirmation_and_capability_helpers_execute_without_database():
    php = shutil.which("php")
    if not php:
        pytest.skip("PHP CLI required for executable helper evidence")
    program = r'''
require 'admin/backend/danger_confirm.php';
require 'admin/backend/services/FeatureGate.php';
define('FEATURE_FINANCE', true);
define('FEATURE_MATERIAL', 'true');
echo json_encode([
 'phrase' => dc_phrases()['finance.delete_transaction'],
 'normalized' => dc_phrase_ok('finance.delete_transaction', ['confirm_phrase'=>' delete   transaction ']),
 'array_denied' => !dc_phrase_ok('finance.delete_transaction', ['confirm_phrase'=>['DELETE TRANSACTION']]),
 'missing_denied' => !dc_phrase_ok('finance.delete_transaction', []),
 'refusal_code' => dc_refusal('finance.delete_transaction')['code'],
 'finance_feature' => App\Services\FeatureGate::forAdminRequest('/backend/api/finance.php'),
 'material_feature' => App\Services\FeatureGate::forAdminRequest('/admin/api_material.php'),
 'finance_enabled' => App\Services\FeatureGate::isEnabled('finance'),
 'material_string_disabled' => !App\Services\FeatureGate::isEnabled('material'),
]);
'''
    result = subprocess.run([php, "-r", program], cwd=ROOT, capture_output=True, text=True, timeout=10, check=True)
    assert json.loads(result.stdout) == {
        "phrase": "DELETE TRANSACTION", "normalized": True, "array_denied": True,
        "missing_denied": True, "refusal_code": "confirm_phrase_required",
        "finance_feature": "finance", "material_feature": "material",
        "finance_enabled": True, "material_string_disabled": True,
    }
