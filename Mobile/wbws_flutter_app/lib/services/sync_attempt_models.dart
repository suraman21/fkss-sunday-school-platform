/// Durable sync observability vocabulary (phase S1).
///
/// This file deliberately has no Flutter dependency so the categories below
/// are pure, exhaustively testable values and so the SQLite runtime harness
/// can read the schema contract the same way it reads `local_schema_v34.dart`.
///
/// Scope discipline: nothing here executes, schedules, or retries anything.
/// `OutboxState` in `outbox_policy.dart` remains the single authoritative
/// execution state machine. These types only *describe what happened* around
/// that machine so an operation's history can be explained after the fact.
library;

import 'outbox_policy.dart';
import 'session_models.dart';
import 'sync_execution.dart';

/// Why one transmission attempt ended the way it did.
///
/// These are deliberately NOT a generic HTTP-status dump. The SSMS API gives
/// several statuses a specific domain meaning (notably 409, which is used for
/// four unrelated situations), so the categories below follow the real
/// contract in `api/v1/core/middleware.php` and `outbox_policy.dart` instead
/// of inventing a parallel vocabulary.
enum SyncErrorCategory {
  /// The attempt succeeded and the server executed it for the first time.
  none('none'),

  /// The attempt succeeded, but the server answered from its idempotency
  /// record: a previous attempt of this same operation had already been
  /// applied. Not a failure — the distinction matters for lineage.
  idempotencyReplay('IDEMPOTENCY_REPLAY'),

  // ── transport ───────────────────────────────────────────────────────────
  networkUnavailable('NETWORK_UNAVAILABLE'),
  timeout('TIMEOUT'),
  dnsFailure('DNS_FAILURE'),
  tlsFailure('TLS_FAILURE'),

  // ── authentication / authorization ──────────────────────────────────────
  authExpired('AUTH_EXPIRED'),
  authScopeChanged('AUTH_SCOPE_CHANGED'),
  permissionDenied('HTTP_403'),

  // ── request rejected on its own merits ──────────────────────────────────
  validationError('VALIDATION_ERROR'),
  notFound('HTTP_404'),
  payloadRejected('PAYLOAD_REJECTED'),

  // ── the four distinct meanings of 409 in this API ───────────────────────
  /// Same idempotency key, different payload. The operation is unrepairable
  /// as transmitted: a replacement must be created instead.
  idempotencyConflict('IDEMPOTENCY_CONFLICT'),

  /// An earlier attempt of this very operation is still executing on the
  /// server and holds the lease. Retrying later is correct.
  idempotencyInProgress('IDEMPOTENCY_IN_PROGRESS'),

  /// Domain workflow refused it (already submitted / workflow rejected).
  workflowRejected('WORKFLOW_REJECTED'),

  /// The server holds a newer revision; a canonical copy may be attached.
  revisionConflict('REVISION_CONFLICT'),

  // ── server side ─────────────────────────────────────────────────────────
  rateLimited('HTTP_429'),
  serverError('SERVER_ERROR'),

  /// A 5xx that the server replayed from its idempotency record. This is the
  /// pinned-failure shape: the stored error answer is returned to every later
  /// retry, so the operation cannot make progress under this operation id.
  serverErrorReplayed('SERVER_ERROR_REPLAYED'),

  serviceUnavailable('SERVICE_UNAVAILABLE'),

  // ── local ───────────────────────────────────────────────────────────────
  localDatabaseError('LOCAL_DB_ERROR'),
  serializationError('SERIALIZATION_ERROR'),
  protocolError('PROTOCOL_ERROR'),
  unknown('UNKNOWN');

  final String storageValue;
  const SyncErrorCategory(this.storageValue);

  static SyncErrorCategory fromStorage(String? value) {
    for (final category in SyncErrorCategory.values) {
      if (category.storageValue == value) return category;
    }
    return SyncErrorCategory.unknown;
  }
}

