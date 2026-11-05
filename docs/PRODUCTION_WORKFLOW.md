# Development acceptance and production promotion

**Status:** maintained release policy, updated 2026-10-28. Source baseline: development main `7209278`. This document distinguishes owner instructions, checked-in automation and historical observations. It does not certify live servers or current repository-protection settings.

## Current delivery policy

```text
Scoped change on a development branch
    → commit and push
    → development pull request and all applicable checks
    → authorized merge to development main
    → development-server cron update, independently verified
    → owner acceptance on development / owner-built phone APK
    → separate explicit authorization for production promotion
    → production review, checks and approved deployment
    → post-deployment verification and dated release record
```

**Stop before production unless the owner explicitly authorizes it.** Permission to merge development main is not permission to push to production, publish an APK, apply migrations, change hosting or access real school records. Do not automatically synchronize both repository mains after every development merge.

## Repository roles

| Repository | Purpose |
|---|---|
| [Development](https://github.com/suraman21/fkss-sunday-school-platform) | Feature/documentation branches, pull requests, source review, isolated tests and development-main integration |
| [Production](https://github.com/Felege-Kidusan/felegekidusan) | Separately approved production states and release history, not experimental work |

The owner's instruction is that cron pulls development main to the development server. Its execution, deployed files, schema and feature flags must be verified separately. Source equality does not prove runtime parity. `dev` remains a distinct beta-configuration branch; never merge it into `main` or push it to production. See [BRANCHING.md](../BRANCHING.md).

## Development integration

1. Start a scoped branch from current development main. Record the baseline and affected workflows before editing.
2. Use synthetic local data and the existing fixture harnesses. Preserve permissions, API compatibility and database boundaries unless the owner authorizes a change to them.
3. Commit the reviewed diff and push the branch without embedding credentials in the remote URL or logs.
4. Open a development pull request. Verify successful checks for its exact head; resolve failures, conflicts and required reviews. Do not bypass protections.
5. Merge only within the owner's authorization. Verify the resulting main revision and its checks.
6. Record the development-server revision and owner acceptance separately. The owner builds/signs the APK locally and tests the phone; CI's debug build is not that acceptance.

## CI requirements

Checked-in development automation at the baseline:

| Workflow | Function |
|---|---|
| [backend-checks.yml](../.github/workflows/backend-checks.yml) | PHP 8.4/8.5 lint, isolated backend zero-skip suite, idempotency lifecycle, Flutter tests, migration hygiene and debug APK build |
| [production-integrity.yml](../.github/workflows/production-integrity.yml) | Repository secrets/artifacts/history guards |
| [branch-guard.yml](../.github/workflows/branch-guard.yml) | Main mobile configuration and beta-branch constraints |
| [apk-upload-e2e.yml](../.github/workflows/apk-upload-e2e.yml) | Manually dispatched synthetic browser/PHP APK-transfer acceptance |

All eight automatic baseline checks passed on `7209278`; see [dated evidence](reports/DOCUMENTATION_BASELINE_2026-10-28.md). That does not establish the next candidate's results. The backend job fails on **any** skip. CI does not deploy, approve production, certify security or prove phone installation. Verify the production repository's actual checks and protection settings before promotion instead of assuming they match this checkout.

### CI evidence storage, not deployment storage

The owner-approved no-billing policy keeps the same build/verification workflow in public development and private production. Actual Android debug/universal/ARM, SDK/native/signature and PHP inspection gates remain mandatory. Full generated reports are recorded in Actions logs/job summaries; only public repositories additionally upload downloadable CI report/APK archives. Private CI APK bytes are not retained or copied to public storage. See [the precise retention and provenance boundaries](../Mobile/wbws_flutter_app/docs/ANDROID_COMPATIBILITY.md#ci-evidence-storage).

Repository contents do not equal account resources: public and private Actions usage have different allowance rules, and separate owners have separate usage histories. This policy does not change repository visibility, billing, owner release signing/publication or log-retention settings. Use the automatic PR checks rather than dispatching duplicate manual runs for an already running candidate.

## Production approval principle

A production release needs a named approver, the accepted development SHA, explicit promotion authorization and a release record. The intended review policy is a production pull request with green checks and a human approval. Repository settings may not enforce the whole policy; do not confuse a written rule with an installed protection.

Before promotion, the operator must:

1. Confirm owner acceptance and the exact files/revision in scope.
2. Check installed-client compatibility and any migration order, data effects and feature flags.
3. Prepare verified backups, key custody and a release-specific rollback plan. Forward-only migrations and local SQLite transitions may prevent a simple code downgrade.
4. Use only the separately authorized production access and deployment method. [OPERATIONS.md](OPERATIONS.md) retains conditional SFTP safeguards; [the deployment runbook](audits/DEPLOYMENT_RUNBOOK.md) retains migration and restore checks.
5. Verify the resulting files, schema and critical workflows, then record the observations and remaining gaps. Do not label a successful repository push as a server deployment.

If checks fail, access is missing, the deployment method is unclear or rollback is unsafe, stop the release action and report the blocker. Continue only safe, in-scope work.

## Historical observations

The original policy was established on 2026-10-07. These observations explain earlier documents; they are not a current settings audit:

- Production pull requests #1–#10 used the sync flow through 2026-10-08. Later records describe approved direct pushes to both mains and manual SFTP deployment.
- The 2026-10-25 settings record reported no production branch protection because of the private-repository plan, and only *Branch guard* required in development with administrator exemptions. Current settings remain **UNKNOWN** until rechecked through authorized access.
- Earlier backend runs were red because of missing fixture prerequisites and 79 skipped tests. PR #41 repaired that environment; later baseline checks are green.
- No checked-in deployment job is evidence of hosting cron configuration. Conversely, the owner's development cron instruction is not proof of production automation.

Preserve dated deployment records in [CHANGELOG.md](../CHANGELOG.md#production-deployment-record). Do not rewrite historical outcomes to look successful.

## Secrets and release evidence

Runtime secrets remain outside the web root in the approved environment file. Public documentation lists key names only. Never commit tokens, credentials, keys, school records or private infrastructure details. Use approved access-transfer channels for maintainer continuity.

For each release, record repository/branch, candidate and merged SHAs, checks, operator/approver, owner acceptance, migration observations, file verification, APK version/signer where applicable, rollback readiness and unresolved gates. [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md), [OPERATIONS.md](OPERATIONS.md) and [DOCUMENTATION_STANDARD.md](DOCUMENTATION_STANDARD.md) define the related evidence boundaries.
