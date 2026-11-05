import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/attendance_preparation_envelope.dart';
import 'package:fkss_app/services/attendance_preparation_fetcher.dart';
import 'package:fkss_app/services/attendance_preparation_store.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';

void main() {
  final fixture = jsonDecode(File('../../tests/fixtures/attendance_preparation_envelope_v1.json').readAsStringSync()) as Map;
  final datasetFixture = jsonDecode(File('../../tests/fixtures/attendance_preparation_dataset_v1.json').readAsStringSync()) as Map;

  String sha(List<int> b) => sha256.convert(b).toString();

  Map<String, dynamic> envelopePayloadFor(Map<String, dynamic> payloadMap) {
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
    return baseEnv;
  }

  late Directory dir;
  late LocalDb db;
  late sqflite.Database raw;

  setUpAll(() async {
    sqfliteFfiInit();
    dir = await Directory.systemTemp.createTemp('prep-fetcher-');
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

  test('successful fetch publishes snapshot', () async {
    const session = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 7);
    final fetcher = AttendancePreparationFetcher(store: AttendancePreparationStore(db: db));
    final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    final env = envelopePayloadFor(payload);
    final scope = AttendancePreparationScope(
      ownerUserId: payload['owner_user_id'] as int,
      authorizationVersion: payload['authorization_version'] as int,
      role: payload['role'] as String,
      familyId: payload['family_id'] as String,
      serverIdentity: payload['server_identity'] as String,
    );
    final ok = ApiResponse(success: true, data: env, statusCode: 200);
    final snap = await fetcher.fetchAndPublish(session: session, expectedScope: scope, get: (_) async => ok);
    expect(snap.preparationId, payload['preparation_id']);
    expect(snap.ownerUserId, 7);
    final stored = await AttendancePreparationStore(db: db).getSnapshot();
    expect(stored, isNotNull);
    expect(stored!.preparationId, payload['preparation_id']);
  });

  test('503 preserves previous snapshot', () async {
    const session = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 7);
    final fetcher = AttendancePreparationFetcher(store: AttendancePreparationStore(db: db));
    final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    final env = envelopePayloadFor(payload);
    final scope = AttendancePreparationScope(
      ownerUserId: payload['owner_user_id'] as int,
      authorizationVersion: payload['authorization_version'] as int,
      role: payload['role'] as String,
      familyId: payload['family_id'] as String,
      serverIdentity: payload['server_identity'] as String,
    );
    final ok = ApiResponse(success: true, data: env, statusCode: 200);
    await fetcher.fetchAndPublish(session: session, expectedScope: scope, get: (_) async => ok);
    final before = await AttendancePreparationStore(db: db).getSnapshot();
    final failResp = ApiResponse(success: false, data: {'code': 'ATTENDANCE_PREPARATION_UNAVAILABLE'}, statusCode: 503, message: 'unavailable');
    await expectLater(
      fetcher.fetchAndPublish(session: session, get: (_) async => failResp),
      throwsA(isA<AttendancePreparationFetchException>()),
    );
    final after = await AttendancePreparationStore(db: db).getSnapshot();
    expect(after!.preparationId, before!.preparationId);
  });

  test('stale generation cannot publish', () async {
    const good = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 7);
    const stale = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 99);
    await db.persistLocalSession(state: SessionState.active, generation: 7, ownerUserId: 7, authorizationVersion: 3, ownerRole: 'teacher');
    final fetcher = AttendancePreparationFetcher(store: AttendancePreparationStore(db: db));
    final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    final env = envelopePayloadFor(payload);
    final scope = AttendancePreparationScope(
      ownerUserId: payload['owner_user_id'] as int,
      authorizationVersion: payload['authorization_version'] as int,
      role: payload['role'] as String,
      familyId: payload['family_id'] as String,
      serverIdentity: payload['server_identity'] as String,
    );
    final ok = ApiResponse(success: true, data: env, statusCode: 200);
    await expectLater(
      fetcher.fetchAndPublish(session: stale, expectedScope: scope, get: (_) async => ok),
      throwsA(isA<AttendancePreparationFetchException>().having((e) => e.code, 'code', 'STALE_SESSION')),
    );
    final stored = await AttendancePreparationStore(db: db).getSnapshot();
    expect(stored, isNull);
  });
}
