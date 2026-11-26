# 01 — System context

## 1. The platform (what this app talks to)

A PHP 8.4/8.5 Sunday-school management system, deployed by SFTP upload to shared
hosting where **the repository root is the web root**. Source:
`suraman21/fkss-sunday-school-platform`.

| Piece | Where | Relevance here |
|---|---|---|
| Staff web console | `admin/`, `frontend/`, `monitor/` | Not used by this app |
| REST API | `api/v1/` | **The only integration point** |
| Database | MariaDB, `users`/`members`/`attendance`/… | **Not reachable** from this app |
| ACM (account/device) subsystem | `acm/` | Drives activation; reachable only through `/api/v1/acm/*` |
| Mobile app (Flutter) | `Mobile/wbws_flutter_app/` | Another API client; useful as a reference for token handling |

The API is versioned by path (`/api/v1`), lives behind
`api/v1/.htaccess` (rewrites everything to `index.php?_route=…`), and is
consumed today by the Flutter app. **The installed Android builds pin these
paths**, so the API contract is effectively frozen — an excellent property for
a second client, because it will not shift under you.

## 2. This app (what is being built)

A web application for **members** (students) and **parents** of the Sunday
school, delivered as its own repository on its own subdomain. It replaces a
developer prototype (`docs/prototypes/member-app-2026-11-26/` in the platform
repository) that was never adopted.

| Property | Value |
|---|---|
| Language / runtime | PHP 8.4 or 8.5 (match production; the platform's CI lints both) |
| Framework | **None.** No Composer, no Laravel, no Symfony — see [04 §2](04-CONVENTIONS.md#2-no-composer) |
| Data layer | HTTP calls to the platform's `/api/v1` |
| Session | JWT access token + rotating refresh token, both issued by the API |
| Rendering | Server-rendered PHP templates |

## 3. What this app may do

| Allowed | How |
|---|---|
| Read a member's own profile | `GET /api/v1/users/me` with a bearer token |
| Read a parent's linked children | `GET /api/v1/users/me/children` |
| Read one member record | `GET /api/v1/members/{id}` (the API decides if you may) |
| Read a member's attendance | `GET /api/v1/members/{id}/attendance?days=90` |
| Complete activation | `POST /api/v1/acm/activate/*`, `/reset/*`, `/devices/*` |
| Sign in / refresh / sign out | `POST /api/v1/auth/login`, `/refresh-token`, `/logout` |
| Manage own profile & password | `/users/me` (PATCH), `/users/change-password` |

## 4. What this app may not do

| Not allowed | Why |
|---|---|
| Connect to the platform's database | Separate host boundary; credentials would have to be copied across, and any schema change would break this app silently |
| `require`/`include` a platform file | Different repository, different deployment, different release cadence |
| Trust a member id sent by the browser | Every ownership decision is made by the API from the bearer token; see [03 §4](03-API-INTEGRATION.md#4-who-may-see-what) |
| Re-implement platform authorisation | The ACL is `api/v1/core/acl.php`, it is tested, and a copy would drift ([03 §5](03-API-INTEGRATION.md#5-the-acl-in-one-table)) |
| Build a second API for members | The platform's API is the integration surface; a parallel one was one of the prototype's main defects |

## 5. The trust model, in one diagram

```
Browser (member / parent)
   │  HTTPS, JWT bearer token (+ DPoP proof when the session is device-bound)
   ▼
Members web app  (this repo, own subdomain)          ← no database, no platform files
   │  HTTPS, JSON
   ▼
Platform /api/v1   (fkss-sunday-school-platform)     ← auth, ACL, database
   │
   ▼
MariaDB
```

Every arrow carries data, never code and never credentials for the layer below.
The subdomain boundary is real, not cosmetic: it is what makes this app
shippable and testable on its own, and what stops a change here from breaking
the staff console.

## 6. Cross-origin consequences you must plan for

Because the app is on a **different origin** from the API, the browser applies
CORS. The platform decides CORS in PHP
(`api/v1/core/middleware.php::handleCors()`), against a fixed allow-list:

```php
$allowedOrigins = [
    SITE_URL,                                // https://<SITE_DOMAIN>
    'http://' . SITE_DOMAIN,
    'https://www.' . SITE_DOMAIN,
];
```

`SITE_DOMAIN` comes from the platform's private env file (`school_config.php`
falls back to `felegekidusan.arkeonethiopia.com`). **The members subdomain is
not in that list**, so a browser `fetch()` from it will be blocked today.

Two ways to fix it, and the choice is the platform owner's
([05 §5](05-OPEN-DECISIONS.md#5-cors-for-the-members-subdomain)):

1. Add the members subdomain to `$allowedOrigins` in the platform (a one-line
   change, but it touches the platform's release train).
2. Proxy API traffic through this app's own server (`/api/*` on the members
   host forwards to the platform). No platform change, but the app must then
   forward `Authorization` and `DPoP` headers verbatim and must never cache.

Until either exists, browser calls fail — this is not a bug in this app.

Related, already correct in the platform: `Access-Control-Allow-Headers`
includes `Authorization`, and a bearer header is passed through to PHP by
`api/v1/.htaccess` and `admin/.htaccess` (`E=HTTP_AUTHORIZATION`).
