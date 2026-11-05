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
    ownerUserId: 101,
    authorizationVersion: 3,
    runtimeGeneration: 7,
  );
  const b = LegacyWriteSession(
    ownerUserId: 202,
    authorizationVersion: 4,
    runtimeGeneration: 8,
  );
  late Directory directory;
  late Database raw;
  late LocalDb db;

  Future<void> activate(
    LegacyWriteSession session, {
    SessionState state = SessionState.active,
  }) =>
      db.persistLocalSession(
        state: state,
        generation: session.runtimeGeneration,
        ownerUserId: session.ownerUserId,
        authorizationVersion: session.authorizationVersion,
        ownerRole: 'teacher',
      );

  Future<LegacyOperationRef> save(
    LegacyWriteSession session, {
    String status = 'present',
  }) =>
      db.saveAttendanceLocal(
          3,
          'Synthetic class',
          '2026-11-04',
          [
            {
              'member_id': 30,
              'status': status,
              'notes': 'Original synthetic note'
            },
          ],
          expectedSession: session);

  Future<List<Map<String, Object?>>> rows() =>
      raw.query('pending_attendance', orderBy: 'id');

  setUpAll(() async {
    sqfliteFfiInit();
    directory = await Directory.systemTemp.createTemp('attendance-fence-');
    await databaseFactoryFfi.setDatabasesPath(directory.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    raw = await db.database;
  });
  setUp(() async {
    await raw.delete('pending_attendance'); // isolated synthetic fixture only
    await raw.delete('sync_attempts');
    await activate(a);
    await save(a);
  });
  tearDownAll(() async {
    await raw.close();
    await directory.delete(recursive: true);
  });

  Future<void> rejectedUnchanged(LegacyWriteSession capture) async {
    final before = await rows();
    Object? failure;
    try {
      await save(capture, status: 'late');
    } catch (error) {
      failure = error;
    }
    // Check actual row identity/payload before checking the error type.
    expect(await rows(), before);
    expect(failure, isA<StaleLegacyWriteSession>());
  }

  test('stale owner A cannot replace owner B same-key work', () async {
    await raw.update('pending_attendance', {
      'sync_state': 'in_flight',
      'attempt_count': 2,
      'last_attempt_at': '2026-11-04T08:00:00Z',
    });
    await activate(b);
    await save(b, status: 'absent');
    await rejectedUnchanged(a);
  });

  test('changed authorization version rejects the original capture', () async {
    await activate(
      const LegacyWriteSession(
        ownerUserId: 101,
        authorizationVersion: 4,
        runtimeGeneration: 7,
      ),
    );
    await rejectedUnchanged(a);
  });

  test(
    'same owner and scope but new runtime generation rejects old capture',
    () async {
      await activate(
        const LegacyWriteSession(
          ownerUserId: 101,
          authorizationVersion: 3,
          runtimeGeneration: 8,
        ),
      );
      await rejectedUnchanged(a);
    },
  );

  for (final state in SessionState.values.where(
    (s) => s != SessionState.active,
  )) {
    test(
      'inactive ${state.storageValue} never accepts a captured write',
      () async {
        await activate(a, state: state);
        await rejectedUnchanged(a);
      },
    );
  }

  for (final invalid in [
    const LegacyWriteSession(
      ownerUserId: 0,
      authorizationVersion: 3,
      runtimeGeneration: 7,
    ),
    const LegacyWriteSession(
      ownerUserId: 101,
      authorizationVersion: -1,
      runtimeGeneration: 7,
    ),
    const LegacyWriteSession(
      ownerUserId: 101,
      authorizationVersion: 3,
      runtimeGeneration: -1,
    ),
  ]) {
    test(
      'invalid capture ${invalid.ownerUserId}/${invalid.authorizationVersion}/'
      '${invalid.runtimeGeneration} cannot fall back to current owner',
      () async {
        await rejectedUnchanged(invalid);
      },
    );
  }

  test(
    'matching session saves original scope date payload and returned binding',
    () async {
      final operation = await save(a, status: 'excused');
      final packet = (await rows()).single;
      expect(packet['class_id'], 3);
      expect(packet['date'], '2026-11-04');
      expect(packet['status'], 'excused');
      expect(packet['notes'], 'Original synthetic note');
      expect(packet['owner_user_id'], a.ownerUserId);
      expect(packet['created_authorization_version'], a.authorizationVersion);
      expect(operation.runtimeGeneration, a.runtimeGeneration);
      expect(operation.clientOpId, packet['client_op_id']);
      expect(
        (await raw.rawQuery('PRAGMA user_version')).single.values.single,
        42,
      );
    },
  );

  test('legacy authorization version and generation zero stay valid', () async {
    const legacy = LegacyWriteSession(
      ownerUserId: 101,
      authorizationVersion: 0,
      runtimeGeneration: 0,
    );
    await activate(legacy);
    final operation = await save(legacy);
    expect(operation.createdAuthorizationVersion, 0);
    expect(operation.runtimeGeneration, 0);
  });

  test(
    'queued save rechecks after another SQLite transaction changes session',
    () async {
      final before = await rows();
      final entered = Completer<void>();
      final release = Completer<void>();
      final transition = raw.transaction((txn) async {
        await txn.update(
            'local_session_state',
            {
              'owner_user_id': b.ownerUserId,
              'owner_authorization_version': b.authorizationVersion,
              'generation': b.runtimeGeneration,
            },
            where: 'id = 1');
        entered.complete();
        await release.future;
      });
      await entered.future;
      final pending = expectLater(
        save(a),
        throwsA(isA<StaleLegacyWriteSession>()),
      );
      release.complete();
      await transition;
      await pending;
      expect(await rows(), before);
    },
  );

  test(
    'rejected save does not poison the queue or erase another owner work',
    () async {
      await activate(b);
      await rejectedUnchanged(a);
      final operation = await save(b, status: 'late');
      expect(operation.ownerUserId, b.ownerUserId);
      final packets = await rows();
      expect(packets, hasLength(2));
      expect(
        packets.singleWhere((r) => r['owner_user_id'] == 101)['status'],
        'present',
      );
      expect(
        packets.singleWhere((r) => r['owner_user_id'] == 202)['status'],
        'late',
      );
    },
  );
}
