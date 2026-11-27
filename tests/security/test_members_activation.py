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


class TheIdempotencyKeyIsWellFormed(unittest.TestCase):
    """Stability is not enough: the server also validates the key's FORMAT.

    THE BUG THIS FILE EXISTS FOR
        makeIdempotencyKey() returned 32 bare hex characters, built by joining
        hex bytes with no separators. ActivationService::establishCredentials
        validates with

            /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

        so every activation was refused on the LAST step with 400
        invalid_request -- shown to the member as "The request is invalid.",
        after they had already chosen a password. The previous tests in this
        file checked that the key was random and stable, which it was. Nobody
        checked that it was a UUID.

    These assertions read the shipped JavaScript rather than a copy, so a
    future edit that drops the hyphens fails here instead of in the browser.
    """

    # The validator, taken verbatim from establishCredentials().
    SERVER_UUID = re.compile(
        r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$", re.I
    )
    # The fallback for browsers without crypto.getRandomValues must be valid
    # too, or it is dead code that only fails on the one browser we cannot test.
    UUID_TEMPLATE = '"{}-{}-{}-{}-{}"'

    @classmethod
    def setUpClass(cls):
        cls.js = strip_js_comments(read(JS))
        start = cls.js.index("function makeIdempotencyKey()")
        # Slice by the next function definition, and tolerate the fallback
        # being absent: the pre-fix file had none, and a missing function
        # should fail a NAMED test, not blow up setUpClass for all four.
        nxt = cls.js.find("function fallbackUuidV4()", start)
        if nxt < 0:
            nxt = cls.js.find("function bindRequest", start)
        cls.generator = cls.js[start:nxt]
        cls.fallback = ""
        if "function fallbackUuidV4()" in cls.js:
            fstart = cls.js.index("function fallbackUuidV4()")
            cls.fallback = cls.js[fstart:cls.js.index("function bindRequest", fstart)]

    def test_the_generator_joins_hex_into_five_groups(self):
        # Four separators, five slices: 8-4-4-4-12.
        self.assertEqual(self.generator.count('"-"'), 4,
                         "a UUID has four hyphens; the key had none")

    def test_the_generated_shape_matches_the_servers_regex(self):
        """Build the string the generator would emit and validate it.

        The generator is pure concatenation over 16 random bytes, so the shape
        is fully determined by the slice boundaries. Reading them out of the
        source is what makes this a real check rather than a copy of the
        intended output.
        """
        slices = re.findall(r"hex\.slice\((\d+),\s*(\d+)\)|hex\.slice\((\d+)\)",
                            self.generator)
        self.assertTrue(slices, "expected the generator to slice a hex string")

        # 16 bytes -> 32 hex chars; 8-4-4-4-12 consumes all 32.
        self.assertIn("slice(0, 8)", self.generator)
        self.assertIn("slice(8, 12)", self.generator)
        self.assertIn("slice(12, 16)", self.generator)
        self.assertIn("slice(16, 20)", self.generator)
        self.assertIn("slice(20)", self.generator)

        sample = "0123456789abcdef0123456789abcdef"
        built = "-".join([sample[0:8], sample[8:12], sample[12:16],
                          sample[16:20], sample[20:]])
        self.assertTrue(self.SERVER_UUID.match(built),
                        "8-4-4-4-12 over 32 hex chars must be a UUID: " + built)

    def test_the_no_crypto_fallback_is_a_uuid_too(self):
        self.assertNotEqual(
            "", self.fallback,
            "there must be a fallback for browsers without getRandomValues, "
            "and it must be a well-formed UUID"
        )
        for slice_text in ("slice(0, 8)", "slice(8, 12)", "slice(12, 16)",
                           "slice(16, 20)", "slice(20)"):
            self.assertIn(slice_text, self.fallback,
                          "the fallback key must have the same shape, or the "
                          "one browser that reaches it cannot activate at all")

    def test_the_joined_hex_is_never_returned_directly(self):
        """The exact defect, stated precisely.

        Joining 32 hex characters is fine -- both paths do it -- as long as the
        RETURNED value is the grouped form. The bug was `return ...join("")`
        handing back the bare 32-character string.
        """
        for name, body in (("crypto path", self.generator),
                           ("fallback", self.fallback)):
            # The defect was `return <expr>.join("")`: returning the bare
            # 32-character string. Joining first and then grouping is correct,
            # so the assertion has to be about the RETURN expression, not about
            # the presence of join("").
            self.assertNotRegex(
                body,
                r"return\s+\(\)[^;]*\.join\(|return\s+[A-Za-z_$][\w$.]*\.join\(",
                name + " returns a joined string instead of the grouped UUID"
            )
            self.assertIn(
                "return hex.slice(", body,
                name + " must return the hyphenated form"
            )


