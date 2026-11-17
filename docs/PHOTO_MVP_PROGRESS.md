# FKSS Offline Photo Capture — Production Status

> The entries under “What's shipped to `main`” are historical milestones. The
> current working-tree hardening below is not deployed; verify the code and
> rollout gates before treating the photo workflow as production-ready.

> NOT MVP — production-grade. Acceptance: WebP q92 @ 1200×1500 / JPEG fallback, 2 MB cap;
> sync OFF by default (3-gate opt-in); multi-photographer conflict picker; ZIP airlock with
> HMAC; SQLite outbox; idempotent upload; batch roster; progress/ETA; orphan reconciliation;
> free-space guards; capability negotiation; least-privilege roles; on-brand maroon/gold UI.

## What's shipped to `main`

| Phase | Status | Commit | Summary |
|---|---|---|---|
| Design | ✅ | `80c8ead4` | `OFFLINE_PHOTO_SYNC_DESIGN.md` + `OFFLINE_PHOTO_ZIP_IMPORT_DESIGN.md` |
| **P1 Foundation** | ✅ | `02d8a0df` | Schema v43 (4 tables), `PhotoOutboxService` CRUD/policy/counters; per-install UUID; sync OFF / Wi-Fi only defaults; image/uuid/archive deps |
| **P2 Capture** | ✅ | `f9ae54b7` | `PhotoCaptureService` — EXIF bake, 4:5 crop, resize 1200×1500, WebP q92 primary / JPEG q92 fallback, 4-step QC loop, 2 MB hard cap; maroon+gold capture screen |
| **P3 Backend** | ✅ | `af0ec44a` | SQL 096 (4 tables); `PhotoUploadService::ingest` with Idempotency-Key, SHA-256, MIME, idempotency tripwire, conflict detection; `POST /api/v1/members/{id}/photo`; CORS `X-Photo-*` headers |
| **P4 Sync Worker** | ✅ | `38505900` | `ApiService.uploadMemberPhoto`; `PhotoSyncWorker` FIFO drain, backoff, pause/resume/cancel, ETA, byte counters, event stream |
| **P5 Sync Panel UI** | ✅ | `8a9b93f2` | Maroon+gold Sync Panel: 3-gate opt-in (toggle → Start → Wi-Fi unless cellular), MB-cost dialog, progress/ETA, event log |
| **Production hardening** | ✅ | `d9769d34` | Orphan reconciliation on startup, 4 GB storage budget, free-space gate on enqueue, Members-tab unsynced-count badge, server `/app/config` media capability probe (WebP vs JPEG fallback) |
| **Admin conflict review** | ✅ | `d9769d34` + `89ed28f2` | `admin/photo_review.php` maroon/gold side-by-side picker; `api_photo_candidates.php` list+decide API (transactional accept/reject with audit trail); `photo_importer` least-privilege role in `access_control.php` |
| **P6 Batch capture** | ✅ | `9a0f1539` | `photo_batch_capture_screen.dart` — roster walk, auto-advance to first missing, gold/grey/maroon status dots, skip/view-details, done dialog; Take-Photo + Batch buttons on member detail; Batch button on member list |
| **Z1 ZIP export** | ✅ | `9a0f1539` | `PhotoZipExportService` — store-only ZIP, canonical manifest.json (sorted keys), per-file SHA-256, HMAC-SHA256 manifest.sig (per-install key; server-paired upgrade in P7), share_plus share sheet; Sync Panel Export button now live; app v1.8.1+44 |
| **Z2-Z5 Server import** | ✅ | `9c941216` | `api_photo_zip_import.php` — upload → extract → manifest parse → per-file validate → `PhotoUploadService::ingestFromFile` (reuses receipt/candidate/conflict pipeline); archive ZIP for audit; `photo_import.php` drag-and-drop admin page with recent-batches table |
| **P7 hardening (partial)** | ✅ | `0878cc98` | Queue/exports moved to app-support dir (no iCloud backup); LRU eviction of delivered files >30 days; no-cloud-backup method channel reserved; `photo_importer` role added to admin user-management dropdown |
| **P7 HMAC pairing + 8 s undo** | ✅ | `4b7d8809` | Per-device HMAC key paired on first photo upload and persisted client-side; ZIP export signs with paired key (falling back to per-install); ZIP importer verifies server_paired signatures and surfaces per-install warnings honestly; capture screen Retake flows through proper retake/replaced_by semantics; 8-second UNDO snackbar after Accept |
| **P7 Android foreground service** | ✅ | `89bc2736` | Started/updated/stopped via MethodChannel from PhotoSyncWorker; ongoing maroon notification with progress/count/ETA and Stop action; dataSync foreground-service type; survives screen-off uploads; auto-stops on drain-complete or swipe-kill |
| **P7 iOS iCloud backup exclusion** | ✅ | `89bc2736` | AppDelegate sets `NSURLIsExcludedFromBackupKey` on queue/exports dirs via `app.fkss.photo/nocloud` MethodChannel; Android side already excluded by `android:allowBackup="false"` |
| **P7 flaky-network soak tests** | ✅ | `89bc2736` | `test/photo_flaky_network_soak_test.dart` with deterministic FakeOutbox/FlakyApi/FakeSyncWorker: idempotent replays, exponential-backoff under 503, 60%-failure chaos drain, mid-drain restart double-delivery, orphan reconciliation, 2 MB cap |

