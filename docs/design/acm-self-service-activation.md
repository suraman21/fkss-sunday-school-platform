# ACM Self-Service Activation — design (v1 draft)

**Status:** design only, no code written. Pending review.
**Scope:** the platform (`fkss-sunday-school-platform`). The member app consumes
this; it does not implement it.
**Baseline:** `main` @ `73eb8d7`.

---

## 1. What changes, in one page

Today activation starts at the office. The intended flow starts with the
student. That single change is what everything below exists to make safe,
because a public endpoint that accepts "here is my ID number" is an
enumeration oracle and a ticket-flooding vector.

**Two hard constraints that are not negotiable:**

1. **No plaintext code is ever delivered digitally.** The current design shows
   the code once to an officer who reads it to the student in person. Emailing
   or texting it puts the secret on the same channel as the identity claim and
   collapses the two factors into one. **Decision D1 (§7) — I recommend keeping
   in-person delivery for v1.**
2. **Device binding stays.** `activate/credentials` leaves the account at
   `account_pending_device`; login returns `403 activation_pending` until a
   device proof succeeds. This is what makes a stolen code useless. It also
   creates the device-loss problem that §5 solves.

---

## 2. The flow, end to end

```
STUDENT                    PLATFORM                         OFFICE
   |                          |                                |
   |-- 1. request activation ->| (public, rate-limited)        |
   |   {member_code, full_name,|  creates acm_activation_request
   |    phone}                |  -> pending_review ------------>|
   |                          |                                |
   |                          |<-- 2. claim (office) -----------|
   |                          |<-- 3. decide: approve ---------|
   |                          |  reconciles request -> person   |
   |                          |  creates ticket (approved)      |
   |                          |<-- 4. issue code ---------------|
   |<- 5. code read in person --------------------------------|
   |                          |                                |
   |-- 6. POST /acm/activate/verify {selector, secret} ------->|
   |<-- grant (15 min) -------|                                |
   |-- 7. POST /acm/activate/credentials {grant, password} --->|
   |<-- 201 {username} + account_pending_device               |
   |-- 8. POST /acm/devices/register/begin {device_grant} ---->|
   |<-- {challenge_id, challenge}                             |
   |-- 9. POST /acm/devices/register/complete {signature} ---->|
   |<-- 200 device active                                     |
   |-- 10. POST /auth/login {username, password,              |
   |              installation_id, device_fingerprint} ------>|
   |<-- access + refresh (device_bound: true)                 |
```

Steps 6–10 exist today and work. Step 5 is the decision in §7. Steps 1–4, and
the three recovery paths in §5, are the work.

---

## 3. New surface

### 3.1 Public: request activation

`POST /api/v1/acm/activate/request` — unauthenticated.

Request (strict body; exactly these keys):

| field | rule |
|---|---|
| `member_code` | 1–40 chars, normalized uppercase, `[A-Z0-9-]` |
| `full_name` | 3–120 chars, compared normalized (case/space-insensitive) |
| `phone` | optional; last 4 digits compared only |
| `idempotency_key` | UUIDv4 |

Response — **always identical**, whatever the truth:

```
202 { "status": "success", "data": { "request_ref": "<8-char ref>" } }
```

`404`/`409`/`200`-with-different-body are all forbidden. A distinct response for
"no such member code" turns this endpoint into a member-directory scraper.

Honeypot fields are rejected (the "verify-then-ignore" contract, consistent with
the existing ACM ceremony).

### 3.2 Office: the request queue

Mirrors the existing ticket queue exactly — same claim/release/notes/decide
vocabulary, same `acm_*` audit events, so one officer workflow covers both.

| action | policy |
|---|---|
| `request_view` | `accounts_office` |
| `request_claim` | `accounts_office` |
| `request_decide` | `accounts_office` (maker-checker applies when office mode = maker_checker) |
| `request_reject` | `accounts_office`, reason mandatory |

### 3.3 Member self-service account

`GET /api/v1/members/me` — a **member's own** record. This is the change that
unblocks the app: `member` is in no allow-list today, and `GET /members/{id}`
denies a member their own row.

