import 'dart:async';
import 'dart:io';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter/services.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

import 'local_db.dart';
import 'photo_outbox_models.dart';

/// Owns all writes to `photo_outbox`, `photo_export_batches`,
/// `photo_device_identity`, and `photo_sync_policy`.
///
/// Responsibilities for P1 (foundation):
///   - Ensure/return the per-install device_id UUID.
///   - Seed the safe-defaults sync-policy row.
///   - Enqueue a newly-captured photo (file already written by the capture
///     service) with all metadata and a fresh client_op_id.
///   - Implement retake semantics (mark prior capture discarded with a
///     replaced_by pointer, undoable for ~30 seconds).
///   - Surface counters for the roster badge and sync panel.
///   - List due entries for the (later) sync worker.
///   - Transition entry state with proper attempt/backoff metadata.
///
/// The capture service and the sync worker are the only two intended
/// callers. The UI talks to this service only for reads (counters, lists)
/// and policy toggles.
///
/// P2 (camera + encode), P4 (sync worker), and Z1 (ZIP export) will add
/// methods here as they land; this file is the seam.
class PhotoOutboxService {
  PhotoOutboxService._();
  // Non-final so integration tests can reset the singleton between cases.
  // Production code always reads through PhotoOutboxService.instance.
  static PhotoOutboxService instance = PhotoOutboxService._();

  // ignore: unused_field (used for jitter; kept for future retry scheduling)
  final Random _random = Random.secure();
  final Uuid _uuid = const Uuid();

  /// Subdirectory under getApplicationDocumentsDirectory() where queued
  /// photo files live. Kept in app-private storage (survives reboot, not
  /// auto-cleared by OS, not readable by other apps). On iOS we additionally
  /// set NSURLIsExcludedFromBackupKey=YES so pending photos don't fill
  /// iCloud (handled by the capture service when it creates the file).
  static const queueSubdir = 'photo_queue';

  /// Subdirectory where completed ZIP exports live.
  static const exportsSubdir = 'photo_exports';

  String? _cachedDeviceId;
  String? _queueDirPath;
  String? _exportsDirPath;
  bool _initialized = false;

  /// Must be awaited before any other method. Safe to call multiple times;
  /// subsequent calls are no-ops after first success.
  Future<void> ensureInitialized() async {
    if (_initialized) return;
    final db = await LocalDb().database;

    // Create queue directory if it doesn't exist. We use the app-support
    // directory (not documents) so the photos aren't visible to the user
    // via iTunes file sharing and (on iOS) aren't uploaded to iCloud by
    // default — photo queues can be multiple GB and we don't want them
    // consuming the user's iCloud quota.
    final appDir = await getApplicationSupportDirectory();
    final qDir = Directory(p.join(appDir.path, queueSubdir));
    if (!await qDir.exists()) await qDir.create(recursive: true);
    _queueDirPath = qDir.path;
    final eDir = Directory(p.join(appDir.path, exportsSubdir));
    if (!await eDir.exists()) await eDir.create(recursive: true);
    _exportsDirPath = eDir.path;
    // Best-effort flag to tell the OS "don't back this up to cloud".
    // The method channel isn't registered yet on either platform (P7
    // polish); if the call fails (MissingPluginException) we silently
    // fall through — files are already in app-support which Android
    // excludes from backup by default via android:allowBackup=false for
    // the app's private dir, and iOS excludes app-support unless the
    // app declares the UIBackupModes "iCloud Documents" entitlement.
    await _markNoCloudBackup(qDir.path);
    await _markNoCloudBackup(eDir.path);

    // Ensure device_id row exists.
    final rows = await db.query('photo_device_identity', where: 'id = 1');
    if (rows.isEmpty) {
      final deviceId = _uuid.v4();
      final now = DateTime.now().toUtc().toIso8601String();
      await db.insert('photo_device_identity', {
        'id': 1,
        'device_id': deviceId,
        'photo_hmac_key': null, // set by server after first login (v2)
        'created_at': now,
        'updated_at': now,
      });
      _cachedDeviceId = deviceId;
    } else {
      _cachedDeviceId = rows.first['device_id'] as String;
    }

    // Ensure sync-policy row is seeded.
    final policyRows = await db.query('photo_sync_policy', where: 'id = 1');
    if (policyRows.isEmpty) {
      await db.insert(
        'photo_sync_policy',
        PhotoSyncPolicyState.seeded().toUpsertMap(),
      );
    }

    _initialized = true;

    // P7 hardening: reconcile orphan files (F1 defense). Runs once per
    // process start and is idempotent. Safe to call repeatedly.
    await _reconcileOrphans();
  }

