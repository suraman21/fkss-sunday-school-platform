# Roles and permissions

**Status:** canonical for what is listed. **Verified against:** `main` at `46fbaa7` (2026-10-22). Sources: `admin/access_control.php`, `api/v1/core/acl.php`, `admin/backend/user-save.php`. Anything not evidenced in code is marked **Unresolved**.

## 1. Roles defined in code

`admin/backend/user-save.php` (`$validRoles`) accepts 13 roles:

| Role | Abbreviation | Purpose (from code comments and dashboards) |
|---|---|---|
| `super_admin` | SA | Full system administration: users, backups, app releases, identity tools |
| `school_admin` | AD | School-wide administration; can impersonate other roles |
| `hr_dept` | HR | Member registration, temporary → permanent promotion, ID cards, imports, groups |
| `info_dept` | INFO | Member information: view and update records, reports, analytics |
| `edu_dept` | EDU | Education: classes, subjects, assignments, timetable, calendar, assessments, report cards |
| `mezmur_dept` | MEZ | Mezmur ministry: hymn catalogue, media, department attendance takers |
| `finance_dept` | FIN | Finance department pages |
| `material_dept` | MAT | Material department pages |
| `teacher` | TCH | Class-scoped: attendance and grades for assigned classes |
| `attendance_taker` | AT | Class-scoped attendance |
| `content_editor` | CE | Public website content (CMS) |
| `mezmur_attendance_taker` | MEZ-T | Department-owned attendance taker, created through `api_dept_takers.php` |
| `hr_attendance_taker` | HR-T | Department-owned attendance taker, created through `api_dept_takers.php` |

`user-save.php` is limited to `super_admin` by `ROLE_MAP`. In practice, therefore, only super admins create staff accounts through it, even though its internal `$rolePermissions` table also lists `school_admin` and `edu_dept`. Department takers are created through `api_dept_takers.php`.

## 2. Web access control (`admin/access_control.php`)

`config.php` includes this guard, which checks the following in order:
1. CLI requests are not blocked.
2. `api_mobile.php` is skipped, because it authenticates with its own token.
3. Only scripts under `/admin/` and `/backend/` are guarded. `ROLE_MAP` keys are bare file names, so an entry applies to that file name in any of those folders, for example `admin/dashboards/hr-dept.php`.
4. A short list of public pages (login, logout, the login handler, the mobile session bridge, the theme file) is allowed without a session.
5. Every other page needs a logged-in user.
6. If the page is listed in `ROLE_MAP`, the user's role must be in its list.

**Impersonation:** a `super_admin` or `school_admin` can assume another role. While impersonating, only `api_impersonate.php` and `impersonate.php` (the switch and restore endpoints) authorize by the original role. Every other page uses the assumed, restricted role (`access_control.php`, step 7).

**Fail-safe:** a page that is not listed in `ROLE_MAP` still requires login, but **any authenticated role** can reach it unless the file checks the role itself.

### 2.1 Page and endpoint role matrix (generated from `ROLE_MAP`, 101 entries)

| Allowed roles | Pages / endpoints (matched by file name for any script under `/admin/` or `/backend/`) |
|---|---|
| SA | `api_test_seed.php`, `backup.php`, `download_backup.php`, `api_app_release.php`, `api_failure_intelligence.php`, `api_identity.php`, `api_identity_migration.php`, `users.php`, `user-save.php`, `user-delete.php`, `user-toggle.php`, `super-admin.php` |
| SA AD | `api_branding.php`, `system_health.php`, `api_impersonate.php`, `impersonate.php`, `year_rollover.php`, `api_telemetry.php`, `preview.php`, `school_admin.php` |
| SA AD AT | `attendance_taker.php` |
| SA AD CE | `content_editor.php` |
| SA AD EDU | `edu_dept.php`, `education_department.php`, `api_assignments.php`, `assignments.php`, `edu_assignments.php`, `edu_teachers.php`, `export_executive_report_excel.php`, `export_executive_report_pdf.php` |
| SA AD FIN | `api_finance.php`, `finance.php`, `finance_department.php`, `finance_dept.php` |
| SA AD HR | `hr_register_member.php`, `api_check_duplicate.php`, `members_check.php`, `hr-dept.php`, `groups.php`, `groups_api.php`, `api_import_members.php`, `api_hr_attendance.php`, `api_hr_reports.php`, `view_id_card.php`, `generate_id_card.php` |
| SA AD INFO | `info-dept.php` |
| SA AD MAT | `api_material.php`, `material.php`, `material_department.php` |
| SA AD MEZ | `mezmur.php`, `api_mezmur.php`, `mezmur_dept.php`, `api_dept_takers.php` |
| SA AD TCH | `teacher.php` |
| SA AD EDU TCH | `api_subjects.php`, `subjects.php`, `api_teachers.php`, `teachers.php`, `api_timetable.php`, `timetable.php`, `api_communication.php`, `communication.php`, `export_class_report.php`, `export_filtered_students.php` |
| SA AD INFO HR | `info_manage_member.php`, `member_file.php`, `info_archive_member.php`, `info_restore_member.php`, `info_get_archived_members.php`, `api_reports.php`, `api_reports_v2.php`, `reports_v2.php`, `api_report_views.php`, `reports.php`, `export_pdf.php`, `api_export_members.php`, `api_info_analytics.php`, `api_info_reports.php` |
| SA AD EDU MEZ HR | `api_qr_roster.php` |
| SA AD EDU TCH AT | `education.php`, `api_attendance.php`, `attendance.php` |
| SA AD INFO HR CE | `api_cms.php` |
| SA AD INFO HR EDU | `print_member.php` |
| SA AD EDU HR TCH AT | `api_education.php` |
| SA AD EDU TCH HR INFO | `api_assessment_followups.php`, `assessment_followups.php`, `api_lesson_attendance.php`, `lesson_attendance.php` |
| SA AD HR INFO EDU MEZ | `api_department_status.php`, `api_attendance_policy.php`, `api_attendance_warnings.php`, `attendance_warnings.php`, `api_attendance_monthly.php`, `attendance_monthly.php` |
| SA AD INFO EDU TCH AT | `api_attendance_info.php`, `attendance_info.php` |
| SA AD INFO HR EDU FIN | `api_list_members.php`, `members.php` |
| SA AD INFO EDU FIN MAT MEZ HR TCH AT MEZ-T HR-T CE | `notifications.php`, `messages.php` |

