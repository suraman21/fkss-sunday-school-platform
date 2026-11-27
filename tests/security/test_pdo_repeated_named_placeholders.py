"""PDO cannot reuse a named placeholder, and this project does not emulate.

`config.php` opens the connection with::

    PDO::ATTR_EMULATE_PREPARES => false

With native prepares the driver rewrites every named parameter to a
positional `?`. So this

    WHERE username LIKE :q ESCAPE '\\' OR full_name LIKE :q ESCAPE '\\'

becomes `... LIKE ? ... LIKE ?`, while `execute([':q' => $like])` supplies
one value. MySQL answers `SQLSTATE[HY093]: Invalid parameter number`, and
PDO raises it from `execute()`, not `prepare()` -- so the mistake survives
review and only shows up on the live connection.

That is exactly what happened to three queries in this repository. The
worst of them was the activation queue: `catch (\\Throwable)` around the
whole load turned HY093 into "The queue is temporarily unavailable", and a
harness pointed at PostgreSQL cleared it, because PDO_SQLITE/PDO_PGSQL
rewrite parameters differently and never complain.

The search forms make this worth guarding: writing the same term into
several columns is the obvious way to build them, and it reads correctly.

Guard verified to fail against both the old code and an injected
regression -- see the module docstring of each test.
"""

from __future__ import annotations

import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]

SQL_KEYWORD = re.compile(r"\b(SELECT|UPDATE|INSERT\s+INTO|DELETE\s+FROM)\b", re.I)
PLACEHOLDER = re.compile(r"(?<!:):([A-Za-z_][A-Za-z0-9_]*)")

# Anchors whose statement introduces SQL. Matching the anchor rather than
# every literal keeps Tailwind classes and inline CSS out of the scan; they
# contain `:` pseudo-selectors like `focus:ring`.
ANCHORS = (
    "->prepare(",
    "->query(",
    "$where[] =",
    "$where[]=",
    "$sql =",
    "$sql .=",
    "$sql=",
    "$sql.=",
)


def _php_sources() -> list[Path]:
    out = []
    for base in ("acm", "admin", "api", "backend", "includes"):
        root = ROOT / base
        if not root.is_dir():
            continue
        for p in sorted(root.rglob("*.php")):
            if any(part in {"node_modules", "vendor", "tests"} for part in p.parts):
                continue
            out.append(p)
    if (ROOT / "config.php").is_file():
        out.append(ROOT / "config.php")
    return out


def _literals(src: str, start: int, end: int) -> list[str]:
    """String literals in src[start:end], skipping comments."""
    i, out = start, []
    while i < end:
        c = src[i]
        if c == "/" and src.startswith("//", i):
            nl = src.find("\n", i)
            i = end if nl < 0 else nl + 1
            continue
        if c == "#" and not src.startswith("#[", i):
            nl = src.find("\n", i)
            i = end if nl < 0 else nl + 1
            continue
        if c == "/" and src.startswith("/*", i):
            close = src.find("*/", i + 2)
            i = end if close < 0 else close + 2
            continue
        if src.startswith("<<<", i):
            m = re.match(r"<<<(['\"]?)(\w+)\1\r?\n", src[i:])
            if m:
                tag = m.group(2)
                body = i + m.end()
                close = re.search(r"^\s*" + tag + r"\b", src[body:], re.M)
                stop = body + (close.start() if close else len(src) - body)
                out.append(src[body:stop])
                i = body + (close.end() if close else len(body))
                continue
        if c in "'\"":
            q, j, buf = c, i + 1, []
            while j < end:
                if src[j] == "\\":
                    buf.append(src[j])
                    j += 2
                    continue
                if src[j] == q:
                    break
                buf.append(src[j])
                j += 1
            out.append("".join(buf))
            i = j + 1
            continue
        i += 1
    return out


# The bind call comes after the SQL, and its array literal is full of
# `':name' => $value` keys -- which are not placeholder occurrences. Stop
# reading there, or every correctly-numbered query looks like a repeat.
BIND_CALL = ("->execute(", "->bindValue(", "->bindParam(", "->execute (")


def _statement_end(src: str, start: int) -> int:
    """Offset where the SQL of the statement beginning at `start` stops."""
    i, depth = start, 0
    while i < len(src):
        if depth == 0 and any(src.startswith(tok, i) for tok in BIND_CALL):
            return i
        c = src[i]
        if c == "/" and src.startswith("//", i):
            nl = src.find("\n", i)
            i = len(src) if nl < 0 else nl
            continue
        if src.startswith("/*", i):
            close = src.find("*/", i + 2)
            i = len(src) if close < 0 else close + 2
            continue
        if c in "'\"":
            q, j = c, i + 1
            while j < len(src):
                if src[j] == "\\":
                    j += 2
                    continue
                if src[j] == q:
                    break
                j += 1
            i = j + 1
            continue
        if c in "([{":
            depth += 1
        elif c in ")]}":
            depth = max(0, depth - 1)
        elif c == ";" and depth == 0:
            return i
        i += 1
    return len(src)