  /// Orphan reconciliation (F1 from OFFLINE_PHOTO_SYNC_DESIGN):
  ///
  ///   1. Files in the queue directory that have no matching photo_outbox
  ///      row AND are older than 1 hour (to avoid racing an in-progress
  ///      capture) are deleted.
  ///   2. Rows in photo_outbox that reference a non-existent local file
  ///      and are not terminal (done/conflict_pending) are marked
  ///      `failed` with error code ORPHAN_FILE so the user sees them.
  ///
  /// Done/conflict_pending rows with missing files are left as-is —
  /// the server accepted them; the file was presumably cleaned up by
  /// LRU or uninstall, and the server is the source of truth.
  Future<void> _reconcileOrphans() async {
    try {
      final db = await LocalDb().database;

      // 2. Missing-file rows → failed.
      final knownPaths = <String>{};
      final rows = await db.query(
        'photo_outbox',
        where: 'state NOT IN (?, ?)',
        whereArgs: [
          PhotoOutboxState.done.storageValue,
          PhotoOutboxState.discarded.storageValue,
        ],
      );
      for (final r in rows) {
        final path = r['local_path'] as String;
        knownPaths.add(path);
        if (!await File(path).exists()) {
          await db.update(
            'photo_outbox',
            {
              'state': PhotoOutboxState.failed.storageValue,
              'last_error': 'File missing from local storage.',
              'last_error_code': 'ORPHAN_FILE',
              'updated_at': DateTime.now().toUtc().toIso8601String(),
            },
            where: 'client_op_id = ?',
            whereArgs: [r['client_op_id']],
          );
        }
      }

      // 1. Directory files with no matching row (safe cleanup).
      final dir = Directory(_queueDirPath!);
      await for (final entity in dir.list(followLinks: false)) {
        if (entity is! File) continue;
        if (!entity.path.endsWith('.jpg') &&
            !entity.path.endsWith('.jpeg') &&
            !entity.path.endsWith('.webp')) {
          continue;
        }
        if (knownPaths.contains(entity.path)) continue;
        // File must be older than 1 hour to avoid racing an enqueue.
        final age = DateTime.now().difference(
            await entity.lastModified());
        if (age < const Duration(hours: 1)) continue;
        try {
          await entity.delete();
        } catch (_) {}
      }
    } catch (_) {
      // Never crash startup for reconciliation failures.
    }
  }

  /// Per-install UUID that identifies this device for multi-photographer
  /// conflict attribution.
  Future<String> deviceId() async {
    await ensureInitialized();
    return _cachedDeviceId!;
  }

  /// P7 pairing: per-(operator,device) HMAC key returned by the server
  /// after first upload. Persisted in photo_device_identity so future
  /// ZIP exports can sign manifests with it. Returns the current key
  /// hex, or null if the server hasn't paired us yet.
  Future<String?> savedHmacKey() async {
    await ensureInitialized();
    final db = await LocalDb().database;
    final rows = await db.query('photo_device_identity',
        where: 'id = 1', limit: 1);
    if (rows.isEmpty) return null;
    final k = rows.first['photo_hmac_key'] as String?;
    return (k != null && k.isNotEmpty) ? k : null;
  }

  Future<void> saveHmacKey(String hexKey) async {
    if (hexKey.isEmpty) return;
    await ensureInitialized();
    final db = await LocalDb().database;
    await db.update('photo_device_identity', {
      'photo_hmac_key': hexKey,
      'updated_at': DateTime.now().toUtc().toIso8601String(),
    }, where: 'id = 1');
  }

  /// Absolute path to the queue directory (app-private).
  Future<String> queueDirectory() async {
    await ensureInitialized();
    return _queueDirPath!;
  }

  /// Absolute path to the exports directory (app-private).
  Future<String> exportsDirectory() async {
    await ensureInitialized();
    return _exportsDirPath!;
  }