Returns the member "safe row" (§4 of the app handbook), never PII.

---

## 4. The identity bridge — the riskiest part

`acm_activation_request` is a *claim* by a stranger. Approving it must bind it to
a real `members` row. Nothing today does this match, and a wrong match gives one
person another's data.

### 4.1 Matching is explicit, never automatic

The office approves a request **against a specific `person_id`**, chosen by the
officer from a search. The platform does not guess. When the officer opens the
request, the console shows candidates from:

```sql
-- deterministic, indexed, no fuzzy scoring in SQL
SELECT id, member_code, full_name_am, full_name_en
  FROM members
 WHERE member_code = :claimed_code
    OR (status = 'active' AND phone_primary LIKE :last4)
 LIMIT 10
```

A candidate is **suggested** only when two independent fields agree (code AND
name-similarity AND, when both present, phone last-4). Everything else is the
officer's decision, recorded as `match_basis` on the record:

```
match_basis ENUM('member_code','member_code+phone','member_code+name','manual')
```

`manual` is allowed but flagged in the audit trail — that is the value an audit
exists to preserve.

### 4.2 What approval does

One transaction:

1. Lock the request row (`FOR UPDATE`), assert it is `pending_review`.
2. Assert the target person has no open ticket (see §6, R3 — currently racy).
3. Create `acm_persons` row if absent; link `member_id`.
4. Create `acm_activation_tickets` (status `approved`) with `member_id`.
5. Record `match_basis`, officer id, timestamp.
6. Transition request to `approved`; write audit event.
7. Commit.

---

## 5. The three recovery paths (currently missing)

These are not edge cases. Each is a support ticket today.

### 5.1 Code lost / expired
Office reissues via the existing `code_issue` panel. Already atomic: issuing
revokes the prior live code in the same transaction. **No new work.** The app
needs only to show "ask the office for a new code".

### 5.2 **Device lost** — the real gap
Today: the account is at `account_pending_device` (or active, device-bound), the
device is gone, and neither the student nor the office can recover it. The
office can revoke a device, but the student then needs a code of purpose
`device_add` (24 h TTL) to bind a new one.

**Design:** an office-initiated "**Re-issue activation code**" action for
`account_pending_device` tickets, and a "**Add a device**" action (purpose
`device_add`) for active accounts, both producing a code the officer reads out.
The machinery exists (`CodePurpose::DeviceAdd`); only the console action and the
policy entry are missing.

**What it took (built).** Two things were true, and both had to be handled:

1. `issueCode()` only runs for `AcmTicketService::ISSUABLE_STATUSES`
   (`approved`, `code_issued`, `grant_verified`, `expired`). An activated account
   is at `active` and a device ceremony at `account_pending_device` — neither is
   issuable. So "show the code panel on active tickets" cannot work; the panel
   would offer a button that always refuses.
2. Nothing else mints a `device_add` grant: the chained grant exists only inside
   an in-flight activation.

So recovery **creates a ticket**, through `AcmTicketService::create()`, at
`submitted` like any other. The officer approves it, and the *existing* panel
issues the `device_add` code from that ticket. Statuses are never rewritten and
no grant is minted directly.

Screen: `admin/acm_device_recovery.php` (nav: *Device recovery*), action
`device_recover`. Only ACTIVE, `is_active = 1` accounts are offered; a suspended
account is refused, not silently accepted. Requires a reason (3–2000 chars),
audited as `acm.device_recovery.requested`. `acm_activation_tickets` has no
discriminator column, so the reason is what tells a recovery ticket apart in
audit — do not drop it.

### 5.3 Password forgotten, device intact
Exists: `reset` purpose, 24 h, plus `/users/change-password` when logged in.
**No new work.** Note the reset path leaves the existing device intact, which is
correct — a password reset must not silently de-bind hardware.

---

## 6. Concurrency: every race, named

