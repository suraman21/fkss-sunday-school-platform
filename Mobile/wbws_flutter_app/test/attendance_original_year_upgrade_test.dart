import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/local_db.dart';

void main() {
  test('actual LocalDb v37 open upgrades additively without inventing old year',
      () async {
    sqfliteFfiInit();
    final dir =
        await Directory.systemTemp.createTemp('attendance-year-v37-open-');
    await databaseFactoryFfi.setDatabasesPath(dir.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    final path = '${dir.path}/wbws_offline_v4.db';
    // Minimal historical attendance fixture, not an installed-school DB copy.
    final old = await databaseFactoryFfi.openDatabase(path,
        options: OpenDatabaseOptions(
            version: 37,
            onCreate: (db, _) async {
              await db.execute(
                  'CREATE TABLE pending_attendance (id INTEGER PRIMARY KEY, '
                  'client_op_id TEXT, owner_user_id INTEGER, class_id INTEGER, date TEXT, '
                  'member_id INTEGER, status TEXT, notes TEXT, synced INTEGER, '
                  'sync_state TEXT, attempt_count INTEGER, next_attempt_at TEXT)');
              await db.insert('pending_attendance', {
                'client_op_id': 'synthetic-old-identity',
                'owner_user_id': 101,
                'class_id': 3,
                'date': '2025-01-01',
                'member_id': 30,
                'status': 'late',
                'notes': 'Old exact note\r\n🌍',
                'synced': 0,
                'sync_state': 'needs_attention',
                'attempt_count': 4
              });
              await db.execute(
                  'CREATE TABLE cached_classes (id INTEGER PRIMARY KEY, label TEXT)');
              await db.insert(
                  'cached_classes', {'id': 3, 'label': 'Synthetic old cache'});
            }));
    final before = await old.query('pending_attendance');
    final cache = await old.query('cached_classes');
    await old.close();
    Database? upgraded;
    try {
      upgraded = await LocalDb().database;
      expect(await upgraded.getVersion(), 42);
      final after = await upgraded.query('pending_attendance');
      expect(after.single['original_academic_year_id'], isNull);
      expect(
          [Map.of(after.single)..remove('original_academic_year_id')], before);
      expect(await upgraded.query('cached_classes'), cache);
    } finally {
      await upgraded?.close();
      await dir.delete(recursive: true);
    }
  });
}
