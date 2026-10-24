> # ⚠️ YOU ARE ON THE `dev` BRANCH — FKSS **BETA** (test server)
> This branch = `main` + one commit that points the **phone app** at
> `https://felegekidusan.arkeonethiopia.com/api/v1` (package `com.arkeonethiopia.fkss.beta`, name "FKSS Beta").
> **Never merge this branch into `main`. Never push it to `Felege-Kidusan/felegekidusan`.**
> Keep it current with `git merge main` (one‑way). Details: `BRANCHING.md` · build: `Mobile/HOW_TO_BUILD_BETA.md`.

# FKSS — Sunday School Management System

FKSS (also referred to in the code as SSMS and, in older files, WBWS/WBSS) is the management system of the Felege Kidusan Sunday School. It replaces spreadsheets and paper records for member registration, education (classes, assessments, report cards, timetable, attendance), the Mezmur (hymn) ministry, communications and reporting. Staff use a PHP web application; teachers and department staff also use an Android app built with Flutter that works offline and synchronises with the server.

> **Status (2026-10-22, `main` = `46fbaa7`).** The system is in production use.
> - The automated *Backend checks* gate is currently **failing** because 79 tests are skipped (the 2 failing tests were fixed on 2026-10-24). See [Testing and CI](docs/DEVELOPMENT_AND_TESTING.md#3-current-ci-status).
> - Production deployments are manual. See [Operations](docs/OPERATIONS.md).
> - New engineers: start with [docs/ONBOARDING.md](docs/ONBOARDING.md).
> - No independent security certification exists.

## Principal capabilities

| Area | What exists in the code |
|---|---|
| Members | Registration (HR), temporary → permanent promotion, guardian/sibling linking ("Family Link"), import/export, ID cards with QR codes, archiving |
| Education | Classes, subjects, teacher assignments, enrolment, assessments with a maximum score, submission → review/approval workflow, report cards, timetable builder, school calendar (holidays / days off) |
| Attendance | Class-day attendance (web and mobile), lesson-level sessions, monthly roll-ups, attendance warnings and department status |
| Mezmur | Hymn catalogue, lyrics with timing, audio stored in object storage, department attendance and submissions |
| Communication | Announcements, notifications, threaded messages (web and mobile) |
| Reporting | Reporting centre with saved views, Excel/PDF exports, department analytics |
| Administration | Users and roles, branding, app-release manager (APK distribution and update policy), system health, encrypted database backups, failure intelligence and mobile telemetry |
| Mobile app | Offline cache and outboxes (attendance, grades, HR, Mezmur, messages), background sync, app lock (PIN/biometric), in-app update from the server |

## Technology stack

| Layer | Technology | Evidence |
|---|---|---|
| Server runtime | PHP **8.2** in production (shared hosting, LiteSpeed, no shell access, no Composer, no cron assumed). CI lints and tests on PHP 8.4 | [ADR-0001](docs/adr/0001-php-monolith-on-shared-hosting.md), `.github/workflows/backend-checks.yml` |
| Database | MariaDB (CI: `mariadb:11.4`). Access mostly through **mysqli** (about 150 files), with PDO in a few places | `backend-checks.yml`, `config.php` |
| PHP libraries | Committed in `vendor/` (PhpSpreadsheet 5.9, OpenSpout, ZipStream). TCPDF and phpqrcode are bundled in `admin/` | `composer.lock` |
| Web front end | Server-rendered PHP pages with vanilla JavaScript (`admin/js`, `frontend/js`) | — |
| REST API | `/api/v1`. Custom HMAC-signed bearer tokens (*not* standard JWT) with rotating refresh sessions | [docs/API.md](docs/API.md) |
| Mobile | Flutter (CI: 3.44.9, stable), Dart ≥ 3.3, `sqflite`, `flutter_secure_storage`, `local_auth`. App version on `main`: **1.7.3+40** | `Mobile/wbws_flutter_app/pubspec.yaml` |
| Tests | Python `pytest` suites (security, regression, e2e with Python Playwright), PHP harnesses, Flutter unit tests | [docs/DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md) |
| CI | GitHub Actions: *Backend checks*, *Production integrity*, *Branch guard* | `.github/workflows/` |

## Architecture at a glance

```
 Browser (staff) ──► admin/*.php pages ──► admin/api_*.php  (session + CSRF)
                     frontend/pages   ──► backend/api/*.php (shims → admin/api_*.php)
 Android app ──────► /api/v1/{resource}/{id}/{action}  (bearer token)
                                │
                     admin/backend/services/*  (shared domain services)
                                │
                     MariaDB  ·  object storage (Mezmur media)  ·  Telegram (alerts)
```

There are **three API layers**; consolidation is unfinished. Read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) before changing any endpoint.

