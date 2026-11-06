import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'api_service.dart';
import 'attendance_sync_coordinator.dart';
import 'catalog_service.dart';
import 'connectivity_service.dart';
import 'mezmur_download_manager.dart';
import 'hymn_store.dart';
import 'legacy_outbox_models.dart';
import 'local_db.dart';
import 'outbox_policy.dart';
import 'sync_attempt_models.dart';
import 'sync_execution.dart';
import 'telemetry_service.dart';

/// Outbox worker — Gmail / WhatsApp / Drive pattern.
/// UI writes SQLite only. One worker sends. Retries wait on the in-flight
/// future instead of returning "Sending…". Idempotency-Key makes peak-hour
/// double posts safe.
class SyncService {
  static final SyncService _instance = SyncService._internal();
  factory SyncService() => _instance;
  SyncService._internal()
      : _api = ApiService(),
        _db = LocalDb();

  /// S2 Goal A.5 — the behavioural test seam, and nothing more.
  ///
  /// WHY A SECOND CONSTRUCTOR RATHER THAN PARAMETERS ON `SyncService()`.
  /// `SyncService()` is a `factory` returning `_instance`. Adding optional
  /// parameters there would compile and then silently ignore them for every
  /// call after the first, because the cached instance is returned unchanged
  /// — a test could pass a fake, get the production singleton back, and still
  /// appear to pass. A separate named constructor makes substitution explicit
  /// and impossible to get wrong.
  ///
  /// Production is untouched: `SyncService()` still returns the same single
  /// instance built with the real [ApiService] and [LocalDb], so no existing
  /// caller changes. This constructor exists so focused tests can drive the
  /// REAL coordinator, `runSyncNow`, `nudge`, `_drain` and the A.4 opportunity
  /// request without a database. It is deliberately NOT a dependency-injection
  /// framework: exactly two collaborators are injectable, because exactly two
  /// are what stands between these code paths and a test.
  ///
  /// Each call returns a FRESH instance, which is what gives tests isolated
  /// `_inflight`/`_queued`/coordinator state.
  SyncService.withCollaborators({ApiService? api, LocalDb? db})
      : _api = api ?? ApiService(),
        _db = db ?? LocalDb();

  final ApiService _api;
  final LocalDb _db;
  final _random = Random();
  Timer? _retryTimer;
  StreamSubscription<bool>? _radioSub;
  bool _started = false;
  Completer<SyncResult>? _inflight;
  int? _inflightGeneration;
  bool _queued = false;
  bool _forceNext = false;
  bool Function()? activeSessionGate;
  int Function()? sessionGenerationProvider;
  bool Function()? drainEnabledGate;

  bool get _drainsAllowed => drainEnabledGate?.call() != false;

  /// Public read-only view of the runtime drain gate, for feedback text.
  /// When false, rows stay saved but nothing is being attempted, so feedback
  /// must say "paused" — never "retrying".
  bool get drainsAllowed => _drainsAllowed;

  bool _ownsGeneration(int generation) =>
      activeSessionGate?.call() != false &&
      generation == (sessionGenerationProvider?.call() ?? generation);

  final _syncController = StreamController<SyncStatus>.broadcast();
  Stream<SyncStatus> get syncStream => _syncController.stream;
  SyncStatus _lastStatus = SyncStatus(
      pendingAttendance: 0,
      pendingGrades: 0,
      pendingMezmur: 0,
      pendingHr: 0,
      pendingHymns: 0,
      syncing: false);
  SyncStatus get lastStatus => _lastStatus;
  String lastError = '';
  static const _confirmationHeldMessage =
      'Server confirmation is incomplete. Your saved work is kept.';

  // ── S2 Goal A.2 — the coordinated execution boundary ────────────────
  // The scheduler is the seam the future native layer attaches to. Until
  // then it is a no-op, so nothing here depends on a platform plugin.
  BackgroundSyncScheduler _backgroundScheduler =
      const NoopBackgroundSyncScheduler();
  set backgroundScheduler(BackgroundSyncScheduler scheduler) {
    _backgroundScheduler = scheduler;
    _coordinatorInstance = null;
  }

  BackgroundSyncCoordinator<SyncResult>? _coordinatorInstance;
  BackgroundSyncCoordinator<SyncResult> get _coordinator =>
      _coordinatorInstance ??= BackgroundSyncCoordinator<SyncResult>(
        scheduler: _backgroundScheduler,
        runDrain: _executeCoordinatedDrain,
      );

  // Arguments the A.1 `runDrain` typedef cannot carry. Safe because
  // `execute()` invokes the runner SYNCHRONOUSLY with no await in between,
  // in a single-threaded isolate, so no other caller can interleave.
  // Extending the typedef instead would force edits to every fake closure
  // in sync_execution_test.dart, risking A.1's green CI with no local Dart
  // compiler to check it. This mirrors the class's existing _forceNext idiom.
  int? _pendingGeneration;
  bool _pendingForce = false;

