# 03 — API integration

The contract between this app and the platform. Read from the platform source at
`d7d27c1`; every endpoint below exists in `api/v1/routes/`.

---

## 1. Base URL and transport

| | |
|---|---|
| Base | `https://<platform-host>/api/v1` |
| Routing | `api/v1/.htaccess` rewrites any non-file path to `index.php?_route=<path>` |
| Format | JSON in, JSON out (`Content-Type: application/json; charset=utf-8`) |
| Auth header | `Authorization: Bearer <access_token>` — **must** be a header. `?token=` is rejected because it lands in access logs. |
| Device proof header | `DPOP: <proof>` — only for device-bound sessions, see §6 |
| Correlation | Responses carry `X-Request-Id`. Clients may send `X-Client-Attempt-Id` (≤64 chars, `[A-Za-z0-9._-]`) to correlate. |
| Version header | `X-API-Version: 1.0` on every response |

`GET /api/v1/ping` is liveness only: no database, no auth. Use it for a health
check, never as a readiness signal for data.

---

## 2. Response envelope

Every response — success or failure — is one of two shapes. This is the whole
grammar; there are no others.

```jsonc
// success
{ "status": "success", "data": { … } }

// success with a message
{ "status": "success", "message": "Signed out.", "data": { … } }

// failure
{ "status": "error", "message": "Human-readable text.", "code": "MACHINE_CODE" }
```

- `data` is omitted when there is nothing to return.
- `code` is **not always present**. Auth, ACM and profile routes set it; older
  routes do not. Code must therefore never be the only thing your client checks.
- `paginated()` responses put `data.items` and `data.pagination`
  (`total/page/limit/pages/has_more`) in the same envelope.
- HTTP status is meaningful and must be honoured: `200/201`, `207` (partial),
  `400`, `401`, `403`, `404`, `405`, `409`, `410`, `413`, `422`, `429`, `500`,
  `503`.
- `429` carries `Retry-After`, and `X-RateLimit-Limit` when a limit was hit.
- `503` means "retry later" (`AUTH_REVALIDATION_UNAVAILABLE`,
  `AUTH_SERVICE_UNAVAILABLE`, `CACHE_UNAVAILABLE`). Do not treat it as a
  permission failure.

Wire it into `Repository` as one parser, once:

```php
// sketch, not final code
$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['status'])) → transport error
if ($body['status'] === 'error')  → ApiError(status, message, code)
return $body['data'] ?? [];
```

---

## 3. Authentication

Tokens are minted by the API and have no relationship to the platform's PHP
session. Nothing in this app can read the platform's `$_SESSION`.

### 3.1 Login

`POST /api/v1/auth/login`

```jsonc
{ "username": "abebe", "password": "…",
  "installation_id": "…",        // optional, see §6
  "device_fingerprint": "…" }    // optional, see §6
```

200:

```jsonc
{ "status": "success", "data": {
    "token": "…",                 // access token, 15 min
    "refresh_token": "…",         // rotating, 90 days
    "expires_in": 900,
    "device_bound": true,         // if true → every refresh AND every API call needs a DPoP proof
    "user": { "id": 7, "username": "…", "full_name": "…", "email": "…",
              "role": "member", "is_active": true,
              "profile_image": { "present": false, "version": null, "url": null },
              "profile_version": "…", "authorization_version": 1 }
} }
```

Failure modes worth handling distinctly:

| Status | `code` | Meaning and what the UI should do |
|---|---|---|
| 401 | — | Wrong username or password. Generic message, no oracle. |
| 403 | `activation_pending` | Credentials are right, but the ACM ceremony is unfinished. **This is the important one**: the account cannot be activated by staff, only by completing device setup. Link to the activation flow. |
| 403 | — | Account inactive for another reason. "Contact the office." |
| 429 | — | 10 attempts/minute/IP. Show `Retry-After`. |
| 503 | `AUTH_SERVICE_UNAVAILABLE` | Retry later. |

Rate limits: login 10/min, refresh 30/min, logout 30/min.

### 3.2 Refresh

`POST /api/v1/auth/refresh-token` → `{ "refresh_token": "…" }`

