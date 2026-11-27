"""Contract pins for /api/v1/my — the caller's own record.

THE DESIGN DECISION THESE TESTS PROTECT
    `GET /members/{id}` shapes its payload by the CALLER'S ROLE: staff receive
    apiMemberStaffRow (phones, guardian contact), members and parents receive
    apiMemberSafeRow (no phone, no address). So a member opening their OWN page
    through that route gets the safe row -- their own phone number is treated
    like somebody else's.

    The fix was NOT to teach that route about selfhood. An id-taking route that
    also admits the member role is the IDOR the existing comment warns about.
    Instead `/my/*` takes no id at all: identity comes from the token, and the
    field set is decided once for a member reading themselves.

    If a future editor "simplifies" /my into /members/{id}, or adds an id
    parameter, or starts emitting guardian phone numbers, one of these fails.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
MY_ROUTE = ROOT / "api/v1/routes/my.php"
INDEX = ROOT / "api/v1/index.php"
MEMBERS_ROUTE = ROOT / "api/v1/routes/members.php"
ACL = ROOT / "api/v1/core/acl.php"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def strip_comments(source: str) -> str:
    """Remove prose so a comment can never satisfy an assertion about code."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    source = re.sub(r"^\s*//.*$", "", source, flags=re.M)
    source = re.sub(r"^\s*#.*$", "", source, flags=re.M)
    return source


class MyRouteTakesNoId(unittest.TestCase):
    """Identity comes from the token, full stop."""

    @classmethod
    def setUpClass(cls):
        cls.raw = read(MY_ROUTE)
        cls.code = strip_comments(cls.raw)

    def test_requires_auth_and_the_member_role(self):
        self.assertIn("apiRequireAuth()", self.code)
        self.assertIn("apiRequireRole($auth, ['member'])", self.code)

    def test_identity_comes_from_the_session(self):
        self.assertIn("apiSelfMemberId($conn, $auth)", self.code)

    def test_no_request_supplied_member_id(self):
        for forbidden in ("$_GET['member_id']", "$_POST['member_id']",
                          "$_REQUEST['member_id']", "$ROUTE['id']"):
            self.assertNotIn(forbidden, self.code, f"{forbidden} would be an IDOR")

    def test_no_id_placeholder_in_the_route(self):
        # Asserted on CODE, not on prose: the docblock legitimately contrasts
        # /my with `/members/{id}`, and an earlier version of this test failed
        # because it searched the whole file including comments.
        self.assertNotIn("{id}", self.code)
        self.assertNotIn("{member", self.code)

    def test_action_is_read_from_the_id_slot_not_the_sub_slot(self):
        """A REGRESSION GUARD WITH A REAL BUG BEHIND IT.

        The router splits a route into [resource, id, sub]. For /learning the
        shape is /learning/lessons/{id}, so the verb sits in `sub`. For /my the
        shape is /my/profile, so `profile` lands in the ID slot and `sub` is
        null. Copying the /learning line made every /my request fall through to
        the final 404 while still parsing cleanly and passing every other test
        here -- the failure was invisible to static checks and only visible by
        reading the router or issuing a request.
        """
        self.assertNotIn("$ROUTE['sub']", self.code,
                         "/my/{action} puts the action in parts[1], not sub")
        self.assertIn("$ROUTE['parts']", self.code)
        self.assertIn("$mySegments[1]", self.code)

    def test_a_numeric_segment_is_not_treated_as_an_action(self):
        # /my/12 must not quietly answer as anything; it must fall through.
        self.assertIn("!ctype_digit($mySegment)", self.code)

    def test_missing_link_denies_rather_than_guesses(self):
        self.assertIn("$selfMemberId <= 0", self.code)
        self.assertIn("NO_MEMBER_LINK", self.code)

    def test_parents_are_not_served_here(self):
        # A parent asking about a child goes through apiParentCanSeeMember on a
        # different route; admitting them here would bypass the verified link.
        self.assertNotIn("'parent'", self.code)
        self.assertNotIn("apiParentCanSeeMember", self.code)
        self.assertNotIn("apiRoleIs($auth, apiRolesPii())", self.code)


