# Architecture decision records (ADRs)

Short records of significant architecture and product decisions: context, decision, consequences and evidence. The format follows Michael Nygard's ADR template.

Records 0001–0020 were written on 2026-10-22 from the code, the commit history and dated planning documents; each decision was made on the date shown. Write a new ADR **before** making a significant decision (see [Writing a new ADR](#writing-a-new-adr)).

| ADR | Decision | Status | Date |
|---|---|---|---|
| [ADR-0001](0001-php-monolith-on-shared-hosting.md) | PHP monolith on shared hosting | Accepted | 2026-08-15 |
| [ADR-0002](0002-mobile-token-authentication.md) | Mobile API authentication with signed access tokens and rotating refresh tokens | Accepted | 2026-08-24 |
| [ADR-0003](0003-offline-first-mobile.md) | Offline-first Android app with outboxes, idempotent writes and a change feed | Accepted | 2026-08-19 to 2026-10-05 |
| [ADR-0004](0004-manual-idempotent-sql-migrations.md) | Hand-applied, additive and idempotent SQL migrations | Accepted (known debt) | 2026-08-15 |
| [ADR-0005](0005-direct-apk-distribution.md) | Distribute the Android app directly, with an in-app updater | Accepted | 2026-08-19 |
| [ADR-0006](0006-department-role-model.md) | One role per account, organised by department | Accepted | 2026-08-15 (HR department created); 2026-08-28 (department-owned takers) |
| [ADR-0007](0007-section-equals-age-group.md) | Section and age group are one concept with one definition | Accepted | 2026-09-12 |
| [ADR-0008](0008-ethiopian-calendar-only.md) | Ethiopian calendar for every user-facing date | Accepted | 2026-10-19 (EC-only sweep); EC/GC conversion since 2026-08-16 |
| [ADR-0009](0009-attendance-ownership.md) | Attendance belongs to Education and Mezmur; HR takes none | Accepted | 2026-10-06 (HR attendance retired); 2026-10-18 (removed completely) |
| [ADR-0010](0010-semester-based-grading.md) | Semester-based grading: 100% per semester, annual score as a plain average | Accepted | 2026-10-08 |
| [ADR-0011](0011-class-day-only-attendance.md) | Attendance only on class days, with a 45-day backdating window | Accepted | 2026-10-20 |
| [ADR-0012](0012-reporting-centre-and-data-boundary.md) | Reporting Centre: in-house governed registry; sensitive datasets for Information only | Accepted | 2026-10-18 (engine); 2026-10-19 (boundary) |
| [ADR-0013](0013-tiered-danger-confirmation.md) | Tiered confirmation for destructive actions | Accepted | 2026-10-21 |
| [ADR-0014](0014-branching-main-dev.md) | `main` is production; `dev` is main plus beta settings, and never flows back | Accepted | 2026-10-20 |
| [ADR-0015](0015-mezmur-media-object-storage.md) | Mezmur audio and art in object storage, with presigned URLs | Accepted | 2026-09-03 |
| [ADR-0016](0016-family-link-guardian-model.md) | Family Link: a guardian entity with typo-tolerant matching and a review queue | Accepted | 2026-10-17 |
| [ADR-0017](0017-no-member-registration-on-mobile.md) | Members are never registered from the Android app | Accepted | 2026-10-17 |
| [ADR-0018](0018-lesson-level-attendance.md) | Lesson-level Education attendance alongside the class-day sheet | Accepted | 2026-10-19 |
| [ADR-0019](0019-attendance-alert-thresholds.md) | Attendance warnings with per-department thresholds; no automatic deactivation by default | Accepted | 2026-10-19 |
| [ADR-0020](0020-score-limit-and-did-not-take.md) | Refuse marks above the maximum; a blank mark is "Did not take" and is excluded from the total | Accepted | 2026-10-22 |

## Writing a new ADR

1. Copy the [template](template.md) to `NNNN-short-title.md`, using the next number.
2. Fill in context, the options considered, the decision and the consequences. Link the evidence: planning document, issue or PR.
3. Open it in the same pull request as the change, or before it. Status starts as **Proposed**, and becomes **Accepted** once the maintainers or reviewers approve.
4. Never edit an accepted decision's content. To change it, write a new ADR and mark the old one **Superseded by ADR-NNNN**.
