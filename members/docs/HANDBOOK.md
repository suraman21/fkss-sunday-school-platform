# Members Web App — Standalone Repository Handbook

Everything a new chat needs to work on the members/parents web app **as its own
repository**, without access to the conversation that produced this folder.

| | |
|---|---|
| **Written** | 2026-11-26 |
| **Platform baseline** | `suraman21/fkss-sunday-school-platform`, commit `d7d27c1` |
| **App status** | Folder structure only. No behaviour, no routing, no styling. |
| **Scope** | Structure, integration contract, conventions, and the decisions still owed |

Read in this order: **01** (what this is and the constraints), **02** (the
folder), **03** (the integration contract — the long one), **04** (conventions),
**05** (what is blocked and on whom), **06** (first tasks).

---

## The one-paragraph summary

The members/parents web app is a separate PHP repository deployed on its own
subdomain. It has **no access to the platform's database, files or
`config.php`**. Everything it knows about a member arrives as JSON from the
platform's existing REST API at `/api/v1`. Those two facts determine the folder
layout, the layering, the security rules and the order of work. This handbook
records them, plus the traps — including one that will otherwise cost a day, in
[03 §6](03-API-INTEGRATION.md#6-the-browser-crypto-problem-you-will-hit-this-first).

---

## Contents

| File | What it answers |
|---|---|
| [`01-SYSTEM-CONTEXT.md`](01-SYSTEM-CONTEXT.md) | What the platform is, what this app is, what it may and may not do |
| [`02-FOLDER-STRUCTURE.md`](02-FOLDER-STRUCTURE.md) | The layout, why each folder exists, the layering rules |
| [`03-API-INTEGRATION.md`](03-API-INTEGRATION.md) | Auth, every endpoint a members app can use, payloads, errors, CORS, the gaps |
| [`04-CONVENTIONS.md`](04-CONVENTIONS.md) | Naming, autoloading, errors, security defaults, testing |
| [`05-OPEN-DECISIONS.md`](05-OPEN-DECISIONS.md) | What is blocked, what is verified vs unknown, who must decide |
| [`06-FIRST-TASKS.md`](06-FIRST-TASKS.md) | The next steps in order, starting with the frontend scaffold |

---

## Three corrections to anything said earlier in conversation

Recorded here because a new chat will only have this handbook.

1. **There is no `/members/*` route on the platform, and there must not be.**
   An earlier plan wired clean URLs through `BrowserPageRoutes` and `.htaccess`
   in the platform repository. That was wrong: this app is not served by the
   platform's dispatcher. Those changes were reverted; the platform is
   byte-identical to `d7d27c1`.

2. **The app does not `require` anything from the platform.** Not `config.php`,
   not `admin/backend/*`, not an `api/v1` route file. Integration is HTTP only.

3. **The app is not inside the platform repository.** The `members/` folder in
   the platform repository is a staging area for this structure; it moves to its
   own repository as its first commit there. See
   [06 §1](06-FIRST-TASKS.md#1-move-the-folder-to-its-own-repository).

---

## What is verified, and how

Every claim about the API in [`03`](03-API-INTEGRATION.md) was read from the
platform's source at `d7d27c1`, not recalled. Where something could not be
confirmed, it is marked **UNVERIFIED** with the reason. The two most important
verified findings:

- **A browser can complete the ACM activation ceremony.** The platform's
  `/api/v1/acm/*` endpoints are Android-agnostic: no Play Integrity, no
  platform attestation, no vendor check. The only device-specific input is a
  P-256 SPKI public key and a signature over a challenge — both of which
  WebCrypto produces. Verified by generating a key in WebCrypto and verifying
  its signature against the platform's own `DeviceCrypto::verify()`. One
  conversion is required; see [03 §6](03-API-INTEGRATION.md#6-the-browser-crypto-problem-you-will-hit-this-first).

- **The API already has a parent surface, and no member surface.** `parent` is
  in the ACL (`apiParentChildren`, `apiParentCanSeeMember`); `member` appears in
  no allow-list anywhere. A member token today is authenticated but authorised
  for almost nothing. See [05 §2](05-OPEN-DECISIONS.md#2-no-member-side-authorisation-model).
