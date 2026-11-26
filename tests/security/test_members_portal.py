"""Members portal: the structural promises this app depends on.

The portal was built as a folder structure before it had features, so the
promises are all about shape. Each one is here because breaking it is silent:

1. The role gate is an allow-list and fails closed. The audit found four role
   maps that had drifted and two routes that leaked precisely because they
   named roles to refuse instead of roles to allow. A test that only checked
   "member is allowed" would not catch that returning.
2. Every page in the table is servable: the clean URL and the entry file share
   a parent directory, and the entry file exists. A nested page, or a handler
   under a public/ subfolder, breaks the registry's dirname rule and the page
   becomes unreachable rather than wrong -- the worst kind of failure.
3. Entry files name their own page as a constant. Nothing in the request picks
   the template.
4. The directory has no rewrite rules of its own, and the root denies its code
   and templates, so a page file cannot be reached around the entry file.
"""
import json
import re
import shutil
import subprocess
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from structure_paths import ROOT, code_only  # noqa: E402

PORTAL = ROOT / "members"
ROUTER = PORTAL / "src" / "Http" / "Router.php"
REGISTRY = ROOT / "admin" / "backend" / "services" / "BrowserPageRoutes.php"
HTACCESS = ROOT / ".htaccess"


def router_pages():
    """Parse Router::PAGES without a PHP binary.

    Each row looks like:  ''  => ['home', 'Home', self::MEMBER_ROLES, true],
    """
    source = code_only(ROUTER.read_text(encoding="utf-8"))
    block = re.search(r"PAGES\s*=\s*\[(.*?)\n    \];", source, re.S)
    if block is None:
        return None
    rows = {}
    for m in re.finditer(
            r"'(?P<slug>[^']*)'\s*=>\s*\[\s*'(?P<view>[^']+)'\s*,\s*'(?P<title>[^']*)'\s*,"
            r"\s*(?P<roles>self::MEMBER_ROLES|\[[^\]]*\])\s*,\s*(?P<session>true|false)\s*\]",
            block.group(1)):
        rows[m.group("slug")] = {
            "view": m.group("view"),
            "title": m.group("title"),
            "roles": m.group("roles"),
            "requires_session": m.group("session") == "true",
        }
    return rows


def registry_pages():
    source = code_only(REGISTRY.read_text(encoding="utf-8"))
    block = re.search(r"PAGES\s*=\s*\[(.*?)\n    \];", source, re.S)
    assert block is not None, "BrowserPageRoutes::PAGES is no longer parseable"
    return dict(re.findall(r"'(/[^']*)'\s*=>\s*'(/[^']*)'", block.group(1)))


