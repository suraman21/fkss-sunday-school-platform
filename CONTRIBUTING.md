# Contributing to FKSS

This guide is for engineers joining the project. Read it together with [README.md](README.md), [docs/ONBOARDING.md](docs/ONBOARDING.md) and [BRANCHING.md](BRANCHING.md).

FKSS is **in production** and holds personal data about children and families. Treat every change as a production change.

## 1. Ground rules

1. **No direct pushes to `main`.** Work on a feature branch and open a pull request into `main`.
2. **Never merge `dev` into `main`** and never push `dev` to the production repository ([BRANCHING.md](BRANCHING.md)).
3. **No production access without the maintainers' approval:** no deploys, database changes, server changes or credential use. A maintainer with production access applies migrations and approves every deployment ([OPERATIONS.md](docs/OPERATIONS.md)).
4. **This repository must contain no personal data, credentials, infrastructure details or vulnerability details.** Use synthetic test data only. Report security issues privately ([SECURITY.md §1](docs/SECURITY.md#1-reporting-a-vulnerability)).
5. **Report results truthfully.** Never claim a test, check or deployment passed unless it ran and passed.

## 2. Getting set up

Follow [DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md): a test-only secrets file, a disposable database built with `tests/e2e/provision_env.sh` (destructive; never point it at production), `php -S`, and Flutter with `--enforce-lockfile`.

Recommended first reads, in order: [PRD](docs/product/PRD.md) → [Technical design](docs/architecture/TECHNICAL_DESIGN.md) → [Roles and permissions](docs/ROLES_AND_PERMISSIONS.md) → [Database](docs/DATABASE.md) → [Decision records](docs/adr/README.md) → [Glossary](docs/product/GLOSSARY.md).

## 3. Workflow

1. **Open or pick an issue** that states the problem and the acceptance criteria. Link the PRD requirement ID (for example `FR-ATT-03`) if one applies.
2. **Branch from `main`:** `feature/<short-name>`, `fix/<short-name>`, `docs/<short-name>`, `security/<short-name>`.
3. **Plan before large changes.** If the change alters a business rule, the data model, authentication or authorization, or the API contract, write a short plan or an ADR draft ([docs/adr/](docs/adr/README.md)) and get it approved first.
4. **Commit messages** follow the Conventional Commits style already used in the history: `feat(attendance): …`, `fix(grades): …`, `docs: …`, `test: …`, `ci: …`, `security: …`. Write the subject in the imperative and explain the *why* in the body.
5. **Open a pull request** into `main` and complete the PR template checklist.
6. **Review:** at least one reviewer other than the author. Changes to authentication, authorization, personal-data handling, migrations or the mobile API contract also need a security-minded reviewer.
7. **After merge:** a maintainer schedules deployment. Then `main` is merged into `dev` (`git checkout dev && git merge main && git push origin dev`).

## 4. Coding standards

### 4.1 PHP (server)
- **Database:** prepared statements only. Never interpolate request data into SQL. Whitelist identifiers (column or sort names). Use transactions for multi-step writes.
- **Output:** escape all user data in HTML with `e()` / `esc()` (`config.php`). In inline `<script>` or attribute contexts, use JSON with hex escaping (`JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP`). Do not build inline `onclick` handlers from user data.
- **Web endpoints (`admin/api_*.php`):**
  - Rely on `config.php` and `admin/access_control.php` for login and the page role.
  - Add the file to `ROLE_MAP` with the narrowest roles.
  - Call `requireCsrf()` / `requireCsrfForPost()` on state-changing requests.
  - Check department ownership or class assignment inside the action.
  - Call `ay_require_writable()` for writes that must be blocked while viewing a past academic year.
- **REST v1 (`api/v1/routes/*.php`):**
  - Use `apiRequireAuth()` and the role helpers in `api/v1/core/acl.php`.
  - Respond with `ok()` / `err()` and `paginated()`.
  - Keep responses backward compatible with installed app builds (check `X-App-Build`). Never remove a field that older builds read.
- **Business rules belong in services** (`admin/backend/services/`), not in dashboards or endpoint files. Before changing a rule, find every implementation across the three API layers ([ARCHITECTURE.md §3](docs/ARCHITECTURE.md#3-the-three-api-layers-unfinished-consolidation)).
- **Compatibility:** code must run on **PHP 8.5** (production). CI runs the tests on PHP 8.4 and lints every file on 8.5. No new PHP extensions, no Composer step on the server; new libraries must be committed to `vendor/` after review.
- **Errors:** log details server-side and return generic messages. Never expose stack traces, SQL or paths.
- **Destructive actions:** use the shared danger-confirmation dialog, with a server-side phrase check for high-risk actions ([ADR-0013](docs/adr/0013-tiered-danger-confirmation.md)).

### 4.2 JavaScript and CSS (web)
- Vanilla JS modules in `admin/js/` and `frontend/js/`; no bundler. Use `textContent` (not `innerHTML`) for user data.
- When you change a script or stylesheet, bump the `?v=` query on **every** page that includes it (for example `report_card.js?v=20261022s`). Browsers otherwise keep the old file. Do not change global asset-version constants for this.
- Third-party assets must use Subresource Integrity (`integrity` + `crossorigin`).
- Mobile-friendly layouts; do not disable pinch zoom.

### 4.3 Flutter (mobile)
- Writes go through the existing outbox and sync services with idempotency keys. Do not call write endpoints directly from widgets.
- Local schema changes need a migration step in the local database and a test for upgrading from the previous version.
- The API base URL on `main` is production and is enforced by CI; beta builds come from `dev` only.
- Bump `version:` in `pubspec.yaml` (both name and build number) for every release build.

### 4.4 Dates, language and data
- **Every user-facing date uses the Ethiopian calendar** ([ADR-0008](docs/adr/0008-ethiopian-calendar-only.md)). Store Gregorian dates and convert at the edges.
- Use `utf8mb4` everywhere. Test with Amharic (Ethiopic) input.
- Derive sections and age groups only from `MemberCategory` ([ADR-0007](docs/adr/0007-section-equals-age-group.md)).

## 5. Database changes

- Add a new file `sql/NNN_short_name.sql` with the next free number. Never edit a migration that may already have been applied.
- Make it **additive and idempotent:** `CREATE TABLE IF NOT EXISTS`, `information_schema` guards before `ALTER`. No `DROP`, `TRUNCATE` or unguarded `DELETE`. CI flags destructive statements.
- MariaDB-safe syntax; no stored procedures, events or triggers unless approved.
- Code must keep working before the migration is applied (detect the table or column, or stay inert), because deploys and migrations happen separately.
- List the migration in the PR description for the maintainer who applies it, with a read-only preflight query where useful (`sql/preflight/`).
- Do not add runtime DDL to application code.

## 6. Tests

- Run before every PR:
  ```bash
  python3 -m pytest tests/security -q -rs
  cd Mobile/wbws_flutter_app && flutter test
  ```
- Add or update tests for every behaviour change, especially authorization (one test per role that must be refused), personal-data exposure and validation rules.
- **Known state:** *Backend checks* currently fails on `main` because 79 tests are skipped. Your PR must not add failures or unexplained skips. Making the skipped tests run is welcome ([DEVELOPMENT_AND_TESTING.md §3](docs/DEVELOPMENT_AND_TESTING.md#3-current-ci-status)).
- Never weaken or delete a test to make CI pass without an agreed reason in the PR.

## 7. Documentation

Update documentation in the same PR as the code:
- **[CHANGELOG.md](CHANGELOG.md):** one line under *Unreleased* for any user-visible or operational change.
- **[PRD](docs/product/PRD.md):** if a requirement or business rule changes.
- **[ADR](docs/adr/README.md):** for any significant architecture or product decision.
- **Canonical docs** (API, database, roles, operations, security) when their subject changes.
- Follow the status labels in [docs/README.md](docs/README.md). Do not rewrite historical documents; add a new one or a banner.

## 8. Definition of done

- [ ] Acceptance criteria met; PRD requirement referenced where applicable.
- [ ] Authorization checked for every role; no new personal-data exposure.
- [ ] Runs on PHP 8.5; no new server dependencies.
- [ ] Tests added or updated; the suites above pass for the touched code; no new skips.
- [ ] Migration (if any) is additive and idempotent, and listed in the PR description.
- [ ] Ethiopian calendar for every new user-facing date.
- [ ] Documentation and changelog updated.
- [ ] Reviewed and approved; deployment scheduled by a maintainer.

## 9. Naming and where files go

The folder map is in the [README](README.md#repository-layout). Each non-website folder has its own guide: [scripts/](scripts/README.md), [sql/](sql/README.md), [tests/](tests/README.md), [Mobile/](Mobile/README.md).

**Where a new file goes**

| New file | Folder | Name pattern |
|---|---|---|
| Staff page | `admin/` | `snake_case.php` |
| Staff page endpoint | `admin/` | `api_<topic>.php` (add it to `ROLE_MAP`) |
| Business rule / service class | `admin/backend/services/` | `PascalCase.php` |
| Department dashboard | `admin/dashboards/` | `snake_case.php` |
| Page script | `admin/js/` | `snake_case.js` |
| Mobile REST route | `api/v1/routes/` | lowercase snake_case, named after the resource (`attendance_warnings.php`) |
| Database migration | `sql/` | `NNN_snake_case.sql`, next free number ([sql/README.md](sql/README.md)) |
| Read-only database check | `sql/preflight/` | `snake_case.sql` |
| Python test | `tests/security/` | `test_<topic>.py` |
| Operator command-line tool | `scripts/` | `snake_case.php`, `.py` or `.sh`, with a usage header |
| Flutter code | `Mobile/wbws_flutter_app/lib/` | `snake_case.dart` |
| Main guide | `docs/` | `UPPER_SNAKE_CASE.md` |
| Topic record | `docs/<topic>/` (lowercase snake_case folder) | follow the folder |
| Decision record | `docs/adr/` | `NNNN-kebab-case.md` |

These patterns are measured from the current code: all migrations, `admin/*.php` pages, services, REST routes, Python tests and Dart files already follow them.

**Rules**
- **Don't rename existing files to fix their style.** Some older files use dashes (for example `admin/js/manage-members.js`, `admin/dashboards/super-admin.php`). Other pages, scripts and access rules refer to these names (for example `admin/access_control.php`). Renaming them is a separate, approved change, never part of other work.
- **Keep the existing folder names** `sql/` and `Mobile/` (capital M). Decision: [RFC 0001 §8](docs/rfc/0001-repository-structure.md#8-option-1-tidy-without-moving-web-files).
- **No documentation inside website folders** (`admin/`, `api/`, `app/`, `backend/`, `frontend/`, `css/`, `js/`, `themes/`, `uploads/`, `monitor/`). Those folders are served by the web server. Documentation goes in `docs/`. A test enforces this for `admin/`, `api/v1/` and `app/`.
- **No `README.md` in `.github/`:** GitHub would show it instead of the repository README. **Nothing hand-written in `vendor/`:** Composer replaces that folder.
- **Never commit** secrets files, signing keys, built APKs, database dumps or backup archives. CI rejects secrets files, keys and APKs, but it does **not** catch dumps or backups, so check `git status` before every commit.
