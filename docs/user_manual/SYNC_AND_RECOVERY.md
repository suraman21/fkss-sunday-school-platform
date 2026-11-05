# Sync and recovery

[Manual index](README.md) · [Account and device settings](ACCOUNT_AND_DEVICE.md) · [App updates](APP_UPDATES.md)

**Audience:** mobile staff with saved/offline work. **Baseline:** `7209278`, source checkpoint 1.7.4+41. **Verification:** recovery UI, state contracts, session coordinator and selected SQLite recovery/cleanup paths reviewed. Individual department submission/review workflows and all domain-specific queue workers are not fully covered by this chapter.

## 1. Understand where work exists

The phone can hold read caches, attendance/grade packets, communication drafts/sends and shared hymn operations. Server acceptance, local saving and workflow approval are different events. A visible mark or a queued message is not proof of successful transmission.

**Do not clear app storage or uninstall to fix a queue problem.** Server rosters may be fetched again, but unsent private work is not recoverable merely by downloading server data. A same-account recovery marker is not an off-device backup.

Open **Profile → Sync Center** before signing out, handing over a shared phone or troubleshooting an interrupted session. Read unresolved counts and any paused/attention states, not only a single aggregate number.

## 2. Inspect Sync Center

1. Open **Profile → Sync Center**.
2. Select the **Refresh** icon or pull down to reload the local inventory. Refresh is not a manual server-acceptance confirmation.
3. Review each group and any message-draft/shared-hymn notices.
4. Select an item's displayed reason to open **Why this needs attention**. Read the complete selectable reason, then select **Close**.
5. If **Open source screen** is available, use that icon to return to the task where the work originated. It is hidden when the current role/feature navigation does not permit the destination.

### Group reference

| Group | Meaning | Safe next step |
|---|---|---|
| **Waiting to send** | Pending or in-flight local work | Wait for transmission/response; do not create duplicate edits just because the request is slow |
| **Retry scheduled** | Work waiting until a later automatic attempt | Review the next-try value and allow the configured delay; repeated manual actions are not needed |
| **Needs attention** | Failed/rejected or dependency-blocked work | Read the reason; repair the source task/dependency, retry only when offered, or deliberately discard |
| **Paused after sign-in/access change** | Authentication or authorization scope prevents normal transmission | Recover the same account or ask the administrator to restore compatible access |
| **Resolved conflicts** | The server copy won the conflict | Review the source/server result, then acknowledge the local conflict record if appropriate |

A saved message draft is not necessarily a queued send. The summary can include drafts alongside unresolved operations. Older-scope private work may appear as an aggregate count only: hiding its details prevents an account/access change from exposing the previous scope's records. Do not try another account to reveal it.

Shared hymn work is distinct from private-account work. If the current user is not an authorized curator, the screen can show a count and state that an authorized curator must continue it. Do not treat that count as permission to edit the library.

## 3. Resolve one operation

| Control | Availability | What it does and does not do |
|---|---|---|
| **Retry** | `needs_attention` or `failed` items | Queues a retry of the exact saved operation and kicks the relevant worker; the success message means queued on this phone, not server acceptance |
| **Open source screen** icon | Current role/feature permits that source | Opens the originating domain/message/library screen; does not itself repair or submit the record |
| Reason text | A nonempty reason exists | Opens the full reason; **Close** returns without changing the operation |
| **Discard** | Attention/conflict-capable item, except where Acknowledge is used | Opens a confirmation; final **Discard** removes the exact local queued operation. It cannot be undone and does not delete an accepted server record |
| **Acknowledge** | Resolved conflict | Removes/acknowledges the exact local conflict record after review; it is not an overwrite of the winning server copy |
| **Cancel** in discard confirmation | Confirmation open | Keeps the operation unchanged |

A dependency-blocked item does **not** offer Retry in this UI: repair/open the prerequisite or deliberately discard it. Repeated retries cannot restore missing permissions or recreate a retired workflow.

While an action is running, conflicting action controls are disabled. If the item changed before the action applied, the screen reloads and says **This item changed. The list was reloaded.** Review the new state; do not assume the old button acted on a newer replacement. The SQLite legacy path checks owner, authorization version, operation ID and expected state rather than deleting by a broad class/date key.

**Completion check:** refresh the inventory and inspect the source/server result when the workflow permits it. A disappeared local operation, a queued-retry toast and a domain approval are not interchangeable outcomes.

## 4. Recover after a session ends

A session can end while private work remains. The recovery screen says **Sign in again to recover your work** and identifies the prior account where known.

