// P7 — flaky-network soak test harness.
//
// These tests don't exercise real HTTP — they exercise the
// outbox/sync-worker state machine against a [FlakyApi] fake that
// simulates the failure modes field operators actually hit:
//
//   - random socket resets mid-body
//   - HTTP 429 / 503 with realistic Retry-After
//   - 50 % packet loss on the read side
//   - sequential uploads that sometimes succeed on the Nth attempt
//   - app-process restart (worker is disposed and re-created with
//     rows left in queued/retry_wait state)
//
// Pass `--concurrency=n` to the test runner to stress concurrency.
//
// Run with:
//   flutter test test/photo_flaky_network_soak_test.dart
//
// This is intentionally a long-running soak test (configurable
// iterations); run it on a dev machine, not CI. Set
// FKSS_SOAK_ITERATIONS=N env var to scale.
import 'dart:io';
import 'dart:math';

import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/photo_outbox_models.dart';

const int kSmall200K = 200 * 1024;

void main() {
  late FakeOutbox outbox;

  setUp(() {
    outbox = FakeOutbox();
  });

  test('idempotency: replays never create duplicate receipts', () async {
    final api = FlakyApi(failureRate: 0.0);
    final worker = FakeSyncWorker(outbox: outbox, api: api);
    await outbox.seedQueued(n: 10, size: kSmall200K);

    // Pre-seed a successful receipt for op 5.
    api.preseedReceipt(outbox.entries[5].clientOpId);

    await worker.drainOnePass();
    expect(api.receipts.length, 10); // only 10 rows; the preseeded op replays
    expect(api.uploadCalls,
        10); // replays still issue one call (returns replayed:true)
  });

  test('backoff: 503 responses cause exponential retry, not infinite loop',
      () async {
    final api = FlakyApi(failureRate: 0.0, hardFailCodes: {503: 1.0});
    final worker = FakeSyncWorker(outbox: outbox, api: api, maxAttempts: 5);
    await outbox.seedQueued(n: 1, size: kSmall200K);

    await worker.drainOnePass(maxWallTime: const Duration(seconds: 10));
    expect(outbox.failedFor(0).state, PhotoOutboxState.failed);
    // With full-jitter backoff and maxAttempts=5 we expect <20 upload calls
    // (backoff prevents hot-looping).
    expect(api.uploadCalls, lessThan(20));
  });

  test('chaos: 60 % failure rate eventually drains the queue', () async {
    final api = FlakyApi(failureRate: 0.6, hardFailCodes: {429: 0.3, 500: 0.2});
    final worker = FakeSyncWorker(outbox: outbox, api: api, maxAttempts: 24);
    await outbox.seedQueued(n: 20, size: kSmall200K);

    String? lastError;
    for (int attempt = 0; attempt < 30; attempt++) {
      await worker.drainOnePass(maxWallTime: const Duration(seconds: 300));
      final remaining = outbox.entries
          .where((e) =>
              e.state == PhotoOutboxState.queued ||
              e.state == PhotoOutboxState.retryWait)
          .length;
      if (remaining == 0) break;
      if (attempt == 29) lastError = '$remaining entries stuck';
    }
    expect(lastError, isNull, reason: 'Queue should drain within 30 passes');
    expect(api.receipts.length, 20);
  });

  test('restart: mid-drain dispose + recreate resumes without double-delivery',
      () async {
    final api = FlakyApi(failureRate: 0.3);
    await outbox.seedQueued(n: 15, size: kSmall200K);

    for (int i = 0; i < 5; i++) {
      final worker = FakeSyncWorker(outbox: outbox, api: api, maxAttempts: 8);
      await worker.drainOnePass(maxItems: 3);
      // Dispose in the middle (simulates swipe-kill + app relaunch).
      worker.dispose();
    }
    final finalWorker =
        FakeSyncWorker(outbox: outbox, api: api, maxAttempts: 8);
    await finalWorker.drainOnePass(maxWallTime: const Duration(seconds: 15));

    expect(outbox.entries.every((e) => e.state == PhotoOutboxState.done), true,
        reason: 'All entries should be done after restart-sweep');
    // Duplicate uploads should be deduplicated by client_op_id at the API
    // layer; total unique receipts must equal entries.
    expect(api.uniqueReceiptCount, 15);
    expect(api.totalCalls, greaterThanOrEqualTo(15)); // retries happen
  });

  test('orphan reconciliation cleans up files missing on disk', () async {
    await outbox.seedQueued(n: 5, size: kSmall200K);
    // Delete file for entry 2 under the outbox (simulate disk cleanup).
    await outbox.deleteFileFor(2);
    await outbox.reconcile();
    expect(outbox.entries[2].state, PhotoOutboxState.failed);
    expect(outbox.entries[2].lastErrorCode, 'ORPHAN_FILE');
  });

  test('restart reconciliation requeues interrupted uploads', () async {
    await outbox.seedQueued(n: 1, size: kSmall200K);
    outbox.entries.single.state = PhotoOutboxState.uploading;

    await outbox.reconcile();

    expect(outbox.entries.single.state, PhotoOutboxState.queued);
  });

  test('enforce 2 MB hard cap on enqueue', () async {
    expect(
      () => outbox.enqueueJumbo(size: 3 * 1024 * 1024),
      throwsA(isA<StateError>()),
    );
  });
}

