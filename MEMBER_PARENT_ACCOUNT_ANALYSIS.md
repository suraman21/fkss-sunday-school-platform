# Member & Parent Accounts — Deep Analysis for the Members Web + Mobile Build

**Scope:** the `member` and `parent` account types end to end — how an account is created, how it authenticates, what it is authorized to do, what data it can reach, and what is missing before a Members app and a Parent app can ship.
**Method:** source-only, no running database, no assumptions from names or docs. Every claim carries a file + symbol.
**Confidence key:** `VERIFIED` = read in source and traced to callers · `HIGH CONFIDENCE` = multiple corroborating sites · `INFERENCE` = likely but not proven · `UNKNOWN` = not determinable from source.
**Supersedes nothing:** the broad report stays at `SYSTEM_KNOWLEDGE_REPORT.md`; this file goes deep on one subsystem.

> **Prompt compliance note.** Nothing in the repository was modified. No fixes were applied to the findings below, including the ones I would strongly recommend fixing before the build starts.

---

## 0. Executive Summary

The account *plumbing* for members and parents is **substantially built and unusually well engineered** — better than the surrounding staff system in several places. The account *product* is **not built at all**.

Specifically:

| Layer | State | Evidence |
|---|---|---|
| Schema for persons / accounts / tickets / devices | **Exists, 18 `acm_*` tables** | `sql/079`–`sql/091` |
| Account provisioning (member + parent) | **Exists, transactional** | `acm/src/Accounts/AccountProvisioner.php` |
| Activation ceremony (code → credential → device) | **Exists, 4 routes + 4 device routes** | `api/v1/routes/acm.php` |
| Suspension / reactivation / deactivation | **Exists, audited** | `AccountProvisioner::suspend/reactivate/deactivate` |
| Parent → child authorization resolver | **Exists, deny-by-default** | `api/v1/core/acl.php` `apiParentGuardianId` |
| **Member (student) self-service authorization** | **Does not exist** | no resolver anywhere; §4.3 |
| **Any endpoint a member account is designed to call** | **Does not exist** | §5, §6 |
| **Published API contract for the ceremony** | **Does not exist** | `openapi.yaml` has zero `acm` paths, no `/users/me/children` — §10 |
| **Web UI for either role** | **Does not exist** | §7 |
| **Mobile app support for either role** | **Does not exist** | §8 |

**The single most important finding:** a provisioned `member` or `parent` account can authenticate successfully and then has **no safe home**. It is not in `$ROLE_MAP` (116 entries, zero for either role) and not in the Flutter role switch — which falls through to `TeacherHomeScreen`, whose first action is `GET /dashboard/stats`. [At the time of the audit that endpoint had **no role gate**, so the account's landing screen rendered school-wide statistics, and `/classes` was gated only by a two-role deny-list and likewise answered. A student token could enumerate the full class list and any class's roster; a parent token saw the school's member counts, today's attendance and the staff activity log. **This was fixed on 2026-11-26** — all three routes now call `apiRequireStaffRole()`, an allow-list gate, backed by 10 new regression tests. See §5.3, §9.1 and §13.] The *structural* finding stands regardless: these accounts still have no endpoint of their own to land on. See §8.1.

**Second most important:** the parent path is real but narrower than the code's own comments imply. There are **two** purpose-built parent reads — `GET /users/me/children` and `GET /members/{id}` — plus profile/password. `GET /members/{id}/attendance` is **denied to parents** by an omitted clause (§5.1), which is the one endpoint a parent app would show on its first screen. Everything else a parent app needs (grades, timetable, fees) is correctly role-blocked; announcements and notifications are reachable for the wrong reason — because `/notifications/*` has no role gate at all (§5.3). See §5.

**Third — and the one that decides the project plan:** the ACM ceremony **cannot be completed by any client that exists**. The only code path that sets `users.is_active = 1` for these accounts requires an Android/WebCrypto keystore keypair signing a server challenge (`DeviceService::completeFull`, pinned as an invariant by `test_acm_pending_link_fix.py`), and the shipping Flutter app contains **zero** activation, keypair, or DPoP code — verified by repo-wide grep. The ceremony is reachable today only by hand-crafted HTTP or the destructive CLI walks in `tests/e2e/`. The enforcement layer was built ahead of the client that would use it, and the docs call the app "locked" / "on hold" without recording that the *server* is now waiting on it. See §8.2-8.3.

**A fourth finding, about the safety net rather than the product:** the ceremony's *cryptography* is the best-tested code in this subsystem — PHPUnit round-trips real ES256/RS256 signatures on both the per-request and refresh-time proofs (`acm/tests/AccessProofTest.php`, `RefreshProofTest.php`) and CI runs them (`acm-contracts` job). But the ceremony's *database transitions* are exercised only by eight destructive `tests/e2e/acm_*_walkthrough.php` scripts that **no CI job invokes** (§13), and the policy matrix those proofs defend **does not authorize the member or parent role for anything at all** (`AcmPolicy::MATRIX` has one role key, `accounts_office`). So the DPoP layer is verified against a set of permissions the two roles will never hold. That mismatch is not currently a bug — nothing authenticates these roles through `PolicyAuthorizer` yet — but it is the seam that will decide what "signed in as a parent" *means* once the app exists. See §4.5 and §13.

---

## 1. The two account types, precisely defined

### 1.1 Where the role lives

`users.role` is a **VARCHAR(30)**, not an ENUM, converted by `sql/001` L10-11 and `sql/002` L13-14 from the original six-value ENUM (`sql/legacy/database_schema.sql` L28). Consequence: **the database does not constrain the role set.** Every allow-list is PHP-side, and they disagree with each other (§10).

`member` and `parent` were added to that free-form column by the ACM foundation, not by a migration that touches `users`:

```
sql/079_acm_p0_foundation.sql:66   acm_activation_tickets.account_type  ENUM('member','parent')
sql/079_acm_p0_foundation.sql:49   acm_person_accounts.account_type     ENUM('staff','member','parent')
```

**`staff` is only reachable in `acm_person_accounts`** — `AccountProvisioner::provision()` is the sole INSERT into that table (`acm/src/Accounts/AccountProvisioner.php:107`) and it writes `:at => $accountType` from the *ticket*, whose enum is `('member','parent')` (`sql/079:66`). `AcmTicketService::create()` independently asserts `'Account type must be member or parent.'` (`AcmTicketService.php:71`). So the `staff` enum value on `acm_person_accounts` is **dead in the current code** — no writer can produce it. (Staff device enrolment uses a separate table, `acm_staff_enrollment_requests`, `sql/091`.)

### 1.2 The three-table account identity

A member/parent account is never one row. It is three, linked by application convention — **`sql/079` declares no FOREIGN KEYs at all** (header comment, L6-7):

```
acm_persons            (the human; may link to members.id and/or guardians.id)
   └── acm_person_accounts  (person ↔ users, account_type, status)
          └── users          (the login; role = account_type)
```

Status lives in **two** places that must agree: `acm_person_accounts.status` (`pending|active|suspended|revoked`) and `users.is_active` (the gate every login path reads). Keeping them in sync is the entire job of `suspend`/`reactivate`/`deactivate` (§3), and the code explicitly names the drift state (`isSuspendedState()` accepts "active link + gated bit", `AccountProvisioner.php:381-388`).

### 1.3 Parent identity resolves through a *fourth* link

`apiParentGuardianId()` (`api/v1/core/acl.php:126-153`) walks:

```
users.id → acm_person_accounts.user_id → acm_persons.guardian_id
        requiring account_type='parent' AND status='active'
```

Then children come from `member_guardians` filtered on **`verified_by IS NOT NULL`** (`apiParentCanSeeMember` acl.php:159-178, `apiParentChildren` acl.php:180-214). So a parent's reach is *not* "my linked children" — it is **"children whose guardian link an officer has stamped"**. Unverified links are invisible, silently, by design (`acl.php:122-126` comment: "deny by default: no guardian, no children, never an error to the client").

That verification stamp has exactly one *explicit* entry point: `GuardianService::verifyLink()` called from `admin/api_guardians.php:297`. Derived links leave it NULL by design (`GuardianService.php:408-412`) — but they still record `created_by` from `$_SESSION['admin_id']`, and **`sql/092` retroactively stamps `verified_by = created_by` for every link with `created_by > 0`** (one guarded UPDATE, no DDL):

```sql
UPDATE member_guardians SET verified_by = created_by, verified_at = created_at
 WHERE verified_by IS NULL AND created_by > 0;
```

So on any database where `sql/092` has been applied, every guardian link created through a logged-in staff action is already treated as verified. That is the *good* case and I believe it is the intended one — it means a parent account provisioned today sees their children immediately, including siblings. Whether 092 has been applied in production is **UNKNOWN** (no DB access). If it has not, **every existing parent account would see an empty children list**, which is exactly the kind of silent failure the resolver's "deny by default" comment predicts and would present as "the app is broken".

---

## 2. Provisioning — the real workflow, traced

### 2.1 Precondition: a person record must exist first

Nothing auto-creates `acm_persons` from `members`. There is **one** writer, `AcmPersonService::create()` (`acm/src/Identity/AcmPersonService.php:47`), reachable only from the ACM console (`admin/api_acm.php:218` `person_create`, gated `AcmPolicy::can($role, 'person', 'create')` — `super_admin` or `accounts_office`).

There is **no bulk import and no backfill** of existing members into `acm_persons`. `grep 'INSERT INTO acm_persons'` outside tests returns exactly the one service line. `admin/tools/` contains `guardian_backfill.php`, `guardian_rematch.php`, `migrate_identity_codes.php` — none touches `acm_persons`.

> **Consequence for the build:** onboarding the current member body to the Members app is, as of today, **a manual one-person-at-a-time console job**. At 5,000+ students (the stated design target, PRD NFR-PRF-01) that is not a launch plan. This is the largest *unbuilt* piece and it is not mentioned in any doc.

