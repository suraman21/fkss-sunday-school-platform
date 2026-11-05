"""Behavioural idempotency tests (S1, finding F-20).

`test_api_idempotency.py` asserts that certain strings exist in the PHP
source. That is worth keeping as a structural guard, but it would still pass
if the logic were inverted, because the strings would be unchanged. These
tests instead execute the real `App\\Services\\ApiIdempotencyService` against a
real database through `tests/e2e/idempotency_lifecycle.php` and assert on what
actually happened to the rows.

The harness is destructive, so it only ever runs against a disposable
database and is refused by the interlock otherwise.
"""
from __future__ import annotations

import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
RUNNER = ROOT / "tests" / "e2e" / "idempotency_lifecycle.php"
ENV_FILE = ROOT / ".fkss_env.php"
IDEM_DB = os.environ.get("SSMS_IDEM_DB", "ssms_idem_e2e")


def _php_binary() -> str | None:
    return os.environ.get("SSMS_E2E_PHP") or shutil.which("php")


def _db_reachable(php: str) -> bool:
    probe = (
        "require %s; $m = @new mysqli(DB_HOST, DB_USER, DB_PASS, %s); "
        "exit($m->connect_errno ? 3 : 0);" % (repr(str(ENV_FILE)), repr(IDEM_DB))
    )
    try:
        return subprocess.run(
            [php, "-r", probe], capture_output=True, text=True, timeout=30
        ).returncode == 0
    except Exception:
        return False


class ApiIdempotencyRuntimeTests(unittest.TestCase):
    """Each scenario is one execution of the shipped service."""

    @classmethod
    def setUpClass(cls) -> None:
        php = _php_binary()
        if not php:
            raise unittest.SkipTest("php CLI not available — idempotency e2e skipped")
        if not RUNNER.is_file():
            raise unittest.SkipTest("idempotency harness not present")
        if not ENV_FILE.is_file():
            raise unittest.SkipTest(".fkss_env.php not present — idempotency e2e skipped")
        if not _db_reachable(php):
            raise unittest.SkipTest(f"idempotency database '{IDEM_DB}' unreachable — skipped")
        cls.php = php

    def _run(self, scenario: str) -> subprocess.CompletedProcess:
        return subprocess.run(
            [self.php, str(RUNNER), scenario],
            capture_output=True, text=True, timeout=300, cwd=str(ROOT),
            env={
                **os.environ,
                # The destructive interlock requires the caller to declare a
                # disposable target explicitly.
                "SSMS_AUDIT_TESTING": "1",
                "SSMS_DB_NAME": IDEM_DB,
            },
        )

    def _assert_pass(self, scenario: str) -> str:
        proc = self._run(scenario)
        self.assertEqual(
            proc.returncode, 0,
            f"{scenario} exited {proc.returncode}:\n{proc.stdout}\n{proc.stderr}",
        )
        self.assertIn("E2E-VERDICT: PASS", proc.stdout,
                      f"{scenario} did not pass:\n{proc.stdout}")
        return proc.stdout

    def test_attendance_database_success_is_atomic(self):
        self._assert_pass("attendance_response")

    def test_attendance_response_failure_rolls_back_and_keeps_retryable(self):
        self._assert_pass("attendance_response_failures")

    def test_readonly_lookup_does_not_claim_renew_or_expose_results(self):
        self._assert_pass("readonly_lookup")

    # ── §16 scenarios ───────────────────────────────────────────────────────
    def test_a_normal_success_stores_exactly_one_effect_and_one_record(self):
        out = self._assert_pass("success")
        self.assertIn("exactly one business effect", out)
        self.assertIn("replay returns the exact stored body", out)

    def test_b_a_duplicate_request_is_replayed_not_re_executed(self):
        out = self._assert_pass("duplicate")
        self.assertIn("no duplicate business effect", out)
        self.assertIn("same key + different payload = conflict", out)

    def test_c_concurrent_duplicates_cannot_both_execute(self):
        out = self._assert_pass("concurrent")
        self.assertIn("exactly one concurrent caller acquires", out)
        self.assertIn("non-owner token cannot overwrite", out)

    def test_d_a_failure_before_commit_leaves_nothing_and_stays_retryable(self):
        out = self._assert_pass("precommit_failure")
        self.assertIn("business transaction rolled back", out)
        self.assertIn("exactly one business effect overall", out)

    def test_e_and_f_the_post_commit_window_allows_re_execution(self):
        """The F-20 window, executed rather than argued.

        A worker that dies between COMMIT and the idempotency write leaves a
        durable business effect behind a record that still says 'processing'.
        Once the 300s lease expires, the identical retry is granted the
        reservation again and the write runs a second time.
        """
        out = self._assert_pass("postcommit_crash")
        self.assertIn("the business effect IS durable", out)
        self.assertIn("it does not know the write happened", out)
        self.assertIn("the duplicate-execution window is REAL", out)

    def test_g_a_stored_replay_is_byte_identical_and_user_scoped(self):
        out = self._assert_pass("replay")
        self.assertIn("byte-identical stored body", out)
        self.assertIn("records are user-scoped", out)

    # ── §18 non-attendance idempotency ──────────────────────────────────────
    def test_attendance_converges_under_re_execution(self):
        out = self._assert_pass("attendance_converge")
        self.assertIn("attendance CONVERGES", out)

    def test_append_only_writes_duplicate_under_re_execution(self):
        """S0.5 left this NOT VERIFIED. It is now verified, and negative."""
        out = self._assert_pass("comm_append")
        self.assertIn("APPEND-ONLY WRITES DUPLICATE", out)

    # ── the pinned-error shape ──────────────────────────────────────────────
    def test_a_transient_server_error_is_pinned_as_the_stored_answer(self):
        out = self._assert_pass("error_pinning")
        self.assertIn("THE TRANSIENT 500 IS PINNED", out)
        self.assertIn("abandoned (429) reservation stays retryable", out)

    def test_the_whole_suite_runs_clean_in_one_pass(self):
        out = self._assert_pass("all")
        self.assertIn("E2E-VERDICT: PASS (all:", out)
        self.assertNotIn("E2E-FAIL", out)


class PinnedErrorClientHandlingTests(unittest.TestCase):
    """The server pins a 5xx; the client must not loop on it forever.

    Guard-the-guard for the finding above: the risk is only acceptable
    because the Dart classifier treats a *replayed* 5xx as terminal rather
    than retryable. If that branch is ever removed the finding becomes a
    permanent retry loop, so it is pinned here.
    """

    def test_a_replayed_5xx_is_terminal_for_the_client(self):
        policy = (ROOT / "Mobile" / "wbws_flutter_app" / "lib" / "services"
                  / "outbox_policy.dart").read_text(encoding="utf-8")
        self.assertIn("status >= 500 && status <= 599", policy)
        self.assertIn("evidence.idempotencyReplayed\n        ? OutboxDecision.needsAttention",
                      policy)

    def test_the_replay_header_is_emitted_and_parsed_end_to_end(self):
        middleware = (ROOT / "api" / "v1" / "core" / "middleware.php").read_text(
            encoding="utf-8")
        api = (ROOT / "Mobile" / "wbws_flutter_app" / "lib" / "services"
               / "api_service.dart").read_text(encoding="utf-8")
        self.assertIn("header('Idempotency-Replayed: true')", middleware)
        self.assertIn("headers['idempotency-replayed']?.toLowerCase() == 'true'", api)


if __name__ == "__main__":
    unittest.main()
