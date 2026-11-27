"""Every ACM console page must emit a document head before it draws chrome.

WHY THIS FILE EXISTS
    `acm_head()` and `acm_chrome_open()` are two halves of one page and the
    console's layout makes them easy to confuse:

      acm_head($title)        emits <!DOCTYPE>, <html>, <head>, <title>,
                              the CSRF meta, and the acm.css / mobile.css /
                              Font Awesome <link> tags.
      acm_chrome_open(...)    emits ONLY the sidebar and the page header.

    Three pages were written with the second and not the first --
    `acm_requests.php`, `acm_request.php` and `acm_device_recovery.php`.
    With no <head> there is no stylesheet, so the browser rendered the raw
    markup: a bare white page with an unstyled sidebar, broken-image boxes
    where the Font Awesome glyphs belong, and the page title shown twice
    (once from the chrome header, once from the page's own <h1>).

    Nothing caught it. The pages returned HTTP 200, their data loaded
    correctly, and every existing test asserted on content or on the
    queries -- none of them asked whether the response was a document. A
    screenshot was the first thing to notice, which is not a test.

WHAT THIS CHECKS
    For every `admin/acm_*.php` that calls `acm_chrome_open(`, the same
    file must also call `acm_head(`. It also asserts the ordering the
    layout requires -- head before chrome -- because a <head> emitted
    after the sidebar has started would be ignored by the parser, which
    is the same visible bug with a clean grep.
"""
import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
ADMIN = ROOT / "admin"

#: Pages that exist to be included, not requested. They never draw chrome.
FRAGMENTS = {"acm_layout.php"}


def _console_pages():
    pages = []
    for path in sorted(ADMIN.glob("acm_*.php")):
        if path.name in FRAGMENTS:
            continue
        src = path.read_text(encoding="utf-8")
        if "acm_chrome_open(" in src:
            pages.append((path, src))
    return pages


def _strip_comments(src: str) -> str:
    """Remove // and /* */ comments, preserving positions and string bodies.

    These pages explain the layout in comments that name both helpers, so a
    naive substring search would pass on prose alone.
    """
    out, i, n = [], 0, len(src)
    while i < n:
        c = src[i]
        if c in ("'", '"'):
            q = c
            out.append(c)
            i += 1
            while i < n and src[i] != q:
                if src[i] == "\\" and i + 1 < n:
                    out.append(src[i])
                    i += 1
                if i < n:
                    out.append(src[i])
                    i += 1
            if i < n:
                out.append(src[i])
                i += 1
        elif src.startswith("//", i):
            while i < n and src[i] != "\n":
                i += 1
        elif src.startswith("/*", i):
            end = src.find("*/", i + 2)
            i = n if end == -1 else end + 2
        else:
            out.append(c)
            i += 1
    return "".join(out)


def test_there_are_console_pages_to_check():
    """A guard that silently checks nothing is worse than no guard."""
    pages = _console_pages()
    assert len(pages) >= 8, (
        f"only found {len(pages)} ACM console pages; the glob or the helper "
        "name changed and this test is no longer looking at the real pages"
    )


@pytest.mark.parametrize("path,src", _console_pages(), ids=lambda v: v.name if isinstance(v, Path) else "")
def test_every_chrome_page_emits_a_head(path, src):
    code = _strip_comments(src)
    assert "acm_chrome_open(" in code
    assert "acm_head(" in code, (
        f"{path.name} calls acm_chrome_open() but never acm_head(): the page "
        "will be served with no <head>, so no stylesheet and no icon font. "
        "Add acm_head('<title>') immediately before acm_chrome_open()."
    )


def _call_sites(code: str, helper: str):
    """Every top-level call to `helper(`, in source order."""
    return [m.start() for m in re.finditer(re.escape(helper) + r"\(", code)]


@pytest.mark.parametrize("path,src", _console_pages(), ids=lambda v: v.name if isinstance(v, Path) else "")
def test_every_chrome_call_site_has_a_head_before_it(path, src):
    """Per call site, not per file.

    A page may legitimately draw chrome more than once -- `acm_request.php`
    has two early-exit error pages (503 and 404) before the real one, and
    `acm_device_recovery.php` has a 503 boot-failure page. Each of those is
    a separate document, so each needs its own head. Comparing only the
    first occurrence of each helper would pass on the happy path while the
    error pages stayed unstyled -- which is how this bug survived: nobody
    sees the 404 page until something is wrong.
    """
    code = _strip_comments(src)
    chrome_sites = _call_sites(code, "acm_chrome_open")
    head_sites = _call_sites(code, "acm_head")
    assert chrome_sites, f"{path.name}: expected at least one acm_chrome_open()"
    assert len(head_sites) >= len(chrome_sites), (
        f"{path.name} draws chrome {len(chrome_sites)}× but emits a head only "
        f"{len(head_sites)}×; every early-exit error page needs its own "
        "acm_head() or it renders unstyled"
    )
    # Each chrome call must have a head call before it, in order.
    for n, chrome_at in enumerate(chrome_sites):
        preceding = [h for h in head_sites if h < chrome_at]
        assert len(preceding) >= n + 1, (
            f"{path.name}: chrome call #{n + 1} has no acm_head() before it. "
            "A <head> emitted after the body has started is ignored by the "
            "parser, so that page still renders unstyled."
        )


