# Security

**Status:** maintained security reference. Original control inventory: `46fbaa7` (2026-10-22); local-lock, diagnostics and signing-evidence boundaries clarified against `7e28157` (2026-10-28). These are source-level observations, not a full per-endpoint authorization audit, penetration test or production configuration assessment. Current hosting, private-register contents and distributed APK certificates were not accessed.

This repository is **public**. **Private-handling policy:** keep open security/privacy findings, evidence and containment status in a restricted register maintained privately by the maintainers, not in this repository. The current register's existence, contents and maintenance were not verified by this source audit. Do not add vulnerability details, member data, credentials or infrastructure identifiers to any file, issue or pull request here.

## 1. Reporting a vulnerability

- Report privately to the maintainers, through the GitHub account that owns this repository. **Do not** open a public issue or pull request.
- Include the affected path or endpoint, the steps to reproduce and the impact.
- **Do not include member personal data** in the report.
- Maintainers should record each report in the restricted register; this is the required handling procedure, not confirmation that a particular report was recorded.

## 2. Controls verified in code

| Area | Control | Location |
|---|---|---|
| Secrets | Loader searches one directory above the project, two above, then the project directory, trying `.fkss_env.php` before `.wbws_env.php` at each location. If no candidate exists, the HTTP setup path stops with status 500. Outside-web-root placement is a deployment requirement, not enforced by this search | `config.php`, `$_envBaseDirs` / `$_envFileNames` / `$_envLoaded` |
| Session | Session ID regenerated at login. Cookie flags HttpOnly, Secure (over HTTPS) and SameSite=Lax. `AdminSessionGuard` revalidates sessions against the database: an absolute timeout, disabled accounts, changed credentials and impersonation integrity | `admin/backend/login.php`, `backend/core/browser.php`, `AdminSessionGuard` |
| Passwords | `password_hash` / `password_verify` | `admin/backend/login.php`, `AccountCredentialService` |
| Brute force | Shared, atomic login throttling | `SecurityRateLimiter` (migration `008`) |
| CSRF | `requireCsrf` rejects failed validation; `requireCsrfForPost` returns for non-POST requests and validates POST tokens when called. This helper inventory does not establish coverage of every state-changing admin action | `config.php` (`requireCsrf`, `requireCsrfForPost`) |
| Access control | Central page and endpoint role guard; class scoping for teachers; PII field shaping in the API | `admin/access_control.php`, `api/v1/core/acl.php` ([ROLES_AND_PERMISSIONS.md](ROLES_AND_PERMISSIONS.md)) |
| API tokens | 15-minute access tokens; one-time rotating refresh with replay detection; bearer header only | `api/v1/core/auth.php`, `RefreshTokenService` ([API.md](API.md#2-authentication)) |
| Idempotency | Write replay protection for mobile sync | `api/v1/core/middleware.php` (migration `009`) |
| Destructive actions | Typed confirmation for dangerous operations (`409 confirm_phrase_required`) | Admin endpoints |
| SQL | Prepared statements are the norm (mysqli). Search parameters are bound (covered by tests in `tests/security/`) | — |
| Headers | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS (over HTTPS), and a baseline CSP | `config.php` |
| Audit trail | `activity_logs` plus `SecurityAuditService` | `admin/backend/services/` |
| Files | Member documents served through a role-checked handler from private storage | `MemberFileService`, `admin/member_file.php` |
| Web exposure | `.htaccess` rules deny the non-web folders (`sql/`, `docs/`, `tests/`, `scripts/`, `vendor/`, `Mobile/`), legacy migration folders and monitor internals | `.htaccess`, `admin/.htaccess`, `monitor/.htaccess` |
| Repository hygiene | CI rejects tracked credential and artifact files and secret-shaped strings | `.github/workflows/production-integrity.yml` |
| Mobile | Tokens in `flutter_secure_storage`; separate local PIN in `AppLockService`, optional biometric authentication through `local_auth`; Android backups disabled and cleartext traffic disabled in the manifest | `Mobile/wbws_flutter_app/` |

## 3. Configuration and secrets

The secrets file is named `.fkss_env.php` (legacy name: `.wbws_env.php`) and defines constants. Deploy it outside the web root. The loader also accepts a project-directory fallback; checked-in source does not prove the location actually used by a host. **Key names only. Never commit or paste values.**

| Group | Keys (from `env.example.php`) |
|---|---|
| Database | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| Site | `SITE_DOMAIN` |
| Signing and keys | `JWT_SECRET` (API token HMAC), `BACKUP_KEY`, `BACKUP_ENCRYPTION_KEY`, `HEALTH_KEY`, `MONITOR_SECRET_KEY`, `TELEMETRY_HASH_SECRET`, `AI_ENC_KEY` |
| Paths | `BACKUP_STORAGE_PATH`, `MEMBER_PRIVATE_STORAGE_PATH`, `TELEMETRY_RETENTION_LOCK_PATH` |
| Compatibility | `API_AUTHZ_LEGACY_COMPAT_UNTIL` |
| Feature flags | `FEATURE_AI_CHATBOT`, `FEATURE_GROUPS`, `FEATURE_FINANCE`, `FEATURE_MEZMUR`, `FEATURE_MATERIAL`, `FEATURE_ID_CARDS`, `FEATURE_ATTENDANCE`, `FEATURE_GRADES`, `FEATURE_REPORTS`, `FEATURE_EXPORT_PDF`, `FEATURE_MONITOR` |
| Test tooling | `ENABLE_TEST_DATA_TOOLS`. Must be **false or absent in production** |
| Media storage | `MEZMUR_MEDIA_ACCOUNT_ID`, `MEZMUR_MEDIA_ACCESS_KEY_ID`, `MEZMUR_MEDIA_SECRET_ACCESS_KEY`, `MEZMUR_MEDIA_BUCKET`, `MEZMUR_MEDIA_PUBLIC_BASE`, `MEZMUR_MEDIA_MAX_BYTES`, `MEZMUR_MEDIA_ALLOWED_EXT` |

**Additional source-defined settings:** `PROFILE_PRIVATE_STORAGE_PATH` may override private profile-image storage; `FEATURE_ATTENDANCE_V2` defaults to false in `school_config.php` and is separately enforced by `FeatureGate`. Record actual booleans, not truthy strings; feature exposure is not action-level permission. Private storage and its cleanup cron require the [retained deployment safeguards](audits/DEPLOYMENT_RUNBOOK.md#profile-image-orphan-cleanup). Do not print secret-file contents to document these settings.

**Further optional constants** are not listed in `env.example.php`. For example, the Telegram failure-alert settings (`MONITOR_TELEGRAM_ENABLED`, `MONITOR_TELEGRAM_BOT_TOKEN`, `MONITOR_TELEGRAM_CHAT_ID`) default to disabled in `monitor/error_monitor.php`, and can be defined in the secrets file. AI provider keys are stored in the database, encrypted with `AI_ENC_KEY`. A complete inventory of optional constants has **not** been compiled.

**Rules:**
1. Use one secrets file per environment. Test files contain only test values.
2. If a key may have been exposed, rotate it and record the rotation in the restricted register.
3. Changing `JWT_SECRET` changes the HMAC verification key for API access and refresh tokens on servers using the new value (`api/v1/core/auth.php`, `verifyTokenState`; `api/v1/routes/auth.php`, refresh verification). Previously issued signatures do not validate with a different key. This is not proof that every installed app immediately signs out or erases cached/unsent work. `BackupService::encryptionSecret` selects `BACKUP_ENCRYPTION_KEY` when defined, otherwise `BACKUP_KEY`; preserve the effective key used for each backup and verify recovery privately before a key change. A named constant alone is not evidence of backup recoverability.

## 4. Known limitations (non-sensitive)

These are documented design limits. They are not the full list of findings.

- **Content Security Policy:** the CSP does not yet restrict scripts or styles; inline scripts are still used.
- **Third-party scripts:** some admin pages load Tailwind from its CDN without SRI. Other CDN assets use SRI.
- **Mobile private storage:** SQLite holds cached personal data and durable unsent work without application-level SQLCipher encryption. App-private/OS protection depends on the device. A local PIN is not database encryption, account authentication or a forensic-erasure guarantee.
- **Diagnostics:** bounded error-log tails can contain sensitive information. Review/redact and share only with an authorized administrator through an approved private channel; do not paste raw reports publicly. See [account/device guidance](user_manual/ACCOUNT_AND_DEVICE.md).
- **APK signing — DEPENDENCY NOT VERIFIED:** checked-in Gradle configuration falls back to debug signing when local `android/key.properties` is absent. This is not proof of the currently offered APK's certificate or compatibility with installed phones. The owner must verify actual signer continuity and key custody before publication; changing certificate identity can prevent in-place updates and an uninstall can destroy unsent work.
- **Runtime parity:** CI behavior tests use PHP 8.4 and lint uses 8.5. The production PHP 8.5 observation is historical; current runtime and behavioral parity remain unverified ([runtime gap](DEVELOPMENT_AND_TESTING.md#5-runtime-parity-gap)).
- **Independent assessment:** no independent penetration-test/certification evidence is recorded here. Do not describe this source review or green CI as security certification.

## 5. Open findings

Record open findings privately in the **restricted register**, with severity, evidence, containment and the agreed action. This is a handling requirement; this audit has not verified the register's existence, contents or maintenance. Do not interpret the absence of public findings as an absence of risk or as proof that a private operational process is functioning.