  /// Free bytes on the filesystem hosting the queue directory, in bytes.
  /// Returns null on platforms where statfs is unavailable; callers treat
  /// null as "assume enough" rather than blocking capture.
  Future<int?> freeDiskBytes() async {
    await ensureInitialized();
    return null;
  }

  /// Whether the queue directory has room for another photo. We require
  /// at least 500 MB of headroom to be safe: 400 photos × 2 MB = 800 MB
  /// worst case, so rejecting capture under 500 MB prevents a full
  /// device mid-photo-day. When the OS doesn't expose free space we
  /// fall back to checking current queued bytes against the 4 GB budget.
  Future<bool> hasEnoughSpace({int additionalBytes = 2 * 1024 * 1024}) async {
    await ensureInitialized();
    try {
      // Sum existing queued non-terminal files.
      final rows = await (await LocalDb().database).rawQuery('''
        SELECT COALESCE(SUM(byte_size),0) AS used
          FROM photo_outbox
         WHERE state NOT IN (?, ?)
      ''', [
        PhotoOutboxState.done.storageValue,
        PhotoOutboxState.discarded.storageValue,
      ]);
      final used = ((rows.first['used'] as int?) ?? 0);
      const budget = 4 * 1024 * 1024 * 1024; // 4 GB
      return used + additionalBytes < budget;
    } catch (_) {
      return true; // fail open; prefer not blocking capture on a stat error
    }
  }

  /// Generate a new client_op_id for the next capture (UUID v4).
  /// Reuses the same generator that every other outbox uses so all
  /// client_op_ids in the app share one format.
  String generateClientOpId() => newClientOpId();

  // -------------------------------------------------------------------
  // Sync policy
  // -------------------------------------------------------------------

  Future<PhotoSyncPolicyState> readPolicy() async {
    await ensureInitialized();
    final db = await LocalDb().database;
    final rows = await db.query('photo_sync_policy', where: 'id = 1');
    if (rows.isEmpty) return PhotoSyncPolicyState.seeded();
    return PhotoSyncPolicyState.fromMap(rows.first);
  }

  Future<void> savePolicy(PhotoSyncPolicyState policy) async {
    await ensureInitialized();
    final db = await LocalDb().database;
    await db.update(
      'photo_sync_policy',
      policy.toUpsertMap(),
      where: 'id = 1',
    );
  }

  /// Convenience: flip the master sync toggle. Does NOT start the worker —
  /// the UI still requires an explicit Start Sync tap.
  Future<void> setSyncEnabled(bool enabled) async {
    final current = await readPolicy();
    await savePolicy(current.copyWith(syncEnabled: enabled));
  }

  Future<void> setAllowCellular(bool allow) async {
    final current = await readPolicy();
    await savePolicy(current.copyWith(allowCellular: allow));
  }

  // -------------------------------------------------------------------
  // Enqueue a newly-captured photo
  // -------------------------------------------------------------------

