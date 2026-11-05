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

  String sha(List<int> bytes) => sha256.convert(bytes).toString();
  String b64(String s) => base64Url.encode(utf8.encode(s)).replaceAll('=', '');

  AttendancePreparationEnvelope freshEnvelope([Map<String, dynamic>? payloadOverride]) {
    final payloadMap = payloadOverride ?? Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    final payloadBytes = utf8.encode(jsonEncode(payloadMap));
    final payloadB64 = base64Url.encode(payloadBytes).replaceAll('=', '');
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
      'payload_sha256': sha(payloadBytes),
    };
    final grantBody = base64Url.encode(utf8.encode(jsonEncode(root))).replaceAll('=', '');
    final baseEnv = Map<String, dynamic>.from(fixture['envelope'] as Map);
    baseEnv['payload'] = payloadB64;
    baseEnv['grant'] = '$grantBody.${'a' * 64}';
    return AttendancePreparationEnvelope.decode(baseEnv, expected: scope, maxPayloadBytes: 1024 * 1024);
  }

  AttendancePreparationDataset freshDataset([Map<String, dynamic>? payloadOverride]) {
    final payloadMap = payloadOverride ?? datasetFixture['payload'] as Map<String, dynamic>;
    return AttendancePreparationDataset.decode(jsonEncode(payloadMap));
  }

  late Directory dir;
  late LocalDb db;
  late Database raw;

  setUpAll(() async {
    sqfliteFfiInit();
    dir = await Directory.systemTemp.createTemp('prep-store-');
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

  test('publish and read back snapshot', () async {
    final env = freshEnvelope();
    final ds = freshDataset();
    final store = AttendancePreparationStore(db: db);
    await store.publishPreparation(expectedSession: session, envelope: env, dataset: ds);
    final snap = await store.getSnapshot();
    expect(snap, isNotNull);
    expect(snap!.preparationId, ds.preparationId);
    expect(snap.payloadSha256, env.payloadSha256);
    expect(snap.ownerUserId, 7);
    expect(snap.generation, 7);
    expect(snap.dataset.members.length, 2);
  });

  test('second publish replaces first atomically', () async {
    final env = freshEnvelope();
    final ds = freshDataset();
    final store = AttendancePreparationStore(db: db);
    await store.publishPreparation(expectedSession: session, envelope: env, dataset: ds);
    final modifiedMap = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    modifiedMap['server_today'] = '2026-11-03';
    final env2 = freshEnvelope(modifiedMap);
    final ds2 = freshDataset(modifiedMap);
    await store.publishPreparation(expectedSession: session, envelope: env2, dataset: ds2);
    final snap = await store.getSnapshot();
    expect(snap!.dataset.serverToday, '2026-11-03');
  });

  test('stale generation cannot publish and preserves old', () async {
    final env = freshEnvelope();
    final ds = freshDataset();
    final store = AttendancePreparationStore(db: db);
    await store.publishPreparation(expectedSession: session, envelope: env, dataset: ds);
    final before = await store.getSnapshot();
    const stale = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 99);
    await expectLater(store.publishPreparation(expectedSession: stale, envelope: env, dataset: ds),
        throwsA(isA<StaleLegacyWriteSession>()));
    final after = await store.getSnapshot();
    expect(after!.preparationId, before!.preparationId);
  });

  test('mismatched envelope/dataset fails and preserves old', () async {
    final env = freshEnvelope();
    final ds = freshDataset();
    final store = AttendancePreparationStore(db: db);
    await store.publishPreparation(expectedSession: session, envelope: env, dataset: ds);
    final before = await store.getSnapshot();
    final mismatchedMap = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    mismatchedMap['preparation_id'] = 'b' * 64;
    final dsBad = AttendancePreparationDataset.decode(jsonEncode(mismatchedMap));
    await expectLater(store.publishPreparation(expectedSession: session, envelope: env, dataset: dsBad),
        throwsA(isA<FormatException>()));
    final after = await store.getSnapshot();
    expect(after!.preparationId, before!.preparationId);
  });

  test('corrupt stored JSON returns null', () async {
    final env = freshEnvelope();
    final ds = freshDataset();
    final store = AttendancePreparationStore(db: db);
    await store.publishPreparation(expectedSession: session, envelope: env, dataset: ds);
    await raw.update('attendance_preparation_snapshot', {'payload_json': 'not-json'}, where: 'id = 1');
    final snap = await store.getSnapshot();
    expect(snap, isNull);
  });

  test('clear with fencing removes snapshot', () async {
    final env = freshEnvelope();
    final ds = freshDataset();
    final store = AttendancePreparationStore(db: db);
    await store.publishPreparation(expectedSession: session, envelope: env, dataset: ds);
    await store.clearSnapshot(expectedSession: session);
    expect(await store.getSnapshot(), isNull);
  });
}
