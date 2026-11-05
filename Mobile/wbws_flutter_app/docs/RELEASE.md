# Android release guide

**Maintained procedure, reviewed 2026-10-28 against `7e28157`.** Use the owner-controlled release script, preserve the installed signing identity and test the actual APK on representative phones. Development-main merge, a debug build, publication, installer launch and installation are distinct events. Follow [development-first release policy](../../../docs/PRODUCTION_WORKFLOW.md); production publication requires separate owner authorization.

## Source checkpoint: 1.7.4+41, not proof of publication

Run the [class-day attendance acceptance checklist](ATTENDANCE_CLASS_DAY_1_7_4.md) on development before production approval. The [Profile App updates contract](PROFILE_APP_UPDATES.md) describes the delivered updater and its remaining phone-acceptance gates. Source metadata does not establish the offered APK, installed fleet or minimum-build policy. This documentation change reserves no release number and changes no signing or update policy.

## 1. Build a release

Review the [Android compatibility gates and pending device matrix](ANDROID_COMPATIBILITY.md). The release script now requires Python 3.11+ and Android SDK build-tools 36.0.0 (`aapt`, `zipalign`, `apksigner`), with `ANDROID_SDK_ROOT` or `ANDROID_HOME` configured. Missing tooling or failed artifact checks stop the build procedure; do not bypass the gate or change signing identity to obtain a pass.

The app's reviewed [native SDK constraints](ANDROID_COMPATIBILITY.md#native-sdk-correction) address observed 16 KiB alignment failures in the existing bundled barcode/camera dependencies. Do not remove them, switch to downloaded models or broaden upgrades to bypass a failed gate. Dart/toolchain/SDK/NDK/signing settings remain otherwise unchanged.

From `Mobile/wbws_flutter_app`, with the pinned toolchain and reviewed lockfile:

```powershell
flutter --version
flutter pub get --enforce-lockfile
powershell -ExecutionPolicy Bypass -File scripts\build-release.ps1
# Optional: also produce ARM32/ARM64 APKs with the same assigned build code.
powershell -ExecutionPolicy Bypass -File scripts\build-release.ps1 -SplitPerAbi
```

The script runs Flutter tests unless `-SkipTests` is supplied. Do **not** use that switch for an accepted release. It runs `flutter pub get --enforce-lockfile`; review the working tree afterward and stop if the committed lockfile or dependencies changed unexpectedly. Do not upgrade packages to obtain a passing build.

**Per-ABI build identity:** Flutter 3.44.9's default `--split-per-abi` implementation adds ABI-specific version-code offsets. FKSS's release-only split variant callback reapplies the existing `flutter.versionCode`, after Flutter's callback, so the assigned universal/per-device build remains the same. This preserves the server's exact matching and ordinary updater ordering instead of normalizing offsets or weakening checks. The owner script verifies actual version codes before copying artifacts; Android CI additionally checks all three reports and sends the genuine artifacts through the real PHP inspector in an isolated fixture. Any mismatch must fail the candidate. Previously installed offset-code APKs are not assumed to exist or to have been migrated; inspect an actual installed version before accepting that upgrade path.

The script's artifact layout under `dist\<version>+<build>\` is:

| Artifact | Purpose |
|---|---|
| `fkss-<version>+<build>-universal.apk` | Required fallback containing the build's supported ABI targets |
| `fkss-<version>+<build>-arm64-v8a.apk` | Optional 64-bit artifact |
| `fkss-<version>+<build>-armeabi-v7a.apk` | Optional 32-bit artifact |
| `release-manifest.json` | Recorded toolchain, artifact sizes, SHA-256 values and compatibility report filenames |
| `<artifact>.compatibility.json` | Actual SDK/ABI/ELF, 64-bit PT_LOAD and ZIP alignment, signature-tool output and artifact hash; not phone acceptance |

A universal APK does not guarantee support for every Android version, GPU or device. Verify the effective minimum SDK, ABI and device compatibility of the built artifact. `flutter run --release` may target only the connected device's architecture; do not distribute that artifact as the fleet fallback. The prior architecture-related launch-crash incident is the reason to retain universal and representative-device checks. A plain `flutter build apk` does not run tests.

## 2. Host the approved artifact

Perform this first on the authorized development target; use production only after separate approval. APKs and keys never belong in Git. The server-private `.fkss_app_release.php` supports these path keys:

```php
'apk_path'       => '/home/<account>/fkss_releases/fkss.apk',
'apk_arm64_path' => '/home/<account>/fkss_releases/fkss-arm64.apk',
'apk_arm32_path' => '/home/<account>/fkss_releases/fkss-arm32.apk',
```

The paths are placeholders, not verified host locations. Confirm the target's private configuration, ownership, quota and fallback availability before publishing. Keep the universal artifact available for unknown/older client ABI requests; split download sizes vary by build.

The super-admin release manager (`admin/api_app_release.php`) supports upload/preflight/chunk/status/finalization actions and writes release metadata. Its resumable **browser upload** is different from the app's **download**, whose retries restart from zero. Upload validation now requires matching ELF identity headers for both Flutter core libraries in each required ABI: universal requires ARM32, ARM64 and x86_64; per-device slots require their named ABI. This is not backend signature/minSDK or full-library verification. Existing hosted files are not automatically revalidated; run the actual-artifact gate on them before authorized republication. See [synthetic upload acceptance](../../../tests/e2e/README.md).

