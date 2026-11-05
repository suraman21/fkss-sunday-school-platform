# Mezmur web search: request handling and verification

**Status:** maintained source reference. **Baseline:** `7209278` (2026-10-28). This page describes the inspected JavaScript path, not a new browser benchmark or proof of database cancellation. It is retained at its existing path for continuity; its subject is application behavior, not CI configuration.

## Request path

The library search handler in [frontend/js/mezmur.js](../../frontend/js/mezmur.js) updates the library filter, resets the page and calls `loadList()`. That function builds a list query, checks its in-memory result cache and calls `apiGet()` when needed. `applyList()` uses a sequence guard so an older response cannot replace newer results.

| Mechanism | Verified implementation | Limit |
|---|---|---|
| Input debounce | The `mzSearch` handler's executable `setTimeout` argument is **160 ms** | Nearby comments describe 250 ms; that is a documentation/implementation mismatch, not an implemented 250 ms delay |
| One-character input | A trimmed single character becomes an empty search filter before loading | This is not a promise of indexed one-character matching |
| Identical in-flight requests | `inflight[q]` shares a promise for the same query | This applies to the client wrapper, not every API caller |
| Supersession | `abortStaleGets()` calls `AbortController.abort()` for older wrapper requests where supported | Client abort does **not prove** that PHP or an already-running SQL query stops |
| Client request cap | `RATE_MAX = 90`, sliding window `RATE_WINDOW_MS = 60000` | A usability/load measure, not a replacement for server-side authorization or rate limiting |
| Result cache | Repeated cached list queries render through `applyList()` | In-memory results are not proof of a fresh server query |
| Latest-response guard | `lib.seq` is checked before rendering/applying results | Does not by itself prove every related catalog mutation refreshes all screens |
| Transient list retry | `loadList()` retries an eligible transient failure once after 600 ms | Aborted, superseded and nontransient errors are not handled as ordinary retry candidates |

The old page's request-per-minute estimates, catalog-size figures and claims about other companies were not freshly reproduced and are not current performance evidence. See Git history for the original proposal/measurements. Do not turn a browser request cancellation into an assertion of saved database work without a server-side trace.

## Failure behavior

`loadList()` ignores deliberate aborts and stale sequence results. A current failure renders a retryable error state; an append failure restores the page counter and keeps existing rows. Read the actual returned/displayed message before repeating a mutation. The inspected GET retry path is not permission to retry arbitrary POSTs: a write might already have reached the server.

The old explanatory comments disagree with the executed debounce value. No debounce, server policy or application behavior is changed by this documentation correction. A future performance change needs measured request/database traces, a scoped implementation review and regression tests.

## Browser acceptance checklist

These scenarios remain a verification checklist, not newly completed tests:

- [ ] Type and erase through empty, one-character and longer queries, including Amharic; the filter and results remain consistent.
- [ ] Inspect request timing against the executable debounce value.
- [ ] Verify same-query deduplication and supported superseded-request cancellation in browser developer tools.
- [ ] Delay older replies and confirm they cannot overwrite the latest list.
- [ ] Simulate a transient GET failure and verify the single retry and eventual visible result/error.
- [ ] Exercise the client cap without confusing it with the server's independent limits.
- [ ] Test category/catalog mutations for pending, success, failure and restored-control states; confirm refreshed rows appear without switching tabs.
- [ ] Check keyboard/touch use, narrow layout and readable error messages.

Use synthetic data and a disposable local server. Record browser, revision, fixture, observations and server traces for any performance claim. Do not test these scenarios against production under routine documentation authorization.
