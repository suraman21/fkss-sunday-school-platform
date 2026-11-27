# ACM is an account-lifecycle department, not a registrar

**Status:** step 1 + step 4 implemented in this branch (see §4); steps 2–3, 5 pending.
The two corrected claims about `acm_persons` uniqueness are fixed in code comments.
**Supersedes:** the "Register person" workflow described in `docs/design/acm-self-service-activation.md` §2 and implemented in `admin/acm_person.php`.

---

## 0. The correction

ACM currently offers a **"Register person"** form (`admin/acm_person.php`, `person_create`
in `admin/api_acm.php`, gated `AcmPolicy::RES_PERSON / create`). That form creates a
*second, independent record of a human being* — `acm_persons` — from data an officer
types by hand.

That is wrong, and it is the root of the activation failure this branch was opened for.
A member already exists. HR created them, verified their guardian, and linked the
family. Asking the accounts office to re-enter that person is:

1. **duplicate ownership of identity** — two tables, two sets of edits, no reconciliation;
2. **a silent trap** — a member with no `acm_persons` row could not activate at all
   (the `no_account` refusal, fixed in `#99`), so the manual step was load-bearing
   and nobody knew;
3. **the wrong department's job** — exactly like asking `edu_dept` to register students
   before it can assign them to a class.

**The rule this document establishes:**

> HR is the only source of truth for *who exists*. ACM is the source of truth for
> *whether that person may sign in*. No other department creates people.

---

## 1. What already exists (verified, not assumed)

| Fact | Evidence |
|---|---|
| HR registration creates the member and links the guardian | `admin/hr_register_member.php:668` → `GuardianService::attachFromLegacy($conn, $id, $picked, 'hr')` |
| Guardians carry their own provenance | `guardians.source ENUM('hr','backfill')` (`sql/069`) |
| Family links carry a verification stamp | `member_guardians.verified_by`, `verified_at` (`sql/069`) |
| Parents may only see **verified** children | `apiParentCanSeeMember` filters `verified_by IS NOT NULL` (`api/v1/core/acl.php:159-178`) |
| `acm_persons` was *designed* as a mirror | it holds **both** `member_id` and `guardian_id` (`sql/079:31-32`) |
| `acm_persons.member_id` is **not unique** | plain `KEY idx_acm_person_member` (`sql/079:41`); `guardian_id` likewise (`:42`) |
| `acm_persons` need not mirror identity at all | `edu_dept` reads `members` directly and keeps no copy (`api_education.php:1878`) |
| `users` has `member_id` but **no `guardian_id`** | `sql/012:101` adds `member_id` only |
| A parent account resolves its guardian through `acm_persons.guardian_id` | `apiParentGuardianId` (`acl.php:126-153`) |

So the family graph that ACM needs **already exists and is already HR-verified**.
What is missing is the automatic projection of it into ACM's own tables — and the
removal of the manual path that was standing in for it.

---

## 2. The model: one direction, one owner

```
                    HR  (source of truth for PEOPLE)
                     │
        members ─────┼───── guardians
                     │           │
                     │      member_guardians
                     │      (HR-verified family link)
                     │           │
                     ▼           ▼
              ┌──────────────────────────┐
              │  acm_persons  (PROJECTION)│  ← derived, never hand-typed
              │  member_id / guardian_id  │
              └────────────┬─────────────┘
                           │
                  acm_person_accounts
                           │
                        users  (role = member | parent)
                           │
                    ACM (source of truth for ACCESS)
```

**Direction is absolute.** HR → ACM. ACM never writes to `members`, `guardians` or
`member_guardians`. If ACM believes the register is wrong, it says so and HR fixes it.

### 2.1 It is a reference, not a projection of data

The first draft of this document proposed *copying* each member into `acm_persons`
and reconciling drift. The owner rejected that, correctly: **edu_dept reads `members`
directly and keeps no roster of its own**, so there is nothing to sync and nothing to
backfill. ACM must work the same way.

So `acm_persons` holds **no identity at all**. It is an *account anchor*: the row that
`acm_person_accounts`, `acm_activation_tickets`, `acm_account_suspensions`,
`acm_activation_requests` and the device tables point at — because they already have
`person_id` columns and dropping that spine would be a rewrite, not a patch.

Identity is read **live, per request** by `AcmPersonService::view()`, which joins
`members` / `guardians` / `member_guardians` at that moment. Consequences:

- a member registered five years ago and one registered this minute are equally
  visible — there is no copy to be missing or stale;
- changing a name in HR changes what ACM shows, instantly, with no sync job;
- `acm_persons` keeps only what ACM genuinely owns: the ID document seen at the
  counter, and notes. Those are the only editable fields on the console page.

