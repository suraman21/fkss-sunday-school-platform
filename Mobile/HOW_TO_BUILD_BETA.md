# FKSS Beta — test app for the dev server (dev branch only)

**This file and the beta settings exist ONLY on the `dev` branch of
`suraman21/fkss-sunday-school-platform`. They are never merged into `main` and never pushed to the
production repo (`Felege-Kidusan/felegekidusan`).** A guard test on `main`
(`tests/security/test_mobile_target_environment.py`) fails if beta values
ever reach `main`.

| | Production app (`main`) | FKSS Beta (`dev`) |
|---|---|---|
| API | `https://felegekidusan.com/api/v1` | `https://felegekidusan.arkeonethiopia.com/api/v1` |
| Package | `com.arkeonethiopia.fkss` | `com.arkeonethiopia.fkss.beta` |
| Name on phone | FKSS | **FKSS Beta** |
| Login screen | — | orange "BETA · test server: felegekidusan.arkeonethiopia.com" chip |
| Data on phone | separate | separate (different package → own storage, own sessions) |

Both apps install side by side on one phone. Logging in on Beta uses the
**dev server's** accounts and tokens (its own `JWT_SECRET` in `.fkss_env.php`);
nothing it does touches production.

## Build the beta APK

```bash
git fetch origin
git checkout dev
git pull origin dev
cd Mobile/wbws_flutter_app
flutter clean && flutter pub get
flutter build apk --release --split-per-abi      # or without --split-per-abi
# → build/app/outputs/flutter-apk/app-arm64-v8a-release.apk
```

Install it over USB / send the file to the phone. The production FKSS app
stays installed and untouched.

## Build the production APK (unchanged)

```bash
git checkout main
cd Mobile/wbws_flutter_app && flutter build apk --release
```

## Keeping `dev` current

After every update on `main` a maintainer merges `main` into `dev`
(`git checkout dev && git merge main`) so Beta always has the newest code
plus the beta-only changes. Never merge the other way.

## Dev server notes
- The dev server deploys from `suraman21/fkss-sunday-school-platform` **main** (`git pull`), so the
  `dev` branch never affects it.
- Beta checks `/api/v1/app/config` on the dev server for updates; keep the dev
  server's `.fkss_app_release.php` `latest_build` ≤ the beta build you installed
  (or leave it unset) so it does not nag.

## If the app stays on the splash screen (white screen with the logo)

The native splash is shown until Flutter draws its first frame. Since the
2026‑10‑20 startup‑watchdog fix the app can no longer stay there silently:
after at most 40 s it shows **"The app is taking too long to start"** with the
name of the step that did not answer (`audio_service`, `orientation`,
`local_database`, `session_bootstrap`, `app_lock`, …). Include that line in the bug
report — it names the step that failed.

To capture the real log from a Windows PC (phone with USB debugging on):

```powershell
adb logcat -c
adb logcat -v time *:E flutter:V AndroidRuntime:E ActivityManager:I > fkss_launch.txt
# now open FKSS Beta on the phone, wait 60 s, then press Ctrl+C
```

Attach `fkss_launch.txt`. Before rebuilding, always:

```powershell
git checkout dev
git pull origin dev
git log -1 --oneline        # must show the newest dev commit
cd Mobile/wbws_flutter_app
flutter clean
flutter pub get
flutter build apk --release --split-per-abi
```
