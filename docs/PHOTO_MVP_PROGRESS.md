# FKSS Offline Photo Capture — Production Status (updated 2026-11-14)

> NOT MVP — production-grade. Capture now uses an in-app camera, Flutter's native crop renderer,
> and native JPEG compression at device-tier resolutions under the existing 2 MB cap;
> sync OFF by default (3-gate opt-in); multi-photographer conflict picker; ZIP airlock with
> HMAC; SQLite outbox; idempotent upload; batch roster; progress/ETA; orphan reconciliation;
> free-space guards; capability negotiation; least-privilege roles; on-brand maroon/gold UI.

## What's shipped to `main`

| Phase | Status | Commit | Summary |
|---|---|---|---|
| Design | ✅ | `80c8ead4` | `OFFLINE_PHOTO_SYNC_DESIGN.md` + `OFFLINE_PHOTO_ZIP_IMPORT_DESIGN.md` |
| **P1 Foundation** | ✅ | `02d8a0df` | Schema v43 (4 tables), `PhotoOutboxService` CRUD/policy/counters; per-install UUID; sync OFF / Wi-Fi only defaults; image/uuid/archive deps |
| **P2 Capture** | ✅ | `f9ae54b7` | Original capture flow: EXIF bake, 4:5 crop, WebP/JPEG, 2 MB cap. Current implementation uses the Flutter `camera` plugin for an in-app guide, focus, exposure, flash, and zoom; Flutter renders the crop and a native compressor creates tier-aware JPEGs. The save/undo affordance closes within 2 seconds. Updated to a 3:4 passport-ratio canvas with a green-frame passport guide (eye/shoulder level marks); historical P2 evidence at `f9ae54b7` refers to the earlier 4:5 output. Now upgraded to the adaptive human-guided capture experience (spec: Adaptive Human-Guided Photo Capture v1.0): on-device ML Kit face + pose landmark detection (models bundled for offline), landmark-driven adaptive guide, single-instruction feedback engine with smoothing, face/eyes/shoulders status chips, gallery import with EXIF bake; see `PHOTO_CAPTURE_ADAPTIVE_GUIDE.md`. The capture screen is built to the approved FKSS reference (crest header, single-instruction card, crop-only 3:4 preview with the adaptive guide, status rail, passport-size card, gallery/shutter/switch bar with Photo/Video tabs; exposure, zoom, flash and close moved into capture settings). On-device calibration (Stage D) and production readiness (Stage E) remain manual acceptance. |
| **P3 Backend** | ✅ | `af0ec44a` | SQL 096 (4 tables); `PhotoUploadService::ingest` with Idempotency-Key, SHA-256, MIME, idempotency tripwire, conflict detection; `POST /api/v1/members/{id}/photo`; CORS `X-Photo-*` headers |
| **P4 Sync Worker** | ✅ | `38505900` | `ApiService.uploadMemberPhoto`; `PhotoSyncWorker` FIFO drain, backoff, pause/resume/cancel, ETA, byte counters, event stream |
| **P5 Sync Panel UI** | ✅ | `8a9b93f2` | Maroon+gold Sync Panel: 3-gate opt-in (toggle → Start → Wi-Fi unless cellular), MB-cost dialog, progress/ETA, event log |
| **Production hardening** | ✅ | `d9769d34` | Orphan reconciliation on startup, 4 GB storage budget, free-space gate on enqueue, Members-tab unsynced-count badge, server `/app/config` media capability probe (WebP vs JPEG fallback) |
| **Admin conflict review** | ✅ | `d9769d34` + `89ed28f2` | `admin/photo_review.php` maroon/gold side-by-side picker; `api_photo_candidates.php` list+decide API (transactional accept/reject with audit trail); `photo_importer` least-privilege role in `access_control.php` |
| **P6 Batch capture** | ✅ | `9a0f1539` | `photo_batch_capture_screen.dart` — roster walk, auto-advance to first missing, gold/grey/maroon status dots, skip/view-details, done dialog; Take-Photo + Batch buttons on member detail; Batch button on member list |
| **Z1 ZIP export** | ✅ | `9a0f1539` | `PhotoZipExportService` — store-only ZIP, canonical manifest.json (sorted keys), per-file SHA-256, HMAC-SHA256 manifest.sig (per-install key; server-paired upgrade in P7), share_plus share sheet; Sync Panel Export button now live; app v1.8.1+44 |
| **Z2-Z5 Server import** | ✅ | `9c941216` | `api_photo_zip_import.php` — upload → extract → manifest parse → per-file validate → `PhotoUploadService::ingestFromFile` (reuses receipt/candidate/conflict pipeline); archive ZIP for audit; `photo_import.php` drag-and-drop admin page with recent-batches table |
| **P7 hardening (partial)** | ✅ | `0878cc98` | Queue/exports moved to app-support dir (no iCloud backup); LRU eviction of delivered files >30 days; no-cloud-backup method channel reserved; `photo_importer` role added to admin user-management dropdown |
| **P7 HMAC pairing + undo** | ✅ | `4b7d8809` | Per-device HMAC key paired on first photo upload and persisted client-side; ZIP export signs with paired key (falling back to per-install); ZIP importer verifies server_paired signatures and surfaces per-install warnings honestly; capture screen Retake flows through proper retake/replaced_by semantics. The save/undo affordance now lasts 2 seconds. |
| **P7 Android foreground service** | ✅ | `89bc2736` | Started/updated/stopped via MethodChannel from PhotoSyncWorker; ongoing maroon notification with progress/count/ETA and Stop action; dataSync foreground-service type; survives screen-off uploads; auto-stops on drain-complete or swipe-kill |
| **P7 iOS iCloud backup exclusion** | ✅ | `89bc2736` | AppDelegate sets `NSURLIsExcludedFromBackupKey` on queue/exports dirs via `app.fkss.photo/nocloud` MethodChannel; Android side already excluded by `android:allowBackup="false"` |
| **P7 flaky-network soak tests** | ✅ | `89bc2736` | `test/photo_flaky_network_soak_test.dart` with deterministic FakeOutbox/FlakyApi/FakeSyncWorker: idempotent replays, exponential-backoff under 503, 60%-failure chaos drain, mid-drain restart double-delivery, orphan reconciliation, 2 MB cap |

