# Test checklist (run on Windows against felege_kidusan_test)
Mark each as PASS/FAIL. Nothing below has been run by the author against PHP/MySQL.
1. `php backend\tools\check_setup.php` shows no FAIL.
2. Existing student logs in; wrong password 5× → "Too many failed attempts".
3. Home shows the original page; verse of the day shows an Amharic verse with its reference.
4. Lessons: unit 1 shows lessons 1–2 open, the rest locked; unit 2 locked with the explanation.
5. Teacher unlocks lesson 3 (API/dashboard) → student sees it open after refresh. Locked lesson URL `lesson.html?id=X` shows the lock card; `/api/learning/lessons/X` returns 403.
6. Open lesson 1 → text shows; press "I finished" → completed; progress page changes; nothing else unlocks.
7. After all lessons of unit 1 are completed, the unit quiz node opens → Quiz Me preselects the unit → pass it. Unit 2 stays locked until a teacher unlocks unit 2.
8. Quiz Me still works (immediate and final review). Questions of locked lessons never appear.
9. Tasks page shows the grade's tasks with attachments.
10. Bell: insert a row into `notifications` → badge appears; reading clears it.
11. Profile menu: attendance empty message; after inserting `attendance` rows → counts and percent. "Ask for permission" saves a row in `permission_requests`.
12. Settings (gear): change password works; sign out ends the session.
13. Floating dove opens the chat window; asking returns passages only from open lessons.
14. Past papers (after demo SQL): 2018 paper opens from Exam Prep.
15. Phone widths 320/375: no sideways scroll; bottom bar shows all six links.
