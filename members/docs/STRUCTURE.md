# Members App — Structure & Foundation

**Status:** foundation built, feature pages intentionally not yet implemented.
**Location:** `members/`
**Design source:** `docs/prototypes/member-app-2026-11-26/frontend/` (frozen, read-only)

---

## 1. Why this folder exists, and why it is not a copy of the prototype

The external developer's prototype was archived rather than adopted. `ARCHIVE.md`
records the reasons, and the most important one governs every decision here:

> **Do not build a second API surface.**

The prototype shipped its own user tables (`staff`, `staff_scopes`), its own
session auth (`$_SESSION`), and its own endpoints (`/api/learning/*`,
`/api/quiz/*`). Adopting that would have meant a second authentication system
next to the one every other route already uses.

So the split is:

| Layer | Owner | What this app does |
|---|---|---|
| Identity, accounts, sessions | existing `/api/v1` + ACM | **consumes** — never re-implements |
| Member & family records | HR (via `members`, `guardians`) | **reads** — never copies |
| Learning path, progress, quizzes | **this app** (`members_*` tables) | new, because it does not exist |
| Visual design | the prototype's `fk-theme.css` | **cloned** — palette, shapes, typography |

---

## 2. Directory layout

```
members/
├── public/                     ← THE ONLY WEB-REACHABLE FOLDER
│   ├── index.php               front controller (single entry point)
│   └── assets/
│       ├── css/theme.css       design system cloned from the prototype
│       └── js/
│           ├── api.js          the ONLY place fetch() is called
│           └── app.js          page behaviour, loaded with defer
│
├── app/                        application code (denied by .htaccess)
│   ├── Core/                   Config + exception hierarchy
│   │   └── Exception/          AppException → 404/422/401/403 subclasses
│   ├── Http/
│   │   ├── Request.php         the ONLY reader of $_GET/$_POST/$_SERVER
│   │   ├── Response.php        immutable response, explicit cache policy
│   │   ├── Router.php          explicit route table, no reflection
│   │   ├── Middleware/         SecurityHeaders etc.
│   │   └── Support/
│   │       ├── View.php        template renderer (path-traversal-proof)
│   │       └── escapes.php     e() — global namespace, see §6
│   ├── Domain/                 (empty — business rules land here)
│   │   ├── Identity/  Learning/  Progress/  Family/  Notifications/
│   └── Infrastructure/
│       ├── Database/
│       │   ├── Connection.php  reuses the site's PDO; no second credential store
│       │   └── Migrator.php    ledger + drift detection, CLI only
│       └── Security/
│           ├── Csrf.php        the token the prototype admitted was missing
│           └── RateLimiter.php cheap second guard on the app's own forms
│
├── resources/views/            templates (outside the web root, on purpose)
│   ├── layouts/base.php        header, bottom nav, language toggle
│   ├── partials/placeholder.php
│   └── pages/                  welcome, login, home, lessons, …
│
├── routes/web.php              THE ATTACK SURFACE, in one readable file
├── config/app.php              policy only — no secrets
├── sql/
│   ├── 000_members_migration_ledger.sql   bookkeeping (apply once, by hand)
│   └── 001_members_learning_schema.sql    learning path, progress, quizzes
├── tools/migrate.php           CLI, refuses web requests
├── tests/{Unit,Integration,Contract}/
├── autoload.php                static manifest — see §6
├── bootstrap.php               composition root
└── .htaccess                   denies the private tree, routes public/
```

---

## 3. Security decisions, and the reason for each

| Decision | Why it is this way |
|---|---|
| One entry point (`public/index.php`) | The attack surface is `routes/web.php`, which a reviewer can read in one go. Nothing is inferred from the URL. |
| Everything outside `public/` denied | `members/.htaccess` **repeats** the root denials. Per-directory rewrites are not inherited, so without this `/members/tests/…` would be served. |
| No JSON API of our own | The platform already has one. A second would duplicate auth, CSRF and rate limiting — and drift. |
| Bearer token in `sessionStorage` | A shared phone in a church hall must not stay signed in after the browser closes. It also removes cookies from the token path, so CSRF cannot carry it. |
| CSP with no `unsafe-inline` | Inline handlers are where XSS lives. With `script-src 'self'`, a template cannot smuggle a handler in, and injected markup cannot execute. |
| CSP as a constant, not config | It *was* in config; a test caught that a missing config load silently downgraded the policy to `default-src 'self'`. A security posture must not depend on boot order. |
| `connect-src 'self'` | A script that somehow ran still could not POST a member's data to another host. |
| `e()` in `View.php` → own file | PHP compiles namespace functions at require time. Any page calling `e()` before `View` was loaded died. One owner file, loaded first. |
| `e()` in the **global** namespace | Templates have no `namespace` declaration, so they resolve globally. A namespaced `e()` is invisible to them — every page would 500. |
| `EMULATE_PREPARES => false` | Real server-side prepares; emulated ones have historically been a charset-injection vector. |
| `ERRMODE_EXCEPTION` | A silent `false` becomes an empty list, and an empty list is indistinguishable from "this member has no lessons". |
| HTML is `no-store` | Every page is personalised; a shared proxy caching one dashboard for another is a breach. |
| Static manifest autoload | The repo forbids SPL autoloaders outside `vendor/` so the folder-safety tests can prove statically which file loads. `acm/autoload.php` set this precedent. |
| No DDL on any application path | `test_request_time_schema.py` rejects CREATE/ALTER outside `sql/`. The ledger moved from `Migrator::ensureLedger()` into `sql/000` because of it. |

