import 'grades_preparation_store.dart';
import 'legacy_outbox_models.dart';

final class GradesPreparationReadiness {
  final bool isReady; final GradesReadinessReason reason; final GradesPreparationSnapshot? snapshot;
  const GradesPreparationReadiness._({required this.isReady, required this.reason, required this.snapshot});
  static GradesPreparationReadiness evaluate({required GradesPreparationSnapshot? snapshot, required LegacyWriteSession expectedSession, required DateTime now}) {
    if (snapshot == null) return const GradesPreparationReadiness._(isReady: false, reason: GradesReadinessReason.missing, snapshot: null);
    if (!expectedSession.isValid) return GradesPreparationReadiness._(isReady: false, reason: GradesReadinessReason.staleSession, snapshot: snapshot);
    if (snapshot.ownerUserId != expectedSession.ownerUserId || snapshot.authorizationVersion != expectedSession.authorizationVersion) return GradesPreparationReadiness._(isReady: false, reason: GradesReadinessReason.staleSession, snapshot: snapshot);
    if (snapshot.generation != expectedSession.runtimeGeneration) return GradesPreparationReadiness._(isReady: false, reason: GradesReadinessReason.staleSession, snapshot: snapshot);
    final nowSec = now.toUtc().millisecondsSinceEpoch ~/ 1000;
    if (nowSec < snapshot.issuedAt) return GradesPreparationReadiness._(isReady: false, reason: GradesReadinessReason.clockSkew, snapshot: snapshot);
    if (nowSec >= snapshot.deadline) return GradesPreparationReadiness._(isReady: false, reason: GradesReadinessReason.expired, snapshot: snapshot);
    return GradesPreparationReadiness._(isReady: true, reason: GradesReadinessReason.ready, snapshot: snapshot);
  }
  bool get shouldBlockWrites => !isReady;
}
enum GradesReadinessReason { ready, missing, staleSession, expired, clockSkew }
