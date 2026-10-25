# CI/CD architecture for FKSS

> **Historical design proposal (marked 2026-10-22).** Written on 2026-09-05 as a proposal ("nothing here is built yet"). It is retained unchanged. The CI that actually exists, and its current status, are described in [DEVELOPMENT_AND_TESTING.md](../DEVELOPMENT_AND_TESTING.md).


Written for someone new to Git. Every term is explained the first time it
appears. Nothing here is built yet — this is the design to agree on first.

---

## 1. Why this matters, using our own history

The last four rounds of search bugs ended with this crash:

```
Unhandled Exception: Unsupported operation: read-only
  QueryRow.[]= (sqflite_common/src/collection_utils.dart:152)
  HymnStore.hymns (hymn_store.dart:148)
```

It reached a real phone. The cost was a 230-second build, an install, and
a manual test — repeated four times across four rounds.

A test that ran automatically on every push would have caught it in
about ninety seconds, before it ever left the computer. That is the point
of this whole document. Automation is secondary; **the gate is the value.**

---

## 2. Git in four ideas

You said you use `git pull` and `git push` without knowing what they do.
That is genuinely all you need to start, but four ideas make the rest
make sense.

| Term | What it actually is |
|---|---|
| **commit** | A save point, with a message describing the change. |
| **push** | Upload your save points to GitHub. |
| **pull** | Download save points other people made. |
| **branch** | A parallel copy of the project where you work without affecting the live version. |

**Branch is the one that changes how you work.**

Today every commit goes straight to `main`. `main` is the official
version, so the moment you commit something broken, broken *is* the
official version. There is no gap in which to catch it.

The rule used by every company you named:

> `main` is always shippable. Nothing enters it unless the automated
> checks pass.

You get that gap by working on a branch and merging only when the robots
approve.

### The everyday loop

```
git checkout -b fix/search-crash   # make a branch, start working
                                   # ...edit files...
git add -A                         # stage the changes
git commit -m "fix: ..."           # save point
git push -u origin fix/search-crash
                                   # → open a Pull Request on GitHub
                                   # → CI runs automatically
                                   # → green? merge. red? fix and push again.
```

A **Pull Request (PR)** is a request to merge your branch into `main`.
It is where the test results appear and where a teammate reviews the
change. With 2-5 people this is also how you avoid overwriting each
other.

---

## 3. What we already have (surveyed, not assumed)

Good news — more exists than expected.

| Component | Status |
|---|---|
| Dart unit tests | **204 passing**, 12 test files |
| PHP smoke tests | 7 scripts in `tests/` |
| **In-app update system** | **Already built and working** |
| CI/CD pipeline | **None** — this is the gap |
| Branch protection | None — anyone can push to `main` |

### The update system you already have

`api/v1/core/app_release.php` + `lib/services/app_update_service.dart`
already implement:

- a version/build check against the server
- `force_update` and `min_build` (can *require* an upgrade)
- in-app APK download with progress
- `installApk` via a platform channel
- release notes and a remote banner

This is a real direct-APK update system. **We do not need to build one.**
It needs three things: automation, a test gate in front of it, and
staged rollout.

> ⚠️ **Already drifting.** `app_release.example.php` says version `1.1.0`
> build `2`; `pubspec.yaml` says `1.1.16+18`. The server file is edited by
> hand, so it falls out of sync. CI should generate it.

---

## 4. Constraint that shapes everything: OTA is limited for us

Shorebird delivers Dart patches over the air, store-compliant, built by
Flutter's original creator. Tempting — but it **patches Dart only, never
native code or plugins**.

This app has 11 native plugins:

```
just_audio  audio_service  sqflite  mobile_scanner  permission_handler
flutter_secure_storage  image_picker  connectivity_plus  local_auth
shared_preferences  path_provider
```

| Change | OTA-patchable |
|---|---|
| Search fixes (P37-P41) | ✅ pure Dart |
| UI, ranking, business logic | ✅ |
| Add or upgrade any plugin | ❌ full release |
| Flutter SDK upgrade | ❌ full release |

**Verdict: not now.** You distribute APKs directly, so you already have
no store review to wait out — which is the main problem Shorebird
solves. Adding it now buys little and adds a second update path to
reason about. Revisit if you publish to Play.

---

## 5. Target architecture

