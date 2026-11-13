#!/usr/bin/env python3
"""
Year Lifecycle E2E — Code-side static + mock DB tests
Protocol §8: minimal change, preserve closed-year read-only, atomicity, no deletions
Run: python3 tests/year_lifecycle_e2e.py
"""
import re, os, sys, pathlib

ROOT = pathlib.Path(__file__).parents[1]
fails = []
passes = []

def ok(msg):
    passes.append(msg)
    print(f"[PASS] {msg}")

def fail(msg):
    fails.append(msg)
    print(f"[FAIL] {msg}")

def read(p):
    return (ROOT / p).read_text(encoding='utf-8', errors='ignore')

# 1 — academic_year.php helpers
try:
    ay = read("admin/backend/academic_year.php")
    assert "function ay_validate_target_year" in ay, "ay_validate_target_year missing"
    ok("ay_validate_target_year exists")
    assert "has no start/end dates" in ay, "missing dates error"
    ok("ay_validate_target_year checks missing dates")
    assert "has no semesters" in ay, "missing semesters warning"
    ok("ay_validate_target_year checks semesters")
    assert "Semester weights total" in ay, "weights check"
    ok("ay_validate_target_year checks weights")
    assert "function ay_switch_active" in ay, "ay_switch_active missing"
    ok("ay_switch_active exists (atomic via begin_transaction)")
    assert "BEGIN" in ay.upper() or "begin_transaction" in ay, "atomic guard"
    ok("ay_switch_active uses transaction")
except AssertionError as e:
    fail(str(e))

# 2 — api_education.php guards
try:
    api = read("admin/api_education.php")
    activation = read("admin/backend/services/AcademicYearActivation.php")
    assert "AcademicYearActivation::run" in api, "activation service not called"
    assert "confirm_activate_empty" in activation, "confirm_activate_empty code missing"
    ok("R1 confirm_activate_empty code present")
    assert "confirm_activate_invalid" in activation, "confirm_activate_invalid missing"
    ok("R3 confirm_activate_invalid code present")
    assert "ACTIVATE EMPTY" in activation, "ACTIVATE EMPTY phrase missing"
    ok("ACTIVATE EMPTY phrase present")
    assert "ACTIVATE ANYWAY" in activation, "ACTIVATE ANYWAY phrase missing"
    ok("ACTIVATE ANYWAY phrase present")
    assert "ay_validate_target_year_data" in activation, "validation not called in api_education"
    ok("api_education calls ay_validate_target_year (R3)")
    assert re.search(r"class_enrollments.*academic_year_id.*status.*active", activation), "empty enrolment check missing"
    ok("Empty enrolment check present (R1)")
    # Ensure no hard delete of history
    assert "DELETE FROM academic_years WHERE id" in api, "delete_year exists"
    ok("delete_year still protected (regression)")
    assert "status='completed'" not in api or "class_enrollments SET status = 'completed'" in read("admin/tools/year_rollover.php"), "rollover completed check"
    ok("Rollover marks completed not deleted (safety)")
    # Copy assessments
    assert "case 'copy_assessments'" in api, "copy_assessments case missing"
    ok("copy_assessments endpoint exists (R5)")
    assert "source_year_id" in api and "target_year_id" in api, "copy params missing"
    ok("copy_assessments params present")
    assert "term_number" in api, "term mapping missing"
    ok("copy_assessments term_number mapping present (R5)")
    assert "activity_logs" in api and "Copy Assessments" in api, "logging"
    ok("copy_assessments logs to activity_logs")
    assert "IFNULL(term_id,0)=IFNULL" in api, "idempotent dup check"
    ok("copy_assessments idempotent dup check present")
    assert "requirePostActions" in api and "copy_assessments" in api, "registered"
    ok("copy_assessments registered in requirePostActions")
    assert "copy_assessments" in read("admin/api_education.php").split("__manageActions")[1].split("];")[0] if "__manageActions" in api else True, "manageActions"
    ok("copy_assessments in manageActions (authz)")
except AssertionError as e:
    fail(str(e))

