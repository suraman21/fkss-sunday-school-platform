# App updates

[Manual index](README.md) · [Account and device settings](ACCOUNT_AND_DEVICE.md) · [Sync and recovery](SYNC_AND_RECOVERY.md)

**Audience:** Android app users. **Entry point:** **Profile → App updates**, or **Update** in the optional banner. **Baseline:** `7209278`, source checkpoint 1.7.4+41. **Verification:** UI/service/native call path reviewed; no new signed-phone installation is claimed.

## 1. Before updating

- Review unsent work in Sync Center. Do not uninstall the app, clear its data or discard private work to solve an installation problem.
- Have a usable connection, sufficient free storage and enough battery. Mobile data charges may apply.
- Only install an administrator-approved FKSS release. An APK's existence or a download progress indicator is not evidence that it has passed verification or has been installed.
- Keep FKSS open while downloading. This version does **not** guarantee saved-progress resume, automatic retry, background completion or continuation after process death.

## 2. Check for a release

1. Open **Profile → App updates**. This entry remains available even if profile data cannot load or the optional reminder was dismissed.
2. Select **Check for updates** or **Check again** when enabled.
3. Read **Installed**, any **Available** version/build and **Last successful check**. The latest successful check is different from the latest failed attempt.
4. Review **What’s new** when release notes are available. If a check fails, earlier displayed information is not a fresh confirmation.

### Status reference

| Status | Meaning and next action |
|---|---|
| **Check for FKSS updates** | No successful check has completed; request one when connected |
| **Checking for updates…** | A check is running; wait rather than repeatedly selecting the control |
| **Couldn’t check for updates** | The check failed; inspect connection and try again |
| **Version information unavailable** | A successful response did not supply usable version information; contact the administrator if it persists |
| **You’re up to date** | The current successful information does not offer a newer update; not a guarantee about future releases |
| **Update available** | A newer release is offered; review notes and download availability |
| **Update required to continue** | The app's required-update gate blocks normal navigation; complete the approved update or contact the administrator |
| Downloading / verifying status | One shared operation is in progress; returning to the page observes that operation |
| **Ready to install** | The downloaded file passed the required verification; installation has not yet happened |

The optional banner's **Dismiss update reminder** icon hides that release's reminder for the current banner session. It does not delete the update or remove Profile's entry point. A new release can show a new reminder. A required update is different: its screen cannot be left through normal Back navigation.

## 3. Download

1. Select **Download update**. If recovering from a failed transfer, the control is **Download again**.
2. Watch the status and received/expected size. The progress display may be indeterminate if the total is unknown or while verification runs.
3. Leave the app open. Navigating back to the same update page observes the shared operation rather than intentionally starting a second transfer. This is not durable resume after the process closes.
4. Wait for **Ready to install**. The service checks SHA-256 and the expected size when known. Missing verification information, incomplete/unreadable files and mismatches are failures, not installable success.

If the server offers a different release during the transfer, the previous artifact is no longer accepted as the current update. The service stops/rejects the stale transfer; check again and download the latest release. Do not rename an old APK or use a partially downloaded file.

**Retry behavior:** failed mobile downloads restart from zero. This is distinct from the web administrator's chunked upload recovery. There is no user-facing pause/resume control or promise of a queued automatic retry in this screen.

## 4. Install and verify

1. Select **Install update** when the file is ready. The service rechecks the file before requesting Android installation.
2. If Android opens install-permission settings, allow installation from FKSS only if this is the approved release. Return to FKSS and select **Install update** again.
3. Finish the system installer's confirmation. Android may close FKSS. Opening the installer does **not** establish that replacement succeeded.
4. Reopen FKSS and check **Profile → Version** or the update screen's **Installed** value against the approved build. Follow the administrator's acceptance instructions.

If installation was canceled, a still-valid verified file can remain ready in the same operation; select **Install update** again when appropriate. Process restarts, changed release metadata or a missing file can require a new download. Never uninstall merely because Android reports a signature/package conflict: the administrator must check signing compatibility and the risk to unsent work first.

## 5. Control reference

| Control | When available | Action / effect |
|---|---|---|
| **App updates** row | Profile's App section, independent of profile-data success | Opens the shared detail route; rapid taps are guarded against duplicate routes |
| **Update** in the banner | Optional update offered | Opens the same detail route |
| **Dismiss update reminder** icon | Optional banner visible | Dismisses that banner reminder; Profile access remains |
| **Check again** | Not downloading, opening installation or already checking | Refreshes server version/configuration information |
| **Check for updates** | No offered update and not currently checking | Requests a check; does not download/install by itself |
| **Download update** / **Download again** | A compatible offered artifact is downloadable and the operation is not busy/checking | Starts or restarts the transfer; retries do not resume saved bytes |
| **Install update** | Verified current file is ready and installer opening is not busy | Revalidates the file, checks install permission and requests Android installation |
| Back navigation | Nonrequired detail screen | Leaves the page; it is not a cancel-transfer action. Required-update navigation is blocked |

Disabled controls prevent conflicting operations; do not interpret them as a completed action.

## 6. Troubleshooting

| Message or symptom | Safe response |
|---|---|
| Connection interrupted | Reconnect and use **Download again**; expect a full restart |
| Cannot save / insufficient storage | Free safe space without deleting FKSS private data, then retry |
| Verification information missing | Ask the administrator to verify release metadata/artifact publication; do not bypass verification |
| Incomplete, wrong-size or unverifiable file | Download again; persistent failures need administrator investigation |
| No compatible Android update file | Ask for a release compatible with the device; repeated checks cannot create an artifact |
| Install settings cannot open / permission not granted | Review Android's install permission for FKSS; return and retry the install action |
| Android refuses installation | Preserve offline work; report device/build and installer message privately so signing/package compatibility can be checked |
| Required update cannot complete | Contact the administrator; do not clear data or bypass the gate |

## 7. Source and verification reference

- [app_updates_tile.dart](../../Mobile/wbws_flutter_app/lib/screens/update/app_updates_tile.dart): persistent Profile entry.
- [update_banner.dart](../../Mobile/wbws_flutter_app/lib/screens/update/update_banner.dart): optional reminder/update/dismiss behavior.
- [update_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/update/update_screen.dart): shared route, status/controls, required gate and progress.
- [app_update_service.dart](../../Mobile/wbws_flutter_app/lib/services/app_update_service.dart): `check`, `downloadApk`, `_download`, `_verifyFile`, `_requireCurrentDownload`, `installReadyApk` and native calls.
- [MainActivity.kt](../../Mobile/wbws_flutter_app/android/app/src/main/kotlin/com/arkeonethiopia/fkss/MainActivity.kt): Android install permission/settings/installer bridge.

The server's release policy, uploaded artifact, device signer, actual installation and accessibility on a real phone need separate evidence. See [release operations](../OPERATIONS.md#5-mobile-release) and [manual coverage](COVERAGE.md).
