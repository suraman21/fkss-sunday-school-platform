import 'dart:async';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

import 'api_service.dart';
import 'connectivity_service.dart';
import 'outbox_policy.dart';
import 'photo_outbox_models.dart';
import 'photo_outbox_service.dart';

/// P4: foreground FIFO upload worker for queued photos.
///
/// Design rules (per OFFLINE_PHOTO_SYNC_DESIGN §7.4):
///   * Sync is OFF by default; nothing leaves the device until the user
///     explicitly opens the Sync Panel and taps Start Sync.
///   * When enabled, default is Wi-Fi-only; cellular uploads require a
///     separate opt-in (see PhotoSyncPolicyState.allowCellular).
///   * FIFO drain, concurrency configurable (default 2), full-jitter
///     exponential backoff via outbox_policy.nextOutboxAttemptAt().
///   * Whole-file multipart (1–2 MB each). A failure at 90% re-sends the
///     whole file; with 2 MB files on 3G that's ~3 seconds of waste,
///     acceptable vs TUS/chunked complexity.
///   * Pause / Cancel at any time; disposes cleanly on app lifecycle.
///   * Does NOT run in the background automatically; foreground only
///     (Android foreground notification + iOS foreground task are added
///     in P7 hardening — worker itself is policy-complete here).
///
/// Listen to [progressStream] for per-photo progress events used by the
/// Sync Panel UI.
class PhotoSyncWorker extends ChangeNotifier {
  PhotoSyncWorker._();
  static final PhotoSyncWorker instance = PhotoSyncWorker._();

  final _outbox = PhotoOutboxService.instance;
  final _api = ApiService();
  final _random = Random.secure();

  bool _running = false;
  bool _paused = false;
  bool _cancelled = false;
  int _inFlight = 0;
  int _done = 0;
  int _total = 0;
  int _conflicts = 0;
  int _failed = 0;
  int _bytesUploaded = 0;
  int _bytesTotal = 0;
  String? _currentMemberLabel;
  final List<PhotoSyncEvent> _events = [];
  final _eventController = StreamController<PhotoSyncEvent>.broadcast();

  // P7 foreground service bridge (Android-only; iOS no-ops via
  // MissingPluginException caught below).
  static const MethodChannel _foregroundChannel =
      MethodChannel('fkss.app/photo_sync_service');
  bool _fgServiceStarted = false;

  Future<void> _startForegroundService() async {
    if (_fgServiceStarted) return;
    try {
      await _foregroundChannel.invokeMethod('start', {
        'title': 'Uploading photos',
        'content': 'Preparing…',
        'progress': 0,
        'max': _total > 0 ? _total : 1,
        'indeterminate': _total == 0,
        'paused': false,
      });
      _fgServiceStarted = true;
    } on MissingPluginException {
      // iOS / desktop: no-op.
    } on PlatformException {
      // Channel exists but service couldn't start — best-effort.
    } catch (_) {}
  }

  Future<void> _updateForegroundService() async {
    if (!_fgServiceStarted) return;
    String content;
    if (_paused) {
      content = 'Paused';
    } else if (_currentMemberLabel != null) {
      content = '$_currentMemberLabel · ${_done + _failed}/$_total · '
          '${_humanBytes(_bytesUploaded)}/${_humanBytes(_bytesTotal)}';
    } else {
      content = '${_done + _failed}/$_total · ${_humanBytes(_bytesUploaded)}';
    }
    try {
      await _foregroundChannel.invokeMethod('update', {
        'title': _paused ? 'Photo upload paused' : 'Uploading photos',
        'content': content,
        'progress': _done + _failed,
        'max': _total > 0 ? _total : 1,
        'indeterminate': false,
        'paused': _paused,
      });
    } catch (_) {}
  }

  Future<void> _stopForegroundService() async {
    if (!_fgServiceStarted) return;
    _fgServiceStarted = false;
    try {
      await _foregroundChannel.invokeMethod('stop');
    } catch (_) {}
  }

  Stream<PhotoSyncEvent> get progressStream => _eventController.stream;

  bool get isRunning => _running;
  bool get isPaused => _paused;
  int get done => _done;
  int get total => _total;
  int get conflicts => _conflicts;
  int get failed => _failed;
  int get inFlight => _inFlight;
  int get bytesUploaded => _bytesUploaded;
  int get bytesTotal => _bytesTotal;
  String? get currentMemberLabel => _currentMemberLabel;
  List<PhotoSyncEvent> get recentEvents => List.unmodifiable(_events);

