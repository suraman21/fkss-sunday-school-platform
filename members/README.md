# Members web app — folder structure

The PHP folder structure for the members/parents web app. **Structure only.**
There is no behaviour here: no routing, no database, no session, no page, no
styling. Creating the files is the next job, and it starts when it is approved.

---

## 1. This app is separate, and that changes the structure

| | |
|---|---|
| **Repository** | Its own repository, not this one (the files will move there) |
| **Deployment** | Its own subdomain in production |
| **Integration** | Through the **API**, not shared files, folders or PHP includes |

That last row is the one that shapes everything below, because the obvious
layout for a PHP app in *this* repository is the wrong one here:

- **No clean-URL registry.** `admin/backend/services/BrowserPageRoutes.php`
  exists to give this repository's pages friendly URLs through
  `browser_page.php`. This app is not served by that dispatcher and must not
  be added to it.
- **No `.htaccess` additions** in the parent repository. This app's web server
  configuration belongs to its own subdomain.
- **No `require` reaching into the platform.** No `config.php`, no
  `admin/backend/*`, no `api/v1/*` route file. Every interaction with the
  platform is an HTTP call to `/api/v1` under the parent repository's rules.
- **Entry files at the top of the app folder**, not in a `public/` subfolder.
  When this app is deployed, its own folder is the document root, so the same
  layout works in both places with no path rewriting.

The only shared thing is the data contract: the JSON the API returns.

---

## 2. Layout

```
members/
├── src/                        application code — never served by the web
│   ├── Auth/                   who is using the app, and what they may do
│   ├── Http/                   request handling: routing, responses
│   ├── Repository/             data access — the only layer that calls the API
│   ├── Service/                business rules
│   ├── Support/                bootstrap.php, HttpException.php
│   └── View/                   turning prepared data into HTML
├── templates/
│   ├── layouts/                the page shell(s)
│   └── pages/                  one template per page
└── assets/
    ├── css/                    styles
    ├── js/                     scripts, if any
    └── favicon/
```

Nine folders, three files:

| File | Why it exists in a skeleton |
|---|---|
| `src/Support/bootstrap.php` | The hand-written autoload map. The host has no Composer (ADR-0001), so every class is listed by hand. Creating a class means adding a line; that rule has to be written down before the first class exists, or it gets skipped. |
| `src/Support/HttpException.php` | The one error type that crosses layer boundaries. A deliberate split from internal failures: this one is safe to show a person, everything else is not. Every layer will throw it, so it comes first. |
| `README.md` | This file. |

Everything else is a `.gitkeep`.

---

## 3. Layering

One direction, no exceptions:

```
Http  →  Service  →  Repository  →  API
```

| Folder | Holds | Must not |
|---|---|---|
| `src/Http/` | Request parsing, routing table, response output | contain a SQL string, an API URL, or a business rule |
| `src/Service/` | Business rules: what a member may see, how a grade is averaged | call the API directly, or touch `$_GET`/`$_POST` |
| `src/Repository/` | The only place that knows the API's URLs, payloads and error shapes | make a decision |
| `src/View/` | Rendering prepared data | reach for the session, the repository or the API |
| `src/Auth/` | Session/token handling and the role check | contain page markup |
| `src/Support/` | Wiring and shared types | contain features |

`src/View/` and `templates/` are split for the same reason: a template that
receives only an array of prepared values cannot quietly query something, so a
leak becomes visible in a diff instead of hidden in a view.

---

## 4. What integration through the API means in practice

The API is the platform's existing one (`/api/v1` in the parent repository),
not a second, parallel API built for this app. Concretely:

- **Authentication is the API's.** Token handling, refresh and revocation are
  its concern. This app stores a token and presents it; it does not check a
  password itself.
- **Authorization for a row is the API's.** Whether a parent may see a
  particular child is `apiParentCanSeeMember()`'s decision, already built and
  tested. This app may ask, and will be told no.
- **Errors come back as the API's envelope.** `src/Repository/` translates it;
  no other layer needs to know its shape.

Two open questions that belong to whoever owns the API, not to this structure:

1. **How a member signs in.** In the platform, a member or parent has
   `users.is_active = 0` until the ACM device ceremony completes
   (`acm/src/Device/DeviceService.php::completeFull`). A browser needs that
   ceremony in WebCrypto, or a different activation path.
2. **What a member may read about themselves.** `member` currently appears in
   no allow-list on the platform side; that list has to exist before the first
   real page can be built.

---

## 5. Naming

| Kind | Convention | Example |
|---|---|---|
| Class file | `PascalCase.php`, one class, name matches | `src/Http/Router.php` |
| Folder | `PascalCase` under `src/`, lowercase under `templates/` and `assets/` | `src/Repository/`, `templates/pages/` |
| Template | `kebab-case.php` | `templates/pages/sign-in.php` |
| Namespace | `MembersPortal\` + folder path | `MembersPortal\Http\Router` |

The namespace is `MembersPortal` rather than `Members` on purpose: `Members`
is too close to `member.php` and to the API's own member routes, and a
collision there would be read as belonging to the platform.

---

## 6. Adding a page (when that starts)

1. A route in `src/Http/Router.php`.
2. A service method in `src/Service/` for any rule it needs.
3. A repository method in `src/Repository/` if it needs data.
4. A template in `templates/pages/`.

No step reads a file path from the request, and no step reaches into the
parent repository.
