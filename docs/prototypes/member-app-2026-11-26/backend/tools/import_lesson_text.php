<?php
// =========================================
// Put the extracted textbook text into the lessons table (lessons.description)
// and the unit introductions into chapters.description.
//
//   php backend/tools/import_lesson_text.php            (dry run: only shows what WOULD happen)
//   php backend/tools/import_lesson_text.php --apply    (writes; test database only)
//   Optional: --subject-id=N when grade 10 has more than one subject.
//
// Safety: refuses to run unless the connected database is felege_kidusan_test, refuses when the
// number of units/lessons does not match the book (5 units, 4+5+5+4+3 lessons), and only
// ever UPDATEs description columns. Back up first. Proofread the text before real students use it.
// =========================================

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../config/database.php";

$apply = in_array("--apply", $argv, true);
$subjectId = 0;
foreach ($argv as $a) {
    if (preg_match("/^--subject-id=(\d+)$/", $a, $m)) {
        $subjectId = (int) $m[1];
    }
}

$db = (string) $pdo->query("SELECT DATABASE()")->fetchColumn();
if ($db !== "felege_kidusan_test") {
    fwrite(STDERR, "Refusing to run: connected database is '$db', not felege_kidusan_test.\n");
    exit(1);
}
echo "Database: $db   Mode: " . ($apply ? "APPLY (writes)" : "DRY RUN (no writes)") . "\n\n";

$book = json_decode((string) file_get_contents(__DIR__ . "/../data/lesson_text_grade10.json"), true);
if (!$book || empty($book["chapters"])) {
    fwrite(STDERR, "Could not read data/lesson_text_grade10.json\n");
    exit(1);
}

if ($subjectId === 0) {
    $subs = $pdo->query("SELECT id, name FROM subjects WHERE grade = '10' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (count($subs) !== 1) {
        fwrite(STDERR, "Grade 10 has " . count($subs) . " subjects. Re-run with --subject-id=N. Subjects:\n");
        foreach ($subs as $s) {
            fwrite(STDERR, "  id {$s['id']}: {$s['name']}\n");
        }
        exit(1);
    }
    $subjectId = (int) $subs[0]["id"];
}

$chStmt = $pdo->prepare("SELECT id, chapter_number, title FROM chapters WHERE subject_id = ? ORDER BY sort_order, id");
$chStmt->execute([$subjectId]);
$dbChapters = $chStmt->fetchAll(PDO::FETCH_ASSOC);

$ok = count($dbChapters) === count($book["chapters"]);
$plan = [];
foreach ($book["chapters"] as $i => $bc) {
    $dc = $dbChapters[$i] ?? null;
    $ls = [];
    if ($dc) {
        $q = $pdo->prepare("SELECT id, lesson_number, title FROM lessons WHERE chapter_id = ? ORDER BY sort_order, id");
        $q->execute([$dc["id"]]);
        $ls = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    echo "UNIT " . ($i + 1) . ": book '{$bc['title']}'  <->  database '" . ($dc["title"] ?? "(missing)") . "'\n";
    if (count($ls) !== count($bc["lessons"])) {
        $ok = false;
        echo "   MISMATCH: book has " . count($bc["lessons"]) . " lessons, database has " . count($ls) . "\n";
    }
    foreach ($bc["lessons"] as $k => $bl) {
        $dl = $ls[$k] ?? null;
        echo "   book {$bl['no']} '{$bl['title']}'  <->  db " . ($dl ? "#{$dl['id']} {$dl['lesson_number']} '{$dl['title']}'" : "(missing)") . "\n";
        if ($dl) {
            $plan[] = [(int) $dl["id"], $bl["text"]];
        }
    }
    if ($dc && trim((string) $bc["intro"]) !== "") {
        $plan[] = ["chapter", (int) $dc["id"], $bc["intro"]];
    }
}

if (!$ok) {
    fwrite(STDERR, "\nCounts do not match the book. Nothing written. Check the list above.\n");
    exit(1);
}
if (!$apply) {
    echo "\nDry run finished. If the pairs above are correct, run again with --apply.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $ul = $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ?");
    $uc = $pdo->prepare("UPDATE chapters SET description = ? WHERE id = ?");
    $n = 0;
    foreach ($plan as $p) {
        if ($p[0] === "chapter") {
            $uc->execute([$p[2], $p[1]]);
        } else {
            $ul->execute([$p[1], $p[0]]);
            $n++;
        }
    }
    $pdo->commit();
    echo "\nUpdated $n lessons (and unit introductions).\n";
} catch (PDOException $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Failed, nothing was changed.\n");
    exit(1);
}
