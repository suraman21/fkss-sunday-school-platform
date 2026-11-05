/// Pure-Dart session contracts shared by the mobile coordinator and SQLite
/// layer.  Keeping these types free of Flutter imports makes the state machine
/// deterministic and unit-testable.
enum SessionState {
  anonymousClean('anonymous_clean'),
  active('active'),
  scopeReconciling('scope_reconciling'),
  reauthRequired('reauth_required'),
  orphanedLocalData('orphaned_local_data'),
  purging('purging');

  const SessionState(this.storageValue);
  final String storageValue;

  static SessionState fromStorage(String? value) {
    return SessionState.values.firstWhere(
      (state) => state.storageValue == value,
      orElse: () => SessionState.orphanedLocalData,
    );
  }
}

enum CredentialLoadState {
  complete,
  absent,
  incomplete,
  unreadable,
  storageUnavailable,
}

enum AuthRefreshOutcome {
  sameScope,
  scopeChanged,
  rejected,
  transientFailure,
  superseded,
}

enum SessionRoot {
  active,
  scopeReconciling,
  reauthentication,
  orphanRecovery,
  purging,
  cleanLogin,
  protectionFailure,
}

enum LoginActivationState {
  activated,
  rejected,
  priorOwnerRequired,
  orphanedDataBlocked,
  protectionFailure,
}

enum LogoutChoice { cancel, preserveForReauthentication, discardPrivateData }

class AuthBundle {
  const AuthBundle({
    required this.accessToken,
    required this.refreshToken,
    required this.userId,
    required this.username,
    required this.displayName,
    required this.role,
    required this.authorizationVersion,
    this.userJson = '',
  });

  final String accessToken;
  final String refreshToken;
  final int userId;
  final String username;
  final String displayName;
  final String role;

  /// Zero means a legacy server/profile which supplied no version.  It is
  /// persisted as unknown rather than silently claiming scope version 1.
  final int authorizationVersion;
  final String userJson;

  bool get isComplete =>
      accessToken.isNotEmpty &&
      refreshToken.isNotEmpty &&
      userId > 0 &&
      role.isNotEmpty;
}

class CredentialLoadResult {
  const CredentialLoadResult._(this.state, {this.bundle, this.diagnostic});

  final CredentialLoadState state;
  final AuthBundle? bundle;
  final String? diagnostic;

  const CredentialLoadResult.complete(AuthBundle bundle)
      : this._(CredentialLoadState.complete, bundle: bundle);
  const CredentialLoadResult.absent()
      : this._(CredentialLoadState.absent);
  const CredentialLoadResult.incomplete(String diagnostic)
      : this._(CredentialLoadState.incomplete, diagnostic: diagnostic);
  const CredentialLoadResult.unreadable(String diagnostic)
      : this._(CredentialLoadState.unreadable, diagnostic: diagnostic);
  const CredentialLoadResult.storageUnavailable(String diagnostic)
      : this._(
          CredentialLoadState.storageUnavailable,
          diagnostic: diagnostic,
        );
}

class LocalSessionRecord {
  const LocalSessionRecord({
    required this.state,
    required this.generation,
    this.ownerUserId,
    this.authorizationVersion,
    this.ownerRole,
    this.ownerUsername,
    this.ownerDisplayName,
    this.reauthReason,
    this.updatedAt,
  });

  final SessionState state;
  final int generation;
  final int? ownerUserId;
  final int? authorizationVersion;
  final String? ownerRole;
  final String? ownerUsername;
  final String? ownerDisplayName;
  final String? reauthReason;
  final String? updatedAt;

  bool get hasKnownOwner => ownerUserId != null && ownerUserId! > 0;
}

class LoginActivationResult {
  const LoginActivationResult(this.state, {this.message});

  final LoginActivationState state;
  final String? message;
  bool get activated => state == LoginActivationState.activated;
}

class OutboxInventory {
  const OutboxInventory({
    this.retryableDue = 0,
    this.retryableWaiting = 0,
    this.inFlight = 0,
    this.needsAttention = 0,
    this.pausedAuth = 0,
    this.pausedScope = 0,
    this.blockedDependency = 0,
    this.resolvedConflict = 0,
    this.privateUnresolvedTotal = 0,
    this.sharedHymnUnresolvedTotal = 0,
    this.communicationDraftCount = 0,
  });

  final int retryableDue;
  final int retryableWaiting;
  final int inFlight;
  final int needsAttention;
  final int pausedAuth;
  final int pausedScope;
  final int blockedDependency;
  final int resolvedConflict;
  final int privateUnresolvedTotal;
  final int sharedHymnUnresolvedTotal;
  final int communicationDraftCount;