1. Review the saved-work inventory and any attention/paused counts.
2. Enter the **Username** and **Password** for the **same account** that owns the work. Use the password-visibility icon only in a private setting.
3. Select **Recover work** and wait while it says **Checking…**.
4. Read any rejection or access-change message. A different valid account is not automatically allowed to inherit this work. Restoring the same account does not promise that an incompatible new role/scope can transmit all old operations.
5. When access is restored, inspect Sync Center and the original task. Complete required review/sync steps rather than assume sign-in itself submitted everything.

If private data has no provable owner, the orphan-recovery screen does not attach it to an arbitrary login. It offers an explicit destructive discard path. Do not use that path until the owner/administrator has considered the consequence: the work will not be recovered by another account.

If the app is reconciling access or finishing secure cleanup, let that transition finish. A protection-failure screen's **Retry** is a safe recovery attempt for its displayed failure, not permission to delete data or bypass owner checks.

## 5. Sign out without confusing preservation and deletion

Select **Profile → Logout**, then read the actual dialog.

### When durable private work exists

| Choice | Effect |
|---|---|
| **Cancel** | Stays in the current session; does not discard work |
| **Keep work & sign out** | Enters same-account reauthentication, requests session revocation/credential clearing and preserves private durable work and the app passcode for recovery. Read any protection failure; do not assume a failed request completed |
| **Discard permanently** | Explicit destructive cleanup of private work/caches, credentials and app passcode; unsent private work cannot be recovered from the server |

Preserving work is not a secure transfer to a new user or another phone. Only compatible same-account recovery can continue it. Keep the phone protected and do not share its passcode.

### When no durable private work is detected

The **Sign out?** dialog explains that private read caches, saved credentials and the app passcode will be removed. **Cancel** keeps the session; **Sign out** chooses private cleanup. Shared hymn operations are intentionally retained and are identified separately when present.

### Destructive recovery choices

**Discard private data and use another account**, **Discard private data permanently** and the confirmation **Discard permanently** are destructive alternatives, not routine recovery buttons. Read the inventory warning, select **Cancel** if uncertain and contact the administrator privately. Do not expect rollback, Undo or a later sign-in to restore discarded unsent work.

Cleanup uses a persisted purging state and fails closed if protected cleanup cannot finish. A failed cleanup is not a successful logout or proof that all local bytes are erased. SQLite secure deletion/checkpoint/vacuum are implementation measures, not a universal forensic-erasure guarantee across phones and backups.

## 6. Troubleshooting and escalation

- **No network:** keep the app/data, avoid destructive cleanup and reconnect. Some writes remain local; online-only profile changes are different.
- **Sign-in rejected:** verify the owner account and current credentials. Do not repeatedly guess or use another staff login.
- **Access changed / old-scope counts hidden:** ask an administrator to review the compatible role/class assignment. Do not request broader permissions merely to send stale work.
- **Retired HR attendance:** use the approved Education/Mezmur reporting workflow on the web. A retired-domain error is not a transient outage; repeated retry cannot restore HR attendance.
- **Persistent conflict or dependency failure:** preserve the operation and reason, review the source task and obtain authorized help before discarding.
- **Diagnostic sharing:** use the [diagnostics procedure](ACCOUNT_AND_DEVICE.md#7-use-diagnostics-safely), review/redact the log and send it privately. Never include passwords, tokens or member records.

## 7. Source and verification reference

- [sync_center_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/profile/sync_center_screen.dart): inventory, sections, reason/source navigation, retry/discard/acknowledge controls and exact-operation handling.
- [sync_recovery_models.dart](../../Mobile/wbws_flutter_app/lib/services/sync_recovery_models.dart): state grouping and `canRetry`/`canDiscard`/`canAcknowledge` conditions.
- [session_logout_dialog.dart](../../Mobile/wbws_flutter_app/lib/widgets/session_logout_dialog.dart): choice-based logout and shared-hymn notice.
- [session_recovery_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/auth/session_recovery_screen.dart): recovery forms, discard confirmations and transition/failure screens.
- [session_service.dart](../../Mobile/wbws_flutter_app/lib/services/session_service.dart): `login`, `enterReauthentication`, `applyLogoutChoice`, `destructiveSignOut` and persisted cleanup/recovery transitions.
- [local_db.dart](../../Mobile/wbws_flutter_app/lib/services/local_db.dart): `retryRejectedOperation`, `discardRejectedOperation`, `getSyncRecoveryItems`, `clearAllUserData`; shared library tables/operations are deliberately retained.
- [HR web retirement endpoint](../../admin/api_hr_attendance.php) and [REST retirement route](../../api/v1/routes/hr.php): authenticated retired-domain responses.

Full domain worker/database execution, process-death scenarios and real-phone recovery acceptance remain separate verification work. See [coverage](COVERAGE.md); this chapter is not proof of every department's sync outcome.