**What is *not* missing** (worth stating, because it changes the size of that job): the underlying `members` and `guardians` data for parents already exists and is already linked. `GuardianService::attachFromLegacy()` is called from the three member-creation paths — `hr_register_member.php:668`, `info_manage_member.php:315`, and `api_guardians.php:119` (`attach_member`) — plus `GuardianService.php:897` (backfill) and `guardian_backfill.php:237` (tool). It match-or-creates a `guardians` row from the legacy `members.guardian_*` columns and links it. So a parent account needs only `acm_persons` + a ticket + the ceremony; the family graph is already there. **Only `acm_persons` and the ceremony are manual.**

### 2.2 Ticket creation

`AcmTicketService::create()` (`acm/src/Accounts/AcmTicketService.php:58-140`), reached from console `ticket_create` (`admin/api_acm.php:248`). Validations, in order:

1. authenticated `submitterId > 0`
2. person exists
3. `account_type ∈ {member, parent}` (L71)
4. **`member` requires `member_id`** (L76) · **`parent` requires `guardian_id`** (L79)
5. the linked member/guardian row must exist (L82-87)
6. no duplicate open ticket for this person+type in `submitted|in_review|changes_requested` (L89-101)

Then it inserts `status='submitted'`, builds `ticket_no = 'ACM-<Ymd>-<id padded 4>'`, writes an event, and sets `sla_due = NOW() + 48h` (`SLA_HOURS = 48`, `AcmTicketService.php:36`). **Nothing verifies that the person's `member_id` matches the ticket's `member_id`, or that the person's `guardian_id` matches the ticket's `guardian_id`** — the two are independent inputs. That is a plausible mis-link path; see §9.4.

### 2.3 Approval and code issuance

`decide` (`admin/api_acm.php:310`) → approve/reject/request_changes, with **maker-checker** enforced by comparing **user IDs, not role titles** (`AcmPolicy` header comment; `AcmTicketService` self-approval flag). `code_issue` (`api_acm.php:506`) mints a selector+secret pair; the secret is shown **once** and stored as `password_hash` (`ActivationService::verifyCode` uses `password_verify` on `$code['secret_hash']`, `ActivationService.php:278`).

Code TTLs are per-purpose (`ActivationService.php:62`):

```
activation → 30 min   device_add → 1440 min   reset → 1440 min
```

> **The 30-minute activation window is the single biggest product risk in this design.** The applicant must complete: redeem code → choose password → register a device with a keypair → sign a challenge. If the client app cannot do steps 3-4 (§8.3), the code expires and the whole ticket needs re-issuance. `CODE_TTL_MIN['activation'] = 30` is defensible for an office-desk ceremony and hostile for a home user on 2G.

### 2.4 Provisioning

`ActivationService::establishCredentials()` (`ActivationService.php:~380-470`) in one transaction:

1. `UPDATE acm_activation_grants SET consumed_at=NOW() ... WHERE consumed_at IS NULL` → `rowCount() !== 1` ⇒ `grant_consumed`. Atomic single-use; concurrent losers land on the same code. (L415-425)
2. `getForUpdate` the ticket; must be `grant_verified`.
3. `hasAccount()` guard → if the person already holds a pending/active/suspended link of this type, transition to `activation_failed`/`account_exists`, **commit the failure**, then throw (L438-450). Comment: "Never auto-merge, never duplicate."
4. `AccountProvisioner::provision()`
5. `markAccountPendingDevice()`
6. idempotency key persisted

`AccountProvisioner::provision()` (`AccountProvisioner.php:71-120`):

```
INSERT INTO users (username, email, full_name, role, password_hash, is_active, member_id)
VALUES (:u, :e, :fn, :r, :ph, 0, :mid)
```

- **`role` = `account_type`** — i.e. the literal strings `'member'` / `'parent'` (`:84`, `:88`)
- **`is_active = 0`** — the account is created *disabled*
- `member_id` set only for `member` accounts (`:75`)
- username: `m<member_code>` for members, `p<phone_n9>` for parents, else `m|p<person_id>` (`buildUsername`, `:479-490`)
- collision: up to 5 attempts, re-probing `nextFreeUsername()` each round, on SQLSTATE 23000; **a lost *email* race degrades the email to NULL** rather than failing (`:92-104`). Documented trade-off; means a parent can silently end up with no email on file.
- then `INSERT INTO acm_person_accounts (..., status='pending', ...)`.

**The account cannot log in at this point** — `is_active = 0`, and every login path rejects that (§3.1).

### 2.5 Device registration is what activates the account

`DeviceService::completeFull()` (`acm/src/Device/DeviceService.php:274-500`), on `operation === 'activation'` (L462):

```php
UPDATE users SET is_active = 1 WHERE id = :u AND is_active = 0        // :467
UPDATE acm_person_accounts SET status='active' WHERE user_id = :u
       AND status = 'pending'                                          // :479
```

The comment at L463-464 is explicit: **"The ONLY path that flips users.is_active"** for these accounts. I verified this: the only other `SET is_active = 1` on `users` in the whole tree are `AccountProvisioner::reactivate()` (`:268`) and this one.

So the ceremony is **code → password → device → active**. There is no "activate without a device" path for members or parents. **A member who loses their phone before enrolling a second one cannot log in at all** — recovery requires an officer-issued reset code (`acm/reset/verify` + `acm/reset/credentials`) which *does* work without a device (no `installation_id` in that route, `api/v1/routes/acm.php:261-296`).

---

## 3. Authentication as it actually behaves for these two roles

### 3.1 Mobile / API login — `POST /api/v1/auth/login`

`api/v1/routes/auth.php:60-178`. Order of checks:

1. IP rate limit 10/min (`isApiRateLimited('auth_login', 10)`)
2. `username` **OR** `email` lookup, `LIMIT 1` (L76)
3. `password_verify` — same generic message for unknown user and bad password (L84)
4. **`is_active !== 1` ⇒ 403 "Your account is inactive. Contact an administrator."** (L87-89)

Note asymmetry with the web login, which returns 401 for bad credentials but 403 for inactive (`admin/backend/login.php:88-91`) — same behaviour, and both leak "this account exists but is disabled" to anyone who guesses a username. Minor enumeration vector; consistent across both surfaces so at least it is deliberate.

5. Optional S4 device assertion: `installation_id` + `device_fingerprint` → `DeviceService::findActiveForAssertion()`; a resolved assertion yields a **bound** refresh family (`device_bound: true`), which then requires a DPoP proof on **every** API call (L362-393 in `api/v1/core/auth.php`).
6. `RefreshTokenService::issue()`; failure ⇒ 503 `AUTH_SERVICE_UNAVAILABLE`
7. `UPDATE users SET last_login = NOW()`
8. `logApiAction(..., 'API Login', 'REST API 1')`
9. Returns `token`, `refresh_token`, `expires_in`, `device_bound`, `user{ id, username, full_name, email, role, is_active, profile_image, profile_version, authorization_version }`

**Token shape** (`createToken`, `api/v1/core/auth.php:47-83`): `base64(json).HMAC-SHA256`, claims `uid, usr, rol, nam, av, iat, exp, typ, jti` plus optional `fid`/`rsid` family binding. **Not a JWT** — no header segment, no `alg` negotiation.

**TTLs** (`api/v1/core/auth.php:11-27`):

| Const | Value | Meaning |
|---|---|---|
| `API_TOKEN_EXPIRY` | 900 s | 15-min access token |
| `API_REFRESH_EXPIRY` | 90 d | rotating refresh session |
| `API_LEGACY_TOKEN_EXPIRY` | 30 d | for builds < 16 (compat window ended 2026-09-24) |
| `API_AUTHZ_SCOPE_CLIENT_BUILD` | 24 | build that revalidates role/version per request |
| **`API_AUTHZ_LEGACY_COMPAT_UNTIL`** | **`PHP_INT_MAX`** | **authorization-scope revalidation is effectively opt-in per client build** |

That last row matters. `apiRevalidateAuthorizationScope()` short-circuits unless the client sends `X-App-Build >= 24` (`auth.php:203-207`). A hand-rolled client that omits the header, or sends a low value, **skips role/authorization_version revalidation entirely** for the token's 15-minute life. Not a vulnerability in itself (15 min is short), but it means "we revalidate every request" is not true by default — it is true only for clients that self-identify.

### 3.2 Web login — `admin/backend/login.php`

Same credential logic over PDO (`:79-91`), plus:

- CSRF required (`:45`)
- rate limit **20/5 min per IP and 5/5 min per account** (`:62-70`)
- on success: `session_regenerate_id(true)`, `$_SESSION = []` reset, then `admin_logged_in, admin_id, admin_username, admin_role, admin_full_name, LAST_ACTIVITY, AUTH_STARTED_AT, AUTH_REVALIDATED_AT, AUTH_PASSWORD_VERSION, PROFILE_ACCOUNT_CONTEXT`, new CSRF (`:93-113`)
- audit row into `activity_logs`, **swallowed on failure** ("Audit-table downtime must not prevent login", `:109-111`)

**`admin_role` is copied verbatim from `users.role`** — there is no role allow-list at login. So a `parent` **can** successfully POST the web login form and get a session. What happens next is §5.4: every admin page denies them, and the fallback dashboard router has no branch for them.

### 3.3 Session lifetime and revalidation

`config.php`: `SESSION_TIMEOUT = 1800` (L147) checked against `LAST_ACTIVITY` (L173-207); `AdminSessionGuard` every 300 s with an absolute 8 h cap (`AdminSessionGuard.php:12-13`), which re-reads `role`, `is_active`, and `password_hash` (`:59-70`) and invalidates on disable or password change. **It also reconciles `admin_role` from the DB on every revalidation** (`:120-124`) — so a role change takes effect within 5 minutes even mid-session. This is genuinely good.

