# Controlled release gates — real-phone acceptance

**Status:** Plan + checklist. Passing `flutter test` is **not** deployment, independent review, school approval, or phone acceptance. Every number below must be *measured on supported real phones* — no invented pass thresholds.

**Applies to:** `offline-first/preparation-dataset-validation` at `ae2e342`+ → `cc56a23` (Education attendance + Mezmur attendance + Grades, v42, 3 scopes, atomic receipts, fenced preparation, transactional logout). Encryption is out-of-scope (see `ENCRYPTION_INVESTIGATION.md`).

## 1. Reference lab (the only devices that count)

| Tier | Example | RAM | Storage | Network | Why |
|---|---|---|---|---|---|
| Low-end Go | Tecno Spark Go / Itel, Android 12 Go | 2 GB | 32 GB (keep 500 MB free before run) | 2G/3G + airplane | Replicates the teachers who file the “lost record” tickets |
| Mid-range | Samsung A14 / similar | 4 GB | 64 GB | 3G/4G + airplane | Reference for p95 latency/memory |

Both run the **same signed build** (`--release`, not debug), same year data, same `SITE_URL`.

## 2. Roster fixtures (acceptance 30)

Build three data sets from anonymized production-shaped data (not real PII):

- **Small:** 8 members, 2 classes (edge: 0-member fallback)
- **Normal:** 153 members, 3 classes (the reported median)
- **Large:** 500+ members, 6+ classes, 4 subjects × 3 assessments (stress)

Each has: `classes` + `subjects`/`assessments` + `members` + `academic_terms`. All three must pass every gate below.

## 3. Gates (measure, don’t assert)

For each fixture × each tier × Education attendance + Mezmur attendance + Grades:

### A. Offline preparation (scenarios 1–8)
1. **Server-defined scope without scrolling:** Sign in → one `GET /attendance/preparation` + one `GET /grades/preparation` → banner `ready` without opening any class sheet. Verify `DepartmentOfflinePolicy.offlineEnabledToday` = 3.
2. **Wrong-role/foreign-account:** Teacher-unassigned & wrong-role account `GET …/preparation` → 401/403, no private payload. B2 check: login as A, copy DB to B’s phone → B sees `staleSession` banner, cannot see A’s PII.
3. **Complete-empty vs. failed/missing:** Publish a complete-empty preparation (legal 0-member) → `ready` (distinct from `missing` banner).
4. **Interrupted/partial refresh:** Kill app mid-`fetchAndPublish` (or airplane + pull). Verify old snapshot stays, banner not `ready` for the new one.
5. **Failed refresh preserves old:** Mock 401/503 from proxy → old snapshot stays, no lease extension.
6. **One coherent revision:** Cross-check `write_year.id` + `preparation_id` + `issued_at` in snapshot vs. `GET /attendance/preparation` payload — all from one read-boundary.
7. **Survives restart without network:** Airplane → force-stop → reboot → open app → banner still `ready`, roster still browsable.
8. **Stale callback fenced:** Start `fetchAndPublish`, switch account (or bump `generation`) before it returns → stale callback throws `StaleLegacyWriteSession`, old snapshot untouched.

### B. Local-first save reliability (9–12)
9. **Tap → durable only after DB:** Tap attendance/grade → `Saved` only after `save*Local` transaction commits. Kill app during write → no phantom `synced=1`.
10. **Rapid section/date switch:** Fast swipe class/date/subject tabs while saving → marks never land in wrong context, newer edit never overwritten by older async load.
11. **Reusable roster survives attendance miss:** Save attendance that the server later rejects (e.g. closed year) → roster cache still there for next try.
12. **Closed/foreign/unknown year preserved:** Create pending row with `original_year` closed/foreign → not silently restamped/removed; `pending_*` row stays for review.

### C. Lease / revocation / cross-account (13–17)
13. **Lease deadline blocks writes:** Mock `now = deadline` (or wait) → banner `expired`, new offline save correctly blocked; old pending rows still attributable.
14. **Renewal requires server:** Roll device clock back past deadline → still `expired` (no silent extension); only a fresh `fetchAndPublish` success renews.
15. **Revocation bounded:** Revoke account server-side → offline device still has old lease until `deadline`/reconnect; after reconnect+401, uploads blocked. Document “not instant” — bounded by reconnect+lease.
16. **B cannot see A:** Login A → create pending → logout → login B (same phone) → B sees no `pending_*`, no `*_preparation_snapshot`, no `*_sync_receipt` (transactional wipe gate 6).
17. **Logout does not clear pending/uncertain/review:** If any `sync_state` in `pending`/`retry_wait`/`needs_attention`/`uncertain`, logout shows “X items need attention” and blocks destructive wipe (acceptance 17).