/// What the system decided to do next, and why — recorded per attempt so
/// "why did it retry?" and "why did it stop?" are answerable after the fact.
enum SyncRetryDecision {
  /// Terminal success; nothing further scheduled.
  completed('COMPLETED'),

  /// Backoff ladder scheduled the next attempt.
  retryScheduled('RETRY_SCHEDULED'),

  /// The server dictated the delay via `Retry-After`.
  retryAfterServerDelay('RETRY_AFTER_SERVER_DELAY'),

  /// The bounded-unknown budget was exhausted; escalated to the user.
  retryLimitReached('RETRY_LIMIT_REACHED'),

  /// Stopped: a person must look at it.
  userActionRequired('USER_ACTION_REQUIRED'),

  /// Stopped: the credential must be renewed before anything can be sent.
  authRefreshRequired('AUTH_REFRESH_REQUIRED'),

  /// Stopped: authorization scope changed under the pending work.
  authorizationScopeChanged('AUTHORIZATION_SCOPE_CHANGED'),

  /// Stopped: a newer canonical revision must be reconciled first.
  conflictRequiresResolution('CONFLICT_REQUIRES_RESOLUTION'),

  /// The attempt's process disappeared before the result could be recorded.
  /// Written by the startup recovery sweep, never by a live attempt.
  interrupted('INTERRUPTED'),

  /// The attempt result could not be applied because the session or the local
  /// row generation moved on. The row was left untouched by design.
  superseded('SUPERSEDED'),

  /// Still in flight; no decision has been reached yet.
  pending('PENDING');

  final String storageValue;
  const SyncRetryDecision(this.storageValue);

  static SyncRetryDecision fromStorage(String? value) {
    for (final decision in SyncRetryDecision.values) {
      if (decision.storageValue == value) return decision;
    }
    return SyncRetryDecision.pending;
  }
}

/// The settled shape of a whole operation, derived from its attempts.
enum SyncOperationOutcome {
  inProgress('in_progress'),
  succeeded('succeeded'),
  waitingRetry('waiting_retry'),
  needsAttention('needs_attention'),
  paused('paused');

  final String storageValue;
  const SyncOperationOutcome(this.storageValue);
}