  Future<SyncResult> _executeCoordinatedDrain(SyncExecutionSource source) {
    final generation =
        _pendingGeneration ?? (sessionGenerationProvider?.call() ?? 0);
    final force = _pendingForce;
    _pendingGeneration = null;
    _pendingForce = false;
    return _syncAllForGeneration(generation, force: force, source: source);
  }

  void startAutoSync() {
    if (activeSessionGate?.call() == false || !_drainsAllowed) return;
    if (_started) {
      nudge(delay: const Duration(milliseconds: 400));
      return;
    }
    _started = true;
    _radioSub?.cancel();
    _radioSub = ConnectivityService().statusStream.listen((hasLink) {
      if (hasLink) nudge(delay: const Duration(milliseconds: 500));
    });
    nudge(delay: const Duration(milliseconds: 800));
  }

  void stopAutoSync() {
    _retryTimer?.cancel();
    _retryTimer = null;
    _radioSub?.cancel();
    _radioSub = null;
    _started = false;
    _queued = false;
    _forceNext = false;
    // Cancel any pending background wake-up. This drops the OPPORTUNITY only:
    // pending operations stay in the durable outbox, still owned by their
    // original user, and drain when that user is active again.
    unawaited(_coordinator.cancelOpportunity());
  }

  void nudge({Duration delay = const Duration(milliseconds: 300)}) {
    if (activeSessionGate?.call() == false || !_drainsAllowed) return;
    if (!_api.isLoggedIn) return;
    if (!_started) startAutoSync();
    _retryTimer?.cancel();
    final generation = sessionGenerationProvider?.call() ?? 0;
    _retryTimer = Timer(delay, () {
      // Through the coordinator, carrying the generation captured above.
      runSyncNow(
        source: SyncExecutionSource.foreground,
        generation: generation,
      );
    });
  }

  /// S2 Goal A.1 — THE authoritative execution entry point.
  ///
  /// Every trigger converges here: the existing foreground callers via
  /// [syncAll]/[nudge], and the future Android worker via
  /// `BackgroundSyncCoordinator.execute`. There is deliberately no second
  /// drain, no "background variant" and no parallel engine — the only thing a
  /// background caller changes is the [source] label attached to the attempts
  /// it produces.
  ///
  /// Everything that decides *what* is eligible stays exactly where it was:
  /// the claim query in `LocalDb.claimNextLegacyOperation` enforces
  /// `next_attempt_at <= now`, owner and authorization-version isolation, and
  /// the session-generation guard. A background trigger cannot widen any of
  /// that, because it does not get its own query.
  /// S2 Goal A.2 — [generation] lets a caller that CAPTURED the session
  /// generation earlier (notably [nudge], when its timer was armed) hand
  /// that value over. Re-reading it here would re-validate against whichever
  /// session is current when the timer fires, defeating the guard that stops
  /// a timer armed under user A from draining under user B. Callers acting
  /// immediately omit it and keep call-time semantics.
  Future<SyncResult> runSyncNow({
    SyncExecutionSource source = SyncExecutionSource.foreground,
    bool force = false,
    int? generation,
  }) {
    _pendingGeneration = generation;
    _pendingForce = force;
    return _coordinator.execute(source);
  }

  Future<SyncResult> syncAll({bool force = false}) =>
      runSyncNow(source: SyncExecutionSource.foreground, force: force);

  Future<SyncResult> _syncAllForGeneration(int generation,
      {bool force = false,
      SyncExecutionSource source = SyncExecutionSource.foreground}) async {
    if (!_ownsGeneration(generation) || !_api.isLoggedIn) {
      return SyncResult(synced: 0, failed: 0, message: 'Not logged in');
    }
    if (!_drainsAllowed) return _releasePausedResult();
    if (force) _forceNext = true;
    // Gmail outbox: if a drain is already running, mark "run again"
    // after it. Joining the in-flight future without that flag swallows
    // any Save that landed while the first drain was already reading.
    if (_inflight != null) {
      final sameGeneration = _inflightGeneration == generation;
      if (sameGeneration) _queued = true;
      final r = await _inflight!.future;
      if (!_ownsGeneration(generation)) {
        return SyncResult(
            synced: 0,
            failed: 0,
            message: 'Sync paused until this account is active again.');
      }
      if (!sameGeneration && _inflight == null) {
        return _syncAllForGeneration(generation, force: force, source: source);
      }
      return r;
    }
    final c = Completer<SyncResult>();
    _inflight = c;
    _inflightGeneration = generation;
    try {
      var r = SyncResult(synced: 0, failed: 0, message: 'Nothing waiting to send');
      var pass = 0;
      do {
        _queued = false;
        pass++;
        final useForce = force || _forceNext;
        _forceNext = false;
        final next = await _drain(generation: generation,
            force: useForce, source: source);
        r = SyncResult(
          synced: r.synced + next.synced,
          failed: next.failed,
          message: next.message,
        );
      } while (_queued && _ownsGeneration(generation) && pass < 10);
      if (_queued && _ownsGeneration(generation)) {
        // Yield after the bounded immediate rescans, then continue from the DB.
        nudge(delay: const Duration(milliseconds: 50));
      }
      // The outbox has settled, so this is the moment the server's view
      // and ours are most likely to differ: our own writes have just
      // landed, and anything another device did is still unseen. Pull
      // after the drain rather than before, so we reconcile against the
      // state our uploads produced.
      await pullServerChanges(generation: generation);
      if (!c.isCompleted) c.complete(r);
      return r;
    } catch (e) {
      final r = !_ownsGeneration(generation)
          ? SyncResult(
              synced: 0,
              failed: 0,
              message: 'Sync paused until this account is active again.',
            )
          : SyncResult(
              synced: 0,
              failed: 1,
              message: 'Could not send yet. Will retry on its own.',
            );
      if (!c.isCompleted) c.complete(r);
      return r;
    } finally {
      if (identical(_inflight, c)) {
        _inflight = null;
        _inflightGeneration = null;
      }
    }
  }

