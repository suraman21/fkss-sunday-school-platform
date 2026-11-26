# Members portal (`members/`)

The web application for **members and parents** — the student-facing side of the
platform. This document describes the folder structure and the rules that keep
it safe. It deliberately describes **structure only**: there is no database
access, no session creation and no password handling here yet.

For the finished prototype this app is based on (13 pages, theme CSS, quiz
engine), see [`docs/prototypes/member-app-2026-11-26/`](../docs/prototypes/member-app-2026-11-26/ARCHIVE.md)
and its `ARCHIVE.md`, which records what may be carried forward and what must
not.

---

## 1. Layout

```
members/                     web entry directory — the only part browsers reach
├── index.php                /members           front controller + home page
├── home.php                 /members/home      home page under its explicit name
├── login.php                /members/login     sign-in (form shell only)
├── .htaccess                Options + DirectoryIndex only; no rewrites (§5)
├── src/                     application code — NEVER served (denied in root .htaccess)
│   ├── Auth/
│   │   ├── Session.php      read-only snapshot of the platform session
│   │   └── RoleGate.php     the only authorisation decision in this app
│   ├── Http/
│   │   ├── Route.php        value object: one page + the roles allowed on it
│   │   ├── Router.php       the page table (finite, no request-derived paths)
│   │   └── Response.php     sends HTML with the headers every page needs
│   ├── Repository/          database access — empty until a feature needs it
│   ├── Service/             business rules — empty until a feature needs it
│   ├── Support/
│   │   ├── bootstrap.php    the explicit autoload map (no Composer)
│   │   ├── Bootstrap.php    runs one page: gate, render, error handling
│   │   └── HttpException.php an error that is safe to show a person
│   └── View/
│       └── Template.php     the only way a page becomes HTML (page → layout)
├── templates/               presentation — NEVER served
│   ├── layouts/base.php     the <html> shell
│   └── pages/               home, sign-in, error
└── assets/                  served
    ├── css/base.css
    ├── js/
    └── favicon/
```

Why the entry files sit at the top of `members/` and not in a `public/`
subfolder: see §5, constraint 1. It is not a style preference.

---

## 2. Blockers — product decisions, not code

Nothing in this section can be resolved by writing code carefully. Each needs a
decision from the product owner, and each is the reason a page is a placeholder.

### 2.1 There is no way to sign in yet

The only path that sets `users.is_active = 1` for a member or parent account is
the ACM device ceremony — `acm/src/Device/DeviceService.php::completeFull`,
option A: in-app keystore plus DPoP. Until that ceremony completes, the account
exists but cannot authenticate.

So a browser login form has nothing to succeed against. Either:

- the ceremony is implemented for the browser (WebCrypto + DPoP), or
- a different activation path is approved for browser users.

`members/login.php` therefore renders the form **inert**: the fields and the
submit button are `disabled`. Nobody can type a password into a page that would
refuse it for a reason it cannot explain.

Related: `MEMBER_PARENT_ACCOUNT_ANALYSIS.md` §8.3.

### 2.2 There is no member-side authorisation model

`member` appears in no allow-list anywhere in the platform. What a member may
read about themselves — which fields of their own record, which lessons, whose
attendance — is undefined. `parent` is defined only as "may see a linked
member", in `api/v1/core/acl.php::apiParentCanSeeMember()`.

`RoleGate` answers "may this role open this page". It cannot answer "may this
person see this row". That second question needs a written rule before the
first real page is built.

Related: `MEMBER_PARENT_ACCOUNT_ANALYSIS.md` §9.2/§9.3.

---

## 3. Layering

One direction only:

```
entry file (index.php)
   → Support\Bootstrap::run(slug)
      → Http\Router::route()          which page is this?
      → Auth\RoleGate::assertAllowed() may this role open it?
      → View\Template::page()          render it
         → templates/layouts + pages
   → Http\Response                   send it
```

Rules that follow from that, and are worth enforcing in review:

| Rule | Why |
|---|---|
| `templates/` receives **prepared data only** | A template that cannot reach `$_SESSION` or a service cannot leak one. `Template::render()` passes `$e` and `$data`, nothing else. |
| A page file draws the page **it was written for** | The slug is a constant in the entry file. No `$_GET`, no `$_POST`, no `SCRIPT_NAME` parsing selects the page, so no request input can choose a template. |
| Exactly one place reaches the platform | `src/Support/bootstrap.php`. When `config.php` is wired in, it is wired there and nowhere else. |
| `admin/access_control.php` is **not** loaded | Its `ROLE_MAP` contains neither `member` nor `parent`, so it refuses exactly the people this app serves. |
| No JSON API here | The machine-readable surface for members belongs in `/api/v1`, where the token layer and the ACL helpers already exist and are tested. |

`src/Repository/` and `src/Service/` are empty on purpose. They are named now so
that the first data-backed feature has an obvious, agreed place to go instead of
landing in a page file.

---

## 4. How to add a page

Adding one page touches four files and nothing else:

1. **`members/<name>.php`** — the entry file. Copy `home.php`, change the slug
   in `Bootstrap::create()->run('/<name>')`.