/// Maps already-classified response evidence onto an error category.
///
/// This is intentionally a *projection* of the evidence the existing policy
/// already consumes — it does not re-decide anything. `classifyOutboxResponse`
/// stays the only function that decides what happens to the row.
SyncErrorCategory classifySyncErrorCategory(OutboxResponseEvidence evidence) {
  if (evidence.refreshOutcome == AuthRefreshOutcome.scopeChanged) {
    return SyncErrorCategory.authScopeChanged;
  }
  if (evidence.refreshOutcome == AuthRefreshOutcome.rejected) {
    return SyncErrorCategory.authExpired;
  }

  if (evidence.requiresReceiptValidation) {
    // Preserve the authoritative policy's auth precedence, but never label a
    // held observation as successful execution or idempotency replay.
    final decision = classifyOutboxResponse(evidence);
    if (decision == OutboxDecision.pauseForAuthentication) {
      return SyncErrorCategory.authExpired;
    }
    if (decision == OutboxDecision.pauseForAuthorizationScope) {
      return SyncErrorCategory.authScopeChanged;
    }
    return SyncErrorCategory.protocolError;
  }

  final status = evidence.statusCode;

  // Success first: a replayed 2xx is a materially different fact from a fresh
  // 2xx, because it proves an earlier attempt of this operation already won.
  if (evidence.success && status >= 200 && status < 300) {
    if (evidence.failureKind == ApiFailureKind.protocol) {
      return SyncErrorCategory.protocolError;
    }
    return evidence.idempotencyReplayed
        ? SyncErrorCategory.idempotencyReplay
        : SyncErrorCategory.none;
  }

  switch (evidence.failureKind) {
    case ApiFailureKind.timeout:
      return SyncErrorCategory.timeout;
    case ApiFailureKind.transport:
      return SyncErrorCategory.networkUnavailable;
    case ApiFailureKind.authentication:
      return SyncErrorCategory.authExpired;
    case ApiFailureKind.authorizationScope:
      return SyncErrorCategory.authScopeChanged;
    case ApiFailureKind.none:
    case ApiFailureKind.nonSettlement:
    case ApiFailureKind.protocol:
    case ApiFailureKind.http:
    case ApiFailureKind.unknown:
      break;
  }

  switch (evidence.errorCode) {
    case 'AUTH_SCOPE_CHANGED':
    case 'AUTH_SCOPE_REFRESH_REQUIRED':
      return SyncErrorCategory.authScopeChanged;
    case 'INVALID_REFRESH_TOKEN':
    case 'REFRESH_EXPIRED':
    case 'REFRESH_REUSED':
    case 'REFRESH_REVOKED':
    case 'ACCOUNT_DISABLED':
    case 'ACCOUNT_REMOVED':
      return SyncErrorCategory.authExpired;
    case 'IDEMPOTENCY_CONFLICT':
      return SyncErrorCategory.idempotencyConflict;
    case 'IDEMPOTENCY_IN_PROGRESS':
      return SyncErrorCategory.idempotencyInProgress;
    case 'ALREADY_SUBMITTED':
    case 'WORKFLOW_REJECTED':
      return SyncErrorCategory.workflowRejected;
    case 'REVISION_CONFLICT':
      return SyncErrorCategory.revisionConflict;
  }

  if (status == 0) return SyncErrorCategory.networkUnavailable;
  if (status == 408 || status == 425) return SyncErrorCategory.timeout;
  if (status == 429) return SyncErrorCategory.rateLimited;
  if (status == 401) return SyncErrorCategory.authExpired;
  if (status == 403) return SyncErrorCategory.permissionDenied;
  if (status == 404 || status == 405 || status == 410) {
    return SyncErrorCategory.notFound;
  }
  if (status == 413 || status == 415) return SyncErrorCategory.payloadRejected;
  if (status == 400 || status == 422) return SyncErrorCategory.validationError;
  if (status == 409) return SyncErrorCategory.revisionConflict;
  if (status == 503) {
    return evidence.idempotencyReplayed
        ? SyncErrorCategory.serverErrorReplayed
        : SyncErrorCategory.serviceUnavailable;
  }
  if (status >= 500 && status <= 599) {
    // The replayed variant is the one that cannot make progress: the server
    // has pinned this answer against the operation's idempotency key.
    return evidence.idempotencyReplayed
        ? SyncErrorCategory.serverErrorReplayed
        : SyncErrorCategory.serverError;
  }
  if (evidence.failureKind == ApiFailureKind.protocol) {
    return SyncErrorCategory.protocolError;
  }
  return SyncErrorCategory.unknown;
}

/// Projects the authoritative [OutboxDecision] onto the recorded reason.
///
/// The decision itself is NOT recomputed here; it is passed in from the one
/// classifier that owns it. This keeps a single source of truth.
SyncRetryDecision describeRetryDecision({
  required OutboxDecision decision,
  required SyncErrorCategory category,
  bool serverDictatedDelay = false,
  bool unknownBudgetExhausted = false,
}) {
  switch (decision) {
    case OutboxDecision.accepted:
      return SyncRetryDecision.completed;
    case OutboxDecision.retryable:
      if (serverDictatedDelay) return SyncRetryDecision.retryAfterServerDelay;
      return SyncRetryDecision.retryScheduled;
    case OutboxDecision.needsAttention:
      if (unknownBudgetExhausted) return SyncRetryDecision.retryLimitReached;
      return SyncRetryDecision.userActionRequired;
    case OutboxDecision.resolvedConflict:
      return SyncRetryDecision.conflictRequiresResolution;
    case OutboxDecision.pauseForAuthentication:
      return SyncRetryDecision.authRefreshRequired;
    case OutboxDecision.pauseForAuthorizationScope:
      return SyncRetryDecision.authorizationScopeChanged;
    case OutboxDecision.supersededSession:
    case OutboxDecision.supersededLocal:
      return SyncRetryDecision.superseded;
  }
}