class MembersPortalStructure(unittest.TestCase):
    def test_router_table_is_readable(self):
        pages = router_pages()
        self.assertIsNotNone(pages, "Router::PAGES is no longer parseable")
        self.assertGreaterEqual(len(pages), 3, pages)

    # 1 -- the gate ------------------------------------------------------
    def test_role_gate_is_one_allow_list_and_fails_closed(self):
        gate = code_only((PORTAL / "src/Auth/RoleGate.php").read_text(encoding="utf-8"))

        # The decision is membership in the page's own list...
        self.assertIn("!in_array($role, $page->roles(), true)", gate)
        # ...and an empty role is refused, not treated as low privilege.
        self.assertIn("$role === ''", gate)
        # There is no deny-list: no "unless the role is X, let them in".
        self.assertNotRegex(gate, r"!\s*in_array\(\s*\$role\s*,\s*\[")
        self.assertNotIn("else {\n            return;", gate)

    def test_only_members_and_parents_are_served(self):
        router = code_only(ROUTER.read_text(encoding="utf-8"))
        self.assertEqual(re.search(r"MEMBER_ROLES\s*=\s*\[([^\]]*)\]", router).group(1).count("'"), 4)
        roles = re.search(r"MEMBER_ROLES\s*=\s*\[([^\]]*)\]", router).group(1)
        self.assertEqual(set(re.findall(r"'([a-z_]+)'", roles)), {"member", "parent"})

        for slug, page in router_pages().items():
            self.assertEqual(page["roles"], "self::MEMBER_ROLES", slug)

    def test_only_sign_in_is_public(self):
        for slug, page in router_pages().items():
            if not page["requires_session"]:
                self.assertIn(slug, ("/login",), f"{slug} is public; that needs a reason")

    def test_staff_roles_are_named_only_to_word_the_refusal(self):
        gate = code_only((PORTAL / "src/Auth/RoleGate.php").read_text(encoding="utf-8"))
        # isStaffRole() must be used for the message, never as the decision.
        self.assertIn("self::isStaffRole($role)", gate)
        decision = gate.index("!in_array($role, $page->roles(), true)")
        self.assertLess(decision, gate.index("self::isStaffRole($role)"))

    # 2 -- every page is servable ----------------------------------------
    def test_every_page_has_an_entry_file_and_a_template(self):
        for slug, page in router_pages().items():
            entry = PORTAL / (slug.lstrip("/") or "index")
            entry = entry.with_name(entry.name + ".php")
            self.assertTrue(entry.is_file(), f"{slug} has no entry file {entry.name}")
            self.assertTrue((PORTAL / "templates/pages" / (page["view"] + ".php")).is_file(),
                            f"{slug} points at a missing template {page['view']}.php")

    def test_clean_url_and_entry_file_share_a_parent_directory(self):
        # The registry's own rule, applied to this app: /members/x may only be
        # served by a file directly in members/.
        for slug in router_pages():
            self.assertRegex(slug, r"^(|/[a-z][a-z0-9-]*)$",
                             f"{slug} is nested; the registry cannot route it")
            if slug == "":
                # /members is the directory itself (DirectoryIndex), not a
                # registry entry -- covered by the registry test below.
                continue
            clean = Path("/members") / slug.lstrip("/")
            entry = Path("/members") / (slug.lstrip("/") + ".php")
            self.assertEqual(clean.parent, entry.parent, slug)

    def test_registry_entries_for_this_app_are_reachable_and_consistent(self):
        pages = registry_pages()
        mine = {c: h for c, h in pages.items() if c.startswith("/members")}

        self.assertIn("/members/login", mine)
        self.assertIn("/members/home", mine)
        # /members is the directory itself, answered by DirectoryIndex, so it
        # must NOT be in the registry: '/members' -> '/members/index.php'
        # would give dirname '/' vs '/members'.
        self.assertNotIn("/members", mine)

        for clean, handler in mine.items():
            self.assertEqual(Path(clean).parent, Path(handler).parent, clean)
            self.assertTrue((ROOT / handler.lstrip("/")).is_file(), handler)
            self.assertNotIn(".php", clean)
            self.assertRegex(clean, r"^/members/[a-z][a-z0-9-]*$")

    # 3 -- the request cannot pick the page ------------------------------
    def test_entry_files_name_their_page_as_a_constant(self):
        entries = sorted(PORTAL.glob("*.php"))
        self.assertGreaterEqual(len(entries), 3)
        for entry in entries:
            source = code_only(entry.read_text(encoding="utf-8"))
            self.assertRegex(source, r"Bootstrap::create\(\)->run\('[^']*'\)", entry.name)
            for superglobal in ("$_GET", "$_POST", "$_REQUEST", "$_COOKIE", "SCRIPT_NAME"):
                self.assertNotIn(superglobal, source, f"{entry.name} reads {superglobal}")

    def test_no_entry_file_queries_the_database(self):
        for entry in sorted(PORTAL.glob("*.php")):
            source = code_only(entry.read_text(encoding="utf-8")).lower()
            for forbidden in ("select ", "insert ", "update ", "mysqli", "pdo", "config.php"):
                self.assertNotIn(forbidden, source, f"{entry.name} contains {forbidden!r}")

    # 4 -- the folder cannot be reached around -------------------------
    def test_portal_htaccess_has_no_rewrite_rules(self):
        text = (PORTAL / ".htaccess").read_text(encoding="utf-8")
        # Directives only: the comment explains why the directive is absent.
        directives = [line for line in text.splitlines()
                      if line.strip() and not line.lstrip().startswith("#")]
        self.assertEqual([line for line in directives if "Rewrite" in line], [])
        self.assertIn("Options -Indexes -MultiViews", text)

    def test_root_htaccess_denies_code_and_templates_but_dispatches_pages(self):
        text = HTACCESS.read_text(encoding="utf-8")
        self.assertIn(r"RewriteRule ^members/(src|templates)(/|$) - [F,L]", text)

        # Both halves of the dispatch pair must know 'members'; updating one
        # and not the other silently loses the deployment prefix.
        prefix = re.search(r"RewriteCond %\{REQUEST_URI\} \^.*members.*$", text, re.M)
        dispatch = re.search(r"RewriteRule \^.*members.*browser_page\.php \[END\]$", text, re.M)
        self.assertIsNotNone(prefix, "the REQUEST_URI prefix rule does not know /members")
        self.assertIsNotNone(dispatch, "the dispatch rule does not know /members")
        # The prefix condition must precede the dispatch rule.
        self.assertLess(prefix.start(), dispatch.start())

        # Denials must still precede dispatch.
        self.assertLess(text.index("^members/(src|templates)"),
                        text.index("browser_page.php [END]"))

    # 5 -- what the code does, not only what it looks like ---------------
    def test_behaviour_fixture_passes(self):
        """Run the portal for real: roles, refusals, escaping, 404 handling.

        Shape assertions above cannot see a gate that is wired the wrong way
        round. This runs the same fixture CI runs, with a session supplied
        directly, so it needs no database, no HTTP server and no session
        store.
        """
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is required (the CI job has it)")

        fixture = ROOT / "tests/fixtures/members_portal.fixture"
        self.assertTrue(fixture.is_file())
        run = subprocess.run([php, str(fixture), str(ROOT)],
                             capture_output=True, text=True, timeout=60)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)

        lines = [line for line in run.stdout.strip().splitlines() if line.startswith("{")]
        self.assertTrue(lines, run.stdout + run.stderr)
        result = json.loads(lines[-1])
        self.assertEqual(result["status"], "PASS", result)
        self.assertGreaterEqual(result["checks"], 35, "the fixture lost checks")

    def test_portal_readme_links_resolve(self):
        readme = PORTAL / "README.md"
        self.assertTrue(readme.is_file())
        body = readme.read_text(encoding="utf-8")
        for target in re.findall(r"\]\(([^)\s]+)\)", body):
            if re.match(r"^[a-z][a-z0-9+.-]*:", target, re.I) or target.startswith("#"):
                continue
            resolved = (readme.parent / target.split("#")[0]).resolve()
            self.assertTrue(resolved.exists(), f"{readme.relative_to(ROOT)}: broken link {target}")


if __name__ == "__main__":
    unittest.main()
