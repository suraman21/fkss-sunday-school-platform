import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/attendance_preparation_dataset.dart';
import 'package:fkss_app/services/attendance_preparation_envelope.dart';
import 'package:fkss_app/services/attendance_preparation_readiness.dart';
import 'package:fkss_app/services/attendance_preparation_store.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';

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

  AttendancePreparationSnapshot snapshotOf(Map<String, dynamic> payloadMap) {
    final env = envOf(payloadMap);
    final payloadJson = jsonEncode(payloadMap);
    return AttendancePreparationSnapshot.forTest(
      preparationId: payloadMap['preparation_id'] as String,
      ownerUserId: payloadMap['owner_user_id'] as int,
      authorizationVersion: payloadMap['authorization_version'] as int,
      serverIdentity: payloadMap['server_identity'] as String,
      issuedAt: payloadMap['issued_at'] as int,
      deadline: payloadMap['deadline'] as int,
      payloadSha256: env.payloadSha256,
      payloadJson: payloadJson,
      writeYearId: (payloadMap['write_year'] as Map)['id'] as int,
      generation: session.runtimeGeneration,
    );
  }

  group('AttendancePreparationReadiness', () {
    test('missing snapshot is not ready', () {
      final r = AttendancePreparationReadiness.evaluate(snapshot: null, expectedSession: session, now: DateTime.fromMillisecondsSinceEpoch(1794000000 * 1000));
      expect(r.isReady, isFalse);
      expect(r.reason, AttendanceReadinessReason.missing);
    });

    test('matching snapshot within lease is ready', () {
      final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
      final snap = snapshotOf(payload);
      final now = DateTime.fromMillisecondsSinceEpoch((payload['issued_at'] as int) * 1000 + 1000);
      final r = AttendancePreparationReadiness.evaluate(snapshot: snap, expectedSession: session, now: now);
      expect(r.isReady, isTrue);
      expect(r.reason, AttendanceReadinessReason.ready);
    });

    test('stale owner is not ready', () {
      final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
      final snap = snapshotOf(payload);
      const stale = LegacyWriteSession(ownerUserId: 999, authorizationVersion: 3, runtimeGeneration: 7);
      final now = DateTime.fromMillisecondsSinceEpoch((payload['issued_at'] as int) * 1000 + 1000);
      final r = AttendancePreparationReadiness.evaluate(snapshot: snap, expectedSession: stale, now: now);
      expect(r.isReady, isFalse);
      expect(r.reason, AttendanceReadinessReason.staleSession);
    });

    test('stale generation is not ready', () {
      final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
      final snap = snapshotOf(payload);
      const stale = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 99);
      final now = DateTime.fromMillisecondsSinceEpoch((payload['issued_at'] as int) * 1000 + 1000);
      final r = AttendancePreparationReadiness.evaluate(snapshot: snap, expectedSession: stale, now: now);
      expect(r.isReady, isFalse);
      expect(r.reason, AttendanceReadinessReason.staleSession);
    });

    test('expired is not ready and blocks writes', () {
      final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
      final snap = snapshotOf(payload);
      final now = DateTime.fromMillisecondsSinceEpoch((payload['deadline'] as int) * 1000);
      final r = AttendancePreparationReadiness.evaluate(snapshot: snap, expectedSession: session, now: now);
      expect(r.isReady, isFalse);
      expect(r.reason, AttendanceReadinessReason.expired);
      expect(r.shouldBlockWrites, isTrue);
    });

    test('clock skew (now before issued) is not ready', () {
      final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
      final snap = snapshotOf(payload);
      final now = DateTime.fromMillisecondsSinceEpoch((payload['issued_at'] as int) * 1000 - 1000);
      final r = AttendancePreparationReadiness.evaluate(snapshot: snap, expectedSession: session, now: now);
      expect(r.isReady, isFalse);
      expect(r.reason, AttendanceReadinessReason.clockSkew);
    });

    test('complete-empty is still ready (distinct from missing)', () {
      final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
      payload['members'] = [];
      payload['classes'] = [];
      payload['school_calendar'] = {};
      final snap = snapshotOf(payload);
      final now = DateTime.fromMillisecondsSinceEpoch((payload['issued_at'] as int) * 1000 + 1000);
      final r = AttendancePreparationReadiness.evaluate(snapshot: snap, expectedSession: session, now: now);
      expect(r.isReady, isTrue);
    });

    test('invalid session is stale', () {
      final payload = Map<String, dynamic>.from(datasetFixture['payload'] as Map);
      final snap = snapshotOf(payload);
      const invalid = LegacyWriteSession(ownerUserId: 0, authorizationVersion: 0, runtimeGeneration: 0);
      final now = DateTime.fromMillisecondsSinceEpoch((payload['issued_at'] as int) * 1000 + 1000);
      final r = AttendancePreparationReadiness.evaluate(snapshot: snap, expectedSession: invalid, now: now);
      expect(r.isReady, isFalse);
      expect(r.reason, AttendanceReadinessReason.staleSession);
    });
  });
}
