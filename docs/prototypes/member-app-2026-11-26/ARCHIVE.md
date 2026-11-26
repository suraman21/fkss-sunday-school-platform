# Member app prototype — archive

Frozen copy of the standalone prototype supplied by an external developer, kept
so its design work and content are preserved after the real members web app
starts being built in `/members` (`members/`).

| | |
|---|---|
| **Source repository** | `https://github.com/suraman21/ssms-member-app.git` |
| **Commit** | `61d50b44574e67803ba489db8cd346e3a4024c29` ("inital base", 2026-11-26) |
| **Archived on** | 2026-11-26 |
| **Files** | 57 (including a 697 KB demo past-paper PDF and two ~127 KB lesson-text files) |
| **Status** | **Reference only. Not deployed, not executed, not maintained.** |

## This is not part of the running application

Nothing in this folder is loaded by the site. It sits under `docs/`, which the
root `.htaccess` blocks from the web (`RewriteRule ^(sql|docs|tests|scripts|tools|vendor|Mobile|\.github)(/|$) - [F,L]`),
and which `tests/security/structure_paths.py` excludes from first-party code
scans.

**The PHP in here is still linted by CI.** The `php-lint` and `php-lint-85`
jobs run `git ls-files '*.php'` and exclude only `vendor/` and
`id_cards/libs/`. That is deliberate: archived code that no longer compiles is
not evidence of anything. If a future PHP version rejects this file set, the
honest fix is to record that in this README — not to widen the lint exclusions.

## What the prototype is

A **complete student-facing front end** and a **deliberately basic backend**,
built against a separate standalone database (`felege_kidusan_test`) rather
than the production schema. Its own handover note
(`backend/../docs/HANDOVER_FOR_DEVELOPER.md`) says plainly that nothing in it
was ever run against a real PHP/MySQL server by its author.

Pages: Home, Lessons, Lesson reader, Tasks, Quiz Me, Exam Prep, Progress,
Login, Change password.
Content: Grade 10 lesson text, verses, a demo past paper.

## Why it was archived rather than adopted

It solves the same problem with a different foundation. Its constraints are not
the ours:

- **Its own user tables.** `staff`, `staff_scopes` and student accounts from
  `database/migrations/001`–`003` — not `users` / `members` / `acm_person_accounts`.
- **Its own session auth.** `backend/lib/security.php::fk_gate()` reads
  `$_SESSION`, whereas every `/api/v1` route in this repository authenticates
  with bearer tokens via `apiRequireAuth()`.
- **Table-name collisions.** Migrations 002 and 003 create `attendance` and
  `notifications`, which already exist here with different columns and
  different meanings. Applying them to this database would corrupt live data.
- **No CSRF token**, by its own admission.
- Its own `.htaccess`-free `router.php` and a second, parallel API surface
  (`/api/learning/*`, `/api/quiz/*`) that would duplicate `/api/v1`.

## What is worth carrying forward

The design, not the plumbing:

- **`frontend/css/fk-theme.css`** (56 KB) and `frontend/style.css` (32 KB) — the
  visual language: palette, typography, component shapes.
- **`frontend/js/fk-ui.js`** (35 KB) — shared header, popups, assistant window.
- **Page structure** — what a student app actually shows, in what order.
- **`backend/data/verses.json`**, `lessons.json`, `lesson_text_grade10.json` —
  real content.
- **The unlock rules** in `backend/api/learning.php`: unit 1 open, first two
  lessons of an open unit open, later lessons need a teacher unlock, unit N+1
  requires every lesson of unit N completed *and* the unit quiz passed *and* a
  manual unlock. That is a considered product rule set worth re-implementing
  against our own schema.
- **`docs/TEST_CHECKLIST.md`** — a usable acceptance list.

## Do not

- Do not copy `backend/` into the application. In particular never run
  `database/migrations/*.sql` against this repository's database.
- Do not "fix" files here. If a rule from this prototype is adopted, implement
  it in `members/` and leave the original untouched as the record.
- Do not wire this folder into `BrowserPageRoutes` or any `.htaccess`.
