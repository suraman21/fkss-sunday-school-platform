# ADR-0019: Attendance warnings with per-department thresholds; no automatic deactivation by default

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-19 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

Staff needed early warning when members stop attending, without the system silently changing a member's status.

## Decision

- Each department has a policy. Defaults: **5 / 8 / 10** consecutive absences for standard, prolonged and critical warnings; a low-rate warning below **75%** with at least **3** sessions; automatic deactivation **off**.
- Warnings are re-evaluated after every official attendance write, escalate in place and notify responsible staff.
- Department status changes are made by people unless a School Admin enables automatic deactivation.

## Consequences

- **Positive:** actionable, explainable warnings.
- **Negative:** evaluation runs inside write requests (no background job); thresholds need review after real use.

## Evidence

`AttendancePolicyService.php`; `AttendanceWarningService.php`; `sql/071_department_status_and_policy.sql`, `sql/072_attendance_warnings.sql`; commits `1b45e03`, `2a571c1`.