class ServerErrorsSpeakTheReadersLanguage(unittest.TestCase):
    """The API's fixed English strings are correct; showing them raw is not.

    THE BUG THIS FILE EXISTS FOR
        The activate page is Amharic (lang="am"), but a failed caller showed
        the API's untranslated text:

            "The request is invalid."

        Every other message on that screen is bilingual -- `pick(am, en)` --
        because the client owns presentation. The server must keep its single,
        vague string (it is the anti-enumeration guarantee), but the client
        has to speak it in the reader's language.

    WHAT MUST NOT HAPPEN INSTEAD
        Rewriting the message into something more specific. That would rebuild
        the member-ID oracle the API removed. So the translation is keyed on
        the machine `code` the server sends, never on its English prose, and an
        unknown code falls back to the generic localized sentence rather than
        echoing server text.
    """

    @classmethod
    def setUpClass(cls):
        cls.js = strip_js_comments(read(JS))

    def test_translation_is_keyed_on_the_machine_code_not_the_english_text(self):
        self.assertIn("error.code", self.js,
                      "the client must branch on the API's `code`, not its message")
        self.assertNotIn('error.message === "', self.js,
                         "matching on English prose breaks the moment the server "
                         "rewords a message")

    def test_known_codes_have_bilingual_copy(self):
        for code in ("not_verified", "invalid_request", "rate_limited",
                     "weak_password", "temporarily_unavailable",
                     "activation_pending", "grant_invalid", "grant_expired",
                     "grant_consumed", "grant_revoked", "code_invalid",
                     "account_exists", "idempotent_conflict", "network_denied"):
            self.assertRegex(
                self.js, r"\b" + code + r"\s*:\s*\[",
                "no localized copy for the server code " + code
            )

    def test_an_unknown_code_does_not_leak_server_prose(self):
        # The generic branch must be our localized sentence, not error.message.
        self.assertIn("pick(", self.js)
        self.assertIn("serverMessage", self.js)

    def test_the_anti_enumeration_copy_stays_vague(self):
        """The localization must not say WHY it failed.

        AUTOMATION PIN: whatever wording is chosen, it may not name a cause.
        """
        code = self.js.lower()
        for forbidden in ("not found", "no such member", "unknown member",
                          "not registered", "wrong name", "invalid member",
                          "does not exist"):
            self.assertNotIn(forbidden, code)


class ThePageDoesNotRebuildTheOracle(unittest.TestCase):
    """The single most important property of this screen."""

    @staticmethod
    def _code():
        return strip_js_comments(read(JS))

    def test_failures_are_not_reworded_reinterpreted_or_explained(self):
        """The rule is "add no detail", not "echo the English".

        This used to assert that `error.message` appeared in the source, as a
        proxy for "the server decides the words". But that also required
        printing the API's untranslated English on an Amharic page, and the
        real invariant is narrower and stronger: whatever we show must come
        from a fixed table keyed by the machine `code`, so the copy can never
        describe WHY the call failed. See ServerErrorsSpeakTheReadersLanguage.
        """
        code = self._code()
        self.assertIn("serverMessage(", code, "failures must route through one helper")
        self.assertIn("SERVER_COPY[error.code]", code,
                      "the mapping must be keyed on the server's machine code")

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