The existing ceremony is unusually well-built (I verified: `SELECT … FOR UPDATE`
on codes and tickets, `rowCount()` guards on consume, compare-and-set status
transitions, commit-before-throw so failure accounting persists). The new
surface must not regress that. In migration style used by this repo: one guarded
statement per block, no `FOREIGN KEY`, no JSON column type.

| # | Race | Guard |
|---|---|---|
| **R1** | Two identical requests, same microsecond | `UNIQUE (request_dedupe)` where `request_dedupe = SHA2(member_code,256)` for rows in `pending_review`… **not expressible as a partial index in MySQL.** Use a **dedupe row**: `UNIQUE (dedupe_hash)` on an insert-first table; the second insert fails with `23000` and is caught → returns the same `202` + the existing `request_ref`. Idempotent by construction. |
| **R2** | Same person requests twice with different idempotency keys | `UNIQUE (person_id, account_type, status IN open)` is likewise not expressible. Enforced inside the approval transaction by re-checking under `FOR UPDATE`; at the request layer, repeated requests are **allowed but collapsed** — the queue shows one row per `dedupe_hash` with `request_count`. |
| **R3** | Two open tickets for one person (existing bug) | No unique key today; `AcmTicketService::create()` pre-checks then inserts, which two concurrent office sessions can both pass. **Add** a `ticket_guard` generated column (`person_id`,`account_type`,`is_open`) with a `UNIQUE` key, or (simpler, portable) an approval-time `FOR UPDATE` re-check plus a `GET_LOCK` on `acm:ticket:<person>`. I recommend the unique-key form: it is declarative and cannot be forgotten by a future caller. |
| **R4** | Office approves the same request twice | `UPDATE … SET status='approved' WHERE id=? AND status='pending_review'`, assert `rowCount()===1`, inside the transaction. Same compare-and-set discipline as `verifyCode`. |
| **R5** | Student redeems a code twice concurrently | Already correct: `lockedCode()` takes `FOR UPDATE`; consume asserts `rowCount()===1`; loser gets the same generic failure. |
| **R6** | Two `establishCredentials` calls with different idempotency keys | Already correct: grant consume is compare-and-set; second gets `GRANT_CONSUMED`. |
| **R7** | Two `establishCredentials` with the **same** key (retry after timeout) | Already correct: idempotency lookup precedes consumption; the documented uncertain-response replay works. |
| **R8** | Device registration replayed | `challenge_id` is single-use; `completeFull` commits the activation transaction. **Verified** (§11): challenges and enrollment grants are consumed by compare-and-set and `rowCount() !== 1` takes a deterministic `replayCompleted()` branch. |
| **R9** | Expiry job vs live ceremony | Already handled: job takes `FOR UPDATE` and re-checks inside the transaction (`acm_pending_expiry.php:157,267`). |
| **R10** | Request flood (public endpoint) | DB-backed atomic limiter (`SecurityRateLimiter`, `INSERT … ON DUPLICATE KEY UPDATE`) — already race-safe, verified. Buckets: per IP, per `member_code`, and a global ceiling. Plus a cap on open requests per `dedupe_hash`. |

**Rule for all new code:** every state transition is a compare-and-set with a
`rowCount()` assertion inside a transaction. Never read-then-write.

---

## 7. Decisions I need from you

| # | Question | My recommendation |
|---|---|---|
| **D1** | Code delivery: in person, or SMS/email? | **In person for v1.** Digital delivery merges the two factors. If SMS is wanted later, the code must be split: SMS carries the selector, the officer gives the secret — or vice versa. |
| **D2** | Is the public request endpoint wanted at all, or does the office keep creating tickets? | Build it, but **returning the same generic `202`** and requiring the office to match. Without it, a student who walks in with no ticket has no path. |
| **D3** | Username: auto-generated `m<member_code>`, or chosen by the student? | **Keep auto-generated; let it be changed once after first login** (`PATCH /users/me` already allows it). Avoids a uniqueness UX and a squatting problem at activation time. |
| **D4** | Should `members/me` be a new route, or `GET /members/{id}` relaxed for member-but-own? | **New route `/members/me`.** Relaxing an id-taking route invites an IDOR regression; a self route cannot be pointed at someone else by construction. |
| **D5** | `account_type` for a student requesting their own activation | Always `member`. `parent` requesters need a different identity proof (guardian phone match) — **out of scope for v1**, office-created tickets only. |

