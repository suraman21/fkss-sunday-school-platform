# Design Decision Record — the ACM console redesign (S12)

**Status:** implemented on `arena/6b9e53d4`, pending review.
**Scope:** every Access Control page (`acm_overview`, `acm_requests`, `acm_request`,
`acm_queue`, `acm_ticket`, `acm_person`, `acm_office`, `acm_audit`,
`acm_staff_enroll`, `acm_device_recovery`) plus the public activation contract.
**Governing references:** the AI Design OS constitution (especially §20 surfaces,
§22 accessibility, §30 no functional regression, §36 decision record) and the
Laws of UX.

This record exists because the change is large and mostly cosmetic at the
surface, which is exactly the kind of change that later gets reverted by
someone who cannot tell which parts were load-bearing. Each decision below
says what it replaced and what would break if it were undone.

---

## 1. The problem, stated precisely

The console worked. It was also hard to use in four specific ways, and "make it
modern" is not a diagnosis:

1. **Three pages rendered as raw HTML.** `acm_requests.php`, `acm_request.php`
   and `acm_device_recovery.php` called `acm_chrome_open()` without
   `acm_head()`, so no stylesheet was ever linked. They returned 200, their
   data loaded, and every existing test passed. Only a screenshot noticed.
   (`tests/security/test_acm_console_layout.py` now pins this.)
2. **The queue was a form.** Six filter controls, eight columns, and a
   row-level action that was a bare ticket number. The officer's actual job —
   "is this the person in front of me?" — was nowhere on the screen.
3. **The member's claim and the register's record were shown as peers.** That
   is how a wrong approval happens: the two look like one record.
4. **The count on the sidebar was attached to the wrong menu item.** The
   pending-request badge was hard-coded to `queue`, so it appeared next to
   "Activation queue" while the officer was looking at "Activation requests".

Nothing here is about taste. Every item is a way the screen misled the person
using it.

---

## 2. Decisions

### D1 — Light-first, dark preserved. Both are first-class.

**Chosen:** light is the document default (`data-theme="light"`); the stored
`wbws-theme` preference still wins, and the toggle still works. The dark token
set is defined in full alongside the light one.

**Rejected:** dark-only (the status quo), and dark-first-with-light-fallback.

**Why:** an accounts office works in daylight, on shared machines, often with a
parent reading the screen. A dark surface is harder to read there, photographs
badly when a case is being explained, and reflects in a projector. But "light
only" would take the console away from the people who chose dark, and that is a
regression, not a redesign.

**Cost accepted:** two token sets to keep in sync. Mitigated by a test that
fails on any `var()` without a definition (`TestDualTheme::test_no_undefined_custom_property`).

**If undone:** the theme toggle becomes a lie and half the palette is undefined.

### D2 — Extend the existing design system; do not replace it.

`admin/css/acm-v2.css` loads *after* `acm.css` and refines it. Every legacy
class the other pages depend on (`.np .cd .ct .tw .inp .btn .bg-* .acm-alert
.acm-empty .acm-filterbar .acm-btn-row`) keeps a definition.

**Why:** eight pages and the ticket screen use those classes and were not all
rewritten in this increment. Deleting them to "clean up" would have unstyled
pages exactly like the bug in §1.1.

**If undone:** pages outside this increment lose their styling silently.

### D3 — The table stays a table; the record becomes a card.

The queue and the requests list remain dense, scannable rows. The verification
card — photo, name, facts, decision — is the only card on that screen.

**Why:** the constitution warns against card-ifying dense queues. Cards cost
rows-per-screen and comparison-across-rows, which is exactly what a queue is
for. A single person's record is the opposite job: one object, read closely.
The shape follows the task, not a fashion.

### D4 — The claim is visually separated from the register.

"What the member submitted" sits in a dashed box, below and distinct from the
record, and is labelled as such.

**Why:** conflating the two is the failure this whole flow exists to prevent.
An officer who reads a merged view approves the claim, not the record.

