<?php
// =========================================
// READ-ONLY setup checker. Run from the command line:
//   php backend/tools/check_setup.php
// It only runs SELECT/SHOW queries. It never prints passwords, hashes or student data.
// =========================================

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

ob_start();
require __DIR__ . "/../config/database.php";   // creates $pdo (or prints a JSON error and stops)
ob_end_clean();

$ok = true;
function line(string $status, string $text): void
{
    echo str_pad($status, 6) . $text . "\n";
}

$db = (string) $pdo->query("SELECT DATABASE()")->fetchColumn();
line("INFO", "Connected to database: $db");
if ($db !== "felege_kidusan_test") {
    line("FAIL", "This is NOT felege_kidusan_test. Stop and fix backend/config/database.php.");
    exit(1);
}

$tables = ["students", "subjects", "chapters", "lessons", "lesson_unlocks", "questions",
           "question_options", "quiz_attempts", "quiz_answers", "quiz_attempt_questions",
           "tasks", "task_attachments", "staff", "staff_scopes", "login_attempts"];
foreach ($tables as $tbl) {
    $has = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl))->fetchColumn();
    line($has ? "OK" : "FAIL", "table $tbl" . ($has ? "" : "  (missing - apply migration 001?)"));
    if (!$has) { $ok = false; }
}

function hasColumn(PDO $pdo, string $table, string $col): bool
{
    $q = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col));
    return (bool) $q->fetchColumn();
}
foreach ([["students", "must_change_password"], ["lesson_unlocks", "class"], ["lesson_unlocks", "class_key"]] as [$tb, $co]) {
    try {
        $has = hasColumn($pdo, $tb, $co);
    } catch (PDOException $e) {
        $has = false;
    }
    line($has ? "OK" : "FAIL", "column $tb.$co" . ($has ? "" : "  (missing - apply migration 001)"));
    if (!$has) { $ok = false; }
}

// Student accounts: counts only, plus the TYPE of each stored password (never the value).
$n = (int) $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
line("INFO", "students in table: $n");
if ($n > 0) {
    $rows = $pdo->query("SELECT active, LEFT(password_hash, 4) AS p, CHAR_LENGTH(password_hash) AS len FROM students")->fetchAll(PDO::FETCH_ASSOC);
    $kinds = [];
    foreach ($rows as $r) {
        $p = (string) $r["p"];
        if (in_array($p, ['$2y$', '$2a$', '$2b$'], true)) { $k = "bcrypt (works with login)"; }
        elseif (strpos($p, '$arg') === 0) { $k = "argon2 (works with login)"; }
        elseif ((int) $r["len"] === 0) { $k = "EMPTY (cannot log in)"; }
        else { $k = "NOT a PHP password hash (cannot log in until reset)"; ok_flag($ok); }
        $k .= ((int) $r["active"] === 1) ? ", active" : ", INACTIVE";
        $kinds[$k] = ($kinds[$k] ?? 0) + 1;
    }
    foreach ($kinds as $k => $c) {
        line(strpos($k, "NOT a PHP") !== false || strpos($k, "EMPTY") !== false || strpos($k, "INACTIVE") !== false ? "WARN" : "OK", "$c student(s): $k");
    }
}

try {
    $s = $pdo->query("SELECT role, COUNT(*) c FROM staff GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
    line("INFO", "staff accounts: admin=" . ($s["admin"] ?? 0) . ", teacher=" . ($s["teacher"] ?? 0));
    if (empty($s["admin"])) { line("WARN", "no admin yet - run: php backend\\tools\\create_staff.php"); }
} catch (PDOException $e) {
    line("INFO", "staff table not readable yet");
}

echo "\n" . ($ok ? "Result: setup looks complete." : "Result: fix the FAIL lines above, then run this again.") . "\n";
exit($ok ? 0 : 1);

function ok_flag(&$ok) { /* bad hashes are reported as WARN, not as a setup failure */ }