  int _asInt(dynamic v) {
    if (v is int) return v;
    if (v is num) return v.toInt();
    return int.tryParse('$v') ?? 0;
  }

  Future<SyncResult> _drain(
      {required int generation,
      required bool force,
      required SyncExecutionSource source}) async {
    if (!_ownsGeneration(generation)) return _pausedResult();
    if (!_drainsAllowed) return _releasePausedResult();

    await _emitStatus(syncing: true);
    var synced = 0;
    var failed = 0;
    var supersededLocal = false;
    var passSummary = const SyncPassSummary();

    for (final kind in LegacyOperationKind.values) {
      if (!_drainsAllowed) {
        await _emitStatus();
        return _releasePausedResult(synced: synced, failed: failed);
      }
      final result = await _drainLegacyKind(kind, generation, source);
      synced += result.synced;
      failed += result.failed;
      passSummary = passSummary.merge(result.summary);
      supersededLocal = supersededLocal || result.supersededLocal;
      if (!_ownsGeneration(generation) || result.supersededSession) {
        return _pausedResult(synced: synced, failed: failed);
      }
      if (!_drainsAllowed) {
        await _emitStatus();
        return _releasePausedResult(synced: synced, failed: failed);
      }
      if (result.paused) {
        await _emitStatus();
        return _pausedResult(synced: synced, failed: failed);
      }
    }

    // Shared hymn operations use the same typed classifier and durable state,
    // but remain deliberately outside private-owner inventory and purge.
    // S2 Goal A.7: this drain is the authoritative execution boundary, so it
    // hands the hymn push the same `source` the legacy kinds were given — the
    // provenance is decided here once, never re-derived further down.
    try {
      final hymnStore = HymnStore();
      final pushed = await hymnStore.pushPending(source: source);
      if (!_ownsGeneration(generation)) {
        return _pausedResult(synced: synced, failed: failed);
      }
      if (pushed > 0) synced += pushed;
      if (!_drainsAllowed) {
        await _emitStatus();
        return _releasePausedResult(synced: synced, failed: failed);
      }
      if (ConnectivityService().hasLink) {
        await hymnStore.pullChanges();
        if (!_ownsGeneration(generation)) {
          return _pausedResult(synced: synced, failed: failed);
        }
        await MezmurDownloadManager.instance.syncPins();
      }
    } catch (error) {
      if (!_ownsGeneration(generation)) {
        return _pausedResult(synced: synced, failed: failed);
      }
      failed++;
      await _db.logSync('hymns', '$error', 'error');
    }

    if (!_ownsGeneration(generation)) {
      return _pausedResult(synced: synced, failed: failed);
    }
    // Each legacy kind is deliberately bounded to 100 claims per pass. Queue
    // another pass for larger due backlogs instead of leaving row 101 for an
    // unrelated lifecycle event.
    final hasMoreDueLegacy = await _db.hasDueLegacyOutbox();
    if (!_ownsGeneration(generation)) {
      return _pausedResult(synced: synced, failed: failed);
    }
    if (hasMoreDueLegacy) _queued = true;

    await _db.cleanupSynced();
    if (!_ownsGeneration(generation)) {
      return _pausedResult(synced: synced, failed: failed);
    }
    await _emitStatus();

    final nextAttempt = await _db.nextOutboxAttemptAt(
      ownerUserId: _api.userId,
      authorizationVersion: _api.authorizationVersion,
    );
    if (!_ownsGeneration(generation)) {
      return _pausedResult(synced: synced, failed: failed);
    }
    if (nextAttempt != null) {
      // S2 Goal A.4 — THE opportunity request. This is the one place in the
      // app that knows, authoritatively, both that durable work remains and
      // when it next becomes eligible: `nextAttempt` is read from the outbox
      // itself (legacy tables UNION pending_hymn_ops), not guessed.
      //
      // It asks the platform for a future execution opportunity. It does not
      // claim, does not drain, does not queue and does not decide
      // eligibility — when the opportunity is eventually granted it re-enters
      // through `_coordinator.execute()` -> `runSyncNow()` -> this same
      // drain, and the claim query decides what is actually due.
      //
      // `notBefore` is advisory: the OS may run us late, early or twice, and
      // the claim still admits only rows whose `next_attempt_at` has passed.
      //
      // NOTE this is deliberately OUTSIDE the connectivity check below. The
      // foreground nudge is pointless without a link, but an opportunity is
      // most valuable exactly then: `requiresNetwork: true` lets the OS wake
      // us when connectivity returns, which is the case the in-app timer
      // cannot cover because the process may not be alive.
      //
      // unawaited + catchError: a scheduling failure must never fail or delay
      // a drain. The coordinator resets its own pending flag before
      // rethrowing, so the next drain simply requests again.
      unawaited(
        _coordinator
            .requestOpportunity(notBefore: nextAttempt)
            .catchError((Object _) {}),
      );
      if (force || ConnectivityService().hasLink) {
        final wait = nextAttempt.difference(DateTime.now().toUtc());
        nudge(delay: wait <= Duration.zero ? Duration.zero : wait);
      }
    }

    // S1 telemetry semantics. The pre-S1 event said `success: failed == 0`
    // with a row count, so one operation that failed three times and then
    // succeeded was indistinguishable from three lost operations. The pass
    // event below reports operations, attempts and retries separately, so
    // that case reads as 1 operation / 4 attempts / 3 retries / 1 success.
    //
    // Attempt-level detail is NOT sent per attempt: it is already durable in
    // the local `sync_attempts` ledger, and emitting one request per attempt
    // would scale telemetry traffic with the retry count of a device that is,
    // by definition, having network trouble.
    //
    // unawaited: telemetry must never delay or fail a drain.
    if (!passSummary.isEmpty) {
      unawaited(TelemetryService.instance.recordSyncPass(passSummary));
    }

    // Inventory-scoped truth (not pass-scoped): _emitStatus() above refreshed
    // _lastStatus from the outbox itself, so the message can never claim
    // "nothing waiting" while rows remain in backoff/in-flight.
    final feedback = SyncFeedback.describe(
      synced: synced,
      failed: failed,
      status: _lastStatus,
      online: ConnectivityService().hasLink,
      drainsPaused: !_drainsAllowed,
      supersededLocal: supersededLocal,
    );
    return SyncResult(
      synced: synced,
      failed: failed,
      message: feedback.message,
    );
  }

