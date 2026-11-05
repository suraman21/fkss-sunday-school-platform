import 'dart:io';

import 'package:path/path.dart' as p;

/// A storage survivor needs review before any writable database open.
abstract class OfflineDatabaseRecoveryRequired implements Exception {
  const OfflineDatabaseRecoveryRequired();
}

/// A migration survivor needs an explicit recovery decision, not auto-cleanup.
class InterruptedDatabaseMigration extends OfflineDatabaseRecoveryRequired {
  final String artifactName;

  const InterruptedDatabaseMigration(this.artifactName);

  @override
  String toString() =>
      'Offline database recovery is required. Migration files were preserved. '
      'Do not clear app data or reinstall.';
}

/// SQLite sidecars cannot authorize recreating their missing main database.
class OrphanedDatabaseSidecar extends OfflineDatabaseRecoveryRequired {
  final String artifactName;

  const OrphanedDatabaseSidecar(this.artifactName);

  @override
  String toString() =>
      'Offline database recovery is required. SQLite sidecar files were preserved. '
      'Do not clear app data or reinstall.';
}

/// Refuse a writable open for historical survivors or orphaned SQLite sidecars.
///
/// We cannot infer format, freshness or accepted operation contents from a
/// sibling's name. Do not rename, delete, choose a survivor or open an empty
/// replacement. Ordinary WAL/SHM/journal files are allowed with a regular main.
/// This is a preservation guard, not an encryption/conversion implementation.
Future<void> ensureNoInterruptedDatabaseMigration(String path) async {
  final directory = Directory(p.dirname(path));
  // A clean first install may not have a database directory yet. Creating
  // that directory does not create or modify any database candidate.
  await directory.create(recursive: true);
  final name = p.basename(path);
  final artifacts = <String>{
    '$name.plaintext-migration-backup',
    '$name.plaintext-migration-backup-wal',
    '$name.plaintext-migration-backup-shm',
    '$name.plaintext-migration-backup-journal',
    '$name.encrypted-migration',
    '$name.encrypted-migration-wal',
    '$name.encrypted-migration-shm',
    '$name.encrypted-migration-journal',
  };
  final mainSidecars = <String>{'$name-wal', '$name-shm', '$name-journal'};
  var hasRegularMain = false;
  String? sidecarName;
  // FileSystemEntity.type/exists can report absence on a lookup error.
  // Enumerate without following links and propagate inspection errors, so
  // unknown storage state cannot authorize creating a fresh empty database.
  await for (final entity in directory.list(followLinks: false)) {
    final entryName = p.basename(entity.path);
    if (artifacts.contains(entryName)) {
      throw InterruptedDatabaseMigration(entryName);
    }
    if (entryName == name && entity is File) hasRegularMain = true;
    if (mainSidecars.contains(entryName)) sidecarName ??= entryName;
  }
  // Wait for the complete enumeration: a sidecar may be listed before main.
  // Do not follow a main symlink or treat a directory as the expected file.
  if (!hasRegularMain && sidecarName != null) {
    throw OrphanedDatabaseSidecar(sidecarName);
  }
}