def test_acm_head_actually_emits_the_linked_stylesheets():
    """The helper is the other half of the contract; pin what it emits.

    If acm_head() ever stopped emitting the stylesheet links, every page
    would go unstyled at once and the per-page guard above would not
    notice, because each page would still be calling it.
    """
    layout = _strip_comments((ADMIN / "acm_layout.php").read_text(encoding="utf-8"))
    body = layout.split("function acm_head(")[1].split("\nfunction ", 1)[0]
    for required in ("<!DOCTYPE html>", "<head>", "</head>", "<title>"):
        assert required in body, f"acm_head() no longer emits {required}"
    for href in ("/admin/css/acm.css", "/admin/css/mobile.css"):
        assert href in body, (
            f"acm_head() no longer links {href}; every console page would "
            "render unstyled"
        )


def test_the_stylesheets_exist():
    """A <link> to a missing file is the same unstyled page with extra steps."""
    for name in ("acm.css", "mobile.css"):
        assert (ADMIN / "css" / name).is_file(), f"admin/css/{name} is missing"


def _chrome_title(code: str):
    m = re.search(r"acm_chrome_open\(\s*'[^']*'\s*,\s*'([^']*)'", code)
    return m.group(1) if m else None


@pytest.mark.parametrize("path,src", _console_pages(), ids=lambda v: v.name if isinstance(v, Path) else "")
def test_the_page_does_not_repeat_the_chrome_title(path, src):
    """acm_chrome_open() already renders the title and subtitle.

    A page that also prints an identical <h1 class="pg"> shows the same
    heading twice. That is what "Activation requests" did, above a
    subtitle it had already printed in the topbar.

    A DIFFERENT h1 is fine and deliberate -- acm_request.php writes
    "Request <code>MX7CVJNR</code>", which adds the reference the topbar
    does not show.
    """
    code = _strip_comments(src)
    title = _chrome_title(code)
    if title is None:
        pytest.skip("chrome title is dynamic; nothing literal to compare")
    for heading in re.findall(r'<h1 class="pg">([^<]*)</h1>', code):
        assert heading.strip() != title.strip(), (
            f"{path.name} prints '{title}' as an <h1> as well as passing it to "
            "acm_chrome_open(), so it appears twice on the page. Drop the h1, "
            "or make it carry information the topbar does not."
        )


# ---------------------------------------------------------------- search
def _request_service() -> str:
    return _strip_comments(
        (ROOT / "acm" / "src" / "Requests" / "AcmActivationRequestService.php")
        .read_text(encoding="utf-8")
    )


def test_request_queue_accepts_a_search_term():
    """The reference the member holds must be findable from the console.

    `request_ref` is the only string handed to a member when they submit,
    so it is what an officer arrives with. The page had no search at all
    and queue() took no text filter, which is why one reference could only
    be found by scrolling status tabs.
    """
    svc = _request_service()
    sig = svc.split("public function queue(")[1].split(")", 1)[0]
    assert "$q" in sig, "queue() must accept a search term"
    body = svc.split("public function queue(")[1].split("\n    private static function", 1)[0]
    assert "searchClause" in body, "queue() must apply the search term"
    page = _strip_comments((ADMIN / "acm_requests.php").read_text(encoding="utf-8"))
    assert "name=\"q\"" in page, "the requests page must render a search box"
    assert "$fQ" in page


def test_search_matches_the_reference_case_insensitively():
    """The column is ascii_bin, so a lower-case reference would not match."""
    svc = _request_service()
    clause = svc.split("private static function searchClause(")[1]
    assert "strtoupper($q)" in clause, (
        "request_ref is an ascii_bin column; an officer typing a lower-case "
        "reference from a phone screen would find nothing unless the term is "
        "upper-cased"
    )


def test_search_escapes_like_wildcards():
    """A typed % must be a character, not 'match everything'."""
    svc = _request_service()
    clause = svc.split("private static function searchClause(")[1]
    assert "escapeLike(" in clause
    assert "ESCAPE" in clause
    esc = svc.split("private static function escapeLike(")[1].split("\n    }", 1)[0]
    for ch in ("%", "_"):
        assert f"'{ch}'" in esc, f"escapeLike() must escape {ch}"


def test_phone_branch_only_fires_for_an_all_digit_term():
    """A reference ending in four digits must not match on phone suffix.

    `ZZZZ9999` matched every request whose phone ended 9999. The officer
    pastes the reference in front of the student, the first row looks
    plausible, and it belongs to somebody else.
    """
    svc = _request_service()
    clause = svc.split("private static function searchClause(")[1]
    phone_at = clause.index("sq_phone")
    guard = clause[:phone_at]
    assert "preg_match('/^\\\\d+$/" in guard or "preg_match('/^\\d+$/" in guard, (
        "the phone clause must be guarded on the term being digits only; "
        "the reference alphabet is alphanumeric, so a reference can end in "
        "four digits and would otherwise match on phone suffix"
    )


def test_queue_does_not_render_a_load_failure_as_an_empty_list():
    """"No tickets match these filters" must not stand in for an error.

    The queue page catches Throwable and sets $pageError, but it still
    rendered the empty state, so a query that never ran looked exactly
    like a queue with nothing in it. That is how a hard PDO failure
    (HY093) was read as "there is no work" for weeks.
    """
    src = _strip_comments((ADMIN / "acm_queue.php").read_text(encoding="utf-8"))
    empty_at = src.index("No tickets match these filters")
    guard_at = src.index("$pageError !== ''", src.index("acm_chrome_open("))
    assert guard_at < empty_at, (
        "the empty state must be guarded on $pageError, or a failed load "
        "reads as an empty queue"
    )
    between = src[guard_at:empty_at]
    assert "elseif" in between or "else" in between, (
        "the empty state must be an elseif/else branch of the error check"
    )