  Future<_LegacyDrainStats> _drainLegacyKind(
      LegacyOperationKind kind, int generation,
      [SyncExecutionSource source = SyncExecutionSource.foreground]) async {
    var synced = 0;
    var failed = 0;
    var supersededLocal = false;
    // S1 counters. `operations` counts distinct logical operations touched in
    // this pass; `attempts` counts real transmissions. They differ whenever
    // anything was retried, which is exactly the distinction S0 telemetry
    // could not express.
    final touchedOperations = <String>{};
    var attemptsMade = 0;
    var operationsSucceeded = 0;
    var operationsWaitingRetry = 0;
    var operationsNeedingAttention = 0;

    for (var guard = 0; guard < 100; guard++) {
      // Do not abandon an HTTP result already in flight: it must settle by its
      // exact operation id. The gate is checked between claims so a remote
      // pause prevents the next transmission without corrupting row state.
      if (!_drainsAllowed) break;
      if (!_ownsGeneration(generation)) {
        return _LegacyDrainStats(
          synced: synced,
          failed: failed,
          supersededLocal: supersededLocal,
          supersededSession: true,
        );
      }
      final claim = await _db.claimNextLegacyOperation(
        kind: kind,
        ownerUserId: _api.userId,
        authorizationVersion: _api.authorizationVersion,
        runtimeGeneration: generation,
        executionSource: source,
      );
      if (!_ownsGeneration(generation)) {
        return _LegacyDrainStats(
          synced: synced,
          failed: failed,
          supersededLocal: supersededLocal,
          supersededSession: true,
        );
      }
      if (claim == null) break;

      touchedOperations.add(claim.operation.clientOpId);
      attemptsMade++;
      final response = await _sendLegacyClaim(claim, source);
      if (!_ownsGeneration(generation) || response.sessionSuperseded) {
        return _LegacyDrainStats(
          synced: synced,
          failed: failed,
          supersededLocal: supersededLocal,
          supersededSession: true,
        );
      }
      final evidence = response.toOutboxEvidence(
        automaticAttemptCount: claim.attemptCount,
      );
      final decision = classifyOutboxResponse(evidence);
      if (decision == OutboxDecision.supersededSession) {
        return _LegacyDrainStats(
          synced: synced,
          failed: failed,
          supersededLocal: supersededLocal,
          supersededSession: true,
        );
      }
      if (decision == OutboxDecision.supersededLocal) {
        supersededLocal = true;
        continue;
      }

      final heldMessage = decision == OutboxDecision.needsAttention &&
              evidence.requiresReceiptValidation
          ? _confirmationHeldMessage
          : null;
      final settlement = _legacySettlement(decision, response, claim,
          failureMessage: heldMessage);
      final category = classifySyncErrorCategory(evidence);
      final result = await _db.settleLegacyOperation(
        claim: claim,
        settlement: settlement,
        currentOwnerUserId: _api.userId,
        currentAuthorizationVersion: _api.authorizationVersion,
        currentRuntimeGeneration: generation,
        attemptClosure: SyncAttemptClosure(
          category: category,
          decision: describeRetryDecision(
            decision: decision,
            category: category,
            serverDictatedDelay: response.retryAfterSeconds != null,
            // classifyOutboxResponse escalates an unknown failure to
            // needsAttention only once the automatic budget is spent.
            unknownBudgetExhausted: claim.attemptCount >= 5,
          ),
          httpStatus: response.statusCode,
          failureMessage:
              decision == OutboxDecision.accepted
                  ? null
                  : heldMessage ?? response.message,
          nextAttemptAt: settlement.nextAttemptAt,
          serverRef: response.serverRequestId,
        ),
      );
      if (result == LegacySettlementResult.supersededSession) {
        return _LegacyDrainStats(
          synced: synced,
          failed: failed,
          supersededLocal: supersededLocal,
          supersededSession: true,
        );
      }
      if (result == LegacySettlementResult.supersededLocal) {
        supersededLocal = true;
        continue;
      }
      if (result != LegacySettlementResult.applied) continue;
      if (decision == OutboxDecision.pauseForAuthentication ||
          decision == OutboxDecision.pauseForAuthorizationScope) {
        return _LegacyDrainStats(
          synced: synced,
          failed: failed,
          supersededLocal: supersededLocal,
          supersededSession: false,
          paused: true,
        );
      }
      if (decision == OutboxDecision.accepted) {
        synced++;
        operationsSucceeded++;
        lastError = '';
      } else if (decision == OutboxDecision.retryable) {
        failed++;
        operationsWaitingRetry++;
        lastError = response.message ?? 'Could not send yet.';
      } else if (decision == OutboxDecision.needsAttention ||
          decision == OutboxDecision.resolvedConflict) {
        failed++;
        operationsNeedingAttention++;
        lastError = heldMessage ??
            response.message ??
            'The school did not accept this work.';
      }
    }

    return _LegacyDrainStats(
      synced: synced,
      failed: failed,
      supersededLocal: supersededLocal,
      supersededSession: false,
      summary: SyncPassSummary(
        operationsAttempted: touchedOperations.length,
        attemptsMade: attemptsMade,
        operationsSucceeded: operationsSucceeded,
        operationsWaitingRetry: operationsWaitingRetry,
        operationsNeedingAttention: operationsNeedingAttention,
      ),
    );
  }