  /// Enqueue a photo whose bytes are already on disk at [absoluteFilePath].
  ///
  /// The file is moved/renamed into the queue directory under a canonical
  /// name so it is decoupled from any camera temp path. The outbox row is
  /// inserted in the same transaction after the rename succeeds, so a
  /// crash mid-enqueue can at worst leave an orphan file that the
  /// reconciliation step (P7) will sweep.
  ///
  /// If [retakeOfClientOpId] is non-null, that prior entry is marked
  /// `discarded` with `replaced_by` set to the new op id (retake semantics
  /// per design doc §3.6).
  ///
  /// Returns the enqueued entry.
  Future<PhotoOutboxEntry> enqueueCapturedPhoto({
    required int memberId,
    String? memberCode,
    required PhotoField field,
    required String absoluteFilePath,
    required PhotoFormat format,
    required int width,
    required int height,
    required DateTime takenAt,
    int? operatorAdminId,
    String? retakeOfClientOpId,
  }) async {
    await ensureInitialized();
    final file = File(absoluteFilePath);
    if (!await file.exists()) {
      throw StateError('Cannot enqueue: file $absoluteFilePath does not exist');
    }
    final bytes = await file.readAsBytes();
    if (bytes.isEmpty) {
      throw StateError('Cannot enqueue: zero-byte file at $absoluteFilePath');
    }
    if (bytes.lengthInBytes > 2 * 1024 * 1024) {
      throw StateError(
        'Cannot enqueue: file is ${bytes.lengthInBytes} bytes, exceeds 2 MB cap',
      );
    }
    if (!await hasEnoughSpace(additionalBytes: bytes.lengthInBytes)) {
      throw StateError(
        'Not enough storage space. Delete old synced photos or free up '
        'space before capturing more members.',
      );
    }
    final sha = sha256.convert(bytes).toString().toLowerCase();

    final clientOpId = newClientOpId();
    final canonicalName =
        'photo_${memberId}_${field.storageValue}_${clientOpId}.${format.extension}';
    final destPath = p.join(_queueDirPath!, canonicalName);
    await file.rename(destPath);

    final now = DateTime.now().toUtc();
    final entry = PhotoOutboxEntry(
      clientOpId: clientOpId,
      memberId: memberId,
      memberCode: memberCode,
      field: field,
      localPath: destPath,
      sha256: sha,
      byteSize: bytes.lengthInBytes,
      format: format,
      width: width,
      height: height,
      takenAt: takenAt.toUtc(),
      operatorAdminId: operatorAdminId,
      deviceId: _cachedDeviceId!,
      replacedBy: null,
      retakeOf: retakeOfClientOpId,
      state: PhotoOutboxState.queued,
      attempts: 0,
      nextAttemptAt: now, // due immediately if sync is running
      lastAttemptAt: null,
      lastError: null,
      lastErrorCode: null,
      lastHttpCode: null,
      serverPath: null,
      serverReceiptId: null,
      conflict: false,
      batchExportId: null,
      exportedAt: null,
      createdAt: now,
      updatedAt: now,
    );

    final db = await LocalDb().database;
    await db.transaction((txn) async {
      await txn.insert('photo_outbox', entry.toInsertMap(),
          conflictAlgorithm: ConflictAlgorithm.fail);

      if (retakeOfClientOpId != null) {
        // Retake: mark the prior capture as discarded, pointing at us.
        await txn.update(
          'photo_outbox',
          {
            'state': PhotoOutboxState.discarded.storageValue,
            'replaced_by': clientOpId,
            'updated_at': now.toIso8601String(),
          },
          where: 'client_op_id = ? AND state NOT IN (?, ?)',
          whereArgs: [
            retakeOfClientOpId,
            PhotoOutboxState.done.storageValue,
            PhotoOutboxState.discarded.storageValue,
          ],
        );
      } else {
        // First capture for this member+field: if any OTHER queued/retry
        // entry exists for the same (member_id, field) it's a leftover
        // from a previous session — leave it alone; roster UI flags it.
        // (Same-session retakes come through retakeOfClientOpId above.)
      }
    });

    return entry;
  }

  /// Undo a retake within the undo window (~30 s after capture). Re-discards
  /// the newer op and un-discards the one it replaced. Returns the restored
  /// entry, or null if there is nothing to undo.
  Future<PhotoOutboxEntry?> undoRetake(String newClientOpId) async {
    await ensureInitialized();
    final db = await LocalDb().database;
    return db.transaction<PhotoOutboxEntry?>((txn) async {
      final rows = await txn.query('photo_outbox',
          where: 'client_op_id = ?', whereArgs: [newClientOpId], limit: 1);
      if (rows.isEmpty) return null;
      final newOne = PhotoOutboxEntry.fromMap(rows.first);
      if (newOne.retakeOf == null) return null;
      if (newOne.state == PhotoOutboxState.done ||
          newOne.state == PhotoOutboxState.uploading) {
        return null; // too late
      }
      final now = DateTime.now().toUtc().toIso8601String();
      await txn.update(
        'photo_outbox',
        {
          'state': PhotoOutboxState.discarded.storageValue,
          'updated_at': now,
        },
        where: 'client_op_id = ?',
        whereArgs: [newClientOpId],
      );
      await txn.update(
        'photo_outbox',
        {
          'state': PhotoOutboxState.queued.storageValue,
          'replaced_by': null,
          'next_attempt_at': now,
          'updated_at': now,
        },
        where: 'client_op_id = ?',
        whereArgs: [newOne.retakeOf],
      );
      final restored = await txn.query('photo_outbox',
          where: 'client_op_id = ?', whereArgs: [newOne.retakeOf], limit: 1);
      if (restored.isEmpty) return null;
      return PhotoOutboxEntry.fromMap(restored.first);
    });
  }

