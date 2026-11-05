import 'attendance_preparation_dataset.dart';
import 'attendance_preparation_readiness.dart';
import 'attendance_preparation_store.dart';
import 'legacy_outbox_models.dart';
import 'local_db.dart';
import 'session_models.dart';

/// Result of resolving the local preparation for attendance.
///
/// Inactive: no existing UI calls this yet. The attendance screen will
/// call `resolve()` to decide whether to render from the durable
/// snapshot (with truthful banner) or fall back to legacy cache
/// (with “needs refresh” banner).
///
/// The adapter never synthesizes data, never extends leases, and never
/// deletes the previous snapshot. It only reports what is durably
/// present and whether it is ready for offline use.
final class AttendancePreparationResolution {
  final AttendancePreparationReadiness readiness;
  final AttendancePreparationSnapshot? snapshot;

  const AttendancePreparationResolution._(this.readiness, this.snapshot);

  bool get isReady => readiness.isReady;
  AttendanceReadinessReason get reason => readiness.reason;
  AttendancePreparationDataset? get dataset => snapshot?.dataset;
}

final class AttendancePreparationAdapter {
  final LocalDb _db;
  final AttendancePreparationStore _store;
  final DateTime Function() _now;

  AttendancePreparationAdapter({
    LocalDb? db,
    AttendancePreparationStore? store,
    DateTime Function()? now,
  })  : _db = db ?? LocalDb(),
        _store = store ?? AttendancePreparationStore(),
        _now = now ?? (() => DateTime.now().toUtc());

  /// Resolve the current preparation from durable state.
  ///
  /// Reads the local session record and the durable snapshot, then
  /// evaluates readiness. Returns `missing` when no snapshot was ever
  /// published, `staleSession` when owner/AV/generation mismatches,
  /// `expired`/`clockSkew` for lease issues, otherwise `ready`.
  Future<AttendancePreparationResolution> resolve() async {
    final sessionRecord = await _db.getLocalSession();
    final snapshot = await _store.getSnapshot();
    final session = _toLegacySession(sessionRecord);
    final readiness = AttendancePreparationReadiness.evaluate(
      snapshot: snapshot,
      expectedSession: session,
      now: _now(),
    );
    return AttendancePreparationResolution._(readiness, snapshot);
  }

  /// Roster lookup from the resolved snapshot, when ready.
  ///
  /// Returns members for the given class on the given date when the
  /// preparation is ready and the class/date exists in the snapshot.
  /// Returns null when not ready or not found (caller shows appropriate
  /// banner/empty state, never synthesizes).
  Future<ClassPreparation?> classFor(int classId) async {
    final res = await resolve();
    if (!res.isReady) return null;
    final ds = res.dataset;
    if (ds == null) return null;
    for (final c in ds.classes) {
      if (c.classId == classId) return c;
    }
    return null;
  }

  LegacyWriteSession _toLegacySession(LocalSessionRecord rec) {
    final owner = rec.ownerUserId ?? 0;
    final av = rec.authorizationVersion ?? 0;
    final gen = rec.generation;
    return LegacyWriteSession(
      ownerUserId: owner,
      authorizationVersion: av,
      runtimeGeneration: gen,
    );
  }
}