/// Everything needed to close one open attempt row.
///
/// Built by the sync service, where the authoritative [OutboxDecision] and
/// the raw response evidence are both in scope, and handed to the database
/// so the close happens inside the same transaction as the settlement.
final class SyncAttemptClosure {
  final SyncErrorCategory category;
  final SyncRetryDecision decision;
  final int? httpStatus;
  final String? failureMessage;
  final DateTime? nextAttemptAt;
  final String? serverRef;

  const SyncAttemptClosure({
    required this.category,
    required this.decision,
    this.httpStatus,
    this.failureMessage,
    this.nextAttemptAt,
    this.serverRef,
  });
}

/// One durable transmission attempt.
final class SyncAttemptRecord {
  final String clientOpId;
  final int attemptNumber;
  final String attemptUid;
  final String domain;
  final int? ownerUserId;
  final DateTime startedAt;
  final DateTime? finishedAt;
  final int? durationMs;
  final int? httpStatus;
  final SyncErrorCategory category;
  final SyncRetryDecision decision;
  final String? failureMessage;
  final DateTime? nextAttemptAt;
  final String? serverRef;

  /// S2 Goal A.1 — which trigger ran the drain that produced this attempt.
  final SyncExecutionSource executionSource;

  const SyncAttemptRecord({
    required this.clientOpId,
    required this.attemptNumber,
    required this.attemptUid,
    required this.domain,
    required this.startedAt,
    this.ownerUserId,
    this.finishedAt,
    this.durationMs,
    this.httpStatus,
    this.category = SyncErrorCategory.unknown,
    this.decision = SyncRetryDecision.pending,
    this.failureMessage,
    this.nextAttemptAt,
    this.serverRef,
    this.executionSource = SyncExecutionSource.foreground,
  });

  bool get isOpen => finishedAt == null;

  /// True when this attempt proves the server had already applied the
  /// operation during an earlier attempt.
  bool get provesEarlierDelivery =>
      category == SyncErrorCategory.idempotencyReplay;

  static DateTime? _time(Object? value) {
    if (value == null) return null;
    return DateTime.tryParse('$value')?.toUtc();
  }

  static int? _int(Object? value) {
    if (value == null) return null;
    if (value is int) return value;
    return int.tryParse('$value');
  }

  factory SyncAttemptRecord.fromRow(Map<String, Object?> row) {
    return SyncAttemptRecord(
      clientOpId: '${row['client_op_id']}',
      attemptNumber: _int(row['attempt_number']) ?? 0,
      attemptUid: '${row['attempt_uid']}',
      domain: '${row['domain']}',
      ownerUserId: _int(row['owner_user_id']),
      startedAt: _time(row['started_at']) ?? DateTime.fromMillisecondsSinceEpoch(0, isUtc: true),
      finishedAt: _time(row['finished_at']),
      durationMs: _int(row['duration_ms']),
      httpStatus: _int(row['http_status']),
      category: SyncErrorCategory.fromStorage(row['error_category'] as String?),
      decision: SyncRetryDecision.fromStorage(row['retry_decision'] as String?),
      failureMessage: row['failure_message'] as String?,
      nextAttemptAt: _time(row['next_attempt_at']),
      serverRef: row['server_ref'] as String?,
      executionSource:
          SyncExecutionSource.fromStorage(row['execution_source']),
    );
  }
}

/// Every attempt belonging to one logical operation, newest attempt last.
final class SyncOperationLineage {
  final String clientOpId;
  final String domain;
  final List<SyncAttemptRecord> attempts;

  const SyncOperationLineage({
    required this.clientOpId,
    required this.domain,
    required this.attempts,
  });

  int get attemptCount => attempts.length;

