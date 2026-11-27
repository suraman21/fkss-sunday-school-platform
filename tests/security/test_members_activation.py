"""Account activation: the page must reach the ceremony that already exists.

WHAT THE OWNER REPORTED
    "there is no way to activate an account."

WHAT WAS ACTUALLY TRUE
    The whole ACM ceremony already shipped: /api/v1/acm has activate/request,
    activate/verify, activate/credentials and devices/register, with OTP
    issuance, single-use grants, idempotency and rate limits. What was missing
    was any DOORWAY to it from the members app -- so a member holding no
    credentials had nowhere to go, which is the same experience as the feature
    not existing.

WHY THESE TESTS ARE MOSTLY ABOUT WHAT THE PAGE MUST NOT DO
    /acm/activate/request answers an unknown member ID, a wrong first name and a
    wrong phone with ONE identical response, deliberately, so it cannot be used
    to discover who is registered. A well-meaning UI that "explains" the failure
    would rebuild exactly that oracle. Several tests below exist only to stop
    that.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
PAGE = ROOT / "members/resources/views/pages/activate.php"
JS = ROOT / "members/public/assets/js/activate.js"
ROUTES = ROOT / "members/routes/web.php"
LOGIN = ROOT / "members/resources/views/pages/login.php"
API_ACM = ROOT / "api/v1/routes/acm.php"
API_AUTH = ROOT / "api/v1/routes/auth.php"
APP_JS = ROOT / "members/public/assets/js/app.js"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def strip_js_comments(src: str) -> str:
    src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
    return re.sub(r"^\s*//.*$", "", src, flags=re.M)


class TheRouteExists(unittest.TestCase):
    def test_activate_is_a_registered_page(self):
        self.assertIn("'/activate'", read(ROUTES))

    def test_login_links_to_it(self):
        """The doorway is useless if the screen a stuck member sees omits it."""
        login = read(LOGIN)
        self.assertIn('href="/members/activate"', login)


class TheCeremonyIsTheOneThatAlreadyExists(unittest.TestCase):
    """Call the shipped endpoints. Do not invent a parallel approval path."""

    @classmethod
    def setUpClass(cls):
        cls.js = strip_js_comments(read(JS))
        cls.acm = read(API_ACM)

    def test_request_endpoint(self):
        self.assertIn('"/acm/activate/request"', self.js)
        self.assertIn("activate/request", self.acm)

    def test_verify_endpoint(self):
        self.assertIn('"/acm/activate/verify"', self.js)
        self.assertIn("activate/verify", self.acm)

    def test_credentials_endpoint(self):
        self.assertIn('"/acm/activate/credentials"', self.js)
        self.assertIn("activate/credentials", self.acm)

    def test_no_parallel_or_invented_endpoint(self):
        for invented in ("/acm/approve", "/acm/register", "/members/activate/",
                         "/api/members/activate"):
            self.assertNotIn(invented, self.js)

    def test_the_body_field_names_match_the_api(self):
        # acm.php: acmStrictBody(['member_code','first_name','full_name','phone',
        # 'account_type']) -- strict bodies REJECT unknown fields, so a typo here
        # is a 400, not a silent ignore.
        for field in ("member_code", "first_name", "account_type"):
            self.assertIn(field, self.js)

    def test_verify_sends_the_reference_the_api_scopes_on(self):
        # verifyOtp(otp, reference, meta): the reference is the scope of the
        # comparison, which is what stops a guess from being checked against
        # every live code in the school.
        self.assertIn("reference:", self.js)
        self.assertIn("otp:", self.js)

    def test_credentials_send_a_grant_and_an_idempotency_key(self):
        self.assertIn("grant:", self.js)
        self.assertIn("idempotency_key", self.js)


class TheIdempotencyKeyIsStablePerGrant(unittest.TestCase):
    """A retry must replay, not double-provision.

    ActivationService::establishCredentials takes an idempotency key: same key +
    same grant replays or resumes. A key regenerated on every submit turns a
    retry into a second attempt, which is precisely what the key exists to stop.
    """

    @classmethod
    def setUpClass(cls):
        cls.js = strip_js_comments(read(JS))

    def test_key_is_created_once_after_verification(self):
        self.assertIn("state.idempotencyKey = makeIdempotencyKey();", self.js)

    def test_key_is_generated_in_the_verify_step_not_the_submit_step(self):
        # Count occurrences: the generator must be CALLED once, at verify.
        calls = self.js.count("makeIdempotencyKey()")
        self.assertEqual(calls, 2, "one definition site + one call site expected")

    def test_key_is_random_not_derived_from_the_member(self):
        self.assertIn("getRandomValues", self.js)
        self.assertNotIn("member_code + Date.now", self.js)


class ThePageDoesNotRebuildTheOracle(unittest.TestCase):
    """The single most important property of this screen."""

    @staticmethod
    def _code():
        return strip_js_comments(read(JS))

    def test_failures_show_the_servers_own_words(self):
        # `error.message` comes from the API. Prefixing or rewriting it would
        # reintroduce detail the server deliberately withheld.
        self.assertIn("error.message", self._code())

    def test_no_local_guessing_at_why_it_failed(self):
        code = self._code()
        for forbidden in (
            "not found", "no such member", "unknown member",
            "isn't registered", "not registered", "wrong name",
            "member does not exist", "invalid member",
        ):
            self.assertNotIn(forbidden, code.lower(),
                             "a UI that names the cause rebuilds the ID oracle")

    def test_a_202_is_not_reported_as_verified(self):
        """The API returns 202 for a duplicate AND for an unverified claim.

        So success does not prove the member ID was recognised, and the screen
        must not say it was. It asks for the code instead.
        """
        code = self._code()
        self.assertIn("#step-verify", code)
        lower = code.lower()
        for claim in ("verified!", "we found your record", "your id was found",
                      "record confirmed"):
            self.assertNotIn(claim, lower)

    def test_the_reference_is_kept_for_the_next_step(self):
        self.assertIn("state.reference =", self._code())


class ThePageDoesNotOverclaimCompletion(unittest.TestCase):
    """establishCredentials leaves the account at account_pending_device.

    Sign-in is refused until a device is registered (auth.php returns 403
    activation_pending). A screen that says "you can sign in now" would be
    contradicted by the very next request.
    """

    @classmethod
    def setUpClass(cls):
        cls.page = read(PAGE)
        cls.js = strip_js_comments(read(JS))

    def test_the_done_step_names_the_remaining_device_step(self):
        self.assertIn("step-done", self.page)
        self.assertIn("device", self.page.lower())

    def test_it_does_not_claim_the_account_is_ready(self):
        for claim in ("you can now sign in", "you may now sign in",
                      "account is active", "activation complete"):
            self.assertNotIn(claim, self.page.lower())

    def test_the_api_really_does_hold_the_account(self):
        # Guards the assumption above against a future server change.
        self.assertIn("account_pending_device", read(API_ACM))


class ThePasswordStaysOutOfOurPHP(unittest.TestCase):
    """Same rule as the login form: /api/v1 owns password handling."""

    @classmethod
    def setUpClass(cls):
        cls.page = read(PAGE)
        cls.js = strip_js_comments(read(JS))

    def test_the_page_does_not_post_to_itself(self):
        self.assertNotIn("<form action", self.page)
        self.assertNotIn("method=\"post\"", self.page.lower())

    def test_the_page_has_no_php_password_handling(self):
        for forbidden in ("password_hash(", "password_verify(", "$_POST['password']",
                          "$_POST[\"password\"]"):
            self.assertNotIn(forbidden, self.page)

    def test_confirmation_is_a_ui_concern_only(self):
        # The API takes ONE password value (design D3.4); matching is checked
        # in the browser and the confirm field is never sent.
        self.assertIn("confirm", self.js)
        self.assertNotIn("confirm:", self.js)

    def test_minimum_length_is_enforced_before_the_call(self):
        self.assertIn("password.length < 8", self.js)


class TheLoginScreenExplainsThePendingAccount(unittest.TestCase):
    """The old check compared against a code the API never sends.

    auth.php returns `activation_pending`. app.js tested for
    ACCOUNT_PENDING_ACTIVATION, so the explanation never fired.
    """

    @classmethod
    def setUpClass(cls):
        cls.app = strip_js_comments(read(APP_JS))

    def test_matches_the_code_the_api_actually_sends(self):
        self.assertIn("activation_pending", self.app)

    def test_the_api_sends_that_code(self):
        # It is the LOGIN route that refuses a pending account, not the
        # ceremony route. An earlier version of this test looked in acm.php.
        self.assertIn("'activation_pending'", read(API_AUTH))


if __name__ == "__main__":
    unittest.main()
