# Search-as-you-type without flooding the database

How Google, Algolia and Elasticsearch-backed products keep instant
search cheap — and what this codebase now does.

---

## The problem, measured

Debounce was the only protection. Measured against the real pipeline:

| Typing pattern | Debounce | Requests/min (1 user) | Server cap 240/min |
|---|---|---|---|
| Normal (120ms gap) | 160ms | ~30 | fine |
| Sustained, just above window | 160ms | **352** | **exceeded** |
| Sustained, after fix | 250ms | 230 | within budget |

Two honest observations:

1. **For normal typing the debounce already worked.** It coalesces a
   burst into a single request. My first instinct — "every keystroke
   hits the database" — was wrong, and the simulation corrected it.
2. **The flood is real for sustained typing and backspace-editing**,
   where keystrokes land just outside the debounce window so every one
   fires. One user could exceed the server's own limit and rate-limit
   themselves out of their dashboard.

Multiply by ~3 DB queries per search (word candidates + COUNT + page)
and the worst case was ~1000 queries/minute from a single person.

---

## The industry pattern: four layers

Nobody relies on debounce alone. The standard stack, outermost first:

### 1. Debounce — do not ask until they pause
250ms here. Nielsen's 0.1s "instant" threshold applies to **feedback**,
not the network round trip; the input echoes keystrokes immediately
regardless, so the extra 90ms is invisible.

### 2. In-flight dedupe — never ask the same question twice
Identical concurrent queries share one promise. Common when a user
backspaces and retypes the same characters.

### 3. Request supersession — cancel work nobody will read
**The layer that actually protects the database.** When a newer
keystroke arrives, the older request is aborted via `AbortController`.
The server stops computing a result set that will be discarded.

Without this, typing "hallelujah" makes the backend fully compute
results for `h`, `ha`, `hal`, `hall`… — all thrown away. This is why
Algolia and Google Instant abort aggressively rather than just
debouncing.

### 4. Client-side rate cap — degrade, do not fail
A hard ceiling of 90 requests/minute, deliberately far below the
server's 240. If something goes wrong (a stuck key, a loop), the UI
shows "Searching too quickly, pause a moment" instead of the server
returning 429 and the dashboard appearing broken.

### Already present, and kept
- **Server-side rate limiting** — 240 reads/min per user, the backstop
  that does not trust the client at all. Client limits are a courtesy;
  only the server limit is security.
- **Result caching** — repeated queries render from memory.
- **Sequence guards** — only the newest response paints, so a slow
  earlier reply cannot overwrite a newer one.

---

## What we deliberately did NOT do

**A dedicated search engine (Elasticsearch, Algolia, Meilisearch).**
The correct answer at millions of documents. This catalogue is ~73
hymns. Adding a search service would mean another process to run, index
to keep in sync, and thing to break — for a table that fits in memory.
Revisit at tens of thousands of hymns.

**Caching search results server-side (Redis).** Same reasoning: the
query cost is small and the working set is tiny. The client cache
already absorbs repeats.

**Minimum 3 characters before searching.** Common, and it does cut
load — but it makes short Amharic roots unsearchable, which is the
opposite of what this app needs.

---

## Error handling: what changed and why

The dashboard used to show, on an ordinary timeout:

> "the server backend may be outdated — ask the administrator to pull
> the latest code and run sql/024_mezmur_submissions.sql"

Wrong on two counts:

- **It leaked internals.** File paths, schema names, and the existence
  of migrations are reconnaissance for an attacker and meaningless to a
  user with slow Wi-Fi.
- **It was usually false.** A transient timeout is a network blip, not a
  stale deployment — which is exactly why reloading the page made it go
  away.

Now: a plain message, plus **one silent automatic retry** after 600ms.
That is precisely what the user was doing by hand when they reloaded, so
the app does it for them. POSTs are never auto-retried — they may
already have been applied server-side.

Operators still get full diagnostics from `?action=ping` and the browser
console.

---

## The four states every mutation must have

Adding a category appeared to freeze, then the row showed up after
switching tabs. Two causes, both now fixed:

- every handler ended `.catch(function () {})` — errors swallowed whole
- `loadCatalog()` refreshed state but never repainted the manager, which
  only rendered on tab switch

Any button that talks to a server needs all four:

| State | What the user sees |
|---|---|
| **Pending** | button disabled, "Saving…", cannot double-submit |
| **Success** | toast, and the new row appears immediately |
| **Failure** | the real server message; typed input preserved |
| **Always** | button restored, even if an exception was thrown |

Silent failure is the worst outcome: the user cannot tell "it worked"
from "it broke", so they retry and create duplicates.

---

## Still to verify in a browser

Simulated and syntax-checked, not observed:

- [ ] spinner appears on Add and the button cannot be double-clicked
- [ ] a duplicate category name shows the server's message, input kept
- [ ] the new row appears **without** switching tabs
- [ ] typing fast then pausing yields one result set, no error flash
- [ ] DevTools Network shows superseded requests as `cancelled`
