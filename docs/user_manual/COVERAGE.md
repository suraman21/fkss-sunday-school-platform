# Manual coverage and verification queue

[Manual index](README.md)

**Overall status: INCOMPLETE — ADDITIONAL WORK REQUIRED.** Mobile account/update/recovery baseline `7209278`; new Finance/Materials core review baseline `13f00ac`, 2026-10-28. The exhaustive manual is active requested work, not deferred. This ledger records reviewed scope and outstanding work; it is not a statement that every listed file is a reachable screen or that every control works.

## Evidence boundaries

- Three original task/control chapters cover mobile Profile/device settings, updates and recovery. Six screen-directory source files have UI-level coverage; selected backing paths are traced. This is not full backend/domain-worker review.
- Two additional chapters cover core [Finance](FINANCE.md) and [Materials](MATERIALS.md) web workflows, handler actions, validation, persistence and recovery limitations. Their two mobile home notices were reviewed as website-only screens. Shared, dynamic, legacy and runtime acceptance work remains; no whole-department/every-control sign-off is claimed.
- A read-only extraction found 140 source files containing 2,985 text/control candidates before vendor/legacy/dynamic review. Those figures are **discovery counts**, not verified button counts, manual coverage percentages or executed tests. Text candidates include headings and exclude some dynamic controls. The raw research inventory stays outside maintained manuals.
- Every tracked Dart source in the screen directory is listed below, including helpers/style files. Every direct dashboard/frontend PHP source is queued separately; legacy routing/reachability must be checked before describing it as active.
- Runtime verification of these new chapters on a phone/browser/hosting is not performed. Source references establish only the behavior specifically reviewed.
- No complete all-button catalog or per-action permission matrix has been verified. Do not describe this edition as exhaustive.

## Department and workflow queue

| Area | Required remaining trace and manual work |
|---|---|
| Public website and registration | Public pages/gallery/forms, validation, duplicate policy, result/recovery and privacy; root handlers |
| Web account/shared navigation | Login/logout/account, year context, menus, notifications/messages, danger confirmations and accessibility |
| Education | Classes, subjects, teachers/assignments, enrollment, calendars/timetable, assessments, approval/reopen/return states, grades/report cards, every web/mobile control |
| Teacher / education attendance taker | Assignment/class/year scope, offline class-day gates, QR/marking, save/submit/Undo/review and lesson attendance |
| Attendance coordination | Monthly rollups, warnings/follow-ups, department status/policy/takers, permission differences and report actions |
| Human Resources | Temporary/permanent registration/promotion, import, duplicates, guardians/family links, groups, identity/ID cards and archive/report workflows; retired HR attendance must not be described as active |
| Information | Member editing/archives, reporting datasets/saved views/exports, analytics and PII boundaries |
| Mezmur / Mezmur attendance taker | Catalog/categories/zemarians, submissions, media/lyrics timing/playback/downloads, attendance/review/analytics and curator ownership |
| Finance | [Core web trace written](FINANCE.md): active shell/client/shim/API, fields, fee/ledger side effects, limits and known recovery/control discrepancies. Remaining: shared picker/pager/profile/communications/notifications/theme controls, legacy/direct-path reconciliation, failure/date/report acceptance and independent browser review. Mobile home is a reviewed website notice |
| Materials | [Core web trace written](MATERIALS.md): dashboard/actions, fields, stock/request distinction, deletion limits and export/filter behavior. Remaining: all shared/navigation controls, legacy/direct-path reconciliation and independent browser/failure/concurrency review. Mobile home is a reviewed website notice |
| Content Editor | CMS/public-content controls, publication effects, uploads, preview and permissions; mobile support must not be inferred from role existence |
| School Admin | Department overview, impersonation/restoration, configuration, branding, reporting, account management boundaries |
| Super Admin | Users/roles, backups/restore/health, identity, release manager, telemetry/failure intelligence and optional AI provider/chat controls; destructive/secret/privacy boundaries |
| All domains | Per-control conditions, exact labels, handler/API/service, persistence, confirmation, success/failure recovery; dynamic/shared controls and older installed clients |

## Mobile source queue

Statuses below apply only to the stated review depth. “Pending” means not yet approved as manual behavior, even if extraction has discovered labels.

