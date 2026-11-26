# ፈለገ ቅዱሳን — student app (test copy)

Work only in this folder against the TEST database `felege_kidusan_test`. Never point it at `felege_kidusan`.

## What is in here
- `frontend/` — all student pages (Home, Lessons, Lesson reader, Tasks, Quiz Me, Exam Prep, Progress, Login, Change password).
  Shared look: `css/fk-theme.css`. Shared header, popups, assistant window: `js/fk-ui.js`.
- `backend/` — PHP API (router, `api.php`, `api/*.php`, `lib/security.php`, `config/database.php`).
- `database/migrations/` — 001 (login/roles/unlocks), 002 (lesson progress), 003 (units, notifications, attendance, permissions, past papers), each with a rollback.
- `backend/tools/` — `check_setup.php` (read-only check), `create_staff.php`, `import_lesson_text.php`.
- `backend/data/` — `verses.json`, `lesson_text_grade10.json`.
- `docs/` — this guide, `HANDOVER_FOR_DEVELOPER.md`, `TEST_CHECKLIST.md`, `STAGE1_SETUP.md`, `lesson_text_for_proofreading.md`.

## First-time setup on Windows
1. Put your own MySQL user/password in `backend\config\database.php` (the ZIP has placeholders). Database name stays `felege_kidusan_test`.
2. Back up the test database:  `mysqldump -u YOUR_USER -p felege_kidusan_test > backup.sql`
3. Apply, in order (each only ADDS tables/columns):
   `mysql -u YOUR_USER -p felege_kidusan_test < database\migrations\001_stage1_auth_and_unlocks.sql`
   `mysql -u YOUR_USER -p felege_kidusan_test < database\migrations\002_lesson_progress.sql`
   `mysql -u YOUR_USER -p felege_kidusan_test < database\migrations\003_unit_unlocks_and_student_extras.sql`
4. Check: `php backend\tools\check_setup.php`  (read-only; fix any FAIL line).
5. Copy your existing `uploads` files into `uploads\` (this ZIP only has the demo past paper).
6. Start: `php -S localhost:8001 backend/router.php` then open http://localhost:8001
7. Optional: demo past paper row: `mysql ... felege_kidusan_test < database\demo\demo_past_paper.sql`

## Lesson text (needs a teacher's proofreading first)
`docs\lesson_text_for_proofreading.md` holds the text extracted from the Grade 10 textbook PDF. A repeating PDF font problem (ዘ ዙ ዚ ዛ ዜ ዝ ዞ shifted by one letter) was repaired by rule, but a teacher must read it against the book.
Then: `php backend\tools\import_lesson_text.php` (dry run, shows the pairing) and, if right, `... --apply` (test DB only).
Tables in the book were replaced by a note "see the book"; the raw table text is in the JSON.

## Unlock rules (enforced on the server, see backend/api/learning.php)
- Unit 1 of a subject is open. The first 2 lessons of every open unit are open by default; later lessons need a teacher/admin unlock (`lesson_unlocks`).
- A lesson is completed only when the student presses "I finished this lesson". Finishing never unlocks anything.
- Unit N+1 opens only when (a) every lesson of unit N is completed AND the unit-N quiz was passed (units without quiz questions count as done) AND (b) a teacher/admin unlocked unit N+1 (`unit_unlocks`).
- The unit quiz uses the existing Quiz Me engine (scope "unit"); "passed" is the engine's own pass mark (60%).
- Quiz questions from lessons that are not open are not served.