  Future<ApiResponse> _sendLegacyClaim(
      LegacyClaimSnapshot claim, SyncExecutionSource source) {
    final operation = claim.operation;
    final attemptUid = claim.attemptUid;
    final rows = claim.records
        .map((row) => Map<String, dynamic>.from(row))
        .toList(growable: false);
    switch (operation.kind) {
      case LegacyOperationKind.attendance:
        final classId = _asInt(operation.naturalKey['class_id']);
        final date = '${operation.naturalKey['date'] ?? ''}';
        final records = rows
            .map((row) => <String, dynamic>{
                  'member_id': row['member_id'],
                  'status': row['status'],
                  'notes': row['notes'] ?? row['note'] ?? '',
                })
            .toList(growable: false);
        return operation.packetKind == LegacyPacketKind.submitted
            ? _api.submitAttendance(classId, date, records,
                clientOpId: operation.clientOpId,
                attemptUid: attemptUid,
                attemptNumber: claim.attemptCount,
                executionSource: source.storageValue)
            : _api.saveAttendance(classId, date, records,
                clientOpId: operation.clientOpId,
                attemptUid: attemptUid,
                attemptNumber: claim.attemptCount,
                executionSource: source.storageValue);
      case LegacyOperationKind.grades:
        final assessmentId = _asInt(operation.naturalKey['assessment_id']);
        final grades = rows
            .map((row) => <String, dynamic>{
                  'member_id': row['member_id'],
                  'score': row['score'],
                  'remark': row['remark'] ?? '',
                  'record_id': row['record_id'],
                })
            .toList(growable: false);
        return operation.packetKind == LegacyPacketKind.submitted
            ? _api.submitGrades(assessmentId, grades,
                clientOpId: operation.clientOpId,
                attemptUid: attemptUid,
                attemptNumber: claim.attemptCount,
                executionSource: source.storageValue)
            : _api.saveGrades(assessmentId, grades,
                clientOpId: operation.clientOpId,
                attemptUid: attemptUid,
                attemptNumber: claim.attemptCount,
                executionSource: source.storageValue);
      case LegacyOperationKind.mezmur:
        final date = '${operation.naturalKey['date'] ?? ''}';
        final section = '${operation.naturalKey['section'] ?? ''}';
        final records = rows
            .map((row) => <String, dynamic>{
                  'member_id': row['member_id'],
                  'status': row['status'],
                  'notes': row['notes'] ?? '',
                })
            .toList(growable: false);
        return _api.saveMezmurSheet(
          date,
          records,
          section: section,
          kind: operation.packetKind == LegacyPacketKind.submitted
              ? 'submitted'
              : 'draft',
          clientOpId: operation.clientOpId,
          attemptUid: attemptUid,
          attemptNumber: claim.attemptCount,
          executionSource: source.storageValue,
        );
      case LegacyOperationKind.hr:
        final date = '${operation.naturalKey['date'] ?? ''}';
        final section = '${operation.naturalKey['section'] ?? ''}';
        final records = rows
            .map((row) => <String, dynamic>{
                  'member_id': row['member_id'],
                  'status': row['status'],
                  'notes': row['notes'] ?? '',
                })
            .toList(growable: false);
        return _api.saveHrSheet(
          date,
          records,
          section: section,
          kind: operation.packetKind == LegacyPacketKind.submitted
              ? 'submitted'
              : 'draft',
          clientOpId: operation.clientOpId,
          attemptUid: attemptUid,
          attemptNumber: claim.attemptCount,
          executionSource: source.storageValue,
        );
    }
  }

