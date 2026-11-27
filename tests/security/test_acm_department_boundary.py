"""HR owns people. ACM owns access. This file is the fence between them.

WHY THIS EXISTS
    ACM grew a "Register person" form that created a second, independent record
    of a human being by hand. It was load-bearing without anyone knowing: a
    member with no acm_persons row could not activate at all (the `no_account`
    refusal). That made a manual re-entry step the precondition for the whole
    self-service product, which is the wrong department's job -- the same
    mistake as asking edu_dept to register students before it can assign them
    to a class.

    The owner's rule: registration's source of truth is HR and HR only. ACM
    projects the register into its own tables and then manages ACCOUNTS --
    activation, password reset, suspension -- and nothing else.

    A removed capability cannot be re-enabled by a role grant. A hidden button
    can. So these assertions are about ABSENCE, which is why they look odd:
    they fail when somebody helpfully adds a form back.

WHAT THESE CANNOT DO
    There is no PHP binary or database in this sandbox. These are source-level
    assertions: they prove no ACM file writes to HR's tables, not that a
    projection produces correct rows.
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ADMIN = ROOT / "admin"
ACM = ROOT / "acm"
API_ROUTES = ROOT / "api" / "v1" / "routes"

# Files that belong to the ACM department's own surface.
ACM_OWNED = [
    ADMIN / "acm_person.php",
    ADMIN / "acm_office.php",
    ADMIN / "acm_overview.php",
    ADMIN / "acm_queue.php",
    ADMIN / "acm_request.php",
    ADMIN / "acm_requests.php",
    ADMIN / "acm_ticket.php",
    ADMIN / "acm_audit.php",
    ADMIN / "acm_device_recovery.php",
    ADMIN / "acm_staff_enroll.php",
]

# The register tables HR owns. ACM may read them; it may never write them.
HR_TABLES = ("members", "guardians", "member_guardians")


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def strip_comments(source: str) -> str:
    """Remove PHP and SQL comments so prose cannot satisfy a check."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    source = re.sub(r"^\s*--.*$", "", source, flags=re.M)
    source = re.sub(r"^\s*#.*$", "", source, flags=re.M)
    source = re.sub(r"^\s*//.*$", "", source, flags=re.M)
    return source


class AcmNeverWritesTheRegister(unittest.TestCase):
    """ACM is a reader of `members`/`guardians`, never a writer."""

    def test_no_acm_console_file_writes_an_hr_table(self):
        offenders = []
        for path in ACM_OWNED:
            if not path.exists():
                continue
            sql = strip_comments(read(path))
            for table in HR_TABLES:
                for verb in ("INSERT INTO", "UPDATE", "DELETE FROM"):
                    # UPDATE `members` / UPDATE members (backticks optional)
                    pattern = rf"{verb}\s+`?{table}`?\b"
                    if re.search(pattern, sql, re.I):
                        offenders.append(f"{path.name}: {verb} {table}")
        self.assertEqual([], offenders,
                         "ACM must not write HR's tables; it projects and reads.")

    def test_acm_package_never_writes_the_register_either(self):
        """The service layer is where a 'quick fix' would hide."""
        offenders = []
        for path in (ACM / "src").rglob("*.php"):
            sql = strip_comments(read(path))
            for table in HR_TABLES:
                for verb in ("INSERT INTO", "UPDATE", "DELETE FROM"):
                    pattern = rf"{verb}\s+`?{table}`?\b"
                    # AcmOfficeService legitimately reads these; writes are not.
                    if re.search(pattern, sql, re.I):
                        offenders.append(f"{path.name}: {verb} {table}")
        self.assertEqual([], offenders)


class NoManualRegistrationPath(unittest.TestCase):
    """The registrar role is removed, not hidden behind a role check."""

    def test_person_create_endpoint_is_gone(self):
        api = strip_comments(read(ADMIN / "api_acm.php"))
        self.assertNotIn("person_create", api,
                         "a removed capability cannot be re-enabled by a role grant")

    def test_policy_grants_no_person_creation(self):
        policy = strip_comments(read(ACM / "src" / "Policy" / "AcmPolicy.php"))
        person_actions = re.search(r"'person'\s*=>\s*\[([^\]]*)\]", policy)
        if person_actions:
            self.assertNotIn(
                "'create'", person_actions.group(1),
                "ACM does not create people; HR does. The policy must not grant it.",
            )

    def test_console_has_no_register_person_form(self):
        page = strip_comments(read(ADMIN / "acm_person.php"))
        self.assertNotIn("acmPersonForm", page)
        self.assertNotIn("Register person", page)


class ProjectionIsDerivedNotTyped(unittest.TestCase):
    """The person row comes from the register, on demand, idempotently."""

    def setUp(self):
        self.persons = strip_comments(read(ACM / "src" / "Identity" / "AcmPersonService.php"))

    def test_member_projection_reads_the_register(self):
        body = self.persons.split("public function ensureForMember")[1]
        body = body.split("public function personIdForMember")[0]
        self.assertIn("FROM members", body)
        # Derived fields, not caller-supplied ones.
        for column in ("full_name_am", "father_name", "grandfather_name",
                       "date_of_birth", "phone_primary", "member_id"):
            self.assertIn(column, body, column)

    def test_projection_returns_an_existing_row_untouched(self):
        body = self.persons.split("public function ensureForMember")[1]
        body = body.split("public function personIdForMember")[0]
        # Fast path first: if a person exists, nothing is written.
        self.assertLess(body.index("personIdForMember"), body.index("INSERT INTO acm_persons")
                        if "INSERT INTO acm_persons" in body else len(body))

    def test_uniqueness_limit_is_stated_not_implied(self):
        """sql/079 gives member_id a plain KEY. The code must not pretend otherwise."""
        sql = read(ROOT / "sql" / "079_acm_p0_foundation.sql")
        self.assertNotRegex(sql, r"UNIQUE[^\n]*member_id",
                            "if a unique key is added, update the documented limit")
        # The limit is documented in prose, so this one reads the RAW file:
        # strip_comments() would delete the very sentence being asserted.
        raw = read(ACM / "src" / "Identity" / "AcmPersonService.php")
        self.assertIn("NOT a UNIQUE", raw,
                      "the honest limit must be stated where the code lives")


class ParentProjectionInheritsTheVerifiedLinkRule(unittest.TestCase):
    """A parent account must only be projected from a VERIFIED family link.

    apiParentCanSeeMember filters on verified_by IS NOT NULL, so projecting an
    unverified link would create a parent account that sees no children -- the
    silent failure that presents as "the app is broken".
    """

    def test_parent_reach_still_requires_verification(self):
        acl = read(API_ROUTES / ".." / "core" / "acl.php")
        self.assertIn("verified_by IS NOT NULL", acl)

    def test_design_document_states_the_inherited_rule(self):
        doc = read(ROOT / "docs" / "design" / "acm-department-boundaries.md")
        self.assertIn("verified", doc.lower())
