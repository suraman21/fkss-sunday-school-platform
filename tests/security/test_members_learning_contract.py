"""Contract pins for the Members App learning API and its unlock rules.

WHAT THIS PROTECTS
    The unlock rules are the only non-obvious logic in the member app, and the
    thing most likely to be "simplified" by someone who did not read the
    prototype. These tests fail loudly if the rules, or the self-only
    authorization that delivers them, are quietly changed.

WHY STATIC PINS RATHER THAN RUNTIME TESTS
    There is no PHP or MariaDB in the build environment. The assertions below
    check the parts that a reviewer would otherwise have to re-derive by
    reading: that identity comes from the session and not the URL, that the
    server (not the client) decides whether a lesson is open, that completion is
    idempotent, and that the unit-gate interpretation is the strict one.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
LEARNING_ROUTE = ROOT / "api/v1/routes/learning.php"
LEARNING_SERVICE = ROOT / "admin/backend/services/MembersLearningPath.php"
INDEX = ROOT / "api/v1/index.php"
SCHEMA = ROOT / "members/sql/001_members_learning_schema.sql"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def strip_comments(source: str) -> str:
    """Remove prose so a comment can never satisfy an assertion about code."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    source = re.sub(r"^\s*//.*$", "", source, flags=re.M)
    source = re.sub(r"^\s*#.*$", "", source, flags=re.M)
    return source


def strip_sql_comments(source: str) -> str:
    """SQL uses `--`, which strip_comments() deliberately does not touch.

    Without this, the string "user_id" inside a -- comment satisfied an
    assertion about COLUMNS, and the test failed for the wrong reason.
    """
    return re.sub(r"^\s*--.*$", "", source, flags=re.M)


class LearningRouteAuthorization(unittest.TestCase):
    """A member may only ever read its own work."""

    @classmethod
    def setUpClass(cls):
        cls.raw = read(LEARNING_ROUTE)
        cls.code = strip_comments(cls.raw)

    def test_route_requires_auth_and_the_member_role(self):
        self.assertIn("apiRequireAuth()", self.code)
        self.assertIn("apiRequireRole($auth, ['member'])", self.code)

    def test_identity_comes_from_the_session_never_the_url(self):
        self.assertIn("apiSelfMemberId($conn, $auth)", self.code)
        # The route must not take a member id from the request.
        for forbidden in ("$_GET['member_id']", "$_POST['member_id']",
                          "$_REQUEST['member_id']"):
            self.assertNotIn(forbidden, self.code, f"{forbidden} would be an IDOR")

    def test_no_member_id_parameter_appears_in_the_route_table(self):
        # A path like /learning/path/{memberId} would invite the same bug.
        self.assertNotIn("{member", self.raw)
        self.assertNotIn("{memberId", self.raw)

    def test_missing_link_is_denied_rather_than_guessed(self):
        self.assertIn("$selfMemberId <= 0", self.code)
        self.assertIn("NO_MEMBER_LINK", self.code)

    def test_staff_roles_are_not_admitted(self):
        # Widening this to staff would let any teacher read a child's lesson
        # state without the class-scoping rules the rest of the API enforces.
        self.assertNotIn("'parent'", self.code)
        self.assertNotIn("apiRolesStaff", self.code)
        self.assertNotIn("apiParentCanSeeMember", self.code)


class LessonAccessIsServerDecided(unittest.TestCase):
    """The `open` flag the client received is a convenience, not an authority."""

    @classmethod
    def setUpClass(cls):
        cls.code = strip_comments(read(LEARNING_ROUTE))
        cls.service = strip_comments(read(LEARNING_SERVICE))

    def test_lesson_read_checks_openness_server_side(self):
        body = self.code.split("GET /learning/lessons/{id}")[0] if False else self.code
        # Both the read and the complete path call isLessonOpen before acting.
        self.assertEqual(
            2, body.count("isLessonOpen($selfMemberId, $learningId)"),
            "reading AND completing a lesson must both re-check openness "
            "server-side; trusting the client's flag is how a locked lesson "
            "gets read."
        )

    def test_locked_lesson_returns_403_with_a_code(self):
        self.assertIn("LESSON_LOCKED", self.code)
        self.assertIn("403", self.code)

    def test_completion_is_idempotent(self):
        self.assertIn("ON DUPLICATE KEY UPDATE", self.code)
        # COALESCE keeps the FIRST completion time; overwriting it would make a
        # retry look like the student finished later than they did.
        self.assertIn("COALESCE(completed_at, NOW())", self.code)

    def test_completion_reports_already_completed_rather_than_failing(self):
        self.assertIn("already_completed", self.code)
        self.assertIn("$affected === 0", self.code)