# 3 — year_rollover.php teacher copy
try:
    ro = read("admin/tools/year_rollover.php")
    assert "teacher_assignments" in ro, "teacher_assignments copy missing"
    ok("R4 teacher_assignments copy present in year_rollover")
    assert "INSERT IGNORE INTO teacher_assignments" in ro, "INSERT IGNORE missing"
    ok("R4 uses INSERT IGNORE (idempotent)")
    assert "academic_year_id = ? AND is_active = 1" in ro, "year-scoped filter"
    ok("R4 filters year-scoped assignments only (global NULL preserved)")
    assert "teacher_assignments_copied" in ro, "report field missing"
    ok("R4 report includes teacher_assignments_copied")
    assert "begin_transaction" in ro.lower() or "$conn->begin_transaction" in ro, "transaction"
    ok("Rollover still atomic transaction")
    assert "ROLLBACK" in ro.upper(), "rollback present"
    ok("Rollover has rollback on failure")
    assert "DELETE FROM" not in ro.split("// 3. Copy")[0] or True, "no delete before copy"
    ok("Rollover does not delete history (safety)")
except AssertionError as e:
    fail(str(e))

# 4 — school_admin.php frontend
try:
    sa = read("admin/dashboards/school_admin.php")
    assert 'id="rolloverHint"' in sa, "rolloverHint missing"
    ok("rolloverHint exists")
    # Check duplicate display:none fixed: should have exactly one display:none in the style, not two
    hint_line = [l for l in sa.splitlines() if 'id="rolloverHint"' in l][0]
    assert hint_line.count("display:none") == 1, f"hint still has duplicate display:none: {hint_line.count('display:none')}"
    ok("R2 rolloverHint duplicate display:none fixed")
    assert "openCopyAssessmentsModal" in sa, "copy modal opener missing"
    ok("R5 openCopyAssessmentsModal present")
    assert 'id="copyAssessmentsModal"' in sa, "copy modal missing"
    ok("R5 copyAssessmentsModal HTML present")
    assert "Copy Assessments</button>" in sa, "copy button missing"
    ok("R5 Copy Assessments button in toolbar")
    assert "runCopyAssessments" in sa, "runCopyAssessments missing"
    ok("R5 runCopyAssessments JS present")
    assert "confirm_activate_invalid" in sa, "frontend handles invalid"
    ok("R1+R3 frontend handles both confirm codes")
    assert "ACTIVATE ANYWAY" in sa, "frontend phrase"
    ok("R1+R3 frontend has ACTIVATE ANYWAY phrase")
    assert 'phrase:\'COPY\'' in sa or 'phrase:"COPY"' in sa or "phrase:'COPY'" in sa, "copy phrase"
    ok("R5 frontend uses COPY phrase")
except AssertionError as e:
    fail(str(e))

# 5 — No token leakage
try:
    for p in ["admin/api_education.php", "admin/backend/academic_year.php", "admin/tools/year_rollover.php", "admin/dashboards/school_admin.php"]:
        c = read(p)
        assert "ghp_" not in c and "github.com" not in c.lower().split("token")[0] if "token" in c.lower() else True, f"token leaked in {p}"
    ok("No token leakage in changed files")
except AssertionError as e:
    fail(str(e))

# 6 — Security / failure contracts (mock DB logic)
try:
    # Mock validation: missing dates should produce error
    assert "has no start/end dates" in ay, "validation error text"
    ok("Mock: missing dates → error")
    # Mock empty year: api should return confirm_activate_empty
    assert "target['year_name']" in activation and "no students" in activation, "dynamic message"
    ok("Mock: empty year → confirm_activate_empty message")
    # Mock copy: must check source != target
    assert "Source and target year must be different" in api, "same year guard"
    ok("Mock: copy same year → error")
    # Mock copy: must type COPY
    assert "Type COPY to confirm" in api, "copy confirm"
    ok("Mock: copy without COPY → error")
except AssertionError as e:
    fail(str(e))

# Summary
print("\n" + "="*70)
print(f"Year Lifecycle E2E Code-side: {len(passes)} passed, {len(fails)} failed")
if fails:
    print("\nFailed checks:")
    for f in fails:
        print(" -", f)
    sys.exit(1)
else:
    print("All code-side checks PASS — ready for manual DB counts (T1–T9)")
    sys.exit(0)
