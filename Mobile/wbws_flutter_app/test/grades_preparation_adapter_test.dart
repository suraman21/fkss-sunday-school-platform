import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:fkss_app/services/grades_preparation_adapter.dart';
import 'package:fkss_app/services/grades_preparation_dataset.dart';
import 'package:fkss_app/services/grades_preparation_envelope.dart';
import 'package:fkss_app/services/grades_preparation_store.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';

void main() {
  const session = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 5);
  const scope = GradesPreparationScope(ownerUserId: 7, authorizationVersion: 3, role: 'teacher', familyId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', serverIdentity: 'https://school.example/api/v1');
  final fixture = jsonDecode(File('../../tests/fixtures/grades_preparation_envelope_v1.json').readAsStringSync()) as Map;
  final datasetFixture = jsonDecode(File('../../tests/fixtures/grades_preparation_dataset_v1.json').readAsStringSync()) as Map;
  String sha(List<int> b) => sha256.convert(b).toString();
  GradesPreparationEnvelope envOf(Map<String, dynamic> p) {
    final bytes = utf8.encode(jsonEncode(p));
    final b64 = base64Url.encode(bytes).replaceAll('=', '');
    final root = {'preparation_id': p['preparation_id'],'server_identity': p['server_identity'],'owner_user_id': p['owner_user_id'],'authorization_version': p['authorization_version'],'role': p['role'],'family_id': p['family_id'],'issued_at': p['issued_at'],'deadline': p['deadline'],'kind':'root','payload_sha256': sha(bytes)};
    final grantBody = base64Url.encode(utf8.encode(jsonEncode(root))).replaceAll('=', '');
    final baseEnv = Map<String, dynamic>.from(fixture['envelope'] as Map);
    baseEnv['payload'] = b64; baseEnv['grant'] = '$grantBody.${'a'*64}';
    return GradesPreparationEnvelope.decode(baseEnv, expected: scope, maxPayloadBytes: 1024*1024);
  }
  late Directory dir; late sqflite.Database raw; late LocalDb db;
  setUpAll(() async { sqfliteFfiInit(); dir = await Directory.systemTemp.createTemp('grades-adapter-'); await databaseFactoryFfi.setDatabasesPath(dir.path); sqflite.databaseFactory = databaseFactoryFfi; db = LocalDb(); raw = await db.database; });
  setUp(() async { await raw.delete('grades_preparation_snapshot'); await db.persistLocalSession(state: SessionState.active, generation: 5, ownerUserId: 7, authorizationVersion: 3, ownerRole: 'teacher'); });
  tearDownAll(() async { await raw.close(); await dir.delete(recursive: true); });

  test('adapter reports missing when no snapshot', () async {
    final adapter = GradesPreparationAdapter(db: db);
    final res = await adapter.resolve();
    expect(res.isReady, isFalse); expect(res.reason.toString(), contains('missing'));
  });

  test('adapter reports ready when snapshot matches session and lease', () async {
    final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
    final env = envOf(payload); final ds = GradesPreparationDataset.decode(jsonEncode(payload));
    await GradesPreparationStore(db: db).publishPreparation(expectedSession: session, envelope: env, dataset: ds);
    final adapter = GradesPreparationAdapter(db: db, now: () => DateTime.fromMillisecondsSinceEpoch((payload['issued_at'] as int)*1000 + 1000, isUtc: true));
    final res = await adapter.resolve();
    expect(res.isReady, isTrue);
    final cls = await adapter.classFor(101);
    expect(cls, isNotNull); expect(cls!.subjects.first.id, 10);
  });
}
