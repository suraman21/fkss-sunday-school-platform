import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/local_db.dart';

void main() {
  late Directory directory;
  late Database raw;
  late LocalDb db;
  List<Map<String, dynamic>> roster(int first, String name) => [
        {'member_id': first, 'student_name': '$name A', 'father_name': 'Synthetic'},
        {'member_id': first + 1, 'student_name': '$name B', 'member_code': '$name-code'},
      ];
  Future<List<Map<String, Object?>>> rows(int classId) => raw.query(
      'cached_students', where: 'class_id = ?', whereArgs: [classId], orderBy: 'member_id');
  setUpAll(() async {
    sqfliteFfiInit();
    directory = await Directory.systemTemp.createTemp('roster-refresh-');
    await databaseFactoryFfi.setDatabasesPath(directory.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    raw = await db.database;
  });
  setUp(() async {
    await raw.delete('cached_students');
    await db.cacheStudents(3, roster(10, 'Old'));
    await db.cacheStudents(4, roster(20, 'Other'));
  });
  tearDownAll(() async {
    await raw.close();
    await directory.delete(recursive: true);
  });
  test('late SQLite failure preserves the previous committed complete roster', () async {
    final before = await rows(3);
    final other = await rows(4);
    await raw.execute("CREATE TRIGGER synthetic_roster_abort BEFORE INSERT ON cached_students WHEN NEW.member_id = 31 BEGIN SELECT RAISE(ABORT,'synthetic second row failure'); END");
    try {
      await expectLater(db.cacheStudents(3, roster(30, 'New')),
          throwsA(isA<DatabaseException>()));
    } finally {
      await raw.execute('DROP TRIGGER synthetic_roster_abort');
    }
    expect(await rows(3), before);
    expect(await rows(4), other);
    final reader = await databaseFactoryFfi.openDatabase(raw.path,
        options: OpenDatabaseOptions(readOnly: true, singleInstance: false));
    try {
      expect(await reader.query('cached_students', where: 'class_id = ?',
          whereArgs: [3], orderBy: 'member_id'), before);
    } finally {
      await reader.close();
    }
  });
  test('late malformed input cannot erase the old roster', () async {
    final before = await rows(3);
    await expectLater(db.cacheStudents(3, [roster(30, 'New').first, 42]), throwsA(anything));
    expect(await rows(3), before);
  });
  test('duplicate replacement failure does not destroy the old roster', () async {
    final before = await rows(3);
    await expectLater(db.cacheStudents(3, [roster(30, 'New').first, roster(30, 'New').first]),
        throwsA(isA<DatabaseException>()));
    expect(await rows(3), before);
  });
  test('successful replacement commits only the complete new class roster', () async {
    final other = await rows(4);
    await db.cacheStudents(3, roster(30, 'New'));
    final result = await rows(3);
    expect(result.map((r) => r['member_id']), [30, 31]);
    expect(result.map((r) => r['student_name']), ['New A', 'New B']);
    expect(result.map((r) => r['updated_at']).toSet(), hasLength(1));
    expect(await rows(4), other);
  });
  test('an explicitly successful empty roster replaces just that class', () async {
    final other = await rows(4);
    await db.cacheStudents(3, []);
    expect(await rows(3), isEmpty);
    expect(await rows(4), other);
  });
  test('caller mutation after invocation cannot change the captured refresh', () async {
    final input = roster(30, 'New');
    final future = db.cacheStudents(3, input);
    input[0]['student_name'] = 'Mutated later';
    input.clear();
    await future;
    expect((await rows(3)).map((r) => r['student_name']), ['New A', 'New B']);
  });
  test('legacy id alias and field defaults remain compatible', () async {
    await db.cacheStudents(3, [{'id': 30}]);
    final row = (await rows(3)).single;
    expect(row['member_id'], 30);
    for (final key in ['student_name', 'father_name', 'member_code', 'gender']) {
      expect(row[key], '');
    }
  });
}