  // -------------------------------------------------------------------
  // Queries
  // -------------------------------------------------------------------

  Future<PhotoOutboxCounters> counters() async {
    await ensureInitialized();
    final db = await LocalDb().database;
    final rows = await db.rawQuery('''
      SELECT state,
             COUNT(*) AS c,
             COALESCE(SUM(byte_size), 0) AS bytes
      FROM photo_outbox
      GROUP BY state
    ''');
    int byState(PhotoOutboxState s) {
      for (final r in rows) {
        if (r['state'] == s.storageValue) return (r['c'] as num).toInt();
      }
      return 0;
    }

    int queuedBytes = 0;
    final activeStates = [
      PhotoOutboxState.queued,
      PhotoOutboxState.uploading,
      PhotoOutboxState.retryWait,
      PhotoOutboxState.conflictPending,
    ];
    for (final r in rows) {
      final state = PhotoOutboxState.fromStorage(r['state'] as String);
      if (activeStates.contains(state)) {
        queuedBytes += (r['bytes'] as num).toInt();
      }
    }

    return PhotoOutboxCounters(
      queued: byState(PhotoOutboxState.queued),
      uploading: byState(PhotoOutboxState.uploading),
      retryWait: byState(PhotoOutboxState.retryWait),
      done: byState(PhotoOutboxState.done),
      conflictPending: byState(PhotoOutboxState.conflictPending),
      failed: byState(PhotoOutboxState.failed),
      discarded: byState(PhotoOutboxState.discarded),
      totalBytesQueued: queuedBytes,
    );
  }

  /// Set of member_ids that have a non-discarded outbox row for [field]
  /// (queued, uploaded, conflict_pending, or failed). Used by the batch
  /// capture roster to skip members who already have a capture pending
  /// review or upload.
  Future<Set<int>> memberIdsWithPhoto({PhotoField field = PhotoField.student}) async {
    await ensureInitialized();
    final db = await LocalDb().database;
    final rows = await db.query(
      'photo_outbox',
      columns: ['DISTINCT member_id'],
      where: 'field = ? AND state != ?',
      whereArgs: [field.storageValue, PhotoOutboxState.discarded.storageValue],
    );
    return rows
        .map((r) => (r['member_id'] as int?) ?? 0)
        .where((id) => id > 0)
        .toSet();
  }

  /// The latest (non-discarded) photo for a member+field, or null. Used by
  /// the roster to show a thumbnail status dot.
  Future<PhotoOutboxEntry?> latestForMember(int memberId,
      {PhotoField field = PhotoField.student}) async {
    await ensureInitialized();
    final db = await LocalDb().database;
    final rows = await db.query(
      'photo_outbox',
      where: 'member_id = ? AND field = ? AND state != ?',
      whereArgs: [
        memberId,
        field.storageValue,
        PhotoOutboxState.discarded.storageValue,
      ],
      orderBy: 'taken_at DESC, created_at DESC',
      limit: 1,
    );
    if (rows.isEmpty) return null;
    return PhotoOutboxEntry.fromMap(rows.first);
  }

  /// Entries due for upload: queued or retry_wait whose next_attempt_at
  /// has passed, excluding discarded/done/conflict. Up to [limit] entries
  /// (the sync worker uses concurrency*2 to keep the pipe full).
  Future<List<PhotoOutboxEntry>> pickDueForUpload({int limit = 4}) async {
    await ensureInitialized();
    final db = await LocalDb().database;
    final now = DateTime.now().toUtc().toIso8601String();
    final rows = await db.query(
      'photo_outbox',
      where: 'state IN (?, ?) AND next_attempt_at <= ?',
      whereArgs: [
        PhotoOutboxState.queued.storageValue,
        PhotoOutboxState.retryWait.storageValue,
        now,
      ],
      orderBy: 'next_attempt_at ASC, created_at ASC',
      limit: limit,
    );
    return rows.map(PhotoOutboxEntry.fromMap).toList();
  }