### D5 — One primary action per screen; the decision is adjacent to its object.

Approve and reject live in a rail next to the record, not in a toolbar at the
bottom. The rail's first card is the only one carrying full elevation.

**Why:** Fitts's Law and proximity — the control belongs to the thing it acts
on. Von Restorff — one salient element per view, or none is salient. Hick's
Law — the old queue offered six simultaneous filters; the new one offers one
status control and two toggles that are actually used.

### D6 — Approval completes in place: approve → ticket → code.

Approving reveals the ticket number in the decision panel, then offers "Issue
the code", which reveals the six-digit OTP and the request reference together.
Nothing navigates. A "Review the next request" link appears when more work is
waiting.

**Why:** the officer is standing with the member. A page jump breaks the
conversation, loses the context, and makes the code a separate errand. This is
peak–end: the flow ends on the useful thing.

**What is preserved:** `acm_ticket.php` remains the long-running home of the
ticket (claim, release, notes, devices, cancel, reissue) and is linked from the
panel once the ceremony is done. Approval still creates a ticket, never a code;
issuance still goes through `ActivationService::issueCode()` with its own
policy, network gate, velocity check and single-display rule. **The console
gained no new way to mint a credential.**

**Constraint found while building:** the OTP is verified *against the request
reference*, so a code alone cannot activate anything. The panel therefore shows
the reference beside the code and the copy button copies both. Omitting it
would have left every member stuck at the next screen.

### D7 — Filled colour carries its own ink, and the palette is verified.

Every semantic fill has an `--v2-on-ok` / `--v2-on-bad` token rather than a
hard-coded white. The first pass put white text on the dark theme's light green
(`#3ecf8e`): **2.0:1**, unreadable. The dark hues are light and the light hues
are dark, so one ink cannot serve both.

**Verified, not eyeballed:** `TestContrast` computes WCAG ratios from the token
values in both themes and fails below 4.5:1. Confirmed by reintroducing the bug
and watching the test fail.

### D8 — Status is always words, never only colour.

Every chip carries a label; the status stripe on the card is backed by the
heading text on the same row.

**Why:** colour-blindness and black-and-white printing are both real in this
office.

### D9 — Motion is feedback, and can be switched off.

Two durations, one easing, and a `prefers-reduced-motion` block that disables
transitions and animations wholesale. The old drifting radial background is
gone: it animated forever on every page and communicated nothing.

### D10 — The console is operable from the keyboard.

Skip link to `#acmMain`; visible `:focus-visible` rings everywhere; `/` focuses
search on the list pages; `a` / `r` approve and reject on the verification page;
`Esc` returns to the queue.

**Deliberately bounded:** the shortcuts do not fire while a field has focus, so
typing a name containing "a" cannot approve somebody, and `a` stops working once
the approve step has been used.

### D11 — The match is described honestly.

`AmharicNameMatcher::compare()` reports `exact` only when the strings are
identical as written; when folding makes them equal but the letters differ it
reports `homophone` and says so ("Same sounds, different letters"). Both satisfy
the gate; only one is true.

**Why:** the earlier version returned `exact` for `ሰላም` against `ሠላም ገበረ`, and
the card told the officer "Spelled exactly as registered" about a visibly
different spelling — at the exact moment they decided to approve someone. A
small lie in the one place the console must not lie.

---

## 3. What was deliberately not done

- **No new dependencies.** No framework, no build step, no icon package. The
  console runs on the machines it already runs on.
- **No change to the policy model.** Maker–checker, solo mode, self-approval
  reasons, the network gate and every status transition are untouched. The
  redesign moved pixels, not authority.
- **No removal of the ticket screen.** Collapsing it into the request page
  would have required porting claim/release, devices, cancel and reissue — and
  the inline panel is a shortcut for the common path, not a replacement for the
  full one.
- **No invented metrics.** No fake charts, no sparklines over data that is not
  collected.
