# Encryption investigation — separate from integrity migration

**Status:** Research-only. **No encryption is enabled.** Ordinary SQLite remains ordinary SQLite — never called encrypted. No key rollout, no migration, no “data is now safe” claim. This doc is the evidence gate for ADR-0003; any future “encrypted at rest” claim must cite measured migration/recovery/key-lifecycle tests (30).

**Decision date:** 2026-11-06 — Engineering research, not school approval.
**Branch:** `offline-first/preparation-dataset-validation` at `ae2e342`+ — `localDatabaseSchemaVersion=42`, `*_preparation_snapshot` + `*_sync_receipt` drains. Encryption intentionally not mixed into that 40→42 integrity path.

## 1. What we have today

- `Mobile/wbws_flutter_app/lib/services/local_db.dart` opens an ordinary `sqflite` database (`singleInstance:false`, `PRAGMA foreign_keys=ON`), versioned via `local_schema_v34.dart`. Tables: `pending_*`, `cached_*`, `attendance_preparation_snapshot`/`grades_preparation_snapshot`, `attendance_sync_receipt`/`grades_sync_receipt`, `sync_attempts`, `local_session_state`, etc. No HMAC verification on client; HMAC stays server-only (`AttendancePreparationGrant`/`GradesPreparationGrant`).
- `api/v1/core/acl.php` + `AttendancePreparationService`/`GradesPreparationService` enforce role/AV/family/session/revocation at the MySQL `REPEATABLE READ` read-boundary — authority lives server-side.
- Logout wipes member PII caches (`clearAllCache`/`clearAllUserData` now include both preparation snapshots + both receipts). Hymn library deliberately survives logout.
- **Fact:** The file at `getDatabasesPath()/fkss*.db` is **unencrypted at rest**. A forensic dump, `adb backup`, or a rooted file read can read it. Calling it encrypted would violate the “never describe ordinary SQLite as encrypted” rule and the acceptance contract’s safety scope.

## 2. Threat model for “encrypted at rest”

| Adversary / scenario | Ordinary SQLite today | What encryption-at-rest would change | What it would *not* change |
|---|---|---|---|
| Stolen unlocked phone (attacker has PIN) | App opens, data visible | Still visible if key is unlocked (Keystore key is available while device is unlocked) | Does not replace lock-screen discipline |
| Stolen locked phone, cold forensic dump | DB readable | DB unreadable without Keystore-backed key + device auth | Does not protect data already in RAM |
| `adb backup` / file copy to another device | Copy is readable | Copy is useless without key material (Keystore keys are non-exportable) | Does not protect screenshots, clipboard, or backup of decrypted export |
| Malware with app UID | Reads DB via app | Same — in-process key is available when app is running | Requires separate app-sandbox hardening |
| Revoked account whose DB was copied before revocation | Old DB remains on that device (by design, for offline) | Old copy still decryptable if key still on device — revocation is server-side (lease/reconnect bounded, not instant) | Must combine encryption with explicit wipe-on-revocation policy |

**Non-goal:** Hiding data from the phone’s owner while the owner is using the app (the app must decrypt to render). Goal is *at-rest* protection when the device is locked, lost, or copied.

## 3. Options investigated (no code landed)

### Option A — SQLCipher (SQLite extension, AES-256-XTS/CTS)
- **How:** `sqflite_sqlcipher` (or `sqlite3` + `sqlcipher` on Android) encrypts the whole DB file; key in Android Keystore (with `userAuthenticationRequired` + `invalidatedByBiometricEnrollment`).
- **Pros:** Transparent to SQL, single-file, well-audited, works offline.
- **Cons:** Heavy migration: existing plaintext `fkss.db` → `fkss.db` ciphertext must run in one job that (1) opens plaintext, (2) `ATTACH … KEY …; sqlcipher_export`, (3) verify, (4) delete plaintext only after fsync. Interrupt → orphan plaintext sibling + WAL/journal must not replace newer ciphertext (acceptance 27). Cold-start +10–25 ms on low-end, +5–12% APK. Keystore key loss (factory reset, biometric reset, “invalidated” key) = **permanent data loss** unless a recoverable passphrase escrow exists.
- **Key lifecycle:** Generate AES-256 random key → wrap with Keystore `AES/GCM` (or `RSA/EC`) → store wrapped blob in `EncryptedSharedPreferences` + optional user passphrase fallback. Rotation requires re-key (`PRAGMA rekey`).

