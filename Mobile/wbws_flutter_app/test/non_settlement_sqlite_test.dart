import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/services/sync_service.dart';

// Only transport is substituted. Production coordinator, parser, classifiers,
// immutable claims, SQLite settlement, ledger and cleanup execute unchanged.
class _ResponseApi implements ApiService {
  _ResponseApi(this.response, this.afterTransmission);
  final ApiResponse response;
  final Future<void> Function() afterTransmission;
  bool loggedIn = true;
  int transmissions = 0;
  String? transmittedOperation;
  @override
  bool get isLoggedIn => loggedIn;
  @override
  int get userId => 101;
  @override
  int get authorizationVersion => 3;
  @override
  String get userRole => 'teacher';
  @override
  Future<ApiResponse> saveAttendance(
      int classId, String date, List<Map<String, dynamic>> records,
      {String? clientOpId,
      String? attemptUid,
      int? attemptNumber,
      String? executionSource}) async {
    transmissions++;
    transmittedOperation = clientOpId;
    expect(classId, 3);
    expect(date, '2026-11-04');
    expect(records.single['notes'], 'SYNTHETIC old note');
    expect(attemptUid, isNotEmpty);
    expect(attemptNumber, 1);
    await afterTransmission();
    // Prevent all unrelated downloads/network paths. The existing drain gate
    // stops after this in-flight result is settled, not before settlement.
    loggedIn = false;
    return response;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw StateError('Unexpected transport call: ${invocation.memberName}');
}

void main() {
  const session = LegacyWriteSession(
      ownerUserId: 101, authorizationVersion: 3, runtimeGeneration: 7);
  late Directory directory;
  late Database raw;
  late LocalDb db;
  setUpAll(() async {
    sqfliteFfiInit();
    directory = await Directory.systemTemp.createTemp('non-settlement-sqlite-');
    await databaseFactoryFfi.setDatabasesPath(directory.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    raw = await db.database;
  });
  setUp(() async {
    await raw.delete('pending_attendance');
    await raw.delete('sync_attempts');
    await db.persistLocalSession(
        state: SessionState.active,
        generation: 7,
        ownerUserId: 101,
        authorizationVersion: 3,
        ownerRole: 'teacher');
  });
  tearDownAll(() async {
    await raw.close();
    await directory.delete(recursive: true);
  });
  Future<LegacyOperationRef> save(String status, String note) =>
      db.saveAttendanceLocal(
          3,
          'Synthetic class',
          '2026-11-04',
          [
            {
              'member_id': 30,
              'student_name': 'Synthetic student',
              'status': status,
              'notes': note
            }
          ],
          expectedSession: session);

  final responses = <String, Map<String, dynamic>>{
    'unknown cache': {
      'status': 'success',
      'data': {
        'authority': 'legacy_replay_cache',
        'state': 'unknown',
        'authorizes_settlement': false
      }
    },
    'completed cache': {
      'status': 'success',
      'data': {
        'authority': 'legacy_replay_cache',
        'state': 'completed',
        'cached_http_status': 201
      }
    },
    'capability': {
      'status': 'success',
      'data': {'durable_receipt_versions': []}
    },
    'unvalidated positive flag': {
      'status': 'success',
      'data': {'authorizes_settlement': true}
    },
    'unsupported receipt': {
      'status': 'error',
      'code': 'ATTENDANCE_RECEIPT_CONTRACT_UNSUPPORTED'
    },
  };
  for (final entry in responses.entries) {
    test(
        '${entry.key} holds exact old generation and preserves a newer edit on disk',
        () async {
      final original = await save('absent', 'SYNTHETIC old note');
      final before = (await raw.query('pending_attendance')).single;
      late LegacyOperationRef newer;
      var allowed = true;
      final api = _ResponseApi(
          ApiResponse.fromJson(
              entry.value, entry.key == 'unsupported receipt' ? 412 : 200,
              headers: {'idempotency-replayed': 'true'}), () async {
        newer = await save('present', 'SYNTHETIC new note');
        allowed = false;
      });
      final worker = SyncService.withCollaborators(api: api, db: db);
      worker.sessionGenerationProvider = () => 7;
      worker.activeSessionGate = () => true;
      worker.drainEnabledGate = () => allowed;
      try {
        final result = await worker.runSyncNow();
        expect(result.synced, 0);
        expect(api.transmissions, 1);
        expect(api.transmittedOperation, original.clientOpId);
        await db.cleanupSynced();
        // Independent connection observes committed disk state, not a mocked
        // settlement or an in-memory row projection. Not a phone crash test.
        final reader = await databaseFactoryFfi.openDatabase(raw.path,
            options:
                OpenDatabaseOptions(readOnly: true, singleInstance: false));
        try {
          final rows = await reader.query('pending_attendance', orderBy: 'id');
          expect(rows, hasLength(2));
          final old = rows.first;
          for (final field in [
            'id',
            'client_op_id',
            'owner_user_id',
            'created_authorization_version',
            'class_id',
            'date',
            'member_id',
            'status',
            'notes'
          ]) {
            expect(old[field], before[field], reason: field);
          }
          expect(old['synced'], 0);
          expect(old['synced_at'], isNull);
          expect(old['sync_state'], 'needs_attention');
          expect(old['next_attempt_at'], isNull);
          expect(old['sync_error'],
              'Server confirmation is incomplete. Your saved work is kept.');
          expect(rows.last['client_op_id'], newer.clientOpId);
          expect(rows.last['status'], 'present');
          expect(rows.last['notes'], 'SYNTHETIC new note');
          expect(rows.last['synced'], 0);
          expect(rows.last['sync_state'], 'pending');
          final attempts = await reader.query('sync_attempts');
          expect(attempts, hasLength(1));
          expect(attempts.single['client_op_id'], original.clientOpId);
          expect(attempts.single['error_category'], 'PROTOCOL_ERROR');
          expect(attempts.single['retry_decision'], 'USER_ACTION_REQUIRED');
          expect(attempts.single['finished_at'], isNotNull);
        } finally {
          await reader.close();
        }
      } finally {
        worker.dispose();
      }
    });
  }
}
