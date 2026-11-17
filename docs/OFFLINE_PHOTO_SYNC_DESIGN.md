# Offline Member Photo Capture & Sync — Deep Analysis & Specification

**System:** FKSS Sunday School Management System (Flutter mobile app + PHP/MySQL backend)
**Use case:** Field HR/Info staff photograph 300–400+ member profile/ID pictures in areas with unstable/absent internet. Photos must reach the server reliably — no corruption, no loss, no duplicates, no silent discard, and the queue must survive app kills, device reboots, storage pressure, and very slow 2G/EDGE when connectivity does return.

---

## 1. Existing Infrastructure (what's already built that we can reuse — DO NOT rebuild)

Auditing `Mobile/wbws_flutter_app/`:

| Component | Status | Notes |
|---|---|---|
| Flutter SDK 3.27+, single Dart codebase | ✅ | iOS/Android parity already in place |
| `sqflite` (SQLite) for offline cache + outbox | ✅ | Schema v34, migration framework in place (`local_db.dart`, 7752 LOC) |
| Durable outbox pattern for attendance packets | ✅ | `pending_attendance`, `pending_grades` use `client_op_id` idempotency keys, retry/backoff, `retry_wait` state |
| `Idempotency-Key` header support in ApiService | ✅ | `X-Idempotency-Replayed` detection already wired (`api_service.dart:774-786`) |
| HTTP resume-capable **download** manager | ✅ | `MezmurDownloadManager` uses HTTP `Range: bytes=N-`, `.part` files, sha256 integrity, concurrency cap (2), retry policy (4 attempts) — proven pattern we should mirror for uploads |
| `camera: ^0.12.2` | ✅ | Flutter-maintained in-app preview; uses the endorsed CameraX implementation on Android |
| `image_picker: ^1.1.2` | ✅ | Still used for profile & mezmur cover gallery selection |
| `connectivity_plus` network awareness | ✅ | Online/offline gating exists |
| `flutter_secure_storage` for auth tokens | ✅ | JWT already persisted securely |
| `path_provider` for app-private storage | ✅ | Used by hymn downloads |
| `permission_handler` | ✅ | Already handles runtime permissions |
| `AppLifecycleState.resumed` outbox kick | ✅ | `CommOutboxService` auto-kicks sync on foreground |
| Multipart upload (existing profile image) | ⚠️ partial | Single-shot `http.MultipartRequest`, 60s timeout, **no resume, no queue, no offline** |
| Backend file ingest (`MemberFileService::storeRequestUpload`) | ✅ | Accepts PSR-7 uploaded files, size validation, MIME validation, atomic rename, ext MIME detection |
| Private file serving (`member_file.php`) | ✅ | Auth-gated, MIME-safe, no path traversal |
| iOS/Android camera permission strings | ⚠️ partial | iOS has `NSCameraUsageDescription` for QR scanning already; we'll re-use it. Android camera permission also already in manifest (QR scanner uses it) |
| EXIF orientation / image resizing | ❌ missing | Existing upload uses raw `maxWidth/maxHeight/imageQuality` in image_picker; no explicit EXIF rotation fix |
| Resumable upload protocol on server | ❌ missing | No `Content-Range` / TUS / chunk endpoint exists today |
| Member photo capture screen on mobile | ❌ missing | No "capture member photo" flow in `lib/screens/members/` |
| Bulk-capture workflow (roster-based "photograph these 400 members one after another") | ❌ missing | Would be a massive UX win for the field scenario |
| Photo upload outbox table | ❌ missing | Only `comm_outbox` and attendance/grades outboxes exist |

**Key conclusion:** The app already has a production-proven offline queue (attendance) and a production-proven resumable download pattern (hymns). **We should not bring in a new third-party sync framework.** We extend the same primitives to photos.

---

## 2. Research — How Mature Apps Solve This Problem

Patterns surveyed:

