# FKSS Mobile App

Flutter client for FKSS (Felege Kidusan Sunday School). The app supports
role-based online workflows plus offline attendance, grades, and roster caches.

## Local-data security

The offline database (`wbws_offline_v4.db`) is a **cache** of server data plus
temporary unsynced attendance/grade drafts. It lives in the app's private
sandbox and is protected at rest by the device's own file-based encryption,
the standard approach on Android. The server
remains the source of truth; synced data can always be re-fetched after a
reinstall.

The app no longer performs app-level (SQLCipher) database encryption.
Version 1.1.15 attempted an in-place encryption upgrade that dead-ended some
phones at a recovery screen, and for cached data the reliability cost
outweighed the protection.

What is still protected:

- **Tokens and the staff profile** live in platform secure storage (Android
  Keystore / iOS Keychain), never in plain preferences. Secure-storage reads
  at bootstrap are best-effort: a keystore hiccup degrades to the login
  screen instead of blocking the app.
- **Startup is resilient.** A genuine offline-storage failure shows a retry
  screen (data is never deleted) and appends the real error to
  `fkss_bootstrap_error.log` in the app directory for diagnosis.
- **Interrupted 1.1.15 upgrades self-heal.** If the old encryption step left
  the original file set aside, first startup restores it and removes the
  stale sibling files — no data is lost and no "clear app data" is needed.
- **Logout** removes all prior-user rows from the local cache
  (`clearAllUserData`), with WAL checkpoint + vacuum.
- **App-data backup is disabled** (`android:allowBackup="false"`) so local
  caches are never carried between devices or restored across phones.

## Required release checks

Use Flutter 3.27 or newer (the minimum in `pubspec.yaml`); CI uses 3.44.9.

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --release
```

Before rollout, test both a fresh install and an upgrade containing
representative cached rosters plus unsynced attendance/grade packets on
Android and iOS. Confirm the upgrade opens the existing database with all
unsynced packets intact, logout removes all prior-user rows, and a second
cold start opens the database successfully.
