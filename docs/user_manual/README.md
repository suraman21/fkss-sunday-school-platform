# FKSS user manual

**Edition:** source checkpoint 1.7.4+41, development baseline `7209278` (2026-10-28).

**Coverage notice:** this is a growing, source-reviewed manual, not yet the requested complete all-department/all-control edition. The chapters below cover mobile account/device settings, updates/recovery and core Finance/Materials web workflows reviewed against `13f00ac`. The full departmental/every-control manual is active requested work, not deferred. Remaining departments, shared/dynamic/legacy controls, permissions and browser/device acceptance stay in the [coverage queue](COVERAGE.md). Do not infer a feature exists or works merely because a role, API action or screen is listed.

## Start here

FKSS supports staff administration, member records, education, attendance, Mezmur, communications and reporting. Web and mobile capabilities differ by role, class assignment, academic year, feature flags and installed app version. A missing or disabled control is not permission to bypass a restriction.

| Task | Guide |
|---|---|
| Edit your own profile, change your password, protect the phone app and inspect diagnostics | [Account and device settings](ACCOUNT_AND_DEVICE.md) |
| Check, download and install an Android app update | [App updates](APP_UPDATES.md) |
| Understand saved work, resolve queue problems and sign out safely | [Sync and recovery](SYNC_AND_RECOVERY.md) |
| Record income/expenses/member fees, understand reports and avoid unsafe retries | [Finance website workflows](FINANCE.md) |
| Manage inventory, record receipts/issues and distinguish request approval from stock movement | [Materials website workflows](MATERIALS.md) |
| Check which areas are documented and which still require review | [Coverage and verification](COVERAGE.md) |
| Understand technical role identifiers and page/API boundaries | [Roles and permissions reference](../ROLES_AND_PERMISSIONS.md) |
| Understand Ethiopian dates, classes, semesters and domain terms | [Glossary](../product/GLOSSARY.md) |

Use your own assigned staff account. Do not share credentials, work around class/department limits or put real student details into support messages. If your permissions appear wrong, ask the school administrator to review the assignment rather than try another person's login.

## Important distinctions

- **Saved locally** means work is on this phone. It is not proof that the server has accepted it.
- **Queued** means an operation awaits transmission or a later decision. Review its status before retrying or discarding it.
- **Submitted** and **approved** are workflow states, not synonyms for saved.
- **Downloaded** is not **installed**. Android must finish the installation and the installed version must be checked afterward.
- **Source reviewed** means the documented path was traced in the repository. It is not a phone/browser or hosted acceptance test.
- **Development main** and **production** are different release gates. This manual's baseline does not certify what is currently deployed or installed.

## Reading procedures and controls

Bold text quotes a visible label; labels may differ in an older installed build. An icon is described by its function or tooltip. Each chapter lists availability conditions, expected outcomes, recovery and source references. Read warnings before destructive controls: discarding private offline work cannot be undone by downloading the roster again.

If a step fails, stop repeating the action, inspect the displayed reason and confirm whether the previous attempt reached the server. Report the app version, task, expected result, actual result and approximate time privately. Do not send passwords, tokens, member records or unreviewed diagnostic logs.
