# Prompt for a new chat opened on `suraman21/ssms-member-app`

Paste everything between the rules. It is written to be the **first message** in
that chat, with no other context. It assumes the new session has write access to
that repository, which this session did not.

---

You are continuing work on the **members (students) and parents web app** of the Felege Kidusan Sunday School. This repository is the app's own repo, separate from the platform (`suraman21/fkss-sunday-school-platform`). You have full read/write access here.

**Do not ask me questions about the project's background first — it is all written down in the repo.** Read the docs, then act.

## Step 1 — Apply one prepared commit

The app structure is already finished and sitting as a patch in the *platform* repository. Get it and apply it:

```bash
# must be at this commit — if not, stop and tell me
git log --oneline -1        # expect: 61d50b4 inital base

# git am needs an identity; set one if missing
git config user.name  "…"
git config user.email "…"

# fetch the patch from the platform repo (read access is public)
gh api "repos/suraman21/fkss-sunday-school-platform/contents/docs/handoff/2026-11-26-member-app/structure-and-archive-prototype.patch?ref=arena/750bac56-fkss-sunday-school-platform" \
  --jq '.content' | base64 -d > /tmp/structure.patch

git am /tmp/structure.patch
git push -u origin HEAD:setup/structure-and-archive-prototype
```

Expected: `Applying: Archive the prototype and lay down the app structure`, then **80 files changed, 1491 insertions**. If the SHA is not `61d50b4`, or `git am` conflicts, stop and report rather than `git apply`-ing over it.

If `gh` is unavailable, try `git fetch --depth=1 https://github.com/suraman21/fkss-sunday-school-platform.git arena/750bac56-fkss-sunday-school-platform && git show FETCH_HEAD:docs/handoff/2026-11-26-member-app/structure-and-archive-prototype.patch > /tmp/structure.patch`. If network access is limited to certain hosts, say so instead of improvising.

**Verify before moving on** — all four must pass:

- root is exactly `README.md .gitignore src templates assets docs`
- `docs/prototypes/member-app-2026-11-26/` contains 57 files across `backend database docs frontend tests uploads`, plus `ARCHIVE.md`
- `git log --follow` on `docs/prototypes/member-app-2026-11-26/frontend/js/session.js` traces back to `61d50b4 inital base` (history must survive the move)
- the PR is open and green

Then tell me the PR URL and **stop**. Do not start scaffolding in the same turn.

## Step 2 — After I say go: read before building

Read, in this order:

1. `docs/00-START-HERE.md` — orientation
2. `docs/HANDBOOK.md` — index plus corrections to earlier conversation
3. `docs/03-API-INTEGRATION.md` — the full API contract (the long one)
4. `docs/05-OPEN-DECISIONS.md` — **unanswered questions; check before building**
5. `docs/06-FIRST-TASKS.md` — ordered task list

## The facts that will otherwise lead you wrong

1. **No platform file is ever `require`d.** No `config.php`, no `admin/backend/*`, no shared session. Everything arrives as JSON over HTTP. `src/Repository/` is the **only** layer allowed to make those calls.
2. **The platform has no member self-path.** `GET /members/{id}` returns 403 for a member token — the `member` role is in no allow-list. Only `parent` works, via `apiParentChildren()`. This is the app's headline blocker; read `03 §5` before designing a member-facing screen.
3. **CORS blocks the subdomain today.** `handleCors()` on the platform allows only `SITE_URL`, `http://SITE_DOMAIN`, `https://www.SITE_DOMAIN`. Adding the members subdomain is a change in the **platform** repo — you cannot make it from here.
4. **Refresh tokens rotate.** Access 900 s, refresh 90 d, single-flight. A concurrent second refresh revokes the entire token family.
5. **The archived prototype is reference only.** `docs/prototypes/member-app-2026-11-26/backend/` and `database/` are **not** adopted: parallel API, a second `staff`/session model, migrations colliding with the platform's `sql/001–098`. Only `frontend/` is reusable.
6. **`frontend/js/session.js` must be replaced, not adapted.** It calls the prototype's dead API.
7. **Do not invent endpoints.** `03` lists what exists, read from platform source. Anything absent from that table does not exist — the prototype's ~29 `/api/…` paths are its own discarded backend, and lessons/quizzes/progress/tasks/past-papers/verses/permissions do not exist on the platform at all.

## Step 3 — Respect these boundaries

- **The scaffold is blocked** on `05 §6`: subdomain name, Apache or nginx, is HTTPS provisioned, and whether the repo root is the document root or a `public/` layer is needed. Ask me these four before writing an entry point.
- **Do not add a `public/` layer by default.** Entry files go at the repository root unless §6 says otherwise; reasoning is in `02 §5`.
- **Keep `docs/` for the handbook.** The prototype's docs live under `prototypes/`; do not merge them back.
- **Do not touch the platform repo.** If something needs changing there (CORS, a member self-route), report it; do not attempt it.
- **One concern per PR**, with a message that says what changed and why.

Start with Step 1 now.