`AdminSessionGuard::KNOWN_ROLES` (`:21-24`) is a 10-role list that **does not include** `member` or `parent`. That only matters inside the impersonation branch (`:84-105`), which requires `original_admin_role ∈ {super_admin, school_admin}` — a parent cannot originate impersonation. **No reachable defect**; it is a latent trap for whoever adds member impersonation (§11).

---

## 4. Authorization — the exact permission surface

### 4.1 Role maps: three of them, and they disagree

| Map | Location | Roles | Contains `member`/`parent`? |
|---|---|---|---|
| Web page guard | `admin/access_control.php:96-295` | 116 page keys, 16 roles | **No** (verified by counting the `$ROLE_MAP` literal) |
| Flutter mirror | `Mobile/.../lib/utils/config.dart:39-53` `UserRoles` | 12 roles | **No** |
| Notification centre | `admin/backend/services/NotificationCenterService.php:35-49` `ROLE_LABELS` | 13 roles | **No** |

And the *authoritative* role list for user administration, `admin/backend/user-save.php:90-115` `$validRoles`, is a **17th** list that also excludes them — deliberately, with a comment: *"member and parent login roles are deliberately NOT listed — they are provisioned only by approved activation tickets."*

**That decision is correct and is the reason the system is safe today.** The absence of `member`/`parent` from every human-maintained list is what keeps them out of the console. But it also means: **every one of those lists is a future failure point.** There is no single source of truth for "what roles exist", and `config.php`'s `getUserRole()` returns whatever is in the session.

### 4.2 The API ACL — the only real policy layer for these roles

`api/v1/core/acl.php`. Helper functions, all pure allow-lists:

```
apiRolesPii()        = info_dept, school_admin, super_admin
apiRolesEducation()  = edu_dept, school_admin, super_admin
apiRolesAttendance() = teacher, attendance_taker, edu_dept, school_admin, super_admin
apiRolesGrades()     = teacher, edu_dept, school_admin, super_admin
```

`member` and `parent` appear in **none** of them. They appear in exactly two places in the whole ACL file:

```
acl.php:126   apiParentGuardianId()      — ['parent'] only
acl.php:159   apiParentCanSeeMember()    — delegates to the above
```

There is **no `apiMemberCanSeeSelf()`** and **no member branch anywhere**. §4.3.

### 4.3 The parent resolver, traced

`apiParentCanSeeMember($conn, $auth, $memberId)` (`acl.php:159-178`):

```sql
SELECT 1 FROM member_guardians
 WHERE guardian_id = ? AND member_id = ? AND verified_by IS NOT NULL
```

Composite PK `(member_id, guardian_id)` (`sql/069`), so this is an index seek. Correct and cheap.

`apiParentChildren()` (`acl.php:180-214`): joins `member_guardians → members`, `verified_by IS NOT NULL`, `m.status != 'archived'`, `ORDER BY student_name LIMIT 100`, and shapes each row through `apiMemberSafeRow()` + adds `relation`, `is_primary`, `verified_at`.

**`LIMIT 100` is a silent truncation.** A guardian with more than 100 verified children loses the tail with no `has_more` flag and no pagination parameter. Physically implausible for a real family; still an unannounced cap in a response contract.

**`apiMemberSafeRow()` (acl.php:216-238) is the PII boundary** and it is enforced *in the shaper*, not in the callers:

```
id, member_code, student_name, father_name, grandfather_name, full_name_am,
gender, age_group, status, member_type, current_section, photo_url, created_at
```

No phones, no address, no DOB, no guardian phones, no `baptismal_name`. `apiMemberStaffRow()` adds the sensitive set and is only reachable when `apiCanViewMemberPii($auth)` — which requires `info_dept|school_admin|super_admin`. **A parent cannot reach the staff shaper on any path I could find.** This is the strongest part of the whole subsystem.

### 4.4 What a member account can do today: nothing, by omission — and that is the problem

Because `member` is in no allow-list, every ACL helper returns false. That sounds safe, and where a route *calls an ACL helper* it is. The failure mode is routes that **do not gate at all** or **gate on a deny-list**. Those are §5.3.

### 4.5 A fourth role map: `AcmPolicy`, which grants these roles nothing either (VERIFIED)

The three role maps in §4.1 are not the whole story, because ACM carries **its own** authorization matrix — a separate, deliberately Casbin-shaped `can($role, $resource, $action)` — and it is a fourth place where `member` and `parent` must eventually appear.

`acm/src/Policy/AcmPolicy.php` holds a single-entry matrix: `MATRIX` (`:39-63`) has exactly **one role key, `accounts_office`**. `can()` (`:65-76`) returns `true` immediately for `super_admin`, then falls to `MATRIX[$role][$resource] ?? null` and returns `false` when that is not an array. So for `member` and `parent`, every resource/action pair returns `false` — the same deny-by-default outcome as the API ACL, reached independently.

What makes this worth flagging separately from §4.4:

- **The denial is tested, and only the denial.** `acm/tests/PolicyParityTest.php` sweeps 6 roles × 10 resources × 12 actions (`:19-33`) asserting `PolicyAuthorizer::can` matches `AcmPolicy::can` exactly, so matrix/seam drift fails CI. `member` and `parent` are in that role list (`:21`). `testCeremonyRights` (`:71-82`) additionally asserts a `member` context cannot `issue` or `view` a `code`. **Every one of those assertions is a `false`.** There is no test asserting any permission a member or parent *should* hold, because none has been defined.
- **`roleLabel()` knows both roles** (`AcmPolicy.php:83-92`) — `'member' => 'Member'`, `'parent' => 'Parent'` — while `MATRIX` grants them nothing. Labels exist; permissions were never designed. That is a precise statement of how far the ACM-side design got.
- **Nothing authenticates these roles through `PolicyAuthorizer` yet.** `api/v1/routes/acm.php` gates on session role checks, not on `PolicyAuthorizer`; the resolver in `acl.php` is `SQL`-predicate-based. So this gap is currently **latent, not exploitable**. It becomes load-bearing the moment a member/parent endpoint asks "may this account do X?" — which is the first thing a self-service API must do.

The consequence for the build: "signed in as a parent" has **no ACM-level meaning** today. The DPoP proof layer is verified (real ES256/RS256 round-trips in `AccessProofTest`/`RefreshProofTest`, run in CI), but it is verified against a permission set these roles will never hold. Whoever writes the first parent endpoint must decide whether authorization for these roles lives in `AcmPolicy` (extending `MATRIX` with two role keys) or in the `acl.php` SQL-predicate style — and doing both would create a fifth role map. Recorded as decision §11.8.

---

## 5. Endpoint-by-endpoint: what each role actually reaches

I walked all 18 route files in `api/v1/routes/`. `member` and `parent` are considered against each.

### 5.1 The parent's three real surfaces (VERIFIED)

| Endpoint | Gate | Result for `parent` |
|---|---|---|
| `GET /users/me` | none beyond auth (`users.php:406`) | Own profile: `id, username, email, full_name, role, is_active, member_id, assignments, profile_image, profile_version, created_at, last_login` (`ProfileService::shapeProfile`) |
| `PATCH /users/me` | none beyond auth + optimistic `profile_version` | Can change **only** `username, email, full_name` (`ProfileService::ALLOWED_INPUT_FIELDS`); all other keys ⇒ `PROFILE_FIELD_NOT_ALLOWED` |
| `POST /users/change-password` | none beyond auth; 5/15min per user + per IP | `AccountCredentialService::changeOwnPassword` — verifies current, min 12 chars, max 72 bytes, 6-password common list, revokes **all** refresh sessions in the same transaction |
| `GET/POST/DELETE /users/me/profile-image` | none beyond auth; rejects `user_id`/`id`/`owner_id`/`username` params | Own image only |
| **`GET /users/me/children`** | `apiRoleIs($auth, ['parent'])` (`users.php:503`) | `apiParentChildren()` — the only parent-specific endpoint |
| **`GET /members/{id}`** | `$canParent && !PII && !EDU && !TEACHER && apiParentCanSeeMember()` (`members.php:104-113`) | **safe row only** (14 fields) + `current_class` if year resolves |
| `GET /members/{id}/attendance` | `!$canPii && !$canEdu && !(teacher && scoped)` ⇒ 403 (`members.php:157-163`) | **Denied** — this branch has **no parent term** |

That last row is a real inconsistency, not a documentation quirk. Line 104 explicitly grants parents `GET /members/{id}`; line 162 then denies them `/members/{id}/attendance`. The route's own header comment claims *"attendance history (assigned class or PII role)"* — accurate about the code, and it means **a parent can see which section their child is in but not whether they attended**. For a parent app whose core value proposition is attendance visibility, this is the feature, and it is blocked.

> Whether this is intentional is **UNKNOWN** — no comment, test, or doc states a rationale. It reads as an oversight: the three lines above it (`canPii`, `canEdu`, `canTeacher`) were updated for parents and the fourth clause was not.

### 5.2 Everything else is role-blocked for both (VERIFIED)

