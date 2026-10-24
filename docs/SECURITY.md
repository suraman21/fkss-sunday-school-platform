# Security

**Status:** canonical. **Verified against:** `main` at `46fbaa7` (2026-10-22) by reading the code. Production configuration was **not** re-tested for this document, unless a row says otherwise.

This repository is **public**. Open security and privacy findings, their evidence and their containment status are kept in a **restricted register maintained privately by the maintainers**, not in this repository. Do not add vulnerability details, member data, credentials or infrastructure identifiers to any file, issue or pull request here.

## 1. Reporting a vulnerability

- Report privately to the maintainers, through the GitHub account that owns this repository. **Do not** open a public issue or pull request.
- Include the affected path or endpoint, the steps to reproduce and the impact.
- **Do not include member personal data** in the report.
- Each report is recorded in the restricted register.

## 2. Controls verified in code

| Area | Control | Location |
|---|---|---|
| Secrets | Loaded from a PHP file outside the web root. The application **fails closed** if the file is missing | `config.php` |
| Session | Session ID regenerated at login. Cookie flags HttpOnly, Secure (over HTTPS) and SameSite=Lax. `AdminSessionGuard` revalidates sessions against the database: an absolute timeout, disabled accounts, changed credentials and impersonation integrity | `admin/backend/login.php`, `backend/core/browser.php`, `AdminSessionGuard` |
| Passwords | `password_hash` / `password_verify` | `admin/backend/login.php`, `AccountCredentialService` |
| Brute force | Shared, atomic login throttling | `SecurityRateLimiter` (migration `008`) |
| CSRF | Token required on state-changing admin requests | `config.php` (`requireCsrf`, `requireCsrfForPost`) |
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
| Mobile | Tokens in `flutter_secure_storage`; app lock with `local_auth`; Android backups disabled; no cleartext traffic | `Mobile/wbws_flutter_app/` |

## 3. Configuration and secrets

The secrets file is named `.fkss_env.php` (legacy name: `.wbws_env.php`). It lives outside the web root and defines constants. **Key names only. Never commit or paste values.**

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

**Further optional constants** are not listed in `env.example.php`. For example, the Telegram failure-alert settings (`MONITOR_TELEGRAM_ENABLED`, `MONITOR_TELEGRAM_BOT_TOKEN`, `MONITOR_TELEGRAM_CHAT_ID`) default to disabled in `monitor/error_monitor.php`, and can be defined in the secrets file. AI provider keys are stored in the database, encrypted with `AI_ENC_KEY`. A complete inventory of optional constants has **not** been compiled.

**Rules:**
1. Use one secrets file per environment. Test files contain only test values.
2. If a key may have been exposed, rotate it and record the rotation in the restricted register.
3. Rotating `JWT_SECRET` logs out every mobile session. Rotating `BACKUP_ENCRYPTION_KEY` makes older backups unreadable unless the old key is kept.

## 4. Known limitations (non-sensitive)

These are documented design limits. They are not the full list of findings.

- **Content Security Policy:** the CSP does not yet restrict scripts or styles; inline scripts are still used.
- **Third-party scripts:** some admin pages load Tailwind from its CDN without SRI. Other CDN assets use SRI.
- **Mobile offline cache:** the cache is not encrypted at the application level; it relies on Android file-based encryption.
- **APK signing:** the distributed Android APK is signed with a debug key (see [OPERATIONS.md](OPERATIONS.md#5-mobile-release)).
- **Runtime parity:** CI runs the tests on PHP 8.4 and lints every file on 8.5, while production runs PHP 8.5 ([DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md#5-runtime-parity-gap)).
- **No independent assessment:** no independent penetration test or certification has been performed.

## 5. Open findings

Open findings are tracked in the **restricted register**, together with severity, evidence, containment and the agreed action. This repository records only that the register exists. Do not interpret the absence of findings here as an absence of risk.
