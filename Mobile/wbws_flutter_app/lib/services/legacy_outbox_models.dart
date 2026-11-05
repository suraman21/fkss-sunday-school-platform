import 'dart:collection';

/// Pure operation-identity contracts for the four packet-style outboxes.
enum LegacyOperationKind {
  attendance,
  grades,
  mezmur,
  hr,
}

enum LegacyPacketKind {
  draft,
  submitted,
}

enum LegacySettlementKind {
  accepted,
  retryable,
  needsAttention,
  pausedAuthentication,
  pausedAuthorizationScope,
  resolvedConflict,
}

enum LegacySettlementResult {
  applied,
  supersededLocal,
  supersededSession,
}

enum SubmitUndoResult {
  applied,
  alreadyClaimed,
  supersededLocal,
  supersededSession,
}

/// Original sheet identity; this is a write fence, not an authorization grant.
final class LegacyWriteSession {
  final int ownerUserId;
  final int authorizationVersion;
  final int runtimeGeneration;

  const LegacyWriteSession({
    required this.ownerUserId,
    required this.authorizationVersion,
    required this.runtimeGeneration,
  });

  bool get isValid =>
      ownerUserId > 0 && authorizationVersion >= 0 && runtimeGeneration >= 0;
}

final class StaleLegacyWriteSession implements Exception {
  const StaleLegacyWriteSession();

  @override
  String toString() =>
      'Sign-in changed. These edits are not saved. Existing saved work was kept.';
}

/// Immutable, PII-light handle for one complete saved packet generation.
final class LegacyOperationRef {
  final LegacyOperationKind kind;
  /// Original WRITE year, only when explicitly captured and coherent.
  /// Null means unknown; never infer from current session or roster fallback.
  /// Not authorization and not a durable acceptance receipt.
  final int? originalAcademicYearId;
  final Map<String, Object?> naturalKey;
  final String clientOpId;
  final LegacyPacketKind packetKind;
  final int ownerUserId;
  final int createdAuthorizationVersion;
  final int runtimeGeneration;

  LegacyOperationRef({
    required this.kind,
    this.originalAcademicYearId,
    required Map<String, Object?> naturalKey,
    required this.clientOpId,
    required this.packetKind,
    required this.ownerUserId,
    required this.createdAuthorizationVersion,
    required this.runtimeGeneration,
  })  : assert(clientOpId.trim().isNotEmpty),
        naturalKey = UnmodifiableMapView(
          Map<String, Object?>.from(naturalKey),
        );
}

/// Coherent payload captured in the same short transaction as durable claim.
/// The transaction is complete before this snapshot can cross an HTTP await.
final class LegacyClaimSnapshot {
  final LegacyOperationRef operation;
  final List<Map<String, Object?>> records;
  final DateTime claimedAt;
  final int attemptCount;

  /// Correlation id for this one transmission (S1).
  ///
  /// Optional so existing callers and tests that build a snapshot directly
  /// keep compiling; the database always supplies it for a real claim.
  final String? attemptUid;

  LegacyClaimSnapshot({
    required this.operation,
    required List<Map<String, Object?>> records,
    required this.claimedAt,
    required this.attemptCount,
    this.attemptUid,
  })  : assert(records.isNotEmpty),
        records = List<Map<String, Object?>>.unmodifiable(
          records.map(
            (row) => UnmodifiableMapView(Map<String, Object?>.from(row)),
          ),
        );
}

/// Typed state transition requested after one claimed request completes.
final class LegacySettlement {
  final LegacySettlementKind kind;
  final String? failureCode;
  final int? failureHttpStatus;
  final String? failureMessage;
  final DateTime? nextAttemptAt;
  // Exact atomic receipt (when kind == accepted and a server response exists).
  // The receipt is stored atomically with the outbox completion — never separately.
  final String? receiptJson;
  final String? serverRequestId;
  final int? httpStatus;

  const LegacySettlement({
    required this.kind,
    this.failureCode,
    this.failureHttpStatus,
    this.failureMessage,
    this.nextAttemptAt,
    this.receiptJson,
    this.serverRequestId,
    this.httpStatus,
  });
}
