# Account and device settings

[Manual index](README.md) · [Sync and recovery](SYNC_AND_RECOVERY.md) · [App updates](APP_UPDATES.md)

**Audience:** staff using the mobile Profile screen. **Baseline:** `7209278`, source checkpoint 1.7.4+41. **Verification:** source-traced controls and service calls; no new phone acceptance is claimed. This chapter does not describe the separate web account page.

## 1. Open Profile and check your account

1. In the mobile app, select **Profile** in your role's navigation.
2. Check your full name, username and role before editing or transmitting work.
3. If the screen says it is offline, treat the displayed information as saved data, not a fresh server confirmation.
4. When connected, select the **Refresh profile** icon or pull down to refresh. If there is no saved profile and loading fails, inspect the error and use **Retry** when offered.

Account details show the role and active/inactive status; account-created and last-login values appear when supplied by the server. These are read-only. Self-service profile edits do not assign a new role, activate an account or change class permissions.

Editing requires a loaded profile, an online connection and no conflicting loading/mutation operation. Disabled controls are expected while those conditions are unmet. **App updates**, Sync Center, local app-lock settings, diagnostics and logout are outside the profile-data success block, so profile retrieval failure does not remove those entry points.

## 2. Change your full name, email or username

1. Under **Account information**, select the edit icon beside **Full name**, **Email** or **Username**.
2. Enter the new value. For email and username changes, also enter **Current password**. The full-name form does not request it.
3. Use **Show password** / **Hide password** only when it is safe for the screen to be visible.
4. Select **Save** once and wait for the result. **Cancel** closes the form without submitting it; Cancel is disabled while the request is running.
5. Confirm the saved value. If a conflict reloads newer server values, review your still-open draft and save again only if your change is still correct.

### Field rules

| Field | Implemented client rules | Important effects |
|---|---|---|
| Full name | Required; at most 100 Unicode characters; unsupported control characters rejected | Changes your staff account's display name, not a member's name |
| Email | Empty is allowed; otherwise a valid email of at most 100 characters | Normalized to lowercase; empty is submitted as no email; current password required |
| Username | 3–50 lowercase letters, numbers, dots or underscores; begin/end with a letter or number; no consecutive dot/underscore punctuation | Trimmed/lowercased; must pass server uniqueness/validation; current password required |

The client can show an optimistic value before the response. **That is not a server save confirmation.** On failure the service restores the prior canonical profile; on a version conflict it reloads the server profile. The password field is cleared before the request, so a retry may require reentry. After a successful account change, session claims may need reconciliation; a message that the profile was saved but session details still need refreshing must not be treated as an unsaved edit or ignored.

**Recovery:** correct a field-specific error rather than submitting repeatedly. For a duplicate username/email, choose an allowed value. If connectivity disappears, reconnect and refresh before deciding whether to repeat a save. These edits are online-only; they are not attendance/grade outbox drafts.

## 3. Change your password

**Prerequisites:** online access, your current password and a loaded editable profile.

