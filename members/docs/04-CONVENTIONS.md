# 04 — Conventions

Follow these and the app will look like the platform it talks to. Deviate and be
explicit — several of these are load-bearing, not taste.

## 1. Naming

| Kind | Convention | Example |
|---|---|---|
| Class file | `PascalCase.php`, one class, name matches the file | `src/Http/Router.php` |
| Namespace | `MembersPortal\` + folder path | `MembersPortal\Repository\MemberRepository` |
| `src/` folder | `PascalCase` | `src/Repository/` |
| Template | `kebab-case.php` | `templates/pages/sign-in.php` |
| Page entry file | `lowercase.php` | `index.php`, `children.php` |
| Asset | `kebab-case` | `assets/css/base.css` |
| Documentation | `UPPER_SNAKE_CASE.md` at the top, `NN-Topic.md` in `docs/` | `HANDBOOK.md` |

The namespace is **`MembersPortal`**, not `Members`. Reason: `Members` collides
conceptually with `member.php` and with the API's own member routes, and a
reader would assume it belongs to the platform.

## 2. No Composer

There is no Composer on the deployment host (the platform's ADR-0001), and this
app inherits that. Consequences:

- Classes are listed by hand in `src/Support/bootstrap.php`, **in dependency
  order**. Adding a class means adding a line. There is no autoloader to save
  you and no `composer dump-autoload`.
- No third-party libraries unless they are vendored as plain PHP files with a
  licence note. In practice: write the 30 lines yourself.
- Paths in `bootstrap.php` are written `__DIR__ . '/…'`, never built from a
  constant, so a static include check can resolve them.

## 3. Errors

- Throw `Support\HttpException` for anything a person should see. Its message is
  public by construction.
- Anything else is an internal failure: catch at the top level, log it, answer
  with a neutral message. Never echo an exception message, a path, a query or a
  stack trace — the platform learned this the hard way (`apiSendJson()`
  deliberately replaces PHP errors with a stable envelope).
- `Repository` translates API failures into `HttpException`; no other layer
  should know the API's error codes. Keep the machine `code` on the exception so
  a page can branch (`activation_pending` deserves a different screen from
  "wrong password").

## 4. Security defaults, not options

| Rule | Why it is not negotiable |
|---|---|
| Escape on output, always | `htmlspecialchars($v, ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')` |
| Never put a token in a URL | the platform rejects `?token=` for this exact reason; URLs land in logs, `Referer` and history |
| Never send a member id the user can edit without the API deciding | ownership is the API's call ([03 §4](03-API-INTEGRATION.md#4-who-may-see-what)) |
| `Cache-Control: no-store` on any page with personal data | a shared phone or a proxy must not keep it |
| `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` | matches the API's own headers |
| Send a CSRF token on every state-changing request **that uses a cookie** | irrelevant with bearer tokens in `localStorage`, mandatory with `HttpOnly` cookies |
| No third-party JS | an XSS in a dependency reads the token; the page is small enough to need none |

## 5. Configuration

One file, outside the web root, loaded once by `bootstrap.php`. Never committed.

```
MEMBERS_API_BASE=https://<platform-host>/api/v1
MEMBERS_ENV=production            # or development
```

The platform does exactly this (`.fkss_env.php` outside `public_html`). Until the
file is wired, `bootstrap.php` must not guess a base URL — a wrong default that
silently points at production is worse than a hard failure.

## 6. Testing

No PHPUnit and no Composer, so no framework. Use the platform's pattern, which
already works in its CI:

- A test is a plain `.php` script that exits non-zero on failure and prints one
  JSON line (`{"status":"PASS","checks":N}`). Reference:
  `tests/fixtures/browser_page_routes.fixture` and
  `tests/fixtures/members_portal.fixture` in the platform repository — the
  latter was written for this app's earlier skeleton.
- Put them next to what they test once there is something to test:
  `src/Repository/ApiClient.test.php`, or a top-level `tests/` when there are
  enough to organise.
- What to test, in order of value: the envelope parser (including malformed and
  `status`-missing responses), the single-flight refresh, the DER conversion
  ([03 §6.3](03-API-INTEGRATION.md#63-the-conversion-you-must-write)), and the
  route table's refusal of unknown paths.
- Lint every PHP file in CI with `php -l` on **8.4 and 8.5**, with
  `-d error_reporting=-1`, failing on `Deprecated:` — that is what the platform
  does, and production has run both versions.

## 7. Git and review

One repository for this app, its own history, its own release cadence. Nothing
in this app's build should require a platform release, and nothing in the
platform should require this app. That is the point of the split.

Commit messages: state the *why* when a choice was not obvious, and record what
was verified versus assumed. Both habits exist in the platform's history for a
reason — several of its commits exist solely to correct an earlier claim.

## 8. Documentation

`docs/` holds this handbook. Keep it true: when a decision in
[05](05-OPEN-DECISIONS.md) is settled, edit that file rather than adding a new
one. A stale handbook is worse than none, because it will be believed.
