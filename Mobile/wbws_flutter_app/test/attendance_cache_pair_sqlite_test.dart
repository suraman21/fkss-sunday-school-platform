import 'dart:convert';
import 'dart:async';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/session_models.dart';

void main() {
  const session = LegacyWriteSession(
      ownerUserId: 101, authorizationVersion: 3, runtimeGeneration: 7);
  late Directory dir;
  late LocalDb db;
  late Database raw;
  List<Map<String, dynamic>> roster(int id, String name) => [
        {
          'member_id': id,
          'student_name': name,
          'status': 'present',
          'notes': name
        },
        {
          'member_id': id + 1,
          'student_name': '$name B',
          'status': 'late',
          'notes': ''
        },
      ];
  Future<void> publish(List<Map<String, dynamic>> students,
      {LegacyWriteSession capture = session}) async {
    await db.cacheAttendanceSnapshot(3, '2026-11-05', students,
        expectedSession: capture, submissionStatus: 'revision_needed');
  }

  Future<List<Map<String, Object?>>> snapshot() async => [
        ...await raw.query('cached_students', orderBy: 'class_id, member_id'),
        ...await raw.query('cached_attendance', orderBy: 'class_id, date'),
      ];
  setUpAll(() async {
    sqfliteFfiInit();
    dir = await Directory.systemTemp.createTemp('attendance-cache-pair-');
    await databaseFactoryFfi.setDatabasesPath(dir.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    raw = await db.database;
  });
  setUp(() async {
    await raw.delete('cached_students');
    await raw.delete('cached_attendance');
    await db.persistLocalSession(
        state: SessionState.active,
        generation: 7,
        ownerUserId: 101,
        authorizationVersion: 3,
        ownerRole: 'teacher');
    await db.cacheStudents(3, roster(10, 'Old'));
    await db.cacheStudents(4, roster(20, 'Other'));
    await db.cacheAttendanceResponse(3, '2026-11-05', roster(10, 'Old'));
    await db.cacheAttendanceResponse(3, '2026-11-04', roster(40, 'Earlier'));
    await db.cacheAttendanceResponse(4, '2026-11-05', roster(20, 'Other'));
  });
  tearDownAll(() async {
    await raw.close();
    await dir.delete(recursive: true);
  });
  test('failed sheet insert cannot publish a new roster with the old sheet',
      () async {
    final before = await snapshot();
    await raw.execute(
        "CREATE TRIGGER synthetic_pair_sheet_abort BEFORE INSERT ON cached_attendance WHEN NEW.class_id = 3 BEGIN SELECT RAISE(ABORT,'synthetic sheet failure'); END");
    try {
      await expectLater(
          publish(roster(30, 'New')), throwsA(isA<DatabaseException>()));
    } finally {
      await raw.execute('DROP TRIGGER synthetic_pair_sheet_abort');
    }
    expect(await snapshot(), before);
    final reader = await databaseFactoryFfi.openDatabase(raw.path,
        options: OpenDatabaseOptions(readOnly: true, singleInstance: false));
    try {
      expect(
          await reader.query('cached_students',
              where: 'class_id = ?', whereArgs: [3], orderBy: 'member_id'),
          before
              .where((r) => r['class_id'] == 3 && r.containsKey('member_id'))
              .toList());
    } finally {
      await reader.close();
    }
  });
  test('failed second roster insert leaves both old caches intact', () async {
    final before = await snapshot();
    await raw.execute(
        "CREATE TRIGGER synthetic_pair_roster_abort BEFORE INSERT ON cached_students WHEN NEW.member_id = 31 BEGIN SELECT RAISE(ABORT,'synthetic roster failure'); END");
    try {
      await expectLater(
          publish(roster(30, 'New')), throwsA(isA<DatabaseException>()));
    } finally {
      await raw.execute('DROP TRIGGER synthetic_pair_roster_abort');
    }
    expect(await snapshot(), before);
  });
  for (final capture in [
    const LegacyWriteSession(
        ownerUserId: 202, authorizationVersion: 3, runtimeGeneration: 7),
    const LegacyWriteSession(
        ownerUserId: 101, authorizationVersion: 4, runtimeGeneration: 7),
    const LegacyWriteSession(
        ownerUserId: 101, authorizationVersion: 3, runtimeGeneration: 8),
  ]) {
    test(
        'mismatched capture cannot touch either cache: ${capture.ownerUserId}/${capture.authorizationVersion}/${capture.runtimeGeneration}',
        () async {
      final before = await snapshot();
      await expectLater(publish(roster(30, 'New'), capture: capture),
          throwsA(isA<StaleAttendanceSyncSession>()));
      expect(await snapshot(), before);
    });
  }
  test('inactive durable session cannot publish even with matching numbers',
      () async {
    final before = await snapshot();
    await db.persistLocalSession(
        state: SessionState.reauthRequired,
        generation: 7,
        ownerUserId: 101,
        authorizationVersion: 3,
        ownerRole: 'teacher');
    await expectLater(
        publish(roster(30, 'New')), throwsA(isA<StaleAttendanceSyncSession>()));
    expect(await snapshot(), before);
  });
  test('successful pair shares one timestamp and preserves other dates/classes',
      () async {
    final other = (await snapshot())
        .where((r) => r['class_id'] == 4 || r['date'] == '2026-11-04')
        .toList();
    await publish(roster(30, 'New'));
    final all = await snapshot();
    expect(
        all
            .where((r) => r['class_id'] == 4 || r['date'] == '2026-11-04')
            .toList(),
        other);
    final changed = all
        .where((r) => r['class_id'] == 3 && r['date'] != '2026-11-04')
        .toList();
    expect(changed.map((r) => r['updated_at']).toSet(), hasLength(1));
    expect((await db.getCachedAttendanceSheet(3, '2026-11-05'))!['students'],
        roster(30, 'New'));
  });
  test('later caller edits cannot change captured JSON or roster', () async {
    final input = roster(30, 'New');
    final future = publish(input);
    input[0]['notes'] = 'Mutated';
    input[0]['student_name'] = 'Mutated';
    input.clear();
    await future;
    expect((await db.getCachedAttendanceSheet(3, '2026-11-05'))!['students'],
        roster(30, 'New'));
  });
  test('unencodable input cannot commit just the roster half', () async {
    final before = await snapshot();
    final input = roster(30, 'New');
    input.first['notes'] = Object();
    await expectLater(
        publish(input), throwsA(isA<JsonUnsupportedObjectError>()));
    expect(await snapshot(), before);
  });
  test(
      'session switched while publication is queued is checked inside the transaction',
      () async {
    final before = await snapshot();
    final entered = Completer<void>();
    final release = Completer<void>();
    final blocker = raw.transaction((txn) async {
      entered.complete();
      await release.future;
      await txn.update(
          'local_session_state', {'owner_user_id': 202, 'generation': 8},
          where: 'id = ?', whereArgs: [1]);
    });
    await entered.future;
    final pending = publish(roster(30, 'New'));
    // Attach the exception listener before allowing the queued write to run.
    final refused =
        expectLater(pending, throwsA(isA<StaleAttendanceSyncSession>()));
    release.complete();
    await blocker;
    await refused;
    expect(await snapshot(), before);
  });
  test('cache rollback never changes durable unsynchronized attendance',
      () async {
    await db.saveAttendanceLocal(
        3,
        'Synthetic',
        '2026-11-05',
        [
          {'member_id': 30, 'status': 'late', 'notes': 'Durable unsent note'}
        ],
        expectedSession: session,
        originalAcademicYearId: 5);
    final before = await raw.query('pending_attendance', orderBy: 'id');
    await raw.execute(
        "CREATE TRIGGER synthetic_pair_pending_abort BEFORE INSERT ON cached_attendance WHEN NEW.class_id = 3 BEGIN SELECT RAISE(ABORT,'synthetic cache failure'); END");
    try {
      await expectLater(
          publish(roster(30, 'New')), throwsA(isA<DatabaseException>()));
    } finally {
      await raw.execute('DROP TRIGGER synthetic_pair_pending_abort');
    }
    expect(await raw.query('pending_attendance', orderBy: 'id'), before);
    await raw.delete('pending_attendance');
  });
  test('a successful empty pair affects only the requested cache scope',
      () async {
    final other = (await snapshot())
        .where((r) => r['class_id'] == 4 || r['date'] == '2026-11-04')
        .toList();
    await publish([]);
    expect(await db.getCachedStudents(3), isEmpty);
    expect((await db.getCachedAttendanceSheet(3, '2026-11-05'))!['students'],
        isEmpty);
    expect(
        (await snapshot())
            .where((r) => r['class_id'] == 4 || r['date'] == '2026-11-04')
            .toList(),
        other);
  });
}
