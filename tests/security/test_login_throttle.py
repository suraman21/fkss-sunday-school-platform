"""Per-ACCOUNT login throttling.

This is the control that lets `PasswordPolicy::MIN_CHARACTERS` be 6.

The old posture bounded guesses per IP only. That bounds one address and
nothing else: an attacker with a pool of addresses could take unlimited
guesses at one account, which is why the floor was raised to 12. Length
was compensating for a missing control. These tests exist so the control
cannot quietly disappear while the floor stays at 6.
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
THROTTLE = ROOT / "admin" / "backend" / "services" / "LoginThrottle.php"
LIMITER = ROOT / "admin" / "backend" / "services" / "SecurityRateLimiter.php"
AUTH = ROOT / "api" / "v1" / "routes" / "auth.php"
POLICY = ROOT / "admin" / "backend" / "services" / "PasswordPolicy.php"


def read(path):
    return path.read_text(encoding="utf-8")


def strip_php_comments(source: str) -> str:
    """Comments removed, positions preserved.

    These methods are EXPLAINED in comments that name them ("recordSuccess()
    deletes the bucket"), so comment text lands BEFORE the code it describes
    and any index()-based ordering assertion silently compares a comment
    against the line it is warning about. Replacing rather than deleting
    keeps line structure intact.
    """
    source = re.sub(r"/\*.*?\*/", lambda m: re.sub(r"[^\n]", " ", m.group(0)), source, flags=re.DOTALL)
    return re.sub(r"//[^\n]*", "", source)


class ThrottleShape(unittest.TestCase):
    def setUp(self):
        self.src = read(THROTTLE)

    def test_keyed_on_the_typed_identifier_not_the_resolved_user(self):
        """Keying on the resolved id would let anyone lock a known account."""
        self.assertIn("hash('sha256', self::normalize($identifier))", self.src)
        # The constructor must take the identifier, not a user id.
        self.assertIn("string $identifier", self.src)

    def test_the_identifier_is_hashed_before_storage(self):
        """A limiter dump must not reveal which accounts were probed."""
        self.assertIn("'id:' . hash('sha256'", self.src)

    def test_soft_limit_refuses_before_verifying(self):
        self.assertIn("ACCOUNT_SOFT_LIMIT", self.src)
        code = strip_php_comments(self.src)
        attempt = code.split("public function attempt")[1].split("public function ", 1)[0]
        self.assertIn("ACCOUNT_SOFT_LIMIT", attempt)
        # The count must be allowed to exceed the threshold, or "at or over
        # the limit" can never be observed and the counter freezes: pass a
        # ceiling above the limit, not the limit itself.
        self.assertIn("COUNTER_CEILING", attempt)
        self.assertNotIn("ACCOUNT_SOFT_LIMIT,\n            self::WINDOW_SECONDS", attempt)

    def test_a_refusal_does_not_consume(self):
        """Refusing must not slide the window forward.

        If a refused attempt still consumed, one request every few minutes
        would hold a victim's account locked forever: the window would keep
        being pushed out from under them.
        """
        code = strip_php_comments(self.src)
        attempt = code.split("public function attempt")[1].split("public function ", 1)[0]
        guard = attempt.index("ACCOUNT_SOFT_LIMIT)")
        # Everything from the threshold test up to the FIRST consume(), which
        # must sit outside it -- the consume calls come after the guard, so
        # anything found in this span runs before them.
        first_consume = attempt.index("consume(", guard)
        branch = attempt[guard:first_consume]
        self.assertIn("'locked' => true", branch)
        self.assertNotIn("consume(", branch)

    def test_peek_does_not_consume_an_attempt(self):
        """Otherwise logging in correctly would lock the account."""
        limiter = read(LIMITER)
        peek = limiter.split("public function peek")[1].split("public function ", 1)[0]
        self.assertNotIn("INSERT INTO security_rate_limits", peek)
        self.assertIn("SELECT attempts", peek)

    def test_success_clears_the_bucket(self):
        success = self.src.split("public function recordSuccess")[1].split("\n    public static", 1)[0]
        self.assertIn("clear('login_account'", success)


class AuthWiring(unittest.TestCase):
    """A throttle nobody calls is documentation, not a control."""

    def setUp(self):
        self.auth = read(AUTH)
        self.login = self.auth.split("if ($action === 'login' && $method === 'POST')")[1]
        self.login = self.login.split("if ($action === ", 1)[0]
        # Ordering assertions below must see code, not the comments that
        # explain why the order matters.
        self.code = strip_php_comments(self.login)

    def test_attempt_happens_before_the_password_is_verified(self):
        attempt_at = self.code.index("$throttle->attempt()")
        verify_at = self.code.index("password_verify($password")
        self.assertLess(attempt_at, verify_at)

    def test_there_is_only_one_threshold(self):
        """A second line that cannot be reached is a lie in the code.

        This file used to assert that a "hard lockout" was checked before
        recordSuccess(), and it passed -- while the check could never fire,
        because `attempt()` refuses at the soft limit and refuses WITHOUT
        consuming, so the counter froze at 5 and never reached 10. A
        behavioural test against the real limiter found it; an ordering
        assertion could not.
        """
        body = strip_php_comments(read(THROTTLE))
        self.assertNotIn("hardLocked", body)
        self.assertNotIn("ACCOUNT_HARD_LIMIT", body)
        self.assertNotIn("hardLocked", self.code)

    def test_lockout_is_reported_honestly(self):
        """The lockout message is safe because the bucket is keyed on the
        identifier as TYPED: an invented username produces the same message,
        so nothing is confirmed about whether the account exists."""
        block = self.code.split("if (!$throttleState['allowed'])")[1]
        block = block.split("$profileImageColumnExists", 1)[0]
        self.assertIn("account_locked", block)
        self.assertIn("if (!empty($throttleState['locked']))", block)
        # The identifier is what the caller typed, not a directory lookup.
        throttle = read(THROTTLE)
        self.assertIn("hash('sha256', self::normalize($identifier))", throttle)

    def test_a_locked_account_does_not_reset_on_the_way_out(self):
        """recordSuccess() clears the bucket, so it must not run when the
        attempt was refused -- otherwise a locked account is unlocked by
        the request that discovered it was locked."""
        guard_at = self.code.index("if (!$throttleState['allowed'])")
        clear_at = self.code.index("$throttle->recordSuccess()")
        self.assertLess(guard_at, clear_at)


class PolicyCoupling(unittest.TestCase):
    def test_the_floor_is_six_and_says_why(self):
        policy = read(POLICY)
        self.assertIn("MIN_CHARACTERS = 6;", policy)
        # The justification must name the control, or the next person
        # lowers it further without one.
        self.assertIn("LoginThrottle", policy)

    def test_the_class_is_loaded_for_the_api(self):
        """It is not autoloaded; auth.php constructs it with a bare \\App\\."""
        middleware = read(ROOT / "api" / "v1" / "core" / "middleware.php")
        self.assertIn("LoginThrottle.php", middleware)

    def test_the_old_per_ip_limiter_is_still_there(self):
        """Per-account does not replace per-IP; they catch different things."""
        auth = read(AUTH)
        self.assertIn("isApiRateLimited('auth_login'", auth)

    def test_common_password_list_matches_the_new_floor(self):
        """12-character entries described a floor that no longer exists."""
        policy = read(POLICY)
        common = policy.split("COMMON_PASSWORDS = [")[1].split("];", 1)[0]
        self.assertIn("'123456'", common)
        self.assertIn("'password'", common)
        for twelve_only in ("'123456789012'", "'adminadmin12'", "'changeme1234'"):
            self.assertNotIn(twelve_only, common)


if __name__ == "__main__":
    unittest.main()