---

## 8. Migration plan

`099` … (next free numbers; verified highest is `098`). Each idempotent and
re-runnable, guarded ALTERs, no `FOREIGN KEY`, no JSON column type.

| # | file | contents |
|---|---|---|
| 099 | `acm_self_service_requests.sql` | `acm_activation_requests` (id, uuid, request_ref, dedupe_hash UNIQUE, member_code, full_name_given, phone_last4_given, account_type, status enum, request_count, claimed_by, decided_by, match_basis, person_id, ticket_id, timestamps, indexes) + `acm_request_dedupe` if needed |
| 100 | `acm_ticket_open_guard.sql` | generated `is_open` column + `UNIQUE (person_id, account_type, is_open)` with a pre-flight duplicate report (never auto-delete — the repo's rule, per migration 018/031) |
| 101 | `acm_request_events.sql` | event vocabulary for the audit timeline (mirrors `acm_ticket_events`) |

---

## 9. Test plan (each named after the race it covers)

- `test_acm_request_generic_response.py` — unknown code, known code, mismatched
  name **all** return an identical `202` body and status.
- `test_acm_request_dedupe.py` — two concurrent inserts with the same
  `dedupe_hash` yield exactly one row and one `request_ref`; the loser sees 202.
- `test_acm_ticket_open_guard.py` — two concurrent `ticket_create` for the same
  person: exactly one succeeds, the other gets a clean error, zero orphans.
- `test_acm_approve_idempotent.py` — double `request_decide` → one ticket, one
  event, second call a no-op.
- `test_acm_reissue_device_add.py` — device-loss path produces a `device_add`
  code; redemption binds a new device while the old stays revoked.
- `test_members_me_member_role.py` — `member` token → own row; `parent` token →
  unchanged behaviour; staff unchanged; a member cannot reach another id.
- `test_acm_request_rate_limit.py` — the limiter's buckets trip within the
  documented window, and the 429 carries `retry_after`.

---

## 10. What the member app can build against, immediately

`/acm/activate/request` (202), `/acm/activate/verify`, `/acm/activate/credentials`,
`/acm/devices/register/{begin,complete}`, `/auth/login`, `/users/me`,
`/members/me`. Everything except the first and last already exists.

---

## 11. Honest limitations

- **No automated name matching.** Fuzzy scoring in SQL invites false approvals;
  the officer decides, assisted. If a name-match score is wanted later it
  belongs in PHP with an explicit threshold and an audit record of the score.
- **The request surface is an enumeration oracle by design**, mitigated — not
  eliminated — by uniform responses and rate limits. Anyone can confirm that
  *some* member exists by watching whether an approval eventually happens; they
  cannot do it at scale or in one request.
- **`allow_url_fopen`/`curl` cost:** no. Not relevant here.
- ~~I have not verified `DeviceService` replay protection (§6 R8).~~
  **Now verified.** `DeviceService` consumes challenges and enrollment grants by
  compare-and-set: `rowCount() !== 1` takes a deterministic `replayCompleted()`
  branch. No assumption remains in §6.

---

## 12. What the console has now (built)

| Screen | Action | Guard |
|---|---|---|
| `admin/acm_requests.php` | — (list) | `RES_REQUEST` `view` |
| `admin/acm_request.php` | `request`, `request_approve`, `request_reject`, `request_claim` | `view` / `approve` / `reject` / `claim` |
| `admin/acm_device_recovery.php` | `device_recover` | `RES_CODE` `issue` |

`request_approve` computes `match_basis` **server-side** from the person the
officer picked — the client cannot assert a stronger basis than the data
supports. A searched person with no `member_id`/`guardian_id` shows
*"none — link it first"* and links to the person screen, rather than failing
after the officer has committed.

Tests: `tests/security/test_acm_self_service_activation.py` — 37 pins covering
the services, the endpoint contract, the console wiring and recovery.
