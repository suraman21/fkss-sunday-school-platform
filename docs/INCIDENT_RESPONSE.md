# Incident response and evidence handling

**Maintained operator procedure, 2026-10-28.** This is a response checklist, not proof that staffing, contact paths, alert delivery or recovery drills are operational. The owner must assign the responsible people and private contacts. No response-time promise, on-call rota or availability target is established here.

## Authority and entry criteria

Use this procedure for suspected loss of access, failed submissions, incorrect results, deployment regressions, lost data or security/privacy concerns. Routine documentation work does not authorize server operations. An incident is not permission to query real school records, change permissions, disable authentication, bypass idempotency or promote unaccepted code.

| Responsibility | Required assignment |
|---|---|
| Incident lead | Owner-designated maintainer coordinates scope, evidence, decisions and status |
| Operator | Authorized person with target-specific access performs approved actions |
| School owner / data authority | Approves business/data decisions and production intervention |
| Communications contact | Gives affected staff a factual status through an approved private channel |

One person may hold several roles in a small team; record who is accountable. Keep contact addresses, infrastructure details, credentials and member information outside public Git/issues. If no authorized operator is available, stop the risky action and escalate privately to the owner.

## 1. Establish facts without changing state

1. Record time/timezone, affected environment, symptoms, affected workflow/role and the last known working observation. Separate a user's report from independently verified evidence.
2. Identify exact source/deployment SHA, offered/installed app build, schema/feature readiness and relevant configuration **only through authorized evidence**. Do not infer parity from `main`, Git metadata, one healthy URL or a previous deployment record.
3. Correlate safe request/operation/attempt IDs with the [sync monitor](WEB_ADMIN_SYNC_MONITORING.md) or [failure registry](FAILURE_INTELLIGENCE.md). A monitor observation is not the authoritative business outcome; missing/in-flight rows can remain ambiguous after a crash.
4. Review/redact diagnostic reports before private sharing. Do not include raw request bodies, secrets, student names, contact details or private file contents in public evidence.
5. Classify the issue: application availability, correctness/integrity, local unsent-work risk or suspected security/privacy exposure. For suspected vulnerabilities use [private reporting](SECURITY.md#1-reporting-a-vulnerability), not a public issue.

## 2. Contain only with explicit approval

- Pause the affected release or administrative operation if the outcome is uncertain. Do not repeatedly finalize, resubmit or restore merely because the response was lost; reconcile the authoritative result first.
- Preserve unsent mobile work. Do not prescribe clearing application data, uninstalling, account switching or destructive logout as a generic repair. Follow [same-account recovery](user_manual/SYNC_AND_RECOVERY.md).
- Revoke/rotate an exposed credential through its authorized custodian; record impact privately. Removing a public message or tracked file does not revoke a credential.
- A code rollback, feature disablement, production configuration change or data repair needs target-specific authorization and a release-specific plan. See [operations rollback](OPERATIONS.md#3-rollback). Forward-only database/SQLite transitions can make a simple downgrade unsafe.
- Do not modify or erase operational evidence to make the dashboard appear healthy. Preserve private logs under the hosting retention policy.

## 3. Reproduce and validate safely

Use an isolated disposable environment with synthetic fixtures. Reproduce the smallest relevant scenario and inspect the executing handler/service rather than trusting a nearby comment or remediation suggestion. Record source evidence, hypothesis and contradictory observations separately.

Test the scoped fix, relevant regressions and failure/retry boundaries. A clean CI result does not prove production or device acceptance. Restore drills must target a new disposable database, not overwrite production. Retain foreign-key validation and [migration/restore safeguards](audits/DEPLOYMENT_RUNBOOK.md); do not disable checks to obtain a superficial success.

## 4. Recover and communicate

1. Record the accepted fix SHA, checks and known limits.
2. Independently verify the authorized development deployment and obtain owner acceptance.
3. Obtain separate production authorization, backups and rollback readiness before any production intervention. Verify files/schema/configuration and relevant client behavior after the approved action.
4. Report observed outcome without inventing success: resolved, mitigated, still investigating or blocked. Record remaining ambiguous writes, unsent work and follow-up owners.

## 5. Close with a bounded record

Keep a dated incident record containing impact, timeline, evidence sources, root cause with confidence, approved changes, checks, recovery observation and follow-up actions. Publish only a sanitized summary in `docs/incidents/`; security/privacy details stay in the restricted register. Preserve earlier failed observations instead of rewriting history. Do not close the issue solely because an alert stopped firing or a remediation suggestion was applied.
