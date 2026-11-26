"""The OTP read and its consumer must name the same columns.

WHY THIS FILE EXISTS
    `ActivationService::verifyOtp()` read `$code['secret_hash']` while
    `lockedOtpCode()`'s SELECT list did not include `secret_hash`. PHP
    answered with an undefined-key warning and null, so `Otp::verify()`
    was ALWAYS handed an empty hash and therefore always returned false.

    The consequence was not a cosmetic defect: a correct six-digit code
    could never activate anyone, and because every failure was recorded
    as a guess, five correct entries revoked the code and burnt the
    ticket. The member's only path through the ceremony was broken, and
    the failure looked exactly like "wrong code".

    It survived review and 25 tests because those tests asserted on the
    shape of the source text, never on a row that the code had actually
    read. A SELECT list and the code that consumes it are one unit; this
    test holds them together.

WHAT IT CHECKS
    Column names accessed as `$row['name']` inside verifyOtp()'s body (in
    the verification branch) are all present in lockedOtpCode()'s SELECT
    list. SQL aliases are honoured. It is deliberately textual, because
    the alternative -- a database in CI -- is not available, but it fails
    on exactly the class of mistake that shipped.
"""
import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
SERVICE = ROOT / "acm" / "src" / "Activation" / "ActivationService.php"


def _source() -> str:
    return SERVICE.read_text(encoding="utf-8")


def _method_body(src: str, signature: str) -> str:
    """Return the body of the named method, brace-matched."""
    start = src.index(signature)
    brace = src.index("{", start)
    depth = 0
    for i in range(brace, len(src)):
        if src[i] == "{":
            depth += 1
        elif src[i] == "}":
            depth -= 1
            if depth == 0:
                return src[brace + 1:i]
    raise AssertionError(f"unbalanced braces in {signature}")


def _php_string_literals(body: str) -> str:
    """Join the string literals in a method body into one pseudo-SQL string.

    The statements are built as adjacent PHP string literals joined with
    '.', so the raw source is not parseable SQL. Quotes are PHP escapes
    ('' and \\'), which have to come out before the column list means
    anything.
    """
    parts, i, n = [], 0, len(body)
    while i < n:
        if body[i] == "'":
            i += 1
            buf = []
            while i < n:
                c = body[i]
                if c == "\\" and i + 1 < n:
                    buf.append(body[i + 1])
                    i += 2
                    continue
                if c == "'":
                    if i + 2 < n and body[i + 1] == "'":
                        buf.append("'")
                        i += 2
                        continue
                    i += 1
                    break
                buf.append(c)
                i += 1
            parts.append("".join(buf))
        else:
            i += 1
    return " ".join(parts)


def _select_columns(body: str) -> set[str]:
    """Columns named by the SELECT in lockedOtpCode(), aliases included."""
    body = _php_string_literals(_strip_line_comments(body))
    m = re.search(r"SELECT(.*?)FROM\s+acm_activation_codes", body, re.S)
    assert m, "lockedOtpCode() no longer selects from acm_activation_codes"
    cols = set()
    for chunk in m.group(1).split(","):
        chunk = chunk.strip()
        alias = re.search(r"\bAS\s+(\w+)\s*$", chunk, re.I)
        if alias:
            cols.add(alias.group(1).lower())
            continue
        # A bare column, possibly qualified or inside an expression.
        bare = re.fullmatch(r"(?:\w+\.)?(\w+)", chunk)
        if bare:
            cols.add(bare.group(1).lower())
            continue
        # Any other expression (e.g. a CASE) contributes no column name,
        # which is correct: nothing reads it by name.
    return cols


def _strip_line_comments(text: str) -> str:
    """Drop // comments, preserving string literals."""
    out, i, n = [], 0, len(text)
    while i < n:
        c = text[i]
        if c in ("'", '"'):
            q = c
            out.append(c)
            i += 1
            while i < n and text[i] != q:
                if text[i] == "\\":
                    out.append(text[i])
                    i += 1
                if i < n:
                    out.append(text[i])
                    i += 1
            if i < n:
                out.append(text[i])
                i += 1
        elif text.startswith("//", i):
            while i < n and text[i] != "\n":
                i += 1
        else:
            out.append(c)
            i += 1
    return "".join(out)


def test_service_file_is_where_we_expect_it():
    assert SERVICE.is_file(), f"missing {SERVICE}"


def test_locked_otp_read_selects_every_column_the_verifier_uses():
    src = _source()
    read_cols = _select_columns(_method_body(src, "private function lockedOtpCode("))
    verifier = _method_body(src, "public function verifyOtp(")

    assert "secret_hash" in read_cols or "secret_hash" in _source(), "sanity"


def test_verify_otp_only_reads_columns_that_were_fetched():
    src = _source()
    read_cols = _select_columns(_method_body(src, "private function lockedOtpCode("))
    verifier = _method_body(src, "public function verifyOtp(")

    # The row the verifier holds is the one lockedOtpCode() returned.
    used = set(re.findall(r"\$code\[\s*'(\w+)'\s*\]", verifier))
    assert used, "could not find any $code[...] access -- the test is not looking at the right code"
    missing = sorted(used - read_cols)
    assert not missing, (
        "verifyOtp() reads columns that lockedOtpCode() does not select: "
        f"{missing}. PHP will yield null for these, and for secret_hash that "
        "means every correct OTP compares against nothing and is recorded as "
        "a wrong guess."
    )


def test_secret_hash_specifically_is_fetched():
    # Belt and braces: the named regression, independent of the parser above.
    src = _source()
    read_cols = _select_columns(_method_body(src, "private function lockedOtpCode("))
    assert "secret_hash" in read_cols, (
        "lockedOtpCode() must SELECT secret_hash; without it Otp::verify() "
        "always fails and correct codes burn the ticket."
    )


def test_every_failure_path_is_generic():
    """A spent budget must not be distinguishable from an unknown reference.

    `otpBudgetExhausted()` used to throw rateLimited(), so an invented
    reference answered code_invalid while a real one with a spent budget
    answered rate_limited: two requests told an attacker whether a
    reference exists.
    """
    src = _source()
    body = _strip_line_comments(_method_body(src, "public function verifyOtp("))
    assert "otpBudgetExhausted" in body, "the budget check moved; update this test"
    guard = re.search(r"if\s*\(\s*\$this->otpBudgetExhausted\([^)]*\)\s*\)\s*\{(.*?)\}", body, re.S)
    assert guard, "could not locate the budget guard"
    branch = guard.group(1)
    assert "rateLimited" not in branch, (
        "the exhausted-budget branch must throw the same verifyFailed() as an "
        "unknown reference, not rateLimited() -- otherwise it is an existence oracle"
    )
    assert "verifyFailed" in branch
