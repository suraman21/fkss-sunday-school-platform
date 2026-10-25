# RFC 0001: Repository structure and root clean-up

| | |
|---|---|
| **Status** | **Accepted.** Phase 1 (approved 2026-10-23) is implemented, including the production server. Option 1 (approved 2026-10-23) is implemented: Step 1 (safety checks) and Step 2 (tidy non-web folders) are done, and the old copies were removed from the production server on 2026-10-23; see [section 8](#8-option-1-tidy-without-moving-web-files). Phase 3 remains proposed |
| **Author** | FKSS maintainers |
| **Decision makers** | FKSS maintainers, with the technical team |
| **Evidence base** | `main` at `46fbaa7`: `git ls-files`, `.htaccess` rules, and a reference search for every path named below |

## 1. Summary

The repository root has about 40 entries: web entry points, legacy folders, a vendored dependency tree, tooling and documents side by side. Reviewers read this as disorder. This RFC proposes a **phased** clean-up that first changes **nothing the running site depends on**, and only later, with testing and an approved deployment, moves code.

## 2. Constraints (why we cannot just move folders)

| Constraint | Evidence | Consequence |
|---|---|---|
| The repository root **is** the web root on shared hosting, deployed by file upload | [OPERATIONS.md §2](../OPERATIONS.md#2-code-deployment-manual-sftp) | Every move changes a public URL |
| Printed member ID cards carry QR codes that open `member.php` | `member.php` header; referenced from 63 files, including the app's QR scanner (`Mobile/wbws_flutter_app/lib/services/qr_attendance.dart`) | `member.php` must keep its URL **permanently** |
| Installed Android builds call fixed paths (`/api/v1/...`) and cannot be updated remotely | [ADR-0005](../adr/0005-direct-apk-distribution.md) | API paths are frozen for the life of field builds |
| `frontend/js/*` calls `backend/api/*` shims | [API.md §1](../API.md#1-entry-points) | Shims stay until callers are migrated |
| No Composer on the host | [ADR-0001](../adr/0001-php-monolith-on-shared-hosting.md) | `vendor/` is committed on purpose (it is listed in `.gitignore`, but its files are tracked) |
| Tests assert on root paths | `tests/security/test_deployment_tool_hardening.py`, `test_id_card_subsystem_hardening.py` reference `leak_detector.php` and `branding_defaults.php` | Any move updates tests in the same change |
| Access rules are path-based | `.htaccess` line 48 denies `sql`, `docs`, `tests`, `scripts`, `tools`, `vendor`, `Mobile`, `.github` | Moving a file into or out of these folders changes whether the web can reach it |

## 3. Current root inventory

| Entry | What it is | Proposal |
|---|---|---|
| `index.php`, `member.php`, `public_gallery.php`, `register_submit.php`, `robots.txt`, `.htaccess` | Public web entry points | **Keep** (URLs) |
| `config.php`, `school_config.php`, `branding_defaults.php`, `env.example.php` | Configuration loaded by every request | **Keep** (included by path) |
| `admin/`, `api/`, `backend/`, `frontend/`, `app/`, `monitor/`, `themes/`, `css/`, `js/`, `uploads/` | Application code and assets served by the web | **Keep** in Phase 1; see Phase 3 |
| `sql/`, `tests/`, `docs/`, `scripts/`, `tools/`, `Mobile/`, `.github/` | Non-web material, already blocked from the web | **Keep** |
| `vendor/`, `composer.json`, `composer.lock` | PHP libraries, committed for shared hosting | **Keep**; document why (Phase 0) |
| `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `BRANCHING.md`, `.editorconfig`, `.gitignore` | Standard project files | **Keep** |
| `ENTERPRISE_SECURITY_AUDIT_REPORT.md` | Superseded audit (2026-09-27) | **Move** to `docs/audits/` (Phase 1); later removed from the repository |
| `database_schema.sql` | Legacy, partial schema (11 tables) | **Move** to `sql/legacy/` with a README (Phase 1); 15 files reference it |
| `leak_detector.php` | Branding check run after deployment | **Review** (Phase 1); referenced by `admin/access_control.php` and tests |
| `index.html` | Static page alongside `index.php` | **Removed** (2026-10-23): never served at `/`, no requests in the October access logs, no references |
| `cgi-bin/` | Hosting-created folder (`Options -Indexes` only) | **Keep** (hosting artifact) |
| `public_website_frontend/` | A standalone copy of the landing site; no code references it, it is not blocked from the web, and it has already drifted (its `public_gallery.js` differs from `js/public_gallery.js`) | **Decide** (Phase 1): remove from the tree, or move under `docs/` as an archived design reference |

## 4. Proposal

### Phase 0: explain, do not move (documentation only; no deployment)

- Keep the **Repository layout** section of `README.md` as the single map of the root, and add the reasons above (web root, frozen URLs, committed `vendor/`).
- Adopt this RFC process (`docs/rfc/`) for structural changes.

**Risk:** none. **Rollback:** not needed.

### Phase 1: tidy non-runtime files (small pull requests; one move each)

Each item is its own pull request containing the move, every reference update, and passing tests:
1. `ENTERPRISE_SECURITY_AUDIT_REPORT.md` → `docs/audits/2026-09-27-enterprise-security-audit.md` (update 2 links; content unchanged).
2. `database_schema.sql` → `sql/legacy/database_schema.sql` (update 15 references; check that no tool loads it by path).
3. `public_website_frontend/`: decide between removal and archiving; first confirm whether a copy exists on the server.
4. `index.html` and `leak_detector.php`: confirm their use, then keep or move with references and tests updated.

**Risk:** low; these files are not part of normal page loads. **Deployment:** items 2–4 change files on the server and need an approved deployment; item 1 does not. **Rollback:** revert the pull request.

### Phase 2: per-folder guidance

- A short README in each top-level code folder, **only** where the web server cannot serve it (or with a deny rule added in the same change), so that documentation does not become a public file.

**Risk:** low, but touches `.htaccess`, so it needs review and an approved deployment.

### Phase 3: long-term structure (separate RFCs; not before the API consolidation)

- Retire `backend/api/*` shims after `frontend/js/*` is migrated to the admin endpoints.
- Move application code under a single `src/` (or `app/`) with autoloading, leaving only entry points at the web root.
- Separate a `public/` web root from code, if the hosting allows setting the document root.

**Risk:** high; affects every request. Requires full regression testing, a staged rollout and a rollback plan.

## 5. Non-goals

- No moves before the technical review meeting.
- No change to public URLs, API paths or the database.
- No removal of historical evidence. Files are moved with `git mv`, so their history is kept.

## 6. Open questions

1. Is `public_website_frontend/` still needed, and is a copy deployed?
2. Is `index.html` ever served instead of `index.php`? `.htaccess` sets no `DirectoryIndex`, so the hosting default decides which file answers `/`, and `robots.txt` explicitly allows `/index.html`. Check with a request to the live site before changing either file. **Answered 2026-10-23:** both servers serve `index.php` at `/`; `index.html` had no requests in the October access logs. It was removed and the `robots.txt` line dropped.
3. Is it possible to set a separate document root on the current hosting (affects Phase 3)?

## 7. Decision

**2026-10-23: Phase 1 approved**, with the condition that every removal is proven not to affect the system, and that it is confirmed on the dev server before production.

### Phase 1 outcome

| Item | Done | Evidence that nothing depends on it |
|---|---|---|
| Superseded audit report | Moved to `docs/audits/`; later removed from the repository with the other historical audit records | Referenced only by documentation; already denied by `.htaccess` |
| `database_schema.sql` | Moved to [`sql/legacy/`](../../sql/legacy/README.md) | Users updated: `tests/e2e/provision_env.sh` and two tests. CI never loads it. The `.htaccess` rule still matches the new path |
| Root `leak_detector.php` | **Removed** (changed from "move to `tools/`") | Byte-identical to `admin/leak_detector.php`, whose relative paths only work from `admin/`; the root copy looked for the configuration outside the project. The `admin/` copy and its HTTP blocks stay |
| `public_website_frontend/` | **Removed** | No PHP, JavaScript, mobile, test, CI or server-rule reference; the live homepage is `index.php` with the root `css/` and `js/`; 0 requests in the available access logs (21–23 Oct 2026) |
| `backend/id_cards/libs/` | **Removed** (26 files) | Byte-identical to `admin/id_cards/libs/`. The two `backend/id_cards/` shims load the `admin/` pages, which include libraries with `__DIR__`, so the `admin/` copy is always used. No relative `libs/` include exists. Unlike the `admin/` copy, this folder was not denied by the web server, so its demo script `phpqrcode/index.php` (which writes files from request data) was publicly runnable |

Found during the analysis, not changed (outside Phase 1): `index.html` at the root is an old page with the previous school name and is still reachable at `/index.html`; the live homepage is served by `index.php`. Recommended for Phase 2. Removed on 2026-10-23.

## 8. Option 1: tidy without moving web files

**2026-10-23: approved.** The web root stays as it is (as in WordPress and MediaWiki). Only folders the website never uses are tidied. No page, API or app URL changes. Each part is its own pull request, checked file by file, tested on dev first. Old copies on the production server are moved to a dated backup folder only after explicit approval.

Decisions:
- `sql/` keeps its name (not renamed to `database/`): more than 250 files mention it, and migrations are applied from it by hand.
- `Mobile/` keeps its name (not renamed to `mobile/`): about 100 files mention it, and a capital-letter-only rename is unreliable on Windows.
- Old PHP migrations go to `sql/legacy/`, next to the legacy schema, so all database material is in one folder.
- Dated records (audit evidence, applied migrations, dated plans) are not rewritten. Dated plans with run commands get a one-line "moved" note.
- Live website files are not edited for path mentions in comments, so production keeps matching `main`. Such comments are fixed the next time the file changes for a real reason.

| Step | What | Status |
|---|---|---|
| 1 | Safety checks: include-path and boundary tests, live URL checker ([OPERATIONS.md §2.2](../OPERATIONS.md#22-folder-structure-safety-checks)) | Done (PR #16) |
| 2a | `tools/` (3 CLI scripts) → `scripts/` | Done. The old copy was removed from the production server on 2026-10-23 (kept in a dated backup) |
| 2b | `admin/migrations/` and `backend/migrations/` (19 old PHP migrations) → `sql/legacy/php-migrations/admin/` and `…/backend/` | Done, moved unchanged (they can no longer run from the new folder, by design). The old copies were removed from the production server on 2026-10-23 (kept in a dated backup) |
| 2c | Sort loose files in `docs/` into topic folders | Done. 33 historical records moved into `mobile_offline_sync/`, `academic_tracking/`, `communication/`, `responsive_ui/`, `attendance/`, `incidents/` and `audits/`; the 16 main guides stay at the top of `docs/`. Links fixed; new CI test `test_docs_links.py` checks every relative Markdown link. Most of these records were later moved out of the repository; `incidents/` and `audits/` remain |
| 2d | Folder guides and naming rules | Done. Guides added to the four non-website folders (`scripts/`, `sql/`, `tests/`, `Mobile/`); the README folder map now covers every top-level folder and says which are website folders; naming rules in CONTRIBUTING §9. No README inside website folders (they are served), `.github/` (GitHub would show it instead of the root README) or `vendor/` (Composer replaces it) |

Known outdated comments (fix each with the next real change to that file; they are comments only and change no behaviour):
- `admin/backend/services/AttendanceRiskService.php` line 53 still says `tools/attendance_rollout_2026.php` (now `scripts/`).
- `admin/components/notification_center.php` line 6 refers to a design record that is no longer in the repository.
- `Mobile/wbws_flutter_app/android/app/src/main/kotlin/com/arkeonethiopia/fkss/BackgroundSyncProducer.kt` line 45 refers to a design record that is no longer in the repository.
- `env.example.php` line 110 refers to `Mobile/HOW_TO_SHIP_AN_UPDATE.md`, which is no longer in the repository; the current guide is `Mobile/wbws_flutter_app/docs/RELEASE.md`.

`sql/042_notification_center.sql` line 6 mentions a design record that is no longer in the repository. It is an applied migration, a dated record, so it is not changed.