```
        ┌──────────────────────────────────────────────┐
        │  1. DEVELOP                                  │
        │  branch → commit → push → Pull Request       │
        └───────────────────┬──────────────────────────┘
                            ▼
        ┌──────────────────────────────────────────────┐
        │  2. CI GATE   (automatic, ~2 min)            │
        │  · dart analyze        · flutter test (204)  │
        │  · php -l syntax check · build APK           │
        │  ── fails → merge BLOCKED ───────────────────│
        └───────────────────┬──────────────────────────┘
                            ▼
        ┌──────────────────────────────────────────────┐
        │  3. REVIEW → merge to main                   │
        │  main is always shippable                    │
        └───────────────────┬──────────────────────────┘
                            ▼
        ┌──────────────────────────────────────────────┐
        │  4. RELEASE  (you tag v1.2.0 deliberately)   │
        │  · build signed APK · attach to GitHub       │
        │  · generate app_release config               │
        └───────────────────┬──────────────────────────┘
                            ▼
        ┌──────────────────────────────────────────────┐
        │  5. STAGED ROLLOUT                           │
        │  internal → 10% → 50% → 100%                 │
        │  halt at any point                           │
        └──────────────────────────────────────────────┘
```

Note **merging is not releasing**. Code lands in `main` continuously;
releases happen when *you* tag one. This is how large teams ship safely.

---

## 6. The CI gate in detail

Runs on every push and every PR. Four jobs in parallel:

| Job | Command | Catches |
|---|---|---|
| Analyze | `dart analyze --fatal-infos` | type errors, dead code, bad imports |
| Test | `flutter test` | logic regressions — all 204 |
| Build | `flutter build apk --release` | **compile errors the analyzer misses** |
| PHP lint | `php -l` on every file | syntax errors before they hit the server |

**The build job is the one that would have caught our crash.** Not
literally — `row['x'] = y` compiles fine — which is why we also add a
regression test (`test/search_row_readonly_test.dart`, already written)
that reproduces the read-only throw. Between them, that class of bug
cannot ship again.

> **Honest limit.** These are unit tests, not device tests. They would
> have caught the read-only crash *because we wrote a test reproducing
> it*. They cannot catch "the highlight looks wrong." Widget tests and a
> manual smoke checklist cover that; see §9.

---

## 7. Staged rollout without a store

Play Store gives percentage rollout for free. On direct APK we build it,
and the existing config already has the right shape:

```php
return [
    'latest_version'  => '1.2.0',
    'latest_build'    => 20,
    'min_build'       => 18,      // below this, update is FORCED
    'force_update'    => false,
    'rollout_percent' => 10,      // ← the one field to add
    'release_notes'   => '...',
];
```

Server-side: hash the device id, serve the update only if
`hash % 100 < rollout_percent`. Deterministic — a device that sees the
update keeps seeing it, so nobody flip-flops.

Then: `10` → watch → `50` → watch → `100`. Something wrong? Set it back
to `0`. Nobody else is offered the bad build.

`min_build` is your emergency lever: raise it and old versions are
*forced* to upgrade. Reserve it for security fixes and data-corrupting
bugs — it interrupts everyone.

---

## 8. Web backend deploy

You said "cron job pulling or manual upload sometimes." The mixture is
the risk: manual uploads can be silently overwritten by the next cron
pull, and nobody can tell what is actually live.

`vendor/` **is** committed (737 files), so a `git pull` deploy is
self-contained — no `composer install` needed on the server. That is
convenient and worth keeping.

Proposal — keep cron-pull, make it safe:

1. Server pulls **only tagged releases**, never `main`. Unfinished work
   can never appear on the live site.
2. CI runs `php -l` on every PHP file, so a syntax error cannot be
   tagged. Right now a bad `.php` file goes live and takes the site down
   with a blank page.
3. The cron script writes the deployed tag to a file; a
   `/api/v1/health` endpoint reports it. You can always answer "what is
   live?"
4. Stop the manual uploads. If something needs to change, it goes
   through Git — otherwise the next pull silently reverts it.

---

## 9. Testing strategy (the "industry standard" you asked about)

The standard model is a pyramid — many cheap tests, few expensive ones:

```
        ╱ Manual  ╲        a short checklist, real device, before release
       ╱  Widget   ╲       does the UI render and respond correctly
      ╱   Unit      ╲      204 tests — logic, ranking, policies  ← we are here
     ╱  Static       ╲     analyzer + linter, every push
```

We are strong at the bottom two, absent at the top two.

| Layer | Now | Target |
|---|---|---|
| Static | ad hoc | every push, blocking |
| Unit | 204 ✅ | keep, run automatically |
| Widget | **0** | ~10 for search + player |
| Manual | ad hoc | a written pre-release checklist |

