# FKSS — Product Requirements Document (reconstructed baseline)

| Field | Value |
|---|---|
| Document | Product Requirements Document (PRD) |
| Status | **Reconstructed baseline. Draft for review by the engineering team** |
| Version | 1.0 (2026-10-22) |
| Describes | The system as implemented on `main` at `46fbaa7` (deployed to production on 2026-10-22) |
| Method | Reconstructed after the fact from the source code, migration files, commit history and the decision records ([ADRs](../adr/README.md)). No PRD existed before development began; the system was built incrementally from 2026-08-15 |
| Owner | FKSS project owner |
| Companion documents | [Technical design](../architecture/TECHNICAL_DESIGN.md) · [Decision records](../adr/README.md) · [Glossary](GLOSSARY.md) · [Roles and permissions](../ROLES_AND_PERMISSIONS.md) · [Engineering handover](../ENGINEERING_HANDOVER.md) |

**How to read this document**
- Requirement IDs (`FR-MEM-01`, `NFR-SEC-02`, …) are stable references for future tickets and tests.
- **Status:** **Implemented** = present on `main` at `46fbaa7`. **Partial** = implemented with a documented gap. **Planned** = decided or designed but not built. **On hold** = deliberately paused by the owner.
- **Source** points to the code or decision record that defines the behaviour. Where the code and a planning document disagree, the code is what runs; the difference is noted.
- Production configuration (feature flags, which migrations are applied) is **not verified** here. See [ENGINEERING_HANDOVER.md §5](../ENGINEERING_HANDOVER.md#5-unknowns-that-need-read-only-verification).

---

## 1. Background and purpose

The Felege Kidusan Sunday School (FKSS) runs education, attendance, membership and ministry activities for children, teenagers and youth. Before this system, these were managed on spreadsheets and paper.

FKSS is a web and Android system that gives each department its own workspace on one shared member register. It works on low-end phones and unreliable mobile networks.

## 2. Goals and non-goals

### 2.1 Goals
| ID | Goal |
|---|---|
| G-1 | One authoritative member register, with registration owned by a single department (HR) |
| G-2 | Reliable attendance and grade capture by teachers, including offline capture on Android phones |
| G-3 | Department-scoped workspaces with least-privilege access to personal data |
| G-4 | Report cards, monthly attendance and management reports without manual spreadsheet work |
| G-5 | Early warning when members stop attending |
| G-6 | Run within the limits of low-cost shared hosting, operated by a very small team |
| G-7 | Ethiopian calendar and Amharic content throughout |

### 2.2 Non-goals (current scope)
- Member self-registration or member logins. Members do not have accounts. A Members app and a Parent app are planned or on hold (section 7).
- Online payments, e-mail or SMS delivery.
- Multi-tenant hosting for other schools. Branding is configurable, but the system serves one school per deployment.
- Play Store distribution. The Android app is distributed directly (see [ADR-0005](../adr/0005-direct-apk-distribution.md)).

## 3. Users and stakeholders

The organisation is modelled as departments. Each staff account has exactly one role. The full page and endpoint matrix is in [ROLES_AND_PERMISSIONS.md](../ROLES_AND_PERMISSIONS.md).

| Role (code) | User | Primary responsibilities in the system | Web | Android app |
|---|---|---|---|---|
| Super Admin (`super_admin`) | System administrator | Staff accounts, branding, identity codes, backups, app releases, failure intelligence | Yes | Yes |
| School Admin (`school_admin`) | School leadership | Academic years, attendance policy, school-wide oversight; can open any department view (impersonation) | Yes | Yes |
| HR Department (`hr_dept`) | Membership office | Member registration, temporary → permanent promotion, imports, ID cards, Family Link, groups | Yes | Yes |
| Information Department (`info_dept`) | Records and statistics office | View and correct member records, archive and restore, reports and analytics. Does **not** register or promote members | Yes | Yes |
| Education Department (`edu_dept`) | Academic office | Classes, subjects, teachers, assessments, grade review, timetable, school calendar, report cards | Yes | Yes |
| Mezmur Department (`mezmur_dept`) | Hymn ministry | Hymn library and media, Mezmur attendance and takers | Yes | Yes |
| Finance Department (`finance_dept`) | Treasury | Transactions, categories, member fees, reports | Yes | Yes |
| Material Department (`material_dept`) | Store keeping | Items, categories, requests, transactions | Yes | Yes |
| Teacher (`teacher`) | Class teacher | Attendance and marks for **assigned** classes only; timetable | Yes | Yes |
| Attendance Taker (`attendance_taker`) | Volunteer | Class attendance only | Yes | Yes |
| Mezmur / HR Attendance Taker | Department volunteers | Department-owned taker accounts | Yes | Yes |
| Content Editor (`content_editor`) | Website editor | Public website content (CMS) | Yes | No |

Other stakeholders: the parents and guardians of students (data subjects; no system access today), and the public (public website, gallery and online registration form).

## 4. Scope overview

```
                     ┌────────────── Members register (HR owns writes) ───────────────┐
 Public website ───► │ registration · tiers · codes · Family Link · imports · ID cards │
 (CMS, form)         └─────┬──────────────┬──────────────┬──────────────┬────────────┘
                           ▼              ▼              ▼              ▼
                      Education      Attendance       Mezmur      Finance / Material
                 (classes, marks,  (class-day rules, (hymns, media, (ledgers, items,
                  report cards,     warnings,         attendance)    requests)
                  timetable)        monthly reports)
                           └──────────────┴──────┬───────┴──────────────┘
                                                 ▼
                       Information: reports, analytics, Reporting Centre
                       Cross-cutting: notifications and messaging, academic years,
                       accounts and profiles, app release, telemetry, backups
```

## 5. Functional requirements

### 5.1 Members and registration (HR)
| ID | Requirement and business rules | Status | Source |
|---|---|---|---|
| FR-MEM-01 | Only HR registers members. School Admin has a reduced "quick add" form (name, father's name and gender required). Members are **never** registered from the Android app | Implemented | `admin/hr_register_member.php`; `MemberRegistrationPolicy.php`; [ADR-0017](../adr/0017-no-member-registration-on-mobile.md) |
| FR-MEM-02 | Registration types: `waiting`, `transfer`, `direct`. Member types: `regular`, `special_regular`, `honorary`. Status: `active`, `warning`, `inactive`. Gender: `male`, `female` | Implemented | `MemberRegistrationPolicy.php` |
| FR-MEM-03 | Two membership tiers: **temporary** and **permanent**. A temporary member becomes eligible for promotion **6 months** after `registered_at` | Implemented | `hr-dept.php`; `MemberDirectoryService.php`; `ReportRegistry.php` |
| FR-MEM-04 | HR is notified once per member when they become eligible for promotion (idempotent, using `permanent_eligibility_notified_at`) | **Partial.** The notifier is a CLI script that expects a daily cron job, and whether production has one is unknown. Eligible members are always listed on the HR dashboard | `admin/backend/notify_permanent_eligibility.php`; `sql/068_member_permanent_eligibility_notified.sql` |
| FR-MEM-05 | Promotion updates the existing member record and keeps the original code, `registered_at` and history. The form is prefilled from the current record | Implemented | `hr_register_member.php` (`upgrade_member_id`); commit `207e610` |
| FR-MEM-06 | Duplicate detection before registration: a **strong** match needs the name plus a second signal (complete Ethiopian birth date or phone suffix). Weaker matches are shown as advisory | Implemented | `api_check_duplicate.php`; `MemberDuplicateService.php` |
| FR-MEM-07 | Section = age group, from one definition: A **ህጻናት** (7–13), B **ማዕከላዊያን** (14–17), C **ወጣቶች** (18+). Members under 7 are placed in A | Implemented | `MemberCategory.php`; [ADR-0007](../adr/0007-section-equals-age-group.md) |
| FR-MEM-08 | Identity codes `{PREFIX}-{5-digit random tail}`: student prefixes A/B/C; staff prefixes from department and position. Codes are unique; tails are random | Implemented | `MemberCodeFormat.php`; `IdentityCodeService.php`; `sql/017_identity_codes.sql`, `018`, `020` |
| FR-MEM-09 | Excel import v2: all-or-nothing, Ethiopian-calendar birth dates, age-derived sections, strict class matching | Implemented | `admin/api_import_members.php`; commit `b26467f` |
| FR-MEM-10 | Information Department edits member details, archives and restores members. It does not register or promote | Implemented | `info_manage_member.php`, `info_archive_member.php`, `info_restore_member.php` |
| FR-MEM-11 | Member documents and photos are stored privately and served only after a role check | Implemented | `MemberFileService.php`; `admin/member_file.php` |
| FR-MEM-12 | **Family Link:** guardian records linked to one or more children, with typo-tolerant matching and a review queue for uncertain matches | Implemented | `GuardianMatchService.php`; `api_guardians.php`; `sql/069_guardian_linkage.sql`; [ADR-0016](../adr/0016-family-link-guardian-model.md) |
| FR-MEM-13 | Printable member ID cards with QR code; an ID card designer in Super Admin branding | Implemented | `admin/id_cards/`; `generate_id_card.php` |
| FR-MEM-14 | Public online registration form; submissions are stored for staff review (honeypot anti-spam) | Implemented | `register_submit.php` → `cms_registration_submissions` |

### 5.2 Education
| ID | Requirement and business rules | Status | Source |
|---|---|---|---|
| FR-EDU-01 | Education manages classes (with age group), subjects (Amharic names supported), teacher accounts and teacher ↔ subject ↔ class assignments | Implemented | `api_education.php`, `api_subjects.php`, `api_teachers.php`, `api_assignments.php` |
| FR-EDU-02 | Subjects are `SEMESTER_ONLY` or `FULL_YEAR`. A full-year subject's annual score is the plain average of the two semester totals. A semester-only subject's final score is that semester's score | Implemented | `SubjectDurationPolicy.php`; [ADR-0010](../adr/0010-semester-based-grading.md) |
| FR-EDU-03 | Assessments are created by Education (batch across classes and subjects). Assessment types are configurable. Each semester has a **100% weight budget** | Implemented | `AssessmentTypeService.php`; release 1.6.6 (`02b6c6d`) |
| FR-EDU-04 | A mark above an assessment's maximum is refused with HTTP 422 and nothing is saved. A blank mark is shown as **"Did not take"** and is **excluded** from the total | Implemented | commits `524ab7f`, `d854591`; [ADR-0020](../adr/0020-score-limit-and-did-not-take.md) |
| FR-EDU-05 | Mark lists: draft → submit → Education review (approve or reject). Submitted lists are read-only. One packet per slot; review transitions cannot be replayed | Implemented | `ReviewTransitionPolicy.php`; `SubmissionService.php`; `sql/053_grade_submission_slot_uniqueness.sql` |
| FR-EDU-06 | Semesters can be closed and reopened for corrections. Teachers can view closed semesters. Grade entry outside the current semester is visible and overridable, never silent | Implemented | `sql/067_semester_reopen_flag.sql`; releases 1.6.4 and 1.6.5 |
| FR-EDU-07 | Report cards: totals, averages, rank and attendance, from one service used by Education and the teacher portal. Printed as an A5 booklet | Implemented | `ReportCardService.php`; release 1.6.10 |
| FR-EDU-08 | Academic tracking by student, teacher, subject and class; education analytics | Implemented | `AcademicTrackingService.php`; `EducationAnalyticsService.php`; `docs/ACADEMIC_TRACKING_PHASE*.md` |
| FR-EDU-09 | **Timetable builder:** per-class working days, per-class bell schedule and a weekly grid. Periods of one class never overlap; a teacher cannot be in two overlapping periods; makeup classes must be today or later | Implemented | `TimetableService.php`; `sql/077_timetable_builder.sql` |
| FR-EDU-10 | Teachers see their timetable on the web and on Android (Daily, Weekly, Monthly; Daily is the default) | Implemented | commits `7162d42`, `b64df5e` |
| FR-EDU-11 | **School calendar:** planned holidays and "Day Off" days, written by Education only | Implemented | `SchoolCalendarService.php`; `sql/078_school_calendar.sql` |
| FR-EDU-12 | Assessment follow-ups and make-up attempts when a member is reactivated | Implemented | `sql/075_assessment_followups.sql`; commit `e4ac5db` |

### 5.3 Attendance
| ID | Requirement and business rules | Status | Source |
|---|---|---|---|
| FR-ATT-01 | Attendance is owned by **Education** (class attendance) and **Mezmur** (department attendance). HR does not take attendance | Implemented | [ADR-0009](../adr/0009-attendance-ownership.md); commits `eec830c`, `d098afc` |
| FR-ATT-02 | Attendance is recorded per day, by class. Teachers record only their assigned classes; attendance takers record class attendance | Implemented | `AttendanceRecordService.php`; `api_attendance.php`; `api/v1/routes/attendance.php` |
| FR-ATT-03 | **Class-day rule:** attendance only on days the class meets according to the timetable (or a makeup day); no future dates; at most **45 days** back. Classes without configured working days are blocked. "Day Off" days cancel attendance | Implemented (active once `sql/078_school_calendar.sql` is applied) | `SchoolCalendarService.php`; [ADR-0011](../adr/0011-class-day-only-attendance.md) |
| FR-ATT-04 | Sheets default to absent; drafts merge partial sheets; a submitted sheet can be reopened or rejected with a reason, which triggers recalculation | Implemented | commits `e2184d1`, `9ba54ee`, `c7b6284` |
| FR-ATT-05 | QR-code scanning to mark attendance on Android | Implemented | `api_qr_roster.php`; commit `8d106ce` |
| FR-ATT-06 | **Lesson-level attendance** for Education: a teacher records a lesson sheet (class, subject, date, lesson number). Precedence: present › late › excused › absent | Implemented (web) | `LessonAttendanceService.php`; `sql/076_attendance_sessions.sql` |
| FR-ATT-07 | **Attendance warnings:** per-department thresholds (defaults: 5 / 8 / 10 consecutive absences for standard / prolonged / critical; low-rate warning below 75% with at least 3 sessions; auto-deactivate **off**). Warnings escalate in place and notify the responsible staff | Implemented | `AttendancePolicyService.php`; `AttendanceWarningService.php`; [ADR-0019](../adr/0019-attendance-alert-thresholds.md) |
| FR-ATT-08 | Department membership status (active / inactive), changed by people, not automatically (unless a School Admin enables auto-deactivate) | Implemented | `sql/071_department_status_and_policy.sql`; `api_department_status.php` |
| FR-ATT-09 | Monthly attendance report in Ethiopian-calendar months, with Excel export | Implemented | `AttendanceMonthRollupService.php`; `sql/074_attendance_month_rollup.sql` |

### 5.4 Mezmur (hymn ministry)
| ID | Requirement | Status | Source |
|---|---|---|---|
| FR-MEZ-01 | Hymn library: two-level categories, singers, lyrics with sections, unique titles, typo-tolerant Amharic search (server word index plus on-device search) | Implemented | `Mezmur*Service.php`; `sql/021_mezmur_department.sql`–`039` |
| FR-MEZ-02 | Audio and cover art in object storage, uploaded through presigned URLs; synced (karaoke) lyrics; offline downloads on Android | Implemented | `MezmurMediaService.php`; [ADR-0015](../adr/0015-mezmur-media-object-storage.md) |
| FR-MEZ-03 | Date-based Mezmur attendance by section, with department-owned takers and the same submit/review workflow as Education | Implemented | `MezmurAttendanceService.php`; `api_dept_takers.php` |

### 5.5 Finance, Material and Groups
| ID | Requirement | Status | Source |
|---|---|---|---|
| FR-FIN-01 | Finance: transactions, categories, member fees and reports, with an audit trail of ledger changes | Implemented | `admin/api_finance.php`; commit `201557f` |
| FR-MAT-01 | Material: categories, items, requests and transactions | Implemented | `admin/api_material.php` |
| FR-GRP-01 | Groups with leaders and members | Implemented | `admin/backend/groups_api.php` |

### 5.6 Information, reporting and analytics
| ID | Requirement and business rules | Status | Source |
|---|---|---|---|
| FR-REP-01 | Reporting Centre: governed member dataset with per-field view and export permissions, saved views (private, department, shared), audited CSV and Excel export | Implemented | `admin/backend/reporting/ReportRegistry.php`; `api_reports_v2.php`; `sql/070_report_definitions.sql` |
| FR-REP-02 | **Boundary:** future sensitive or cross-department datasets (finance, material, detailed education, telemetry) go to the Information Department only | Implemented as a rule for additions; department datasets (V2-4) are **On hold** | [ADR-0012](../adr/0012-reporting-centre-and-data-boundary.md) |
| FR-REP-03 | Attendance analytics hub for Information (Education and Mezmur sources), PDF report templates, member exports | Implemented | `api_info_analytics.php`; `admin/backend/pdf/` |
| FR-REP-04 | Past-year view: any user can view a closed academic year read-only; writes are blocked while viewing the past | Implemented | `admin/api_year_context.php` |

### 5.7 Communication
| ID | Requirement | Status | Source |
|---|---|---|---|
| FR-COM-01 | Notification bell, announcements and targeted notifications by role | Implemented | `NotificationCenterService.php`; `api_notifications.php` |
| FR-COM-02 | Staff messaging: threads, contact picker, read receipts, edit and delete; offline send queue on Android with exactly-once delivery | Implemented | `api_communication.php`; `api/v1/routes/notifications.php`; `sql/042_notification_center.sql`–`046` |

### 5.8 Administration and platform
| ID | Requirement and business rules | Status | Source |
|---|---|---|---|
| FR-ADM-01 | Staff accounts are created by Super Admin; department takers by their department. Accounts can be disabled. Role, status and authorization version are re-checked against the database on every web and API request, so changes take effect immediately | Implemented | `user-save.php`; `api_dept_takers.php`; `AdminSessionGuard.php`; `sql/048_user_authorization_scope.sql` |
| FR-ADM-02 | Academic year lifecycle (upcoming → active → closed) and a year rollover (carry, promote, graduate) that requires typing `ROLLOVER` | Implemented | `sql/004_year_lifecycle.sql`; `admin/tools/year_rollover.php` |
| FR-ADM-03 | School Admin and Super Admin can open a department view (impersonation). Every other page then uses the assumed role | Implemented | `access_control.php`; `api_impersonate.php` |
| FR-ADM-04 | Destructive actions use a tiered confirmation: a three-step dialog with a typed phrase for high-risk actions, checked again on the server | Implemented | `admin/backend/danger_confirm.php`; [ADR-0013](../adr/0013-tiered-danger-confirmation.md) |
| FR-ADM-05 | Branding (name, logos, colours, ID card design) and per-module feature flags | Implemented | `school_config.php`; `FeatureGate.php`; `api_branding.php` |
| FR-ADM-06 | Users manage their own profile and avatar | Implemented | `ProfileService.php`; commits `5f2e6ae`–`6337013` |
| FR-ADM-07 | Encrypted database backups, created and downloaded by Super Admin | Implemented; restore never tested | `BackupService.php`; [OPERATIONS.md](../OPERATIONS.md) |
| FR-ADM-08 | Android app release manager (APK upload, version, minimum build, forced update), first-party app telemetry, failure intelligence (crash signatures, issues, alerts) | Implemented | `AppReleaseManager.php`; `AppTelemetryService.php`; `FailureIssueService.php` |
| FR-ADM-09 | Optional AI assistant with administrator-configured providers | Implemented (feature-flagged; production state unknown) | `admin/api_ai.php`; `admin/backend/ai_providers.php` |
| FR-WEB-01 | Public website content managed through a CMS: gallery, programmes, schedule, teachers, social links | Implemented | `admin/api_cms.php`; `index.php`, `public_gallery.php` |

## 6. Android app requirements
| ID | Requirement | Status | Source |
|---|---|---|---|
| FR-MOB-01 | Role-based home screens for 12 staff roles (not Content Editor) | Implemented | `Mobile/wbws_flutter_app/lib/screens/`; `lib/utils/config.dart` |
| FR-MOB-02 | Offline-first: screens render from the on-device database first; attendance, marks, Mezmur and messages are queued in outboxes and sent when online, with idempotency keys so retries never duplicate | Implemented | `local_db.dart`; `api/v1/core/middleware.php`; [ADR-0003](../adr/0003-offline-first-mobile.md) |
| FR-MOB-03 | Offline work survives logout, re-login and authorization changes, and stays bound to its owner | Implemented | commits `3ed96b7`, `2b00f78` |
| FR-MOB-04 | App lock (PIN or biometric); secure token storage | Implemented | `local_auth`; `flutter_secure_storage` |
| FR-MOB-05 | In-app update from the school's server (optional or forced by minimum build) | Implemented | `api/v1/routes/app.php`; [ADR-0005](../adr/0005-direct-apk-distribution.md) |
| FR-MOB-06 | Background sync on Android | Implemented | `AndroidBackgroundSyncScheduler`; `docs/MOBILE_OFFLINE_SYNC_S3_A8_*` |

## 7. Planned and paused work (not implemented)
| Item | Status | Record |
|---|---|---|
| Parent app | Feature list **locked**; not started | — |
| Members app | **On hold** | — |
| Account Command Center (parent/member accounts and devices) | In planning; **no code until the implementation plan is approved** | — |
| Reporting Centre V2-4 (department datasets) | **On hold** pending user feedback | [ADR-0012](../adr/0012-reporting-centre-and-data-boundary.md) |
| Lesson-level attendance on mobile | Not in scope of Phase 6 | [ADR-0018](../adr/0018-lesson-level-attendance.md) |

## 8. Non-functional requirements
| ID | Requirement | Status / evidence |
|---|---|---|
| NFR-PLT-01 | Runs on shared hosting: PHP 8.2, MariaDB, LiteSpeed; no shell, Composer, cron, Python, Redis or C extensions for the application | Constraint ([ADR-0001](../adr/0001-php-monolith-on-shared-hosting.md)) |
| NFR-PLT-02 | Database changes are additive, idempotent SQL that the owner applies by hand | Constraint ([ADR-0004](../adr/0004-manual-idempotent-sql-migrations.md)) |
| NFR-PRF-01 | Designed for a school of 5,000+ students: paginated lists (capped page sizes), indexed directory and roster queries, monthly roll-ups | Design target (`sql/003_production_hardening.sql` header; `sql/014_member_directory_scaling.sql`, `028`, `052`). **No load test on record** |
| NFR-PRF-02 | Usable on 2G/3G: cache-first screens and conditional GETs (ETag/304) for messaging | Implemented (commits `4b438ae`, `40acaa7`) |
| NFR-SEC-01 | Role-based access on every page and endpoint; CSRF on web writes; rate-limited login (20 per 5 minutes per IP, 5 per 5 minutes per account); 30-minute idle web session | Implemented ([SECURITY.md](../SECURITY.md)) |
| NFR-SEC-02 | Mobile tokens: 15-minute access token, 90-day rotating refresh token with replay detection; account changes revoke access | Implemented ([API.md](../API.md)) |
| NFR-SEC-03 | Personal data (phones, addresses) visible only to roles that need it | Implemented in the API (`apiRolesPii()`) and in the reporting field permissions |
| NFR-SEC-04 | No independent security certification exists; see the risk register | Gap ([ENGINEERING_HANDOVER.md §6](../ENGINEERING_HANDOVER.md#6-risk-register-public-summary)) |
| NFR-LOC-01 | Every user-facing date uses the **Ethiopian calendar**; content and names in Amharic (Ethiopic script) | Implemented ([ADR-0008](../adr/0008-ethiopian-calendar-only.md)) |
| NFR-ACC-01 | Responsive web for phones and tablets; pinch zoom allowed; reduced-motion respected in key screens | Implemented (commits `8a27fb7`, `2a5a169`). No formal accessibility audit |
| NFR-REL-01 | Encrypted backups; documented restore path | **Partial:** backups exist; restore never tested; no off-site copy |
| NFR-OBS-01 | Error and uptime monitoring with optional Telegram alerts; mobile telemetry and crash signatures | Implemented in code; production configuration unknown |
| NFR-AVL-01 | Availability target / SLA | **Not defined** |

## 9. Open questions for the engineering team
1. Confirm the production schema and applied migrations (handover U-1, U-2) before any database work.
2. Decide the canonical API layer per domain ([ARCHITECTURE.md §3](../ARCHITECTURE.md#3-the-three-api-layers-unfinished-consolidation)).
3. Define availability, backup-restore (RPO/RTO) and support targets (NFR-AVL-01, NFR-REL-01).
4. Decide whether FR-MEM-04 needs a cron job, or should be triggered on dashboard load.
5. Set an approval process for this PRD. Future changes should update the requirement IDs here, and new decisions should get a record in [docs/adr/](../adr/README.md).