def repeated_placeholders(path: Path) -> list[tuple[int, str, int]]:
    """(line, name, occurrences) for placeholders used twice in one statement."""
    src = path.read_text(encoding="utf-8", errors="replace")
    found: list[tuple[int, str, int]] = []
    for anchor in ANCHORS:
        for m in re.finditer(re.escape(anchor), src):
            end = _statement_end(src, m.end())
            text = "".join(_literals(src, m.end(), end))
            if ":" not in text:
                continue
            is_sql = bool(SQL_KEYWORD.search(text)) or (
                "LIKE" in text.upper() and "ESCAPE" in text.upper()
            )
            if not is_sql:
                continue
            counts: dict[str, int] = {}
            for name in PLACEHOLDER.findall(text):
                counts[name] = counts.get(name, 0) + 1
            line = src.count("\n", 0, m.start()) + 1
            for name, n in counts.items():
                if n > 1:
                    found.append((line, name, n))
    return found


def _offenders() -> list[str]:
    out = []
    for path in _php_sources():
        for line, name, n in repeated_placeholders(path):
            rel = path.relative_to(ROOT).as_posix()
            out.append(f"{rel}:{line} uses :{name} {n} times in one statement")
    return sorted(set(out))


def test_the_scanner_has_something_to_scan():
    files = _php_sources()
    assert len(files) > 50, f"walk found only {len(files)} PHP files"


def test_the_scanner_can_see_a_repeated_placeholder(tmp_path):
    """A guard that cannot fail is not a guard."""
    probe = tmp_path / "probe.php"
    probe.write_text(
        "<?php\n"
        "$stmt = $pdo->prepare(\n"
        "    'SELECT id FROM users WHERE username LIKE :q ESCAPE \\'\\\\\\''\n"
        "    . \" OR full_name LIKE :q ESCAPE '\\\\'\"\n"
        ");\n"
        "$stmt->execute([':q' => $like]);\n",
        encoding="utf-8",
    )
    found = repeated_placeholders(probe)
    assert found, "the scanner did not spot :q used twice"
    assert found[0][1] == "q" and found[0][2] == 2


def test_the_scanner_ignores_css_and_class_names(tmp_path):
    """No false positives from `focus:ring` style tokens."""
    probe = tmp_path / "probe.php"
    probe.write_text(
        '<?php\n$sql = "x";\n'
        '<div class="focus:ring-2 focus:ring-emerald-400 grid grid-cols-2"></div>\n'
        '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600">\n',
        encoding="utf-8",
    )
    assert repeated_placeholders(probe) == []


def test_no_query_reuses_a_named_placeholder():
    """The regression guard.

    config.php sets ATTR_EMULATE_PREPARES to false, so a name used twice
    raises HY093 from execute(). This caught the activation queue, the
    person search and the staff picker.
    """
    offenders = _offenders()
    assert offenders == [], (
        "PDO with ATTR_EMULATE_PREPARES => false cannot reuse a named "
        "parameter -- each occurrence needs its own name, all bound to the "
        "same value:\n  " + "\n  ".join(offenders)
    )


def test_prepare_still_runs_natively():
    """If emulation is ever turned on, this whole test module is moot.

    Pinning it means the day someone flips the flag, the reason these
    placeholders are numbered stays visible.
    """
    config = (ROOT / "config.php").read_text(encoding="utf-8", errors="replace")
    assert "ATTR_EMULATE_PREPARES => false" in config, (
        "ATTR_EMULATE_PREPARES is no longer false; if that was deliberate, "
        "this guard (and the numbered placeholders it protects) can be "
        "revisited -- but do not flip it just to silence these failures"
    )


@pytest.mark.parametrize(
    "path,needle",
    [
        ("acm/src/Accounts/AcmTicketService.php", "t.ticket_no LIKE :q1"),
        ("acm/src/Identity/AcmPersonService.php", "full_name_am LIKE :q1"),
        ("admin/api_acm.php", "username LIKE :q1"),
    ],
)
def test_the_three_known_queries_are_numbered(path, needle):
    src = (ROOT / path).read_text(encoding="utf-8", errors="replace")
    assert needle in src, f"{path} must keep numbered placeholders ({needle})"
