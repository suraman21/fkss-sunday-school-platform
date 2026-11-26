# 05 — Open decisions and unknowns

Each entry states what is blocked, what is known, and who must decide. Nothing
here should be resolved by a developer guessing.

---

## 1. What happened to the prototype features

**Status: needs a product decision. Not technical.**

The prototype has lessons, units, quiz-me, exam prep, past papers, progress and
weak topics. **The platform has none of these**: no lessons tables, no quiz
engine, no materials storage, no progress model. Its API has no route for any of
them ([03 §7](03-API-INTEGRATION.md#7-what-the-prototype-expected-vs-what-exists)).

So "scaffold the prototype" can mean two very different things:

| Option | What it needs | Consequence |
|---|---|---|
| **(a) Scaffold only what the API supports** — sign-in, activation, profile, children, child record, attendance | Nothing new on the platform | Buildable today. Smaller than the prototype's page set |
| **(b) Scaffold the prototype's full page set with stubs** | A decision that lessons/quizzes will be built, and a data model for them | Looks complete, no data, and risks cementing a schema nobody approved |

Recommendation: **(a)**, then add each prototype page when its data has an owner.
Reason: the prototype's backend was written against tables that collide with the
platform's (`attendance`, `notifications`) and was never run against a real
database. Its pages are a design reference, not a specification.

---

## 2. No member-side authorisation model

**Status: blocked on a platform decision. Blocks every member (not parent) screen.**

Verified: `member` appears in **no allow-list** anywhere in the API. The only
places `parent` is named are `apiParentGuardianId()`,
`apiParentCanSeeMember()`, `apiParentChildren()`, the `/users/me/children` route,
and two clauses in `members.php`.

What this means concretely:

| Screen | Parent | Member |
|---|---|---|
| Own profile, password, photo | ✅ `/users/me*` | ✅ `/users/me*` |
| Linked children | ✅ `/users/me/children` | n/a |
| A child's record | ✅ verified links only | ❌ `403` for every id, including its own |
| A child's attendance | ✅ | ❌ |
| Lessons, grades, timetable | ❌ (staff routes) | ❌ |

A member is a student with an activated account that can edit its own profile and
nothing else. Closing this needs the platform to decide what a member may read
about **itself** — most plausibly a `member` self-path in
`members.php` (`/members/me`, or allowing `apiParentCanSeeMember`'s sibling
`apiMemberCanSeeSelf`), which is a platform change with its own review.

**Do not** work around it in this app. A client-side check is not a control, and
calling it one is how the audit's §9.1 leaks happened.

---

## 3. Activation still requires an office-issued code

**Status: understood, not blocked. Affects the UI, not the architecture.**

The ceremony is browser-capable ([03 §6](03-API-INTEGRATION.md#6-the-browser-crypto-problem-you-will-hit-this-first)),
but it starts with `selector` + `secret` from a code the office hands out, and
the redemption may additionally be gated on the office network
(`ActivationException` has `network_denied`). So "sign up from home" is not a
flow that exists.

Consequence: this app's sign-in page needs a "I have an activation code" path and
a clear message for `403 activation_pending`
([03 §3.1](03-API-INTEGRATION.md#31-login)). It does **not** need a public
registration form.

Unknown (not verified): whether `network_denied` is enforced in production. The
service reads it from seeds; the seed values in the production database were not
available here.

---

## 4. Token storage in the browser

**Status: needs a decision before the first authenticated page.**

| Option | Requires | XSS | CSRF |
|---|---|---|---|
| `localStorage` + bearer header | nothing | token readable by any injected script | none |
| `HttpOnly` cookie set by this app's server | the app to proxy API calls; a CSRF token scheme | token unreadable | must implement |

Note the device private key has the same question and a stronger answer: it
belongs in **IndexedDB as a non-exportable or extractable-with-warning
CryptoKey**, never in `localStorage` as JWK text.

---

## 5. CORS for the members subdomain

**Status: blocked on a platform change, or on this app proxying.**

The platform's `handleCors()` allows `SITE_URL`, `http://<domain>`,
`https://www.<domain>`. The members subdomain is not among them
([01 §6](01-SYSTEM-CONTEXT.md#6-cross-origin-consequences-you-must-plan-for)).

Two fixes, both real:

1. **Add the subdomain to `$allowedOrigins`** in `api/v1/core/middleware.php`.
   One line, but it rides the platform's release train and widens what the API
   accepts from a browser.
2. **Proxy through this app** (`/api/*` on the members host → platform). No
   platform change; the app must forward `Authorization` and `DPOP` verbatim,
   must not cache, and adds a hop.

**Unverified:** whether production actually serves the API on the same host as
the site or on a separate API hostname. `SITE_DOMAIN` comes from the deployment's
private env file, which was not available. Confirm before choosing.

---

## 6. Deployment target

**Status: unknown, needs the owner.**

Not yet confirmed: the subdomain name, whether the host supports
`open_basedir`-style isolation, whether HTTPS is provisioned there, and whether
the app's own `.htaccess` may be used (Apache) or the host is nginx.

This matters for exactly one thing so far: whether entry files at the top of the
folder are the document root (§02-5) or need a `public/` split after all.

---

## 7. Fields the app may show

**Status: mostly answered by the API, one gap.**

`apiMemberSafeRow()` defines what a parent receives for a child
([03 §4.4](03-API-INTEGRATION.md#44-the-safe-row--know-exactly-what-you-receive)).
That is the answer for parents. For a **member looking at itself** the answer
does not exist, because the endpoint does not exist (§2 above).

Unknown but relevant: whether the office wants a member to see its own guardian
contact details (the "safe row" excludes them by design). If yes, that is part of
the platform change in §2 — not something to fetch from elsewhere.

---

## 8. Amharic UI

**Status: needs native review, not code.**

The prototype's UI is bilingual. The platform's data carries Amharic fields
(`full_name_am`, `class_name` plus `class_name_en`). Two rules that follow:

- Never machine-translate a label without review; Sunday-school terminology
  (መዝሙር, ክፍል, etc.) has established usage in this school.
- Store both values where the API sends both; do not strip `*_am` fields.

---

## 9. Summary: what a developer can start today

| Work | Blocked by |
|---|---|
| Move the folder to its own repository, add CI | nothing |
| Scaffold the shell: layout, CSS, route table, error page | nothing |
| `Repository` for `/ping`, `/auth/*`, `/users/me*` | nothing |
| Sign-in page (against real API) | §5 CORS, §4 token storage |
| Activation wizard (code → password → device) | §5 (browser calls) |
| Parent children + child attendance screens | §5 (browser calls) |
| Any member screen beyond its own profile | §2 |
| Lessons / quiz / progress screens | §1 |
