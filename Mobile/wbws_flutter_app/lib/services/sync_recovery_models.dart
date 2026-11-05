/// PII-light, immutable contracts used by the global Sync Recovery Center.
///
/// Private rows are constructed only for the active owner and authorization
/// version. Work from an older scope is represented by aggregate counts in
/// OutboxInventory and never becomes a SyncRecoveryItem.
enum SyncRecoveryDomain {
  attendance,
  grades,
  mezmur,
  hr,
  communication,
  hymn,
}

enum SyncRecoveryActionResult {
  applied,
  stale,
  serverConfirmationRequired,
}

final class SyncRecoveryItem {
  final SyncRecoveryDomain domain;
  final String operationId;
  final int? rowId;
  final String state;
  final String title;
  final String detail;
  final String? reason;
  final DateTime? nextAttemptAt;

  const SyncRecoveryItem({
    required this.domain,
    required this.operationId,
    required this.state,
    required this.title,
    required this.detail,
    this.rowId,
    this.reason,
    this.nextAttemptAt,
  });

  bool get isWaiting => state == 'pending' || state == 'in_flight';
  bool get isRetryScheduled => state == 'retry_wait';
  bool get isPaused => state == 'paused_auth' || state == 'paused_scope';
  bool get isConflict => state == 'resolved_conflict';
  bool get needsAttention =>
      state == 'needs_attention' ||
      state == 'failed' ||
      state == 'blocked_dependency';

  // A dependency-blocked operation must be repaired/opened;
  // retrying it before its prerequisite resolves would only hide it again.
  bool get canRetry => state == 'needs_attention' || state == 'failed';
  // A local terminal label is not an exact server-confirmed disposition.
  bool get requiresServerConfirmation =>
      domain == SyncRecoveryDomain.attendance;
  bool get canAcknowledge => !requiresServerConfirmation && isConflict;
  bool get canDiscard =>
      !requiresServerConfirmation && (needsAttention || isConflict);
}