  double get progress => _total == 0 ? 0 : _done / _total;
  bool get isFinished => _running && !_paused && _done + _failed >= _total;

  @override
  void notifyListeners() {
    super.notifyListeners();
    if (_fgServiceStarted) _updateForegroundService();
  }

  /// Compute an ETA string based on observed throughput.
  String estimateEta(Duration elapsed) {
    if (_done == 0 || _bytesUploaded == 0) return 'estimating…';
    final bytesPerMs = _bytesUploaded / elapsed.inMilliseconds;
    if (bytesPerMs <= 0) return 'estimating…';
    final remainingBytes = _bytesTotal - _bytesUploaded;
    final remainingMs = remainingBytes / bytesPerMs;
    final sec = (remainingMs / 1000).round();
    if (sec < 60) return '~${sec}s remaining';
    if (sec < 3600) return '~${(sec / 60).round()}m ${sec % 60}s remaining';
    return '~${(sec / 3600).floor()}h ${((sec % 3600) / 60).round()}m remaining';
  }

  /// Returns false if sync cannot start (policy/lifecycle). The UI shows
  /// the returned reason string to the user.
  Future<StartSyncResult> canStart() async {
    final policy = await _outbox.readPolicy();
    if (!policy.syncEnabled) {
      return StartSyncResult(
          ok: false, reason: 'Sync is disabled. Enable it in Sync Settings.');
    }
    final connectivity = ConnectivityService();
    if (!connectivity.hasLink) {
      return StartSyncResult(ok: false, reason: 'No network connection.');
    }
    if (!policy.allowCellular && !connectivity.isUnmetered) {
      return StartSyncResult(
          ok: false,
          reason: 'Connected to cellular data. Enable "Sync over cellular" '
              'in Sync Settings or connect to Wi-Fi.');
    }
    final counters = await _outbox.counters();
    if (counters.unsynced == 0) {
      return StartSyncResult(ok: false, reason: 'No photos queued to sync.');
    }
    return StartSyncResult(ok: true);
  }

  /// Start a sync drain. Returns false if already running or policy
  /// blocks the start. Must be called from the Sync Panel in response to
  /// an explicit user tap.
  Future<bool> start({int? maxConcurrency}) async {
    if (_running) return false;
    final check = await canStart();
    if (!check.ok) {
      _emit(PhotoSyncEvent(PhotoSyncEventType.error, check.reason ?? ''));
      return false;
    }
    final policy = await _outbox.readPolicy();
    _running = true;
    _paused = false;
    _cancelled = false;
    _done = 0;
    _conflicts = 0;
    _failed = 0;
    _bytesUploaded = 0;
    _inFlight = 0;
    _events.clear();
    _currentMemberLabel = null;

    final counters = await _outbox.counters();
    _total = counters.unsynced;
    _bytesTotal = counters.totalBytesQueued;

    _emit(PhotoSyncEvent(PhotoSyncEventType.started,
        'Starting sync of $_total photos (${_humanBytes(_bytesTotal)})…'));
    notifyListeners();

    _ensureFgServiceHandler();

    unawaited(_startForegroundService());
    unawaited(_drain(policy.maxConcurrency));
    return true;
  }

  bool _fgHandlerInstalled = false;

  void _ensureFgServiceHandler() {
    if (_fgHandlerInstalled) return;
    _fgHandlerInstalled = true;
    _foregroundChannel.setMethodCallHandler((call) async {
      if (call.method == 'stopFromNotification') {
        cancel();
      }
    });
  }

  void pause() {
    if (!_running || _paused) return;
    _paused = true;
    _emit(PhotoSyncEvent(PhotoSyncEventType.paused, 'Paused.'));
    _updateForegroundService();
    notifyListeners();
  }

  void resume() {
    if (!_running || !_paused) return;
    _paused = false;
    _emit(PhotoSyncEvent(PhotoSyncEventType.resumed, 'Resumed.'));
    _updateForegroundService();
    notifyListeners();
  }

  void cancel() {
    if (!_running) return;
    _cancelled = true;
    _paused = false;
    _emit(PhotoSyncEvent(PhotoSyncEventType.cancelled, 'Cancelled.'));
    _stopForegroundService();
    notifyListeners();
  }

  // -------------------- drain loop --------------------

