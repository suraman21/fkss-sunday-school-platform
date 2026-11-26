# Handoff — members app separation, 2026-11-26

Everything needed to continue the members web app in a **new chat**, with no
history from the conversation that produced it.

Read this page, then run the one command in §2.

---

## 1. Where things stand

| Thing | State |
|---|---|
| Platform repo `suraman21/fkss-sunday-school-platform` | ✅ `members/` removed — PR #90, merge `2d6e308` |
| Members app repo `suraman21/ssms-member-app` | ⚠️ **Read-only from this session.** Holds only the prototype, commit `61d50b4` |
| The prepared structure + archive commit | ✅ Exists, and is in this folder as a patch (§2) |
| Frontend scaffold | ⏸ Not started — needs §2 applied first |

**The one blocker:** this Arena session cannot write to `ssms-member-app`. The
installation reports `repository_selection: all` and repo `permissions.push:
true`, yet `git push` returns `403` and the branch-creation API returns
`Resource not accessible by integration`. That is Arena binding git access to
the session's own repository, not a permissions problem in your GitHub account.
No credential fixes it; only a session that belongs to the app repo does.

## 2. Apply the prepared work

The complete commit is `structure-and-archive-prototype.patch` in this folder.
It is a normal `git am` patch — it carries the author, the message and the full
rename history.

```bash
git clone https://github.com/suraman21/ssms-member-app.git
cd ssms-member-app

# git am refuses to run without an identity — set one first if the machine
# has none, or it fails with "empty ident name"
git config user.name  "your name"
git config user.email "you@example.com"

# must be at 61d50b4 first
git log --oneline -1

git am /path/to/structure-and-archive-prototype.patch
```

Expected: `Applying: Archive the prototype and lay down the app structure`, then
`80 files changed, 1491 insertions(+)`.

Rename history survives even though `git show --stat` prints no `rename` lines
(patch text has no rename headers). Proof, both checked on a fresh clone:

- `git log --follow -- frontend/js/session.js` in the archive traces back to
  `61d50b4 inital base`
- `git blame` on the moved file still attributes lines to `inital base`
- original and moved copy have the same md5

`git mv` at commit time does the detection; the patch does not need to declare
it.

Then push and open the PR:

```bash
git push -u origin HEAD:setup/structure-and-archive-prototype
```

If `git am` fails on a context mismatch, fall back to:

```bash
git apply --3way --directory=. structure-and-archive-prototype.patch
```

**Verify after applying** — these four checks were run before the patch was cut:

- `ls` at the root shows `README.md .gitignore src templates assets docs`
- `ls docs/prototypes/member-app-2026-11-26/` shows
  `ARCHIVE.md backend database docs frontend tests uploads`
- `python3 -c` link check: every relative Markdown link and `#anchor` resolves
  across the 9 first-party docs
- `git log --follow .../frontend/js/session.js` traces back past the move

## 3. What the patch does

1. **Archives the prototype.** All six root folders (`backend/`, `database/`,
   `docs/`, `frontend/`, `tests/`, `uploads/`) move to
   `docs/prototypes/member-app-2026-11-26/` with `git mv`. One folder, so the
   root stays readable.
2. **Lays down the structure** at the now-empty root:
   `src/{Auth,Http,Repository,Service,Support,View}`,
   `templates/{layouts,pages}`, `assets/{css,js,favicon}`, `docs/`.
3. **Adds `docs/00-START-HERE.md`** — the cold-start page (§4).
4. **Rewrites paths in the docs** for root-relative layout, marks
   `06 §1` done, and restates HANDBOOK correction 3 as fact.

**No behaviour.** No entry point, no routing, no auth, no CSS.

## 4. Read next, in the app repo

`docs/00-START-HERE.md` is the short version and is also copied into this folder
for convenience. In full:

| # | File | Why |
|---|---|---|
| 1 | `00-START-HERE.md` | Orientation in five minutes |
| 2 | `HANDBOOK.md` | Index + three corrections to earlier conversation |
| 3 | `01-SYSTEM-CONTEXT.md` | What the platform is, who the users are |
| 4 | `02-FOLDER-STRUCTURE.md` | The tree and the layers |
| 5 | `03-API-INTEGRATION.md` | The full API contract — the long one |
| 6 | `04-CONVENTIONS.md` | How code in here is written |
| 7 | `05-OPEN-DECISIONS.md` | **Unanswered questions — check before building** |
| 8 | `06-FIRST-TASKS.md` | Ordered task list; §1 done, §2 is next |

## 5. The constraints a new chat gets wrong first

1. **No platform file is ever `require`d.** Integration is HTTP only.
   `src/Repository/` is the only layer that may call the API.
2. **The platform has no member self-path.** `member` is in no allow-list, so
   `GET /members/{id}` is 403 for a member token. Only `parent` works, via
   `apiParentChildren()`. This is the **headline blocker**.
3. **CORS blocks the members subdomain.** `handleCors()` allows only
   `SITE_URL`, `http://SITE_DOMAIN`, `https://www.SITE_DOMAIN`. The fix belongs
   in the **platform** repo, not the app repo.
4. **Refresh tokens rotate.** A concurrent second refresh revokes the whole
   family — refresh must be single-flight.
5. **The prototype's `backend/` and `database/` are not adopted.** Parallel API,
   second `staff`/session model, migrations colliding with `sql/001–098`.
6. **`frontend/js/session.js` must be replaced, not adapted** — it calls the
   prototype's dead API.

## 6. Next task — the scaffold

`06-FIRST-TASKS.md` §2 blocks the scaffold on the **deployment target**, which
is unanswered in `05-OPEN-DECISIONS.md §6`:

- subdomain name
- Apache or nginx
- is HTTPS provisioned
- is the repo root the document root, or is a `public/` layer needed

Answer those four first, because they decide whether entry files sit at the root
or one level down. Then scaffold from
`docs/prototypes/member-app-2026-11-26/frontend/` — markup, CSS and interaction
patterns only.

## 7. Loose ends in the platform repo

- `docs/prototypes/member-app-2026-11-26/` still exists there deliberately, so
  the prototype is readable from either side until the app repo has it. Remove
  it once §2 is applied and verified.
- **A GitHub token was pasted into a chat on 2026-11-26.** It must be revoked at
  <https://github.com/settings/tokens> if that has not been done. It is not used
  by anything in this folder and must not be added to any file.
