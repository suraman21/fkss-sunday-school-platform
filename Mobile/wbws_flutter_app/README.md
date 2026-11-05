# FKSS mobile app

Flutter client for Felege Kidusan Sunday School. Role-based online workflows coexist with offline attendance/grade packets, communication drafts and read caches. Source checkpoint at development baseline `7209278` is **1.7.4+41**; this is not evidence of a published or installed APK.

- User procedures: [mobile account/device settings](../../docs/user_manual/ACCOUNT_AND_DEVICE.md), [sync and recovery](../../docs/user_manual/SYNC_AND_RECOVERY.md), [app updates](../../docs/user_manual/APP_UPDATES.md).
- Build/signing: [release guide](docs/RELEASE.md).
- Test setup and exact CI versions: [development and testing](../../docs/DEVELOPMENT_AND_TESTING.md).
- Manual coverage is partial; see [the coverage queue](../../docs/user_manual/COVERAGE.md).

## Local data and security boundaries

The SQLite database (`wbws_offline_v4.db`) contains server read caches **and unsent local work**. Calling it only a cache is misleading: unsent drafts/packets cannot be recovered simply by fetching server records after a reinstall.

The current app does not use SQLCipher for application-level database encryption. It relies on the app-private sandbox and device/OS storage protection; the repository does not establish the encryption, lock strength or compromise status of a particular phone. Tokens and the cached staff profile use platform secure storage. Android app-data backup is disabled in the manifest; do not interpret that as a guarantee that no external device/vendor backup can exist.

Startup/storage recovery paths are intended to preserve existing work. A retry screen or legacy-database recovery implementation is **not a guarantee against all data loss**. Do not clear app storage, reinstall or delete database files as a routine troubleshooting step. Review the actual failure and offline-work inventory first.

## Sign-out and account changes

Logout uses `SessionCoordinator`, not unconditional deletion:

- With durable private work, the dialog offers **Cancel**, **Keep work & sign out** and **Discard permanently**.
- Preservation enters same-account reauthentication and keeps private work and the app passcode. It does not authorize another user to inherit the work.
- Explicit discard purges private caches/queues, credentials and passcode through a persisted cleanup state. Cleanup failures remain a protection/recovery condition, not successful erasure.
- Shared hymn caches and queued hymn operations are deliberately retained for an authorized curator. They are not private-account work.
- Authorization changes can pause old-scope work with count-only visibility. Restoring sign-in does not automatically make incompatible work writable.

Implementation: [session_logout_dialog.dart](lib/widgets/session_logout_dialog.dart), [session_service.dart](lib/services/session_service.dart), `LocalDb.clearAllUserData()` in [local_db.dart](lib/services/local_db.dart). Detailed user controls and consequences are in [sync and recovery](../../docs/user_manual/SYNC_AND_RECOVERY.md).

## Build and release checks

Use the workflow-pinned Flutter SDK, JDK and Android SDK components; do not infer the supported toolchain from an old minimum-version note.

```bash
flutter pub get --enforce-lockfile
flutter analyze
flutter test
flutter build apk --release
```

`flutter analyze` is a local check, not currently an enforced backend CI step. The release command requires the signing/release-guide prerequisites; CI builds a debug APK and does not establish release signing or field installation. Verify dependency files remain unchanged.

Before publishing, the owner tests a fresh install and a compatible signed upgrade on the actual target Android phone, using synthetic cached rosters and unsent attendance/grade/message work. Check cold start, same-account recovery, explicit discard, different-account refusal, access-change holds, sync, update verification/installer behavior and accessibility. Record what ran and what remains untested. iOS-specific behavior needs separate evidence; do not infer it from an Android build.

Development integration comes first, then owner acceptance, then separately authorized production promotion. A repository merge is not an installed-phone update.