| Resource | Gate | Effect |
|---|---|---|
| `/attendance/*` | `apiRolesAttendance()` (`attendance.php:32`) | 403 |
| `/grades/*` | `apiRolesGrades()` (`grades.php:25`) | 403 |
| `/mezmur/*` | `$MEZMUR_ROLES` (`mezmur.php:36`) | 403 |
| `/enrollment/*` | `apiRequireRole($auth, apiRolesEducation())` (`enrollment.php:12`) | 403 |
| `/teachers/*` | same (`teachers.php:11`) | 403 |
| `/students/*`→`/subjects/*` | `apiRolesEducation() + teacher` (`subjects.php:8`) | 403 |
| `/timetable/*` | same (`timetable.php:22`) | 403 |
| `/sync/*` | `apiRolesAttendance()` (`sync.php:45`) | 403 |
| `/acm/*` (activate/reset) | pre-auth by design; the *device* endpoints need a session | Reachable but useless without a device key |
| `/members` list | `apiCanBrowseMembers()` = PII ∪ `edu_dept` (`members.php:26`) | 403 |
| `/members/{id}` PUT | `apiRolesPii()` (`members.php:211`) | 403 |
| `/members/{id}/department-status` | `$dsReadRoles` (`members.php:379-382`) | 403 |
| `/members/{id}/attendance-risk` | `AttendanceWarningService::departmentsForRole()` → `default => []` (`AttendanceWarningService.php:104-112`) | 403 |
| `/members/{id}/photo` | `apiCanManageMembers()` (`members.php:500`) | 403 |
| `/hr/*` | — | 410 for **everyone** (retired, `hr.php`) |
| `/notifications/*` | `apiRequireStaffRole()` (**added 2026-11-26**; previously no gate) | 403 — see §5.3 |
| `/dashboard/*` | `apiRequireStaffRole()` (**added 2026-11-26**; previously no gate) | 403 — see §5.3 |
| `/classes/*` | `apiRequireStaffRole()` then the finance/material deny-list (**added 2026-11-26**) | 403 — see §5.3 |
| `/app/config`, `/app/download` | public | intentional |
| `/telemetry/*` | **no auth** | intentional |

### 5.3 Three endpoints a `member` or `parent` token could read that they should not — **FIXED 2026-11-26**

> **STATUS: CLOSED.** This section was written as a POTENTIAL SECURITY CONCERN during the audit and has since been remediated. The three routes now call `apiRequireStaffRole($auth)` at the top of the file, backed by an allow-list (`apiRolesStaff()`) in `api/v1/core/acl.php` and pinned by `tests/security/test_staff_route_closure.py` (10 tests). The text below is retained as the record of the defect and its reasoning. **Everything after this note describes the pre-fix state.** The one part of the original recommendation that was *not* implemented is the broadcast-fallback change to `NotificationCenterService` (see the note at the end of (d)) — that remains open by design, because it is a data-model decision, not a gate.

These are not theoretical: I traced each gate to its absence.

**(a) `GET /classes` — full class list, school-wide.**
`api/v1/routes/classes.php:17-19`:

```php
if (apiRoleIs($auth, ['finance_dept', 'material_dept'])) {
    err('Classes are not available for this role. Use the website.', 403);
}
```

That is a **deny-list of two roles**; every other role falls into the non-restricted branch (L75-99) which selects **all** `classes WHERE is_active = 1` with `student_count` and `attendance_count`. `teacher`/`attendance_taker` are scoped by the `$isRestricted` branch (L42); `member` and `parent` are neither restricted nor denied. **A parent token receives the complete class inventory of the school with per-class enrolment counts.**

`GET /classes/{id}` (L116) and `GET /classes/{id}/students` (L178) have the same shape: the scope check is `if (in_array($userRole, ['teacher','attendance_taker']))`. A parent passing any valid class id gets `SELECT * FROM classes` plus the **full active roster** — but shaped through `apiRosterStudentRow()` (14-ish fields: id, member_code, student_name, father_name, gender, age_group, status, photo_url), so no phones/addresses. It is still **other people's children's names and photos**, for any class id the caller enumerates.

This is the most serious finding in this analysis. It needs no crafted token — any provisioned parent account, on any device, can enumerate it with two requests.

**(b) `GET /dashboard/stats` — school-wide aggregates.**
`api/v1/routes/dashboard.php:16-25` has **no role gate**. The only branching is `$isRestricted = in_array($userRole, ['teacher','attendance_taker'])`. For a parent or member (`$isRestricted === false`) the response includes:

- `members.total/active/male/female` for the **entire school**
- `classes_count` for the school
- `today_attendance` — **school-wide present/absent/late counts for today**
- `users_count` — number of active staff accounts
- `recent_registrations` — new members in the last 7 days
- `features` (`FeatureGate::mobileCapabilities()`)
- `current_term`

**(c) `GET /dashboard/recent` — the school's activity log.**
Same file, L216-227: `if (in_array($userRole, ['teacher','attendance_taker']))` scopes to own rows, **else** `SELECT username, action, details FROM activity_logs ORDER BY created_at DESC LIMIT ?`. A parent receives other staff members' usernames and free-text action details. `details` strings contain member IDs and status transitions (`"ID: 42 active -> inactive"`, `api/v1/routes/members.php:311-313`).

**(d) `GET /notifications/*` — no role gate whatsoever.**
`api/v1/routes/notifications.php` calls `apiRequireAuth()` (L41) and then dispatches on action with **no `apiRoleIs` anywhere in the file** (verified by grep). Behaviour per endpoint for a parent:

- `summary` → `unreadSummary()` + `can_announce = false`, `can_message = false` (correct, from `NotificationCenterService` tables that lack `parent`)
- `feed` → alerts where `FIND_IN_SET(role, target_roles) > 0 OR target_user_id = uid OR **(target_roles IS NULL AND target_user_id IS NULL)**`. That last disjunct is a **broadcast fallback**. Any notification inserted with both target columns NULL is delivered to every authenticated account in the system, including parents and members.
- `announcements` → same fallback: `target_roles IS NULL OR target_roles = ''` matches everyone.
- `compose` / `thread-start` / `send-message` → correctly refused by `canAnnounce`/`canMessage` returning false.

Is the broadcast fallback reachable with real data? I traced every `INSERT INTO notifications` in the tree:

| Writer | target_roles | target_user_id |
|---|---|---|
| `AttendanceWarningService:442-455` | `BELL_ROLES[$dept]` (never null) | NULL |
| `SubmissionService:1285-1295` | NULL | teacherId (never null) |
| `MezmurSubmissionService:697-707` | NULL | takerId (never null) |
| `AssessmentFollowupService:724-742` | one explicit role, or NULL | one explicit user |
| `workflow.php:92-99` (`sendNotification`) | `$NOTIFICATION_MATRIX[$type] ?? []` → `implode(',')` of empty array = **empty string `''`** for `task_assigned`; and `'target_user_id' => null` when `$toUserId` is falsy | can be NULL |
| `admin/backend/workflow.php:334` (`createDepartmentTask` → `sendNotification('task_assigned', ..., ['target_roles' => $toDept ? [$toDept] : [], 'target_user_id' => $toUserId])`) | `''` if neither dept nor user | null if no assignee |

So: **`task_assigned` notifications created without a target department, or with a department but no explicit user, land in the "everyone" bucket.** Also note `NOTIFICATION_MATRIX['task_assigned'] = []` (`workflow.php:55`) with the comment *"Specific to target"* — the code relies on the caller always passing one, and there is no guard when it does not.

I did **not** find an existing row in a live database (none available), so whether *current production data* contains such rows is **UNKNOWN — REQUIRES VERIFICATION**. The code path is verified; the data is not.

**Recommended minimal fix — APPLIED 2026-11-26.** The three open routes now call `apiRequireStaffRole($auth)`, a deny-by-default allow-list companion to `apiCanBrowseMembers()`. The deny-list in `classes.php` was left in place *beside* the new gate rather than converted, because it carries a distinct message ("use the website") for finance/material that the mobile app relies on; the new gate sits in front of it. Files changed: `api/v1/core/acl.php` (+`apiRolesStaff()`, +`apiRequireStaffRole()`), `api/v1/routes/classes.php:17-21`, `api/v1/routes/dashboard.php:11-17`, `api/v1/routes/notifications.php:41-49`.

**Still open — the second half of that recommendation.** The `target_roles IS NULL AND target_user_id IS NULL` disjunct in `NotificationCenterService` (`:145`, `:275-277`, `:311-313`, `:330-332`) still behaves as a *broadcast* rather than a *deny*. The new staff gate makes it unreachable for member/parent tokens, so the leak is closed. But a NULL/NULL row is still delivered to **every staff role**, including roles that were never intended as recipients, and `workflow.php:55` still sets `NOTIFICATION_MATRIX['task_assigned'] = []` so a task with no department produces exactly that row. Fixing it properly means deciding what audience a NULL/NULL notification means — a data-model decision (and possibly a migration to backfill existing rows), not a gate. Left open deliberately.

**Why this was not caught — and what now catches it:** the static security suite pins allow-list *strings* in the routes that have them (`attendance`, `mezmur`, `timetable`, `grades`, `teachers`) and pins the parent resolver thoroughly (§13), but **nothing asserts that a role outside the allow-list is refused**. `tests/security/test_dashboard_role_routing.py` comes closest — it checks `ROLE_MAP` entries for specific admin pages — but the `/api/v1` routes have no equivalent closure test. Adding one test per route in the `test_dashboard_role_routing.py` shape ("a role in no allow-list gets 403") would have made this a CI failure instead of a launch blocker. **That is what `tests/security/test_staff_route_closure.py` now does** (§13): it asserts the gate is present and ordered before the first handler, that it is an allow-list rather than a deny-list, that the allow-list matches `user-save.php`'s `$validRoles`, and that `NotificationCenterService`'s three role matrices contain no member/parent entry.

### 5.4 The web surface: authenticated but homeless

A parent logging into the web console (`/admin/index.php` → `backend/login.php`) gets a valid session. Then:

1. `admin/access_control.php` runs. `$PUBLIC_PAGES` (L81-88) does not include their target. `$ROLE_MAP` has **no key** for `dashboard.php` (verified: not present in the 116-entry map) — and even if it did, no entry lists `parent`. Step 6 (L297-305) requires login → passes. Step 7 (L306-319) only enforces when a key exists → **no restriction applies**. Step 8 feature gate (L321-325) → `forAdminRequest('dashboard.php')` returns null.
2. So `admin/dashboard.php` executes. Its `switch ($role)` (L75-153) has cases for 16 distinct roles and **`default:` → `showDashboardNotReady($role, ...)`** at L152-153, a styled error page.
3. `admin/account.php` (self-service profile page) would return 403: `$supportedProfileRoles` (L12-26) lists 13 staff roles, no `member`/`parent` (`:27`).

