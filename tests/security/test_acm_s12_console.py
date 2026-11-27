"""S12: the refined activation console must keep the properties that make it safe.

WHY THIS FILE EXISTS
    S12 changed two things that are easy to undo by accident:

      1. A claim that matches no registered member is REFUSED. Before, any
         well-formed claim became a queue row, so the office did the
         verification by hand and a wrong ID cost a human decision.

      2. The refusal is one message for every failure mode. The tempting
         "improvement" is to say which field was wrong ("that ID is not on
         the register", "the name does not match"), which would turn an
         unauthenticated endpoint into a member-directory oracle: a caller
         could tell a real ID from a fake one by the shape of the error.

    Both are the kind of decision that a later refactor removes politely, so
    they are pinned here rather than left to a commit message.

WHAT THIS DOES NOT DO
    These are source-level assertions. They cannot prove the query returns
    the right rows -- that is covered by the runtime probes and by
    test_acm_self_service_activation. They prove the *shape* of the code
    still honours the decisions.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ACM = ROOT / "acm" / "src"
ROUTES = ROOT / "api" / "v1" / "routes" / "acm.php"
ADMIN = ROOT / "admin"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def strip_comments(source: str) -> str:
    """Remove PHP and doc comments.

    Every assertion here is about code that RUNS. A comment that mentions
    `full_name` or `getMessage()` must not satisfy (or break) a check.
    """
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    source = re.sub(r"(?m)^\s*//.*$", "", source)
    source = re.sub(r"(?m)#.*$", "", source)
    source = re.sub(r"(?m)(?<![:\w])//[^\n]*$", "", source)
    return source


class TestRefusalIsNotAnOracle:
    """The public endpoint must not reveal whether an ID exists."""

    def test_the_refusal_carries_one_message_and_no_kind(self):
        route = strip_comments(read(ROUTES))
        # Find the catch block for the refusal.
        at = route.index("AcmClaimRefused $refused")
        block = route[at:at + 900]
        assert "PUBLIC_MESSAGE" in block, (
            "the refusal must send the constant message, not an exception text"
        )
        assert "$refused->getMessage()" not in block, (
            "the exception's own message must never reach err(): it is the "
            "field-level reason, and echoing it makes the endpoint an oracle"
        )
        # kind() is allowed for logging but must not be in the response body.
        payload = block[block.index("err("):] if "err(" in block else ""
        assert "kind" not in payload.replace("'code'", ""), (
            "the refusal payload must not name which check failed"
        )

    def test_the_public_message_names_no_field_and_no_reason(self):
        src = strip_comments(read(ACM / "Requests" / "AcmClaimRefused.php"))
        # The constant is built by concatenation across lines, so read every
        # literal up to the terminating semicolon. Reading only the first one
        # would miss "contact the church office." on the next line.
        m = re.search(r"PUBLIC_MESSAGE\s*=\s*(.+?);", src, re.S)
        assert m, "PUBLIC_MESSAGE must be a plain constant"
        message = " ".join(re.findall(r"'([^']*)'", m.group(1))).lower()
        assert message.strip(), "PUBLIC_MESSAGE must not be empty"
        # The rule is that the message must not ISOLATE a field as the wrong
        # one, and must not confirm whether an ID exists. Telling the person
        # to re-check both inputs ("the ID number and first name") is fine and
        # is what makes the message actionable rather than a dead end -- so
        # this looks for accusatory/confirmatory phrasings, not for the words
        # "id" or "name".
        for forbidden in (
            "does not exist", "not found", "no such", "not registered",
            "unknown member", "mismatch", "does not match", "wrong",
            "invalid", "incorrect", "already taken", "archived", "ambiguous",
            "not active", "the name is", "the id is",
        ):
            assert forbidden not in message, (
                f"the public refusal message must not say {forbidden!r}: it is "
                "identical for every failure mode by design, so it may not "
                "identify which check failed or whether the ID is real"
            )
        assert "office" in message, (
            "a refusal has to tell the person what to do instead; the office "
            "is the only route that can resolve it"
        )

    def test_the_message_is_identical_across_kinds(self):
        """One constant, not a switch over kinds."""
        src = strip_comments(read(ACM / "Requests" / "AcmClaimRefused.php"))
        assert src.count("PUBLIC_MESSAGE") >= 1
        # A per-kind message table would defeat the whole point.
        assert not re.search(r"(?i)(message|text)\s*=>\s*\[", src), (
            "there must be no per-kind message table"
        )


class TestDetectionGate:
    """Nothing unverified becomes office work."""

    def test_submit_detects_before_it_inserts(self):
        src = strip_comments(read(ACM / "Requests" / "AcmActivationRequestService.php"))
        submit_at = src.index("public function submit(")
        body = src[submit_at:]
        detect_at = body.index("detect(")
        # The first INSERT in submit() must come after the detection.
        insert_at = body.index("INSERT INTO acm_activation_requests")
        assert detect_at < insert_at, (
            "the claim must be verified before a row is written; otherwise "
            "an unmatched claim still creates office work"
        )

    def test_the_gate_only_accepts_exact_or_homophone(self):
        src = strip_comments(read(ACM / "Identity" / "AmharicNameMatcher.php"))
        m = re.search(r"function agrees\([^)]*\)[^{]*\{(.{0,400})", src, re.S)
        assert m, "agrees() must exist"
        body = m.group(1)
        assert "'exact'" in body and "'homophone'" in body
        assert "'close'" not in body, (
            "a one-letter difference is also what a WRONG name looks like; "
            "close must never satisfy the gate"
        )

    def test_ambiguity_binds_nothing(self):
        src = strip_comments(read(ACM / "Requests" / "AcmActivationDetector.php"))
        at = src.index("AMBIGUOUS")
        window = src[at:at + 800]
        assert "person_id" in window and "null" in window, (
            "an ambiguous match must resolve to no person at all, not to the "
            "highest-scoring candidate"
        )


class TestAmharicFolding:
    """Homophone folding must keep vowels and stay arithmetic."""

    def test_families_are_computed_not_listed(self):
        src = strip_comments(read(ACM / "Identity" / "AmharicNameMatcher.php"))
        assert "& ~7" in src or "& 0xFFF8" in src, (
            "the consonant family must be derived from the code point base; a "
            "hand-written glyph table folded all seven vowel orders of the "
            "glottal series and made አ equal ኡ"
        )

    def test_latin_is_lowercased_by_the_fold(self):
        src = strip_comments(read(ACM / "Identity" / "AmharicNameMatcher.php"))
        fold_at = src.index("function fold(")
        body = src[fold_at:fold_at + 1600]
        assert "strtolower" in body, (
            "fold() must lowercase non-Ethiopic text, or 'Nahom' and 'nahom' "
            "compare as different names"
        )


class TestInlineApproval:
    """The officer must not be thrown to another page mid-ceremony."""

    PAGE = ADMIN / "acm_request.php"

    def test_approving_reveals_the_ticket_in_place(self):
        src = read(self.PAGE)
        assert "acmTicketStage" in src, "the ticket stage must exist in the page"
        assert "revealTicket(" in src, "approval must reveal, not navigate"
        # The old behaviour was a hard redirect to the ticket page.
        assert "window.location.href = '/admin/acm_ticket.php?id='" not in src, (
            "approving must not redirect to the ticket screen; the whole "
            "ceremony happens in the decision panel"
        )

    def test_the_code_can_be_issued_without_leaving(self):
        src = read(self.PAGE)
        assert "'code_issue'" in src, (
            "the panel must issue the activation code itself"
        )

    def test_the_reference_is_given_with_the_code(self):
        """The OTP is scoped to one request, so both halves are needed."""
        src = read(self.PAGE)
        code_at = src.index("acmCodeBox")
        window = src[code_at:code_at + 4000]
        assert "request_ref" in window, (
            "verification is scoped to the request reference, so the officer "
            "must hand over the reference as well as the code"
        )

    def test_a_terminal_ticket_still_offers_the_ticket(self):
        src = read(self.PAGE)
        assert "acm_ticket.php?id=" in src, (
            "the long-running ticket page is still reachable for devices, "
            "notes, cancel and reissue"
        )


class TestNavigationHonesty:
    """A count shown against the wrong menu item is worse than none."""

    def test_the_badge_follows_the_nav_item_it_belongs_to(self):
        src = strip_comments(read(ADMIN / "acm_layout.php"))
        assert "$badgeNav" in src, (
            "the pending count was hard-coded to the 'queue' item, so it "
            "appeared next to 'Activation queue' while the officer was on "
            "'Activation requests'"
        )

    def test_requests_page_routes_its_badge(self):
        src = strip_comments(read(ADMIN / "acm_requests.php"))
        at = src.index("acm_chrome_open(")
        window = src[at:at + 400]
        assert "'requests'" in window, (
            "acm_requests.php must declare which nav item owns its badge"
        )


class TestDualTheme:
    """Light-first with dark preserved, per the accepted design direction."""

    CSS = ADMIN / "css" / "acm-v2.css"

    def test_light_is_the_document_default(self):
        src = read(ADMIN / "acm_layout.php")
        assert 'data-theme="light"' in src, (
            "the console renders light-first; dark is kept as a toggle"
        )

    def test_dark_tokens_still_exist(self):
        css = read(self.CSS)
        assert '[data-theme="dark"]' in css, "dark must remain a first-class theme"
        assert "--v2-page" in css and "--v2-ink" in css

    def test_the_new_layer_is_loaded_after_the_old_one(self):
        src = read(ADMIN / "acm_layout.php")
        old = src.index("acm.css")
        new = src.index("acm-v2.css")
        assert old < new, (
            "acm-v2.css refines acm.css, so it must load after it"
        )

    def test_legacy_component_classes_are_preserved(self):
        """Eight pages and the ticket screen still use these."""
        css = read(self.CSS)
        for cls in (".np", ".cd", ".ct", ".tw", ".inp", ".btn", ".bg-ok", ".acm-alert"):
            assert cls + " " in css or cls + "{" in css or cls + "," in css, (
                f"{cls} is used by pages outside this redesign and must keep "
                "its definition"
            )

    def test_no_undefined_custom_property(self):
        """An undefined var() fails silently and looks like a typo in the UI."""
        v2 = re.sub(r"/\*.*?\*/", "", read(self.CSS), flags=re.S)
        v1 = re.sub(r"/\*.*?\*/", "", read(ADMIN / "css" / "acm.css"), flags=re.S)
        defined = set(re.findall(r"(--[a-zA-Z0-9-]+)\s*:", v2)) | set(
            re.findall(r"(--[a-zA-Z0-9-]+)\s*:", v1)
        )
        used = set(re.findall(r"var\(\s*(--[a-zA-Z0-9-]+)", v2))
        assert used <= defined, f"undefined custom properties: {sorted(used - defined)}"

    def test_reduced_motion_is_respected(self):
        css = read(self.CSS)
        assert "prefers-reduced-motion" in css, (
            "the console animates toasts and spinners; a user who asked for "
            "less motion must not get it anyway"
        )


class TestAccessibility:
    CSS = ADMIN / "css" / "acm-v2.css"

    def test_focus_is_visible(self):
        css = read(self.CSS)
        assert ":focus-visible" in css, "keyboard focus must not be invisible"

    def test_a_skip_link_exists(self):
        src = read(ADMIN / "acm_layout.php")
        assert 'href="#acmMain"' in src, (
            "nine navigation items sit before the content on every page"
        )
        assert 'id="acmMain"' in src

    def test_status_is_never_colour_only(self):
        """Every status chip also carries words."""
        page = read(ADMIN / "acm_requests.php")
        # The chip text is the label, not an icon alone.
        assert "statusLabel[" in page

    def test_danger_actions_are_visually_distinct(self):
        css = read(self.CSS)
        assert ".v2-danger" in css and ".v2-ok" in css, (
            "reject and approve must not share a visual treatment"
        )


class TestContrast:
    """The palette is verified, not eyeballed.

    WCAG AA needs 4.5:1 for body text. The first pass at this layer put
    white text on the dark theme's light-green approve hover: 2.0:1, which
    is unreadable, and it was only found by computing the ratio. A filled
    semantic colour therefore needs its own on-colour token per theme.
    """

    CSS = ADMIN / "css" / "acm-v2.css"

    @staticmethod
    def _tokens(block: str) -> dict:
        return dict(re.findall(r"(--v2-[a-zA-Z0-9-]+)\s*:\s*(#[0-9a-fA-F]{3,8})", block))

    @staticmethod
    def _luminance(hex_colour: str) -> float:
        h = hex_colour.lstrip("#")
        if len(h) == 3:
            h = "".join(c * 2 for c in h)
        r, g, b = (int(h[i:i + 2], 16) / 255 for i in (0, 2, 4))
        f = lambda c: c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)

    @classmethod
    def _ratio(cls, a: str, b: str) -> float:
        la, lb = cls._luminance(a), cls._luminance(b)
        hi, lo = max(la, lb), min(la, lb)
        return (hi + 0.05) / (lo + 0.05)

    def _blocks(self):
        css = re.sub(r"/\*.*?\*/", "", read(self.CSS), flags=re.S)
        light = css[css.index(":root,"):css.index('[data-theme="dark"]')]
        dark = css[css.index('[data-theme="dark"]'):]
        dark = dark[:dark.index("}", dark.index("--v2-page")) + 1]
        return {"light": self._tokens(light), "dark": self._tokens(dark)}

    def test_theme_blocks_define_the_same_tokens(self):
        b = self._blocks()
        missing = set(b["light"]) - set(b["dark"])
        assert not missing, (
            f"tokens defined for light but not dark: {sorted(missing)}. An "
            "undefined token silently falls back to the light value, so dark "
            "mode inherits a light surface"
        )

    def test_body_text_meets_aa_in_both_themes(self):
        b = self._blocks()
        for theme, tokens in b.items():
            for fg, bg in (("--v2-ink", "--v2-page"),
                           ("--v2-ink-2", "--v2-raised"),
                           ("--v2-bad", "--v2-raised"),
                           ("--v2-ok", "--v2-raised")):
                ratio = self._ratio(tokens[fg], tokens[bg])
                assert ratio >= 4.5, (
                    f"{theme}: {fg} on {bg} is {ratio:.2f}:1, below AA 4.5"
                )

    def test_filled_colours_use_their_own_ink(self):
        """White on the dark theme's light hues is the failure this catches."""
        b = self._blocks()
        for theme, tokens in b.items():
            for ink, fill in (("--v2-on-ok", "--v2-ok"), ("--v2-on-bad", "--v2-bad"),
                              ("--v2-accent-ink", "--v2-accent")):
                assert ink in tokens, f"{theme}: missing {ink}"
                ratio = self._ratio(tokens[ink], tokens[fill])
                assert ratio >= 4.5, (
                    f"{theme}: {ink} on {fill} is {ratio:.2f}:1, below AA 4.5"
                )

    def test_no_hardcoded_white_on_a_semantic_fill(self):
        css = re.sub(r"/\*.*?\*/", "", read(self.CSS), flags=re.S)
        # `color: #fff` on a hover that also sets a semantic background is how
        # the 2.0:1 pair got in. Filled states must use the on-colour token.
        for m in re.finditer(r"\.(?:v2-ok|v2-danger|bd):hover\s*\{([^}]*)\}", css):
            body = m.group(1)
            assert "color: #fff" not in body.lower(), (
                "a filled semantic hover must use --v2-on-ok / --v2-on-bad, "
                "not a hard-coded white"
            )


