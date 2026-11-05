import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/attendance_preparation_dataset.dart';
import 'package:fkss_app/services/attendance_preparation_envelope.dart';
import 'package:fkss_app/services/attendance_preparation_store.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';

void main() {
  const session = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 5);
  const scope = AttendancePreparationScope(
    ownerUserId: 7,
    authorizationVersion: 3,
    role: 'teacher',
    familyId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    serverIdentity: 'https://school.example/api/v1',
  );
  final fixture = jsonDecode(File('../../tests/fixtures/attendance_preparation_envelope_v1.json').readAsStringSync()) as Map;
  final datasetFixture = jsonDecode(File('../../tests/fixtures/attendance_preparation_dataset_v1.json').readAsStringSync()) as Map;

  String sha(List<int> b) => sha256.convert(b).toString();

  AttendancePreparationEnvelope envOf(Map<String, dynamic> payloadMap) {
    final bytes = utf8.encode(jsonEncode(payloadMap));
    final b64 = base64Url.encode(bytes).replaceAll('=', '');
    final root = {
      'preparation_id': payloadMap['preparation_id'],
      'server_identity': payloadMap['server_identity'],
      'owner_user_id': payloadMap['owner_user_id'],
      'authorization_version': payloadMap['authorization_version'],
      'role': payloadMap['role'],
      'family_id': payloadMap['family_id'],
      'issued_at': payloadMap['issued_at'],
      'deadline': payloadMap['deadline'],
      'kind': 'root',
      'payload_sha256': sha(bytes),
    };
    final grantBody = base64Url.encode(utf8.encode(jsonEncode(root))).replaceAll('=', '');
    final baseEnv = Map<String, dynamic>.from(fixture['envelope'] as Map);
    baseEnv['payload'] = b64;
    baseEnv['grant'] = '$grantBody.${'a' * 64}';
    return AttendancePreparationEnvelope.decode(baseEnv, expected: scope, maxPayloadBytes: 1024 * 1024);
  }

  late Directory dir;
  late sqflite.Database raw;
  late LocalDb db;

  setUpAll(() async {
    sqfliteFfiInit();
    dir = await Directory.systemTemp.createTemp('lifecycle-');
    await databaseFactoryFfi.setDatabasesPath(dir.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    raw = await db.database;
  });

  setUp(() async {
    await raw.delete('pending_attendance');
    await raw.delete('attendance_sync_receipt');
    await raw.delete('attendance_preparation_snapshot');
    await db.persistLocalSession(state: SessionState.active, generation: 5, ownerUserId: 7, authorizationVersion: 3, ownerRole: 'teacher');
  });

  tearDownAll(() async {
    await raw.close();
    await dir.delete(recursive: true);
  });

  test('30-day lease renewal requires complete refresh, not login', () async {
    final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    final env = envOf(payload);
    final ds = AttendancePreparationDataset.decode(jsonEncode(payload));
    final store = AttendancePreparationStore(db: db);
    await store.publishPreparation(expectedSession: session, envelope: env, dataset: ds);
    var snap = await store.getSnapshot();
    expect(snap, isNotNull);
    final oldDeadline = snap!.deadline;
    await db.persistLocalSession(state: SessionState.active, generation: 5, ownerUserId: 7, authorizationVersion: 3, ownerRole: 'teacher');
    snap = await store.getSnapshot();
    expect(snap!.deadline, oldDeadline);
    final newPayload = Map<String, dynamic>.from(payload);
    newPayload['preparation_id'] = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    final env2 = envOf(newPayload);
    final ds2 = AttendancePreparationDataset.decode(jsonEncode(newPayload));
    await store.publishPreparation(expectedSession: session, envelope: env2, dataset: ds2);
    snap = await store.getSnapshot();
    expect(snap!.preparationId, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
  });

  test('logout clears preparation and receipts with no cross-account leakage', () async {
    final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    final env = envOf(payload);
    final ds = AttendancePreparationDataset.decode(jsonEncode(payload));
    await AttendancePreparationStore(db: db).publishPreparation(expectedSession: session, envelope: env, dataset: ds);
    await db.saveAttendanceLocal(101, 'Class A', '2026-03-01', [
      {'member_id': 1, 'student_name': 'A', 'father_name': 'B', 'member_code': 'M1', 'status': 'present', 'notes': ''},
    ], expectedSession: session, packetKind: 'draft');
    final claim = await db.claimNextLegacyOperation(kind: LegacyOperationKind.attendance, ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 5);
    expect(claim, isNotNull);
    await db.settleLegacyOperation(
      claim: claim!,
      settlement: LegacySettlement(kind: LegacySettlementKind.accepted, receiptJson: '{"saved":1}', serverRequestId: 'req-1', httpStatus: 201),
      currentOwnerUserId: 7,
      currentAuthorizationVersion: 3,
      currentRuntimeGeneration: 5,
    );
    expect(await AttendancePreparationStore(db: db).getSnapshot(), isNotNull);
    expect((await raw.query('attendance_sync_receipt')).isNotEmpty, isTrue);
    await db.clearAllUserData();
    expect(await AttendancePreparationStore(db: db).getSnapshot(), isNull);
    expect(await raw.query('attendance_sync_receipt'), isEmpty);
    expect(await raw.query('pending_attendance'), isEmpty);
    await db.persistLocalSession(state: SessionState.active, generation: 1, ownerUserId: 99, authorizationVersion: 1, ownerRole: 'teacher');
    expect(await AttendancePreparationStore(db: db).getSnapshot(), isNull);
  });
}
