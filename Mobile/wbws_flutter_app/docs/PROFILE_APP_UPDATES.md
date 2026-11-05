# Permanent App updates access — first delivery

## Entry and dismissal

`Profile > App > App updates` is always present outside profile-data/account-editing guards. The optional banner and this row open the same detail route. Rapid repeated opens are coalesced. X hides only that release's banner for the lifetime of its widget; a later release can appear. Same-release metadata refreshes do not resurrect it.

The row and detail page observe the application-lifetime update service. Progress does not rebuild the whole Profile form or shell. Downloads are single-flight; leaving/reopening the route attaches to the same operation. A download is not automatically canceled by Back.

## Status truthfulness

- Initial, checking, successfully current, available and failed-check states are distinct.
- The last-successful timestamp is in-memory and changes only after a successful metadata response. Failure retains the last-known release and explicitly marks refresh failure.
- Full release metadata is **not** durably cached in this delivery. A cold offline launch must not imply a previous successful version check.
- Download, verification, ready-to-install and installer-opened are separate. There is no automatic claim that installation succeeded.
- Installer permission/settings failures do not discard a verified APK. Permission is checked again on the next Install action.

## Download safety and limits

One operation owns the APK file. The service closes network/file handles, limits header/stream inactivity waits, verifies actual length when known, and streams SHA-256 verification without loading the APK into memory. It requires a valid served-file or selected-artifact digest. Existing ABI/universal fallback precedence is preserved.

A release change during transfer keeps the original transfer identity visible and rejects old bytes at the next stream/verification boundary; it does not offer the stale artifact for installation.

Installation revalidates the file and refuses a file associated with a different current release. No verification bypass is offered. Displayed errors are controlled messages, not raw URLs, paths or exceptions.

**Retries restart from zero. No durable pause/resume, automatic bounded retry, process-death recovery, guaranteed background transfer or silent install is delivered here.** These require the separately scoped identity-bound client/server transport work. The UI explicitly explains the restart limitation.

## Release policy integration

Successful manual checks notify the existing shell's feature/release handling. The root listens only for changes in the forced-update decision, not progress events. Release observers run after the temporary background-drain hold is released. Mandatory screens use `PopScope` and no back action. Existing app-lock precedence is unchanged.

No backend/API/database/dependency/permission/signing changes, role-navigation redesign, update severity schema, unread badge or automatic download policy are introduced.

## Automated coverage

`test/app_update_workflow_test.dart` and `test/app_updates_ui_test.dart` cover status freshness and failure, coalesced checks/downloads, release identity, required-screen back behavior, dismissal/re-entry, permission recheck/failure, corruption/truncation/connection closure, served-artifact fallback, Android tap-target labeling and 200% text on a small screen.

Run with the existing pinned toolchain and committed lockfile:

```sh
flutter pub get --enforce-lockfile
flutter test --reporter expanded
```

Do not change SDK/dependency versions to make this feature pass.

## Owner phone acceptance before release

1. Publish an optional development release; dismiss X and reach it through Profile without restarting.
2. Verify Profile access if account-profile loading fails.
3. Start a download, navigate away and return; ensure only one transfer exists.
4. Interrupt Wi-Fi/mobile data; verify the actionable failure and explicit restart behavior.
5. Download successfully, deny permission or cancel the installer, return and install again without redownloading a valid file.
6. Check required-version enforcement through manual refresh, Back and Android resume; verify app lock and offline queued work remain protected.
7. Test TalkBack, large fonts, low storage, cache removal, ABI fallback and the owner's real signing identity.

CI debug APK compilation is not evidence of production signing or a real phone installation. Follow the existing owner-controlled version/build increment, signing and publication procedure before publishing a release. This patch does not reserve or advertise a new public release number.

Development merge does not authorize production promotion. Resumable transfer remains follow-up work.
