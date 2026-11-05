import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;

import 'package:fkss_app/services/interrupted_database_migration.dart';

void main() {
  late Directory directory;
  late String path;
  setUp(() async {
    directory = await Directory.systemTemp.createTemp('orphan-guard-');
    path = p.join(directory.path, 'offline.db');
  });
  tearDown(() async => directory.delete(recursive: true));

  Future<void> put(String target, String value) =>
      File(target).writeAsString(value, flush: true);

  Future<void> expectBlocked() async {
    await expectLater(ensureNoInterruptedDatabaseMigration(path),
        throwsA(isA<OrphanedDatabaseSidecar>()));
  }

  for (final suffix in ['-wal', '-shm', '-journal']) {
    test('$suffix without main is retained and blocks opening', () async {
      await put('$path$suffix', 'Only survivor $suffix');
      await expectBlocked();
      expect(await File(path).exists(), isFalse);
      expect(
          await File('$path$suffix').readAsString(), 'Only survivor $suffix');
    });

    for (final sidecarFirst in [true, false]) {
      test('$suffix with regular main allows SQLite recovery ($sidecarFirst)',
          () async {
        if (sidecarFirst) await put('$path$suffix', 'Recovery sidecar');
        await put(path, 'Regular main file');
        if (!sidecarFirst) await put('$path$suffix', 'Recovery sidecar');
        await ensureNoInterruptedDatabaseMigration(path);
        expect(await File(path).readAsString(), 'Regular main file');
        expect(await File('$path$suffix').readAsString(), 'Recovery sidecar');
      });
    }

    test('$suffix with directory in place of main fails closed', () async {
      await Directory(path).create();
      await put(p.join(path, 'retain'), 'Directory evidence');
      await put('$path$suffix', 'Recovery sidecar');
      await expectBlocked();
      expect(await File(p.join(path, 'retain')).readAsString(),
          'Directory evidence');
      expect(await File('$path$suffix').readAsString(), 'Recovery sidecar');
    });

    test('$suffix with dangling main link fails closed without following it',
        () async {
      final target = p.join(directory.path, 'absent-main-target');
      await Link(path).create(target);
      await put('$path$suffix', 'Recovery sidecar');
      await expectBlocked();
      expect(await Link(path).target(), target);
      expect(await File(target).exists(), isFalse);
      expect(await File('$path$suffix').readAsString(), 'Recovery sidecar');
    });

    test('$suffix directory survivor is not mistaken for absence', () async {
      await Directory('$path$suffix').create();
      await put(p.join('$path$suffix', 'retain'), 'Surviving evidence');
      await expectBlocked();
      expect(await File(path).exists(), isFalse);
      expect(await File(p.join('$path$suffix', 'retain')).readAsString(),
          'Surviving evidence');
    });

    test('$suffix dangling link survivor is retained', () async {
      final target = p.join(directory.path, 'absent-sidecar-target');
      await Link('$path$suffix').create(target);
      await expectBlocked();
      expect(await Link('$path$suffix').target(), target);
      expect(await File(path).exists(), isFalse);
      expect(await File(target).exists(), isFalse);
    });
  }

  test('another database sidecars do not block clean first install', () async {
    await put(p.join(directory.path, 'another.db-wal'), 'Other database');
    await ensureNoInterruptedDatabaseMigration(path);
    expect(await File(path).exists(), isFalse);
    expect(await File(p.join(directory.path, 'another.db-wal')).readAsString(),
        'Other database');
  });

  test('repeated concurrent readers preserve all orphan sidecars', () async {
    for (final suffix in ['-wal', '-shm', '-journal']) {
      await put('$path$suffix', 'Original $suffix');
    }
    await Future.wait(List.generate(4, (_) => expectBlocked()));
    expect(await File(path).exists(), isFalse);
    for (final suffix in ['-wal', '-shm', '-journal']) {
      expect(await File('$path$suffix').readAsString(), 'Original $suffix');
    }
  });

  test('orphan error shares safe guidance without exposing private paths', () {
    final error = OrphanedDatabaseSidecar('$path-wal');
    expect(error, isA<OfflineDatabaseRecoveryRequired>());
    expect(error.toString(), contains('SQLite sidecar files were preserved'));
    expect(error.toString(), contains('Do not clear app data or reinstall'));
    expect(error.toString(), isNot(contains(directory.path)));
    expect(const InterruptedDatabaseMigration('fixture'),
        isA<OfflineDatabaseRecoveryRequired>());
  });
}
