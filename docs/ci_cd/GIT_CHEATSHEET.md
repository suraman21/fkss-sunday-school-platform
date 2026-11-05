# Git workflow reference

Use a topic branch, review the exact diff and merge through a checked PR. [Branching policy](../../BRANCHING.md) defines repository roles and the one-way beta boundary. [Contribution guidelines](../../CONTRIBUTING.md) define review and test requirements.

## Start from current development main

```sh
git status --short                 # preserve existing local changes first
git fetch origin
git switch -c fix/short-description origin/main
```

Use `fix/`, `feature/` or `docs/` names appropriate to the scope. Verify the remote repository before publishing; do not put credentials in the remote URL.

## Review and commit

```sh
git status
git diff
git diff --check
# Run applicable tests against isolated synthetic data.
git add path/to/reviewed-file
git diff --staged
git commit -m "fix: describe the behavior corrected"
git push -u origin fix/short-description
```

Stage explicit paths rather than accidentally including unrelated edits, generated files or credentials. Small coherent commits are easier to review and revert.

## Open and merge a PR

1. Target development `main`, never from beta `dev`.
2. Describe the source evidence, scope, tests and remaining limits.
3. Inspect actual checks for the candidate SHA. Green checks are evidence for those checks, not proof of production readiness or complete behavior coverage.
4. Stop for failed checks, conflicts, missing access or required reviews. Do not bypass protections or mark skipped coverage as passing.
5. After merging, record the merge SHA and main-branch checks. Independently confirm development deployment and obtain owner acceptance. Production promotion needs separate authorization.

After merge, with no local work pending:

```sh
git switch main
git pull --ff-only origin main
git branch -d fix/short-description
```

## Inspect and recover safely

| Command | Purpose / caution |
|---|---|
| `git log --oneline -10` | Inspect recent commits |
| `git diff --staged` | Review exactly what the next commit includes |
| `git restore --staged path/to/file` | Unstage without deleting working-file edits |
| `git restore path/to/file` | **Discards** that file's uncommitted tracked edits; use only after reviewing/saving them |
| `git stash push -u -m "context"` | Temporarily save tracked and untracked work; excludes ignored files and is not an external backup |
| `git stash list` / `git stash apply` | Inspect and restore saved work; resolve conflicts before dropping the stash |

If commits were made on the wrong local branch, first create a named safety branch at the current commit and preserve uncommitted/untracked work. Do not use `reset --hard`, delete a worktree or rewrite shared history as a routine recovery step. If the commit is already shared, coordinate a normal revert or corrective PR.

Amend only unpublished commits you own. Never force-push a shared branch, overwrite another contributor's work or merge beta `dev` into `main`.

## Credentials and artifacts

- Never commit real environment files, access tokens, private keys, keystores, APKs or machine-specific build files. Repository hygiene checks are a backstop, not a complete secret detector.
- Use approved credential tooling; never paste credentials into command history, repository URLs, documentation, issue text or logs.
- If a credential is exposed, rotate/revoke it immediately and report privately under [the security procedure](../SECURITY.md). Deleting a file or rewriting history does not revoke the credential.
- Protection settings can change. Inspect current repository requirements instead of assuming a particular GitHub plan or protection rule.