Refresh tokens are **single-use and rotating**. Presenting a used one returns
`401 REFRESH_REUSED` and revokes the whole family — the user is signed out.

**Implementation rule:** the refresh must be single-flight. Two concurrent
refreshes will invalidate each other and sign the user out. Guard with one
in-flight promise in `assets/js/` and a server-side lock if refresh ever happens
server-side.

Codes to handle: `REFRESH_EXPIRED` (re-login), `REFRESH_REUSED` (re-login,
explain), `SESSION_REVOKED` (re-login), `ACCOUNT_DISABLED`, `ACCOUNT_REMOVED`,
`DEVICE_PROOF_REQUIRED` / `DEVICE_PROOF_INVALID` (see §6),
`AUTH_SERVICE_UNAVAILABLE` (retry later).

Success: same shape as login (`token`, `refresh_token`, `expires_in`, `user`).

### 3.3 Verify, logout

- `GET /api/v1/auth/verify` → `{ "valid": true, "user": {claims}, "expires_at": "…" }`
- `POST /api/v1/auth/logout` → `{ "refresh_token": "…" }`, revokes the family.
  Idempotent: an empty token still returns success.

### 3.4 Every authenticated call re-checks authority

`apiRequireAuth()` re-validates against the database on the server for every
request (role and `authorization_version` must still match the token, and the
account must still be `is_active`). Consequences for this app:

- A role change or password change invalidates tokens mid-session →
  `401 AUTH_SCOPE_CHANGED` / `PROFILE_CLAIMS_CHANGED` (`409`). **Handle both by
  refreshing once, then signing out if it recurs.**
- Tokens cannot be forged, extended or kept alive client-side. Do not try.

### 3.5 Token storage on the web

The platform's own model is a server-side session; this app cannot use it. For a
browser the options are:

| Option | XSS risk | CSRF risk | Note |
|---|---|---|---|
| `localStorage` | token readable by any XSS | none for the API (no cookies) | simplest; acceptable only with a strict CSP and no third-party JS |
| `HttpOnly` cookie set by this app's own server | not readable by JS | needs CSRF protection on state-changing calls | requires the app to proxy API calls (§1 of [01](01-SYSTEM-CONTEXT.md#6-cross-origin-consequences-you-must-plan-for)) |

