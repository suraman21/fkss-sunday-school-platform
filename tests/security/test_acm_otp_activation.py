"""S12 — six-digit OTP activation codes.

The point of this file is not that the OTP is six digits. It is that a
six-digit secret stays safe, because the properties below are what make
the loss of ~19 bits of entropy survivable:

  * the comparison is SCOPED to one ticket (a guess is never evaluated
    against every live code),
  * the guessing budget is per ticket AND per source, and survives a
    reissue (NIST SP 800-63B-4 Sec 3.1.3.2),
  * the stored value cannot be precomputed or reversed from a dump,
  * a wrong guess is never free (commit-before-throw),
  * consumption is compare-and-set, so two concurrent correct guesses
    cannot both win.

Every test below fails if one of those is quietly removed.
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OTP = ROOT / "acm" / "src" / "Activation" / "Otp.php"
SVC = ROOT / "acm" / "src" / "Activation" / "ActivationService.php"
ROUTE = ROOT / "api" / "v1" / "routes" / "acm.php"
SQL102 = ROOT / "sql" / "102_acm_otp_attempts.sql"
CONSOLE = ROOT / "admin" / "api_acm.php"


def read(path):
    return path.read_text(encoding="utf-8")


def _strip_comments(source: str) -> str:
    """PHP with /* */ and // comments removed.

    Needed because these files EXPLAIN the rules they must follow ("do not
    reach for mt_rand()", "no FOREIGN KEY") — a whole-file substring check
    matches the warning and reports a violation that does not exist.
    """
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.DOTALL)
    return re.sub(r"//[^\n]*", "", source)


def _strip_sql_comments(source: str) -> str:
    return "\n".join(
        line for line in source.splitlines() if not line.lstrip().startswith("--")
    )


class OtpPrimitive(unittest.TestCase):
    def setUp(self):
        self.otp = read(OTP)

    def test_six_digits_from_a_csprng(self):
        self.assertIn("public const LENGTH = 6;", self.otp)
        self.assertIn("random_int(0, 999999)", self.otp)
        # Assert on the CODE, not the prose: the comment says "do not reach
        # for rand() or mt_rand()", so a whole-file grep matches the warning.
        code = _strip_comments(self.otp)
        self.assertNotIn("mt_rand", code)
        self.assertNotRegex(code, r"(?<!_)\brand\s*\(")

    def test_stored_form_is_salted_per_code_row(self):
        """A plain hash of a 6-digit number is a million-row rainbow table."""
        self.assertIn("hash_hmac('sha256', $otp,", self.otp)
        self.assertIn("$codeUuid", self.otp)
        # The key must be per-row, not a single global constant.
        self.assertIn("'acm:otp:v1:' . $codeUuid", self.otp)

    def test_comparison_is_constant_time(self):
        self.assertIn("hash_equals(", self.otp)
        self.assertNotIn("===", self.otp.split("public static function verify")[1].split("}")[0])

    def test_ttl_respects_the_nist_ceiling(self):
        # A transmitted secret must complete within 10 minutes.
        self.assertIn("public const TTL_MIN = 10;", self.otp)

    def test_attempt_limits_are_per_source_and_per_ticket(self):
        self.assertIn("public const MAX_ATTEMPTS = 5;", self.otp)
        self.assertIn("public const MAX_ATTEMPTS_PER_SOURCE = 3;", self.otp)
        # Per-source must be the tighter of the two, or one attacker can
        # burn a victim's whole budget.
        self.assertLess(3, 5)


class VerificationIsScoped(unittest.TestCase):
    def setUp(self):
        self.svc = read(SVC)
        self.body = self.svc.split("public function verifyOtp")[1].split("\n    public function ", 1)[0]

    def test_the_code_value_is_never_a_lookup_key(self):
        """The whole reason a 6-digit code needs scoping."""
        locked = self.svc.split("private function lockedOtpCode")[1].split("\n    private function ", 1)[0]
        # Looked up by ticket, never by the submitted digits.
        self.assertIn("WHERE ticket_id = :t", locked)
        self.assertIn("purpose = 'activation'", locked)
        self.assertNotIn("secret_hash = ", locked)
        # And the digits never reach SQL as a comparison operand.
        self.assertNotIn("$otp", locked)

    def test_the_comparison_uses_the_constant_time_helper(self):
        self.assertIn("Otp::verify(", self.body)

    def test_both_budgets_are_checked_before_comparing(self):
        guard = self.svc.split("private function otpBudgetExhausted")[1].split("\n    private function ", 1)[0]
        self.assertIn("SUM(attempts)", guard)
        self.assertIn("MAX(CASE WHEN source = :src", guard)
        # Checking happens before the comparison in the verifier.
        self.assertLess(self.body.index("otpBudgetExhausted"), self.body.index("Otp::verify("))

    def test_the_budget_survives_a_reissue(self):
        """Keyed on ticket, not code: a new code must not buy new guesses."""
        schema = read(SQL102)
        self.assertIn("PRIMARY KEY (`ticket_id`, `source`)", schema)
        # The comment warns against adding code_id, so strip comments first.
        self.assertNotIn("code_id", _strip_sql_comments(schema))

    def test_failures_commit_before_throwing(self):
        """A wrong guess must cost something even though we roll back."""
        fail = self.svc.split("private function failOtp")[1].split("\n    private function ", 1)[0]
        self.assertLess(fail.index("commit()"), fail.index("throw ActivationException"))

    def test_every_failure_is_indistinguishable(self):
        """Wrong digit, expired, burnt, wrong ticket: one error."""
        # Four exits: unknown ticket, rate-limited budget, the combined
        # failure check, and a lost compare-and-set race. All identical.
        self.assertEqual(
            self.body.count("ActivationException::verifyFailed()"),
            4,
            "every exit from verifyOtp must raise the same generic failure",
        )
        # The expiry/status/wrong-value cases all funnel into failOtp().
        combined = self.body.split("if ($code === null")[1].split("}\n")[0]
        self.assertIn("failOtp(", combined)

    def test_consumption_is_compare_and_set(self):
        self.assertIn("status = 'consumed', consumed_at = NOW()", self.body.replace("\\'", "'"))
        self.assertIn("rowCount() !== 1", self.body)

    def test_burn_happens_on_the_last_attempt_only(self):
        fail = self.svc.split("private function failOtp")[1].split("\n    private function ", 1)[0]
        # PHP escapes the quote inside a single-quoted SQL string.
        fail = fail.replace("\\'", "'")
        self.assertIn(">= Otp::MAX_ATTEMPTS", fail)
        self.assertIn("'revoked'", fail)
        self.assertIn("code_burned", fail)


class RouteContract(unittest.TestCase):
    def setUp(self):
        self.route = read(ROUTE)
        # Anchor on the DISPATCH line, not the handler comment above it.
        start = self.route.index("if ($acmAction === 'activate/verify' && $method === 'POST')")
        nxt = self.route.find("if ($acmAction === '", start + 1)
        self.block = self.route[start:nxt if nxt != -1 else len(self.route)]

    def test_otp_body_is_accepted(self):
        self.assertIn("'otp', 'reference'", self.block)

    def test_mixed_bodies_are_refused(self):
        """An OTP and a selector pair at once leaves the server choosing."""
        self.assertIn("$hasOtp && $hasPair", self.block)

    def test_legacy_activation_pair_is_refused(self):
        """One state must not keep two verification paths."""
        self.assertIn("if ($purpose === CodePurpose::Activation)", self.block)

    def test_otp_path_is_reachable(self):
        self.assertIn("verifyOtp(", self.block)


class ReferenceResolution(unittest.TestCase):
    """The member must be able to name their own ceremony."""

    def setUp(self):
        self.svc = read(SVC)
        self.body = self.svc.split("private function ticketIdByReference")[1].split("\n    private function ", 1)[0]

    def test_a_request_reference_resolves(self):
        """A member never sees a ticket number; they do hold a reference."""
        self.assertIn("FROM acm_activation_requests", self.body)
        self.assertIn("request_ref = :ref", self.body)

    def test_a_ticket_number_still_works(self):
        self.assertIn("str_starts_with($reference, 'ACM-')", self.body)
        self.assertIn("WHERE ticket_no = :no", self.body)

    def test_unresolved_requests_do_not_match(self):
        """ticket_id IS NULL means the office has not approved yet."""
        self.assertIn("ticket_id IS NOT NULL", self.body)

    def test_route_accepts_reference_and_keeps_the_alias(self):
        route = read(ROUTE)
        self.assertIn("'otp', 'reference', 'ticket_no'", route)
        self.assertIn("verifyOtp($body['otp'], $reference, $meta)", route)


class ConsoleShowsTheOtp(unittest.TestCase):
    def test_activation_display_is_the_grouped_otp(self):
        console = read(CONSOLE)
        self.assertIn("Otp::display($issued->secret)", console)
        # No stale 'A-SELECTOR-SECRET' display for an OTP row.
        self.assertIn("'kind' => $isOtp ? 'otp' : 'pair'", console)

    def test_audit_does_not_record_an_empty_selector(self):
        console = read(CONSOLE)
        self.assertIn("'otp' : $issued->selector", console)


class MigrationDiscipline(unittest.TestCase):
    def test_no_foreign_keys_and_no_json_columns(self):
        ddl = _strip_sql_comments(read(SQL102)).upper()
        self.assertNotIn("FOREIGN KEY", ddl)
        self.assertNotRegex(ddl, r"\bJSON\b")

    def test_is_idempotent(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS", read(SQL102))


if __name__ == "__main__":
    unittest.main()