  Future<void> _drain(int concurrency) async {
    try {
      final completers = <Future<void>>[];
      final actualConcurrency = concurrency.clamp(1, 4);
      for (var i = 0; i < actualConcurrency; i++) {
        completers.add(_workerLoop(i));
      }
      await Future.wait(completers);
    } finally {
      _running = false;
      _paused = false;
      _cancelled = false;
      _currentMemberLabel = null;
      if (_failed == 0 && _conflicts == 0) {
        _emit(PhotoSyncEvent(PhotoSyncEventType.completed,
            'Done. $_done photo${_done == 1 ? '' : 's'} synced.'));
      } else if (_failed == 0) {
        _emit(PhotoSyncEvent(PhotoSyncEventType.completed,
            'Done. $_done synced, $_conflicts waiting for admin review.'));
      } else {
        _emit(PhotoSyncEvent(
            PhotoSyncEventType.completedWithErrors,
            'Finished with issues. $_done synced, $_conflicts pending review, '
            '$_failed failed (tap to retry).'));
      }
      _stopForegroundService();
      notifyListeners();
    }
  }

  Future<void> _workerLoop(int slotId) async {
    while (!_cancelled) {
      if (_paused) {
        await Future<void>.delayed(const Duration(milliseconds: 500));
        continue;
      }

      // Check network policy between items.
      final policy = await _outbox.readPolicy();
      final connectivity = ConnectivityService();
      if (!connectivity.hasLink) {
        _emit(
            PhotoSyncEvent(PhotoSyncEventType.waiting, 'Waiting for network…'));
        await _waitFor(const Duration(seconds: 5));
        continue;
      }
      if (!policy.allowCellular && !connectivity.isUnmetered) {
        _emit(PhotoSyncEvent(PhotoSyncEventType.waiting,
            'Wi-Fi required. Pausing until Wi-Fi is available.'));
        await _waitFor(const Duration(seconds: 10));
        continue;
      }

      // Claim one due entry.
      PhotoOutboxEntry? claimed;
      final due = await _outbox.pickDueForUpload(limit: 1);
      for (final entry in due) {
        if (await _outbox.claimForUpload(entry.clientOpId)) {
          claimed = entry;
          break;
        }
      }
      if (claimed == null) {
        // No work is due. Wait for in-flight uploads or scheduled retries
        // before ending this explicit sync run.
        if (_inFlight > 0) {
          await Future<void>.delayed(const Duration(milliseconds: 300));
          continue;
        }
        final counters = await _outbox.counters();
        if (counters.queued + counters.retryWait > 0) {
          await Future<void>.delayed(const Duration(seconds: 1));
          continue;
        }
        break;
      }

      _inFlight++;
      _currentMemberLabel = claimed.memberCode ?? '#${claimed.memberId}';
      notifyListeners();
      _emit(PhotoSyncEvent(
          PhotoSyncEventType.uploading,
          'Uploading ${claimed.memberCode ?? claimed.memberId} '
          '(${claimed.humanSize})…',
          entry: claimed));

      // Perform upload.
      await _uploadOne(claimed);

      _inFlight--;
    }
  }