- **The member-facing form itself is not in this repository** (it ships in the
  mobile client). What changed here is the contract: `first_name` is accepted,
  `full_name` still works, and `docs/design/acm-self-service-activation.md`
  now documents the refusal instead of the old "always 202" rule.

---

## 4. Verification

| What | How | Result |
|---|---|---|
| Amharic folding | runtime probe: 40+ pairs incl. `አ`≠`ኡ`, `ሰላም`≡`ሠላም`, distance 1 not 3 | green |
| Detection gate | runtime probe on PGlite: unknown ID, wrong name, archived, ambiguous, homophone | green |
| Claim refusal | end-to-end `submit()`: 0 rows and 1 refusal log for every failure; identical messages | green |
| Rendered pages | requests list, verification card (match / homophone / no-match), queue, ticket | inspected |
| CSS integrity | no undefined `var()`; no unused token drift; braces balanced | green |
| Colour contrast | WCAG ratios computed for both themes; filled states use their own ink | green (was 2.0:1 in dark) |
| PHP syntax | `token_get_all(..., TOKEN_PARSE)` on every touched file | green |
| Test suite | full `tests/` minus e2e, diffed against the base commit | no new failures |
| JS syntax | `node --check` over every emitted script block | green |

The suite has 121 pre-existing failures on the base commit (attendance and
mobile-environment tests that need a live server). They were confirmed
pre-existing by running the same suite in a worktree at the merge base rather
than assumed.

---

## 5. Files

| File | Role |
|---|---|
| `admin/css/acm-v2.css` | the design layer: tokens, primitives, card, rail, list, states |
| `admin/acm_layout.php` | light default, skip link, badge ownership, theme icon, toast fallback |
| `admin/acm_requests.php` | request list: status segments with counts, search, scannable rows |
| `admin/acm_request.php` | the verification screen and the inline approve → issue ceremony |
| `admin/acm_queue.php` | ticket queue: working states only, SLA in words, ownership |
| `admin/acm_overview.php` | stat cards on the new tokens |
| `acm/src/Identity/AmharicNameMatcher.php` | folding, grapheme distance, honest kinds |
| `acm/src/Requests/AcmActivationDetector.php` | resolve exactly one member or refuse |
| `acm/src/Requests/AcmClaimRefused.php` | one message for every refusal |
| `sql/104_acm_detection_and_refusals.sql` | `acm_request_refusals` (no name, no FK) |
| `admin/acm_staff_enroll.php` | staff enrollment: status segments, token revealed in the approved row |
| `admin/acm_device_recovery.php` | recovery: person search, account list, reason dialog |
| `admin/acm_person.php` | persons and accounts: command bar, honest empty states |
| `admin/acm_office.php` | office mode and designations, in sentences |
| `admin/acm_audit.php` | monthly audit: report, attestation, failure states |
| `admin/acm_ticket.php` | ticket detail: evidence card, decision rail, code ceremony |
| `tests/security/test_acm_s12_console.py` | pins the properties above |

### Two defects this pass found, both invisible to a text-based test

Both pages failed in a browser and passed every static check, which is worth
recording because it is the shape of the defect rather than the instance:

1. **`acm_office.php` and `acm_audit.php` never loaded.** They called `load()`
   at the top level of their script, which the browser parses *before*
   `acm_chrome_close()` emits `acmApi`/`acmToast`. The first line threw
   `ReferenceError`, and the tables sat on "Loading…" for as long as anyone was
   willing to look at them. A screenshot of a page stuck loading looks like a
   slow server, not a bug.
2. **A failed sidebar query was reported as a failed ticket.** On
   `acm_ticket.php` and `acm_staff_enroll.php`, `overviewStats()` shared a
   `try` block with the data the page exists to show, so one failing aggregate
   put a red "temporarily unavailable" banner above a page that had loaded
   perfectly.

Both are now pinned: `TestScriptOrder` walks each page's script tracking brace
depth, and `TestTicketPageCeremony` asserts that issuing a code never
navigates.
