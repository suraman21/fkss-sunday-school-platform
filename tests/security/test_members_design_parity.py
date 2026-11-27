"""The members app's welcome/login screens must BE the prototype's design.

WHY THIS FILE EXISTS
    The first build of the login screen was a white card centred on an ivory
    page. It used the right colours and the right tokens, so every check passed
    -- but it was not the prototype's design, and the owner rejected it: "the
    design have to be cloned exactly no redesign or other design to extend it".

    A colour palette is easy to keep and easy to drift away from. These tests pin
    the COMPOSITION and the exact CSS values that make it the prototype's screen:
    the gold patterned page, the triple-ring gold frame, the emblem medallion
    straddling the card's top edge, the display-face heading and the gold rule.

    Values are the prototype's own, read from
    docs/prototypes/member-app-2026-11-26/frontend/css/fk-theme.css lines
    604-720. If the prototype changes, this file must change with it -- that is
    the point: a silent redesign fails here first.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
PROTO = ROOT / "docs/prototypes/member-app-2026-11-26/frontend/css/fk-theme.css"
PROTO_LOGIN = ROOT / "docs/prototypes/member-app-2026-11-26/frontend/login.html"
THEME = ROOT / "members/public/assets/css/theme.css"
LOGIN = ROOT / "members/resources/views/pages/login.php"
WELCOME = ROOT / "members/resources/views/pages/welcome.php"
LAYOUT = ROOT / "members/resources/views/layouts/base.php"
LANG_PILL = ROOT / "members/resources/views/partials/lang-pill.php"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


class ThePaletteIsThePrototypes(unittest.TestCase):
    """Colours are values, not moods. Compare them, do not describe them."""

    @classmethod
    def setUpClass(cls):
        cls.theme = read(THEME)

    def test_four_stop_gold_page_gradient(self):
        # The prototype's body.login-page background, last layer.
        self.assertIn(
            "linear-gradient(180deg, #F7BE45 0%, #E9A02F 38%, #C27126 72%, #8E3A18 100%)",
            self.theme,
        )

    def test_top_radial_glow(self):
        self.assertIn(
            "radial-gradient(ellipse 90% 55% at 50% 6%, #FFE79A 0%, rgba(255, 231, 154, 0) 70%)",
            self.theme,
        )

    def test_the_cross_pattern_tile_is_present(self):
        # An inlined SVG tile, not an image file: it must survive with no extra
        # request, on a login screen that may load over a weak connection.
        self.assertIn("data:image/svg+xml", self.theme)
        self.assertIn("stroke-opacity='.22'", self.theme)

    def test_ivory_card_gradient(self):
        self.assertIn(
            "linear-gradient(180deg, #FFFBEF 0%, var(--fk-cream) 100%)", self.theme
        )


class TheFramedCardIsThePrototypes(unittest.TestCase):
    """The triple gold ring is the most recognisable part of the design."""

    @classmethod
    def setUpClass(cls):
        cls.theme = read(THEME)

    def test_three_ring_gold_frame(self):
        # 2px deep, 8px bright, 10px deep -- exactly the prototype's stack.
        # Collapse whitespace on BOTH sides before comparing: the declaration is
        # multi-line in both files, and an earlier version of this test compared
        # a collapsed needle against an uncollapsed haystack and always failed.
        compact = re.sub(r"\s+", "", self.theme)
        needle = re.sub(
            r"\s+", "",
            "0 0 0 2px var(--fk-gold-deep), 0 0 0 8px var(--fk-gold), "
            "0 0 0 10px var(--fk-gold-deep), 0 24px 50px rgba(75, 20, 10, .45)",
        )
        self.assertIn(needle, compact)

    def test_card_geometry(self):
        self.assertIn("border-radius: 30px", self.theme)
        self.assertIn("padding: 56px 24px 30px", self.theme)
        self.assertIn("max-width: 460px", self.theme)

    def test_emblem_medallion_straddles_the_top_edge(self):
        # 68px circle pulled up by half its height, so it sits ON the border.
        self.assertIn("top: -34px", self.theme)
        self.assertIn("width: 68px", self.theme)
        self.assertIn("height: 68px", self.theme)

    def test_medallion_draws_the_prototypes_emblem(self):
        self.assertIn("center / 80% no-repeat", self.theme)
        self.assertIn(
            "radial-gradient(circle, #FFFBEF 0 62%, var(--fk-gold) 64% 100%)",
            self.theme,
        )


class TheAuthPageLaysOutAsOneColumn(unittest.TestCase):
    """A flex container with two children laid them out SIDE BY SIDE.

    .fk-auth-main holds the framed card AND the footnote beneath it. As a plain
    `display:flex` row, the footnote rendered as stray text floating to the
    right of the card -- visible in a screenshot from the running site, where
    "ወደ መግቢያ ተመለስ" appeared stranded beside the card.

    The prototype's .login-main is also a flex row, but it has only ONE child
    (the container), so it never hit this. Ours has two, so the direction must
    be explicit. This test exists because the bug was invisible to every value
    comparison -- every colour and radius was correct.
    """

    @classmethod
    def setUpClass(cls):
        cls.theme = read(THEME)

    @staticmethod
    def _block(css: str, selector: str) -> str:
        start = css.index(selector + " {")
        return css[start:css.index("}", start)]

    def test_main_stacks_its_children_vertically(self):
        block = self._block(self.theme, ".fk-auth-main")
        self.assertIn("display: flex", block)
        self.assertIn(
            "flex-direction: column",
            block,
            "two children in a row-direction flex container render side by side",
        )

    def test_main_centres_both_children(self):
        block = self._block(self.theme, ".fk-auth-main")
        self.assertIn("align-items: center", block)

    def test_the_pages_really_do_have_two_children(self):
        """Guards the assumption above: if the footnote moves inside the card,
        column direction stops being load-bearing and this test should be
        re-read rather than silently kept."""
        for page in (LOGIN, WELCOME):
            source = read(page)
            self.assertIn('class="fk-auth-card"', source)
            self.assertIn('class="fk-auth-foot"', source)
            self.assertLess(
                source.index('class="fk-auth-card"'),
                source.index('class="fk-auth-foot"'),
                "the footnote is a sibling AFTER the card, which is what makes "
                "flex-direction load-bearing",
            )


class TheTypographyIsThePrototypes(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.theme = read(THEME)

    def test_display_face_heading_size(self):
        self.assertIn("font-size: clamp(32px, 8vw, 44px)", self.theme)

    def test_gold_rule_under_the_subheading(self):
        # A 140x14 strip: 2px gold line with a 5px dot in the middle.
        self.assertIn("width: 140px", self.theme)
        self.assertIn("height: 14px", self.theme)
        self.assertIn(
            "radial-gradient(circle, var(--fk-gold) 0 5px, transparent 6px) center / 14px 14px no-repeat",
            self.theme,
        )


class ThePagesUseThatComposition(unittest.TestCase):
    """The CSS existing is not the same as the page using it."""

    @classmethod
    def setUpClass(cls):
        cls.login = read(LOGIN)
        cls.welcome = read(WELCOME)
        cls.layout = read(LAYOUT)

    def test_login_is_the_gold_page_with_the_framed_card(self):
        self.assertIn("fk-auth-page", self.login)
        self.assertIn("fk-auth-main", self.login)
        self.assertIn("fk-auth-card", self.login)

    def test_welcome_uses_the_same_frame(self):
        self.assertIn("fk-auth-page", self.welcome)
        self.assertIn("fk-auth-card", self.welcome)

    def test_brand_and_subheading_in_the_prototypes_order(self):
        # h1 brand, then h2 with the rule, then the form -- the prototype's
        # stacking (login.html: h1, h2, p, form).
        body = self.login[self.login.index("<main"):]
        h1 = body.index("<h1>")
        h2 = body.index("<h2>")
        form = body.index("<form")
        self.assertLess(h1, h2)
        self.assertLess(h2, form)

    def test_both_screens_carry_the_language_pill(self):
        self.assertIn("partials/lang-pill.php", self.login)
        self.assertIn("partials/lang-pill.php", self.welcome)

    def test_chromeless_pages_are_not_wrapped_in_the_app_shell(self):
        """A gold page inside the ivory .fk-page wrapper is neither design."""
        self.assertIn("<?php if ($chrome): ?>", self.layout)
        # The church watermark and page wrapper belong to the signed-in shell.
        shell = self.layout[self.layout.index("<?php if ($chrome): ?>"):]
        watermark = shell.index("fk-church")
        self.assertGreater(watermark, shell.index("<?php else: ?>"))

    def test_the_old_white_card_design_is_gone(self):
        # .fk-welcome was the rejected generic screen.
        self.assertNotIn('class="fk-welcome"', self.login)
        self.assertNotIn('class="fk-welcome"', self.welcome)


class TheLanguagePillWorks(unittest.TestCase):
    """The prototype's pill only relabelled itself; ours must change the page."""

    @classmethod
    def setUpClass(cls):
        cls.pill = read(LANG_PILL)

    def test_offers_both_languages(self):
        self.assertIn('data-lang-choice="am"', self.pill)
        self.assertIn('data-lang-choice="en"', self.pill)

    def test_sets_the_cookie_the_server_reads(self):
        # base.php and the pages read $_COOKIE['members_lang'].
        self.assertIn("members_lang=", self.pill)
        self.assertIn("path=/", self.pill)

    def test_closes_on_escape_and_outside_click(self):
        self.assertIn('event.key === "Escape"', self.pill)
        self.assertIn('document.addEventListener("click"', self.pill)


if __name__ == "__main__":
    unittest.main()