Abbreviations are those in section 1. This matrix shows **page-level** gating only. Many endpoints apply further rules per action: department ownership, class assignment, view-only modes, typed confirmation for destructive actions, and so on. Those rules are **not** tabulated here.

### 2.2 `admin/api_*.php` files not listed in `ROLE_MAP`

The following files rely on their own in-file checks, or are intentionally open to every authenticated role:

| File | Observation (`46fbaa7`) |
|---|---|
| `api_mobile.php` | Token-authenticated mobile bridge; bypasses the session guard by design |
| `api_settings.php` | Comment in `access_control.php`: intentionally not role-restricted. Every role reads some settings; the file checks the role for writes |
| `api_notifications.php`, `api_ai.php`, `api_guardians.php`, `api_school_calendar.php` | Contain in-file role checks |
| `api_year_context.php` | Intentionally open to every logged-in user (file header). It changes only the user's own *view* of a past academic year, and writes are blocked while viewing a past year (`ay_require_writable()`) |

## 3. REST v1 authorization (`api/v1/core/acl.php`)

| Helper | Roles |
|---|---|
| `apiRolesPii()`: may see phones and addresses; may manage members | `info_dept`, `school_admin`, `super_admin` |
| `apiCanBrowseMembers()` | PII roles plus `edu_dept` |
| `apiRolesEducation()` | `edu_dept`, `school_admin`, `super_admin` |
| `apiRolesAttendance()` | `teacher`, `attendance_taker`, `edu_dept`, `school_admin`, `super_admin` |
| `apiRolesGrades()` | `teacher`, `edu_dept`, `school_admin`, `super_admin` |
| `apiIsClassRestricted()` | `teacher` and `attendance_taker`. They are limited to classes in active `teacher_assignments` for the academic year |

Route files call these helpers and add their own rules; for example, Mezmur and HR routes check department roles.

## 4. Business rules recorded by the owner (verify in code before relying on them)

These are standing owner decisions recorded in project notes. The table shows where they are visible in code.

| Rule | Evidence |
|---|---|
| Registration and promotion belong to HR; Information is a viewer/updater only | `hr_register_member.php`, `api_import_members.php`, `api_check_duplicate.php` are HR-only in `ROLE_MAP` |
| HR does not take attendance | [ADR-0009](adr/0009-attendance-ownership.md) (HR attendance retired). `api_hr_attendance.php` still exists and is HR-gated. **Unresolved:** current behaviour |
| Sensitive reporting datasets are restricted to Information | [ADR-0012](adr/0012-reporting-centre-and-data-boundary.md). Enforcement per dataset is in `api_reports_v2.php`; not tabulated |
| Only Education writes school holidays and days off | `api_school_calendar.php` in-file checks; not tabulated |

## 5. Unresolved

1. There is no complete per-action permission matrix for `admin/api_*.php` or `api/v1` routes. Producing one needs a file-by-file read; it is a candidate for the independent review stage.
2. Whether `finance_dept` and `material_dept` are in active use in production. Unverified; needs a read-only check of user accounts by the owner.
3. There are no automated tests that assert the full `ROLE_MAP`. Some security tests cover individual endpoints (`tests/security/`).