### D. Idempotency / atomicity (18–23)
18. **Stable client_op_id + payload hash:** Kill/retry 3× → same `client_op_id` + same payload hash on disk, no new row.
19. **Timeout after server commit → uncertain until receipt:** Proxy: server commits but app times out → `pending_*` stays `needs_attention`/`retry_wait` until exact `receipt_json` + `X-Request-Id` match returns.
20. **Replay = one business effect:** Send same `client_op_id`+payload twice (concurrently) → one DB row server-side, same receipt both times.
21. **Payload mutation rejected:** Same `client_op_id`, different payload → 409 `IDEMPOTENCY_CONFLICT`, no mutation.
22. **Atomic receipt:** Power-loss during `settleLegacyOperation` → both business row + `*_sync_receipt` rolled back (never one without the other). Verify with `adb shell kill -9`.
23. **Permission/year/revision in mutation boundary:** Change year/permission mid-offline → server rejects on sync (revocation race gate), pending stays for review.

### E. Real-world stress (24–30)
24. **Multi-device conflict → review, not silent overwrite:** Two phones edit same class/day/assessment → last writer does not silently overwrite; server keeps accepted state + competing work in review queue (per your “review, not overwrite” decision).
25. **Member add/remove/transfer/promotion/inactive:** Change roster server-side → next preparation refresh brings new roster; pending original-roster evidence stays.
26. **Upgrade from legacy DBs:** Install old APK with `v38` DB (8/153/500 rows) → install new `v42` → pending IDs/payloads/attempt history intact; ambiguous work quarantined (not dropped).
27. **Interrupted migration / orphan WAL/journal / plaintext sibling:** Kill during `onUpgrade` 38→42, or leave `.wal`/`.db-journal` + old `fkss.db` sibling → newest ciphertext/work wins, never an empty/older DB (acceptance 27).
28. **Foreground + background workers:** `SyncService` foreground drain + `android_background_sync` job racing → cannot double-settle or bypass `generation` fencing.
29. **Truthful UI states:** UI shows `local saved`/`pending`/`uncertain`/`incomplete`/`stale`/`blocked`/`review`/`confirmed` — `ready` only for complete eligible snapshot.
30. **Synthetic + real-phone matrix:** Fill the table below on both tiers, for 7/14/30-day airplane runs, low storage (100 MB free), weak net (50 kbps + 30% loss), and 2-phone conflict. Report **numbers**, not green ticks.

### Measurement template (copy per run)

| Run | Tier | Fixture | Dept | Prep publish ms (p50/p95) | Save tap→durable ms (p50/p95) | Peak RSS MB | DB on disk MB | Sync 50 rows s | Kill-recovery ok? | Lease/wipe ok? | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|
|  | Go | 8 | Edu att |  |  |  |  |  |  |  |  |
|  | Go | 153 | Grades |  |  |  |  |  |  |  |  |
|  | A14 | 500+ | Mezmur |  |  |  |  |  |  |  |  |

**Budgets are observed, not invented.** Record them and attach the `adb bugreport` + `logcat` + `dumpsys meminfo` for each run. A regression is “worse than last run on same phone/fixture”, not worse than an imagined number.

## 4. Before you tap “Release”

- [ ] All 30 scenarios above have a row in the table (or an explicit “not applicable, because …” with owner sign-off).
- [ ] No “encrypted at rest” text in release notes (we are ordinary SQLite until Phase 8 spike passes its 5 measurements).
- [ ] Rollback plan: previous APK + `v42` DB downgrade docs: old pending rows stay readable (quarantine if ambiguous).
- [ ] School sign-off on: key-loss UX (if encryption later), revocation bounded-not-instant wording, and “review not silent overwrite” wording.

---

*Next step:* If you want, cut a `beta/offline-v42` APK from `ae2e342`+ and run the table above. I can turn the rows you bring back into a `RELEASE_EVIDENCE.md` with the numbers.