1. In **Security**, select **Change password**.
2. Enter **Current password**, **New password** and **Confirm new password**.
3. Use a new password different from the current password, at least 12 Unicode characters and no more than 72 UTF-8 bytes. Non-ASCII characters can take several bytes each.
4. Select **Change Password**. The fields are cleared before the request, so you may need to reenter them after a failure.
5. After success, sign in again with the new password. If the app shows a recovery screen, follow [same-account recovery](SYNC_AND_RECOVERY.md#4-recover-after-a-session-ends); do not discard unsent work just to reach the login screen.

The credential service updates the password and revokes refresh sessions. An accepted password change and successful local sign-out are separate operations: if local cleanup cannot finish safely, follow the displayed recovery/protection message rather than assume the password was unchanged. Never send a password to an administrator in a screenshot or support report.

## 4. Upload, replace or remove your profile image

**Prerequisites:** online connection and no other profile mutation. The image belongs to the signed-in staff account.

1. Select **Upload**, or **Replace** if an image is already set.
2. Select an image from the phone's gallery. Canceling the picker makes no upload request.
3. Review the preview. Select **Upload** to submit it or **Cancel** to keep the current image.
4. Wait for the result and refresh if necessary. Do not treat the picker preview as a completed upload.

The picker requests a reduced image up to 2048 pixels on each side. The selected bytes are validated: no more than 4 MiB, dimensions at least 64 × 64, neither side above 4096 pixels and no more than 12 megapixels. The UI calls the size “4 MB.” Decode/read failures are reported rather than accepted as images.

To remove an existing image, select **Remove**, review **Remove profile image?**, then select **Remove** again. **Cancel** keeps it. The expected replacement is your initials. A disabled final control after connectivity loss is not a completed request.

**Recovery:** choose a supported readable image that meets the limits. A server storage/validation/version failure must be resolved before assuming the image was saved. Profile image requests use the authenticated owner and profile version; the user does not choose another account's image owner.

## 5. Configure App Lock

App Lock protects access to this phone's app with a local passcode. It is **not** your account password, a server logout or a substitute for the phone's own screen lock.

| Control | Availability and action | Expected result / recovery |
|---|---|---|
| **App Lock** switch: on | Available in **Privacy & Security**; enter and confirm a 4–8 digit passcode | Matching valid values configure the local lock; mismatches/validation errors leave a message |
| **App Lock** switch: off | When configured; enter the current passcode | Successful verification disables it; a wrong passcode does not |
| **Auto-lock** | Shown when configured; select an interval | Options: **Immediately**, **After 1 minute**, **After 5 minutes**, **After 1 hour**, **After 1 day** |
| **Unlock with fingerprint** | Shown when configured; enabling asks you to confirm the passcode | Biometric unlock still depends on device support and enrollment; the switch does not create a fingerprint on the phone |
| **Change passcode** | Enter current passcode, new passcode and confirmation | A valid matching new passcode replaces the old one; the account password is unchanged |
| **Lock now** | Shown when configured | Requests the local lock immediately; does not transmit pending work or sign out |
| **CANCEL** / **OK** in passcode dialogs | Cancel or submit that dialog's input | Cancel/empty input aborts that step; completing a first step does not complete the whole setup/change sequence |

Keep your passcode private. Do not clear app storage, reinstall or choose a destructive sign-out as a routine response to a lock problem: unsent private work can be lost. Ask the administrator for guidance if you cannot unlock and have work that must be preserved. Same-account sign-in is not a promise to bypass the retained local passcode.

## 6. Sync, updates and application information

| Control or field | Meaning |
|---|---|
| **Sync Center** | Opens the saved-work inventory and recovery controls; see [Sync and recovery](SYNC_AND_RECOVERY.md) |
| **Sync Now** | Requests a sync pass; disabled while syncing. Read its result and unresolved counts; selecting it does not itself prove acceptance |
| **App updates** | Always-visible entry to the shared update operation; see [App updates](APP_UPDATES.md) |
| **Version** | Compiled app version/build displayed by the app; compare it after an installation |
| **Server** | Configured API address for this build; read-only, not a server-switch control |
| **Logout** | Opens the choice-based sign-out flow; review the offline-work warning before continuing |

“Everything is up to date” is derived from the local inventory available to this app. It is not proof that another phone, another user or the entire school has no pending work.

## 7. Use diagnostics safely

1. In **App**, select **Diagnostics**.
2. Review the device tier/ABI information and the available error-log tail. **No recorded errors** means no content was available in that local log; it is not a full system health assessment.
3. If an authorized administrator requests a report, select **Copy report**. This copies the generated report to the clipboard and closes the dialog; it does not send a message automatically.
4. Review/redact the report before sharing it through the approved private channel. It includes version, configured server, device details, generation time and available raw log tail. Do not assume every exception message is free of sensitive information.
5. Use **Close** to dismiss the dialog without copying. **Clear log** appears when there is log content; it clears that local diagnostic log and closes the dialog. Preserve needed evidence before clearing it.

Do not post raw reports publicly. A report's absence of obvious member fields is not a guarantee that arbitrary logged exception text contains no sensitive details.

## 8. Source and verification reference

- [profile_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/profile/profile_screen.dart): `_showChangePassword`, `_showProfileEdit`, image validation/picker/removal, `_buildAppLockSection`, `_buildSyncSection`, `_showDiagnostics` and `build`.
- [profile_service.dart](../../Mobile/wbws_flutter_app/lib/services/profile_service.dart): `open`, `refresh`, `_updateProfile`, `changePassword`, image requests and owner/epoch fencing.
- [user_profile.dart](../../Mobile/wbws_flutter_app/lib/models/user_profile.dart): `ProfileInputPolicy`, `ProfileImageInputPolicy`.
- [users.php](../../api/v1/routes/users.php): authenticated own-profile/image routes; [AccountCredentialService.php](../../admin/backend/services/AccountCredentialService.php): password mutation/revocation.
- [app_lock_service.dart](../../Mobile/wbws_flutter_app/lib/services/app_lock_service.dart): local settings/options and PIN validation.
- [crash_log_service.dart](../../Mobile/wbws_flutter_app/lib/services/crash_log_service.dart): `buildReport` appends the available log tail; no universal redaction guarantee.

These references establish the described source behavior, not a new signed-device, accessibility or hosted acceptance test. The [coverage record](COVERAGE.md) lists remaining surfaces and validation gaps.
