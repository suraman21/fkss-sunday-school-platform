import 'attendance_preparation_store.dart';
import 'legacy_outbox_models.dart';

/// Truthful readiness evaluation for a durable preparation snapshot.
///
/// Inactive: no existing app code calls this. Future UI will use it to decide
/// whether to show "Ready offline" vs "Needs refresh / blocked / stale".
///
/// Readiness is strict and fail-closed:
/// - No snapshot → not ready (missing)
/// - Snapshot owner/AV/generation mismatch → not ready (stale session)
/// - Lease expired (now >= deadline) → not ready (blocked)
/// - Clock apparently before issued_at → not ready (suspect clock)
/// - Otherwise → ready, even for complete-empty (distinct from failed)
///
/// This does NOT grant capture permission; capture still checks per-day
/// eligibility, calendar, workflow and server authority at save time.
/// It also does NOT extend lease on clock rollback; expired stays expired.
final class AttendancePreparationReadiness {
  final bool isReady;
  final AttendanceReadinessReason reason;
  final AttendancePreparationSnapshot? snapshot;

  const AttendancePreparationReadiness._({
    required this.isReady,
    required this.reason,
    required this.snapshot,
  });

  /// Evaluate readiness for [expectedSession] at [now].
  ///
  /// [snapshot] is the durable row (may be null). [now] is device time
  /// in UTC; caller supplies it so tests can control time deterministically.
  static AttendancePreparationReadiness evaluate({
    required AttendancePreparationSnapshot? snapshot,
    required LegacyWriteSession expectedSession,
    required DateTime now,
  }) {
    if (snapshot == null) {
      return const AttendancePreparationReadiness._(
        isReady: false,
        reason: AttendanceReadinessReason.missing,
        snapshot: null,
      );
    }
    if (!expectedSession.isValid) {
      return AttendancePreparationReadiness._(
        isReady: false,
        reason: AttendanceReadinessReason.staleSession,
        snapshot: snapshot,
      );
    }
    if (snapshot.ownerUserId != expectedSession.ownerUserId ||
        snapshot.authorizationVersion != expectedSession.authorizationVersion) {
      return AttendancePreparationReadiness._(
        isReady: false,
        reason: AttendanceReadinessReason.staleSession,
        snapshot: snapshot,
      );
    }
    if (snapshot.generation != expectedSession.runtimeGeneration) {
      return AttendancePreparationReadiness._(
        isReady: false,
        reason: AttendanceReadinessReason.staleSession,
        snapshot: snapshot,
      );
    }
    final nowSec = now.toUtc().millisecondsSinceEpoch ~/ 1000;
    if (nowSec < snapshot.issuedAt) {
      return AttendancePreparationReadiness._(
        isReady: false,
        reason: AttendanceReadinessReason.clockSkew,
        snapshot: snapshot,
      );
    }
    if (nowSec >= snapshot.deadline) {
      return AttendancePreparationReadiness._(
        isReady: false,
        reason: AttendanceReadinessReason.expired,
        snapshot: snapshot,
      );
    }
    // Complete-empty is ready (distinct from failed), but we still mark it
    // as ready; UI will show "no classes" rather than "not ready".
    return AttendancePreparationReadiness._(
      isReady: true,
      reason: AttendanceReadinessReason.ready,
      snapshot: snapshot,
    );
  }

  /// Convenience: whether new offline writes should be blocked.
  ///
  /// Blocked when not ready for any reason except missing (which is also
  /// blocked, but caller may distinguish).
  bool get shouldBlockWrites => !isReady;
}

enum AttendanceReadinessReason {
  ready,
  missing,
  staleSession,
  expired,
  clockSkew,
}