  LegacySettlement _legacySettlement(OutboxDecision decision,
      ApiResponse response, LegacyClaimSnapshot claim,
      {String? failureMessage}) {
    final message =
        failureMessage ?? response.message ?? 'Could not send this work.';
    final kind = switch (decision) {
      OutboxDecision.accepted => LegacySettlementKind.accepted,
      OutboxDecision.retryable => LegacySettlementKind.retryable,
      OutboxDecision.needsAttention => LegacySettlementKind.needsAttention,
      OutboxDecision.pauseForAuthentication =>
        LegacySettlementKind.pausedAuthentication,
      OutboxDecision.pauseForAuthorizationScope =>
        LegacySettlementKind.pausedAuthorizationScope,
      OutboxDecision.resolvedConflict => LegacySettlementKind.resolvedConflict,
      OutboxDecision.supersededSession || OutboxDecision.supersededLocal =>
        throw StateError('Superseded work must not be settled.'),
    };
    final isAccepted = decision == OutboxDecision.accepted;
    // Exact atomic receipt: capture server's exact response for attendance + grades (v42).
    String? receiptJson;
    if (isAccepted && (claim.operation.kind == LegacyOperationKind.attendance || claim.operation.kind == LegacyOperationKind.grades)) {
      try {
        final data = response.data;
        receiptJson = data != null ? data.toString() : null;
        // Prefer JSON encoding when data is a Map/List for faithful receipt.
        if (data is Map || data is List) {
          receiptJson = _encodeReceipt(data);
        }
      } catch (_) {
        receiptJson = null;
      }
    }
    return LegacySettlement(
      kind: kind,
      failureCode: response.errorCode,
      failureHttpStatus: response.statusCode == 0 ? null : response.statusCode,
      failureMessage: isAccepted ? null : message,
      nextAttemptAt: decision == OutboxDecision.retryable
          ? nextOutboxAttemptAt(
              attemptCount: claim.attemptCount,
              retryAfterSeconds: response.retryAfterSeconds,
              randomUnit: _random.nextDouble(),
            )
          : null,
      receiptJson: receiptJson,
      serverRequestId: response.serverRequestId,
      httpStatus: response.statusCode == 0 ? null : response.statusCode,
    );
  }

  String _encodeReceipt(dynamic data) {
    try {
      return jsonEncode(data);
    } catch (_) {
      return '$data';
    }
  }

  SyncResult _pausedResult({int synced = 0, int failed = 0}) => SyncResult(
        synced: synced,
        failed: failed,
        message: 'Sync paused until this account is active again.',
      );

  SyncResult _releasePausedResult({int synced = 0, int failed = 0}) =>
      SyncResult(
        synced: synced,
        failed: failed,
        message: 'Sending is temporarily paused. Your work remains saved.',
      );

