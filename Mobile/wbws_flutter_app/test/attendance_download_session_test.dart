import 'dart:async';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/session_models.dart';

void main() {
  const a = LegacyWriteSession(
      ownerUserId: 101, authorizationVersion: 3, runtimeGeneration: 7);
  const b = LegacyWriteSession(
      ownerUserId: 202, authorizationVersion: 4, runtimeGeneration: 8);
  const mutations = ['page', 'reset', 'complete', 'status'];
  late Directory directory;
  late Database raw;
  late LocalDb db;

  Future<void> activate(LegacyWriteSession session,
          {SessionState state = SessionState.active}) =>
      db.persistLocalSession(
          state: state,
          generation: session.runtimeGeneration,
          ownerUserId: session.ownerUserId,
          authorizationVersion: session.authorizationVersion,
          ownerRole: 'teacher');

  Future<void> mutate(String kind, LegacyWriteSession capture) async {
    switch (kind) {
      case 'page':
        await db.applyAttendanceDelta(
            expectedSession: capture,
            nextCursor: 11,
            items: [
              {
                'op': 'UPDATE',
                'entity_id': 99,
                'class_id': 3,
                'member_id': 30,
                'date': '2026-11-04'
              }
            ],
            records: {
              '99': {
                'id': 99,
                'class_id': 3,
                'member_id': 30,
                'attendance_date': '2026-11-04',
                'status': 'late',
                'notes': 'Synthetic server note'
              }
            });
      case 'reset':
        await db.resetAttendanceSyncForBootstrap(expectedSession: capture);
      case 'complete':
        await db.completeAttendanceBootstrap(41, expectedSession: capture);
      case 'status':
        await db.setSyncStatus('attendance', 'failed',
            error: 'Synthetic error', expectedSession: capture);
    }
  }

  Future<Map<String, Object?>> snapshot() async => {
        'cache':
            await raw.query('cached_attendance', orderBy: 'class_id, date'),
        'cursor_status': await raw.query('sync_state', orderBy: 'domain'),
        'pending': await raw.query('pending_attendance', orderBy: 'id'),
        'attempts': await raw.query('sync_attempts', orderBy: 'id'),
      };

  Future<void> rejectUnchanged(String kind, LegacyWriteSession capture) async {
    final before = await snapshot();
    Object? failure;
    try {
      await mutate(kind, capture);
    } catch (error) {
      failure = error;
    }
    expect(await snapshot(), before);
    expect(failure, isA<StaleAttendanceSyncSession>());
  }

  setUpAll(() async {
    sqfliteFfiInit();
    directory =
        await Directory.systemTemp.createTemp('attendance-download-fence-');
    await databaseFactoryFfi.setDatabasesPath(directory.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    raw = await db.database;
  });
  setUp(() async {
    for (final table in [
      'cached_attendance',
      'sync_state',
      'pending_attendance',
      'sync_attempts'
    ]) {
      await raw.delete(table); // disposable synthetic fixture only
    }
    await activate(a);
    await db.cacheAttendanceResponse(3, '2026-11-04', [
      {'member_id': 30, 'student_name': 'Synthetic student', 'status': 'absent'}
    ]);
    await db.saveAttendanceLocal(
        3,
        'Synthetic class',
        '2026-11-04',
        [
          {
            'member_id': 30,
            'status': 'present',
            'notes': 'Unsent original intent'
          }
        ],
        expectedSession: a);
    final claim = await db.claimNextLegacyOperation(
        kind: LegacyOperationKind.attendance,
        ownerUserId: 101,
        authorizationVersion: 3,
        runtimeGeneration: 7);
    expect(claim, isNotNull);
    expect(await raw.query('sync_attempts'), hasLength(1));
    await db.cacheAttendanceResponse(4, '2026-11-03', [
      {'member_id': 40, 'student_name': 'Other scope', 'status': 'present'}
    ]);
    await raw.insert('sync_state', {
      'domain': 'attendance',
      'cursor': 10,
      'status': 'ok',
      'last_sync_at': 'before'
    });
    await raw.insert('sync_state', {
      'domain': 'unrelated',
      'cursor': 88,
      'status': 'ok',
      'last_sync_at': 'before'
    });
  });
  tearDownAll(() async {
    await raw.close();
    await directory.delete(recursive: true);
  });

  for (final kind in mutations) {
    for (final change in <String, LegacyWriteSession>{
      'owner': b,
      'authorization': const LegacyWriteSession(
          ownerUserId: 101, authorizationVersion: 4, runtimeGeneration: 7),
      'generation': const LegacyWriteSession(
          ownerUserId: 101, authorizationVersion: 3, runtimeGeneration: 8),
    }.entries) {
      test('$kind rejects stale ${change.key} and preserves all original rows',
          () async {
        await activate(change.value);
        await rejectUnchanged(kind, a);
      });
    }
    for (final state
        in SessionState.values.where((s) => s != SessionState.active)) {
      test('$kind rejects inactive ${state.storageValue}', () async {
        await activate(a, state: state);
        await rejectUnchanged(kind, a);
      });
    }
    for (final invalid in [
      const LegacyWriteSession(
          ownerUserId: 0, authorizationVersion: 3, runtimeGeneration: 7),
      const LegacyWriteSession(
          ownerUserId: 101, authorizationVersion: -1, runtimeGeneration: 7),
      const LegacyWriteSession(
          ownerUserId: 101, authorizationVersion: 3, runtimeGeneration: -1),
    ]) {
      test(
          '$kind rejects invalid ${invalid.ownerUserId}/${invalid.authorizationVersion}/${invalid.runtimeGeneration}',
          () async {
        await rejectUnchanged(kind, invalid);
      });
    }
    test(
        '$kind valid session leaves pending work and unrelated status unchanged',
        () async {
      final before = await snapshot();
      await mutate(kind, a);
      final after = await snapshot();
      expect(after['pending'], before['pending']);
      expect(after['attempts'], before['attempts']);
      final states = after['cursor_status'] as List;
      expect(states.last, (before['cursor_status'] as List).last);
      final attendance = states.first as Map;
      if (kind == 'page') {
        expect(attendance['cursor'], 11);
        expect((after['cache'] as List).last, (before['cache'] as List).last);
        final sheet = await db.getCachedAttendanceSheet(3, '2026-11-04');
        final student = (sheet!['students'] as List).single as Map;
        expect(student['status'], 'late');
        expect(student['student_name'], 'Synthetic student');
        expect(student['notes'], 'Synthetic server note');
      } else if (kind == 'reset') {
        expect(after['cache'], isEmpty);
        expect(attendance['cursor'], 0);
        expect(attendance['status'], 'bootstrap');
      } else if (kind == 'complete') {
        expect(attendance['cursor'], 41);
        expect(after['cache'], before['cache']);
      } else {
        expect(attendance['cursor'], 10);
        expect(attendance['status'], 'failed');
        expect(attendance['error'], 'Synthetic error');
      }
    });
    test('$kind rechecks session after queued SQLite transition', () async {
      final before = await snapshot();
      final entered = Completer<void>();
      final release = Completer<void>();
      final transition = raw.transaction((txn) async {
        await txn.update(
            'local_session_state',
            {
              'owner_user_id': 202,
              'owner_authorization_version': 4,
              'generation': 8
            },
            where: 'id = 1');
        entered.complete();
        await release.future;
      });
      await entered.future;
      final pending = expectLater(
          mutate(kind, a), throwsA(isA<StaleAttendanceSyncSession>()));
      release.complete();
      await transition;
      await pending;
      expect(await snapshot(), before);
    });
  }
  test('matching legacy version and generation zero are accepted', () async {
    const legacy = LegacyWriteSession(
        ownerUserId: 101, authorizationVersion: 0, runtimeGeneration: 0);
    await activate(legacy);
    await mutate('page', legacy);
    expect(await db.getSyncCursor('attendance'), 11);
  });
  test(
      'page failure rolls back sheet and cursor and a later page remains usable',
      () async {
    final before = await snapshot();
    await raw.execute(
        "CREATE TEMP TRIGGER fail_attendance_cursor BEFORE INSERT ON sync_state WHEN NEW.domain = 'attendance' BEGIN SELECT RAISE(ABORT, 'synthetic cursor refusal'); END");
    try {
      await expectLater(mutate('page', a), throwsA(anything));
    } finally {
      await raw.execute('DROP TRIGGER fail_attendance_cursor');
    }
    expect(await snapshot(), before);
    await mutate('page', a);
    expect(await db.getSyncCursor('attendance'), 11);
  });
}
