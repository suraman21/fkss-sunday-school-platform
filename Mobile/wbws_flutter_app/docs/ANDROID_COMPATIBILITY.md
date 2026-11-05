# Android compatibility and acceptance

## Scope and limits

Retain Flutter and existing Android integration. No iOS implementation, Kotlin rewrite, dependency downgrade, new permission, signing-key change, renderer workaround or Android SDK override is part of this hardening. “Universal” describes the APK's ABI coverage, **not support for every Android phone**. The reported first-install rejection of 1.6.10+36 on a Galaxy J7 Prime 2 / Android 9 remains **UNKNOWN**: its exact APK and device-supported ABI list were not inspected. A source defect or synthetic regression is not proof of that incident's cause.

The pinned CI toolchain is Flutter 3.44.9 / JDK 17 / AGP 8.11.1 / Gradle 8.14.3, with the existing NDK 26.1.10909125. Flutter's delegated source minimum is API 24 (Android 7); delegated target/compile SDK is 36. The release gate reads the actual merged APK and rejects a minimum above 24 or target below 36. It does not lower the minimum or upgrade plugins to force older support. Android 6 / API 23 and older are outside this current-toolchain scope. A passing artifact gate is still not a tested-device support certificate.

Android 17 / API 37 is the latest stable major platform at this review date, 2026-10-28; target/compile 36 does not establish successful API 37 execution. The official announcement distinguishes new target37-specific behaviors from all-app changes. [1](https://android-developers.googleblog.com/2026/06/Android-17.html)

## Native SDK correction

A genuine release APK failed the 64-bit 16 KiB LOAD-alignment gate. Independent inspection of the declared Google Maven AARs confirmed 4 KiB-aligned `libbarhopper_v3.so` in bundled ML Kit barcode-scanning 17.2.0 and `libimage_processing_util_jni.so` in CameraX core 1.3.3.

The app now constrains these existing native dependencies to bundled barcode-scanning **17.3.0** and the aligned CameraX core/camera2/lifecycle **1.4.2** family. These are targeted native SDK upgrades, not a Dart plugin/toolchain upgrade or an unchanged-dependencies claim. The inspected replacement AARs retain ARM32, have 16 KiB-aligned 64-bit LOAD segments with safe static RELRO page ranges, and declare minimum API 21, below the app's existing API 24 floor. The genuine merged APK remains the authority for final SDK/permission/native properties.

Keep the bundled model: scanning must not acquire a new Play Services model-download requirement as an alignment workaround. Dart `mobile_scanner` 5.2.3, the lockfile, SDK/NDK pins, permissions, signing selection and assigned build are unchanged. Android CI records dependency resolution and verifies the actual artifact set. QR recognition, camera lifecycle/rotation, denied permissions, offline scanning and low-memory behavior still require device acceptance after these native updates.

References: [bundled-model versioning](https://developers.google.com/ml-kit/tips/installation-paths), [CameraX release history](https://developer.android.com/jetpack/androidx/releases/camera), and [Android page-size guidance](https://developer.android.com/guide/practices/page-sizes). Do not expand this into unrelated dependency upgrades or suppress the artifact gate.

## Artifact gates

From `Mobile/wbws_flutter_app`, with Python3.11+, the pinned Flutter toolchain and Android SDK build-tools36.0.0 installed:

```sh
flutter pub get --enforce-lockfile
flutter test
flutter build apk --release --target-platform android-arm,android-arm64,android-x64
python3 scripts/verify_android_apk.py build/app/outputs/flutter-apk/app-release.apk --report build/android-compatibility.json
```

Set `ANDROID_SDK_ROOT` or `ANDROID_HOME`, or provide `--sdk-root`. For a per-ABI APK use `--abis armeabi-v7a` or `--abis arm64-v8a`. FKSS's release-only split variant callback reapplies the existing assigned base build code after Flutter's default ABI-offset callback. Exact universal/per-device build matching remains mandatory. The owner script checks genuine output codes, and Android CI checks the full release set and the real PHP publication inspector. Source configuration alone is not artifact evidence; inspect fresh candidate CI results. See [release instructions](RELEASE.md). Add `--version <expected-name> --version-code <expected-code>` when independently checking an owner's build. The PowerShell release script supplies these from pubspec. Do not use an unrelated keystore to satisfy the gate.

| Gate | Checks | Does not establish |
|---|---|---|
| Shared-host publication | Existing package/build rules plus unique required core-library entry paths and little-endian ELF class/machine/type/version headers; bounded prefix decompression | Native library integrity beyond prefixes, signature authenticity, merged minSDK, dependency closure, successful install |
| Universal publication slot | `libflutter.so` and `libapp.so` for ARM32, ARM64 and x86_64, matching current default fat-build coverage | Compatibility with arbitrary older OS/CPU/GPU |
| ARM32/ARM64 publication slot | Named ABI core-library identity plus existing universal identity/build matching | Exclusivity to that ABI, signer continuity or performance |
| Python actual-artifact gate | Duplicate ZIP entries/CRC, actual package/version/SDK metadata, both Flutter core libraries for every bundled native ABI (not plugin-only architectures), every bundled `.so` ELF identity/program-header table, 64-bit PT_LOAD bounds/alignment/congruence and RELRO page-protection overlap, SDK zipalign/signature verification, SHA-256 | Full ELF semantics, runtime native dependency resolution/page-size behavior, owner-trusted certificate continuity, installation, feature behavior or frame/memory benchmarks |
| Existing `Android debug APK` CI check | Retains debug manifest/dex checks; additionally runs the owner PowerShell procedure with universal and ARM splits, SDK gates and real PHP inspection; checks same build identity, expected complete ABI sets and report/manifest/APK hashes | Windows execution, owner release signing, production publication, emulator/phone execution |

The public SDK signature verification result means the tool verified the APK, not that the signer matches the owner's trusted certificate. Compare its certificate fingerprints with an independently trusted prior release. CI retains its existing debug-key fallback and never uses the owner's signing key. Do not distribute its build as an owner-approved release.

An incorrectly labeled upload is rejected before atomic replacement; regression tests preserve the prior APK and release policy on refusal. Existing files already on disk are not automatically revalidated or deleted. An operator copying files directly can bypass the upload gate: run the actual-artifact verifier before every authorized publication. The backend intentionally does not require Android SDK executables on shared hosting.

Android's official 16 KiB guidance requires checking ELF/ZIP alignment and RELRO page protection, then testing in an actual 16 KiB environment. The verifier checks page-rounded RELRO against writable LOAD ranges, permitting isolated RELRO segments rather than rejecting every unaligned RELRO end. Static header checks still do not prove complete ELF validity or runtime behavior. [Page-size guidance](https://developer.android.com/guide/practices/page-sizes)

## CI evidence storage

The same backend workflow runs the actual debug, universal/ARM release, SDK/native/signature and PHP inspection gates in both repositories. After successful inspection, `tests/e2e/android_release_artifacts.py --summary` writes the complete release-set result, manifest, three SDK compatibility reports and native dependency records to the Actions step summary and structured job log. Missing/malformed evidence, summary-write failure or an oversized summary fails the check; reports are not silently truncated. This does not change any APK validation or owner-signing requirement.

Under the owner's approved no-billing policy, public development additionally retains downloadable CI reports and test-signed APKs for seven days. Private production does not upload those duplicate archives: its raw CI APK bytes are discarded with the runner after inspection, not transferred to the public repository. Development APKs are not retained copies of private-runner bytes. Private reports follow the repository's Actions log/summary retention and are not a permanent binary archive. Unknown/non-public event visibility does not enable artifact uploads.

This is an explicit evidence-retention policy, not an ignored upload error or a skipped build/native gate. A green check still does not prove owner signer continuity, publication, installation or phone runtime acceptance. Do not make production public, enable paid storage, publish CI builds or remove verification to work around a storage limit.

## Capability handling and memory ceilings

The existing read-only `fkss.app/device` channel reports OS-supported ABIs, total RAM, low-RAM flag, API/release and model/manufacturer. This is the OS ABI list, not an inference from a64-bit CPU marketing specification. Empty/invalid fields remain unknown; no synthetic ABI/model strings are invented. A missing channel, native exception or non-map response preserves the last successful snapshot; before a successful probe, the service starts at MID with null information.

| Native capability | Tier | Image cache item/byte ceilings |
|---|---|---|
| OS low-RAM flag, regardless of reported capacity | LOW |250/32MiB |
| Known RAM at most2048MiB | LOW |250/32MiB |
|2049–4096MiB, or unknown/nonpositive RAM | MID |500/64MiB after a successful map response |
| Above4096MiB | HIGH | Original Flutter/application cache settings captured before the first adjustment |

Repeat successful boots restore the appropriate budget, including LOW→MID→HIGH. These are image-cache ceilings, **not total app memory limits or measured improvements**. They do not prove absence of out-of-memory kills, smooth animations or fast startup. No speculative graphics/animation/prefetch optimization is included.

## Required execution matrix — pending until evidence is recorded

All rows below are acceptance targets, not completed device tests. Record exact sourceSHA, APK SHA256/certificate, device/OS/ABI/page size/RAM, install result and observed feature/performance outcomes. Use synthetic school records and existing test-environment injection only; a default main build's API configuration is not permission to test production.

| Target | Hardware/OS coverage | Required observations |
|---|---|---|
| Oldest supported | API24/25 ARM32,1–2GiB/Go where available | First install, cold launch, low cache, low storage, camera/media, offline restart |
| Reported phone | Actual GalaxyJ7Prime2 Android9/API28, actual OS ABI verified locally | Install exact owner-signed candidate; record native install error if rejected; compare build36 only if available |
| Intermediate | API29/30, ARM32 and ARM64,2–4GiB | Offline account ownership, local auth, media/gallery/permission denials, update cancellation/resume |
| Platform transitions | API31/32 and33/34 ARM64 | PendingIntent/alarms, notifications granted/denied, background restrictions, lifecycle and update install permission denial |
| Modern | API35/36 ARM64,4KiB and16KiB environments, high RAM | Actual16KiB install/launch/native feature use; background/foreground transitions, memory pressure |
| Latest stable | Android17/API37 ARM64, representative modern hardware | First install plus in-place upgrade with trusted signer; native feature execution and all-app behavior changes; adaptive/large-text checks |
| Optional supported desktop/emulator ABI | x86_64 actual artifact and suitable API24+ emulator | Install/launch/native feature smoke; not a substitute for ARM phone acceptance |

For each applicable row: login/logout/app lock and same-account offline recovery; unsent-work preservation/sync; core attendance and class-day flow; long lists/images/lyrics; QR/camera/media/audio/biometrics where present; notification/background scheduling; large text/TalkBack/rotation; update download/checksum/installer denial/cancellation and independently confirmed installed version. Unsupported optional hardware must fail gracefully, not weaken authentication or silently grant permission. An upgrade test must not uninstall or wipe unsent data.

Measure release-mode cold/warm start, Flutter frame timings/jank, app/native memory peaks, image-heavy scrolling and QR/media operations under representative memory pressure. Record device, workload, sample count and failures before proposing optimizations. No benchmark or “all phones” performance guarantee follows from mocked channel tests.

## Acceptance record

Keep an evidence ledger with: check, exact candidateSHA, artifact hash, environment/device, command/workload, observed result, classification (`VERIFIED`, `INFERENCE`, `UNKNOWN`, `DEPENDENCY NOT VERIFIED`), unresolved failure, owner and date. Preserve failed evidence. A failed or missing release/CI/physical gate is not passed by documentation or a renamed status. Development main, development-server cron deployment, owner phone acceptance and separately authorized production release remain distinct events.