  /// Download half of synchronization: pull server attendance changes
  /// into the local cache.
  ///
  /// Kept separate from the outbox drain because the two fail
  /// independently — being unable to reach the feed must not make a
  /// pending upload look failed, and vice versa. Never throws, so a
  /// sync problem cannot stop the app opening offline.
  Future<void> pullServerChanges({int? generation}) async {
    final gen = generation ?? sessionGenerationProvider?.call() ?? 0;
    if (!_ownsGeneration(gen) || !_api.isLoggedIn) return;
    try {
      final outcome = await AttendanceSyncCoordinator().pull();
      if (!_ownsGeneration(gen)) return;
      if (outcome.changedLocalData) {
        // Local data moved underneath whatever is on screen; the
        // existing status stream is how screens already learn to reread
        // the local database.
        await _emitStatus();
      }
    } catch (_) {
      // Download failures are recorded in sync_state by the coordinator.
    }
  }

  Future<void> cacheForOffline() async {
    final generation = sessionGenerationProvider?.call() ?? 0;
    if (!_ownsGeneration(generation) || !_api.isLoggedIn) return;
    // Startup / session restore / foreground return all land here, which
    // makes it the natural place to reconcile downwards as well.
    await pullServerChanges(generation: generation);
    if (!_ownsGeneration(generation)) return;
    try {
      final dashRes = await _api.getDashboardStats();
      if (!dashRes.sessionSuperseded &&
          _ownsGeneration(generation) &&
          dashRes.success &&
          dashRes.data != null) {
        await _db.cacheDashboardStats(dashRes.data, _api.userRole);
      }
    } catch (_) {}
    if (!_ownsGeneration(generation)) return;
    try {
      await CatalogService().classes();
    } catch (_) {}
  }

  Future<void> _emitStatus({bool? syncing}) async {
    final pa = await _db.getPendingAttendanceCount();
    final pg = await _db.getPendingGradesCount();
    final pm = await _db.getPendingMezmurCount();
    final phr = await _db.getPendingHrCount();
    final ph = await _db.getPendingHymnOpsCount();
    final inventory = await _db.getOutboxInventory();
    _lastStatus = SyncStatus(
        pendingAttendance: pa,
        pendingGrades: pg,
        pendingMezmur: pm,
        pendingHr: phr,
        pendingHymns: ph,
        rejected: inventory.needsAttention,
        retryableDue: inventory.retryableDue,
        retryableWaiting: inventory.retryableWaiting,
        inFlight: inventory.inFlight,
        pausedAuth: inventory.pausedAuth,
        pausedScope: inventory.pausedScope,
        blockedDependency: inventory.blockedDependency,
        resolvedConflict: inventory.resolvedConflict,
        privateUnresolvedTotal: inventory.privateUnresolvedTotal,
        sharedHymnUnresolvedTotal: inventory.sharedHymnUnresolvedTotal,
        communicationDraftCount: inventory.communicationDraftCount,
        syncing: syncing ?? (_inflight != null));
    _syncController.add(_lastStatus);
  }

  Future<void> emitCurrentStatus() async => _emitStatus();

  void dispose() {
    stopAutoSync();
    _syncController.close();
  }
}

final class _LegacyDrainStats {
  const _LegacyDrainStats({
    required this.synced,
    required this.failed,
    required this.supersededLocal,
    required this.supersededSession,
    this.paused = false,
    this.summary = const SyncPassSummary(),
  });

  final int synced;
  final int failed;
  final bool supersededLocal;
  final bool supersededSession;
  final bool paused;

  /// Operation- and attempt-shaped counters for this kind (S1). `synced`
  /// and `failed` stay row-shaped so existing callers are unaffected.
  final SyncPassSummary summary;
}

class SyncStatus {
  final int pendingAttendance;
  final int pendingGrades;
  final int pendingMezmur;
  final int pendingHr;
  final int pendingHymns;
  final int retryableDue;
  final int retryableWaiting;
  final int inFlight;
  final int pausedAuth;
  final int pausedScope;
  final int blockedDependency;
  final int resolvedConflict;
  final int privateUnresolvedTotal;
  final int sharedHymnUnresolvedTotal;
  final int communicationDraftCount;
  final bool syncing;

  /// F8: batches the school's workflow refused (kept on this phone
  /// until reviewed or discarded). Included in totalPending — they
  /// are genuinely "not yet sent" — but called out separately so no
  /// screen can claim they are merely waiting for the network.
  final int rejected;
  bool get needsAttention => rejected > 0;