## File map (committed)

### Mobile (Flutter)
- `lib/services/local_schema_v43.dart` — DDL for 4 outbox tables
- `lib/services/photo_outbox_models.dart` — enums/entry/counters/policy models
- `lib/services/photo_outbox_service.dart` — SQLite CRUD + retake/claim/policy + orphan reconciliation + free-space guard + `memberIdsWithPhoto()` roster helper
- `lib/services/photo_capture_service.dart` — camera + EXIF/crop/resize/WebP/JPEG QC loop
- `lib/services/photo_sync_worker.dart` — FIFO ChangeNotifier drain
- `lib/services/photo_zip_export_service.dart` — Z1 signed ZIP packaging + share
- `lib/services/api_service.dart` — `uploadMemberPhoto()` multipart
- `lib/screens/members/member_photo_capture_screen.dart` — single capture
- `lib/screens/members/photo_batch_capture_screen.dart` — P6 roster walk
- `lib/screens/members/photo_sync_panel.dart` — P5 control surface (Z1 button live)
- `lib/screens/members/member_detail_screen.dart` — Take-photo + Batch buttons
- `lib/screens/members/member_list_screen.dart` — unsynced-count badge + Batch button
- `test/photo_outbox_p1_foundation_test.dart`
- `pubspec.yaml` — image/uuid/archive/share_plus deps, v1.8.1+44

### Server / Admin
- `sql/096_mobile_photo_uploads.sql` — receipts + candidates + import batches/items
- `admin/backend/services/PhotoUploadService.php` — `ingest` (multipart) + `ingestFromFile` (ZIP use), shared conflict/promote logic
- `api/v1/routes/members.php` — `POST /api/v1/members/{id}/photo` (30/min rate limit)
- `api/v1/routes/app.php` — `/app/config` now advertises `media` capability block (max bytes, MIME list, idempotency flag)
- `api/v1/core/middleware.php` — CORS `X-Photo-*` headers
- `admin/api_photo_candidates.php` — pending-list + accept/reject transactional API
- `admin/photo_review.php` — maroon/gold side-by-side conflict picker
- `admin/api_photo_zip_import.php` — Z2-Z5 ZIP upload + ingest
- `admin/photo_import.php` — drag-and-drop import page
- `admin/access_control.php` — `photo_importer` least-privilege role wired to photo pages
- `admin/uploads/members/photos/` — canonical WebP/JPEG storage
- `admin/uploads/members/photo_imports/` — audit archive of imported ZIPs (created on demand)

## Current working-tree hardening (not yet released)

- Mobile recognizes `photo_dept` and legacy `photo_importer` as photo-only roles,
  exposes member photo capture/sync, and fails closed for unknown roles.
- API member projections, dashboard/class access, photo uploads, and authenticated
  photo streams enforce role-specific limits.
- The Photo Department dashboard omits individual gender/age details and scopes
  import/device history to the signed-in operator.
- New member photos and ZIP imports use private storage. Legacy web photo views
  now use the authenticated photo endpoint; Apache denies direct requests to the
  legacy photos directory via `admin/uploads/members/photos/.htaccess`.
- ZIP imports use a validated preview/commit flow rather than extracting the
  whole archive. Review actions and batch attribution have also been corrected.
- Focused PHP ACL and Flutter role tests pass. The debug APK builds and launches
  on the connected Android 15 target, but its live API request still receives
  the old member-list 403 because these working-tree changes have not been
  deployed. The screen renders despite repeated emulator Impeller/GLES shader
  errors.

## Rollout blockers

1. **Do not assume legacy files are private.** Existing photos have not been
   migrated out of the web root. The `.htaccess` rule applies to Apache only;
   deployments using Nginx or another server must add and verify an equivalent
   deny rule before release. Plan and verify a controlled legacy-photo migration.
2. **Database rollout is unverified.** Review and apply the SQL migrations only
   through the normal deployment process after testing them on the target
   MySQL/MariaDB version; they have not been run against a database here.
3. **Run integration acceptance before release.** Verify paired and unpaired ZIP
   imports, role-scoped mobile browsing/capture, upload retries, private image
   delivery, and web review against a non-production database and a real device.
    The deployed API must first include the photo-role member-list ACL and
    projection changes; do not validate this by sending test data to production.

## Remaining polish

1. **POST_NOTIFICATIONS runtime prompt on Android 13+** — foreground-service notifications show without the permission on Android 12-; on Android 13+ users see a generic "app running" notification until they grant POST_NOTIFICATIONS. Prompt on first sync start if we get reports of missing notifications.
2. **Notification-tap opens Sync Panel** — tapping the notification currently brings the task to foreground; deep-linking straight to the Sync Panel requires the same route plumbing used by the app's notifications system. Nice-to-have.

## Test status

- **PHP:** All changed PHP files pass `php -l`; the Photo Department API ACL regression
  test passes. No database-backed integration test was run.
- **Flutter:** The focused role, outbox, and network-soak tests pass (17 tests);
  analysis of the photo sync services/config/tests reports no issues. Analysis of
  the changed UI files reports informational style/deprecation lints only.
- **Android:** Debug build and install succeeded; a screenshot confirmed the
  member/photo UI renders. Production API behavior and Android emulator renderer
  compatibility still need verification.