  /// Retries are attempts after the first one.
  int get retryCount => attempts.isEmpty ? 0 : attempts.length - 1;

  int get failedAttemptCount => attempts
      .where((a) =>
          a.finishedAt != null &&
          a.category != SyncErrorCategory.none &&
          a.category != SyncErrorCategory.idempotencyReplay)
      .length;

  SyncAttemptRecord? get lastAttempt =>
      attempts.isEmpty ? null : attempts.last;

  /// True when the operation reached the server successfully at any point,
  /// including when the winning attempt was answered as an idempotent replay.
  bool get succeeded => attempts.any((a) =>
      a.decision == SyncRetryDecision.completed ||
      a.category == SyncErrorCategory.idempotencyReplay);

  SyncOperationOutcome get outcome {
    if (succeeded) return SyncOperationOutcome.succeeded;
    final last = lastAttempt;
    if (last == null) return SyncOperationOutcome.inProgress;
    if (last.isOpen) return SyncOperationOutcome.inProgress;
    switch (last.decision) {
      case SyncRetryDecision.retryScheduled:
      case SyncRetryDecision.retryAfterServerDelay:
      case SyncRetryDecision.interrupted:
        return SyncOperationOutcome.waitingRetry;
      case SyncRetryDecision.authRefreshRequired:
      case SyncRetryDecision.authorizationScopeChanged:
        return SyncOperationOutcome.paused;
      case SyncRetryDecision.userActionRequired:
      case SyncRetryDecision.retryLimitReached:
      case SyncRetryDecision.conflictRequiresResolution:
        return SyncOperationOutcome.needsAttention;
      case SyncRetryDecision.completed:
        return SyncOperationOutcome.succeeded;
      case SyncRetryDecision.superseded:
      case SyncRetryDecision.pending:
        return SyncOperationOutcome.inProgress;
    }
  }

  /// A one-line, privacy-safe summary for diagnostics and telemetry.
  ///
  /// Contains only identifiers and counts — never payload, member data or
  /// credentials.
  String get summary =>
      '$domain $clientOpId: $attemptCount attempt(s), $retryCount retry(ies), '
      '${outcome.storageValue}';
}

/// Aggregate view of one drain pass, in operation terms rather than row terms.
///
/// Phase S0 telemetry could only say "N failed" per pass, which made one
/// operation that failed three times indistinguishable from three operations
/// that each failed once. These counters remove that ambiguity.
final class SyncPassSummary {
  final int operationsAttempted;
  final int attemptsMade;
  final int operationsSucceeded;
  final int operationsWaitingRetry;
  final int operationsNeedingAttention;

  const SyncPassSummary({
    this.operationsAttempted = 0,
    this.attemptsMade = 0,
    this.operationsSucceeded = 0,
    this.operationsWaitingRetry = 0,
    this.operationsNeedingAttention = 0,
  });

  /// Retries are the attempts beyond the first one for each operation.
  int get retriesMade =>
      attemptsMade > operationsAttempted ? attemptsMade - operationsAttempted : 0;

  bool get isEmpty => operationsAttempted == 0 && attemptsMade == 0;

  SyncPassSummary merge(SyncPassSummary other) => SyncPassSummary(
        operationsAttempted: operationsAttempted + other.operationsAttempted,
        attemptsMade: attemptsMade + other.attemptsMade,
        operationsSucceeded: operationsSucceeded + other.operationsSucceeded,
        operationsWaitingRetry:
            operationsWaitingRetry + other.operationsWaitingRetry,
        operationsNeedingAttention:
            operationsNeedingAttention + other.operationsNeedingAttention,
      );

  /// Privacy-safe telemetry payload: counts and nothing else.
  Map<String, Object?> toTelemetryData() => <String, Object?>{
        'operations': operationsAttempted,
        'attempts': attemptsMade,
        'retries': retriesMade,
        'succeeded': operationsSucceeded,
        'waiting_retry': operationsWaitingRetry,
        'needs_attention': operationsNeedingAttention,
      };
}
