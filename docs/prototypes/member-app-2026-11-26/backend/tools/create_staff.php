<?php
// =========================================
// Create a teacher or administrator account.
// Run from the command line ONLY:
//   php backend/tools/create_staff.php
// Refuses to run unless the connected database is felege_kidusan_test.
// =========================================

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../config/database.php";

$db = $pdo->query("SELECT DATABASE()")->fetchColumn();
if ($db !== "felege_kidusan_test") {
    fwrite(STDERR, "Refusing to run: connected database is not felege_kidusan_test.\n");
    exit(1);
}
echo "Connected to database: $db\n\n";

function ask(string $label): string
{
    echo $label . ": ";
    return trim((string) fgets(STDIN));
}

$staffId = ask("Staff ID (letters/numbers/-/_)");
$name    = ask("Full name");
$role    = strtolower(ask("Role (teacher or admin)"));
$grades  = ($role === "teacher") ? ask("Grades this teacher controls, comma separated (e.g. 10,8). Leave a grade as 10:B to limit it to class B") : "";
echo "NOTE: the password you type is visible on screen. Close this window afterwards.\n";
$password = ask("Password (min 8 characters)");

if (!preg_match("/^[A-Za-z0-9_-]{3,50}$/", $staffId) || $name === "" || !in_array($role, ["teacher", "admin"], true) || strlen($password) < 8) {
    fwrite(STDERR, "Invalid input. Nothing was saved.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT INTO staff (staff_id, name, role, password_hash, active) VALUES (?, ?, ?, ?, 1)");
    $ins->execute([$staffId, $name, $role, password_hash($password, PASSWORD_DEFAULT)]);

    if ($role === "teacher" && $grades !== "") {
        $sc = $pdo->prepare("INSERT INTO staff_scopes (staff_id, grade, `class`) VALUES (?, ?, ?)");
        foreach (explode(",", $grades) as $g) {
            $g = trim($g);
            if ($g === "") {
                continue;
            }
            $parts = explode(":", $g, 2);
            $sc->execute([$staffId, trim($parts[0]), isset($parts[1]) && trim($parts[1]) !== "" ? trim($parts[1]) : null]);
        }
    }
    $pdo->commit();
    echo "\nSaved $role account '$staffId'.\n";
} catch (PDOException $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Could not save (is the ID already used, or has migration 001 been applied?).\n");
    exit(1);
}