class TestScriptOrder:
    """Page scripts must not run before the shared helpers exist.

    The console's helpers (acmApi, acmToast, acmCsrf) are emitted by
    acm_chrome_close(), which runs at the END of each page. A page that
    calls one of them at top level -- `load();` at the bottom of its own
    IIFE -- executes BEFORE the definition has been parsed, so the call
    throws ReferenceError and the page never fetches its data.

    That is not theoretical: acm_office.php and acm_audit.php both did
    exactly this. Their tables sat on "Loading…" forever in a real
    browser, and no test noticed because the tests read source text
    rather than executing it. This guard is static, so it catches the
    shape; the browser check below is what actually proves behaviour.
    """

    PAGES = ["acm_office.php", "acm_audit.php", "acm_person.php",
             "acm_queue.php", "acm_requests.php", "acm_request.php",
             "acm_staff_enroll.php", "acm_device_recovery.php"]

    def test_no_top_level_helper_call_before_chrome_close(self):
        """A helper called at the IIFE's top level runs before the definition.

        Indentation alone cannot decide this: a call inside a function body
        is deferred and safe. So walk the page script tracking brace depth,
        and only flag a call made at depth 1 (inside the page IIFE but not
        inside any function within it) -- that is the shape that throws.
        """
        for name in self.PAGES:
            src = read(ADMIN / name)
            close_at = src.index("acm_chrome_close()")
            page_script = src[:close_at]

            # Only look inside <script> blocks; markup mentions are harmless.
            for block in re.findall(r"<script[^>]*>([\s\S]*?)</script>", page_script):
                body = re.sub(r"/\*[\s\S]*?\*/", "", block)
                body = re.sub(r"(?m)//.*$", "", body)
                depth = 0
                for line in body.split("\n"):
                    stripped = line.strip()
                    if depth == 1 and stripped and not stripped.startswith("*"):
                        for helper in ("acmApi(", "acmToast(", "acmCsrf("):
                            if stripped.startswith(helper):
                                raise AssertionError(
                                    f"{name}: {helper} called at the top level of "
                                    f"the page script, before acm_chrome_close() "
                                    f"defines it. In a browser this throws "
                                    f"ReferenceError and the page never loads its "
                                    f"data. Line: {stripped!r}"
                                )
                    # function(){} bodies close on the same line often enough
                    # that counting raw braces is the reliable measure here.
                    depth += stripped.count("{") - stripped.count("}")
