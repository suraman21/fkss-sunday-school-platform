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
import unittest
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
             "acm_staff_enroll.php", "acm_device_recovery.php",
             "acm_ticket.php"]

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


class TestTicketPageCeremony:
    """The ticket is the last page rebuilt, and the one place a code is read out.

    Three things on it are load-bearing and were each broken in the S11 page:

      1. issuing a code must NOT navigate. The code is returned once; a reload
         destroys the only copy the officer will ever see.
      2. the request reference has to be reachable from the ticket, because an
         activation code is verified against a REQUEST, not a ticket. The
         request card shows it at issue time, but a reissue can happen weeks
         later, from this page.
      3. the reason guards are not decoration: reject and request-changes go
         through `decide`, which records the note as the decision reason.
    """

    SRC = (ADMIN / "acm_ticket.php").read_text(encoding="utf-8")

    def test_issuing_a_code_never_reloads_the_page(self):
        src = self.SRC
        issue = src.split("issueBtn.addEventListener", 1)[1]
        issue = issue.split("var cancelBtn", 1)[0]
        assert "code_issue" in issue
        for forbidden in ("reloadSoon()", "location.reload()", "location.href"):
            assert forbidden not in issue, (
                "the issue handler navigates or reloads; the one-time code is "
                "only ever returned in the issue response, so a reload loses it"
            )

    def test_the_request_reference_travels_with_the_code(self):
        src = self.SRC
        assert "acm_activation_requests WHERE ticket_id" in src, (
            "the ticket page no longer looks up its request reference, so an "
            "officer reissuing from here cannot tell the member what to pair "
            "the digits with"
        )
        issue = src.split("issueBtn.addEventListener", 1)[1]
        assert "ref" in issue and "request reference" in issue.lower(), (
            "the issue message must give the reference alongside the code"
        )

    def test_reject_and_request_changes_require_a_written_reason(self):
        src = self.SRC
        assert "reasonRequired" in src
        assert "'request_changes'" in src and "'reject'" in src, (
            "a decision note is the audit's only record of why; a rejection "
            "with no reason cannot be reviewed"
        )

    def test_missing_evidence_says_so_in_words(self):
        src = self.SRC
        assert "not on file" in src, (
            "an empty fact cell must say 'not on file', not print a bare dash"
        )


class DetectorColumnContract(unittest.TestCase):
    """The detector must only name columns that the schema actually has.

    WHY THIS EXISTS (production 500, Ref #868)
        memberRows() selected `m.photo_path`. No migration has ever added
        that column to `members` -- the register stores it as
        `student_photo_path` -- so with PDO's native prepares (config.php
        sets ATTR_EMULATE_PREPARES false) MySQL rejected the whole statement
        with "Unknown column". The result was not a degraded photo:

          * admin/acm_request.php 500'd on every request, so no reviewer could
            open a verification card at all;
          * AcmActivationRequestService::submit() calls the same detector
            before it inserts, so the member app could not submit a claim
            either.

        A source-level test cannot run the query, but it CAN check the column
        names against the register's schema, which is where the mistake was.
        Every other column in that SELECT was verified present; this pins it.
    """

    def setUp(self):
        self.detector = strip_comments(read(ACM / "Requests" / "AcmActivationDetector.php"))
        self.schema = read(ROOT / "sql" / "legacy" / "database_schema.sql")

    def test_members_columns_in_the_detector_query_all_exist(self):
        # The SELECT list lives between "SELECT m." and "FROM members".
        body = re.search(r"SELECT m\.(.*?)FROM members", self.detector, re.S)
        self.assertIsNotNone(body, "the members query moved; update this test")
        listed = set(re.findall(r"\bm\.(\w+)", body.group(1)))

        # Column names the legacy register actually defines.
        defined = set(re.findall(r"^\s*`(\w+)`", self.schema, re.M))
        # Columns added by migrations (CALL ssms_013_add_column('members', ...)).
        for mig in (ROOT / "sql").glob("*.sql"):
            defined |= set(re.findall(
                r"add_column\('members','(\w+)'", read(mig), re.I))

        unknown = sorted(listed - defined)
        self.assertEqual(
            [], unknown,
            "AcmActivationDetector selects members columns the schema never "
            f"defines: {unknown}. With native prepares that is a fatal "
            "'Unknown column' on every request, not a graceful miss.",
        )

    def test_photo_comes_from_the_real_column(self):
        # members stores the photo as student_photo_path; prove the query uses
        # it rather than inventing a second name for the same thing.
        self.assertIn("student_photo_path", self.detector)
        self.assertNotRegex(
            self.detector, r"m\.photo_path",
            "m.photo_path does not exist on members (Ref #868)",
        )

    def test_optional_photo_cannot_take_the_page_down(self):
        # The photo is the one column probed rather than assumed, so a
        # deployment with an older `members` degrades to "no photo".
        self.assertIn("membersColumnExists", self.detector)
        self.assertIn("AS photo_path", self.detector)

    def test_guardian_link_is_not_read_off_the_member_row(self):
        # guardians live in member_guardians (sql/069); `members` has no
        # guardian_id, so reading it there yields null forever.
        self.assertIn("member_guardians", self.detector)


