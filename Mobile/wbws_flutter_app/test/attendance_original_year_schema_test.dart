import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/local_db.dart';

void main() {
  test('real fresh LocalDb retains an explicit unknown original-year slot',
      () async {
    sqfliteFfiInit();
    final dir =
        await Directory.systemTemp.createTemp('attendance-year-schema-');
    await databaseFactoryFfi.setDatabasesPath(dir.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    final db = await LocalDb().database;
    try {
      final columns =
          await db.rawQuery('PRAGMA table_info(pending_attendance)');
      expect(
          columns.map((c) => c['name']), contains('original_academic_year_id'));
      final column =
          columns.singleWhere((c) => c['name'] == 'original_academic_year_id');
      expect(column['type'], 'INTEGER');
      expect(column['notnull'], 0);
      expect(column['dflt_value'], isNull);
      expect(await db.getVersion(), 42);
    } finally {
      await db.close();
      await dir.delete(recursive: true);
    }
  });
}
