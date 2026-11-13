<?php
/**
 * ============================================================
 * SECRETS TEMPLATE  —  env.example.php
 * ============================================================
 * This is a TEMPLATE. Do the following once, on the live server:
 *
 *   1. Copy this file to your account home folder, ONE LEVEL ABOVE
 *      public_html, and rename it to:   .fkss_env.php
 *      (Example full path:  /home/arkeonet/.fkss_env.php)
 *
 *   2. Fill in the real values below.
 *
 *   3. Set its permission to 0600 (owner read/write only):
 *          chmod 600 /home/arkeonet/.fkss_env.php
 *
 *   4. Reload the website. The "Setup required" message disappears.
 *
 * WHY ABOVE public_html:  files above the web root can never be
 * downloaded through a browser, so the database password stays secret
 * even if a web-server misconfiguration exposes the site folder.
 *
 * NEVER commit the real .fkss_env.php to git. (It is already listed in
 * .gitignore.) This example file is safe because it contains no real
 * secrets.
 * ============================================================
 */

// ---- Database (get these from cPanel → MySQL Databases) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'REPLACE_WITH_YOUR_DB_NAME');
define('DB_USER', 'REPLACE_WITH_YOUR_DB_USER');
define('DB_PASS', 'REPLACE_WITH_YOUR_DB_PASSWORD');

// ---- Site identity (optional — see school_config.php) ----
// Define the deployment's public domain here to override the development
// default in school_config.php. SITE_URL, ADMIN_URL, the API's CORS
// origins, QR-card payloads and the uptime check all derive from this
// single value, so this one line is the whole domain configuration.
// No scheme, no www, no trailing slash:
// define('SITE_DOMAIN', 'felegekidusan.com');


// ---- Security keys ----
// Generate long random strings. On the server you can run:
//   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
// Paste a DIFFERENT value into each of the three below. Keep them stable
// (do not change them after go-live, or existing logins/tokens break).
define('JWT_SECRET',         'REPLACE_WITH_A_LONG_RANDOM_STRING_1'); // mobile app login tokens
define('BACKUP_KEY',         'REPLACE_WITH_A_LONG_RANDOM_STRING_2'); // encrypts backups; required for restore
define('HEALTH_KEY',         'REPLACE_WITH_A_LONG_RANDOM_STRING_3'); // HTTP Basic/X-Health-Key health credential
define('MONITOR_SECRET_KEY', 'REPLACE_WITH_A_LONG_RANDOM_STRING_4'); // password for the error-monitor dashboard (/monitor/)
define('TELEMETRY_HASH_SECRET', 'REPLACE_WITH_A_LONG_RANDOM_STRING_5'); // HMAC salt for rotating telemetry IP hashes
// define('TELEMETRY_RETENTION_LOCK_PATH', '/home/YOUR_ACCOUNT/ssms_mobile_telemetry_retention.lock');

// ---- Mobile authorization-scope rollout (TEMPORARY / OPTIONAL) ----
// Build 24+ always receives live role/status/assignment revalidation. Older
// builds keep their former token-window behavior until this UNIX timestamp.
// Leave omitted during the build-24 pilot (the code defaults to compatibility).
// After build 24 is the enforced minimum, set this to a past timestamp (for
// example 1) so a spoofed old X-App-Build value cannot bypass revalidation.
// define('API_AUTHZ_LEGACY_COMPAT_UNTIL', 1);

// ---- Encrypted database backup storage (OPTIONAL) ----
// Defaults to `ssms_secure_backups` beside (not inside) public_html. The path
// must be absolute, outside the web root, and writable by the PHP/cron account.
// BACKUP_KEY above encrypts every new compressed backup. A separate stable key
// may be used if desired; preserve it securely or the backups cannot be restored.
// define('BACKUP_STORAGE_PATH', '/home/YOUR_ACCOUNT/ssms_secure_backups');
// define('BACKUP_ENCRYPTION_KEY', 'REPLACE_WITH_A_LONG_RANDOM_STRING_5');

// ---- Private member document storage (OPTIONAL) ----
// Defaults to a `ssms_private` directory beside (not inside) public_html.
// Set this only when your hosting layout needs a different non-web-accessible path.
// The PHP/web-server account must be able to create/write this directory.
// define('MEMBER_PRIVATE_STORAGE_PATH', '/home/YOUR_ACCOUNT/ssms_private');