## Repository layout

The web server's document root is the repository root (production: `public_html`). Folders marked **website** are served by the web server; the others are blocked by the root `.htaccess` and are never needed at run time. Do not add documentation files inside website folders ([CONTRIBUTING.md §9](CONTRIBUTING.md#9-naming-and-where-files-go)).

**Website folders**

| Path | Contents |
|---|---|
| `admin/` | Staff web application: pages, department dashboards (`dashboards/`), `api_*.php` endpoints, business services (`backend/services/`), ID cards (`id_cards/`), JS/CSS, maintenance tools (`tools/`: backups, health check, data repair) |
| `api/v1/` | REST API used by the mobile app (`routes/`, `core/`) |
| `backend/` | Compatibility shims that delegate to `admin/` (`api/`, `auth/`, `core/`, `id_cards/`, `users/`), plus `api/init.php` (dashboard bootstrap) |
| `frontend/` | Newer router-style pages (`pages/`, `layouts/`, `js/`): dashboard, Mezmur, finance, education assignments and teachers |
| `app/` | Legacy installable web app (PWA) |
| `monitor/` | Error and uptime monitor |
| `themes/` | Shared design system and per-school themes (`fkss/`, `wbss/`) |
| `css/`, `js/` | Styles and script for the public gallery page |
| `uploads/` | Server-side upload folders; only placeholders and `.htaccess` rules are tracked |
| `cgi-bin/` | Placeholder; its `.htaccess` only turns off folder listing |
| Root files | Public entry points (`index.php`, `member.php`, `public_gallery.php`, `register_submit.php`), shared settings (`config.php`, `school_config.php`, `branding_defaults.php`), `env.example.php` (template for the secrets file), `robots.txt` |

**Not served by the web server**

| Path | Contents |
|---|---|
| `Mobile/` | Flutter app in `wbws_flutter_app/`, plus icon and splash source images used by its build. See [Mobile/README.md](Mobile/README.md) |
| `sql/` | Numbered SQL migrations (`001`–`078`), read-only checks (`preflight/`) and archived legacy schema sources (`legacy/`). See [sql/README.md](sql/README.md) |
| `tests/` | Automated tests: `security/` (runs in CI), `e2e/`, `smoke/`, `audit/`, `mutation/`, `js/`, `fixtures/`. See [tests/README.md](tests/README.md) |
| `scripts/` | Command-line tools for the operator: live URL check, production promotion, database restore, one-off data tools. See [scripts/README.md](scripts/README.md) |
| `docs/` | Documentation. Start at [docs/README.md](docs/README.md) |
| `vendor/` | Third-party PHP libraries, committed because the server has no Composer step (`composer.json`, `composer.lock`) |
| `.github/` | CI workflows and the pull-request template |

## Local development (summary)

The full, verified procedure and its caveats are in [docs/DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md).

1. You need:
   - PHP 8.2 (production parity) or 8.4 (CI parity) with mysqli, pdo_mysql, mbstring, curl, xml, gd, zip and bcmath. These are the extensions CI and `provision_env.sh` install.
   - MariaDB, Python 3.11 or later, and Flutter for mobile work.
2. Create a secrets file named `.fkss_env.php` **outside the web root**, using `env.example.php` as the template. `config.php` refuses to start if the file is missing. Never commit it.
3. Build a disposable test database with `tests/e2e/provision_env.sh`. It is destructive and test-only, and expects Debian/Ubuntu with passwordless `sudo`. **Never run it against production.**
4. Serve the repository root with PHP's built-in server, as the e2e harnesses do (`php -S 0.0.0.0:8080 -t .`).

## Testing and CI

```bash
python3 -m pytest tests/security -q          # security / regression suite (as in CI)
cd Mobile/wbws_flutter_app && flutter test     # Flutter unit tests
```

Latest CI run on `main` (`46fbaa7`, 2026-10-22):

| Job | Result |
|---|---|
| PHP lint | pass (363 files) |
| Flutter tests | pass (657 tests) |
| Migration numbering | pass |
| Android debug APK | pass |
| Security and regression suite | **fail** (2 failed, 2,793 passed, 79 skipped; the job also fails whenever any test is skipped) |

Update 2026-10-24: the 2 failures are fixed; the suite still fails on the 79 skips.

Details and the work needed to make the gate trustworthy again: [docs/DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md).

## Known limitations (summary)

