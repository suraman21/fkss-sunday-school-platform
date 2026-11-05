import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/interrupted_database_migration.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('LocalDb never replaces newer pending work with a migration backup',
      () async {
    sqfliteFfiInit();
    final directory = await Directory.systemTemp.createTemp('migration-open-');
    await databaseFactoryFfi.setDatabasesPath(directory.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    final path = p.join(directory.path, 'wbws_offline_v4.db');
    final backupPath = '$path.plaintext-migration-backup';

    Future<void> seed(String target, String operation) async {
      final database = await databaseFactoryFfi.openDatabase(
        target,
        options: OpenDatabaseOptions(
          version: 37,
          onCreate: (db, _) async {
            await db.execute('CREATE TABLE saved_intents ('
                'operation_id TEXT PRIMARY KEY, owner_id INTEGER, '
                'scope_id INTEGER, business_date TEXT, payload TEXT)');
            await db.insert('saved_intents', {
              'operation_id': operation,
              'owner_id': 101,
              'scope_id': 3,
              'business_date': '2026-11-03',
              'payload': 'Synthetic unsynchronized original',
            });
          },
        ),
      );
      await database.close();
    }

    try {
      await seed(path, 'newer-main-operation');
      await seed(backupPath, 'older-backup-operation');
      final mainBefore = await File(path).readAsBytes();
      final backupBefore = await File(backupPath).readAsBytes();
      Object? failure;
      Database? opened;
      try {
        opened = await LocalDb().database;
      } catch (error) {
        failure = error;
      } finally {
        await opened?.close();
      }

      // Assert actual file identity first: a generic open error after
      // overwriting main is not evidence of safe failure.
      expect(await File(path).readAsBytes(), mainBefore);
      expect(await File(backupPath).exists(), isTrue);
      expect(await File(backupPath).readAsBytes(), backupBefore);
      expect(failure, isA<InterruptedDatabaseMigration>());

      final preserved = await databaseFactoryFfi.openDatabase(
        path,
        options: OpenDatabaseOptions(readOnly: true),
      );
      try {
        final rows = await preserved.query('saved_intents');
        expect(rows.single['operation_id'], 'newer-main-operation');
        expect(rows.single['owner_id'], 101);
        expect(rows.single['scope_id'], 3);
        expect(rows.single['business_date'], '2026-11-03');
      } finally {
        await preserved.close();
      }
    } finally {
      // Synthetic fixture teardown only, never production recovery behavior.
      await directory.delete(recursive: true);
    }
  });
}