Widget tests matter for us specifically: **every search bug in P37-P41
was invisible to unit tests** because the ranker was correct in
isolation. A widget test that types into the search box and asserts the
list changes would have caught P41 immediately.

---

## 10. Environments

| Environment | Purpose | Who |
|---|---|---|
| Local | your machine | you |
| **Staging** | a copy of the live site with fake data | the team |
| Production | the real thing | users |

There is no staging today, so `main` is tested against production data.
Staging is where you verify a backend change before real students'
records are involved.

---

## 11. Phased plan

Per your choice: **safety net first.** Nothing auto-deploys in Phase 1.

### Phase 1 — the gate (build now)
- `.github/workflows/ci.yml`: analyze, test, build APK, PHP lint
- Branch protection: `main` requires green CI + one review
- **Result:** broken code cannot enter `main`. Zero deployment risk.

### Phase 2 — release automation
- Tag `v1.2.0` → CI builds the signed APK, publishes it, generates the
  release config, syncs the version so it stops drifting

### Phase 3 — staged rollout
- `rollout_percent` in the config + server-side bucketing

### Phase 4 — web deploy safety
- Tag-only cron pull, health endpoint reporting the live tag

### Phase 5 — coverage
- Widget tests, staging environment, pre-release checklist

---

## 12. Decisions on record

| Decision | Why |
|---|---|
| GitHub Actions, not Codemagic | Codemagic wins on iOS signing; we are Android-only and have PHP + Flutter in one repo. One system, free at our volume. |
| No Shorebird yet | 11 native plugins limit what is patchable, and direct-APK already has no store review to skip. Revisit if we publish to Play. |
| Keep the existing update service | It already does force-update, min-build, download and install. Automate it rather than replace it. |
| Merging ≠ releasing | Code lands continuously; releases are deliberate and tagged. |
| Tag-only web deploy | Prevents unfinished `main` from reaching the live site. |

---

## 13. What I need from you before Phase 2

Phase 1 needs nothing — it only reads code.

Phase 2 signs APKs, which needs secrets stored in GitHub (encrypted,
never in the repo):

- the Android **keystore** (`.jks`) and its passwords — **must be the
  same keystore every time**, or Android refuses to install the update
  over the existing app
- how the APK reaches `<account-home>/fkss_releases/fkss.apk`

If the keystore was ever lost, say so early — it changes the plan, and
existing users would have to uninstall before updating.

---

## 14. Adding the workflow file (one-time, needs your action)

The workflow is written and verified at `.github/workflows/ci.yml` in
your local clone, but it is **not on GitHub yet**. GitHub refused the
push:

```
refusing to allow a Personal Access Token to create or update
workflow `.github/workflows/ci.yml` without `workflow` scope
```

This is a deliberate security control: a token that can add workflows
can make GitHub run arbitrary code with your repository's secrets, so
that permission is separate and opt-in. The block is correct behaviour.

Two ways to add it — the second is safer.

### Option A — paste it in the GitHub web UI (no token change)

1. On GitHub, open the repo → **Add file** → **Create new file**
2. Name it exactly `.github/workflows/ci.yml`
3. Paste the contents of your local `.github/workflows/ci.yml`
4. Choose **Create a new branch and start a pull request**
5. Open the PR — CI runs on itself, so you see it work immediately

### Option B — grant `workflow` scope to a token

Only if you prefer the command line. Add the `workflow` scope on a
**new** token, then push.

> 🔑 **Rotate the current token regardless.** It has been pasted into a
> chat transcript, so treat it as compromised: GitHub → Settings →
> Developer settings → Personal access tokens → revoke it and issue a
> new one. Grant only the scopes you need (`repo`, plus `workflow` if
> you choose Option B).

### After it is added

Turn on branch protection, which is what actually enforces the gate:

**Settings → Branches → Add rule**, branch name pattern `main`:

- ☑ Require a pull request before merging
  - Required approvals: **1** (you have 2-5 people)
- ☑ Require status checks to pass before merging
  - Select: `Analyze and test (Flutter)`, `Build release APK`,
    `Lint PHP`, `Repo hygiene`
- ☑ Require branches to be up to date before merging

The status checks only appear in that list **after the workflow has run
at least once**, so add the workflow first, then set the rule.

From then on, a red check makes the merge button unavailable. That is
the entire mechanism — the gate is not advisory.