Each item is VERIFIED unless marked otherwise. Detail and evidence: [OPERATIONS.md §7](docs/OPERATIONS.md#7-known-limitations-and-open-questions).

| Area | Limitation | Detail |
|---|---|---|
| Security boundaries | Web: PHP session, CSRF token and the role guard in `admin/access_control.php`. Mobile: custom HMAC bearer token (not a JWT). No independent security certification exists | [SECURITY.md](docs/SECURITY.md), [API.md](docs/API.md) |
| Database and migrations | There is no migration ledger and the applied production schema is unverified. `sql/legacy/database_schema.sql` is stale (11 tables). `sql/` cannot be applied linearly to an empty database. Mezmur tables can change at runtime | [DATABASE.md](docs/DATABASE.md) |
| Runtime versions | CI uses PHP 8.4 while production uses PHP 8.2. Production MariaDB was 11.4 in a 2026-10-02 dump (not re-verified) | [DATABASE.md §1](docs/DATABASE.md#1-engine-and-access) |
| Deployment | Manual SFTP to shared hosting: no shell, Composer or cron for the application, no staging, no rehearsed rollback. Server-side git metadata is not authoritative | [OPERATIONS.md](docs/OPERATIONS.md) |
| Release evidence | The CI gate is red and 79 tests are skipped. The Android APK is debug-signed. Mobile 1.7.3+40 is not released | [DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md), [OPERATIONS.md](docs/OPERATIONS.md) |
| Backups | Backups are kept in the same hosting account; restore has never been tested | [OPERATIONS.md](docs/OPERATIONS.md) |

## Documentation

| Topic | Canonical document |
|---|---|
| Index of all documents | [docs/README.md](docs/README.md) |
| New engineer onboarding | [docs/ONBOARDING.md](docs/ONBOARDING.md) |
| Product requirements (PRD) | [docs/product/PRD.md](docs/product/PRD.md) |
| Technical design (TRD/SAD) | [docs/architecture/TECHNICAL_DESIGN.md](docs/architecture/TECHNICAL_DESIGN.md) |
| Architecture decision records | [docs/adr/README.md](docs/adr/README.md) |
| Glossary | [docs/product/GLOSSARY.md](docs/product/GLOSSARY.md) |
| Change history | [CHANGELOG.md](CHANGELOG.md) |
| How to contribute | [CONTRIBUTING.md](CONTRIBUTING.md) |
| Architecture | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| API (overview and OpenAPI 3.1) | [docs/API.md](docs/API.md), [docs/api/openapi.yaml](docs/api/openapi.yaml) |
| Roles and permissions | [docs/ROLES_AND_PERMISSIONS.md](docs/ROLES_AND_PERMISSIONS.md) |
| Database and migrations | [docs/DATABASE.md](docs/DATABASE.md) |
| Data dictionary and ERD | [docs/DATA_DICTIONARY.md](docs/DATA_DICTIONARY.md) |
| Development, testing, CI | [docs/DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md) |
| Test strategy | [docs/TEST_STRATEGY.md](docs/TEST_STRATEGY.md) |
| Proposals (RFCs) | [docs/rfc/README.md](docs/rfc/README.md) |
| Deployment, backups, mobile release | [docs/OPERATIONS.md](docs/OPERATIONS.md) |
| Security controls and reporting | [docs/SECURITY.md](docs/SECURITY.md) |
| Branch rules | [BRANCHING.md](BRANCHING.md) |
| Release workflow | [docs/PRODUCTION_WORKFLOW.md](docs/PRODUCTION_WORKFLOW.md) |

## Contribution, review and release

The full working agreement (workflow, coding standards, database rules, tests, definition of done) is in **[CONTRIBUTING.md](CONTRIBUTING.md)**.

- **Repositories:**
  - Development happens in `suraman21/fkss-sunday-school-platform`. That repository was formerly named `suraman21/SSMS`, and the old URL redirects to it.
  - `Felege-Kidusan/felegekidusan` is the private production repository.
  - Their `main` branches are kept identical.
- **Beta branch:** `dev` builds the beta mobile app. It is **never** merged into `main`. See [BRANCHING.md](BRANCHING.md).
- **Changes:** use a feature or documentation branch and a pull request against `main`. Changes to application logic, schema, migrations, deployment or production configuration need the owner's explicit approval.
- **Migrations:** applied to production manually by the owner.
- **Deployment:** a manual SFTP upload of the changed files, with a backup and checksum verification. See [docs/OPERATIONS.md](docs/OPERATIONS.md).
- **Security issues:** do **not** report them in public issues or pull requests. Contact the project owner privately. See [docs/SECURITY.md](docs/SECURITY.md).

No licence file is present in this repository.