**Net: a member or parent who logs into the website sees a "Dashboard Not Ready" screen and cannot reach their own profile page.** The API has `/users/me`; the web has no equivalent for these roles. `frontend/pages/dashboard.php`'s `$dashboardMap` (L22-42) has the same deficiency, but it also references nine dashboard files that do not exist in `frontend/pages/` (documented in the main report §U) so it is not a working entry point for anyone.

The *only* web surface these roles could reach without new construction is `admin/account.php` — and its `$supportedProfileRoles` list (L12-26, check at L27) omits them, returning 403 "This account role is not supported." Adding two strings there plus a `case 'member':` / `case 'parent':` in `admin/dashboard.php` (both pointing at `account.php`) is the smallest possible web on-ramp and touches nothing security-sensitive — the page's own mutations are all self-scoped (`api_settings.php` derives `$adminId` from the session, L31-33, and rejects client-supplied ownership).

---

## 6. Data model the two roles touch

### 6.1 Read paths (all verified)

```
parent:  users.id
           → acm_person_accounts (user_id, account_type='parent', status='active')
           → acm_persons.guardian_id
           → member_guardians (guardian_id, verified_by IS NOT NULL)
           → members (status != 'archived')
           → class_enrollments → classes   (for current_class only)

member:  users.id
           → acm_person_accounts (user_id, account_type='member', status='active')
           → acm_persons.member_id
           → [NOTHING READS THIS FOR AUTHORIZATION]
```

That last line is the crux. `users.member_id` exists (`sql/012:101`), is written by `AccountProvisioner::provision()`, is **returned** in `/users/me` and in login responses — and is used **only** by teacher-linking code (`api_teachers.php:436,569,661,1085`; `AssignmentService.php:1359`; `api_education.php:1894`), all of which filter `role='teacher'`. **No authorization decision anywhere consults it.** Building "member can see own record" requires writing that resolver from scratch; the column is there, the policy is not.

### 6.2 Writes

Neither role can write to `members`, `member_guardians`, `attendance`, `grades`, or any academic table. Their only writes are:

| Write | Path |
|---|---|
| own `users.username/email/full_name` | `PATCH /users/me` (optimistic locked) |
| own `users.password_hash` | `POST /users/change-password` (+ revokes all refresh sessions) |
| own `users.profile_image_path` | `/users/me/profile-image` |
| own `api_refresh_sessions` (rotation/revocation) | `/auth/refresh-token`, `/auth/logout` |
| own `acm_devices` (via console, not self-serve for member/parent) | `admin/api_acm.php` `device_revoke` |

**A parent cannot correct their own phone number.** The guardian record (`guardians.phone_primary`) is written only by `admin/api_guardians.php` (roles: `super_admin, school_admin, hr_dept` for writes). The one path that *looks* like parent self-service — the Family Link reverse-sync in `api/v1/routes/members.php:316-360` — is gated by `apiRequireRole($auth, apiRolesPii())` at L211, so it is staff-only. Its comment says *"guardian contact edits from the mobile app flow into the linked primary guardian"* — true, for **staff** using the mobile app. A parent cannot trigger it.

This is a significant product gap: **contact-data correction is the #1 self-service request in any school app**, and it requires an HR ticket today.

---

## 7. Account lifecycle — what an officer can actually do

All from the ACM console (`admin/api_acm.php`, `super_admin` or `accounts_office`):

