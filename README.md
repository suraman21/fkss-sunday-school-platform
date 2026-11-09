# FKSS — Sunday School Management System

FKSS is the management system of the Felege Kidusan Sunday School. It replaces spreadsheets and paper records for member registration, education (classes, assessments, report cards, timetable, attendance), the Mezmur (hymn) ministry, communications and reporting. Staff use a PHP web application; teachers and department staff also use an Android app built with Flutter that works offline and synchronises with the server.

> **Source baseline (reviewed 2026-10-28):** development main `7209278`. All eight baseline checks passed; the earlier 79-skip failure is resolved. A later change needs its own checks. See [Testing and CI](docs/DEVELOPMENT_AND_TESTING.md#3-current-ci-status).
> - Delivery is development main → development-server update → owner acceptance → separately authorized production promotion. A merge does not prove deployment. See [Operations](docs/OPERATIONS.md).
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
| Server runtime | PHP **8.5** in production (shared hosting, LiteSpeed, no shell access, no Composer, no cron assumed). CI runs the tests on PHP 8.4 and lints every file on PHP 8.5 | [ADR-0001](docs/adr/0001-php-monolith-on-shared-hosting.md), `.github/workflows/backend-checks.yml` |
| Database | MariaDB (CI: `mariadb:11.4`). Access mostly through **mysqli** (about 150 files), with PDO in a few places | `backend-checks.yml`, `config.php` |
| PHP libraries | Committed in `vendor/` (PhpSpreadsheet 5.9, OpenSpout, ZipStream). TCPDF and phpqrcode are bundled in `admin/` | `composer.lock` |
| Web front end | Server-rendered PHP pages with vanilla JavaScript (`admin/js`, `frontend/js`) | — |
| REST API | `/api/v1`. Custom HMAC-signed bearer tokens (*not* standard JWT) with rotating refresh sessions | [docs/API.md](docs/API.md) |
| Mobile | Flutter (CI: 3.44.9, stable), Dart ≥ 3.3, `sqflite`, `flutter_secure_storage`, `local_auth`. Source checkpoint: **1.7.5+42**; publication and installed versions are separate facts | `Mobile/wbws_flutter_app/pubspec.yaml` |
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
   - PHP 8.5 (production parity) or 8.4 (CI test parity) with mysqli, pdo_mysql, mbstring, curl, xml, gd, zip and bcmath. These are the extensions CI and `provision_env.sh` install.
   - MariaDB, Python 3.11 or later, and Flutter for mobile work.
2. Create a secrets file named `.fkss_env.php` **outside the web root**, using `env.example.php` as the template. `config.php` refuses to start if the file is missing. Never commit it.
3. Build a disposable test database with `tests/e2e/provision_env.sh`. It is destructive and test-only, and expects Debian/Ubuntu with passwordless `sudo`. **Never run it against production.**
4. Serve the repository root with PHP's built-in server, as the e2e harnesses do (`php -S 0.0.0.0:8080 -t .`).

## Testing and CI

```bash
python3 -m pytest tests/security -q          # security / regression suite (as in CI)
cd Mobile/wbws_flutter_app && flutter test     # Flutter unit tests
```

All eight checks on baseline `7209278` passed, rechecked on 2026-10-28. The backend workflow now provisions the isolated live-test world and retains its fail-on-any-skip gate. See [dated evidence](docs/reports/DOCUMENTATION_BASELINE_2026-10-28.md).

Use the exact candidate's results, not an old green badge, for release decisions. Setup, suite boundaries and remaining device/runtime gates: [docs/DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md).

## Known limitations (summary)

Each item is VERIFIED unless marked otherwise. Detail and evidence: [OPERATIONS.md §7](docs/OPERATIONS.md#7-known-limitations-and-open-questions).

| Area | Limitation | Detail |
|---|---|---|
| Security boundaries | Web: PHP session, CSRF token and the role guard in `admin/access_control.php`. Mobile: custom HMAC bearer token (not a JWT). No independent security certification exists | [SECURITY.md](docs/SECURITY.md), [API.md](docs/API.md) |
| Database and migrations | There is no migration ledger and the applied production schema is unverified. `sql/legacy/database_schema.sql` is stale (11 tables). `sql/` cannot be applied linearly to an empty database. Mezmur tables can change at runtime | [DATABASE.md](docs/DATABASE.md) |
| Runtime versions | CI behavioral tests use PHP 8.4, with an 8.5 lint job. Production PHP 8.5 and MariaDB 11.4 are dated hosting observations, not fresh runtime verification | [DATABASE.md §1](docs/DATABASE.md#1-engine-and-access) |
| Deployment | Owner acceptance on development precedes separate production authorization. Historical SFTP instructions do not establish today's cron, file or schema state; rollback rehearsal remains unverified | [OPERATIONS.md](docs/OPERATIONS.md) |
| Release evidence | Baseline CI is green, but signing, published APKs, device installation and hosted acceptance are separate gates. Source is 1.7.5+42 | [DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md), [OPERATIONS.md](docs/OPERATIONS.md) |
| Backups | The 2026-10-22 record places backups in the hosting account with no restore test. Current off-site/key-custody/restore readiness is unverified | [OPERATIONS.md](docs/OPERATIONS.md) |

## Documentation

| Topic | Canonical document |
|---|---|
| Index of all documents | [docs/README.md](docs/README.md) |
| User manual (partial; coverage explicit) | [docs/user_manual/README.md](docs/user_manual/README.md) |
| Documentation standard and lifecycle | [Authoring standard](docs/DOCUMENTATION_STANDARD.md), [retirement register](docs/DOCUMENT_LIFECYCLE.md) |
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
  - Development happens in `suraman21/fkss-sunday-school-platform`.
  - `Felege-Kidusan/felegekidusan` is the private production repository.
  - Development integration and production promotion are separate. Do not assume their `main` branches or deployed files are identical.
- **Beta branch:** `dev` builds the beta mobile app. It is **never** merged into `main`. See [BRANCHING.md](BRANCHING.md).
- **Changes:** use a feature or documentation branch and a pull request against `main`. Changes to application logic, schema, migrations, deployment or production configuration need explicit approval from the maintainers.
- **Migrations:** applied to production manually by a maintainer with production access.
- **Delivery:** merge checked changes to development main; verify the development-server update and obtain owner acceptance. Production promotion and hosting changes need separate explicit authorization. Historical SFTP safeguards remain in [docs/OPERATIONS.md](docs/OPERATIONS.md).
- **Security issues:** do **not** report them in public issues or pull requests. Report them privately to the maintainers. See [docs/SECURITY.md](docs/SECURITY.md).

No licence file is present in this repository.
