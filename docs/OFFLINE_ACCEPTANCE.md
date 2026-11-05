# Offline capability — acceptance contract

Status: Engineering acceptance targets derived from the owner's roadmap. Three policy decisions below were confirmed by the owner in conversation. The individual engineering targets are not completed or school-release-approved merely because they are documented.
Baseline for review: main merge 5651a64b0720dc094c1b6578115cd6cd83462ea4.
Request: reconstruct and audit the prior offline campaign, preserve verified safeguards, complete in small tested increments, and ask the owner about business-policy decisions.

## Safety and scope
- No production deployment, real school data access, destructive database resets, dependency upgrades or signing-policy changes.
- Existing ordinary SQLite must not be called encrypted.
- Preserve all pending work, original ownership, academic year, operation identity, attempted payloads and recovery evidence.
- Uncertainty blocks the affected change; it is not proof that an older commit is safer. Any rollback needs a dependency analysis, data-preservation tests and an explicit revert plan. Never reset shared history.
- Audit all 24 campaign merges (#54–#77), their full diffs, surviving consumers and tests. Inventory is not review. Mark each behavior source-verified, fixture-verified, device-verified, unknown or defective separately.

## Measurable acceptance scenarios
1. A server-defined preparation includes every supported authorized scope without scrolling through screens.
2. Wrong-role, unassigned and foreign-account requests disclose no private preparation data.
3. A complete empty scope is distinguished from failed or missing data.
4. An interrupted/partial refresh never activates a new snapshot or reports ready.
5. Failed refresh retains the last complete snapshot; current authority restrictions still apply.
6. Snapshot contents, authority, calendar, roster and workflow come from one coherent server revision/read boundary.
7. Preparation survives process restart and phone restart without network access.
8. A stale callback cannot publish across an account, session, authorization or preparation-generation change.
9. A tap is acknowledged as saved only after durable local persistence; storage errors remain visible and edits recoverable.
10. Rapid section/date switching never moves marks into the wrong context or overwrites newer edits.
11. Reusable roster availability does not depend on a successful attendance request.
12. Closed/foreign/unknown original-year records and pending work are preserved, never silently restamped or removed.
13. At the authorized lease deadline, new offline writes stop; already saved work remains attributable and recoverable.
14. Renewal requires authenticated current server validation; clock rollback cannot silently extend authority.
15. Revocation prevents server uploads after revocation is authoritative; disconnected-device discovery is explicitly bounded by reconnection/lease, not described as instantaneous.
16. Account B cannot access Account A's private data or inherit its workers, callbacks or pending work.
17. Logout cannot clear private work while settlement is pending, uncertain or under review.
18. Stable operation identity and immutable attempted-payload hashes survive retries, crashes and upgrade.
19. A timeout after server commit leaves the operation uncertain until an exact matching receipt is obtained.
20. Repeated/concurrent identical uploads produce one business effect and the same attributable receipt.
21. Reusing an operation ID with a different payload fails without mutation.
22. Business mutation and durable receipt commit atomically; rollback commits neither.
23. Current permissions, eligibility, year and revision are checked in the mutation boundary, including revocation races.
24. Conflicting devices do not silently overwrite changed server state; owner-approved conflict policy governs review.
25. Member additions, removal, transfer, promotion and inactive status refresh every prepared scope; pending original-roster evidence is retained.
26. Upgrades from supported legacy databases preserve pending IDs, payloads, history and dependencies; ambiguous work is quarantined, not dropped.
27. Interrupted migrations, orphan WAL/journal files and historical plaintext siblings cannot replace newer work with an empty/older database.
28. Foreground/background database and upload workers cannot double-settle or bypass session fencing.
29. UI distinguishes local saved, pending, uncertain, incomplete, stale, blocked, review and confirmed server settlement; ready is shown only for a complete eligible snapshot.
30. Synthetic automated tests and supported real phones cover rosters of 8, 153 and 500+ members, 7/14/30-day disconnection, low storage, process death, weak networks and multi-device conflict. Memory/latency/storage budgets require measured device evidence; no invented pass thresholds.

## Department and release gates
- **Two attendance scopes in SSMS** — Education Department attendance (by **class**) and Mezmur Department attendance (by **section**, `member_department_periods` when `attendance_v2` is on) — each require actual end-to-end coverage; one role does not prove the other, but both are served by the single atomic `GET /attendance/preparation` endpoint (predicate `classRestricted` + `attendance_v2`).
- **Department offline inventory** — Education attendance, Mezmur attendance, and **Grades** are offline-enabled today (`DepartmentOfflinePolicy.offlineEnabledToday = {educationAttendance, mezmurAttendance, grades}`) with truthful banners + refresh action and exact atomic `*_sync_receipt` drains (v40 attendance, v42 grades). Education (curriculum), members, HR, Finance and Materials remain **online-only** until separate supported-scope and dependency inventories land. Offline enrollment requires an owner decision.
- Encryption investigation is separate from the integrity migration; no encryption claim without measured migration/recovery and key-lifecycle tests.
- Passing CI is not deployment, independent review, school approval or phone acceptance.

## Owner decisions confirmed in this conversation
- Valid offline work affected by later assignment/member eligibility changes is preserved for authorized administrator review; no automatic application or deletion. Revoked accounts remain blocked from upload.
- Multi-device conflicts require review. Preserve the accepted server state and competing work; never silently overwrite.
- Renewal of the 30-day offline capture lease requires a successful complete authorized-data refresh. Login, partial refresh and permission validation alone do not renew it. Failed refresh preserves old work without extending the old lease.

## Remaining policy boundary
- Existing closed-year read-only rules remain in force. No cross-year capture extension or automatic restamping is authorized. Any new exception requires a separate owner decision.

## Implementation and evidence gates
1. Audit prior campaign diffs, consumers and tests; classify keep/fix/revert evidence. The 24-merge inventory is not an exhaustive behavioral review.
2. Implement bounded inactive preparation parsing, then full dataset validation and protected atomic storage/publication.
3. Integrate truthful readiness and local-first attendance per supported department.
4. Add exact atomic settlement/reconciliation before claiming full synchronization.
5. Complete account lifecycle, departmental extensions and real-phone release gates.

Each slice must document source trace, regression impact, unchanged contracts and exact test evidence in [ADR-0003](adr/0003-offline-first-mobile.md). All criteria above remain acceptance targets unless explicitly supported by scoped evidence; unit tests do not prove phone, deployment or school acceptance.
