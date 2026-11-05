# Branching and environment boundaries

**Maintained policy, clarified 2026-10-28.** Development-main integration, server deployment, APK publication and production promotion are separate events. See [release policy](docs/PRODUCTION_WORKFLOW.md) and [ADR-0021](docs/adr/0021-development-acceptance-before-production.md).

## Branch roles

| Branch / repository | Purpose | Release boundary |
|---|---|---|
| Development repository `main` | Reviewed integration branch; feature/documentation PRs target this branch | Owner's workflow updates the development server by cron, then the owner tests. A merge does not verify cron execution, deployed files, schema or acceptance |
| Development repository `dev` | Beta Android settings layered on `main` | Separate API target, package ID and beta branding. Not a feature-integration branch and never a source for `main` |
| Production repository `main` | Separately approved production state | May intentionally lag development. Requires explicit owner authorization after development acceptance; never automatically mirror every development merge |

`main` retains the production Android identity/API settings so beta configuration cannot enter a production-target build. This build configuration does **not** mean every development-main commit is published, installed or production-approved. Source checkpoint 1.7.4+41 is not an installed-fleet inventory.

## One-way beta synchronization

The rule established on 2026-10-20 remains in force:

**Never merge, rebase, cherry-pick or push `dev` into `main`, and never push `dev` to the production repository.** Direction is `main` → `dev` only.

Features and fixes originate from current development `main`. The intended `dev` delta is beta-target configuration plus merge history, not unrelated features. Inspect the actual divergence before synchronizing; do not assume two servers or two branches are identical.

After an approved development-main change, a maintainer may synchronize beta:

```sh
git status --short                    # stop if local work is present
git fetch origin
git switch dev
git pull --ff-only origin dev
git merge origin/main                 # stop on conflicts; preserve beta-only settings
# Inspect the diff and run beta-specific tests before pushing.
git push origin dev
```

Do not force-push either shared branch. If divergence or conflicts require changing the environment boundary, stop for maintainer review.

## Normal change workflow

```sh
git status --short
git fetch origin
git switch -c docs/topic origin/main
# Make and review scoped edits; run relevant isolated checks.
git diff --check
git add path/to/reviewed-file
git diff --staged
git commit -m "docs: clarify topic"
git push -u origin docs/topic
```

Open a PR into development `main`. Inspect every applicable check, not only the minimum configured protection, and do not bypass a failure, review requirement or conflict. Record the merged SHA and check results. Confirm development deployment independently and record owner acceptance; production remains a separate decision.

## Guards and their limits

| Guard | Source | What it establishes |
|---|---|---|
| Target-environment regression | `tests/security/test_mobile_target_environment.py` | Pins expected production settings on `main`; the beta branch has its own expected settings |
| Branch guard | `.github/workflows/branch-guard.yml` | Rejects beta markers on `main` and PRs from `dev` into `main` |
| PR checklist | `.github/PULL_REQUEST_TEMPLATE.md` | Requires the author to acknowledge the branch/environment boundary |
| Release script | `Mobile/wbws_flutter_app/scripts/build-release.ps1` | Runs Flutter tests unless explicitly skipped and creates versioned artifacts; skipping tests is not release acceptance |

A plain `flutter build apk` does not run tests. A successful debug build is not production-signing evidence. Build and install only under the [Android release guide](Mobile/wbws_flutter_app/docs/RELEASE.md); the owner controls signing, phone acceptance and publication. Never change app targets, minimum build or signing policy as a workaround for a failed check.

## Exceptions

If a task appears to require reversing the beta direction, changing production settings, bypassing protections or promoting an unaccepted revision, stop and obtain explicit maintainer authorization. Existing development-merge authorization does not authorize production operations.