  int get paused => pausedAuth + pausedScope;
  int get terminalReview =>
      needsAttention + blockedDependency + resolvedConflict;
}

class LocalDataInventory {
  const LocalDataInventory({
    this.attendanceOperations = 0,
    this.gradeOperations = 0,
    this.mezmurOperations = 0,
    this.hrOperations = 0,
    this.communicationPending = 0,
    this.communicationFailed = 0,
    this.communicationDrafts = 0,
    this.attentionOperations = 0,
    this.pausedOperations = 0,
    this.privateCacheRows = 0,
    this.privateOwnerUserIds = const <int>[],
    this.sharedHymnOperations = 0,
  });

  final int attendanceOperations;
  final int gradeOperations;
  final int mezmurOperations;
  final int hrOperations;
  final int communicationPending;
  final int communicationFailed;
  final int communicationDrafts;
  final int attentionOperations;
  final int pausedOperations;
  final int privateCacheRows;
  final List<int> privateOwnerUserIds;

  bool get hasMixedPrivateOwners => privateOwnerUserIds.length > 1;

  /// Shared hymn operations survive private-account cleanup and are never
  /// included in the private-work decision.
  final int sharedHymnOperations;

  int get legacyOperations =>
      attendanceOperations +
      gradeOperations +
      mezmurOperations +
      hrOperations;

  int get communicationWork =>
      communicationPending + communicationFailed + communicationDrafts;

  /// Compatibility views retained from the v34 schema commit. Attention and
  /// paused counts are subsets of the four domain totals, not additive work.
  int get privatePending => legacyOperations + communicationPending;
  int get privateNeedsAttention => attentionOperations;
  int get privatePaused => pausedOperations;
  int get sharedHymnUnresolved => sharedHymnOperations;

  int get privateDurableWork => legacyOperations + communicationWork;
  bool get hasPrivateDurableWork => privateDurableWork > 0;
  bool get hasPrivateData => hasPrivateDurableWork || privateCacheRows > 0;

  LocalDataInventory withAdditionalPrivateCacheRows(int additionalRows) =>
      LocalDataInventory(
        attendanceOperations: attendanceOperations,
        gradeOperations: gradeOperations,
        mezmurOperations: mezmurOperations,
        hrOperations: hrOperations,
        communicationPending: communicationPending,
        communicationFailed: communicationFailed,
        communicationDrafts: communicationDrafts,
        attentionOperations: attentionOperations,
        pausedOperations: pausedOperations,
        privateCacheRows: privateCacheRows + additionalRows,
        privateOwnerUserIds: privateOwnerUserIds,
        sharedHymnOperations: sharedHymnOperations,
      );

  String get workSummary {
    final parts = <String>[];
    if (attendanceOperations > 0) {
      parts.add('$attendanceOperations attendance batch(es)');
    }
    if (gradeOperations > 0) parts.add('$gradeOperations grade batch(es)');
    if (mezmurOperations > 0) parts.add('$mezmurOperations mezmur batch(es)');
    if (hrOperations > 0) parts.add('$hrOperations HR batch(es)');
    if (communicationPending > 0) {
      parts.add('$communicationPending pending message(s)');
    }
    if (communicationFailed > 0) {
      parts.add('$communicationFailed failed message(s)');
    }
    if (communicationDrafts > 0) {
      parts.add('$communicationDrafts nonempty draft(s)');
    }
    if (parts.isEmpty) return 'no unsent private work';
    return parts.join(', ');
  }
}

bool canActivateCandidate({
  required SessionState currentState,
  required int? boundOwnerUserId,
  required int candidateUserId,
  required LocalDataInventory inventory,
}) {
  if (currentState == SessionState.purging ||
      currentState == SessionState.orphanedLocalData ||
      inventory.privateOwnerUserIds.any((id) => id != candidateUserId)) {
    return false;
  }
  if (boundOwnerUserId == null || boundOwnerUserId == candidateUserId) {
    return true;
  }
  return !inventory.hasPrivateDurableWork;
}

SessionState stateForMissingCredentials({
  required int? boundOwnerUserId,
  required LocalDataInventory inventory,
}) {
  if (boundOwnerUserId != null) return SessionState.reauthRequired;
  if (inventory.hasPrivateData) return SessionState.orphanedLocalData;
  return SessionState.anonymousClean;
}

/// A download may not mutate cache or cursor state after its session changes.
final class StaleAttendanceSyncSession implements Exception {
  const StaleAttendanceSyncSession();

  @override
  String toString() => 'Attendance sync session changed; this update was not applied.';
}
