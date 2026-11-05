import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/attendance_original_year.dart';

void main() {
  late Directory directory;
  late Database db;
  setUp(() async {
    sqfliteFfiInit();
    directory =
        await Directory.systemTemp.createTemp('attendance-year-migrate-');
    db = await databaseFactoryFfi.openDatabase('${directory.path}/old.db');
    await db.execute('CREATE TABLE pending_attendance (id INTEGER PRIMARY KEY, '
        'client_op_id TEXT, owner_user_id INTEGER, class_id INTEGER, date TEXT, '
        'status TEXT, notes TEXT, sync_state TEXT, attempt_count INTEGER)');
    await db.execute('PRAGMA user_version = 37');
    for (final state in [
      'pending',
      'in_flight',
      'retry_wait',
      'needs_attention'
    ]) {
      await db.insert('pending_attendance', {
        'client_op_id': 'synthetic-$state',
        'owner_user_id': 101,
        'class_id': 3,
        'date': '2026-11-05',
        'status': 'absent',
        'notes': 'ሰላም 🌍\r\n\u0000',
        'sync_state': state,
        'attempt_count': 2,
      });
    }
  });
  tearDown(() async {
    await db.close();
    await directory.delete(recursive: true);
  });
  test(
      'additive v37 migration preserves all original rows, states and unknown provenance',
      () async {
    final before = await db.query('pending_attendance', orderBy: 'id');
    await db.transaction(migrateAttendanceOriginalYear);
    await db.transaction(migrateAttendanceOriginalYear);
    final after = await db.query('pending_attendance', orderBy: 'id');
    expect(after.map((r) => r[attendanceOriginalYearColumn]),
        everyElement(isNull));
    expect([
      for (final row in after) Map.of(row)..remove(attendanceOriginalYearColumn)
    ], before);
    expect(await db.getVersion(),
        37); // Helper never lies about owning open/version commit.
    await db.update('pending_attendance', {attendanceOriginalYearColumn: 5});
    await db.transaction(migrateAttendanceOriginalYear);
    expect(
        (await db.query('pending_attendance'))
            .map((r) => r[attendanceOriginalYearColumn]),
        everyElement(5));
  });
  test(
      'failure after ALTER rolls back column and preserves original database for retry',
      () async {
    final before = await db.query('pending_attendance');
    await expectLater(db.transaction((txn) async {
      await migrateAttendanceOriginalYear(txn);
      throw StateError('Synthetic failure after ALTER');
    }), throwsStateError);
    expect(
        (await db.rawQuery('PRAGMA table_info(pending_attendance)'))
            .map((c) => c['name']),
        isNot(contains(attendanceOriginalYearColumn)));
    expect(await db.query('pending_attendance'), before);
    expect(await db.getVersion(), 37);
    await db.transaction(migrateAttendanceOriginalYear);
    expect(
        (await db.query('pending_attendance'))
            .map((r) => r[attendanceOriginalYearColumn]),
        everyElement(isNull));
  });
  test('unexpected preexisting default/type fails without rewriting data',
      () async {
    await db.execute(
        'ALTER TABLE pending_attendance ADD COLUMN original_academic_year_id TEXT DEFAULT "unknown"');
    final before = await db.query('pending_attendance');
    await expectLater(
        db.transaction(migrateAttendanceOriginalYear), throwsStateError);
    expect(await db.query('pending_attendance'), before);
  });
  test('unexpected rowid primary key cannot manufacture unknown provenance',
      () async {
    final other = await databaseFactoryFfi
        .openDatabase('${directory.path}/unexpected.db');
    try {
      await other.execute(
          'CREATE TABLE pending_attendance (original_academic_year_id INTEGER PRIMARY KEY, notes TEXT)');
      await other.insert('pending_attendance',
          {'original_academic_year_id': 5, 'notes': 'Synthetic retained note'});
      final before = await other.query('pending_attendance');
      await expectLater(
          other.transaction(migrateAttendanceOriginalYear), throwsStateError);
      expect(await other.query('pending_attendance'), before);
    } finally {
      await other.close();
    }
  });
  for (final value in [null, 0, -1, '5', 5.0, true, 9007199254740992]) {
    test('coherence rejects unproven metadata ${value.runtimeType}_$value', () {
      expect(
          coherentAttendanceOriginalYear([
            {'original_academic_year_id': value}
          ]),
          isNull);
    });
  }
  test('coherence requires every row, never selects first or latest year', () {
    expect(coherentAttendanceOriginalYear([]), isNull);
    for (final second in [null, 6, 0, '5']) {
      expect(
          coherentAttendanceOriginalYear([
            {'original_academic_year_id': 5},
            {'original_academic_year_id': second}
          ]),
          isNull);
    }
    expect(
        coherentAttendanceOriginalYear([
          {'original_academic_year_id': 5},
          {'original_academic_year_id': 5}
        ]),
        5);
    expect(
        coherentAttendanceOriginalYear([
          {'original_academic_year_id': 9007199254740991}
        ]),
        9007199254740991);
  });
}