| Screen-directory source | Review status | Chapter |
|---|---|---|
| [admin/admin_home.dart](../../Mobile/wbws_flutter_app/lib/screens/admin/admin_home.dart) | Pending source/action trace | Not yet written |
| [att_taker/att_taker_home.dart](../../Mobile/wbws_flutter_app/lib/screens/att_taker/att_taker_home.dart) | Pending source/action trace | Not yet written |
| [attendance/attendance_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/attendance/attendance_screen.dart) | Pending source/action trace | Not yet written |
| [attendance_warnings/attendance_warning_detail_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/attendance_warnings/attendance_warning_detail_screen.dart) | Pending source/action trace | Not yet written |
| [attendance_warnings/attendance_warning_widgets.dart](../../Mobile/wbws_flutter_app/lib/screens/attendance_warnings/attendance_warning_widgets.dart) | Pending source/action trace | Not yet written |
| [attendance_warnings/attendance_warnings_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/attendance_warnings/attendance_warnings_screen.dart) | Pending source/action trace | Not yet written |
| [auth/login_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/auth/login_screen.dart) | Pending source/action trace | Not yet written |
| [auth/session_recovery_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/auth/session_recovery_screen.dart) | UI/session paths reviewed; all domain workers not reviewed | [Guide](SYNC_AND_RECOVERY.md) |
| [edu_dept/edu_classes_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/edu_dept/edu_classes_screen.dart) | Pending source/action trace | Not yet written |
| [edu_dept/edu_enrollment_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/edu_dept/edu_enrollment_screen.dart) | Pending source/action trace | Not yet written |
| [edu_dept/edu_home.dart](../../Mobile/wbws_flutter_app/lib/screens/edu_dept/edu_home.dart) | Pending source/action trace | Not yet written |
| [edu_dept/edu_subjects_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/edu_dept/edu_subjects_screen.dart) | Pending source/action trace | Not yet written |
| [edu_dept/edu_teachers_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/edu_dept/edu_teachers_screen.dart) | Pending source/action trace | Not yet written |
| [finance/finance_home.dart](../../Mobile/wbws_flutter_app/lib/screens/finance/finance_home.dart) | Website-only notice source reviewed; shared notification control remains pending | [Finance](FINANCE.md) |
| [hr/hr_home.dart](../../Mobile/wbws_flutter_app/lib/screens/hr/hr_home.dart) | Pending source/action trace | Not yet written |
| [hr/hr_taker_home.dart](../../Mobile/wbws_flutter_app/lib/screens/hr/hr_taker_home.dart) | Pending source/action trace | Not yet written |
| [info_dept/info_home.dart](../../Mobile/wbws_flutter_app/lib/screens/info_dept/info_home.dart) | Pending source/action trace | Not yet written |
| [lock/lock_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/lock/lock_screen.dart) | Pending source/action trace | Not yet written |
| [material/material_home.dart](../../Mobile/wbws_flutter_app/lib/screens/material/material_home.dart) | Website-only notice source reviewed; shared notification control remains pending | [Materials](MATERIALS.md) |
| [members/member_detail_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/members/member_detail_screen.dart) | Pending source/action trace | Not yet written |
| [members/member_list_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/members/member_list_screen.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_analytics.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_analytics.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_attendance.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_attendance.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_categories.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_categories.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_category_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_category_screen.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_download_settings.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_download_settings.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_downloads.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_downloads.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_home.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_home.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_hymn_detail.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymn_detail.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_hymn_editor.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymn_editor.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_hymns.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymns.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_lyrics_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_lyrics_screen.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_lyrics_sync_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_lyrics_sync_screen.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_mini_player.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_mini_player.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_mini_player_host.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_mini_player_host.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_player_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_player_screen.dart) | Pending source/action trace | Not yet written |
| [mezmur/mezmur_zemarians.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_zemarians.dart) | Pending source/action trace | Not yet written |
| [mezmur/parchment_style.dart](../../Mobile/wbws_flutter_app/lib/screens/mezmur/parchment_style.dart) | Pending source/action trace | Not yet written |
| [notifications/messages_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/notifications/messages_screen.dart) | Pending source/action trace | Not yet written |
| [notifications/notification_center_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/notifications/notification_center_screen.dart) | Pending source/action trace | Not yet written |
| [profile/profile_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/profile/profile_screen.dart) | UI and selected service paths reviewed | [Guide](ACCOUNT_AND_DEVICE.md) |
| [profile/sync_center_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/profile/sync_center_screen.dart) | UI and selected recovery paths reviewed | [Guide](SYNC_AND_RECOVERY.md) |
| [reviews/review_inbox_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/reviews/review_inbox_screen.dart) | Pending source/action trace | Not yet written |
| [shell/app_shell.dart](../../Mobile/wbws_flutter_app/lib/screens/shell/app_shell.dart) | Pending source/action trace | Not yet written |
| [teacher/student_result_card.dart](../../Mobile/wbws_flutter_app/lib/screens/teacher/student_result_card.dart) | Pending source/action trace | Not yet written |
| [teacher/teacher_grades.dart](../../Mobile/wbws_flutter_app/lib/screens/teacher/teacher_grades.dart) | Pending source/action trace | Not yet written |
| [teacher/teacher_home.dart](../../Mobile/wbws_flutter_app/lib/screens/teacher/teacher_home.dart) | Pending source/action trace | Not yet written |
| [teacher/teacher_timetable_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/teacher/teacher_timetable_screen.dart) | Pending source/action trace | Not yet written |
| [update/app_updates_tile.dart](../../Mobile/wbws_flutter_app/lib/screens/update/app_updates_tile.dart) | UI/shared route reviewed | [Guide](APP_UPDATES.md) |
| [update/update_banner.dart](../../Mobile/wbws_flutter_app/lib/screens/update/update_banner.dart) | UI/shared route reviewed | [Guide](APP_UPDATES.md) |
| [update/update_screen.dart](../../Mobile/wbws_flutter_app/lib/screens/update/update_screen.dart) | UI/service/native install path reviewed | [Guide](APP_UPDATES.md) |

