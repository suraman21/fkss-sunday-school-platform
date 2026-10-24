# Production Workflow — Felege Kidusan SSMS

> Established 2026-10-07 (production migration **Step 1**). This document
> defines how code travels from development to production, who approves it,
> and what CI enforces. Application configuration, domains and deployment
> are deliberately unchanged in Step 1.

## Current state (verified 2026-10-22)

The policy below was adopted on 2026-10-07. Part of it is **not** in effect today. The table shows where practice differs.

| Policy element | Actual state, 2026-10-22 |
|---|---|
| Changes reach production `main` through a sync pull request with one approving review | Pull requests #1–#10 used the sync flow until 2026-10-08. Since then, approved commits have been **pushed directly** to `main` in both repositories, which are kept identical (both at `46fbaa7`) |
| Production `main` is protected | **No branch protection is active** on the production repository: protection for private repositories is unavailable on the current GitHub plan. The development repository's `main` requires only the *Branch guard* job (`main must build the production app`). No review is required, and administrators are exempt (rechecked 2026-10-22) |
| Every job must be green before merge | *Backend checks* has been **failing** on `main` since `b9cbb0f` (2026-10-19). See [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md#3-current-ci-status) |
| Deployment is not configured | It is still not automated. Production is deployed by a **manual SFTP upload** of changed files, with a backup and checksum verification. See [OPERATIONS.md](OPERATIONS.md#2-code-deployment-manual-sftp) |
| Development repository URL | Renamed to `suraman21/fkss-sunday-school-platform`. The old `suraman21/SSMS` URL redirects |

Restoring the intended policy is a recommendation in [ENGINEERING_HANDOVER.md](ENGINEERING_HANDOVER.md). Until then, treat the sections below as the **intended** workflow.

## Repository roles

| | Development repository | Production repository |
|---|---|---|
| URL | https://github.com/suraman21/fkss-sunday-school-platform (formerly `suraman21/SSMS`) | https://github.com/Felege-Kidusan/felegekidusan |
| Purpose | development, feature work, experimentation, testing, development CI | production-ready code, approved releases, production CI, release history |

**Rules**

- The production repository is **not** a second development repository. No
  feature work, no experiments, no unreviewed fixes happen here.
- Production `main` contains only code approved for production.
- The development repository remains the working source of truth; this
  repository receives approved states of it.

## Branch strategy (intended policy)

- `main` — the production branch. Protected: changes arrive by pull request
  with green CI and one approving review; force pushes and deletions are
  rejected (repository admins can bypass in an emergency).
- `sync/from-development`, `hotfix/*` — short-lived branches inside this
  repository, used only as pull-request sources and deleted after merge.
- `feature/*` and `fix/*` branches live in the **development** repository.
- No other permanent branches.

## How approved code reaches production

```text
Developer
   |
   v
feature/* or fix/* branch        (development repository)
   |
   v
development PR + backend-checks CI
   |
   v
approved merge to development main
   |
   v
sync PR into this repository
   |
   v
production CI (backend checks + production integrity)
   |
   v
manual approval + merge  =  production release on main
```

Step by step:

1. Develop on `feature/*` / `fix/*` branches in **suraman21/fkss-sunday-school-platform**.
2. Open the pull request there; `backend-checks.yml` must pass.
3. Merge to development `main` after review.
4. A maintainer pushes the approved commit here as a PR source branch:
   `git push https://github.com/Felege-Kidusan/felegekidusan.git <approved-commit>:refs/heads/sync/from-development`
5. Open a pull request `sync/from-development` → `main` **in this repository**.
6. Both workflows must pass (see CI requirements below).
7. A maintainer approves (one approving review is required) and merges. The
   resulting `main` state is the production release. Delete the sync branch.

Hotfixes follow the same path from a `hotfix/*` branch in the development
repository — still via pull request and CI here.

## CI requirements (this repository)

Two workflows run on every push to `main` and every pull request:

1. **Backend checks** (`backend-checks.yml`, unchanged from the development
   repository): PHP syntax lint of every first-party PHP file, the security
   and regression suite (`tests/security`) against a real MariaDB with a
   zero-skip policy, the F-20 idempotency lifecycle harness, Flutter unit
   tests with the committed lockfile, migration numbering hygiene, and an
   Android debug APK build with manifest/dex verification.
2. **Production integrity** (`production-integrity.yml`, this repository
   only): no credential/environment/artifact files tracked, no secret-shaped
   material in the tree **or anywhere in history**, no tracked file over
   15 MiB, and no workflow may read repository secrets.

CI needs no secrets, no production database and no credentials. A pull
request is not mergeable until every job is green.

## Production approval principle (intended policy)

- Every change to production `main` requires a pull request, green CI and at
  least one human approval.
- CI is a gate, never an approver: no auto-merge, no auto-deploy.
- Deployment is a deliberate manual act by a named person.

## Deployment — not automated

> **Current practice:** manual SFTP deployment by the owner; see [OPERATIONS.md](OPERATIONS.md). The text below is the original 2026-10-07 statement, kept for history.

**Nothing in this repository deploys anywhere.** There are no deploy keys, no
server credentials, no webhooks and no deployment jobs. This is intentional
(Step 1 scope): deployment will be designed and approved in a later migration
step, after the new production server has been audited and prepared.

Until then, the live system continues to be updated by the manual server-side
procedure documented in `Mobile/HOW_TO_SHIP_AN_UPDATE.md` and
`docs/audits/DEPLOYMENT_RUNBOOK.md`.

Future architecture (to be approved in a later step):

```text
production main (approved release)
   |
   v
CI (checks only)
   |
   v
manual trigger + approval
   |
   v
deployment mechanism on the cPanel server   (exact form decided later)
   |
   v
post-deploy health verification             (runbook STAGE 5)
```

## Secrets policy

- No secrets in this repository — not in the tree, not in history.
  `production-integrity.yml` enforces this on every push and pull request.
- Production runtime secrets (database credentials, JWT_SECRET, BACKUP_KEY,
  Telegram tokens, …) live only in the server-side `.fkss_env.php` above the
  web root of the production server; `env.example.php` documents the key
  inventory.
- When a future approved step needs CI secrets (for example to deploy), they
  will be GitHub environment secrets configured through repository settings —
  never committed files.

## Related documents

- `docs/ci_cd/ARCHITECTURE.md`, `docs/ci_cd/GIT_CHEATSHEET.md` — development workflow
- `docs/audits/DEPLOYMENT_RUNBOOK.md` — server-side go-live procedure
- `Mobile/HOW_TO_SHIP_AN_UPDATE.md` — current manual deployment procedure