// ---- AI provider key encryption (OPTIONAL but recommended) ----
// AI API keys (Gemini/Groq/OpenAI/…) are stored ENCRYPTED in the database.
// If you set a dedicated secret here, it is used to encrypt them; otherwise the
// system derives one from DB_PASS + JWT_SECRET above (which already live in this
// file, above the web root). Set a stable value and do NOT change it after
// saving keys, or the saved AI keys can no longer be decrypted (just re-enter
// them). Generate with:  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
// define('AI_ENC_KEY', 'REPLACE_WITH_A_LONG_RANDOM_STRING_5');

// ---- Deployment feature capabilities (OPTIONAL) ----
// Omit these to keep every current module enabled. Use real booleans only —
// quoted strings such as 'false' deliberately fail closed. Disabled modules are
// blocked by browser and REST routes; clients also hide their controls.
// define('FEATURE_AI_CHATBOT', false);
// define('FEATURE_GROUPS', false);
// define('FEATURE_FINANCE', false);
// define('FEATURE_MEZMUR', false);
// define('FEATURE_MATERIAL', false);
// define('FEATURE_ID_CARDS', false);
// define('FEATURE_ATTENDANCE', false);
// define('FEATURE_GRADES', false);
// define('FEATURE_REPORTS', false);
// define('FEATURE_EXPORT_PDF', false);
// define('FEATURE_MONITOR', false);
// Attendance alerting v2 is OFF by default; enable only after sql/071+ are applied.
// define('FEATURE_ATTENDANCE_V2', true);

// Review-only Telegram registration. Generate unique values independently for
// development, FKSS and WBSS; never copy credentials or operator records.
// define('FEATURE_TELEGRAM_REGISTRATION', false);
// define('TELEGRAM_BOT_TOKEN', '123456789:replace-with-deployment-local-token');
// define('TELEGRAM_WEBHOOK_SECRET', 'replace-with-32-to-256-url-safe-characters');
// define('TELEGRAM_WEBHOOK_MAX_BYTES', 262144);
// define('TELEGRAM_PROCESSED_UPDATE_RETENTION_DAYS', 30);
// define('TELEGRAM_CANCELLED_SESSION_RETENTION_DAYS', 90);
// define('TELEGRAM_AUDIT_RETENTION_DAYS', 730);

// Test-data loading/deletion is disabled by default. Enable only in a controlled
// staging environment, never on a live member database.
// define('ENABLE_TEST_DATA_TOOLS', true);

// ---- Phone app updates (OPTIONAL) ----
// Do NOT put the APK in this file. See Mobile/HOW_TO_SHIP_AN_UPDATE.md
// and api/v1/app_release.example.php  →  /home/USER/.fkss_app_release.php

// ---- Mezmur audio media (Cloudflare R2) — P0 audio upgrade (OPTIONAL) ----
// Fill these to stream hymns from Cloudflare R2. Egress is free, but Class A
// (PUT/LIST) and Class B (GET) OPERATIONS are billed past the free monthly
// allowance, and streaming generates a Class B read per play — size the
// budget on operations, not on bandwidth.
// Until they are set, hymn audio endpoints answer
// "not configured" and the rest of the system is untouched.
// How to get them: R2 → Manage R2 API Tokens → Create API Token (Object
// Read & Write, scoped to ONE bucket) — the Secret is shown only once.
//   ACCOUNT_ID     → dashboard right rail (Account Details) — a 32-hex id
//   PUBLIC_BASE    → your bucket's custom domain, e.g.
//                    https://media.fkss.arkeonethiopia.com
//                    (NOT the r2.dev dev URL — that one is test-only).
//                    This is the ONE value to change when you move to a
//                    new server/domain — nothing is stored in the DB.
// The real values below live ONLY in .fkss_env.php, never in git.
// define('MEZMUR_MEDIA_ACCOUNT_ID',          'REPLACE_WITH_YOUR_R2_ACCOUNT_ID');
// define('MEZMUR_MEDIA_ACCESS_KEY_ID',       'REPLACE_WITH_YOUR_R2_ACCESS_KEY');
// define('MEZMUR_MEDIA_SECRET_ACCESS_KEY',   'REPLACE_WITH_YOUR_R2_SECRET_KEY');
// define('MEZMUR_MEDIA_BUCKET',              'fkss-media');
// define('MEZMUR_MEDIA_PUBLIC_BASE',         'https://media.fkss.arkeonethiopia.com');
// define('MEZMUR_MEDIA_MAX_BYTES',           15728640);            // 15 MB
// define('MEZMUR_MEDIA_ALLOWED_EXT',         'mp3,m4a,ogg,wav,aac,opus');