Set `latest_version` and `latest_build` to the exact built APK's identity, corroborated by the version-sync test and artifact inspection. Advertising a higher build than the hosted APK can create repeated update prompts after installation. Preserve the publication manifest and compare artifact SHA-256/size with the served selected artifact.

`min_build` and `force_update` affect enforcement and are owner-approved release-policy controls, not routine upload settings. Do not raise them to test a banner on production, temporarily reset them as an undocumented workaround, or advertise an artifact that has not passed acceptance. Banner text, release notes and tile configuration also need target-specific review.

### Verify after authorized hosting

1. Record target, accepted source SHA, APK version/build, certificate fingerprint and manifest hashes privately as appropriate.
2. Confirm the selected `/api/v1/app/config` metadata and `/api/v1/app/download` artifact agree, including ABI/universal selection and digest/length. Test only the authorized environment.
3. Check through **Profile > App > App updates**. Status must distinguish checking, failed refresh, available/current, downloading, verified and installer opened.
4. Install the actual upgrade on representative supported 32-bit and modern phones, without deleting unsent work. Reopen and independently confirm installed identity and essential flows.
5. Record checks, failures and owner acceptance; a clean diagnostic screen alone is not acceptance.

## 3. Signing and key custody

**VERIFIED — source configuration:** `android/app/build.gradle` selects the release keystore when local `android/key.properties` exists, otherwise debug signing. **DEPENDENCY NOT VERIFIED:** the current hosted APK certificate, installed-phone certificates and key custody were not inspected. A debug key on a different machine is not necessarily the same key.

Before publishing, use the owner's Android SDK tooling to inspect certificates on the actual APK and compare with an installed sample/the previous accepted artifact. Keep the signing key, alias and passwords under owner custody with secure independent backups. Never commit or paste them into support reports.

For an owner-approved new-key setup only, the existing Gradle property layout is:

```properties
storePassword=<private value>
keyPassword=<private value>
keyAlias=<owner-selected alias>
storeFile=<private keystore path relative to the Gradle app project>
```

Creating this file changes the signing selection. Do not create it merely to remove a warning. Certificate continuity is required for ordinary in-place updates; this project does not establish an automatic signing-lineage migration. A certificate mismatch can require a coordinated reinstall, and uninstalling removes the local database, including unsent work. Resolve synchronization/recovery, device acceptance and rollout/rollback before any signer change. Losing a key cannot be repaired by rebuilding with an unrelated key.

## 4. Renderer or ABI failures

Do not change renderers based on symptoms alone. On an authorized test device, capture `adb logcat -b crash -d`, reviewing/redacting sensitive output before private sharing.

- `UnsatisfiedLinkError` may indicate incompatible/missing ABI libraries: inspect the installed artifact and universal fallback.
- SIGSEGV inside `libflutter.so` with Impeller frames may require a device-specific renderer investigation. A Skia fallback via `io.flutter.embedding.android.EnableImpeller=false` is a separately reviewed source change, not part of this guide's documentation update. Reproduce, change on a branch, test the same affected hardware and the wider supported matrix before release.
- A native signal crash cannot be assumed to appear in Dart/Java exception capture. Preserve the native evidence rather than calling a blank in-app report a pass.

## 5. Diagnostics and private support

**Profile > App > Diagnostics > Copy report** includes device/app context and a bounded tail of `databases/fkss_bootstrap_error.log`, shared by Dart bootstrap and native exception capture. The tail is **not guaranteed free of personal or sensitive data**. Review/redact it and send only through an approved private channel to an authorized administrator. Never request that staff paste raw reports into public issues or arbitrary messaging channels.

See [account/device instructions](../../../docs/user_manual/ACCOUNT_AND_DEVICE.md). Captured evidence is not proof that every crash was recorded or that a reported cause is correct.

## 6. Pre-publication checklist

- [ ] Accepted development SHA, fresh applicable checks, observed development target and owner acceptance recorded.
- [ ] Separate production authorization recorded if production is the target.
- [ ] `pubspec.yaml` and `lib/utils/config.dart` agree; artifact identity independently checked.
- [ ] Build script ran without skipped tests; dependency/lockfile changes reviewed.
- [ ] Actual signing certificate matches the intended upgrade path; keys backed up privately.
- [ ] Universal and selected split artifacts, size and SHA-256 match the publication manifest.
- [ ] Update metadata and minimum/force policy reviewed independently of upload success.
- [ ] Optional/required update, denial/cancellation, low storage, interrupted network, restart-from-zero, ABI fallback and re-verification tested on supported phones.
- [ ] Installer launch distinguished from successful installation; installed version independently confirmed.
- [ ] App lock, same-account offline recovery and unsent-work protection retained.
- [ ] Accessibility/large-text/TalkBack and relevant class-day acceptance recorded; open failures not hidden.
- [ ] Publication/rollback instructions and evidence retained. Documentation, CI and a debug APK alone do not satisfy these gates.
