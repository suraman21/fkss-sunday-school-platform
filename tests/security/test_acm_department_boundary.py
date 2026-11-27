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


class IdentityIsReadLiveNeverCopied(unittest.TestCase):
    """ACM stores a REFERENCE to a person, never a copy of one.

    WHY THIS IS THE POINT OF THE FILE
        edu_dept reads `members` directly and owns no roster of its own: there
        is nothing to sync because nothing is copied. ACM must work the same
        way. A copied name is a second place a name lives, and a stale copy
        means the office verifies somebody against a spelling HR corrected a
        year ago. It also means nothing to backfill -- a member registered five
        years ago and one registered this minute are equally visible.

    So `acm_persons` is an ACCOUNT ANCHOR: the row tickets/accounts/suspensions
    point at, plus the couple of annotations ACM genuinely owns. Identity comes
    from the register at read time.
    """

    def setUp(self):
        self.raw = read(ACM / "src" / "Identity" / "AcmPersonService.php")
        self.persons = strip_comments(self.raw)

    def test_member_anchor_copies_no_identity_into_acm_persons(self):
        body = self.persons.split("public function ensureForMember")[1]
        body = body.split("private function insertAnchor")[0]
        self.assertIn("insertAnchor", body)
        # The old behaviour read the member row and copied these across.
        for copied in ("baptismal_name", "father_name", "grandfather_name",
                       "date_of_birth", "phone_primary"):
            self.assertNotIn(copied, body,
                             f"{copied} must not be copied into acm_persons")

    def test_guardian_anchor_copies_no_identity_either(self):
        body = self.persons.split("public function ensureForGuardian")[1]
        body = body.split("public function personIdForGuardian")[0]
        self.assertNotIn("display_name", body.split("insertAnchor")[0].replace("SELECT id, status", ""))
        self.assertIn("insertAnchor", body)

    def test_view_reads_the_register_at_request_time(self):
        body = self.persons.split("public function view(")[1]
        self.assertIn("FROM members", body)
        self.assertIn("FROM guardians", body)
        # Family comes from HR's link table, with its verification stamp.
        self.assertIn("member_guardians", body)
        self.assertIn("verified_by", body)

    def test_view_exposes_only_acm_owned_annotations_as_editable(self):
        body = self.persons.split("public function view(")[1]
        profile = body.split("$profile = [")[1].split("];")[0]
        # The editable slice must not contain register fields.
        for registered in ("full_name", "student_name", "father_name",
                           "current_section", "phone"):
            self.assertNotIn(registered, profile,
                             f"{registered} belongs to HR, not to ACM's profile")
        self.assertIn("id_doc_ref", profile)
        self.assertIn("notes", profile)

    def test_console_renders_the_live_view_not_the_anchor(self):
        page = strip_comments(read(ADMIN / "acm_person.php"))
        self.assertIn("->view(", page)
        self.assertIn("Read live from the", page)
        # The old card printed copied fields straight off $person.
        self.assertNotIn("$person['full_name_am']", page)

    def test_role_flags_are_surfaced_from_the_register(self):
        page = read(ADMIN / "acm_person.php")
        for col in ("is_teacher", "is_staff", "is_committee", "is_volunteer"):
            self.assertIn(col, page, col)


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
