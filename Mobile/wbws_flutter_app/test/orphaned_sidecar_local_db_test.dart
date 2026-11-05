import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/interrupted_database_migration.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test(
      'LocalDb preserves a committed orphan WAL instead of creating empty main',
      () async {
    sqfliteFfiInit();
    final directory = await Directory.systemTemp.createTemp('orphan-open-');
    final sourcePath = p.join(directory.path, 'synthetic-source.db');
    final path = p.join(directory.path, 'wbws_offline_v4.db');
    await databaseFactoryFfi.setDatabasesPath(directory.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    final source = await databaseFactoryFfi.openDatabase(sourcePath);
    late List<int> walBefore;
    try {
      await source.rawQuery('PRAGMA journal_mode = WAL');
      await source.rawQuery('PRAGMA wal_autocheckpoint = 0');
      await source.execute('CREATE TABLE synthetic_intents ('
          'id INTEGER PRIMARY KEY, owner_id INTEGER, business_date TEXT, '
          'payload TEXT)');
      await source.insert('synthetic_intents', {
        'id': 1,
        'owner_id': 101,
        'business_date': '2026-11-04',
        'payload': 'Older value',
      });
      await source.rawQuery('PRAGMA wal_checkpoint(TRUNCATE)');
      await source.update(
          'synthetic_intents',
          {
            'payload': 'Newer committed unsynchronized original',
          },
          where: 'id = 1');
      await ensureNoInterruptedDatabaseMigration(sourcePath);
      final rows = await source.query('synthetic_intents');
      expect(rows.single['payload'], 'Newer committed unsynchronized original');
      // Deliberately incomplete, invented filesystem fixture. Copy a stable
      // committed WAL with no transaction in progress, not a backup recipe.
      walBefore = await File('$sourcePath-wal').readAsBytes();
      expect(walBefore.length, greaterThan(32));
      await File('$path-wal').writeAsBytes(walBefore, flush: true);
    } finally {
      await source.close();
    }

    Database? opened;
    Object? failure;
    try {
      try {
        opened = await LocalDb().database;
      } catch (error) {
        failure = error;
      } finally {
        await opened?.close();
      }
      // The old guard allowed SQLite to create an unrelated empty main and
      // discard this orphan WAL, even though it contained a committed update.
      expect(await File(path).exists(), isFalse);
      expect(await File('$path-wal').exists(), isTrue);
      expect(await File('$path-wal').readAsBytes(), walBefore);
      expect(failure, isA<OrphanedDatabaseSidecar>());
    } finally {
      await directory.delete(recursive: true); // synthetic teardown only
    }
  });
}