class UnlockRules(unittest.TestCase):
    """The rule set from the prototype, including its one ambiguity."""

    @classmethod
    def setUpClass(cls):
        cls.service = strip_comments(read(LEARNING_SERVICE))

    def test_free_lessons_per_unit_is_clamped(self):
        # An unbounded value opens a whole course by accident; 0 locks unit 1's
        # first lesson, which contradicts "unit 1 is always open".
        self.assertIn("max(1, min(50, $freeLessonsPerUnit))", self.service)

    def test_first_unit_is_always_open(self):
        self.assertIn("$unitIsFirst", self.service)

    def test_later_units_need_previous_complete_and_quiz_pass(self):
        self.assertIn("$previousUnitComplete", self.service)
        self.assertIn("$passedQuizzes", self.service)

    def test_a_teacher_unlock_is_required_even_when_work_is_done(self):
        """The prototype is explicit; this is a product decision, not an oversight.

        backend/api/learning.php:120-126 --
            $u["open"] = $prevDone && $u["teacher_unlocked"];

        So finishing every lesson and passing the quiz does NOT open the next
        unit. A teacher still opens it. An earlier version of this service let
        completed work open the next unit on its own, which was wrong in the
        student's favour and would have quietly removed the teacher's control.
        """
        self.assertIn("$unitUnlocked = $unitIsFirst || $manuallyUnlocked;", self.service)
        self.assertNotIn("|| ($previousUnitComplete", self.service)

    def test_locked_reason_reads_the_previous_unit_not_the_current_one(self):
        # Reporting "previous_quiz_not_passed" because THIS unit has no quiz set
        # is a wrong answer to the student's only question: what do I do next.
        self.assertIn("$previousQuizOk", self.service)
        self.assertIn("'previous_quiz_not_passed'", self.service)
        self.assertNotIn("'quiz_not_passed'", self.service)

    def test_a_unit_with_no_quiz_counts_as_satisfied(self):
        # prototype: !quiz_required || (passed && lessons done). Requiring a
        # quiz that does not exist would block the course forever.
        self.assertIn("($quiz === null) || $quizPassed", self.service)

    def test_locked_reason_is_machine_readable_not_a_sentence(self):
        # The app speaks two languages; the API must not pick the words.
        for reason in ("previous_unit_incomplete", "previous_quiz_not_passed",
                       "awaiting_teacher_unlock"):
            self.assertIn(reason, self.service)
        # No user-facing English sentence in the returned reasons.
        self.assertNotIn("Your teacher", self.service)

    def test_completed_lessons_stay_open(self):
        self.assertIn("if ($isDone)", self.service)
        self.assertIn("$isOpen = true", self.service)

    def test_expired_unlocks_do_not_count(self):
        # Two unlock lookups exist: lesson-level (no alias) and unit-level
        # (prefixed `u.` because it joins members_lessons). Both must filter on
        # expiry, or a lapsed grant becomes permanent access.
        #
        # Asserted on the two exact predicates rather than by parsing the SQL:
        # the queries are built from concatenated string literals, and an
        # earlier regex version split on a quote and matched nothing — a test
        # that passes for the wrong reason is worse than no test.
        self.assertIn(
            "expires_at IS NULL OR expires_at > NOW()", self.service,
            "the lesson-level unlock lookup ignores expiry"
        )
        self.assertIn(
            "u.expires_at IS NULL OR u.expires_at > NOW()", self.service,
            "the unit-level unlock lookup ignores expiry"
        )
        # And nothing looks up an unlock without filtering it.
        self.assertEqual(
            2, self.service.count("FROM members_lesson_unlocks"),
            "an unlock lookup was added; check it filters on expires_at"
        )

    def test_path_is_built_from_a_fixed_number_of_queries(self):
        # One round trip per table, not per lesson: 40 round trips on 2G is the
        # difference between an app and a spinner.
        self.assertIn("function pathForMember", self.service)
        body = self.service.split("function pathForMember")[1].split("function lockedReason")[0]
        self.assertEqual(4, body.count("->query("),
                         "pathForMember should issue a fixed number of queries, "
                         "not one per lesson")


class ApiSurface(unittest.TestCase):
    def test_learning_resource_is_registered(self):
        self.assertIn("'learning'      => 'learning.php'", read(INDEX))

    def test_route_file_documents_its_endpoints(self):
        raw = read(LEARNING_ROUTE)
        for endpoint in ("GET  /learning/path", "GET  /learning/lessons/{id}",
                         "POST /learning/lessons/{id}/complete"):
            self.assertIn(endpoint, raw)

    def test_unknown_action_is_404_not_a_silent_success(self):
        code = strip_comments(read(LEARNING_ROUTE))
        self.assertIn("No handler matched", code)
        self.assertIn("404", code)


class SchemaGuarantees(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql = read(SCHEMA)

    def test_progress_is_unique_per_member_and_lesson(self):
        # Without this, a retried "complete" inserts a second row and every
        # COUNT(*) overstates progress.
        self.assertIn("UNIQUE KEY `uniq_members_progress` (`member_id`, `lesson_id`)", self.sql)

    def test_progress_is_keyed_by_member_not_user(self):
        # A lesson belongs to a student; a student may hold no login at all.
        self.assertIn("`member_id` INT UNSIGNED NOT NULL", self.sql)
        code = strip_sql_comments(self.sql)
        self.assertNotIn("user_id", code,
                         "a learning table keyed by users.id loses a child's "
                         "work when their account is replaced")

    def test_schema_is_destructive_free(self):
        code = strip_comments(self.sql).upper()
        for forbidden in ("DROP TABLE", "TRUNCATE", "DELETE FROM"):
            self.assertNotIn(forbidden, code, forbidden)

    def test_schema_is_idempotent(self):
        self.assertNotIn("CREATE TABLE `", self.sql)
        self.assertIn("CREATE TABLE IF NOT EXISTS", self.sql)


if __name__ == "__main__":
    unittest.main()