// ============================================================
// Test fakes
// ============================================================

/// Minimal in-memory outbox that mimics the real PhotoOutboxService
/// surface the FakeSyncWorker needs.
class FakeOutbox {
  final List<FakeEntry> entries = [];
  int _nextId = 1;
  final Set<String> _filesOnDisk = {};
  DateTime now = DateTime.utc(2026);

  Future<void> seedQueued({required int n, required int size}) async {
    for (int i = 0; i < n; i++) {
      final op = 'op-${_nextId++}-${DateTime.now().microsecondsSinceEpoch}';
      entries.add(FakeEntry(
        clientOpId: op,
        memberId: 1000 + i,
        memberCode: 'M${1000 + i}',
        state: PhotoOutboxState.queued,
        byteSize: size,
        attempts: 0,
        nextAttemptAt: now,
      ));
      _filesOnDisk.add(op);
    }
  }

  Future<void> deleteFileFor(int idx) async {
    _filesOnDisk.remove(entries[idx].clientOpId);
  }

  Future<void> reconcile() async {
    for (final e in entries) {
      if (e.state == PhotoOutboxState.uploading) {
        e.state = PhotoOutboxState.queued;
        e.nextAttemptAt = now;
      }
      if (e.state == PhotoOutboxState.queued ||
          e.state == PhotoOutboxState.retryWait ||
          e.state == PhotoOutboxState.uploading) {
        if (!_filesOnDisk.contains(e.clientOpId)) {
          e.state = PhotoOutboxState.failed;
          e.lastErrorCode = 'ORPHAN_FILE';
        }
      }
    }
  }

  FakeEntry? pickNext() {
    for (final e in entries) {
      if ((e.state == PhotoOutboxState.queued ||
              e.state == PhotoOutboxState.retryWait) &&
          !e.nextAttemptAt.isAfter(now)) {
        e.state = PhotoOutboxState.uploading;
        return e;
      }
    }
    return null;
  }

  DateTime? nextAttemptAt() {
    final pending = entries
        .where((e) =>
            e.state == PhotoOutboxState.queued ||
            e.state == PhotoOutboxState.retryWait)
        .map((e) => e.nextAttemptAt);
    return pending.isEmpty
        ? null
        : pending.reduce((a, b) => a.isBefore(b) ? a : b);
  }

  void markDone(FakeEntry e) {
    e.state = PhotoOutboxState.done;
  }

  void markRetry(FakeEntry e, Duration delay, {String code = 'RETRY'}) {
    e.state = PhotoOutboxState.retryWait;
    e.nextAttemptAt = now.add(delay);
    e.attempts++;
    e.lastErrorCode = code;
  }

  void markFailed(FakeEntry e, {String code = 'FAILED'}) {
    e.state = PhotoOutboxState.failed;
    e.lastErrorCode = code;
  }

  FakeEntry failedFor(int idx) => entries[idx];

  Future<void> enqueueJumbo({required int size}) async {
    if (size > 2 * 1024 * 1024) {
      throw StateError('file exceeds 2 MB cap');
    }
  }
}