| App / Library | Approach | What works | What fails |
|---|---|---|---|
| **WhatsApp / Signal** | Outbox in SQLite (msg table), per-message state: pending → sending → sent/failed; resumable uploads via binary chunk protocol; at-least-once with server dedupe by message id | Survives reboot, crashes, airplane mode | End-to-end encryption is overkill for us; custom binary protocol too heavy |
| **Google Photos** | Chunked resumable upload (Google's "resumable upload" protocol — similar to TUS), background worker with explicit Wi-Fi/cellular policy, content-hash integrity | Works on flaky networks, survives app kill, pauses when metered | Proprietary protocol, but pattern is open |
| **TUS (tus.io)** | Open protocol for resumable uploads (`PATCH` with offset, `Upload-Offset`, `Upload-Length`, `Upload-Metadata`) | Mature, has Flutter and PHP server libraries, handles pause/resume, server state, HEAD to query offset | Adds a dependency; requires server-side storage of partial uploads (tmp files); one more thing to operate |
| **Open Food Facts / CommCare (ODK Collect)** | App-side compression + resizing to ~300–600 KB JPEG, SQLite queue of (compressed file path, metadata), FIFO worker, multipart POST with retry/backoff, idempotency key | Simple, no server protocol changes; works at photo sizes needed for ID cards | Whole-file retry means a failed 500 KB photo restarts; acceptable for <1 MB files; NOT acceptable for raw 5–10 MB photos |
| **Firebase Storage / Supabase Storage** | Built-in resumable + retry SDKs | Battle-tested | Locks us into a third-party service; user specifically has unstable internet to **our server**, not GCS; we are self-hosted |
| **WorkManager (Android) / BGTaskScheduler (iOS)** | Background execution guarantees for upload workers | Can finish uploads when app is backgrounded/killed | iOS BGTask is opportunistic (OS decides when to run) — unreliable; Android Doze/app standby restrictions are increasing; adds complexity |
| **Flutter `camera` plugin (CameraX / AVFoundation)** | In-app preview with focus, zoom, flash, exposure, and a framing overlay; use the Flutter-maintained federated plugin and CameraX implementation on Android | Direct camera controls and an on-device guide; avoids an external camera-app handoff | App owns camera lifecycle, permissions, and device-specific control errors |

**Recommendation for FKSS:**

A **hybrid approach** tuned to high-quality ID-card photo sizes (see §3 below for updated spec):

1. **Quality-first client-side encoding**: capture at a device-tier-appropriate camera resolution, apply orientation, center-crop to 3:4 (passport ratio) with Flutter's native renderer, then use native JPEG compression. JPEG is selected for broad print-shop and screen compatibility. Output targets are 960×1280 on low-memory devices, 1200×1600 on mid-tier devices, and 1536×2048 on high-tier devices, with measured downscaling/quality steps under the existing 2 MB cap. JPEG is lossy; this is a deliberate size/compatibility tradeoff, not a promise of mathematically lossless compression.
2. **Whole-file multipart upload with idempotency** (not chunked/TUS) — the client caps images at 2 MB; whole-file retry remains bounded and protected by the existing idempotency key.
3. **SQLite outbox** exactly mirroring the existing `pending_attendance` pattern: `photo_outbox(client_op_id, member_id, field, local_file_path, sha256, byte_size, state, attempts, next_attempt_at, last_error, server_path, created_at)`.
4. **Durable worker** (Foreground Service on Android when the user taps "Start syncing", ordinary foreground on iOS) — FIFO with concurrency=2, exponential backoff (15s/30s/60s/2m/5m/15m/1h, capped at 24h), idempotency keys, network class gating (fail fast on "none").
5. **Captured photos stored in app-internal storage** (NOT temporary cache; NOT user gallery) — survives reboot, cleared only on confirmed server success or explicit user discard.
6. **Server-side idempotency endpoint** `POST /api/v1/members/<id>/photo` that accepts `Idempotency-Key`, looks up whether it already has the photo with that `client_op_id`+sha256 and returns 200 with the stored path if so; stores partial-upload as temp file (single-shot, no chunking needed at our size).
7. **Batch capture UI** — a roster of members with thumbnail + status (pending/capturing/captured/uploaded/failed), swipe/tap to photograph each, auto-advance, overlay framing guide, retake per member, one big "Sync all" button.

This is **far simpler and more reliable** than bringing in TUS or chunked upload, because:
- Each photo is encoded under the existing **2 MB hard cap** before queueing.
- 400 photos × 2 MB is a worst-case **800 MB** payload; actual JPEG sizes depend on image detail and device tier.
- Re-sending an image under the 2 MB cap from scratch after a network failure is bounded and remains compatible with the existing idempotent retry path.
- We reuse 100% of the existing outbox/idempotency/retry patterns the app already uses for attendance — one less new concept for maintenance.

We add a **manual "retry failed" button** and a visible progress screen (photos done / total / MB uploaded / MB remaining / estimated time) so the field operator always knows exactly what state they're in.

---

## 3. The Photography Rules — Why Smaller Is Better

### 3.1 Target sizes

## 3. The Photography Rules — Quality First, Size Second

These photos are used for **printed ID cards, report cards, diplomas, yearbooks, and on-screen profile displays**. Preserve print-ready resolution while keeping compression quality and file size measurable.

### 3.1 Why high-quality JPEG with tier-aware print resolutions

| Use case | Required resolution (300 DPI) | Mid-tier output (1200×1600) covers? |
|---|---|---|
| Standard ID card (25×35 mm) | 295×413 px | ✅ 4× headroom |
| Large staff/student ID badge (40×50 mm) | 472×591 px | ✅ 2.5× headroom |
| Report-card profile photo (30×40 mm) | 354×472 px | ✅ 3× headroom |
| Diploma / certificate portrait (50×65 mm) | 590×768 px | ✅ 2× headroom |
| A5 / A4 yearbook photo (60×80 mm) | 709×945 px | ✅ 1.7× headroom |
| On-screen admin / mobile profile (≤200×250 px) | 200×250 px | ✅ 6× headroom |

The `image` package's current `encodeWebP` API is lossless-only and does not accept a quality value. It therefore cannot implement the earlier documented lossy-WebP q=92 target and may produce larger files. The capture pipeline uses native JPEG compression at quality 90/88, checks the actual encoded size, and reduces dimensions only as needed. JPEG is supported by common print workflows as well as screens. Since JPEG is lossy, the quality/size cap remains an explicit tradeoff rather than “no quality loss.”

**We do NOT go above 2 MB** per file:
- 400 members × 2 MB = 800 MB total payload → ~25 min on typical ET 3G (~500 KB/s) → acceptable.
- Anything larger than 2 MB hurts sync reliability on bad networks for no visible print benefit at our use cases.
- Hard cap enforced on client (encode-then-measure; lower quality from q=90 to q=88, then downscale in 3:4-proportioned steps to 800×1066) and server (reject >2 MB with `413 file_too_large`).

### 3.2 Camera capture parameters

| Parameter | Value | Reason |
|---|---|---|
| Capture resolution | Device tier selects `high`, `veryHigh`, or `max`; the camera implementation selects the closest supported camera mode | Keep preview/capture work proportionate to device memory and capability |
| Output resolution after resize | **960×1280 / 1200×1600 / 1536×2048 px portrait (3:4)** for low/mid/high tiers, reduced if the 2 MB cap requires it | Passport-ratio output maintains print-ready detail on low-tier phones while allowing larger high-tier outputs |
| Primary format | **JPEG**, quality **90**, with a quality-88 and measured downscale fallback | Broad compatibility with common print software and screens |
| Face/skin processing | No face retouching or smoothing; use camera exposure compensation, flash, and focus controls | Improve lighting while preserving identity-photo fidelity |
| Color space | Platform-managed color conversion; no forced profile or face retouching | Consistent print/display color while preserving the captured appearance |
| EXIF | Apply orientation to pixels and strip source metadata during re-encoding | Avoids sideways photos and prevents source GPS/EXIF metadata from being retained |
| Quality-control encode loop | Encode at the device-tier target/q90 → measure bytes; retry q88, then downscale in 3:4-proportioned steps to 800×1066 if necessary | Enforces the 2 MB cap and returns an explicit error if even the minimum is too large |
| File naming | `photo_{member_id}_{field}_{client_op_id}.jpg` for camera captures | Deterministic for debug; extension reflects actual format |
| Storage location | `getApplicationDocumentsDirectory()/photo_queue/` (iOS: NSDocumentDirectory with `NSURLIsExcludedFromBackupKey`=YES so queued photos don't fill iCloud; Android: `filesDir` not cacheDir) | Survives reboot; NOT auto-cleared by OS |
| Max disk budget | **4 GB** hard cap (≈ 2000 photos at 2 MB each) — LRU evict only fully-synced photos older than 30 days | Defensive against filling device; 4 GB is safe for any modern phone |

### 3.3 Photo requirements (what to show as overlay guide on camera screen)

- Face centered, eyes near the guide line (standard passport/ID framing)
- Even lighting, no harsh shadows on face
- No sunglasses, no hats (except religious head covering)
- Plain light-colored wall background if possible (visual guide only, not enforced)
- Review screen shows the photo at full capture resolution with **pinch-to-zoom** so staff can verify eye/focus sharpness before accepting
- ✓ Accept adds to queue; ↺ Retake discards the current capture and re-opens the shutter (see §3.6 retake semantics)

### 3.4 Permission rules (already mostly in place)

- iOS `NSCameraUsageDescription` — **already present**; update text to: *"The camera is used to take high-quality member profile and ID photos. Photos stay in your private queue until you choose to sync or export them."*
- iOS `NSPhotoLibraryAddUsageDescription` — **NOT needed** by default (we don't save to gallery); added only if user enables an opt-in "Save a copy to my photos" toggle.
- iOS `NSPhotoLibraryUsageDescription` — **already present** (gallery picker).
- Android `CAMERA` permission — already declared via mobile_scanner/QR. Confirm for direct camera access.
- Android `WRITE_EXTERNAL_STORAGE` — **NOT needed** on Android 10+ (scoped storage; app-private dir).

### 3.5 Legal / privacy rules

- Photos are **PII (biometric-adjacent)**.
- **Sync is OFF by default** (see §7.4): the app will NEVER upload any photo unless the user explicitly enables sync AND taps "Start Sync". The default capture-only workflow produces ZIP files that staff hand in via USB. No silent data usage.
- When sync IS enabled, the default is **Wi-Fi only**; cellular upload requires a second explicit opt-in with a MB-warning dialog.
- Photos in the outbox are in app-private storage, not readable by other apps.
- Logging never logs full file paths or includes photo bytes.
- Server stores photos outside web root (already true per `MemberFileService`).
- Server rejects non-image files and files >2 MB, and re-encodes suspicious uploads as a safety net.
- A "Delete unsynced photos" / "Sign out and discard" action must exist.

### 3.6 Retake semantics (user-mandated)

When a staff member photographs a member they have already captured (same `member_id` + `field`) **on the same device**:

1. The previous queued photo for that `(member_id, field)` is immediately transitioned to state **`discarded`** (NOT deleted from disk until the batch is exported or sync completes, so an accidental retake can be undone for ~30 seconds via an "Undo retake" toast).
2. The new photo replaces it as the current queued item.
3. The roster row briefly shows "↺ retaken" then returns to "🟡 queued".
4. If the previous photo was **already synced** (state=done), retaking replaces it locally and the new photo uploads as a fresh entry; server receives the newer `taken_at` and applies the conflict rule (§5).
5. Retakes do NOT produce duplicate ZIP entries — only the current (last) photo is included in `manifest.json`; discarded photos are listed in an optional `discarded: []` manifest section for audit only and are NOT packed into the ZIP.

**Same-photographer retakes always mean "the latest capture wins" locally.** Multiple photographers photographing the same member is an admin-conflict case (§5.4) and is resolved in the web review screen, not on the phone.

---

## 4. Failure Modes to Design Against (from your field description)

For 300–400 members photographed offline over several hours/days, these are the real-world failure cases and how we handle each:

| # | Failure | Defense |
|---|---|---|
| F1 | **App killed mid-capture** | Photo saved to disk BEFORE the database row is committed; SQL transaction inserts outbox row in the same step as file rename; app restart scans queue directory and reconciles any orphan files |
| F2 | **Device reboot mid-queue** | Outbox rows in SQLite persist; worker auto-kicks on app next open and on connectivity restored; no in-memory-only state |
| F3 | **Accidental discard by user** | "Discard" requires confirmation; already-captured thumbnails visible in roster; "undo discard" available until sync starts |
| F4 | **Server returns 500 / timeout mid-upload** | Whole-file retry on exponential backoff; idempotency key prevents duplicate files on server if the success response was lost but the file was stored |
| F5 | **Server receives the photo but the response is lost (network drops between send and response)** | Idempotent retry: server returns `200 {status:'success', path:'...', replayed:true}` on second attempt |
| F6 | **Two field staff (or one staff + ZIP import) photograph the same member** | Server keeps ALL incoming photos in a `member_photo_candidates` table (keyed by member_id + client_op_id) and flags a conflict; web admin review screen lists each candidate side-by-side with photographer name, device, taken_at, sha256, file size; admin picks the winner (or rejects all). The winning photo becomes `student_photo_path`; losers are retained in a `photo_archive` folder for audit. Same-device retakes (§3.6) never produce a conflict because the earlier shot is `discarded` before upload. |
| F7 | **Corrupted / zero-byte file / non-image bytes** | Empty files rejected before processing; Flutter's image codec decodes the captured image and the native compressor produces a fresh JPEG before enqueue; post-upload server verifies magic bytes + GD/Imagick `imagecreatefromwebp/jpeg` succeeds and returns 400 if not |
| F8 | **Photo is sideways (EXIF orientation)** | Client reads EXIF, rotates pixel data before saving queue file; all queued photos are orientation-corrected before upload |
| F9 | **Out of disk space on phone** | Pre-capture check: if free space < 200 MB, block capture with a clear message; LRU-evict already-synced photos older than 30 days automatically |
| F10 | **Photos sent over metered / expensive connection by mistake, exhausting a user's data plan** | **Sync is OFF by default at install.** User must (1) flip a master "Enable photo sync" toggle in Settings AND (2) tap an explicit "Start Sync" button on the Sync Panel AND (3) either be on Wi-Fi OR flip a secondary "Allow sync over cellular" toggle with a MB-cost warning. No auto-sync on any network by default. A persistent banner "⚠ Sync is OFF — photos are saved locally only" is shown on the roster until sync is enabled or a ZIP is exported. |
| F11 | **Staff member loses phone (queued photos never sync)** | Server never sees them (acceptable — this is a device-loss scenario, not a sync bug). Recommend staff sync at end of each day. No sensitive metadata leaves device except the photo bytes. |
| F12 | **App version upgrade wipes database** | Database migrations are additive and tested; we add `photo_outbox` in schema v35 with the same migration pattern already used |
| F13 | **Backend changes member record while photo is in queue** | On upload, server resolves member by current ID; if member is archived, returns `410 {code:'member_archived'}` → UI shows "archived" chip and lets user dismiss |
| F14 | **Auth token expires mid-sync** | Existing `_refreshAfterUnauthorized` already handles refresh; queued photos use the same durable retry as attendance |
| F15 | **Huge backlog (400 photos) overwhelms server** | Client caps concurrency at 2 parallel uploads (mirrors hymn downloader); server accepts idempotent replay cheaply |
| F16 | **User force-quits during upload** | `try/finally` updates state before returning; half-uploaded files are discarded client-side (whole-file retry); server detects incomplete upload by size mismatch and discards temp; next retry starts fresh (at ~1.5 MB = ~3s loss on 3G, ~15s on EDGE — acceptable given idempotency) |
| F17 | **Wrong photo attached to wrong member** | Capture screen shows large member name + code + existing photo (if any) before shutter; review screen shows thumbnail + name + retake before confirming queue; mismatch is the #1 field error and UX must prevent it |
| F18 | **Lighting is so bad photo is unusable** | UI shows a preview immediately after capture (1–2 seconds); user can retake on the spot; optional server-side blur/underexposure detection can flag after sync |

---

## 5. Server-side API Design

### 5.1 New endpoint

```
POST /api/v1/members/{id}/photo
Authorization: Bearer <staff JWT>
Content-Type: multipart/form-data
Idempotency-Key: <uuid4>      # required — client_op_id
X-Photo-Field: student_photo  # or guardian_photo (future: doc_school_records, etc.)
X-Photo-SHA256: <hex>         # hex sha256 of file bytes (lowercase)
X-Photo-Taken-At: 2026-11-16T09:42:00+03:00  # ISO 8601, when shutter was pressed
X-Photo-Capture-Device: <device model>        # optional telemetry
X-Photo-Operator-Id: <admin_id>               # the staff member whose device captured this (from JWT, verified)
X-Photo-Device-Id: <device_id>                # per-install UUID generated at first launch
X-Photo-Format: webp | jpeg                   # declared content format
Body: multipart file part "photo" (WebP/JPEG, ≤ 2 MB)
```

Responses:

| Status | Meaning | Body |
|---|---|---|
| 200 OK (first time, accepted as current) | Stored and set as member's current photo | `{status:'success', path:'.../uploads/...webp', replayed:false, conflict:false}` |
| 200 OK (first time, conflict queued for review) | Stored as candidate; another newer/different photo already exists | `{status:'success', path:'...', replayed:false, conflict:true, conflict_reason:'newer_candidate_exists', pending_review:true}` |
| 200 OK (idempotent replay) | Already stored | `{status:'success', path:'...', replayed:true}` |
| 400 Bad Request | Not a valid WebP/JPEG / too large / missing fields / sha256 mismatch | `{status:'error', message:'...', code:'invalid_image'}` |
| 401 / 403 | Auth | (existing handling) |
| 404 | Member not found | `{status:'error', code:'member_not_found'}` |
| 409 Conflict (DEPRECATED as auto-reject — replaced by 200+conflict:true) | — | — |
| 410 Gone | Member archived | `{status:'error', code:'member_archived'}` |
| 413 | File too large (>2 MB) | `{status:'error', code:'file_too_large', max_bytes:2097152}` |
| 415 | Unsupported media type (not WebP/JPEG) | `{status:'error', code:'unsupported_format', accepted:['image/webp','image/jpeg']}` |
| 429 | Rate limited | `Retry-After: <seconds>` |
| 5xx | Transient server error | Client retries with backoff |

### 5.1.1 Multi-photographer conflict handling (key change from v1 spec)

When the server receives a photo for a member who already has a non-archived current photo:

1. The new photo is ALWAYS persisted to a `member_photo_candidates` table (never silently discarded, never auto-overwrites).
2. The server compares `taken_at` timestamps:
   - If the new photo is **older** than the current photo AND from a **different operator** → stored as candidate, returns `conflict:true`, does NOT replace current.
   - If the new photo is **newer** than the current photo AND from a **different operator** → stored as candidate, returns `conflict:true`, does NOT auto-replace (admin decides).
   - If the new photo has the **same client_op_id** (replay) → handled by idempotency, no conflict.
   - If the new photo has the **same operator_id** AND is newer (same photographer retake after sync) → auto-replaces current (photographer's own retake wins without review).
3. The web admin dashboard's **Photo Review** page (or the existing member edit screen) lists all candidates per member with photographer/device/timestamp; admin picks "Set as current" for the winner; other candidates move to archive.
4. Photos from ZIP imports go through the same candidate table — same UI, same conflict resolution.

This guarantees: **a photo from a second photographer never silently clobbers a photo from the first**, and the admin always sees all options to pick the best one.

### 5.2 Server tables (new/updated)

- `photo_upload_receipts(client_op_id PK, member_id, field, sha256, path, operator_admin_id, device_id, format, byte_size, taken_at, created_at)` — idempotency ledger, records every accepted upload regardless of whether it's the current winner.
- `member_photo_candidates(id PK, member_id, field, receipt_id, operator_admin_id, device_id, sha256, path, taken_at, is_current BOOLEAN, decision_pending BOOLEAN, archived BOOLEAN, created_at, decided_at, decided_by_admin_id)` — every candidate photo; `is_current=true` for exactly one per (member_id, field) at any time.
- The `members.student_photo_path` column becomes a **denormalized cache** pointing at the current candidate's path; updates happen inside the same transaction that flips `is_current` flags.

### 5.3 Photo storage on server

Reuse `MemberFileService::storeRequestUpload` with a new field map key `student_photo_mobile`. Save under `uploads/members/photos/yyyy/mm/{member_id}_{ts}.webp` (or `.jpg`). MIME detection must accept `image/webp` and `image/jpeg`. If client sends WebP but GD reports the server cannot read WebP on this host, return a clear `501 webp_not_supported_on_server` so the client can fall back to JPEG q=92 on retry.

### 5.4 Back-of-the-envelope capacity

- 2 MB × 400 members × (say) 3 staff = **2.4 GB per photo drive** — trivial for a server, and candidates can be auto-archived (moved to cold storage) 6 months after a decision.
- PHP `post_max_size = 16M, upload_max_filesize = 4M` (raise to accommodate the 2 MB per-file cap with headroom for multipart overhead).

---

## 6. Client Data Model

### 6.1 New SQLite table `photo_outbox` (schema v35 migration)

```sql
CREATE TABLE photo_outbox (
  client_op_id   TEXT PRIMARY KEY,          -- uuid4, sent as Idempotency-Key
  member_id      INTEGER NOT NULL,
  field          TEXT NOT NULL,             -- 'student_photo' | 'guardian_photo'
  local_path     TEXT NOT NULL,             -- absolute path in app-private dir
  sha256         TEXT NOT NULL,             -- lowercase hex sha256 of file bytes
  byte_size      INTEGER NOT NULL,
  format         TEXT NOT NULL,             -- 'webp' | 'jpeg' (actual encoding used)
  width          INTEGER NOT NULL,
  height         INTEGER NOT NULL,
  taken_at       TEXT NOT NULL,             -- ISO 8601, when shutter pressed
  operator_admin_id INTEGER,                -- from JWT at time of capture (null until login-confirmed)
  device_id      TEXT NOT NULL,             -- per-install UUID generated at first launch
  replaced_by    TEXT,                      -- client_op_id of the photo that replaced this via retake (so we can undo)
  state          TEXT NOT NULL DEFAULT 'queued',
                                               -- queued | uploading | done | failed | discarded | conflict_pending
  attempts       INTEGER NOT NULL DEFAULT 0,
  next_attempt_at TEXT NOT NULL,            -- ISO 8601 for retry backoff
  last_error     TEXT,
  last_http_code INTEGER,
  server_path    TEXT,                      -- filled when state in (done, conflict_pending)
  conflict       INTEGER NOT NULL DEFAULT 0,-- boolean flag if server asked for admin review
  created_at     TEXT NOT NULL,
  updated_at     TEXT NOT NULL
);
CREATE INDEX idx_photo_outbox_state ON photo_outbox(state, next_attempt_at);
CREATE INDEX idx_photo_outbox_member ON photo_outbox(member_id, field);
CREATE INDEX idx_photo_outbox_device ON photo_outbox(device_id);
```

**New states:**
- `conflict_pending` — server accepted the photo but flagged it as a candidate requiring admin review; the local photo is considered "delivered" (safe to remove after retention) but not yet "published" as the member's current photo. The UI shows ⚠️ "Photo sent — awaiting admin review" rather than 🟢 synced.

### 6.2 Queue states (mirrors attendance outbox)

```
queued  ──► uploading ──► done
               │
               └─► retry_wait ──► queued
                     │
                     └─► failed  (after MAX_ATTEMPTS = 12 over ~24 hours)
```

Additional user-facing states for UI:
- `captured` — photo taken, review confirmed, enqueued (same as `queued`)
- `synced` — same as `done`
- `needs_permission` — camera permission not yet granted (in-memory only)

### 6.3 Client module layout

```
lib/services/
  photo_capture_service.dart      # camera open, EXIF rotation, compression, enqueue
  photo_outbox_service.dart       # SQLite persistence (mirrors CommOutboxService pattern)
  photo_sync_worker.dart          # FIFO upload pump with backoff, concurrency=2
  photo_upload_policy.dart        # wifi_only, cap_mb, concurrency
lib/screens/members/
  member_photo_capture.dart       # camera screen with framing overlay, retake, confirm
  member_photo_batch.dart         # roster screen showing each member's status
  photo_sync_panel.dart           # upload progress dashboard (Done/Total/MB/ETA)
  photo_queue_screen.dart         # review errored/queued items, retry, discard
```

---

## 7. User Experience Flow (field-staff oriented)

### 7.1 "Capture day" mode

1. Staff opens the app → Members → **📸 Photo Capture Day**
2. App shows list of active members with filter (by section/class/all), each row showing:
   - Name + code + section
   - Thumbnail (existing photo if any; dashed placeholder if none)
   - Status dot: ⚪ not yet / 🟡 in queue / 🟢 synced / 🔴 failed
   - Large "📷 Capture" button
3. Staff can start anywhere, or tap "Start from first missing" — auto-advances.
4. Camera opens with:
   - Passport-style framing overlay (oval/rectangle for head/shoulders)
   - Name + code at top so staff confirms correct member
   - Big shutter button, retake button, flash toggle, rotate-camera toggle
5. After shutter: review screen shows the photo 1 second, with ✓ Accept and ↺ Retake. Accept saves compressed file to queue and advances to next member.
6. Progress counter at top: "127 / 382 photographed · 0 synced · 26 MB queued".
7. Back at the roster, tapping ⚙ Sync button opens the **Sync Panel**.

### 7.2 Sync Panel

- **Master state at top** — one of:
  - ⚪ **Sync is OFF** (default after install) — "Photos are saved locally only. Use Export ZIP, or enable sync below to upload."
  - 🟡 **Sync is ON · Waiting for Wi-Fi**
  - 🟢 **Sync is ON · Online (Wi-Fi)** or **Online (Cellular)** with warning.
  - 🔴 **Offline — queued locally**.
- **Settings row (persistent, not hidden):**
  - Toggle: `[ ] Enable photo sync` (default OFF). Requires confirmation: "Photos will be uploaded when you tap Start Sync. This may use data."
  - Sub-toggle (visible only when sync is ON): `[ ] Allow sync over cellular (not recommended — may use your data plan)` (default OFF).
- **Summary card:** 127 queued · 0 done · 0 pending review · 238 MB total · estimated ~8 min on Wi-Fi · 42 min on 3G.
- **Two big action buttons:**
  - 📦 **Export ZIP file** (always available, works offline) → saves a copy to public Downloads/FKSS (Android, for USB handoff) and opens the share sheet (see ZIP_IMPORT_DESIGN).
  - ▶ **Start Sync** (enabled only when sync toggle is ON AND network is available per the policy).
- When **Start Sync** is tapped:
  - Foreground worker begins; per-photo progress bar with member name, X/127, current MB/s, ETA.
  - Pause / Cancel buttons available at any time.
  - Staff can background the app; Android uses a foreground notification (same pattern as mezmur playback); iOS keeps the upload alive while the app is foreground (iOS BGTask is opportunistic — we warn the user).
  - Errors show inline with retry button; "Retry all failed" button.
- On completion: summary screen — "125 photos synced as current · 2 sent for admin review (duplicate photographers) · 0 failed · 8m 14s".

### 7.3 Edge UX
- **Never** start syncing automatically — not on photo capture, not on app open, not on Wi-Fi join. Sync is a **user-initiated, explicit action**. Staff might be on a metered hotel Wi-Fi, or roaming, or near a data-cap limit; automatic uploads have caused real issues in similar deployments.
- **Do** show a persistent badge on the Members tab: `📸 N unsynced photos (sync OFF)` or `📸 N unsynced photos` depending on state, so staff can't forget.
- If storage < 500 MB: warning banner before capture. (Budget raised with the larger photo size.)
- If battery < 20% and not charging: warn before starting sync.
- After successful sync, keep the local file for 30 days (for quick retries/re-view) then LRU-evict.
- Photos that return `conflict:true` are shown as ⚠️ "Sent, awaiting admin choice" on the roster — they are not failures, they are just pending a human decision between two photographers' shots.

### 7.4 Default-off sync policy (user-mandated)

1. **Fresh install:** `sync_enabled = false`, `sync_cellular = false`. No upload ever.
2. **After capture:** photos are queued + ZIP-exportable. No background traffic.
3. **To upload:** staff must (a) open Sync Panel, (b) toggle Enable, (c) tap Start Sync.
4. **To use cellular:** staff must additionally toggle "Allow cellular" and confirm a "This may use up to ~800 MB of data for 400 photos — are you sure?" dialog.
5. **"Export ZIP" is always available, with or without sync enabled.** It is the primary, lowest-friction path when the field has no reliable internet — exactly as the user specified: "by default sync will be off, they will just give us the zip file."

---

## 8. Performance Sizing (realistic for Ethiopia)

Target file size is bounded by the 2 MB cap; actual JPEG sizes vary with scene detail and device tier. 400 photos are capped at 800 MB total.

| Connection | Effective upload speed | 1.5 MB photo | 400 photos total |
|---|---|---|---|
| Wi-Fi (5 Mbps up) | ~500 KB/s | up to 4 s | up to ~27 minutes |
| 4G LTE (typical ET) | 200–500 KB/s | up to 10 s | up to ~67 minutes |
| 3G (typical ET) | 50–100 KB/s | up to 40 s | up to ~267 minutes |
| EDGE (2G worst case) | 10–20 KB/s | up to 200 s | up to ~22 hours |
| Offline | 0 KB/s | ∞ | queued until connectivity (use ZIP export) |

This is why **ZIP export is the primary workflow** for rural/EDGE scenarios: on 2G the sync time is impractical (multiple hours per batch), so staff plug the phone into a PC and transfer via USB. On 3G+Wi-Fi, sync is usable when the photo-day batch is complete. The 2 MB cap is chosen because:
- It preserves enough detail for all print use cases (§3.1).
- Wi-Fi/4G sync remains fast enough.
- ZIP export over USB takes seconds regardless of network quality — the safety valve.
- Whole-file retry is still acceptable on 3G (re-sending one failed 1.5 MB photo = 15–30 seconds).

---

## 9. What We Will NOT Build (and why)

| Rejected approach | Reason |
|---|---|
| TUS / resumable chunked upload | Overkill at the current per-file cap; adds server complexity; not worth the maintenance cost. Revisit only if we later add multi-page document scans (> 5 MB). |
| Android WorkManager / iOS BGTaskScheduler background uploads | iOS BGTask is not guaranteed; Android WorkManager is being restricted in newer Android versions. Better UX: foreground sync with persistent notification when the user explicitly starts it; staff can keep the phone on a charger with the app open. Same pattern as the existing mezmur download manager. |
| Firebase / third-party BaaS | Would bypass your server; you already have the hardened API layer; adds data residency concern for a church. |
| Full raw-resolution camera (12 MP+ uncompressed) | 12 MP images require significant memory and processing; tier-aware decode and resize keeps the saved image under the 2 MB cap and avoids UI-thread blocking. |
| Auto-sync the second each photo is captured | User explicitly forbade this — must not burn mobile data silently. Sync is user-initiated; default off. |
| PNG (lossless) | Much larger than JPEG for continuous-tone portraits; use only for formats that require lossless transparency, not member ID photos. |
| Face detection / auto-crop on device | Nice-to-have v2; increases complexity and introduces mis-crop risk. v1 uses a framing overlay and lets the human frame it. |
| End-to-end encryption of photos | Photos are PII but are TLS-encrypted in transit and stored behind authenticated access on your server. E2EE would block admin/HR workflows (need to view photos in dashboard). |

---

## 10. Rollout Plan (what order to build, how much work)

| Phase | Work | Effort (engineer-days) | Risk |
|---|---|---|---|
| **P1 — Foundation** | Schema v35 migration with updated `photo_outbox` table (format, width/height, operator, device_id, replaced_by, conflict columns); per-install device_id generation; PhotoOutboxService (SQLite CRUD, idempotency keys, retry policy, retake/replaced_by semantics, discard+undo) modeled on comm_outbox; unit tests for queue state machine | 3 | Low |
| **P2 — Capture** | In-app `camera` preview with a passport-grade 3:4 framing guide (green frame, eye/shoulder level marks); tap-to-focus, flash/exposure/zoom controls; EXIF correction and 3:4 crop with Flutter's native renderer; native quality-controlled JPEG compression, tier-aware output dimensions, and a measured 2 MB cap; review/retake and offline enqueue | 5 | Medium — native camera lifecycle and image processing |
| **P3 — Backend** | `/api/v1/members/{id}/photo` endpoint with Idempotency-Key, sha256 verification, WebP/JPEG MIME validation, 2 MB cap; GD/Imagick WebP capability probe; **photo_upload_receipts + member_photo_candidates tables**; multi-photographer conflict detection (store as candidate, do not auto-overwrite; same-operator retake auto-wins); reuse MemberFileService for storage; add permission/routing; new admin Photo Review page (grid of candidates per member with "Set as current" action) | 5 | Medium — candidate review UI |
| **P4 — Sync worker** | PhotoSyncWorker (FIFO, concurrency=2, exponential backoff, connectivity gating, **sync-disabled-by-default gate**, cellular-vs-wifi policy gate, idempotent multipart with new headers (X-Photo-Format/Device-Id/Operator-Id), handle 200+conflict:true response, foreground notification on Android); ApiService.multipartUpload with idempotency key; JPEG fallback on 501 webp_not_supported | 4 | Medium |
| **P5 — UI** | Batch roster screen with per-member status dots (⚪/🟡/🟢/⚠️/🔴); **photo_sync_panel redesigned** with master sync-ON/OFF toggle, cellular toggle, Start Sync button, always-available Export ZIP button, progress/ETA, pause/cancel; photo_queue_screen for failures/retry/conflict_pending; persistent Members-tab badge; settings screen for sync prefs | 5 | Medium — default-off policy UI clarity |
| **P6 — Batch capture UX** | "Start from first missing" auto-advance mode; capture-day summary screen; storage-cap check (raised to 500 MB); battery warning; LRU eviction of synced photos older than 30 days | 3 | Medium |
| **P7 — Hardening & QA** | Orphan-file reconciliation; crash recovery; simulated-flaky-network tests; 400-photo soak test on real ET 3G; multi-photographer conflict end-to-end; WebP/JPEG fallback matrix; permission edge cases; dark mode; Amharic localization; verify "sync OFF + ZIP only" works end-to-end with zero network traffic | 4 | Important |
| **P8 — iOS-specifics** | NSCameraUsageDescription text update; iOS background-task awareness; iOS filesystem backup flags (don't sync pending photos to iCloud); TestFlight build | 2 | Medium (needs Mac) |
| **Total** | | **~31 engineer-days** (~6–7 weeks of one engineer, or ~3 weeks with two) | |

### Hard prerequisites
- Verify PHP GD/Imagick has WebP support on the production server (`imagewebp` exists); if not, install or enable (one apt command) — otherwise server falls back to JPEG and client must too.
- Apple Developer account + Mac (for iOS build, not needed to start Android-first).
- 1–2 test Android phones from the field (real low-end hardware).
- Sample photos from a real photo day to validate print quality across device tiers and q=90/q=88 JPEG output.

### MVP slice that delivers 80% of value in ~15 days
Ship P1+P2+P4+minimal P5 (no batch roster yet — single-member capture, ZIP export always available, sync toggle default-off, manual Start Sync, no candidate review yet — server auto-wins by newest taken_at; admin can swap photos manually via the existing member edit page). ZIP import (see ZIP_IMPORT_DESIGN) comes next; multi-photographer review UI rounds out v1.

---

## 11. Libraries to add

| Package | Purpose | Size / risk |
|---|---|---|
| `camera: ^0.12.2` | In-app camera preview, capture, focus, exposure, flash, and zoom controls | Flutter-maintained plugin; native camera implementations |
| `flutter_image_compress: ^2.5.1` | Native JPEG encoding with quality/size control | Native Android/iOS compressor; avoids the slow pure-Dart JPEG encoder |
| `image: ^4.5.3` | Queued-image thumbnail decoding | Pure Dart helper, not used to encode camera captures |
| `crypto: ^3.0.3` (already in pubspec) | SHA-256 + HMAC-SHA256 of queued files (HMAC also used for ZIP signing) | Already present |
| `uuid: ^4.0.0` (likely already transitive) | client_op_id + per-install device_id UUID generation | Trivial |
| `archive: ^3.4.0` (new for ZIP export) | ZIP packaging for export (store, no compression) | Pure Dart; low risk |

No face-beautification SDK, Firebase, TUS, or WorkManager.

---

## 12. What we are NOT changing about the existing system

- Attendance outbox, grades outbox, communications outbox — untouched.
- Mezmur download manager — untouched (we mirror its pattern, not its code).
- Backend `MemberFileService` — reused, only a thin endpoint added.
- Admin dashboard member management — the uploaded photo lands in the same `student_photo_path` column, so the new member profile UI we just redesigned shows the new photo automatically, with zero additional work.
- Auth, CSRF, rate-limiting — reused.
- Telegram, admin, attendance, reports — untouched entirely.