  Future<void> _uploadOne(PhotoOutboxEntry entry) async {
    final payload = PhotoUploadPayload(
      clientOpId: entry.clientOpId,
      memberId: entry.memberId,
      field: entry.field.storageValue,
      localPath: entry.localPath,
      sha256: entry.sha256,
      format: entry.format.storageValue,
      width: entry.width,
      height: entry.height,
      takenAt: entry.takenAt,
      deviceId: entry.deviceId,
      operatorAdminId: entry.operatorAdminId,
      timeoutSeconds: 90,
    );

    try {
      final response = await _api.uploadMemberPhoto(
        memberId: entry.memberId,
        payload: payload,
      );

      if (response.success) {
        final data = response.data as Map<String, dynamic>? ?? const {};
        final replayed = data['replayed'] == true;
        final conflict = data['conflict'] == true;
        final serverPath = data['path'] as String?;
        // P7 pairing: save any HMAC key the server hands back.
        final hmacKey = data['photo_hmac_key'] as String?;
        if (hmacKey != null && hmacKey.isNotEmpty) {
          await _outbox.saveHmacKey(hmacKey);
        }
        await _outbox.markDone(
          entry.clientOpId,
          serverPath: serverPath ?? '',
          conflict: conflict,
        );
        _done++;
        if (conflict) _conflicts++;
        _bytesUploaded += entry.byteSize;
        _emit(PhotoSyncEvent(
          conflict
              ? PhotoSyncEventType.uploadedConflict
              : PhotoSyncEventType.uploaded,
          conflict
              ? '${entry.memberCode ?? entry.memberId} uploaded (admin review needed)'
              : replayed
                  ? '${entry.memberCode ?? entry.memberId} (already synced)'
                  : '${entry.memberCode ?? entry.memberId} uploaded',
          entry: entry,
        ));
        notifyListeners();
        return;
      }

      // Classify response: retryable vs needsAttention.
      final code = response.statusCode;
      final errMsg = response.message ?? 'Upload failed';
      final errCode =
          (response.data is Map && (response.data as Map)['code'] != null)
              ? '${(response.data as Map)['code']}'
              : null;

      if (_isRetryable(code, errCode)) {
        final nextAt = nextOutboxAttemptAt(
          attemptCount: entry.attempts + 1,
          randomUnit: _random.nextDouble(),
        );
        await _outbox.markRetry(
          clientOpId: entry.clientOpId,
          httpCode: code,
          errorCode: errCode ?? 'HTTP_$code',
          errorMessage: errMsg,
          attemptCount: entry.attempts + 1,
          nextAttemptAt: nextAt,
        );
        _emit(PhotoSyncEvent(PhotoSyncEventType.retrying,
            'Retrying ${entry.memberCode ?? entry.memberId}: $errMsg'));
        // Don't count as done; it will be picked up again later if still
        // running. For simplicity we treat retry as "continue" — next
        // pickDueForUpload picks it up after backoff.
      } else {
        await _outbox.markFailed(
          clientOpId: entry.clientOpId,
          httpCode: code,
          errorCode: errCode ?? 'HTTP_$code',
          errorMessage: errMsg,
        );
        _failed++;
        _emit(PhotoSyncEvent(PhotoSyncEventType.failed,
            'Failed: ${entry.memberCode ?? entry.memberId} — $errMsg'));
      }
      notifyListeners();
    } catch (e) {
      final nextAt = nextOutboxAttemptAt(
        attemptCount: entry.attempts + 1,
        randomUnit: _random.nextDouble(),
      );
      await _outbox.markRetry(
        clientOpId: entry.clientOpId,
        httpCode: 0,
        errorCode: 'TRANSPORT',
        errorMessage: '$e',
        attemptCount: entry.attempts + 1,
        nextAttemptAt: nextAt,
      );
      _emit(PhotoSyncEvent(
          PhotoSyncEventType.retrying, 'Network error, will retry: $e'));
    }
  }

  bool _isRetryable(int code, String? errCode) {
    if (code == 0 || code == 408 || code == 425 || code == 429) {
      return true;
    }
    if (code >= 500 && code <= 599) {
      return true;
    }
    // 401 after refresh-failure is treated as pauseForAuth in higher layer;
    // here we mark retry so a later app foreground with a fresh token can
    // continue.
    if (code == 401) {
      return true;
    }
    // Definitive non-retryable codes.
    if (code == 400 ||
        code == 403 ||
        code == 404 ||
        code == 410 ||
        code == 413 ||
        code == 415 ||
        code == 422) {
      return false;
    }
    return false;
  }

  Future<void> _waitFor(Duration d) async {
    final end = DateTime.now().add(d);
    while (DateTime.now().isBefore(end)) {
      if (_cancelled) return;
      await Future<void>.delayed(const Duration(milliseconds: 500));
    }
  }

  void _emit(PhotoSyncEvent event) {
    _events.insert(0, event);
    if (_events.length > 200) _events.removeLast();
    _eventController.add(event);
  }

  static String _humanBytes(int bytes) {
    if (bytes < 1024) return '$bytes B';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(1)} KB';
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }
}

enum PhotoSyncEventType {
  started,
  uploading,
  uploaded,
  uploadedConflict,
  retrying,
  failed,
  waiting,
  paused,
  resumed,
  cancelled,
  completed,
  completedWithErrors,
  error,
}

class PhotoSyncEvent {
  final PhotoSyncEventType type;
  final String message;
  final PhotoOutboxEntry? entry;
  final DateTime at;
  PhotoSyncEvent(this.type, this.message, {this.entry}) : at = DateTime.now();
}

class StartSyncResult {
  final bool ok;
  final String? reason;
  StartSyncResult({required this.ok, this.reason});
}