  int get totalPending => pendingAttendance +
      pendingGrades +
      pendingMezmur +
      pendingHr +
      pendingHymns;
  String get breakdown {
    if (totalPending <= 0 && rejected <= 0) return 'All synced';
    final parts = <String>[];
    if (pendingAttendance > 0) {
      parts.add(
          '$pendingAttendance attendance sheet${pendingAttendance == 1 ? '' : 's'}');
    }
    if (pendingGrades > 0) {
      parts.add('$pendingGrades grade list${pendingGrades == 1 ? '' : 's'}');
    }
    if (pendingMezmur > 0) {
      parts.add('$pendingMezmur mezmur sheet${pendingMezmur == 1 ? '' : 's'}');
    }
    if (pendingHr > 0) {
      parts.add('$pendingHr HR sheet${pendingHr == 1 ? '' : 's'}');
    }
    if (pendingHymns > 0) {
      parts.add('$pendingHymns hymn change${pendingHymns == 1 ? '' : 's'}');
    }
    if (rejected > 0) {
      parts.add('$rejected need${rejected == 1 ? 's' : ''} attention');
    }
    return parts.join(' · ');
  }

  SyncStatus(
      {required this.pendingAttendance,
      required this.pendingGrades,
      this.pendingMezmur = 0,
      this.pendingHr = 0,
      this.pendingHymns = 0,
      this.rejected = 0,
      this.retryableDue = 0,
      this.retryableWaiting = 0,
      this.inFlight = 0,
      this.pausedAuth = 0,
      this.pausedScope = 0,
      this.blockedDependency = 0,
      this.resolvedConflict = 0,
      this.privateUnresolvedTotal = 0,
      this.sharedHymnUnresolvedTotal = 0,
      this.communicationDraftCount = 0,
      required this.syncing});
}

class SyncResult {
  final int synced;
  final int failed;
  final String message;
  SyncResult(
      {required this.synced, required this.failed, required this.message});
}

/// Severity of a [SyncFeedback]. UI maps: success → green, attention →
/// warning/danger, pending → muted/info. Deliberately UI-agnostic (no theme
/// import in the service layer).
enum SyncFeedbackKind { success, attention, pending }

/// Canonical, inventory-scoped answer to "did my work send?" — the single
/// source of truth for every sync-feedback surface (manual-sync SnackBars,
/// drain result messages).
///
/// [SyncResult] alone is pass-scoped (what *this drain* did: synced/failed),
/// which produced the false "Nothing waiting to send" green bar while rows
/// sat in backoff (`retryableWaiting`) or mid-flight. This helper pairs the
/// pass counts with the post-drain inventory snapshot ([status]) so the
/// sentence always describes what REMAINS, and [kind] never reports success
/// while work remains.
class SyncFeedback {
  final String message;
  final SyncFeedbackKind kind;
  const SyncFeedback(this.message, this.kind);

  static SyncFeedback describe({
    required int synced,
    required int failed,
    required SyncStatus status,
    required bool online,
    required bool drainsPaused,
    bool supersededLocal = false,
  }) {
    final attention =
        status.rejected + status.blockedDependency + status.resolvedConflict;
    final paused = status.pausedAuth + status.pausedScope;
    final remaining =
        status.retryableDue + status.retryableWaiting + status.inFlight;

    // 1. Anything the user must review or that is parked by the system.
    if (attention + paused > 0) {
      final parts = <String>[];
      if (attention > 0) {
        parts.add('$attention need${attention == 1 ? 's' : ''} review');
      }
      if (paused > 0) parts.add('$paused paused');
      return SyncFeedback(
          '${parts.join(' · ')} — open Sync Center', SyncFeedbackKind.attention);
    }
    // 2. Runtime gate closed: rows are saved but nothing is being attempted.
    if (drainsPaused) {
      return const SyncFeedback('Sync is paused — your work stays saved on this phone',
          SyncFeedbackKind.attention);
    }
    // 3. Work remains: say so, with the honest reason.
    if (remaining > 0) {
      if (supersededLocal && synced == 0 && failed == 0) {
        return const SyncFeedback(
            'Local work changed while sending. Checking the new copy.',
            SyncFeedbackKind.pending);
      }
      if (!online) {
        return SyncFeedback(
            'Offline — $remaining saved on this phone, sends automatically',
            SyncFeedbackKind.pending);
      }
      final sentPrefix = synced > 0 ? 'Sent $synced · ' : '';
      return SyncFeedback('${sentPrefix}$remaining still waiting — retrying automatically',
          SyncFeedbackKind.pending);
    }
    // 4. Outbox truly empty.
    if (synced > 0) {
      return const SyncFeedback('All sent ✓', SyncFeedbackKind.success);
    }
    if (failed > 0) {
      // Defensive: failed>0 with an empty outbox should be unreachable
      // (a failed op stays retryable/attention), but never claim success.
      return const SyncFeedback('Could not send yet. Will retry on its own.',
          SyncFeedbackKind.attention);
    }
    return const SyncFeedback(
        'Already up to date', SyncFeedbackKind.pending);
  }
}
