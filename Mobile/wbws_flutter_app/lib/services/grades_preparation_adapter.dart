import 'grades_preparation_dataset.dart';
import 'grades_preparation_readiness.dart';
import 'grades_preparation_store.dart';
import 'legacy_outbox_models.dart';
import 'local_db.dart';
import 'session_models.dart';

final class GradesPreparationResolution {
  final GradesPreparationReadiness readiness;
  final GradesPreparationSnapshot? snapshot;
  const GradesPreparationResolution._(this.readiness, this.snapshot);
  bool get isReady => readiness.isReady;
  GradesReadinessReason get reason => readiness.reason;
  GradesPreparationDataset? get dataset => snapshot?.dataset;
}

final class GradesPreparationAdapter {
  final LocalDb _db;
  final GradesPreparationStore _store;
  final DateTime Function() _now;
  GradesPreparationAdapter({LocalDb? db, GradesPreparationStore? store, DateTime Function()? now}) : _db = db ?? LocalDb(), _store = store ?? GradesPreparationStore(), _now = now ?? (() => DateTime.now().toUtc());

  Future<GradesPreparationResolution> resolve() async {
    final rec = await _db.getLocalSession();
    final snap = await _store.getSnapshot();
    final session = LegacyWriteSession(ownerUserId: rec.ownerUserId ?? 0, authorizationVersion: rec.authorizationVersion ?? 0, runtimeGeneration: rec.generation);
    final readiness = GradesPreparationReadiness.evaluate(snapshot: snap, expectedSession: session, now: _now());
    return GradesPreparationResolution._(readiness, snap);
  }

  Future<GradeClass?> classFor(int classId) async {
    final res = await resolve();
    if (!res.isReady) return null;
    final ds = res.dataset;
    if (ds == null) return null;
    for (final c in ds.classes) { if (c.classId == classId) return c; }
    return null;
  }
}
