# 02 — Folder structure

## 1. The tree

```
members/
├── src/                        application code — never served by the web
│   ├── Auth/                   token storage, refresh, the role check
│   ├── Http/                   routing table, request/response objects
│   ├── Repository/             the ONLY layer that talks to the API
│   ├── Service/                business rules
│   ├── Support/                bootstrap.php, HttpException.php
│   └── View/                   rendering prepared data
├── templates/
│   ├── layouts/                page shell(s)
│   └── pages/                  one template per page
├── assets/
│   ├── css/
│   ├── js/
│   └── favicon/
└── docs/                       this handbook
```

Entry files (`index.php`, and one file per page once routing exists) go at the
**top of `members/`**, not in a `public/` subfolder — see §5.

## 2. What exists today

Only three files, and two of them exist to carry a convention:

| File | Why it is here before any feature |
|---|---|
| `src/Support/bootstrap.php` | The hand-written autoload map. No Composer on the host, so classes are listed by hand; that rule has to be written down before the first class exists or it gets skipped. |
| `src/Support/HttpException.php` | The one error type that crosses a layer boundary. Deliberately distinct from internal failures: this one is safe to show a person. |
| `docs/HANDBOOK.md` + this folder | So the next chat does not have to rediscover the integration contract. |

Everything else is a `.gitkeep`. That is intentional: creating a class is a
deliberate act, and an empty file communicates nothing that the folder name and
this document do not already say.

## 3. Layering

One direction only:

```
Http  →  Service  →  Repository  →  API
```

| Layer | Owns | Must not |
|---|---|---|
| `Http` | Parsing the request, the route table, sending a response | contain an API URL, a token rule, or a business decision |
| `Service` | Rules: which child a parent may open, how attendance rate is presented | call the API directly; read `$_GET`/`$_POST` |
| `Repository` | API URLs, payload shapes, error-envelope translation, token header attachment | make a decision |
| `View` / `templates` | Turning an array into HTML | reach for the session, the repository or the API |
| `Auth` | Token storage, refresh scheduling, the "is this role allowed in this app" check | contain markup |

Why the strictness: the only failure mode that matters here is a page rendering
data the API did not authorise. If `templates/` can only see an array that
`Service` prepared, that failure becomes a visible mistake in a diff instead of
a query hidden in a view.

## 4. Where the API contract lives

Nowhere yet — that is deliberate. When it is written, it goes in
`Repository/`, one class per API resource, and it is the **only** place that
contains strings like `/api/v1/members`. Rationale: the platform's API is a
frozen contract; when it changes (it does — the platform adds fields additively
per release), exactly one directory should need editing.

## 5. Why entry files are at the top of the folder

Two reasons, and they agree:

1. **Deployment.** The app's own folder is its document root on its subdomain,
   so `members/index.php` is `https://<subdomain>/` with no rewriting. A
   `public/` subfolder would require the host's document root to be pointed one
   level deeper, which is an extra deployment dependency for no benefit.
2. **Nothing to conflict with.** This app does not use the platform's clean-URL
   registry (`admin/backend/services/BrowserPageRoutes.php`), which is what
   forces that repository's pages into directory-matching layouts. With no
   registry, the constraint disappears.

## 6. Folder names are fixed; new folders are not forbidden

The six `src/` folders and three `templates`/`assets` folders map to
responsibilities, not to features. A new feature adds a **class**, not a folder.
If a genuinely new responsibility appears (e.g. `src/Api/` for a generated
client, or `src/Middleware/`), add it and record it here.

Do not add: `vendor/` (no Composer), `node_modules/` (no build step), `tests/`
at the top level (tests live next to what they test, see
[04 §6](04-CONVENTIONS.md#6-testing)).
