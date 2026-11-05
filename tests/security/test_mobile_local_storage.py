"""Regression checks for the mobile local-storage posture.

The offline database includes caches and durable unsynchronized work. It uses
ordinary SQLite with OS sandbox protection, not verified SQLCipher. Tokens and
the staff profile stay in platform secure storage. Ambiguous historical
migration survivors must block writable open without deleting or replacing
files; diagnostic retry is not permission to guess which copy is newest.
"""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
APP = ROOT / "Mobile/wbws_flutter_app"


class MobileLocalStorageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.database = (APP / "lib/services/local_db.dart").read_text(encoding="utf-8")
        cls.migration_guard = (
            APP / "lib/services/interrupted_database_migration.dart"
        ).read_text(encoding="utf-8")
        cls.api = (APP / "lib/services/api_service.dart").read_text(encoding="utf-8")
        cls.main = (APP / "lib/main.dart").read_text(encoding="utf-8")
        cls.pubspec = (APP / "pubspec.yaml").read_text(encoding="utf-8")
        cls.lock = (APP / "pubspec.lock").read_text(encoding="utf-8")
        cls.proguard = (APP / "android/app/proguard-rules.pro").read_text(encoding="utf-8")
        cls.manifest = (APP / "android/app/src/main/AndroidManifest.xml").read_text(encoding="utf-8")
        cls.encryption_module_removed = not (
            APP / "lib/services/local_database_security.dart"
        ).exists()

    def test_local_db_uses_sandboxed_plain_sqflite(self):
        self.assertIn("sqflite: ^2.3.0", self.pubspec)
        self.assertNotIn("sqflite_sqlcipher", self.pubspec)
        self.assertNotIn("name: sqflite_sqlcipher", self.lock)
        self.assertIn("name: sqflite", self.lock)
        self.assertIn("package:sqflite/sqflite.dart", self.database)
        self.assertNotIn("package:sqflite_sqlcipher", self.database)
        self.assertNotIn("local_database_security", self.database)
        self.assertNotIn("LocalDatabaseKeyStore", self.database)
        self.assertNotIn("EncryptedDatabaseMigrator", self.database)
        self.assertNotRegex(self.database, r"password:\s*")
        # The current version is centralized in the audited schema contract.
        self.assertIn("version: localDatabaseSchemaVersion", self.database)
        # Set-form PRAGMAs must use rawQuery on Android; db.execute() throws
        # and crashed every database open in 1.1.15.
        self.assertIn("PRAGMA secure_delete = ON", self.database)
        self.assertIn("rawQuery('PRAGMA secure_delete = ON')", self.database)
        self.assertNotIn("db.execute('PRAGMA secure_delete", self.database)
        self.assertIn("CREATE TABLE IF NOT EXISTS cached_grade_sheets", self.database)
        self.assertIn("await db.transaction", self.database)

    def test_app_level_encryption_module_is_removed(self):
        self.assertTrue(self.encryption_module_removed)
        self.assertNotIn("net.sqlcipher", self.proguard)
        self.assertNotIn("sqflite_sqlcipher", self.proguard)
        self.assertIn("io.flutter.plugins.sqflite", self.proguard)

    def test_interrupted_upgrade_preserves_candidates_before_writable_open(self):
        guard_call = "await ensureNoInterruptedDatabaseMigration(path);"
        self.assertIn(guard_call, self.database)
        self.assertLess(self.database.index(guard_call),
                        self.database.index("return await openDatabase("))
        for suffix in (".plaintext-migration-backup", ".encrypted-migration",
                       ".plaintext-migration-backup-wal",
                       ".encrypted-migration-journal"):
            self.assertIn(suffix, self.migration_guard)
        self.assertIn("directory.list(followLinks: false)", self.migration_guard)
        self.assertIn("throw InterruptedDatabaseMigration(entryName)",
                      self.migration_guard)
        # Unknown/read-failed states must not be flattened into a clean open.
        self.assertNotIn("catch (", self.migration_guard)
        self.assertNotIn("catch (_)", self.migration_guard)
        for mutation in (".delete(", ".rename(", ".writeAs", "openDatabase("):
            self.assertNotIn(mutation, self.migration_guard)
        self.assertNotIn("await main.delete()", self.database)
        self.assertNotIn("await backup.rename(path)", self.database)

    def test_migration_failure_gets_specific_preservation_guidance(self):
        self.assertIn("migrationRecoveryRequired: error is OfflineDatabaseRecoveryRequired",
                      self.main)
        self.assertIn("Do not clear app data or reinstall", self.main)
        self.assertIn("Offline database files need review", self.main)

    def test_orphaned_main_sidecars_cannot_authorize_empty_main(self):
        self.assertIn("'$name-wal', '$name-shm', '$name-journal'", self.migration_guard)
        self.assertIn("entryName == name && entity is File", self.migration_guard)
        self.assertIn("!hasRegularMain && sidecarName != null", self.migration_guard)
        self.assertIn("throw OrphanedDatabaseSidecar(sidecarName)", self.migration_guard)
        self.assertIn("class OrphanedDatabaseSidecar extends OfflineDatabaseRecoveryRequired",
                      self.migration_guard)

    def test_tokens_and_profile_stay_in_platform_secure_storage(self):
        self.assertIn("flutter_secure_storage", self.pubspec)
        self.assertIn("FlutterSecureStorage", self.api)
        self.assertIn("legacyUserJson", self.api)
        self.assertIn("prefs.remove(AppConfig.userDataKey)", self.api)
        self.assertNotIn("prefs.setString(AppConfig.userDataKey", self.api)
        # Bootstrap classifies credential state; it never converts a partial
        # or unreadable session into a destructive logout.
        self.assertIn("Future<CredentialLoadResult> loadCredentials()", self.api)
        self.assertIn("CredentialLoadResult.incomplete", self.api)
        self.assertIn("CredentialLoadResult.storageUnavailable", self.api)
        self.assertNotIn("discardedInvalidSession", self.api)
        self.assertIn("await SessionCoordinator().bootstrap()", self.main)
        self.assertNotIn("await LocalDb().clearAllUserData()", self.main)

    def test_bootstrap_degrades_gracefully_with_diagnostics(self):
        # Protected-storage failures are typed and fail closed; they are not
        # flattened to "no credentials" and do not delete secure values.
        load = self.api.split("Future<CredentialLoadResult> loadCredentials()")[1]
        load = load.split("/// Backward-compatible")[0]
        self.assertIn("CredentialLoadResult.unreadable", load)
        self.assertIn("CredentialLoadResult.storageUnavailable", load)
        self.assertNotIn("_secureStorage.delete", load)
        # The failure screen keeps a retry path and records the real error.
        self.assertIn("OfflineDataProtectionFailureApp", self.main)
        self.assertIn("SessionProtectionFailureScreen", self.main)
        self.assertIn("runBootstrap", self.main)
        self.assertIn("onPressed", self.main)
        self.assertIn("fkss_bootstrap_error.log", self.main)
        self.assertNotIn("deleteDatabase(", self.main)

    def test_app_data_backup_stays_disabled(self):
        self.assertIn('android:allowBackup="false"', self.manifest)
        self.assertIn('android:fullBackupContent="false"', self.manifest)


if __name__ == "__main__":
    unittest.main()