The anchor row is created idempotently by `ensureForMember()` / `ensureForGuardian()`
on first need — a `member_id` or `guardian_id` plus a non-name marker, nothing else.

---

## 3. Design decisions

### D1 — `acm_persons` is an anchor, created lazily, never copied, never backfilled

`ensureForMember()` / `ensureForGuardian()` create the anchor the first time ACM needs
it: a `member_id`/`guardian_id` and a marker, **no identity fields**. All reads go
through `view()`, which joins the register live.

**There is no backfill and no sync** — there is no copy to populate. A member who has
never touched ACM has no anchor, and that is fine: every screen reads the register,
and the anchor appears the moment an account action needs it.

### D2 — There is nothing to reconcile

Because no identity is copied, the four `members` write paths (HR registration, member
edit, import, Telegram approval) need no hooks, no fan-out and no reconciliation report.
An edited name is simply what ACM reads next time. This is the whole reason the live
read was chosen over a projection: it removes a class of bug instead of managing it.

### D3 — Parents are projected from the verified family link

For each `guardian` with `status='active'` and at least one
`member_guardians` row, one `acm_persons` row with `guardian_id` set. Guardian details
come from `guardians` (name, phones, address); **no** data is taken from
`members.guardian_*` text columns, which are the legacy denormalised copy.

**The verified-link rule is inherited deliberately.** A parent whose link is unverified
cannot see their child (`acl.php:159`), so projecting an unverified link would create a
parent account that sees nothing. Projection therefore **skips unverified links** and
reports them, so HR can verify. This is the single most important correctness rule in
this design.

### D4 — `person_create` is removed, not hidden

- `AcmPolicy` loses `RES_PERSON / create`.
- `admin/api_acm.php`'s `person_create` case is deleted, so no caller can reach it.
- `admin/acm_person.php?new=1` (and the prefill form added in `#99`) is replaced by a
  **lookup** that projects on demand.
- The ACM console gains a read-only "from the register" view: name, code, section,
  guardians, account state.

A removed capability cannot be re-enabled by a role grant; a hidden button can.

### D5 — ACM's job description, made explicit

ACM owns, per person:

- whether an account exists, of which type (`member` / `parent`);
- the activation ceremony, codes, tickets, devices;
- **password reset** and credential recovery;
- suspension / reactivation / deactivation;
- account audit trail.

ACM does **not** own: names, addresses, phone numbers, section, class, guardian
relationships, or registration status. Those are HR's and Education's.

### D6 — A read-only division guard

A test asserts the boundary: ACM-owned directories may not `INSERT`/`UPDATE`/`DELETE`
on `members`, `guardians`, or `member_guardians`. This is the enforcement that stops
the next well-meaning patch from "just fixing" a name in the ACM console.

---

## 4. Migration plan (no big-bang)

| Step | Change | Risk |
|---|---|---|
| 1 | `107_acm_person_identity_unique.sql` — **DONE**: duplicate report + `UNIQUE` added only where duplicate-free | low, no deletion |
| 2 | `ensureForMember()` / `ensureForGuardian()` anchor + `view()` live read | **DONE** (guardian half enforces the verified-link rule) |
| 3 | ~~backfill~~ — **not needed**: no copy exists to fill | — |
| 4 | Remove `person_create` from API + policy + console; add `person_resolve` | **DONE** — breaking by design |
| 5 | Boundary test | **DONE** — `test_acm_department_boundary.py` |

Step 4 is the behavioural break the owner asked for. Steps 1–3 must land first so no
member is left without a projection when the manual form disappears.

---

## 5. What this does NOT solve (stated plainly)

1. **`acm_persons.member_id` was not unique until migration 107.** Two simultaneous
   first-claims for one member could both insert. 107 adds the key **only where the
   data is already duplicate-free** and writes `acm_person_duplicate_report` otherwise,
   so a deployment carrying duplicates from the manual era does not wedge. The earlier
   commit message claimed the unique key already existed; it did not, and the code
   comments now say so.
2. **The verification stamp depends on data.** If `sql/092` (which back-stamps
   `verified_by = created_by`) was never applied to a deployment, a parent account
   exists but sees zero children — the failure looks exactly like "the app is broken".
   Worth one query on the development database before onboarding parents:
   `SELECT COUNT(*) FROM member_guardians WHERE verified_by IS NOT NULL;`
3. **No DB access here.** Every claim above is from schema and source, not from a
   running server. The dry-run reports are the first real evidence.
4. **This is a design.** Nothing in this PR changes runtime behaviour except the two
   corrected comments. Implementation should be reviewed as its own change, in the
   step order above.