## File map (committed)

### Mobile (Flutter)
- `lib/services/local_schema_v43.dart` — DDL for 4 outbox tables
- `lib/services/photo_outbox_models.dart` — enums/entry/counters/policy models
- `lib/services/photo_outbox_service.dart` — SQLite CRUD + retake/claim/policy + orphan reconciliation + free-space guard + `memberIdsWithPhoto()` roster helper
- `lib/services/photo_capture_service.dart` — captured-image handoff and durable offline enqueue
- `lib/services/photo_image_processor.dart` — EXIF-aware native crop rendering and native JPEG size/quality retry loop
- `lib/services/photo_sync_worker.dart` — FIFO ChangeNotifier drain
- `lib/services/photo_zip_export_service.dart` — Z1 signed ZIP packaging + share
- `lib/services/api_service.dart` — `uploadMemberPhoto()` multipart
- `lib/screens/members/photo_camera_screen.dart` — in-app camera preview, framing guide, focus, lighting, and zoom controls
- `lib/screens/members/member_photo_capture_screen.dart` — single capture and review
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

## Remaining (post-P7 polish — none blocking production field use)

1. **POST_NOTIFICATIONS runtime prompt on Android 13+** — foreground-service notifications show without the permission on Android 12-; on Android 13+ users see a generic "app running" notification until they grant POST_NOTIFICATIONS. Prompt on first sync start if we get reports of missing notifications.
2. **Notification-tap opens Sync Panel** — tapping the notification currently brings the task to foreground; deep-linking straight to the Sync Panel requires the same route plumbing used by the app's notifications system. Nice-to-have.
3. **Physical-device photo QA** — validate focus/exposure/flash controls, framing, capture latency, and print quality on a low-end Android phone and an iPhone.

## Test status

- **PHP well-formedness (security suite):** 8 passed / 384 subtests (green after every commit).
- **Dart/Flutter:** Targeted `flutter analyze` completed with existing info-level lints and no compile errors; `test/photo_outbox_p1_foundation_test.dart` passed (7 tests); Android debug APK built successfully. Physical-device QA remains outstanding. The soak test `test/photo_flaky_network_soak_test.dart` covers idempotency, backoff, chaos drain, restart double-delivery, orphan reconciliation, and the 2 MB cap.