  /// All entries visible in the queue/review UI (skipping discarded).
  Future<List<PhotoOutboxEntry>> listVisibleEntries() async {
    await ensureInitialized();
    final db = await LocalDb().database;
    final rows = await db.query(
      'photo_outbox',
      where: 'state != ?',
      whereArgs: [PhotoOutboxState.discarded.storageValue],
      orderBy: 'created_at DESC',
    );
    return rows.map(PhotoOutboxEntry.fromMap).toList();
  }

  // -------------------------------------------------------------------
  // State transitions (used by the sync worker in P4)
  // -------------------------------------------------------------------

  /// Transition an entry to `uploading` (claim). Returns false if the row
  /// was no longer eligible (another worker claimed it, or user discarded
  /// it), which the sync worker uses as a skip signal.
  ///
  /// Uses rawUpdate so `attempts = attempts + 1` is atomic against the
  /// WHERE guard — two concurrent claims can't both win.
  Future<bool> claimForUpload(String clientOpId) async {
    await ensureInitialized();
    final db = await LocalDb().database;
    final now = DateTime.now().toUtc().toIso8601String();
    final count = await db.rawUpdate('''
      UPDATE photo_outbox
         SET state = ?,
             attempts = attempts + 1,
             last_attempt_at = ?,
             last_error = NULL,
             last_error_code = NULL,
             last_http_code = NULL,
             updated_at = ?
       WHERE client_op_id = ?
         AND state IN (?, ?)
    ''', [
      PhotoOutboxState.uploading.storageValue,
      now,
      now,
      clientOpId,
      PhotoOutboxState.queued.storageValue,
      PhotoOutboxState.retryWait.storageValue,
    ]);
    return count > 0;
  }

  /// Mark entry as done (accepted by server, possibly with conflict flag).
  /// After commit we opportunistically schedule LRU eviction of delivered
  /// files older than 30 days (P7 hardening) so the queue dir does not
  /// grow without bound after many capture days.
  Future<void> markDone(
    String clientOpId, {
    required String serverPath,
    String? serverReceiptId,
    bool conflict = false,
  }) async {
    await ensureInitialized();
    final db = await LocalDb().database;
    final state = conflict
        ? PhotoOutboxState.conflictPending.storageValue
        : PhotoOutboxState.done.storageValue;
    await db.update(
      'photo_outbox',
      {
        'state': state,
        'server_path': serverPath,
        'server_receipt_id': serverReceiptId,
        'conflict': conflict ? 1 : 0,
        'last_error': null,
        'last_error_code': null,
        'last_http_code': 200,
        'updated_at': DateTime.now().toUtc().toIso8601String(),
      },
      where: 'client_op_id = ?',
      whereArgs: [clientOpId],
    );
    // Fire-and-forget LRU sweep — runs async, never blocks the worker.
    unawaited(_evictOldDelivered());
  }

  /// P7 hardening: delete local files for delivered/conflict-resolved
  /// entries older than 30 days to reclaim device storage. The DB row is
  /// kept (for audit/history) with local_path nulled; we only delete the
  /// file. We intentionally keep queued/retry/failed/conflict_pending
  /// files so the operator can retry or review.
  static const _platform = MethodChannel('app.fkss.photo/nocloud');
  Future<void> _markNoCloudBackup(String dirPath) async {
    try { await _platform.invokeMethod('excludeFromBackup', {'path': dirPath}); }
    catch (_) {} // no-op when channel not registered
  }

  Future<int> _evictOldDelivered() async {
    try {
      await ensureInitialized();
      final db = await LocalDb().database;
      final cutoff = DateTime.now().toUtc().subtract(const Duration(days: 30));
      final rows = await db.query(
        'photo_outbox',
        columns: ['id', 'local_path'],
        where: 'state IN (?, ?) AND updated_at < ? AND local_path IS NOT NULL AND local_path != ?',
        whereArgs: [
          PhotoOutboxState.done.storageValue,
          PhotoOutboxState.discarded.storageValue,
          cutoff.toIso8601String(),
          '',
        ],
        limit: 200,
      );
      int removed = 0;
      for (final r in rows) {
        final p = r['local_path'] as String?;
        if (p == null) continue;
        final f = File(p);
        if (await f.exists()) {
          try { await f.delete(); } catch (_) {}
        }
        await db.update('photo_outbox', {'local_path': ''},
            where: 'id = ?', whereArgs: [r['id']]);
        removed++;
      }
      return removed;
    } catch (_) {
      return 0; // never let cleanup crash the sync worker
    }
  }

