# Security foundation (migration 001) - details
(See README_START_HERE.md for the full setup order. This file keeps the migration 001 notes and login troubleshooting.)

**Status: written but NOT yet run.** The PHP in this stage could not be executed where it was
written (no PHP or MySQL available), so you are the first to run it. Follow the steps in order and
report any error message you see.

## What Stage 1 changes
- Real server sessions: the server now remembers who is signed in. Browser-supplied student IDs are no longer trusted.
- Roles: student, teacher, admin. Teacher/admin endpoints (question bank, Excel import, creating tasks, uploading task files) now refuse students.
- Teacher-controlled lesson unlocking, saved in MySQL, limited to the grades/classes each teacher is assigned. Completing a lesson never unlocks another.
- `/api/lessons/{id}` now returns published lessons only, with an `unlocked` flag; description is hidden while locked.
- First-login password change, admin password reset, login throttling (5 failures / 15 min), `/uploads/` needs sign-in.
- PHP errors are no longer shown to the browser; database errors return a generic message.

## Step 0 - keep your original safe
1. Do NOT copy this over `C:\Users\Wintana\Documents\SundaySchoolApp`. Use the test folder `SundaySchoolApp-Claude-Test`.
2. Copy your own existing `uploads` files into this folder's `uploads` (the ZIP has none).

## Step 1 - database settings
Open `backend\config\database.php`. The database name is already `felege_kidusan_test`.
Replace `CHANGE_ME_USER` and `CHANGE_ME_PASSWORD` with a MySQL login that can only use the test database.
(Recommended: create a limited user in MySQL Workbench with privileges on `felege_kidusan_test` only, instead of `root`.)

## Step 2 - back up the TEST database, then apply the migration
Open Command Prompt in the project folder:

    mysqldump -u YOUR_USER -p felege_kidusan_test > backup_before_stage1.sql

(saves a copy of the test database; it changes nothing)

    mysql -u YOUR_USER -p felege_kidusan_test < database\migrations\001_stage1_auth_and_unlocks.sql

(adds the new columns/tables to the TEST database only; it deletes no data).
Check it worked in MySQL: `SHOW TABLES;` should now include `staff`, `staff_scopes`, `login_attempts`.
To undo: `database\migrations\001_stage1_rollback.sql` (read the warning at its top).

Existing students keep their passwords and are NOT forced to change them.

## Step 3 - create your first admin
    php backend\tools\create_staff.php

It refuses to run unless the connected database is `felege_kidusan_test`. Choose role `admin`.

## Step 4 - start the app
    cd /d "<your test folder>"
    php -S localhost:8001 backend/router.php

Open http://localhost:8001/staff.html and sign in as the admin.

## Step 5 - tests to run (tell me which pass or fail)
1. Student login still works (an existing test student); dashboard opens.
2. Wrong password 5 times -> the 6th attempt says "Too many failed attempts".
3. Staff page: add a student with a temporary password. Log in as them on login.html -> you are sent to "change password". After changing, you reach the dashboard. Logging in again with the new password works; the temporary one no longer does.
4. Staff page: reset that student's password -> they must change it again at next login.
5. Create a teacher (`create_staff.php`, role teacher, grade e.g. `10`). In staff.html load Grade 10 lessons, unlock one, refresh the page, sign out and in: still unlocked. A teacher assigned only Grade 8 must get a permission error for Grade 10.
6. As a student, open `http://localhost:8001/api/lessons/<your id>` -> the unlocked lesson has `"unlocked": true`; the others `false` with no description.
7. While signed in as student A, open `/api/student/<student B's id>` -> must be refused (403).
8. Signed in as a student, open `/api/questions` -> must be refused (403). Signed out, `/api/questions` -> 401.
9. Quiz Me: start, answer, complete a quiz as a student; history shows it.
10. Excel import as admin/teacher still works; as a student it is refused.

## Known limits of Stage 1 (honest list)
- `lessons.html`, `tasks.html` and the dashboard still show built-in content; connecting them to the API is Stage 4.
- Quiz questions are not yet limited to unlocked lessons (Stage 3).
- `/uploads/` requires sign-in but does not yet check the student's grade.
- Teachers cannot yet view student data; only admin can.
- Staff page is English only and plain; the full design is Stage 5.
- `api/auth/register.php`, `api/lessons/get.php`, `api/lessons/unlock.php`, `api-backup-before-quiz.php`, `server.js` and the two `test_db` files are untouched and unused; remove them only after you confirm.
- The shared-initial-password policy is supported (admin sets a temporary password per student; first login forces a change). No password is stored in code.

## Login not working? Run the checker first (read-only)
    php backend\tools\check_setup.php

It only reads. It tells you, without showing any password or student data:
- which database PHP is really connected to (it stops if it is not `felege_kidusan_test`),
- which tables/columns from migration 001 are missing,
- how many students exist and whether their stored passwords are in a format the login can verify (bcrypt/argon2) - old accounts with another format must be reset by an admin, never guessed,
- whether an admin account exists.

What the login page will now tell you:
- "Cannot connect to the database..." -> the user/password/name in `backend\config\database.php` are still placeholders or wrong.
- "The database is missing required tables or columns..." -> migration 001 has not been applied to `felege_kidusan_test`.
- "Invalid student ID or password." -> the account exists but the password does not match (or the ID is wrong).
