import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';
import 'grades_preparation_dataset.dart';
import 'grades_preparation_envelope.dart';
import 'legacy_outbox_models.dart';
import 'local_db.dart';

const gradesPreparationSnapshotSql = '''
  CREATE TABLE IF NOT EXISTS grades_preparation_snapshot (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    preparation_id TEXT NOT NULL,
    owner_user_id INTEGER NOT NULL,
    authorization_version INTEGER NOT NULL,
    server_identity TEXT NOT NULL,
    issued_at INTEGER NOT NULL,
    deadline INTEGER NOT NULL,
    payload_sha256 TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    write_year_id INTEGER NOT NULL,
    generation INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  )
''';

final class GradesPreparationSnapshot {
  final String preparationId; final int ownerUserId; final int authorizationVersion; final String serverIdentity; final int issuedAt; final int deadline; final String payloadSha256; final String payloadJson; final int writeYearId; final int generation; final String createdAt; final String updatedAt; final GradesPreparationDataset dataset;
  const GradesPreparationSnapshot._({required this.preparationId, required this.ownerUserId, required this.authorizationVersion, required this.serverIdentity, required this.issuedAt, required this.deadline, required this.payloadSha256, required this.payloadJson, required this.writeYearId, required this.generation, required this.createdAt, required this.updatedAt, required this.dataset});
  static GradesPreparationSnapshot _fromRow(Map<String, Object?> row) {
    final json = row['payload_json'] as String; final dataset = GradesPreparationDataset.decode(json);
    return GradesPreparationSnapshot._(preparationId: row['preparation_id'] as String, ownerUserId: row['owner_user_id'] as int, authorizationVersion: row['authorization_version'] as int, serverIdentity: row['server_identity'] as String, issuedAt: row['issued_at'] as int, deadline: row['deadline'] as int, payloadSha256: row['payload_sha256'] as String, payloadJson: json, writeYearId: row['write_year_id'] as int, generation: row['generation'] as int, createdAt: row['created_at'] as String, updatedAt: row['updated_at'] as String, dataset: dataset);
  }
  @visibleForTesting
  static GradesPreparationSnapshot forTest({required String preparationId, required int ownerUserId, required int authorizationVersion, required String serverIdentity, required int issuedAt, required int deadline, required String payloadSha256, required String payloadJson, required int writeYearId, required int generation}) {
    final dataset = GradesPreparationDataset.decode(payloadJson);
    final now = DateTime.now().toUtc().toIso8601String();
    return GradesPreparationSnapshot._(preparationId: preparationId, ownerUserId: ownerUserId, authorizationVersion: authorizationVersion, serverIdentity: serverIdentity, issuedAt: issuedAt, deadline: deadline, payloadSha256: payloadSha256, payloadJson: payloadJson, writeYearId: writeYearId, generation: generation, createdAt: now, updatedAt: now, dataset: dataset);
  }
}

class GradesPreparationStore {
  final LocalDb _db; GradesPreparationStore({LocalDb? db}) : _db = db ?? LocalDb();
  Future<void> publishPreparation({required LegacyWriteSession expectedSession, required GradesPreparationEnvelope envelope, required GradesPreparationDataset dataset}) async {
    if (!expectedSession.isValid) throw const StaleLegacyWriteSession();
    if (envelope.preparationId != dataset.preparationId) throw const FormatException('Envelope/dataset preparation_id mismatch');
    if (envelope.issuedAt != dataset.issuedAt || envelope.deadline != dataset.deadline) throw const FormatException('Envelope/dataset lease mismatch');
    if (dataset.ownerUserId != expectedSession.ownerUserId) throw const FormatException('Dataset owner mismatch for session');
    if (dataset.authorizationVersion != expectedSession.authorizationVersion) throw const FormatException('Dataset AV mismatch for session');
    final db = await _db.database;
    await db.transaction((txn) async {
      final ok = await _activeSessionMatchesTxn(txn, expectedSession: expectedSession);
      if (!ok) throw const StaleLegacyWriteSession();
      final now = DateTime.now().toUtc().toIso8601String();
      await txn.execute(gradesPreparationSnapshotSql);
      await txn.delete('grades_preparation_snapshot', where: 'id = 1');
      await txn.insert('grades_preparation_snapshot', {'id':1,'preparation_id':dataset.preparationId,'owner_user_id':dataset.ownerUserId,'authorization_version':dataset.authorizationVersion,'server_identity':dataset.serverIdentity,'issued_at':dataset.issuedAt,'deadline':dataset.deadline,'payload_sha256':envelope.payloadSha256,'payload_json':envelope.payloadUtf8,'write_year_id':dataset.writeYear.id,'generation':expectedSession.runtimeGeneration,'created_at':now,'updated_at':now});
    });
  }
  Future<GradesPreparationSnapshot?> getSnapshot() async {
    final db = await _db.database;
    try { await db.execute(gradesPreparationSnapshotSql); } catch (_) {}
    final rows = await db.query('grades_preparation_snapshot', where: 'id = 1', limit: 1);
    if (rows.isEmpty) return null;
    try { return GradesPreparationSnapshot._fromRow(rows.first); } catch (_) { return null; }
  }
  Future<void> clearSnapshot({required LegacyWriteSession expectedSession}) async {
    final db = await _db.database;
    await db.transaction((txn) async {
      final ok = await _activeSessionMatchesTxn(txn, expectedSession: expectedSession);
      if (!ok) throw const StaleLegacyWriteSession();
      await txn.delete('grades_preparation_snapshot', where: 'id = 1');
    });
  }
  Future<bool> _activeSessionMatchesTxn(DatabaseExecutor txn, {required LegacyWriteSession expectedSession}) async {
    try {
      final rows = await txn.query('local_session_state', columns: ['owner_user_id','owner_authorization_version','generation'], where: 'id = 1', limit: 1);
      if (rows.isEmpty) return false;
      final r = rows.first; final owner = r['owner_user_id'] as int?; final av = r['owner_authorization_version'] as int?; final gen = r['generation'] as int?;
      return owner == expectedSession.ownerUserId && av == expectedSession.authorizationVersion && gen == expectedSession.runtimeGeneration;
    } catch (_) { return false; }
  }
}