  /// Mark entry as needing retry (schedules next attempt via full-jitter
  /// backoff using the shared retry ladder).
  Future<void> markRetry({
    required String clientOpId,
    required int httpCode,
    required String errorCode,
    required String errorMessage,
    required int attemptCount,
    required DateTime nextAttemptAt,
  }) async {
    await ensureInitialized();
    final db = await LocalDb().database;
    await db.update(
      'photo_outbox',
      {
        'state': PhotoOutboxState.retryWait.storageValue,
        'attempts': attemptCount,
        'next_attempt_at': nextAttemptAt.toUtc().toIso8601String(),
        'last_http_code': httpCode,
        'last_error_code': errorCode,
        'last_error': errorMessage.length > 500
            ? errorMessage.substring(0, 500)
            : errorMessage,
        'updated_at': DateTime.now().toUtc().toIso8601String(),
      },
      where: 'client_op_id = ?',
      whereArgs: [clientOpId],
    );
  }

  /// Mark entry as terminal failure (non-retryable 4xx etc.).
  Future<void> markFailed({
    required String clientOpId,
    required int httpCode,
    required String errorCode,
    required String errorMessage,
  }) async {
    await ensureInitialized();
    final db = await LocalDb().database;
    await db.update(
      'photo_outbox',
      {
        'state': PhotoOutboxState.failed.storageValue,
        'last_http_code': httpCode,
        'last_error_code': errorCode,
        'last_error': errorMessage.length > 500
            ? errorMessage.substring(0, 500)
            : errorMessage,
        'updated_at': DateTime.now().toUtc().toIso8601String(),
      },
      where: 'client_op_id = ?',
      whereArgs: [clientOpId],
    );
  }

  /// Reset a failed entry back to queued (user tapped "Retry").
  Future<void> retryFailed(String clientOpId) async {
    await ensureInitialized();
    final db = await LocalDb().database;
    await db.update(
      'photo_outbox',
      {
        'state': PhotoOutboxState.queued.storageValue,
        'attempts': 0,
        'next_attempt_at': DateTime.now().toUtc().toIso8601String(),
        'last_error': null,
        'last_error_code': null,
        'updated_at': DateTime.now().toUtc().toIso8601String(),
      },
      where: 'client_op_id = ? AND state = ?',
      whereArgs: [clientOpId, PhotoOutboxState.failed.storageValue],
    );
  }

  /// Reset ALL failed entries back to queued (user tapped "Retry all").
  Future<void> retryAllFailed() async {
    await ensureInitialized();
    final db = await LocalDb().database;
    await db.update(
      'photo_outbox',
      {
        'state': PhotoOutboxState.queued.storageValue,
        'attempts': 0,
        'next_attempt_at': DateTime.now().toUtc().toIso8601String(),
        'last_error': null,
        'last_error_code': null,
        'updated_at': DateTime.now().toUtc().toIso8601String(),
      },
      where: 'state = ?',
      whereArgs: [PhotoOutboxState.failed.storageValue],
    );
  }

  /// Discard a queued/failed entry (and delete its file from disk). Safe on
  /// already-done entries: they stay in receipts (server has them) but
  /// their local file is removed during LRU cleanup, not here.
  Future<void> discard(String clientOpId) async {
    await ensureInitialized();
    final db = await LocalDb().database;
    final rows = await db.query('photo_outbox',
        where: 'client_op_id = ?', whereArgs: [clientOpId], limit: 1);
    if (rows.isEmpty) return;
    final entry = PhotoOutboxEntry.fromMap(rows.first);
    await db.update(
      'photo_outbox',
      {
        'state': PhotoOutboxState.discarded.storageValue,
        'updated_at': DateTime.now().toUtc().toIso8601String(),
      },
      where: 'client_op_id = ?',
      whereArgs: [clientOpId],
    );
    // Best-effort file deletion for non-synced entries; done entries' files
    // are left until LRU eviction (P6).
    if (entry.state != PhotoOutboxState.done &&
        entry.state != PhotoOutboxState.conflictPending) {
      try {
        final f = File(entry.localPath);
        if (await f.exists()) await f.delete();
      } catch (_) {}
    }
  }

}
