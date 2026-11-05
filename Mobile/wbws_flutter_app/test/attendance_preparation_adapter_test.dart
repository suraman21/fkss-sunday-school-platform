import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/attendance_preparation_adapter.dart';
import 'package:fkss_app/services/attendance_preparation_dataset.dart';
import 'package:fkss_app/services/attendance_preparation_envelope.dart';
import 'package:fkss_app/services/attendance_preparation_readiness.dart';
import 'package:fkss_app/services/attendance_preparation_store.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';

void main() {
  const session = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 7);
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
  late LocalDb db;
  late sqflite.Database raw;

  setUpAll(() async {
    sqfliteFfiInit();
    dir = await Directory.systemTemp.createTemp('prep-adapter-');
    await databaseFactoryFfi.setDatabasesPath(dir.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    raw = await db.database;
  });

  setUp(() async {
    await raw.delete('attendance_preparation_snapshot');
    await db.persistLocalSession(state: SessionState.active, generation: 7, ownerUserId: 7, authorizationVersion: 3, ownerRole: 'teacher');
  });

  tearDownAll(() async {
    await raw.close();
    await dir.delete(recursive: true);
  });

  Future<void> publish(Map<String, dynamic> payloadMap) async {
    final env = envOf(payloadMap);
    final ds = AttendancePreparationDataset.decode(jsonEncode(payloadMap));
    await AttendancePreparationStore(db: db).publishPreparation(expectedSession: session, envelope: env, dataset: ds);
  }

  test('missing snapshot is missing', () async {
    final adapter = AttendancePreparationAdapter(db: db, store: AttendancePreparationStore(db: db), now: () => DateTime.fromMillisecondsSinceEpoch(1794000000 * 1000));
    final res = await adapter.resolve();
    expect(res.isReady, isFalse);
    expect(res.reason, AttendanceReadinessReason.missing);
  });

  test('published within lease is ready and class lookup works', () async {
    final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    await publish(payload);
    final now = DateTime.fromMillisecondsSinceEpoch((payload['issued_at'] as int) * 1000 + 1000);
    final adapter = AttendancePreparationAdapter(db: db, store: AttendancePreparationStore(db: db), now: () => now);
    final res = await adapter.resolve();
    expect(res.isReady, isTrue);
    final cls = await adapter.classFor((payload['classes'] as List).first['class_id'] as int);
    expect(cls, isNotNull);
    expect(cls!.classId, (payload['classes'] as List).first['class_id']);
  });

  test('stale generation from DB is not ready', () async {
    final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    await publish(payload);
    await db.persistLocalSession(state: SessionState.active, generation: 99, ownerUserId: 7, authorizationVersion: 3, ownerRole: 'teacher');
    final now = DateTime.fromMillisecondsSinceEpoch((payload['issued_at'] as int) * 1000 + 1000);
    final adapter = AttendancePreparationAdapter(db: db, store: AttendancePreparationStore(db: db), now: () => now);
    final res = await adapter.resolve();
    expect(res.isReady, isFalse);
    expect(res.reason, AttendanceReadinessReason.staleSession);
  });

  test('expired is not ready', () async {
    final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    await publish(payload);
    final now = DateTime.fromMillisecondsSinceEpoch((payload['deadline'] as int) * 1000);
    final adapter = AttendancePreparationAdapter(db: db, store: AttendancePreparationStore(db: db), now: () => now);
    final res = await adapter.resolve();
    expect(res.isReady, isFalse);
    expect(res.reason, AttendanceReadinessReason.expired);
  });
}