class OwnPhoneIsAllowedGuardianPhoneIsNot(unittest.TestCase):
    """The line between "my data" and "somebody else's data"."""

    @classmethod
    def setUpClass(cls):
        cls.code = strip_comments(read(MY_ROUTE))

    def test_own_contact_details_are_exposed(self):
        # A student who cannot check their contact details cannot report that
        # the office has the wrong number.
        self.assertIn("phone_primary", self.code)
        self.assertIn("'phone' =>", self.code)
        self.assertIn("'email' =>", self.code)

    def test_guardian_contact_is_never_exposed(self):
        # A parent's phone number is the PARENT'S. The family link exists; that
        # is not permission to publish it to the child.
        for forbidden in ("guardian_phone", "guardians.phone", "display_name",
                          "guardian_name", "member_guardians"):
            self.assertNotIn(forbidden, self.code)

    def test_staff_only_columns_are_not_selected(self):
        for forbidden in ("recorded_by", "created_by", "updated_by"):
            self.assertNotIn(forbidden, self.code)

    def test_no_other_member_is_reachable(self):
        # Every query must be filtered by the session member id.
        selects = re.findall(r"(?:FROM|JOIN)\s+`?(\w+)`?", self.code)
        self.assertIn("attendance", selects)
        for table in ("members", "attendance", "academic_records"):
            self.assertIn(table, selects, f"expected a query against {table}")
        # And the id used in those queries is the session one.
        self.assertGreaterEqual(self.code.count("$selfMemberId"), 4)


class DepartmentStatus(unittest.TestCase):
    """'Am I active on education and mezmur?' must have a truthful answer."""

    @classmethod
    def setUpClass(cls):
        cls.code = strip_comments(read(MY_ROUTE))

    def test_both_departments_are_reported(self):
        self.assertIn("'edu' => 'active'", self.code)
        self.assertIn("'mezmur' => 'active'", self.code)

    def test_uses_the_existing_department_service(self):
        self.assertIn("DepartmentStatusService", self.code)
        self.assertIn("::statuses(", self.code)

    def test_absence_of_a_record_means_active_not_inactive(self):
        """A department row is written only when somebody is SUSPENDED.

        So a member with no row has not been suspended from anything. Reporting
        "inactive" for the absence of a row would tell a student they were
        removed from a department nobody removed them from.
        """
        self.assertIn("'default'", self.code)
        self.assertIn("$statusSource = 'default'", self.code)

    def test_degrades_safely_when_the_service_is_missing(self):
        self.assertIn("is_file($dsPath)", self.code)
        self.assertIn("class_exists(", self.code)


class AttendanceMaths(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.code = strip_comments(read(MY_ROUTE))

    def test_late_counts_as_attended(self):
        # The school's rule, and the prototype's: present + late over all
        # sessions. Counting a late as an absence would punish a student who
        # turned up.
        self.assertIn("$counts['present'] + $counts['late']", self.code)

    def test_holidays_are_not_in_the_denominator(self):
        denominator = self.code.split("$denominator =")[1].split(";")[0]
        self.assertNotIn("holiday", denominator,
                         "a holiday is not a day the student could have attended")

    def test_empty_history_reports_null_not_zero(self):
        # A student with no records has not attended 0% of anything.
        self.assertIn("$denominator > 0 ? (int)round", self.code)
        self.assertIn(": null", self.code)

    def test_window_is_bounded(self):
        self.assertIn("max(7, min(365, $days))", self.code)


class Grades(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.code = strip_comments(read(MY_ROUTE))

    def test_reads_the_school_ledger_not_a_copy(self):
        self.assertIn("academic_records", self.code)
        self.assertIn("WHERE ar.member_id = ?", self.code)

    def test_average_excludes_unmarked_assessments(self):
        # Averaging nulls as zero punishes a student for work not yet marked.
        self.assertIn("array_filter(", self.code)
        self.assertIn("$p !== null", self.code)

    def test_missing_schema_is_a_409_not_an_empty_list(self):
        # "You have no grades" and "grades are not switched on" are different
        # messages, and only one of them is true.
        self.assertIn("GRADES_UNAVAILABLE", self.code)
        self.assertIn("409", self.code)


class ExistingSurfaceUntouched(unittest.TestCase):
    """/members/{id} keeps its role-shaped behaviour; /my is the addition."""

    def test_member_detail_still_role_shaped(self):
        members = read(MEMBERS_ROUTE)
        self.assertIn(
            "ok($canPii ? apiMemberStaffRow($member) : apiMemberSafeRow($member));",
            members,
            "/members/{id} shaping must not be loosened for the member app",
        )

    def test_safe_row_still_excludes_phones(self):
        acl = read(ACL)
        fn = acl[acl.index("function apiMemberSafeRow"):]
        fn = fn[: fn.index("\n}\n")]
        for forbidden in ("phone_number", "phone_primary", "alt_phone_number"):
            self.assertNotIn(forbidden, fn,
                             "apiMemberSafeRow must stay contact-free for "
                             "members and parents reading OTHER people")

    def test_resource_is_registered(self):
        self.assertIn("'my'            => 'my.php'", read(INDEX))


if __name__ == "__main__":
    unittest.main()