| Action | Case | Guard | Effect |
|---|---|---|---|
| Suspend | `account_suspend:664` | `AcmPolicy` `account.suspend`; `suspendableTarget` rejects non-member/parent roles (`AccountProvisioner:398`) | **revokes sessions first** (`:204`), then link→`suspended` + `is_active=0` + history row, one PDO txn |
| Reactivate | `account_reactivate:688` | accepts the drift state too (`isSuspendedState`, `:381`) | link→`active` + `is_active=1` + history |
| Deactivate | `account_deactivate:710` | **super_admin only** — `accounts_office` has no `account.deactivate` in the matrix (`AcmPolicy.php:50-58` comment #25) | devices→revoked, sessions→revoked, link→`revoked`, `is_active=0`; **terminal** |

Design details worth noting because they will constrain the app:

- **Suspend revokes sessions before touching state** (`AccountProvisioner.php:203-207`). Comment: a crash leaves sessions dead + account active — self-healing. Correct ordering; the inverse would strand live sessions.
- **Reactivate does not re-issue a login.** Sessions were killed at suspend; the user must log in fresh. A parent app must handle "you were suspended and restored" as a full re-auth, not a resumed session.
- **Deactivate is terminal.** `reactivate()` refuses a revoked link (`isSuspendedState` returns false for `revoked`). Re-entry is a *fresh activation ticket*. `user-toggle.php:99-113` enforces the same rule from the other direction: it refuses any 0→1 flip when the ACM link is `revoked`.
- **Hard delete is blocked.** `admin/backend/user-delete.php:114-131` refuses to delete any user with an `acm_person_accounts` row at all, with a message directing the officer to the ACM console. Consequence: **a politely-declined applicant leaves a permanent `users` row** (never provisioned, or provisioned-then-deactivated). No purge path exists — by design: hard delete would shred devices, sessions and ceremony history. As of 2026-11-26 `admin/backend/acm_pending_expiry.php` deactivates *abandoned pending* accounts automatically (§9.7), but a provisioned-then-`revoked` account still has no purge path and that remains open.
- **Suspension is a direct console action, not a ceremony** (`sql/089` header). Good call for latency; means there is no second-approver step on suspension.

---

## 8. Mobile app readiness

### 8.1 The Flutter app has no concept of these roles

`Mobile/wbws_flutter_app/lib/screens/shell/app_shell.dart:299-329`:

```dart
switch (_api.userRole) {
  case UserRoles.teacher: ...
  case UserRoles.superAdmin:
  case UserRoles.schoolAdmin: return AdminHomeScreen(...)
  ...
  default:
    return TeacherHomeScreen(key: _teacherHomeKey);   // :327
}
```

`UserRoles` (`lib/utils/config.dart:39-53`) has 12 constants; no `member`, no `parent`.

The shell degrades in **two** places, and they compound:

1. **Tabs.** `getTabsForRole()` → `_baseTabsForRole()` (`lib/utils/config.dart:115-236`) ends in a `default:` "FALLBACK" clause (L230-235) returning exactly **Home + Profile** for any unknown role. So a parent gets a two-tab shell — no crash, no error, just a shell that cannot do anything.
2. **Home screen.** `_buildHomeForRole()` (`app_shell.dart:299-329`) falls to `default: return TeacherHomeScreen(...)` at **L327**. `TeacherHomeScreen._loadDashboard()` (`lib/screens/teacher/teacher_home.dart:69-119`) immediately calls `_api.getDashboardStats()` (`teacher_home.dart:97` → `api_service.dart:1456` → `GET /dashboard/stats`).

That request **succeeds** — see §5.3(b) — and returns school-wide statistics. The parent's landing screen therefore renders the school's total member count, gender split, active staff count and today's attendance. First launch, no crafted request, no awareness that anything is wrong. This is the same defect as §9.1 seen from the client side, and it is the most likely way the leak gets discovered (by a parent, in a screenshot).

Note `_refreshHome()` (`app_shell.dart:205-238`) has **no** `default:` clause, so pull-to-refresh on the parent shell silently does nothing — a secondary tell that the role is unmapped.

`roleFeature` (L288-294) gates `attendance_taker / finance_dept / material_dept / mezmur_dept / mezmur_attendance_taker` on a feature flag. `member`/`parent` are absent, so no feature gate applies to them either.

### 8.2 No activation UI, no device key, no DPoP

This is the blocking gap. I searched the entire Dart tree:

```
grep -ri "dpop"         lib/  → 0 hits
grep -ri "acm"          lib/  → only attendance_warnings + session_model noise, 0 ceremony hits
grep "publicKey|privateKey|ed25519|ecdsa|sign(" lib/ → 0 hits
grep "devices/register|acm/activate|acm/reset" (whole repo, all languages) → 0 hits outside routes/acm.php
```

`ApiService.login()` posts exactly `{username, password}` (`lib/services/api_service.dart:1067-1071`) — no `installation_id`, no `device_fingerprint`. So the app cannot even *attempt* an S4 device assertion, which means:

- Every login from the app produces an **unbound** refresh family (`device_bound: false`).
- Unbound families never require a DPoP proof (`auth.php:353` `if ($sessionBinding['bound'])`).
- Therefore the S5/S9 device-binding stack — which is elaborate, RFC-9449-shaped, and correct on the server — **is entirely unexercised by the shipping client.** It is dead code from the app's perspective.

The activation ceremony itself is four `POST`s and two key operations:

```
POST /api/v1/acm/activate/verify       {selector, secret, purpose:'activation', installation_id?}
POST /api/v1/acm/activate/credentials  {grant, password, idempotency_key}   → device_grant
POST /api/v1/acm/devices/register/begin{grant, installation_id, pubkey, name?} → challenge
       ← client signs challenge with the Android Keystore key
POST /api/v1/acm/devices/register/complete {challenge_id, challenge, signature}
       → users.is_active = 1
```

`DeviceCrypto` requires **X.509 SubjectPublicKeyInfo DER** as base64, ES256 (P-256) or RS256 ≥2048, and signature verification where ES256 signatures are **DER, not P1363** (`DeviceCrypto.php:14-33`). A new app must produce exactly that, and must persist the private key in the Keystore (`DeviceCrypto` comment cites Android Keystore `.getPublicKey().getEncoded()`).

**None of this exists, and building it is a prerequisite for a member/parent app to have any users at all** — unless the ceremony is done on a staff device (see §8.3).

### 8.3 The ceremony is currently device-locked in a way nobody documented

Because `users.is_active = 1` happens **only** in `DeviceService::completeFull()` on `operation === 'activation'`, and that requires a keystore keypair asserted through `publicKey`, an account cannot become usable without some client that can generate and sign with such a key.

The only clients that exist are:
1. The Flutter app — which has no such code (§8.2).
2. `tests/e2e/acm_*.php` — CLI, with `stubSign()` helpers and real `openssl` keys.

So today, a member/parent account can be **provisioned but not activated** through any shipped UI. Whether the team is activating accounts via a script, an unreleased build, or not at all is **UNKNOWN — not determinable from source**. This should be the first question in the kickoff.

Two design options follow, and the choice is a product decision, not a code detail:

- **(A) Ceremony in the member/parent app.** Requires KeyStore integration, DPoP signing on every request for bound families, and a 30-minute `activation` code TTL that must be survived end-to-end. Highest security; highest client complexity; every future client must reimplement it.
- **(B) Ceremony on a staff device** (officer holds the phone, applicant types a password). Keeps the member app thin — but breaks SI-6-style device binding semantics (the bound device is the *officer's*, so every request from the member requires a proof the member cannot produce). **This path is not supported by the current code**: `acm/activate/credentials` is deliberately called by the applicant, and there is no officer-side equivalent.
- **(C) A separate activation path for low-risk accounts** that skips device binding and flips `is_active` after credential establishment. This is the pragmatic answer, and it is **a security-posture change that requires a decision** — the code comment at `DeviceService.php:463` is emphatic that this is the only flip path, and `AccountProvisioner::provision()` writes `is_active = 0` deliberately.

I would not start the Members app before this is decided. Every screen depends on how a device gets trusted.

### 8.4 Everything else in the mobile stack is role-shaped for staff

- Local SQLite `wbws_offline_v4.db`, schema version **43** (`lib/services/local_schema_v34.dart:5`) — one schema for all roles; no `parent`/`member` tables or scoping.
- Owner-metadata reconciliation (`SessionCoordinator._hasOwnerMetadataConflict`, `session_service.dart:~600-620`) blocks login when local private rows belong to a different user. Correct for staff handover phones; for a **parent app on a shared family phone it may block the second parent** — UNKNOWN without tracing `canActivateCandidate`.
- Notification polling every 30 s (`notification_service.dart:60`) — there is **no push transport**: zero Firebase/FCM hits in the entire repo (PHP and Dart). A parent app on 30-second polling is a battery and data plan decision that has not been made.
- Standard error/`ApiResponse` handling, idempotency keys, ETags — all reusable. The plumbing is in good shape.

---

## 9. Risks, ranked by consequence for this build

### 9.1 [CLOSED 2026-11-26] Parents and members could read school-wide class rosters and statistics
`classes.php:17` deny-list + `dashboard.php` no-gate. A parent token got: full class list with enrolment counts, any class's active roster (names, photos, sections), school-wide member/gender counts, today's school-wide attendance, active staff count, recent registrations, and the school's activity log. No crafted token needed — a normal account, normal device.
**FIXED 2026-11-26** (§5.3 records the defect in full). All three routes now open with `apiRequireStaffRole($auth)` — a deny-by-default allow-list (`apiRolesStaff()`, 16 staff roles) added to `api/v1/core/acl.php`. Guarded by `tests/security/test_staff_route_closure.py`, 10 tests, which also asserts the gate is an allow-list rather than a deny-list naming these two roles. **Residual, deliberately not fixed:** the `target_roles IS NULL AND target_user_id IS NULL` broadcast disjunct in `NotificationCenterService` still delivers NULL/NULL rows to *every staff role*; that is a data-model decision, not a gate, and is now unreachable from a member/parent token.

### 9.2 [BLOCKING] No client can complete activation
§8.3. `is_active = 1` requires a keystore-signed challenge; no shipped client can produce one.

### 9.3 [BLOCKING] No member-side authorization model exists
`users.member_id` is never consulted for authorization (§6.1). Every "my own record" endpoint must be designed, not wired.

### 9.4 [CLOSED 2026-11-26] Ticket link integrity was not cross-checked
`AcmTicketService::create()` validated that `member_id` and `guardian_id` exist, but never that they agreed with `acm_persons.member_id`/`guardian_id` for the same person.

**Corrected during remediation — the original write-up overstated the risk.** `AccountProvisioner::provision()` never copies the ticket's `guardian_id` anywhere: it reads `users.member_id` from the ticket (`:75`) but takes the *authorization* link solely from `acm_persons.guardian_id`, which is the only column `apiParentGuardianId()` reads (`acl.php:180-183`). `AcmPersonService::update()` deliberately cannot change either link column (`:112-122`). So a contradictory ticket could not grant access to a child — the account faithfully followed the person record. The real damage was **silent misrepresentation**: the ticket, the `evidence` packet (`buildEvidence`, `:770-780`) and the console review screen all display the ticket's guardian, so an officer approved a link that was not the one being activated.

**FIXED 2026-11-26.** `create()` now rejects a ticket whose `member_id` or `guardian_id` contradicts the person's, with a message naming the authoritative record. The comparison is NULL-aware, so *filling in* a missing link (the legitimate case for people created without one) still works. Guarded by `tests/security/test_member_parent_remediation.py::TicketLinkIntegrityTests` (5 tests).

### 9.5 [CLOSED 2026-11-26] Parent attendance read was denied while the member record was allowed
§5.1. The inconsistency was one missing clause in the attendance branch. **FIXED:** `members.php:161-171` now ANDs a `$canParent && apiParentCanSeeMember(...)` clause into the same condition the member-record branch uses, so a parent reaches their own verified children's attendance and nothing else. Guarded by `test_member_parent_remediation.py::ParentAttendanceReadTests` (4 tests). Static only — the live HTTP harness has no seeded parent user, so no runtime 403 is asserted.

### 9.6 [MEDIUM] No bulk onboarding path for existing members
§2.1. 5,000 manual console ceremonies is not a launch plan. No importer, no backfill, no CSV path exists.

### 9.7 [CLOSED 2026-11-26 — and it was hiding a worse defect] An unactivated account was a permanent orphan, and could be switched on past the ceremony

**The orphan.** `is_active = 0` + link `pending` + `user-delete.php` refuses any hard delete on an ACM-linked user (§7), so an abandoned or declined activation left a `users` row forever. Cancelling the ticket (`ticket_cancel`, `api_acm.php:564`) was the only path out, and nothing expired a pending link.

**The worse defect, found while writing the cleanup.** A pending account is `is_active = 0`, which reads identically to a deliberately disabled account at the login gate. Both staff surfaces that can flip that bit guarded **only** against `revoked`:

- `admin/backend/user-toggle.php:103-113` — the 0→1 branch
- `admin/backend/user-save.php:277-290` — the edit path, same 0→1 condition

**A `pending` account could therefore be switched on directly from user administration, skipping the device ceremony entirely** — the exact thing §8.3 says cannot be done. The account would then log in with no bound device keypair. This is a more serious finding than the orphan, and it was not in the original §7 or §9.7 write-ups because I read those guards as "protects against revoke" rather than asking what else reaches them.

**FIXED:**
1. Both surfaces now refuse `pending` with a message pointing at activation, and both read the link status into a variable (they previously consumed the row with a bare `fetchColumn()`, so the second read was empty).
2. New `admin/backend/acm_pending_expiry.php` — CLI, cron-shaped like `mobile_telemetry_retention.php`. It **deactivates** rather than deletes (`is_active=0`, link →`revoked`, history row), which is the terminal state `isSuspendedState()` refuses to revive, so re-entry is a fresh ticket and the audit trail survives. Dry run by default; `--apply` is one transaction with `SELECT … FOR UPDATE`; touches only `status='pending'` on inactive users; skips any account a human is still working on (a device row of any status, a consumed grant, or a password change since provisioning).
3. `sql/098_acm_pending_expiry_events.sql` appends `'expired'` to the `acm_account_suspensions` action ENUM (guarded, re-runnable, 090 pattern). Reusing `'deactivated'` would make an automatic timeout indistinguishable from a deliberate `super_admin` action.

**The race this job had to survive:** `DeviceService::completeFull()` commits the activation transaction (`is_active=1` + link `pending`→`active`) and only *afterwards* calls `markActivated()` for the ticket state; the link flip is deliberately lenient (0 rows → `error_log`, never throws). `userIdFor()` resolves these roles via `status IN ('pending','active')`. So a link can legitimately go active between the candidate query and the update. The job re-checks `users.is_active` **inside** the transaction and skips such accounts, reporting them as `skipped_still_active`.

Guarded by `tests/security/test_acm_pending_expiry.py` (25 tests). Two of them fail if the `pending` guard is removed — verified by deleting it.

**Operational note:** this is a deployment-owned cron job. Until it is scheduled, nothing expires — the orphan behaviour returns, though the back-door fix does not depend on the job.

### 9.8 [CLOSED 2026-11-26 — diagnosis corrected] `task_assigned` notifications were written addressed to nobody
§5.3(d). **The original write-up had this backwards, and the correction matters.**

I wrote that `task_assigned` produced a NULL/NULL row and therefore a school-wide broadcast. Re-reading `sendNotification()` (`admin/backend/workflow.php:87-90`) and every one of the six `INSERT INTO notifications` sites showed something different: when `target_roles` is `[]`, `implode(',', [])` yields the **empty string `''`**, and `''` is *not* `NULL`. The broadcast disjunct is `target_roles IS NULL AND target_user_id IS NULL`, so an untargeted `task_assigned` did **not** broadcast — it matched **nobody** and vanished. `createDepartmentTask()` passed `[]` whenever a task had no department (`:336`), and `$NOTIFICATION_MATRIX['task_assigned'] = []` (`:55`), so that path was reachable.

The other five insert sites all pin one of the two columns with a literal `NULL`, so NULL/NULL is **never written today** — which also means the `App\Services\NotificationCenterService` broadcast disjunct has no reachable producer at all.

**FIXED:** `sendNotification()` now refuses a notification with neither a role nor a user target and logs the reason instead of silently dropping it; `createDepartmentTask()` skips the call when there is no recipient. Neither change affects the six other callers — all pass explicit `target_roles`. Guarded by `test_member_parent_remediation.py::TaskNotificationTargetTests` (5 tests).

**Still open:** the `target_roles IS NULL` disjunct remains in `feed`/`summary`/`markAllRead`/`markRead` as a latent broadcast. No first-party writer can produce such a row today, but nothing enforces that, and `announcements` treats `target_roles IS NULL OR target_roles = ''` as "everyone". Deciding what a deliberately-null audience means is a data-model call, not a gate — left open.

### 9.9 [LOW, partly closed 2026-11-26] Role enumeration at login
Both surfaces return 403 "account is inactive" for existing disabled accounts and 401 for wrong credentials. Consistent and deliberate; worth knowing before the app's error copy is written.

Note the timing of that state for these two roles specifically: between `establishCredentials` and device registration, the account is **`is_active = 0`**, so a member/parent who half-completed the ceremony and then tries to log in gets "Your account is inactive. Contact an administrator." — the *correct* security behaviour and completely wrong guidance. **FIXED 2026-11-26, backend half.** `api/v1/routes/auth.php` now calls a new `apiAccountPendingActivation($conn, $userId)` (`api/v1/core/acl.php`) before falling through to the generic message, and answers `403` with `code: activation_pending` and "Your account is waiting for device setup. Finish activation on your device to sign in." The helper selects no identifying column (boolean only), runs after `password_verify()` so it is not an account-existence oracle, and fails closed on a pre-ACM database. **Still open, app half:** `/users/me` returns `is_active: false` and nothing about ACM, so a signed-in-but-inactive account has no way to read its own state — that needs an endpoint, which is app-scope work.

### 9.10 [LOW] No email or SMS channel
`grep` finds zero `mail()`/PHPMailer/SMTP usage outside vendored TCPDF. **Access codes are handed over physically.** A parent app cannot do "forgot password" by email; the only recovery is an officer-issued reset code (`acm/reset/verify`, 1440-min TTL). This is a real operational constraint on the "members web" half.

### 9.11 [LOW] Migration numbering collisions
`sql/081_*` ×2, `sql/082_*` ×2, `sql/091_*` ×2 (verified by directory scan). Applied by hand (ADR-0004), so ordering is a human procedure. Any ACM work will add more `sql/09x` files. `scripts/check_migration_numbering.py` **does** run in CI: the `migration-hygiene` job in `.github/workflows/backend-checks.yml:420-443` invokes it at `:428`, and running it locally reports only the three documented legacy collisions. So a new duplicate number **will** fail CI — but note the second step in that job (`:430-443`) only *warns* on bare `DELETE`/`TRUNCATE` in `sql/*.sql`; it does not fail the build. Migration *ordering* against a live database remains unverified (no DB, and ADR-0004 makes it manual).

---

## 10. Documentation vs implementation (this subsystem only)

| Doc claim | Source reality |
|---|---|
| `docs/product/PRD.md:43` "Members do not have accounts." | Members **do** have accounts: `users.role = 'member'` is provisioned by ACM (`AccountProvisioner:84`). The non-goal is stale. |
| `PRD.md:192` "Parent app — feature list **locked**; not started" | Accurate for the app; **misleading about the backend**, which is P0-shipped (`PRD.md:194`). |
| `PRD.md:193` "Members app — **on hold**" | Accurate. |
| `docs/adr/0017` "Member registration is web-only… mobile edits of guardian data sync back to the server" | Registration: true. The guardian sync it cites is **staff-only** (`members.php:211` `apiRequireRole($auth, apiRolesPii())`) — a parent cannot sync their own guardian data. ADR-0016's "prerequisite for a future parent app" is the accurate framing. |
| `docs/adr/0016` "This is a prerequisite for a future parent app" | The prerequisite that is *missing* is the `verified_by` stamp (§1.3), not the tables. |
| `sql/089` header: "suspension is a direct console action" | Correct. |
| `api/v1/routes/acm.php:6` "Pre-account endpoints: no session auth" | Correct, and `devices/*` **do** require a session (mixed auth, L340-500). |
| Role docs (`ROLES_AND_PERMISSIONS.md`) | Contains **no** mention of `member`/`parent` at all (grep: 0 hits). The two roles that this whole build is about are undocumented. |
| `sql/079` header "Existing tables (users, members, guardians, ...) are untouched." | True of `sql/079` itself — it adds only `acm_*` tables. But the `user` role values `member`/`parent` are introduced *by its data model* and nothing in any doc records that `users.role` now carries two non-staff values. Anyone reading `user-save.php`'s `$validRoles` (17 roles, no `member`/`parent`) concludes those accounts cannot exist. |
| `docs/api/openapi.yaml` (100 935 bytes, 105 paths, **118 operations** — recounted here) | The ACM ceremony is **entirely absent**: zero paths containing `acm`, and **no `/users/me/children`**. So the two endpoint surfaces a member/parent client must implement first — activation+device (8 routes) and the parent's only dedicated endpoint — have **no written contract at all**. The route file's own docblock (`api/v1/routes/acm.php:1-26`) is the only spec. |
| `openapi.yaml` `/members/{id}/attendance` summary: *"Attendance history of one member (assigned class or PII role)"* | Accurate to the code and **silently excludes parents** — which is exactly the §5.1 inconsistency, now also encoded in the published contract. If the parent read is meant to exist, both the route and this line are wrong; if it is not meant to exist, `members.php:104` granting parents `GET /members/{id}` is the odd one out. One of the two must change. |

---

## 11. What I would need to decide before writing app code

These are open questions the source cannot answer. Each blocks a design choice.

1. **How does a device get trusted** (§8.3, options A/B/C)? This determines whether the member app needs keystore + DPoP at all.
2. **What may a member see about themselves** — own profile only, or own attendance/grades/fees? The column exists; the policy does not.
3. **Is the parent attendance denial intentional** (§5.1)?
4. **Can a parent self-correct contact details**, or is that an HR workflow forever (§6.2)?
5. **How do 5,000 existing members get accounts** (§9.6) — bulk import, self-request, or phased?
6. **Web and app, or app only?** The `member`/`parent` roles currently have *no* working web surface (§5.4), and the codebase's web layer (`admin/access_control.php` + `$ROLE_MAP` + `admin/dashboard.php`) is built around staff dashboards. A member web portal is new construction, not an extension.
7. **Who is the audience for a member account** — the student themselves (age 7-17) or the parent acting as the student? The `age_group` enum is `7_13|14_17|18_plus`, and COPPA-style consent questions are not addressed anywhere in the code.
8. **Where does member/parent authorization live — `AcmPolicy` or `acl.php`?** (§4.5.) Today there are four role maps and the ACM matrix grants these roles nothing. Adding them to `AcmPolicy::MATRIX` gives one Casbin-shaped place to reason about the app's permissions; leaving authorization in the `acl.php` SQL-predicate style keeps it next to the data it filters. Choosing both creates a fifth map and the same divergence risk §4.1 already documents. This is the first decision the parent/member API forces, because every new endpoint must answer it.

---

## 12. Change-impact preview (Prompt §37–38, no changes made)

If/when I am asked to implement, the smallest safe increments are, in dependency order:

| # | Change | Files | Blast radius |
|---|---|---|---|
| 1 | ~~Close the deny-list gaps (§9.1)~~ **DONE 2026-11-26** | `api/v1/core/acl.php` (+`apiRolesStaff`, +`apiRequireStaffRole`); `classes.php:17`, `dashboard.php:11`, `notifications.php:41`; new `tests/security/test_staff_route_closure.py` | Isolated; breaks nothing for staff. Full suite verified: 121 pre-existing environment failures before **and** after, identical failure sets — zero regressions. |
| 2 | Grant parent attendance read (§9.5) | `api/v1/routes/members.php:157-163` | One clause; reuses an existing tested resolver. |
| 3 | Member self resolver | `api/v1/core/acl.php` (new `apiMemberSelfId`), `members.php` GET branch | New helper; must mirror the parent resolver's deny-by-default shape. |
| 4 | Activation path decision | `acm/src/Device/DeviceService.php:462-500` **or** a new route; if option (C), also `AccountProvisioner.php:84` (`is_active` literal) | **High** — touches the only `is_active` flip in the system. Existing coverage is `tests/e2e/acm_{ceremony,devices,family,lifecycle,deactivation,recovery,refresh_binding,access_proof}_walkthrough.php` — 8 CLI suites, all destructive, **none run in CI** — `grep -rn "acm_" .github/workflows/` returns nothing. (`tests/e2e/README.md` documents only the APK/browser fixture and never mentions ACM, so it is not a pointer to these walkthroughs.) A change here needs its own e2e **and** a static guard in `tests/security/` (the `test_acm_*` pattern) or it ships untested. |
| 5 | Web surface for both roles | *Minimum:* `admin/account.php:12-26` (+2 role strings) and `admin/dashboard.php:75-153` (+2 `case` → `account.php`). *Full portal:* `admin/access_control.php` (+`$ROLE_MAP` keys), new page(s) | Minimum is 4 lines and reuses a self-scoped page. Full portal is new construction. **Do not** add the roles to `user-save.php` `$validRoles` — that would let an admin hand-create unticketed accounts, contradicting §2. `AdminSessionGuard::KNOWN_ROLES` only needs them if impersonation is extended. |
| 6 | Flutter role branch | `lib/utils/config.dart` (+2 constants, +2 tab sets, replace FALLBACK), `lib/screens/shell/app_shell.dart:299-329` (replace `default:`), `:205-238` (add cases) | Medium; the `default:` fallbacks must become explicit "unsupported role" screens, not teacher home + two tabs. |
| 7 | Bulk onboarding | new CLI under `admin/tools/` + `acm/src/Identity/` | New surface; needs idempotency and a dry-run. |

Ordering is not arbitrary: **#1 must precede any member activation**, and **#4 must precede #6** (an app that cannot activate has no users).

---

## 13. Analysis status

**VERIFIED in this pass:** every file and symbol cited above, read directly. Route gates traced by grep over all 18 route files. Role lists counted, not eyeballed. Notification writers enumerated exhaustively (6 sites). `acm_person_accounts` writers enumerated (6 sites, 1 INSERT). `users.is_active=1` writers enumerated (2). Flutter absence of ACM/DPoP verified by repo-wide grep.

**NOT verified:** live database contents (no MariaDB available) — so "is there a NULL/NULL notification row in production" and "how many members are already provisioned" are open. The exact per-tile behaviour of the Flutter teacher home for a parent token. Whether the `acm-contracts` PHPUnit job actually goes green on a real runner (there is no `php` binary or `composer` in this sandbox, and tracked `vendor/` contains the 11 runtime packages but **not** the 27 `packages-dev` entries in `composer.lock`, phpunit included — so the job's `composer update` step cannot be reproduced here and my reading of `acm/tests/` is source-level only).

**Corrected in this pass (three items previously marked UNKNOWN):**

- **CI *does* run `scripts/check_migration_numbering.py`** — `migration-hygiene` job, `.github/workflows/backend-checks.yml:420-428`. §9.11 updated.
- **CI *does* run the ACM PHPUnit suite** — the `acm-contracts` job (`:372-418`) runs `./vendor/bin/phpunit -c acm/phpunit.xml` (`:418`), and `acm/phpunit.xml` globs `<directory suffix="Test.php">tests</directory>`. That is **6 test files / 47 test methods** (§13 below, new subsection) covering the ceremony alphabet, error-code stability, the policy matrix, and real ES256/RS256 signature round-trips for both per-request and refresh-time DPoP proofs. It is *not* a DB suite by design — the job's own comment (`:373-376`) says the services use MySQL-isms, so behaviour stays pinned by `tests/security` statics plus the dev E2E walkthrough.
- **CI does *not* run any `tests/e2e/acm_*` file.** `grep -rn "acm_" .github/workflows/` returns nothing; the only `tests/e2e/` PHP harness the backend workflow invokes is `idempotency_lifecycle.php` (`:311`). So the eight ACM walkthroughs are developer-machine only. §12 row 4 corrected accordingly.

**Test suite run (static, no DB):** `python3 -m pytest tests/security -k acm -q` → **193 passed, 3 415 deselected**. This matters for two reasons:

- The **parent resolver is well covered**: `tests/security/test_acm_family_gaps.py:100-129` pins `apiParentGuardianId`'s four predicates, the `verified_by IS NOT NULL` filter in both `apiParentCanSeeMember` and `apiParentChildren`, `sql/092`'s exact UPDATE, and the two route call sites (`members.php`, `users.php`). Anything I said about the parent path is already regression-guarded by someone.
- The **flip path is pinned too**: `test_acm_pending_link_fix.py:34-50` asserts the pending→active link flip lives only in `completeFull`'s activation branch and that no second `SET status = 'active'` exists in `AccountProvisioner`; `test_acm_s7_lifecycle.py:119` pins both conditional `is_active` flips. **So §8.3's constraint is not an accident of style — it is an asserted invariant.** Any activation redesign (option C) will fail these tests by design, which is the correct signal: it forces the change to be a decision rather than a drift.

What was **not** covered, and is the gap that allowed §9.1: there was **no test asserting that an unknown/unauthorized role is denied** on `/classes`, `/dashboard/*`, or `/notifications/*`. The route tests that exist pin specific allow-list strings in `attendance`, `mezmur`, `timetable`, `grades` and `teachers` — the files that use allow-lists. The two files that used a deny-list (`classes.php`) or no gate at all (`dashboard.php`, `notifications.php`) were pinned by nothing. A one-test-per-route closure check in the shape of `test_dashboard_role_routing.py` would have caught this.

**That gap is now closed (2026-11-26).** `tests/security/test_staff_route_closure.py` adds **10 tests** in three groups: the staff catalogue (`apiRolesStaff()` contains exactly the 16 staff roles, is disjoint from `{member, parent}`, and matches `user-save.php`'s `$validRoles`); route closure (each of the three routes calls `apiRequireStaffRole($auth)` *before* its first handler, and `classes.php` keeps its finance/material refusal); and notification matrices (`ROLE_LABELS`, `ANNOUNCE_SEND`, `MESSAGE_PARTNERS` contain no member/parent entry, so removing the route gate later would not silently re-open the leak). The shape assertion matters more than the presence assertion: a future "fix" that names member/parent in a *deny-list* would satisfy a naive presence check and still admit the next non-staff role, so `test_gate_is_an_allow_list_not_a_deny_list` fails on that pattern.

**Still uncovered, and worth noting:** these are *static* assertions — they prove the gate is written and ordered correctly, not that a `parent` token receives HTTP 403 at runtime. The runtime proof needs the live HTTP harness (`SSMS_LIVE_GUARDS=1`, `tests/security/test_attendance_phase1.py` `mobile_token`/`mobile` helpers, which log in as seeded `audit_*` users). That harness has **no member or parent seed user** (`USERS` at `test_attendance_phase1.py:35-43` lists seven staff roles), so a live closure test for these two roles cannot be written until an ACM-provisioned account exists in the seeded fixture. Noted rather than worked around.

**Documentation search (adds to §10):** `grep -rln "acm/activate\|devices/register\|device_grant" docs/` returns **zero files**, and `ARCHITECTURE.md` does not mention ACM or the Account Command Center at all. There is therefore **no prose description of the ceremony anywhere in `docs/`** — not in the architecture doc, not in the OpenAPI file, not in an ADR. The only surviving specification is the 26-line docblock at the top of `api/v1/routes/acm.php`. Given that this ceremony is the gate every member and parent must pass through, and that its wire contract includes a base64 X.509 SPKI, DER-encoded ES256 signatures, and stable machine error codes, that is the single largest documentation gap in this subsystem.

**The PHPUnit suite (`acm/tests/`) — what CI actually exercises (VERIFIED by source, not run):** 6 files, 47 test methods.

| File | Methods | What it pins | Relevant to these roles? |
|---|---|---|---|
| `AccessProofTest.php` | 10 (`:146-334`) | Real openssl ES256 **and** RS256 per-request DPoP round-trips; rejects wrong method/path/scheme, missing or malformed `ath`, iat-window edges, degenerate input; asserts host is **not** pinned (two hosts verify). | **Indirectly** — this is the proof a member/parent device will send. |
| `RefreshProofTest.php` | 6 (`:121-265`) | Same for refresh-time proofs, plus algorithm-confusion (embedded-key) rejection and freshness windows. | Indirectly, same. |
| `PolicyParityTest.php` | 4 (`:35-83`) | `PolicyAuthorizer::can` ≡ `AcmPolicy::can` over 6 roles × 10 resources × 12 actions; deny-by-default; ceremony rights. | **Directly** — `member`/`parent` are two of the six roles, and every assertion about them is `false` (§4.5). |
| `CeremonyTest.php` | 12 (`:20-162`) | 30-symbol ambiguous-free alphabet, normalization, distinct prefixes, **error-code stability**, single generic verify failure, grant value object, proxy-safe IP + CIDR matching. | **Directly** — this is the wire contract the app must match. |
| `DeviceCryptoTest.php` | 12 | ECDSA round-trips (per the job comment at `:389`). | Indirectly. |
| `ClassLoadingTest.php` | 3 | PSR-4 mapping plus the `acm/autoload.php` manifest. | Indirectly. |

Two things this changes about the picture:

- **Error-code stability is a tested contract.** `testErrorCodesAreStable` (`CeremonyTest.php:61`) means the app can safely branch on machine error codes rather than parsing message text. Worth knowing before writing client error handling — and it makes the *absence* of any published contract (§10) more pointed, because the contract is stable but undocumented.
- **`acm/autoload.php`'s manifest is complete and CI-enforced — but by pytest, not PHPUnit.** I diffed the 32 `require_once` lines against the 32 files under `acm/src/` — exact match, no missing or stale entries. The guard is `tests/security/test_acm_s2_package.py::test_package_loader_is_an_explicit_manifest` (`:91-101`), which walks `acm/src/**.php` and asserts each relative path appears in `autoload.php`, with a companion test (`:104-115`) asserting contracts are required before the services that implement them. So a new ACM class added without a manifest line fails CI in the `security-tests` job. `ClassLoadingTest` alone would **not** catch it — it uses `class_exists`, which the PSR-4 mapping in the tracked `vendor/` would satisfy even with the manifest line missing. Worth knowing before adding the first member/parent service class: the manifest is a manual step, and the thing that enforces it is a Python test in a different suite from the PHPUnit job.

**One CI-configuration observation, not a product defect:** the `acm-contracts` job runs `composer update` every time (`:403`), and its comment says this is temporary because `composer.lock` + `vendor/` were to be regenerated on a composer-equipped machine. But `composer.lock` **is tracked and already pins `phpunit/phpunit 11.5.58`**, and the tracked `vendor/autoload` files already map `SSMS\\Acm\\` → `acm/src`. What is genuinely missing from tracked `vendor/` is the 27 `packages-dev` entries, phpunit among them. So the job still needs the install step, but the stated blocker is narrower than the comment implies, and the comment's own exit condition ("this MUST become `composer install --frozen-lockfile`") is close to reachable. Flagged because an unpinned `composer update` in CI means the ACM contract suite can change behaviour without a commit — relevant when those contracts start guarding member/parent app code.

**Deliberately not done:** no code changes to application source, no test additions to the repo, no dependency inspection beyond `pubspec.yaml`, no attempt to build or run the app.

**ANALYSIS INCOMPLETE** — specifically: the `acm/src/Device/DeviceRefreshProofs.php` proof-replay implementation was read only at its interface (`rotationVerifier` / `requestVerifier`), not its internals; and `SessionCoordinator.canActivateCandidate` was not traced line by line. Neither affects the conclusions above.