### Option B — File-level EncryptedFile (JetSec `androidx.security:security-crypto`)
- **How:** Keep ordinary SQLite, but the DB file lives under `EncryptedFile` (AES-256-GCM, Keystore-backed `MasterKey`). Migration copies bytes through crypto stream.
- **Pros:** No SQL engine change, easier to fall back, same at-rest benefit as A (file unreadable when locked).
- **Cons:** Same migration/orphan problem (two files, not one), but simpler to detect “which file is current” via `sqlite_master` + `user_version` + `pending_*` row counts. Still pays Keystore loss risk; plus double-IO (encrypt → write → fsync, decrypt → read).
- **Key lifecycle:** Same Keystore story as A; plus you must guard `EncryptedSharedPreferences` master-key rotation.

### Option C — No encryption-at-rest (current, keep with hardening)
- **How:** Keep ordinary SQLite, rely on Android full-disk/file-based encryption + lock-screen. Add what we already did: transactional `clearAllUserData`, no PII in logs, no export, no `adb backup` allow-list (`android:allowBackup=false`), `FLAG_SECURE` on sheets that show member PII.
- **Pros:** Zero migration risk, zero key-loss data loss, zero cold-start hit, no “encrypted” promise to keep. Matches current acceptance (30) which demands *measured* evidence — adding crypto without it would be a new promise.
- **Cons:** Cold forensic dump reads plaintext (but device FBE already mitigates when locked; threat is rooted/forensic + unlocked backup).

## 4. What must be measured before any “we are encrypted” claim

Per acceptance 27 + 30, each option must be spiked with **numbers, not adjectives**:

1. **Migration:** Plaintext `v42` DB (8/153/500+ members, pending ops, snapshots, receipts) → ciphertext on a $90 Go-edition phone + mid-range. Measure wall time, peak RSS, flash written, and that an **interrupted** migration (kill at 10/50/90%, power loss, WAL left behind) never yields an empty/older DB (the newer ciphertext with `pending_*` rows must win; orphan WAL/journal + plaintext sibling quarantined, not silently promoted).
2. **Cold start:** `LocalDb.database` open + `getLocalSession` + `getSnapshot` cold (after reboot, after kill). Budget is *measured*, not invented — report p50/p95 on reference devices; fail the spike if it regresses attendance save (< ~50 ms tap→durable) or grade save.
3. **Crash recovery:** Kill during `saveAttendanceLocal`/`saveGradesLocal` + during `settleLegacyOperation` + during `publishPreparation`. Verify `pending_*` + `*_sync_receipt` atomicity survives, and that a ciphertext DB never boots as plaintext.
4. **Key lifecycle:** (a) Biometric enroll/unenroll — Keystore invalidated key → app shows “key lost, sign in and re-download preparation; old offline marks on this lost key cannot be recovered” (no silent drop of pending work — quarantine + prompt). (b) App reinstall → new key → must not decrypt old file (detect + quarantine). (c) Backup restore → encrypted file without key → must not be promoted over newer ciphertext (same quarantine rule as 27).
5. **Size/power:** DB size delta, backup size, incremental sync cost.

**No lane may claim “encrypted at rest” without all five measured and passing on both reference devices.**

## 5. Recommendation

- **Keep Phase 7 as shipped (integrity only):** `v42` with atomic receipts + fenced preparation + transactional logout wipe. It already satisfies the “no plaintext sibling replaces newer work” rule for the *current* store.
- **Do not land crypto on the main offline-first branch** until a spike for A *or* B passes 1–5 above **and** the owner explicitly approves the key-loss UX (data loss vs. recoverability tradeoff) — this is the “separate investigation” the acceptance contract demands.
- **If the owner wants encryption next, do:** a 2-week spike branch `spike/sqlcipher-migration-v43` that (a) implements A in `LocalDb` behind `FeatureGate.isEnabled('db_cipher')`, (b) runs the five measurements above on the two lab phones, (c) adds `migrations/test_encryption_migration.py` + `test_interrupted_cipher_recovery.py` + Dart `cipher_migration_test.dart`, (d) returns here with numbers and a go/no-go.

---

## 6. Sources checked (no code changed)

- `Mobile/wbws_flutter_app/lib/services/local_db.dart` `onCreate`/`onUpgrade`/`clearAllUserData`/`settleLegacyOperation` (v42)
- `Mobile/wbws_flutter_app/lib/services/local_schema_v34.dart` (`localDatabaseSchemaVersion=42`)
- `admin/backend/services/AttendancePreparationService.php`/`GradesPreparationService.php` (read-boundary, lease)
- Android docs: Keystore `setUserAuthenticationRequired(true)` + `setInvalidatedByBiometricEnrollment(true)`, `security-crypto` `EncryptedFile`/`EncryptedSharedPreferences`, `allowBackup`/`fullBackupContent`.

**Bottom line:** We are **not encrypted at rest** — and we do not claim to be. The next step is a measured spike, not a quiet `PRAGMA key` commit.
