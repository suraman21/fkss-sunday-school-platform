<!-- This is a reference copy of docs/00-START-HERE.md from the members app repo.
     Its relative links point at the other handbook pages, which exist in the
     app repo once structure-and-archive-prototype.patch is applied — not in
     this folder. Read HANDOFF.md for the links that work here. -->

> **Reference copy.** This page ships inside the members app repo at
> `docs/00-START-HERE.md`. Its links point at the other handbook pages
> (`HANDBOOK.md`, `01`–`06`), which exist *after* you apply the patch, not in
> this folder. The page that works from here is [`HANDOFF.md`](HANDOFF.md).

# 00 — Start here (new chat / new contributor)

This file exists so someone with **no conversation history** can start working
without asking a question first. Read this page, then open
[`HANDBOOK.md`](HANDBOOK.md).

---

## What this repository is

The **members (students) and parents web app** of the Felege Kidusan Sunday
School. It is a PHP application that talks to the Sunday-school platform's REST
API. It is **not** part of the platform and shares no code with it.

| | |
|---|---|
| Status | Structure only — no behaviour yet |
| Next task | Scaffold the frontend from the archived prototype |
| Talks to | The platform's `/api/v1` (HTTP only) |
| Deployed to | Its own subdomain (not yet chosen) |

## Get running locally

Nothing to build, no dependencies, no package manager. Clone and you have
everything:

```bash
git clone https://github.com/suraman21/ssms-member-app.git
cd ssms-member-app
```

There is no entry point yet (that is the next task), so there is nothing to run
today. Reading `docs/` is the correct first move.

## Read in this order

| # | File | Why |
|---|---|---|
| 1 | [`HANDBOOK.md`](HANDBOOK.md) | Index, plus three corrections to anything said earlier |
| 2 | [`01-SYSTEM-CONTEXT.md`](01-SYSTEM-CONTEXT.md) | What the platform is, who the users are |
| 3 | [`02-FOLDER-STRUCTURE.md`](02-FOLDER-STRUCTURE.md) | The tree and the layers |
| 4 | [`03-API-INTEGRATION.md`](03-API-INTEGRATION.md) | The full API contract — the long one |
| 5 | [`04-CONVENTIONS.md`](04-CONVENTIONS.md) | How code in here is written |
| 6 | [`05-OPEN-DECISIONS.md`](05-OPEN-DECISIONS.md) | **Unanswered questions — check before building** |
| 7 | [`06-FIRST-TASKS.md`](06-FIRST-TASKS.md) | The ordered task list |

## The six facts that change your decisions

1. **No platform files are ever `require`d.** No `config.php`, no
   `admin/backend/*`, no shared session. Everything arrives as JSON over HTTP.
   `src/Repository/` is the **only** layer allowed to make those calls.
2. **The platform has no member self-path.** `GET /members/{id}` is 403 for a
   member token — the `member` role is in no allow-list. Only `parent` works,
   via `apiParentChildren()`. This is the app's **headline blocker**; see
   [`03 §5`](03-API-INTEGRATION.md) and [`05`](05-OPEN-DECISIONS.md) before
   designing a member-facing screen.
3. **CORS blocks the subdomain today.** `handleCors()` on the platform allows
   only `SITE_URL`, `http://SITE_DOMAIN` and `https://www.SITE_DOMAIN`. The
   members subdomain must be added there, in the **platform** repository, before
   any browser call succeeds.
4. **Auth is `Bearer` only**, access token 900 s, refresh 90 d and **rotating**.
   Refresh is single-flight — a concurrent second refresh revokes the whole
   token family. See [`03 §3`](03-API-INTEGRATION.md).
5. **The prototype's `backend/` and `database/` are not adopted.** They are an
   older parallel API with a second `staff`/session model and migrations that
   collide with the platform's `sql/001–098`. Only `frontend/` is meant for
   reuse.
6. **`frontend/js/session.js` must be replaced, not adapted.** It logs in
   against the prototype's own API, which no longer exists.

## The prototype

`docs/prototypes/member-app-2026-11-26/` holds the earlier app built by another
developer. It is **reference only** — read
[`ARCHIVE.md`](prototypes/member-app-2026-11-26/ARCHIVE.md) before opening
anything else in there. It is not wired up and never will be.

Its `frontend/` is the source for the scaffold. Useful as markup, CSS and
interaction patterns. Not useful as an integration model.

## Where the structure stops today

```
.
├── docs/           the handbook (you are in it)
├── src/
│   ├── Auth/       empty — tokens, refresh, role check
│   ├── Http/       empty — route table, request/response
│   ├── Repository/ empty — the only layer that calls the platform API
│   ├── Service/    empty — business rules
│   ├── Support/    bootstrap.php (autoload map) + HttpException.php
│   └── View/       empty — rendering prepared data
├── templates/      empty — {layouts,pages}
└── assets/         empty — {css,js,favicon}
```

Entry files (`index.php` and one per page) will go at the **repository root**,
not in a `public/` layer — the reasoning is in
[`02 §5`](02-FOLDER-STRUCTURE.md).

## Before writing code

1. Read [`05-OPEN-DECISIONS.md`](05-OPEN-DECISIONS.md). Several questions are
   unanswered and they change the design — most importantly the **deployment
   target** (subdomain, Apache or nginx, HTTPS) and the **member-role gap**.
2. Read [`06-FIRST-TASKS.md`](06-FIRST-TASKS.md) §2 onward. Task 1 (the move to
   this repository) is done.
3. Do not invent API endpoints. [`03`](03-API-INTEGRATION.md) lists what exists,
   read from the platform's source. Anything not in that table does not exist.

## A warning about the prototype's endpoint list

The prototype calls about 29 `/api/…` paths. They belong to **its own discarded
backend**. Lessons, quizzes, progress, tasks, past papers, verses and
permissions do not exist on the platform API. Do not scaffold a screen against
them.