---

## 4. Database: what is ours and what is not

**New (this app owns them):** `members_subjects`, `members_units`,
`members_lessons`, `members_lesson_progress`, `members_lesson_unlocks`,
`members_quizzes`, `members_quiz_questions`, `members_quiz_attempts`.

**Read-only, owned elsewhere:** `members`, `guardians`, `member_guardians`,
`users`, `acm_person_accounts`, `attendance`, `notifications`.

Two rules worth stating plainly:

1. **No parallel copy of a student.** Two tables describing one person is the
   exact fault the ACM boundary work removed. Identity is read from `members`.
2. **Keyed by `member_id`, never `user_id`.** A lesson belongs to a student, and
   a student may hold no login at all. Keying progress to a user would lose a
   child's work the moment their account was suspended or replaced.

`sql/001` is idempotent (`CREATE TABLE IF NOT EXISTS`) and contains no
`DROP`, `TRUNCATE` or `DELETE`.

---

## 5. Where it lives in production

**`https://yoursite.com/members/`** — the folder is the web root, exactly like
`/admin`. Document root stays the repository root; `members/.htaccess` denies the
private tree (`app/`, `resources/`, `config/`, `sql/`) and dispatches everything
else to `public/index.php`.

| URL | Page |
|---|---|
| `/members/` | Welcome |
| `/members/login` | Sign in |
| `/members/home` | Dashboard |
| `/members/lessons` | Lesson path |
| `/members/profile`, `/attendance`, `/grades` | The member's own record |

Every internal link and asset path is written `/members/...`, so that mount is
the supported one; serving the app from a document root instead would break them.

**The mount prefix is the fragile part.** Apache sets `SCRIPT_NAME` to the path
*after* an internal rewrite, so under this mount it reads
`/members/public/index.php` — not `/members/home`. The first implementation took
`dirname()` of that, got `/members/public`, matched no prefix, and 404'd every
page. `Request::stripMountPrefix()` now tries `/members/public`, `/members` and
`/index.php` forms, each only on a `/` boundary, so `/membership` is never
mistaken for `/members`. `members/tests/Unit/RequestPathTest.php` pins thirteen
shapes; eight of them fail against the old code.

---

## 6. Running it

```bash
# 1. Apply the ledger ONCE, by hand (phpMyAdmin). The runner refuses to
#    create it: a class that defines schema is schema in two places, and the
#    request-time-schema guard rejects DDL on application paths.
#      sql:  members/sql/000_members_migration_ledger.sql

# 2. Apply the schema (CLI only; refuses web requests)
php members/tools/migrate.php --status     # what is applied / pending
php members/tools/migrate.php --dry-run    # show, change nothing
php members/tools/migrate.php --execute    # apply

# 2. Serve locally (Apache in production uses members/.htaccess)
php -S 0.0.0.0:8080 -t . members/public/index.php
```

The migrator records every applied file in `members_schema_migrations` with a
checksum, so "is this box up to date?" is a query rather than a guess, and an
edited-after-apply migration is reported rather than silently re-run.

**The ledger is created from `sql/000`, not from PHP.** The first draft had
`Migrator::ensureLedger()` create it on demand. `test_request_time_schema.py`
failed the build, correctly: DDL on a request path means schema defined in two
places, and "run the migrator" would be the moment a database grows a table
nobody reviewed. The guard was right and the code changed.

---

## 7. Deliberate limitations (stated, not hidden)

1. **Feature pages are shells.** `/home`, `/lessons`, `/progress` render the
   real layout with an explicit "still being built" state. They are *not* filled
   with sample data — invented lessons are indistinguishable from a broken API,
   and would hide whether the contract is right.
2. **Two API resources now exist, both in `api/v1/`.** `/learning` (§9) and
   `/my` (§10). Neither lives in `members/`: the app consumes the platform's one
   API surface, it does not grow a second one.
3. **No runtime verification against MariaDB.** No PHP or MySQL was available
   while building this; correctness is established by parse checks and 41
   behavioural assertions run against an in-memory PHP runtime. The first real
   evidence is running it against the development database.
4. **`Domain/` is empty.** It is created so business rules have an obvious home
   when they arrive; an empty folder is honest, a premature one is not.

---

## 8. Feature status

Every unbuilt feature is an explicit **coming soon** page, driven by one
registry (`resources/views/partials/placeholder.php`) that records a status and
an OWNER per feature:

- `owner: api` — waits on an endpoint
- `owner: content` — waits on a human writing lesson text, quiz questions or
  past papers

That distinction is the difference between "blocked on me" and "blocked on the
school", and it is shown on the page rather than buried in a commit message.