class MemberCanActivateWithoutAPreRegisteredPerson(unittest.TestCase):
    """The activation route must work for the whole register, not a subset.

    WHY THIS EXISTS (production, Ref #868 follow-up)
        Detection used to refuse a claim whose member had no acm_persons row
        (`no_account`). The ONLY writer of that row was the manual
        person_create in the persons console, and migration 079 creates the
        table while explicitly leaving the register untouched -- no backfill
        exists anywhere. So a member could match the register perfectly and
        still be refused, forever, unless an officer had already registered
        them by hand. Every member is registered and none have accounts by
        default, so this refused most of the membership.
    """

    def setUp(self):
        self.detector = strip_comments(read(ACM / "Requests" / "AcmActivationDetector.php"))
        self.service = strip_comments(read(ACM / "Requests" / "AcmActivationRequestService.php"))
        self.persons = strip_comments(read(ACM / "Identity" / "AcmPersonService.php"))

    def test_missing_person_row_is_not_a_refusal(self):
        # The NO_ACCOUNT constant may remain for the audit vocabulary, but the
        # detector must not return it: a real member is RESOLVED.
        body = self.detector.split("$personId = $this->personIdFor($member);")[1]
        self.assertNotIn(
            "self::NO_ACCOUNT", body.split("return [")[1] if "return [" in body else "",
            "a missing person row must not refuse a registered member",
        )
        self.assertIn("self::RESOLVED", body)

    def test_service_creates_the_person_link_for_a_resolved_claim(self):
        self.assertIn("ensureForMember", self.service)
        # It must key off the detected member, not off anything the caller sent.
        self.assertIn("$detection['member_id']", self.service)

    def test_person_link_is_idempotent_and_race_safe(self):
        # Returns an existing row untouched; a concurrent claim converges on
        # the winner instead of creating a second person for one member.
        self.assertIn("public function ensureForMember", self.persons)
        self.assertIn("personIdForMember", self.persons)
        self.assertIn("catch (\\PDOException $race)", self.persons)

    def test_person_row_is_derived_from_the_register(self):
        # The register is the directory of people; the row is built from it
        # rather than asking the member to retype what the school already has.
        body = self.persons.split("public function ensureForMember")[1]
        body = body.split("public function personIdForMember")[0]
        for column in ("full_name_am", "father_name", "baptismal_name",
                       "date_of_birth", "phone_primary", "member_id"):
            self.assertIn(column, body, column)

    def test_link_failure_never_turns_a_claim_into_a_refusal(self):
        body = self.service.split("$personId = null;")[1].split("$phoneLast4")[0]
        self.assertIn("catch (\\Throwable", body)


class NonStringFieldsAreReportedNotSwallowed(unittest.TestCase):
    """A number posted where text belongs is a client bug and must say so.

    `acmStrictBody` coerced any non-string to '' silently, so a portal that
    posted {"member_code": 17615} was told "Check the details you entered" --
    pointing the member at their ID when the fault was the form. The 400 is
    about the body's type, so it says nothing about whether the ID exists and
    does not weaken the anti-enumeration rule.
    """

    def test_non_string_field_is_rejected_with_a_400(self):
        route = strip_comments(read(ROOT / "api" / "v1" / "routes" / "acm.php"))
        body = route.split("function acmStrictBody")[1].split("if (!($pdo instanceof PDO))")[0]
        self.assertIn("must be text", body)
        self.assertIn("400", body)
        # The non-string branch must REJECT; it must not fall through to the
        # default-assignment branch. (`$out[$key] = ''` is still correct for an
        # ABSENT field -- optional fields keep defaulting -- so the assertion
        # is about the wrong-value branch specifically.)
        wrong_value = body.split("!is_string($value)")[1].split("$value === null")[0]
        self.assertIn("err(", wrong_value)
        self.assertNotIn("$out[$key]", wrong_value)

    def test_absent_optional_field_still_defaults(self):
        # null is not a type error: optional fields keep their '' default so
        # existing clients that omit them are unaffected.
        route = strip_comments(read(ROOT / "api" / "v1" / "routes" / "acm.php"))
        body = route.split("function acmStrictBody")[1].split("if (!($pdo instanceof PDO))")[0]
        self.assertIn("$value === null", body)


class RefusalsAreVisibleToStaff(unittest.TestCase):
    """recordRefusal() wrote a reason that nothing ever read.

    The public response must stay reasonless (anti-enumeration), but the
    OFFICE needs the reason: "no member has that ID" and "the first name does
    not agree" are different fixes for the member standing in front of them.
    """

    def test_requests_page_reads_the_refusal_log(self):
        page = read(ADMIN / "acm_requests.php")
        self.assertIn("acm_request_refusals", page)
        for kind in ("no_member", "name_mismatch", "ambiguous", "not_active"):
            self.assertIn(kind, page, kind)

    def test_missing_refusal_table_does_not_break_the_queue(self):
        page = read(ADMIN / "acm_requests.php")
        body = page.split("acm_request_refusals")[0]
        self.assertIn("try {", body)


class RegisterSearchReachesUnlinkedMembers(unittest.TestCase):
    """The office must find a person the register knows but ACM does not.

    Most members have a `members` row and no acm_persons row, so a search that
    only queried acm_persons could not find the person whose paper file is on
    the desk. The register is read on the same screen and feeds the existing,
    audited "Register person" form -- no new write path.
    """

    def setUp(self):
        self.page = read(ADMIN / "acm_person.php")

    def test_search_falls_through_to_the_register(self):
        self.assertIn("FROM members m", self.page)
        self.assertIn("registerMatches", self.page)

    def test_archived_members_are_excluded(self):
        self.assertIn("m.status <> 'archived'", self.page)

    def test_register_match_projects_instead_of_registers(self):
        # HR owns registration. The console projects the person from the
        # register row and opens the account side; it never creates one.
        self.assertIn("data-acm-resolve", self.page)
        self.assertIn("person_resolve", self.page)
        self.assertNotIn("person_create", self.page)

    def test_register_query_does_not_reuse_a_named_placeholder(self):
        # ATTR_EMULATE_PREPARES is false: :q repeated is HY093 at runtime.
        block = self.page.split("FROM members m")[1].split("fetchAll")[0]
        self.assertNotIn(":q ", block)
        self.assertIn(":q1", block)