**Decision owed** — [05 §4](05-OPEN-DECISIONS.md#4-token-storage-in-the-browser).

---

## 4. Who may see what

Columns: what a **member** token can do today, and what a **parent** token can
do today. `—` means "not authorised"; `✅` means reachable with normal scoping.

### 4.1 Profile and self-service — `api/v1/routes/users.php`

These routes carry **no role gate at all**: any authenticated account, including
`member` and `parent`, reaches them.

| Endpoint | Member | Parent | Notes |
|---|---|---|---|
| `GET /users/me` | ✅ | ✅ | Own profile. Includes `profile_version` (optimistic concurrency) |
| `PATCH /users/me` | ✅ | ✅ | Requires `profile_version`; changing username/full_name → `claims_refresh_required: true` |
| `GET/POST/DELETE /users/me/profile-image` | ✅ | ✅ | Ownership derived from the token, never from a client id |
| `POST /users/change-password` | ✅ | ✅ | `current_password`, `new_password`, `confirm_password`; rate-limited 5/15 min |
| `GET /users/me/children` | — | ✅ | `403` for non-parents. Verified links only |

### 4.2 Member records — `api/v1/routes/members.php`

| Endpoint | Member | Parent | Notes |
|---|---|---|---|
| `GET /members` (list) | — | — | Info/Education staff only |
| `GET /members/{id}` | — | ✅ *own verified children only* | Parent path is `apiParentCanSeeMember()`, deny-by-default. Returns **safe row** (no phones/address) |
| `GET /members/{id}/attendance?days=90` | — | ✅ own children | Stats + records. **This is the first real screen a parent app shows** |
| `GET /members/{id}/attendance-risk` | — | — | Department staff |
| `GET /members/{id}/department-status` | — | — | PII roles / HR / Edu / Mezmur |
| `PUT /members/{id}`, `POST /members` | — | — | Staff only; `POST` is not supported by the API at all (web-only HR workflow) |
| `POST /members/{id}/photo` | — | — | Staff/photo roles |

**The member gap:** `GET /members/{id}` has no member self-path. A member token
gets a 403 for every id, including its own. See
[05 §2](05-OPEN-DECISIONS.md#2-no-member-side-authorisation-model).

### 4.3 Routes a member/parent token should NOT reach

Verified by reading the gate at the top of each file. This list exists so you do
not build UI against something that returns 403 in production.

| Route | Gate |
|---|---|
| `classes`, `dashboard`, `notifications` | `apiRequireStaffRole()` (allow-list; member/parent absent) |
| `attendance` (+ `attendance/warnings`) | `apiRolesAttendance()` |
| `grades`, `grades/followups` | `apiRolesGrades()` |
| `subjects` | education roles + `teacher` |
| `timetable` | education roles + `teacher` |
| `teachers`, `enrollment` | education roles |
| `mezmur` | `$MEZMUR_ROLES` (staff) |
| `sync` | `apiRolesAttendance()` |
| `hr` | retired, `410 HR_ATTENDANCE_RETIRED` |
| `telemetry`, `app` | no auth by design (device facts / APK download) — client-only, no member data |

So today a member's usable surface is `/users/me*` plus the ACM device routes,
and a parent's is that plus `/users/me/children` and
`/members/{childId}(/attendance)`.

### 4.4 The "safe row" — know exactly what you receive

`apiMemberSafeRow()` is what a parent gets for a child:

```
id, member_code, student_name, father_name, grandfather_name, full_name_am,
gender, age_group, status, member_type, current_section, photo_url,
created_at, (current_class when available)
```

No phone, no address, no date of birth, no baptismal name. Staff PII roles get
`apiMemberStaffRow()` instead. **Do not design a screen that assumes a field
exists because the database has it.**

`photo_url` is absolute (`SITE_URL`-prefixed) or root-relative. It is a public
upload path, not an authenticated stream — treat it as public.

Every member id, including a parent's own child, must come from the API, never
from a URL the user can edit to another number. The API enforces this; the app
must not create the impression that a client-supplied id is trusted.

---

## 5. The ACL in one table

`api/v1/core/acl.php`. Useful for reading errors, not for re-implementing:

| Helper | Roles |
|---|---|
| `apiRolesStaff()` | super_admin, school_admin, info_dept, hr_dept, photo_importer, photo_dept, edu_dept, finance_dept, material_dept, mezmur_dept, teacher, attendance_taker, content_editor, mezmur_attendance_taker, hr_attendance_taker, accounts_office |
| `apiRolesPii()` | info_dept, school_admin, super_admin |
| `apiRolesEducation()` | edu_dept, school_admin, super_admin |
| `apiRolesAttendance()` | teacher, attendance_taker, edu_dept, school_admin, super_admin |
| `apiRolesGrades()` | teacher, edu_dept, school_admin, super_admin |
| `apiParentCanSeeMember()` | a `parent` whose guardian link to that member is **verified** (`member_guardians.verified_by IS NOT NULL`) |

Note the audit note in that file: `member` and `parent` are absent from
`apiRolesStaff()` **on purpose** — those accounts are created only by ACM
activation, never by staff user creation. An allow-list, so any future role
fails closed.

---

## 6. The browser crypto problem (you will hit this first)

The platform's device registration expects input shaped for Android. A browser
can produce all of it, but **one conversion is mandatory and nothing in the
platform hints at it.**

### 6.1 What the server accepts

From `acm/src/Device/DeviceCrypto.php` (authoritative):

| Input | Format | Size |
|---|---|---|
| `pubkey` | base64 of **X.509 SubjectPublicKeyInfo DER** | ≤4096 B; P-256 is 91 B |
| challenge | 32 random bytes, transported base64url, stored hash-only | exactly 32 B |
| signature | base64 of **DER-encoded ECDSA** for ES256 | ≤4096 B |
| curve | **P-256 only** (`prime256v1`); RSA ≥2048 accepted for legacy Android | — |
| `installation_id` | `[A-Za-z0-9._:-]{1,128}`, client-generated and stable | — |

`DeviceCrypto::verify()` calls `openssl_verify($challenge, $signature, $pem, OPENSSL_ALGO_SHA256)`,
which requires **DER**. Android's `SHA256withECDSA` emits DER natively, so the
docblock's "no P1363 conversion on either side" is true for the platform's only
client today.

### 6.2 What WebCrypto produces

`crypto.subtle.sign({name:'ECDSA', hash:'SHA-256'}, key, data)` returns **raw
`r||s`, 64 bytes** (IEEE P1363), not DER.

Verified in this sandbox: P-256 SPKI export is **91 bytes** (fits), and the
signature is **64 bytes** starting with a random byte — OpenSSL rejects it.

```
raw r||s (64 B) accepted by OpenSSL: false
DER      (70 B) accepted by OpenSSL: true
```

### 6.3 The conversion you must write

~15 lines, no library needed: split at 32 bytes, strip leading zeros, prepend
`0x00` when the high bit is set, wrap each in `0x02 <len>`, wrap both in
`0x30 <len>`.

```js
function rawToDer(raw) {                       // raw: Uint8Array(64)
  const toInt = (b) => {
    let i = 0; while (i < b.length - 1 && b[i] === 0) i++;
    let v = b.slice(i);
    if (v[0] & 0x80) v = new Uint8Array([0, ...v]);
    return v;
  };
  const r = toInt(raw.slice(0, 32)), s = toInt(raw.slice(32, 64));
  const body = new Uint8Array([0x02, r.length, ...r, 0x02, s.length, ...s]);
  return new Uint8Array([0x30, body.length, ...body]);
}
```

The DPoP proofs (§6.5) need the **same** conversion — `verifyDpopSignature()`
also calls `openssl_verify`.

### 6.4 The activation ceremony, end to end

No session is required for the first two steps; protection is the code material
plus throttling.

```
1. POST /acm/activate/verify        {selector, secret, purpose, installation_id?}
   → {grant, operation, expires_at}
   NOTE: strict body — an unknown field is a 400. Send exactly these keys.

2. POST /acm/activate/credentials   {grant, password, idempotency_key}
   → {user_id, username, account_type, account_status:'account_pending_device',
      resume:'devices/register/begin', device_grant?}
   NOTE: password rules are the platform's (PasswordPolicy), minimum length is
   not assumed here — surface the API's error, do not pre-validate to your own
   rule. `idempotency_key` must be stable across retries.

3. POST /acm/devices/register/begin {grant, installation_id, pubkey, name}
   → {challenge_id, challenge (base64url), expires_at}

4. POST /acm/devices/register/complete {challenge_id, challenge, signature}
   → {device_uuid, fingerprint, status}

5. POST /auth/login (with installation_id + device_fingerprint)  → tokens,
   device_bound: true
```

Storage decision: the private key must live where it survives a page reload but
not a reinstall — **IndexedDB**, extractable P-256 key, per `installation_id`.
`localStorage` holds only the public key and `installation_id`. Losing the
private key means re-running the ceremony through the office; say so in the UI.

Recovery (R-codes) is the same machinery: `POST /acm/reset/verify` then
`/acm/reset/credentials`.

### 6.5 DPoP: the part that will surprise you later

`device_bound: true` means **every request** — not just refresh — needs a
`DPOP:` header (verified in `apiRequireAuth()`), and a *different kind* of proof
than the refresh route:

| | Refresh (`/auth/refresh-token`) | Every other authenticated request |
|---|---|---|
| `htm` | `POST` | the actual method |
| `htu` | URL whose **path ends with** `/auth/refresh-token` | **exact** request path, trailing slash folded |
| `ath` | absent | **required**: base64url(sha256(access token)) |
| `jti` | unique, replay-tracked | unique, replay-tracked |
| `iat` | within skew (default 300 s) | same |
| header | `typ: dpop+jwt`, `alg: ES256`, `jwk` = **public** key (`kty:EC`, `crv:P-256`, no `d`) | same |
| signature | over `header.payload`, **DER** | same |

`ath` is 43 base64url chars (verified) against a 128-char cap. Because `htu`
binds the exact path, a proof is bound to one endpoint: generate per request,
never reuse.

Server defaults (seeded, may differ in production): proof skew 300 s, `jti` TTL
900 s.

**Practical warning:** with `jti` replay tracking and per-path binding, a
mis-built proof fails as `ACCESS_PROOF_INVALID` with no detail — deliberately no
oracle. Budget time for this; test against a device-bound account early rather
than assuming unbound (which is what a login without `installation_id` gives
you).

---

## 7. What the prototype expected vs what exists

The archived prototype (`docs/prototypes/member-app-2026-11-26/`) calls 29 paths
under `/api/…`. **None of them are the platform's API.** They belong to its own
throwaway backend (own `staff` tables, own session auth, no CSRF) which was
never adopted.

| Prototype area | Prototype path | Reality on the platform |
|---|---|---|
| Login / refresh | `/api/auth/login`, `/api/auth/me`, `/api/auth/logout` | `/api/v1/auth/login` and friends (§3) |
| Change password | `/api/auth/change-password` | `POST /api/v1/users/change-password` |
| Lessons / materials | `/api/learning/*`, `/api/past-papers` | **Nothing.** No lessons, materials or past papers exist in the API |
| Quiz engine | `/api/quiz/*` | **Nothing.** No quiz/question storage on the platform |
| Progress, weak topics | `/api/progress/*` | **Nothing** |
| Tasks | `/api/tasks` | **Nothing** |
| Verse of the day | `/api/verse/today` | **Nothing** |
| Notifications | `/api/notifications*` | Exists as a **staff** route — a member token gets 403 |
| Attendance | `/api/attendance` | Staff route; parents use `/members/{id}/attendance` |
| Permissions | `/api/permissions` | **Nothing** |

**So the prototype's visual shell is reusable and its data layer is not.** Two
honest options for the frontend scaffold
([06 §3](06-FIRST-TASKS.md#3-frontend-scaffold-from-the-prototype)):

- **Scaffold what exists**: sign-in, activation, profile, children list, child
  record and attendance. Small, buildable now, real.
- **Scaffold the prototype's shape**: all pages, with a stub `Repository` for
  the missing areas. Looks complete, does nothing, and risks cementing a data
  model nobody has approved (lessons/quizzes would need new platform tables or
  a new service).

The handbook recommends the first, and says so with a reason in
[05 §1](05-OPEN-DECISIONS.md#1-what-happened-to-the-prototype-features).

---

## 8. Endpoint reference the app can rely on today

Copy this into `Repository/` when it is written.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/ping` | none | liveness |
| POST | `/auth/login` | none | tokens |
| POST | `/auth/refresh-token` | refresh token | rotate |
| POST | `/auth/logout` | refresh token | revoke family |
| GET | `/auth/verify` | bearer | token check |
| POST | `/acm/activate/verify` | none | redeem activation code |
| POST | `/acm/activate/credentials` | grant | set password |
| POST | `/acm/reset/verify` | none | redeem recovery code |
| POST | `/acm/reset/credentials` | grant | set new password |
| POST | `/acm/devices/register/begin` | grant **or** bearer | register device, get challenge |
| POST | `/acm/devices/register/complete` | possession (signature) | finish registration |
| GET | `/acm/devices` | bearer | own devices |
| POST | `/acm/devices/revoke` | bearer | revoke own device |
| GET | `/users/me` | bearer | own profile |
| PATCH | `/users/me` | bearer | update own profile |
| GET/POST/DELETE | `/users/me/profile-image` | bearer | own photo |
| POST | `/users/change-password` | bearer | own password |
| GET | `/users/me/children` | bearer, `parent` | linked children |
| GET | `/members/{id}` | bearer, scoped | one member (safe row for parents) |
| GET | `/members/{id}/attendance?days=` | bearer, scoped | attendance history + stats |

Everything else needs a staff role. Treat the list as the app's whole data
surface until §2 of the open decisions is answered.
