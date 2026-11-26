# Handover for the developer finishing the project

The student FRONTEND is complete on the original page structure. The BACKEND is basic on purpose: the endpoints below work against the
schema, but need your review, hardening and connection to the admin/teacher dashboards. NOTHING here has been run against a real PHP/MySQL
server by the author (none was available): treat first run as testing.

## Request flow
Browser → `backend/router.php` → `backend/api.php` → `lib/security.php::fk_gate()` (session + role + "student may only use their own ID") → handlers → PDO prepared statements → JSON.
Handlers: `api/auth_staff.php` (login, staff, unlocks, admin), `api/learning.php` (path, lessons, progress, verse, assistant), `api/extras.php` (notifications, attendance, permissions, past papers), `api/quiz.php` / `question*.php` (original quiz + question bank).

## Student endpoints (all require a student session)
| Endpoint | Purpose |
|---|---|
| POST /api/auth/login, /logout, /change-password; GET /api/auth/me | session auth |
| GET /api/learning/path | subjects → units → lessons with states + unit quiz node |
| GET /api/learning/lessons/{id}; POST …/complete | lesson text (only if open); explicit completion |
| GET /api/learning/materials/{id}/download | lesson files (only if lesson open) |
| GET /api/progress, /progress/weak-topics, /progress/attempts/{id} | real stats, week dots, score history, review of own finished attempts |
| GET /api/verse/today | verse by Addis Ababa date from `data/verses.json` |
| POST /api/assistant/ask | keyword search over the student's OPEN lessons (no AI model) |
| GET /api/notifications; POST …/{id}/read, …/read-all | announcements for the student |
| GET /api/attendance | records + counts + percent |
| GET/POST /api/permissions | student's own requests |
| GET /api/past-papers; GET …/{id}/file | papers for the student's grade |
| GET /api/tasks/{studentId}; /api/quiz/* | original endpoints (now behind the session gate) |

## What YOUR dashboards must do (tables are created by migrations 001–003)
- **Unlock lessons / units:** `POST /api/staff/lessons/{id}/unlock|lock` and `POST /api/staff/units/{id}/unlock|lock` with `{grade, class?}` (teacher scope in `staff_scopes`) — or write `lesson_unlocks` / `unit_unlocks` directly. Unique per (lesson/unit, grade, class); class NULL = whole grade.
- **Announcements:** INSERT into `notifications` (audience_grade/audience_class/student_id all NULL = everyone).
- **Attendance:** INSERT into `attendance` (one row per student per date; status present|absent|late|permission). The percent rule is in `extras.php` ("present+late / all days"): change it if HR calculates differently.
- **Permission requests:** UPDATE `permission_requests` (status, reviewed_by, review_note, reviewed_at).
- **Past papers:** INSERT into `past_papers`, files under `uploads/`.
- **Tasks:** `tasks` + `task_attachments` as before (the student page reads them by grade). Confirm the file_path format your dashboard saves.
- **Accounts:** the author's `staff` / `staff_scopes` tables and `staff.html` are a TEMPORARY stand-in. Replace with your own login; keep the session shape in `lib/security.php::fk_login_session`.
- Admin endpoints for creating students / temporary password reset exist (`/api/admin/students`, `…/reset-password`); students must change a temporary password at first login.

## Known gaps / to finish
- Exam Prep: study-plan scheduler, National Exam Roadmap (needs the official topic list) and timed mock exams are NOT built (honest "not available yet" cards).
- Past paper interactive exam needs questions + answer key entered (only the files are shown).
- Assistant is keyword search; a real AI provider needs a server-side key (never in frontend).
- No CSRF token (session cookie is SameSite=Lax and the API is JSON-only); add one before production.
- Uploads folder is served to any signed-in user via the router (`/uploads/...`); restrict by grade/lesson.
- Progress day-buckets assume DB timestamps are UTC.
- Old unused files from the first version remain untouched: `api/auth/register.php`, `api/lessons/*.php`, `api-backup-before-quiz.php`, `server.js`, `test_db*.php`, `data/students.json`(not in this ZIP). Remove after checking nothing references them.
- Amharic UI wording was written by an AI: have a native speaker review `FK.addStrings` blocks.
- Assumptions to verify: unit quiz attempts store `chapter_id` with scope 'unit'; `quiz_attempts.score` is 0–100.