| Feature | Status | Owner |
|---|---|---|
| Sign in / sign out | **live** | — |
| Lessons list (`/members/lessons`) | **live** | — |
| Lesson reader (`/members/lesson/{id}`) | **live** | — |
| Quiz me | coming soon | content |
| Tasks | coming soon | api |
| Progress | coming soon | api |
| Exam prep | coming soon | content |
| Assistant | coming soon | api |
| Announcements | coming soon | api |
| Change password | **not possible yet** | ACM decision |

### Why "change password" is not simply built

The existing password-change endpoint (`admin/api_settings.php`) authenticates
with a PHP session + CSRF and admits only staff roles that expose a dashboard. A
member holds a bearer token and no PHP session, so it answers 401. Wiring this
app to it would produce a 401 that looks like a bug. Adding a
token-authenticated password change belongs to ACM, which owns credential
lifecycle — so the page says to contact the office, which is true today.

## 9. The learning API

`GET  /api/v1/learning/path` — subjects → units → lessons, with per-lesson
`open`/`completed` and a summary for the progress ring.
`GET  /api/v1/learning/lessons/{id}` — one lesson, only if open to the caller.
`POST /api/v1/learning/lessons/{id}/complete` — idempotent.

**Self-only.** The member id is resolved from the token via `apiSelfMemberId()`
and never accepted from the request — the same rule `GET /members/me` follows,
because an id-taking route that admits the member role is an IDOR waiting for
the next editor to forget the ownership check.

**The server decides whether a lesson is open.** The `open` flag the list
returns is a convenience; both the reader and the complete handler re-check it.
A client that computed its own unlock state would be wrong the moment a teacher
granted an unlock.

### The unlock rules, and the one that surprised me

From `docs/prototypes/member-app-2026-11-26/backend/api/learning.php`:

1. Unit 1 is always open.
2. Inside an open unit the first 2 lessons are open.
3. Any later lesson needs a teacher unlock.
4. **A later unit needs a teacher unlock, full stop** — `learning.php:123` is
   `$u["open"] = $prevDone && $u["teacher_unlocked"]`. Finishing every lesson and
   passing the quiz does *not* open the next unit.

My first implementation let completed work open the next unit by itself, and a
test expectation I had written from prose rather than code agreed with it. Two
independent checks disagreed, I went to the prototype's source, and the source
won. The rule is now pinned by a test whose docstring quotes the line.

Also corrected: a unit with **no quiz** must count as quiz-satisfied
(`!quiz_required || ...` in the prototype). Requiring a quiz that does not exist
would freeze the course forever.

## 10. The self-service API — `/api/v1/my`

Everything a member needs to see about *themselves*, in one resource that takes
**no id at all**.

| method | path | returns |
|---|---|---|
| GET | `/my/profile` | the caller's own record, contact + address, role flags, department status |
| GET | `/my/attendance?days=90` | windowed history + counts and a percentage |
| GET | `/my/grades` | assessment records with a server-computed percent |

**Why a new resource rather than a relaxed `/members/{id}`.** That route answers
"may I see *this* member" and shapes its payload by the caller's role: staff get
phones and guardian contact, members and parents get a safe row with neither. A
member opening their own page through it therefore gets the *safe* row — their
own phone number is withheld as if it were somebody else's. Teaching that route
about selfhood would put an id-taking endpoint behind the member role, which is
the IDOR its own comment warns about. `/my` takes no id, so identity can only
come from the token.

**One routing detail that was a real bug.** The router splits a path into
`[resource, id, sub]`. `/learning/lessons/{id}` puts the verb in `sub`; `/my/profile`
puts it in the **id** slot and leaves `sub` null. Copying the `/learning` line
made every `/my` request fall through to 404 while still parsing cleanly. The
route now reads `parts[1]` and rejects a numeric segment rather than coercing it.

**The judgement calls, all pinned by tests:**

- Own phone and address **are** returned — a student who cannot check their
  contact details cannot report that the office has the wrong number.
- Guardian contact is **not**. A parent's phone number belongs to the parent.
- A department row is written only when somebody is **suspended**, so
  `departments_source: "default"` means "no row, therefore active on both" —
  never "inactive".
- Attendance `percent` is `null`, not `0`, with no records; `late` counts as
  attended; `holiday` is out of the denominator.
- A missing `academic_records` table is `409 GRADES_UNAVAILABLE`, not an empty
  list: "you have no grades" and "grades are not switched on" are different
  messages and only one is true.
- Parents are **not** served by `/my`. A parent asking about a child goes through
  `apiParentCanSeeMember` on a different route, with its own verified-link rule.

## 11. What comes next

| Step | Where | Why in this order |
|---|---|---|
| 1 | Quiz endpoints + attempt grading | the content exists in schema; needs a teacher to write questions |
| 2 | Progress page | reads what learning + quiz already record |
| 3 | Teacher unlock console | the rules assume a human can unlock; today nobody can |
| 4 | Tasks, exam prep | depend on content that does not exist yet |

Step 3 is the real gap: the unlock rules are implemented, but **there is no
screen for a teacher to grant an unlock**, so today unit 2 of every subject is
reachable only by direct database edit. That is the next thing that blocks real
use, not the quiz.