2. **`members/src/Http/Router.php`** — one row in `PAGES`:
   `'/<name>' => ['<view>', '<Title>', self::MEMBER_ROLES, true]`.
3. **`members/templates/pages/<view>.php`** — the markup. No queries.
4. **`admin/backend/services/BrowserPageRoutes.php`** — one row in `PAGES`:
   `'/<name>' => '/<name>.php'`. See §5.

Then update `tests/security/test_members_portal.py` if the new page changes what
it pins.

Do **not** add a nested page (`/members/lessons/notes`). See §5, constraint 2.

---

## 5. Three constraints that shape this folder

All three come from code that already exists and is already tested. They are
listed here because each one silently rejects the "obvious" layout.

### 1. The handler's parent directory must equal the clean URL's parent directory

`BrowserPageRoutes` preserves containing directories so that relative browser and
PHP targets resolve identically for the clean URL and the legacy script. The
rule is asserted twice — `tests/fixtures/browser_page_routes.fixture`
(`same(dirname($legacy), dirname($clean))`) and
`tests/security/test_browser_page_routes.py`.

Consequences:

- `/members/login` can only be served by a file **directly in `members/`**.
  A `members/public/login.php` would violate the rule, so there is no `public/`
  subfolder. This is the reason the entry files sit at the top of the folder.
- `/members` is not in the registry. It is the `members/` directory itself, which
  the web server answers with its `DirectoryIndex`. Registering it would mean
  mapping a URL to `/members/index.php`, whose parent is `/members`, not `/`.

### 2. A clean URL is one segment below its base

Same rule, read the other way: `/members/<one-segment>` is the only shape the
registry can express. Deeper pages need either their own one-segment name
(`/members/lessons`, `/members/progress`) or a change to the registry rule —
which would be a repository-wide change, not a portal change.

### 3. No `RewriteEngine` inside `members/.htaccess`

A per-directory `RewriteEngine On` without `RewriteOptions Inherit` drops every
parent rule — including the root block on `.md`, `.sql`, `.sh`, `.py`, `.lock`
and `.yaml`. `tests/security/test_structure_boundaries.py` fails on that pattern
by name.

So: **all routing for `/members/*` lives in the root `.htaccess`**, and
`members/.htaccess` carries `Options` only. The root `.htaccess` also denies
`members/src/` and `members/templates/`, which is the right place for it because
that block is already covered by tests.

Root `.htaccess` rules that involve this app:

- the dispatch condition and rule now accept `members` and `members/<slug>`;
- `^members/(src|templates)(/|$)` is denied before any of it.

---

## 6. Serving it

There are two ways a request reaches a page, and they must behave the same:

| URL | Path | Entry file |
|---|---|---|
| `/members` | DirectoryIndex of `members/` (`members/.htaccess` names it) | `members/index.php` |
| `/members/home` | root `.htaccess` → `browser_page.php` → registry | `members/home.php` |
| `/members/login` | root `.htaccess` → `browser_page.php` → registry | `members/login.php` |

Both entry styles call the same `Bootstrap`, so a page cannot behave differently
because of how it was reached. Direct requests to a page file
(`/members/login.php`) are harmless — a page file has no request-selected state —
but they are not the supported URL and nothing links to them.

Deployment: the repository root is the web root and files are uploaded over SFTP
(RFC 0001 §2). `members/` is an ordinary folder in that tree; no server
configuration change is required.

---

## 7. Verification

Two things are checked, and they check different things:

| Check | File | What it proves |
|---|---|---|
| Structure, statically | `tests/security/test_members_portal.py` | The route table is one segment deep, every page has an entry file and a template, the gate is an allow-list, entry files read no request input, the folder has no rewrite rules and the root denies `src/` and `templates/`. Runs everywhere. |
| Behaviour, for real | `tests/fixtures/members_portal.fixture` | The portal actually runs: each role's outcome, the escaped output, `/members` and `/members/home` drawing the same page, an unknown slug becoming a page and not a stack trace. Run by the Python test when `php` is on the path (as it is in CI), skipped locally when it is not. |

The fixture is a bare PHP file rather than a PHPUnit test because the
repository has no test framework for PHP and no Composer to add one. It prints
a single JSON line and exits non-zero on failure, in the same style as
`tests/fixtures/browser_page_routes.fixture`.

Run both:

```bash
php tests/fixtures/members_portal.fixture .      # 39 behavioural checks
python3 -m pytest tests/security/test_members_portal.py -q
```

## 8. What is deliberately absent

| Absent | Why |
|---|---|
| Database access | No feature needs it yet. `config.php` is not wired in yet either (see `src/Support/bootstrap.php`). |
| Session creation / login | §2.1 |
| CSRF tokens | Needed the moment a form posts. There is no posting form yet — the sign-in form is inert. |
| A JS framework, a build step | Nothing here needs one, and the host has no Node toolchain. |
| Composer | Not available on the host (ADR-0001). `src/Support/bootstrap.php` lists classes by hand. |
| The prototype's theme CSS | It is in the archive. Adopting it is a design decision with its own review, not part of the structure. |
