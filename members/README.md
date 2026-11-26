# Members web app

The web application for **members (students) and parents** of the Sunday school.

| | |
|---|---|
| **Status** | Folder structure only — no behaviour yet |
| **Repository** | Its own repository (this folder is the staging area) |
| **Deployed to** | Its own subdomain |
| **Talks to** | The platform's REST API at `/api/v1` — not its files, not its database |

## Start here

[docs/HANDBOOK.md](docs/HANDBOOK.md) — what this app is, how it connects to the
platform, the full API contract, the conventions, the open decisions, and the
first tasks in order.

Written so that a new contributor (or a new chat) can work on this app without
the conversation that produced it.

## Layout

```
members/
├── src/            application code — never served by the web
│   ├── Auth/       tokens, refresh, the portal's own role check
│   ├── Http/       route table, request/response
│   ├── Repository/ the ONLY layer that calls the platform API
│   ├── Service/    business rules
│   ├── Support/    bootstrap.php (autoload map), HttpException.php
│   └── View/       rendering prepared data
├── templates/{layouts,pages}
├── assets/{css,js,favicon}
└── docs/           the handbook
```

## The two facts that shape everything

1. **Integration is HTTP only.** No `require` of a platform file, no database
   connection, no shared PHP session. Everything about a member arrives as JSON
   from `/api/v1`.
2. **No Composer on the host.** Classes are listed by hand in
   `src/Support/bootstrap.php`, in dependency order.

## Before writing code

Read [docs/05-OPEN-DECISIONS.md](docs/05-OPEN-DECISIONS.md). Two items block
real screens: CORS for the members subdomain, and the absence of any
member-side authorisation in the API. Neither is a coding problem.
