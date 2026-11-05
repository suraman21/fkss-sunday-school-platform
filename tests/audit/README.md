# Production-audit regression tests

These are **destructive synthetic-data tests, not production health checks**. They create fixture accounts, write and delete records, inject database failures with temporary triggers, and reset rate-limit buckets. HTTP tests refuse non-localhost targets; `db_fixture.php` requires CLI, `SSMS_AUDIT_TESTING=1`, a loopback database host and database name `ssms`.

## Environment used in this audit

- PHP 8.4.24 / Apache 2.4.68, and a separate PHP 8.2.33 FastCGI instance behind Apache.
- MariaDB 11.8.6, InnoDB, synthetic data only.
- Node 20.20.2 and Playwright Chromium.
- Flutter 3.47.2 / Dart 3.13.2 for the repository's mobile tests.
- No production connection, credentials, records or uploads were used.

The SDKs, database state and installed packages are disposable runtime dependencies, not part of the patch. The audit did not change the Composer or Flutter dependency locks.

## Provision a disposable environment

1. Install PHP with mysqli, PDO MySQL, mbstring, XML/DOM, GD, curl, zip, sodium and zlib, MariaDB, Python, Node, Apache, and optionally PHP CGI for the independent HTTP-denial tests.
2. Use a separate machine/container. Prepare a **test-only** `.fkss_env.php` above this checkout with `DB_HOST=127.0.0.1`, `DB_NAME=ssms`, disposable database credentials and random JWT/backup secrets. Never copy that file to production.
3. Read `tests/e2e/provision_env.sh` before running it. It is an old local bootstrap, **not a production migration runner**. Its non-linear SQL chain reports known ordering/index errors and does repairs for the fixture schema. Record and review its output.
4. On this disposable database only:

   ```bash
   export SSMS_AUDIT_TESTING=1
   bash tests/e2e/provision_env.sh
   # The old provisioner misses the array-based Finance/Materials migration.
   # Run this ONCE on a fresh fixture DB; its default categories are not
   # deduplicated safely by the legacy migration on repeated executions.
   # The file is archived in sql/legacy/php-migrations/ and cannot run from
   # there (by design). Copy it back to its old folder for this one run only,
   # then remove only that copy. Confirm the exact destination is absent
   # before copying; stop rather than overwrite existing local work.
   # Disposable test machines only — never production.
   mkdir -p admin/migrations
   cp sql/legacy/php-migrations/admin/004_add_finance_material_tables.php admin/migrations/
   php admin/migrations/004_add_finance_material_tables.php
   rm -- admin/migrations/004_add_finance_material_tables.php
   # Remove the temporary directory only if it is empty; never delete other work.
   rmdir admin/migrations 2>/dev/null || true
   ```

5. Serve the checkout through Apache with `AllowOverride All` and the rewrite, headers, expires and deflate modules. Use an appropriate PHP handler for the test machine. Preserve the repository's cPanel handler stanza for production; any local handler override belongs in local Apache configuration, not the patch. The root and `admin/.htaccess` denials must both be exercised.

A PHP built-in server is useful for development but does **not** implement `.htaccess`; it cannot validate the complete access-control test suite. Never expose it with real records, secrets or production credentials.

## Run

```bash
# No database required. The CGI check skips explicitly if php-cgi is missing.
python3 -m unittest discover -s tests/audit -p 'test_*.py' -v

# Live local HTTP + synthetic database, including rollback/concurrency probes.
SSMS_AUDIT_TESTING=1 SSMS_AUDIT_BASE=http://127.0.0.1:8081 \
  python3 tests/audit/http_regressions.py

# API bootstrap with initial PHP error display enabled (CGI, local DB).
SSMS_AUDIT_TESTING=1 python3 tests/audit/check_api_bootstrap.py

# Existing workflow suite, with its corrected post-login CSRF handling.
SSMS_AUDIT_TESTING=1 BASE=http://127.0.0.1:8081 \
  bash tests/e2e/run_smoke.sh

# Install Playwright in a disposable tooling directory, NOT the PHP app.
# Set NODE_PATH to that directory's node_modules if needed.
SSMS_AUDIT_TESTING=1 SSMS_AUDIT_BASE=http://127.0.0.1:8081 \
  node tests/audit/browser_regressions.cjs
SSMS_AUDIT_TESTING=1 SSMS_AUDIT_BASE=http://127.0.0.1:8081 \
  node tests/audit/browser_contracts.cjs

python3 tests/audit/static_inventory.py

# Existing security/source-contract suite: currently NOT a green release gate.
python3 -m unittest discover -s tests/security -p 'test_*.py' -v

cd Mobile/wbws_flutter_app
flutter pub get
flutter analyze lib test --no-pub --no-fatal-infos --no-fatal-warnings
flutter test --no-pub --reporter expanded
```

Do not run the HTTP suites concurrently: their account seeding and limiter resets deliberately overlap. The inventory includes tracked and unignored newly added files, excludes its own generated evidence, and records exact file hashes/line counts. Its function/control/literal-route indexes are regex-assisted navigation aids, **not proof that each function or button was exercised**. Dynamic routes, JavaScript closures, Dart methods and conditional UI still need semantic review.

## Important test-harness corrections

- Login now rotates the anonymous CSRF token. Tests must read the authenticated token from a dashboard, not reuse the login-form token.
- PHP 8.2 session cookies can contain URL-encoded commas. The CLI session fixture URL-decodes the cookie before opening the session file.
- Test class IDs are looked up by fixture class code; repeated seeds change auto-increment IDs.
- The browser scanner reads the HTML `id` attribute, not `form.id`, which can be shadowed by a form input named `id`.
- The older Mezmur DOM stand-in now implements the window/element event methods the real script uses. Its filter assertion counts the reconciliation announcement, not an unrelated successful-rename toast.
- `test/update_artifact_test.dart` uses numeric literals compatible with the declared Dart 3.3 language floor and no invalid constant string multiplication. No phone-app feature or dependency upgrade was made.

## Boundaries

The tests do not exercise a physical phone, camera permissions, native background audio, R2 object storage, real APK installation, AI-provider requests, production-size load, every member-edit field, or every financial reversal/partial-payment policy. Backup creation, download and decryption are tested; an actual disaster-recovery restore must still be rehearsed in an isolated staging database.
