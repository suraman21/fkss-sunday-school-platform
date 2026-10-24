"""Documentation safety net: every relative Markdown link must work.

Folder clean-ups (RFC 0001) move documents. A moved document breaks the
links that point to it, and its own links to other files. This test checks
every tracked first-party .md file in CI:

1. Each relative link [text](path) or [ref]: path points to a file or
   folder that exists in the repository.
2. Each #section anchor on a link to a Markdown file matches a heading in
   that file (GitHub heading rules: lower case, spaces to hyphens,
   punctuation removed, underscores kept, duplicates get -1, -2 ...).
3. A sanity floor, so a broken parser cannot "pass" by matching nothing.

Links inside fenced code blocks, web links (https:, mailto:, ...) and
third-party folders (vendor and bundled libraries) are not checked.

Baseline at ebcc864: 0 broken first-party links.
"""
import re
import subprocess
import unicodedata
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

# Third-party documents we do not maintain.
SKIP_PREFIXES = (
    "vendor/",
    "admin/vendor/",
    "admin/backend/pdf/tcpdf/",
    "admin/id_cards/libs/",
)

FENCE_RX = re.compile(r"```.*?```", re.S)
INLINE_RX = re.compile(r"\[[^\]]*\]\(([^)\s]+)(?:\s+\"[^\"]*\")?\)")
REFDEF_RX = re.compile(r"^\s*\[[^\]]+\]:\s*(\S+)", re.M)
HEADING_RX = re.compile(r"^#{1,6}\s+(.*?)\s*#*\s*$", re.M)
HTML_ANCHOR_RX = re.compile(r'<a\s+(?:name|id)="([^"]+)"')
SCHEME_RX = re.compile(r"^[a-z][a-z0-9+.-]*:", re.I)


def tracked_markdown():
    out = subprocess.run(["git", "ls-files", "*.md"], cwd=ROOT,
                         capture_output=True, text=True, check=True).stdout.split()
    return [f for f in out if not f.startswith(SKIP_PREFIXES)]


def without_fences(text):
    # Keep line numbers stable: replace each fenced block by its newlines.
    return FENCE_RX.sub(lambda m: "\n" * m.group(0).count("\n"), text)


def github_slug(heading):
    text = re.sub(r"[`*~]|\[([^\]]*)\]\([^)]*\)", lambda m: m.group(1) or "", heading.strip())
    text = text.lower()
    kept = "".join(c for c in text
                   if c in " -_" or unicodedata.category(c)[0] in ("L", "M", "N"))
    return kept.replace(" ", "-")


_ANCHORS = {}


def anchors(path):
    if path not in _ANCHORS:
        text = without_fences(path.read_text(encoding="utf-8", errors="replace"))
        found, seen = set(), {}
        for m in HEADING_RX.finditer(text):
            slug = github_slug(m.group(1))
            n = seen.get(slug, 0)
            seen[slug] = n + 1
            found.add(slug if n == 0 else f"{slug}-{n}")
        found |= set(HTML_ANCHOR_RX.findall(text))
        _ANCHORS[path] = found
    return _ANCHORS[path]


def links(rel):
    """Yield (line, target) for every relative link in a Markdown file."""
    body = without_fences((ROOT / rel).read_text(encoding="utf-8", errors="replace"))
    for rx in (INLINE_RX, REFDEF_RX):
        for m in rx.finditer(body):
            target = m.group(1)
            if SCHEME_RX.match(target) or target.startswith("//"):
                continue
            yield body.count("\n", 0, m.start()) + 1, target


def broken_links(rel):
    src = ROOT / rel
    problems = []
    for line, target in links(rel):
        path, _, fragment = target.partition("#")
        dest = src if path == "" else (src.parent / path.split("?")[0]).resolve()
        if path and not dest.exists():
            problems.append(f"{rel}:{line}: {target} (no such file)")
        elif fragment and dest.is_file() and dest.suffix.lower() == ".md" \
                and fragment.lower() not in anchors(dest):
            problems.append(f"{rel}:{line}: {target} (no such section)")
    return problems


class DocsLinks(unittest.TestCase):
    def test_every_relative_markdown_link_resolves(self):
        files = tracked_markdown()
        total = sum(1 for f in files for _ in links(f))
        # Sanity floors: fail loudly if file discovery or the link parser
        # silently breaks. Set at roughly 80% of the real counts.
        self.assertGreater(len(files), 55, "too few Markdown files found")
        self.assertGreater(total, 280, "link parser matched too few links")
        problems = [p for f in files for p in broken_links(f)]
        self.assertEqual(problems, [], "Broken documentation links:\n  " + "\n  ".join(problems))

    def test_slug_rules_match_github(self):
        self.assertEqual(github_slug("4. Mobile application (`Mobile/wbws_flutter_app/`)"),
                         "4-mobile-application-mobilewbws_flutter_app")
        self.assertEqual(github_slug("2.2 Folder-structure safety checks"),
                         "22-folder-structure-safety-checks")


if __name__ == "__main__":
    unittest.main()
