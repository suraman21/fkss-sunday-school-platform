import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;

import 'package:fkss_app/services/interrupted_database_migration.dart';

void main() {
  late Directory directory;
  late String path;

  setUp(() async {
    directory = await Directory.systemTemp.createTemp('migration-guard-');
    path = p.join(directory.path, 'wbws_offline_v4.db');
  });

  tearDown(() async {
    await directory.delete(recursive: true);
  });

  Future<void> put(String target, String contents) async {
    await File(target).writeAsString(contents, flush: true);
  }

  Future<Map<String, List<int>>> snapshot() async {
    final result = <String, List<int>>{};
    await for (final entity in directory.list(followLinks: false)) {
      if (entity is File) {
        result[p.basename(entity.path)] = await entity.readAsBytes();
      }
    }
    return result;
  }

  test('clean first install creates only a missing parent directory', () async {
    final fresh = p.join(directory.path, 'new', 'offline.db');
    await ensureNoInterruptedDatabaseMigration(fresh);
    expect(await Directory(p.dirname(fresh)).exists(), isTrue);
    expect(await File(fresh).exists(), isFalse);
    expect(await Directory(p.dirname(fresh)).list().isEmpty, isTrue);
  });

  test('main only is preserved and normal opening is permitted', () async {
    await put(path, 'Current database with newer unsynchronized work');
    final before = await snapshot();
    await ensureNoInterruptedDatabaseMigration(path);
    expect(await snapshot(), before);
  });

  test('ordinary main WAL SHM and journal are not migration artifacts',
      () async {
    for (final suffix in ['', '-wal', '-shm', '-journal']) {
      await put('$path$suffix', 'Ordinary SQLite $suffix');
    }
    final before = await snapshot();
    await ensureNoInterruptedDatabaseMigration(path);
    expect(await snapshot(), before);
  });

  const artifactSuffixes = [
    '.plaintext-migration-backup',
    '.plaintext-migration-backup-wal',
    '.plaintext-migration-backup-shm',
    '.plaintext-migration-backup-journal',
    '.encrypted-migration',
    '.encrypted-migration-wal',
    '.encrypted-migration-shm',
    '.encrypted-migration-journal',
  ];
  for (final suffix in artifactSuffixes) {
    test('$suffix blocks without replacing newer main or deleting survivors',
        () async {
      await put(path, 'Newer main operation and original owner/date');
      await put('$path$suffix', 'Historical candidate $suffix');
      final before = await snapshot();
      await expectLater(
        ensureNoInterruptedDatabaseMigration(path),
        throwsA(isA<InterruptedDatabaseMigration>().having(
          (error) => error.artifactName,
          'artifact filename',
          '${p.basename(path)}$suffix',
        )),
      );
      expect(await snapshot(), before);
    });
  }

  for (final suffix in artifactSuffixes) {
    test('$suffix without main must not become a fresh empty database',
        () async {
      await put('$path$suffix', 'Only surviving candidate');
      final before = await snapshot();
      await expectLater(ensureNoInterruptedDatabaseMigration(path),
          throwsA(isA<InterruptedDatabaseMigration>()));
      expect(await File(path).exists(), isFalse);
      expect(await snapshot(), before);
    });
  }

  test('repeated concurrent inspections preserve all candidate bytes',
      () async {
    await put(path, 'Newer main');
    await put('$path.plaintext-migration-backup', 'Older backup');
    await put('$path.encrypted-migration', 'Unknown staging');
    await put('$path-wal', 'Uncheckpointed main bytes');
    await put('$path-shm', 'Main shared-memory sidecar');
    await put('$path.plaintext-migration-backup-journal', 'Backup journal');
    final before = await snapshot();
    Future<void> inspect() async {
      await expectLater(ensureNoInterruptedDatabaseMigration(path),
          throwsA(isA<InterruptedDatabaseMigration>()));
    }

    await Future.wait(List.generate(4, (_) => inspect()));
    expect(await snapshot(), before);
  });

  test('recognized directory artifact is not treated as a missing file',
      () async {
    final artifact = Directory('$path.encrypted-migration');
    await artifact.create();
    await put(p.join(artifact.path, 'survivor'), 'Retain this evidence');
    await expectLater(ensureNoInterruptedDatabaseMigration(path),
        throwsA(isA<InterruptedDatabaseMigration>()));
    expect(await File(p.join(artifact.path, 'survivor')).readAsString(),
        'Retain this evidence');
    expect(await File(path).exists(), isFalse);
  });

  test('dangling migration link is detected without following its target',
      () async {
    final link = Link('$path.plaintext-migration-backup');
    final missingTarget = p.join(directory.path, 'missing-target');
    await link.create(missingTarget);
    await expectLater(ensureNoInterruptedDatabaseMigration(path),
        throwsA(isA<InterruptedDatabaseMigration>()));
    expect(await link.target(), missingTarget);
    expect(await File(path).exists(), isFalse);
  });

  test('parent I/O error propagates without creating a database', () async {
    final parent = p.join(directory.path, 'not-a-directory');
    await put(parent, 'Preserve the blocking file');
    await expectLater(
      ensureNoInterruptedDatabaseMigration(p.join(parent, 'offline.db')),
      throwsA(isA<FileSystemException>()),
    );
    expect(await File(parent).readAsString(), 'Preserve the blocking file');
  });

  test('artifacts for another database do not block this database', () async {
    await put(p.join(directory.path, 'other.db.encrypted-migration'),
        'Other database candidate');
    final before = await snapshot();
    await ensureNoInterruptedDatabaseMigration(path);
    expect(await snapshot(), before);
    expect(await File(path).exists(), isFalse);
  });

  test('exception guidance does not expose a full private filesystem path', () {
    final error = InterruptedDatabaseMigration('$path.encrypted-migration');
    expect(error.toString(), contains('preserved'));
    expect(error.toString(), contains('Do not clear app data or reinstall'));
    expect(error.toString(), isNot(contains(directory.path)));
  });
}