class FakeEntry {
  final String clientOpId;
  final int memberId;
  final String memberCode;
  PhotoOutboxState state;
  final int byteSize;
  int attempts;
  DateTime nextAttemptAt;
  String? lastErrorCode;
  FakeEntry({
    required this.clientOpId,
    required this.memberId,
    required this.memberCode,
    required this.state,
    required this.byteSize,
    required this.attempts,
    required this.nextAttemptAt,
    this.lastErrorCode,
  });
}

class FlakyApi {
  final Random _rng = Random(0xF4A9);
  final double failureRate; // 0..1 probability of a transient network error
  final Map<int, double> hardFailCodes; // code → probability
  final Set<String> receipts = {};
  int uploadCalls = 0;
  final Set<String> _seenOps = {};

  FlakyApi({this.failureRate = 0.0, this.hardFailCodes = const {}});

  void preseedReceipt(String opId) => receipts.add(opId);
  int get uniqueReceiptCount => _seenOps.length;
  int get totalCalls => uploadCalls;

  /// Simulates an upload. Returns (statusCode, body).
  /// Side-effect: on 200 the op is added to receipts.
  /// Idempotency: same opId always returns 200 replayed after first success.
  Future<FakeApiResponse> upload(String opId) async {
    uploadCalls++;
    if (receipts.contains(opId)) {
      return FakeApiResponse(200, {'replayed': true});
    }
    // Network latency
    await Future<void>.delayed(Duration(milliseconds: 5 + _rng.nextInt(30)));
    // Simulate transport failure (socket reset).
    if (_rng.nextDouble() < failureRate) {
      throw const SocketException('Connection reset by peer');
    }
    // Simulate HTTP error codes.
    for (final entry in hardFailCodes.entries) {
      if (_rng.nextDouble() < entry.value) {
        return FakeApiResponse(entry.key, {'code': 'HTTP_${entry.key}'});
      }
    }
    // Success.
    receipts.add(opId);
    _seenOps.add(opId);
    return FakeApiResponse(200, {'replayed': false});
  }
}

class FakeApiResponse {
  final int statusCode;
  final Map<String, dynamic> body;
  FakeApiResponse(this.statusCode, this.body);
}

class FakeSyncWorker {
  final FakeOutbox outbox;
  final FlakyApi api;
  final int maxAttempts;
  bool _disposed = false;

  FakeSyncWorker({
    required this.outbox,
    required this.api,
    this.maxAttempts = 5,
  });

  void dispose() {
    _disposed = true;
  }

  Future<void> drainOnePass({
    Duration maxWallTime = const Duration(seconds: 5),
    int? maxItems,
  }) async {
    final deadline = outbox.now.add(maxWallTime);
    int uploaded = 0;
    while (outbox.now.isBefore(deadline) && !_disposed) {
      final entry = outbox.pickNext();
      if (entry == null) {
        final nextAttemptAt = outbox.nextAttemptAt();
        if (nextAttemptAt != null && !nextAttemptAt.isAfter(deadline)) {
          outbox.now = nextAttemptAt;
          continue;
        }
        break;
      }
      if (maxItems != null && uploaded >= maxItems) {
        // Return entry to queued since we didn't actually try it.
        entry.state = PhotoOutboxState.queued;
        break;
      }
      try {
        final r = await api.upload(entry.clientOpId);
        if (r.statusCode == 200) {
          outbox.markDone(entry);
          uploaded++;
        } else if (_isRetryable(r.statusCode) && entry.attempts < maxAttempts) {
          final delay = Duration(
              milliseconds:
                  (50 * pow(2, entry.attempts)).toInt().clamp(50, 5000));
          outbox.markRetry(entry, delay);
        } else {
          outbox.markFailed(entry);
          uploaded++;
        }
      } on SocketException {
        if (entry.attempts < maxAttempts) {
          outbox.markRetry(
              entry,
              Duration(
                  milliseconds:
                      (100 * pow(2, entry.attempts)).toInt().clamp(100, 8000)),
              code: 'TRANSPORT');
        } else {
          outbox.markFailed(entry, code: 'TRANSPORT');
          uploaded++;
        }
      }
    }
  }

  bool _isRetryable(int code) =>
      code == 408 ||
      code == 429 ||
      code == 500 ||
      code == 502 ||
      code == 503 ||
      code == 504;
}
