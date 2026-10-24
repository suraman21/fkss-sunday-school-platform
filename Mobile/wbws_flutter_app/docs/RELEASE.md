# FKSS Android Release Guide (P65)

**The one rule:** a build that has not come out of `scripts\build-release.ps1`
is not a release. `flutter run --release` on a dev machine builds **only the
connected phone's CPU architecture** — a 64-bit-only APK that crashes at the
splash screen on every 32-bit phone (`UnsatisfiedLinkError`). That is exactly
how the four-phone launch-crash incident happened. The script always produces
the **universal** APK (all ABIs) and can additionally produce per-ABI builds.

---

## 1. Build a release

From `Mobile/wbws_flutter_app`:

```powershell
# Standard release (universal APK — works on every phone)
powershell -ExecutionPolicy Bypass -File scripts\build-release.ps1

# Also build the ~2x smaller per-ABI APKs (recommended at fleet scale)
powershell -ExecutionPolicy Bypass -File scripts\build-release.ps1 -SplitPerAbi

# Iterating a hotfix (skips tests — do NOT use for a real release)
powershell -ExecutionPolicy Bypass -File scripts\build-release.ps1 -SkipTests
```

The script records the Flutter toolchain version, runs the test suite, builds,
and writes `dist\<version>+<build>\` containing:

| File | Contents |
|---|---|
| `fkss-<version>+<build>-universal.apk` | All ABIs. **Always host this.** |
| `fkss-<version>+<build>-arm64-v8a.apk` | 64-bit phones only (optional) |
| `fkss-<version>+<build>-armeabi-v7a.apk` | 32-bit phones only (optional) |
| `release-manifest.json` | Toolchain, sizes and SHA-256 of every artifact |

## 2. Host it on the server

The APK lives on the web server, never in git. Paths refer to
`.fkss_app_release.php` above the website root:

```php
'apk_path'       => '/home/<user>/fkss_releases/fkss.apk',       // universal — ALWAYS
'apk_arm64_path' => '/home/<user>/fkss_releases/fkss-arm64.apk', // optional
'apk_arm32_path' => '/home/<user>/fkss_releases/fkss-arm32.apk', // optional
```

Then bump `latest_version` / `latest_build` to match what the app itself
reports in **Profile → App → Version** (see the golden rule below). Raise
`min_build` only when old phones **must** update (security fix) — that is
the force-update lever. `force_update: true` forces everyone on the
current version.

> **⚠ The golden rule: the numbers must match the APK.** The app compares
> its own self-reported version (`lib/utils/config.dart`) against
> `latest_build` on the server. If you advertise `latest_build: 20` while
> hosting an APK that reports itself as 19, every phone — including ones
> that just updated — keeps seeing "update available" forever. The
> version-sync test (`test/version_sync_test.dart`) keeps `pubspec.yaml`
> and `lib/utils/config.dart` locked together, and the build script runs
> it before every release; the only remaining rule for you is: **upload
> the APK that matches the numbers you set.**

**Migrating old phones that don't show the update banner.** App versions
before the optional-update banner only react to *forced* updates. To pull
the whole fleet forward once: publish the new APK, then set `min_build` to
one more than the old installed build (e.g. old phones run 17 →
`'min_build' => 18`). They get the blocking update screen, install the new
APK (which reports its true, higher build), and are free — then set
`min_build` back to `1`. This is safe **only** because the hosted APK
self-reports its real build (see the golden rule); a mismatched APK would
loop.

**Why per-ABI?** The universal APK carries both architectures (~68MB). A
per-ABI build is roughly half the size — meaningful on 8GB-storage, 1GB-RAM
phones on mobile data. The app sends `?abi=` automatically; the server falls
back to universal for anything it does not recognize, and older app versions
keep receiving the universal file. **The universal APK must stay published
forever** — it is the fallback for every old client.

Verification after hosting:

1. `https://<server>/api/v1/app/download` in a browser → the APK downloads
   (and note `X-App-Sha256` in the response headers if you inspect it).
2. Open the app on a phone → the update banner appears within a minute.
3. After updating: Profile → App → **Diagnostics** shows no new crash entry.

## 3. Signing (keystore) — read before switching!

Today the app signs release builds with the debug keys, which the installed
fleet already trusts, so in-app updates install cleanly. The build is wired
for the industry-standard flow: the moment `android/key.properties` exists
(git-ignored), Gradle signs with that keystore instead.

**⚠ MIGRATION WARNING — a one-way door.** Android refuses to install an update
whose signature differs from the installed app. Switching keystores means
every phone must **uninstall and reinstall** — and uninstalling deletes that
phone's offline database. Treat it as a planned milestone:

1. Back up the keystore + passwords in two places (password manager + offline
   copy). **Losing it after migration = the same fleet-wide reinstall.**
2. Generate it once, never regenerate:
   ```powershell
   keytool -genkey -v -keystore fkss-release.jks -alias fkss `
     -keyalg RSA -keysize 4096 -validity 10000
   ```
3. Create `android/key.properties` (never commit it — already git-ignored):
   ```properties
   storePassword=<from step 2>
   keyPassword=<from step 2>
   keyAlias=fkss
   storeFile=../fkss-release.jks
   ```
4. Build, host, and coordinate the reinstall window with all users *before*
   publishing — old installs will not see the new build as an update.

## 4. Renderer problems (Impeller) — the decision tree

Symptoms of an Impeller/old-GPU problem: app installs and launches on modern
phones but crashes on specific old ones (Mali-400 / Mali-T820 class GPUs),
and `adb logcat -b crash -d` shows SIGSEGV inside `libflutter.so` with
`Impeller` frames — **not** `UnsatisfiedLinkError` (that is the ABI problem,
solved by the universal APK).

- **Field test first** on the failing phone:
  `adb logcat -b crash -d`.
- If (and only if) the log confirms renderer crashes, add to
  `AndroidManifest.xml` inside `<application>`:
  ```xml
  <meta-data
      android:name="io.flutter.embedding.android.EnableImpeller"
      android:value="false" />
  ```
  That reverts those devices to the battle-tested Skia renderer. Remove it
  only after re-testing on the same phone model.

## 5. Field diagnostics without a laptop

Users can now produce a real crash report themselves: **Profile → App →
Diagnostics → Copy report**. The report contains device model, Android
version, ABI, RAM, device tier and the tail of the shared error log
(`databases/fkss_bootstrap_error.log`, written by both the Dart bootstrap and
the native crash trap `FkssApplication`). Stack traces only — no user data.
Ask them to paste it into any messaging channel.

Native engine crashes (SIGSEGV in `libflutter.so`) cannot be caught in Java
and still need the one-time `adb logcat` capture on the device.

## 6. Pre-publish checklist

- [ ] Version bumped in **both** `pubspec.yaml` and `lib/utils/config.dart`
      (they must match — `test/version_sync_test.dart` fails the build otherwise)
- [ ] Built via `scripts\build-release.ps1` (tests green)
- [ ] Universal APK uploaded and `latest_version`/`latest_build` updated
- [ ] `release-manifest.json` archived with the release notes
- [ ] Installed on one 32-bit phone and one modern phone; both update cleanly
- [ ] Diagnostics screen shows "No recorded errors" after first launch