## Web dashboard/frontend source queue

These files include legacy implementations, shims and shared widgets. Each needs routing, page admission and action-level review. Standalone `admin/*.php`, shared components, generated JavaScript controls and public root pages are additional scope, not implied complete by this list.

| Source | Status |
|---|---|
| [admin/dashboards/ai_assistant.php](../../admin/dashboards/ai_assistant.php) | Pending route/control/handler trace |
| [admin/dashboards/ai_chatbot_widget.php](../../admin/dashboards/ai_chatbot_widget.php) | Pending route/control/handler trace |
| [admin/dashboards/attendance_taker.php](../../admin/dashboards/attendance_taker.php) | Pending route/control/handler trace |
| [admin/dashboards/content_editor.php](../../admin/dashboards/content_editor.php) | Pending route/control/handler trace |
| [admin/dashboards/dept_taker.php](../../admin/dashboards/dept_taker.php) | Pending route/control/handler trace |
| [admin/dashboards/edu_dept.php](../../admin/dashboards/edu_dept.php) | Pending route/control/handler trace |
| [admin/dashboards/education_department.php](../../admin/dashboards/education_department.php) | Pending route/control/handler trace |
| [admin/dashboards/finance_department.php](../../admin/dashboards/finance_department.php) | Pending route/control/handler trace |
| [admin/dashboards/groups.php](../../admin/dashboards/groups.php) | Pending route/control/handler trace |
| [admin/dashboards/hr-dept.php](../../admin/dashboards/hr-dept.php) | Pending route/control/handler trace |
| [admin/dashboards/info-dept.php](../../admin/dashboards/info-dept.php) | Pending route/control/handler trace |
| [admin/dashboards/material_department.php](../../admin/dashboards/material_department.php) | [Core source/handler trace](MATERIALS.md); shared controls and browser acceptance pending |
| [admin/dashboards/school_admin.php](../../admin/dashboards/school_admin.php) | Pending route/control/handler trace |
| [admin/dashboards/super-admin.php](../../admin/dashboards/super-admin.php) | Pending route/control/handler trace |
| [admin/dashboards/teacher.php](../../admin/dashboards/teacher.php) | Pending route/control/handler trace |
| [frontend/pages/dashboard.php](../../frontend/pages/dashboard.php) | Pending route/control/handler trace |
| [frontend/pages/edu_assignments.php](../../frontend/pages/edu_assignments.php) | Pending route/control/handler trace |
| [frontend/pages/edu_teachers.php](../../frontend/pages/edu_teachers.php) | Pending route/control/handler trace |
| [frontend/pages/finance_dept.php](../../frontend/pages/finance_dept.php) | [Core source/handler trace](FINANCE.md); shared controls and browser acceptance pending |
| [frontend/pages/login.php](../../frontend/pages/login.php) | Pending route/control/handler trace |
| [frontend/pages/mezmur_dept.php](../../frontend/pages/mezmur_dept.php) | Pending route/control/handler trace |

## Validation and release gates

1. Complete source/permission/control coverage by domain; distinguish discovery, source review and execution.
2. Review existing canonical/historical documents individually; retire only verified obsolete artifacts after reference/knowledge checks.
3. Review each visible wording change independently; preserve selectors, identifiers, permissions, contracts and logic.
4. Pass documentation links/anchors, include/boundary checks, focused regressions and applicable full suites. Record exact commands and limitations.
5. Validate the final user manual with representative synthetic-data journeys and accessibility/responsive states; export only reviewed content.
6. Commit/push, pass fresh required checks and merge development main within authorization. Owner acceptance and production promotion remain separate.

The publication of five chapters does not complete the full documentation-renewal request. Incomplete coverage is an acceptance gap, not a mere optional improvement. Apply the [control-level acceptance record](../QUALITY_GATES.md#3-manual-control-acceptance-record) before promoting any remaining source or department to reviewed/complete.
