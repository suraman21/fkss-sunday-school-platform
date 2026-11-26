# 06 — First tasks, in order

Each step is small, verifiable, and needs no decision from anyone else. Items
that need a product decision are in [05](05-OPEN-DECISIONS.md) and are not
repeated here.

---

## 1. Move the folder to its own repository

The `members/` folder in `fkss-sunday-school-platform` is a **staging area**. It
is not part of the platform's tree and nothing in the platform references it.

```bash
# from a clean checkout of fkss-sunday-school-platform
cp -a members /tmp/members-app && cd /tmp/members-app
git init && git add -A && git commit -m "Structure for the members web app"
git remote add origin <new-repo-url> && git push -u origin main
```

Then delete `members/` from the platform repository in its own pull request. Its
diff against `main` is exactly: `members/` plus the archived prototype under
`docs/prototypes/` — nothing else touches the platform, so the removal is
mechanical and safe.

Checklist for the new repository:

- [ ] `.gitignore` — `.fkss_env.php`, `*.log`, `.DS_Store`
- [ ] Everything documented is inside; the handbook's links resolve from the
      new root (they are all relative within `docs/`)
- [ ] CI: `php -l` on 8.4 **and** 8.5 with `-d error_reporting=-1`, failing on
      `Deprecated:` (the platform lints both, because production has run both)
- [ ] `README.md` at the root: one paragraph plus a link to `docs/HANDBOOK.md`

---

## 2. Confirm the deployment target

Answer the four questions in
[05 §6](05-OPEN-DECISIONS.md#6-deployment-target) before writing an entry point,
because they decide whether `members/` is the document root or needs `public/`:

- subdomain name
- Apache or nginx
- is HTTPS provisioned
- can the app read a config file **outside** the document root

If Apache: the app needs its own `.htaccess` with `Options -Indexes`, no
`RewriteEngine` until there is a reason, and `Authorization` passthrough only if
the app ever proxies to the API.

---

## 3. Frontend scaffold from the prototype

The prototype's frontend is the reusable half of that work. Verified:
`fk-theme.css` (1058 lines), `style.css` (2445), `fk-ui.js` (505),
`session.js` (50) — **zero external dependencies**, no CDN, no build step. It is
plain HTML + CSS + vanilla JS and can be dropped into this app as-is.

### 3.1 What to take

| From `docs/prototypes/member-app-2026-11-26/frontend/` | To | Why |
|---|---|---|
| `css/fk-theme.css` | `assets/css/fk-theme.css` | The school's palette is defined there (`--fk-gold`, `--fk-wine`, …) and is not in the platform's own themes |
| `style.css` | `assets/css/style.css` | Page-level layout. Review before taking wholesale — it was written alongside the prototype's own markup |
| `js/fk-ui.js` | `assets/js/fk-ui.js` | UI helpers, 505 lines, no dependencies |
| The markup of `login.html`, `student-dashboard.html` | `templates/pages/` | The two pages whose data the API actually has |

### 3.2 What not to take

| Skip | Why |
|---|---|
| `js/session.js` | Its auth is the prototype's own (`/api/auth/login`, `/api/auth/staff-login`). **Replace, do not adapt** — see §3.4 |
| `quiz-me.html`, `lesson.html`, `lessons.html`, `exam-prep.html`, `progress.html`, `tasks.html` | No API behind them ([03 §7](03-API-INTEGRATION.md#7-what-the-prototype-expected-vs-what-exists)). Their markup is still useful as a visual reference |
| `staff.html`, `change-password.html` partly | `staff.html` is a staff surface; `change-password.html` is reusable once pointed at `/users/change-password` |
| `backend/`, `database/` | Never. Own tables, colliding migration numbers, no CSRF ([ARCHIVE.md](../../docs/prototypes/member-app-2026-11-26/ARCHIVE.md)) |

### 3.3 Order inside the scaffold

1. `templates/layouts/base.php` — the shell (head, nav, footer) with the theme's
   CSS classes.
2. `src/Http/Router.php` — a finite route table mapping path → template. No
   request-derived file paths, ever.
3. `src/View/Template.php` — render a page inside the layout, with an escaper and
   prepared data only.
4. `templates/pages/` — start with `sign-in`, `error`, `activation`, then
   `children`.
5. `assets/` — drop in the theme CSS, then delete rules that reference markup you
   did not take.

Stop after step 5. The scaffold has no token handling and no API calls; that is
the next task, and it depends on the CORS and token-storage decisions.

### 3.4 `session.js` must be replaced, not adapted

The prototype's session helper signs in against its own backend and keeps the
result in its own storage. The real flow is
[03 §3](03-API-INTEGRATION.md#3-authentication) and it differs in three
load-bearing ways:

1. **single-flight refresh** — refresh tokens are one-time; two concurrent
   refreshes sign the user out (`REFRESH_REUSED`)
2. **`activation_pending`** — a 403 at login that means "finish device setup",
   not "wrong password"
3. **DPoP** — for a device-bound session every request needs a proof, and the
   proof differs between refresh and normal requests
   ([03 §6.5](03-API-INTEGRATION.md#65-dpop-the-part-that-will-surprise-you-later))

---

## 4. Then: the API client

`Repository/` is the only place that may contain `/api/v1` strings.

1. `ApiClient.php` — one method per HTTP verb; attaches `Authorization` and, when
   present, `DPOP`; parses the envelope
   ([03 §2](03-API-INTEGRATION.md#2-response-envelope)); throws `HttpException`
   carrying status + message + code.
2. `AuthRepository.php` — login, refresh, logout, verify.
3. `ProfileRepository.php` — `/users/me*`.
4. `MembersRepository.php` — `/members/{id}`, `/members/{id}/attendance`,
   `/users/me/children`.
5. `AcmRepository.php` — the activation ceremony, including the DER conversion.

Test the envelope parser against: success with no `data`, success with
`message`, error with `code`, error without `code`, non-JSON body (an HTML error
page from a misconfigured host), and `429` with `Retry-After`.

---

## 5. Before declaring any of it done

- [ ] No `/api/v1` string outside `src/Repository/`
- [ ] No token in a URL, a log line or a template
- [ ] Every rendered value escaped; every page with personal data sends
      `Cache-Control: no-store`
- [ ] An unknown path renders a 404 page, not a stack trace
- [ ] `php -l` clean on 8.4 and 8.5
- [ ] The handbook is still true — edit
      [05](05-OPEN-DECISIONS.md) when a decision lands
