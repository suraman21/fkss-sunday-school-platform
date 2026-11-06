<?php
/**
 * ============================================================
 * ACADEMIC YEAR ROLLOVER — backend processor (JSON only)
 * ============================================================
 * URL: /admin/tools/year_rollover.php
 *
 * This used to be a standalone HTML page. It is now a JSON endpoint driven by
 * the "Academic Year" section of the School Admin dashboard (styled modal), so
 * there is ONE rollover, inside the dashboard, matching the rest of the UI.
 *
 *   GET  ?action=preview[&old_year_id=N]
 *        → { status, years:[{id,year_name,ec_year,is_current,status}],
 *            current_id, old_year_id, count }
 *   POST  action=run  (old_year_id, new_year_id, mode, confirm=ROLLOVER, csrf_token)
 *        → { status, message, report:{mode,carried,promoted,graduated,skipped,closed} }
 *
 * WHO: School Admin (owner) + Super Admin (break-glass) — matches the lifecycle.
 * HOW: ONE all-or-nothing transaction. The chosen new year becomes 'active';
 *      the year being closed becomes 'closed' (year lifecycle). Finished
 *      enrolments are marked 'completed' — that is the ENROLMENT domain, a
 *      different concept from the YEAR being 'closed'. Two modes:
 *        • carry_forward (default): same class, new year.
 *        • promote: each student moves up one level; top level graduates.
 * ============================================================
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';

header('Content-Type: application/json; charset=utf-8');

function _rollover_out($arr) { echo json_encode($arr); exit; }

// ── School Admin (owner) + Super Admin (break-glass) ONLY ──
if (empty($_SESSION['admin_logged_in']) || !in_array($_SESSION['admin_role'] ?? '', ['super_admin', 'school_admin'], true)) {
    http_response_code(403);
    _rollover_out(['status' => 'error', 'message' => 'Only a School Admin or Super Admin can run the year rollover.']);
}

// Guarantee the lifecycle `status` column exists before we touch it below.
if (function_exists('ay_ensure_schema')) { ay_ensure_schema($conn); }

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$action  = $_REQUEST['action'] ?? 'preview';

/** Count active, non-archived enrolments in a year (for the preview). */
function countActiveEnrolments($conn, $yearId) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM class_enrollments ce
        JOIN members m ON m.id = ce.member_id
        WHERE ce.academic_year_id = ?
          AND ce.status = 'active'
          AND m.status <> 'archived'
    ");
    if (!$stmt) return 0;
    $stmt->bind_param("i", $yearId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

/** All academic years (newest first) with lifecycle status. */
function rollover_years($conn) {
    $years = [];
    $res = $conn->query("SELECT id, year_name, ec_year, is_current, status FROM academic_years ORDER BY COALESCE(ec_year,0) DESC, id DESC");
    if ($res) { while ($r = $res->fetch_assoc()) { $years[] = $r; } }
    return $years;
}

// ============================================================
// PREVIEW (GET) — populate the dashboard modal
// ============================================================
if ($action === 'preview') {
    $years = rollover_years($conn);
    $currentId = 0;
    foreach ($years as $y) {
        if (($y['status'] ?? '') === 'active' || (int)$y['is_current'] === 1) { $currentId = (int)$y['id']; break; }
    }
    $oldYearId = (int)($_GET['old_year_id'] ?? $currentId);
    $count = $oldYearId ? countActiveEnrolments($conn, $oldYearId) : 0;
    _rollover_out([
        'status'      => 'success',
        'years'       => $years,
        'current_id'  => $currentId,
        'old_year_id' => $oldYearId,
        'count'       => $count,
    ]);
}

// ============================================================
// RUN (POST) — the atomic rollover
// ============================================================
if ($action === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        _rollover_out(['status' => 'error', 'message' => 'Security token expired. Please refresh the page and try again.']);
    }

    // A rollover is a live, destructive action — it must NOT be run while the
    // user is time-travelling (viewing a past year, read-only). Return to the
    // current year first.
    if (function_exists('ay_is_readonly') && ay_is_readonly($conn)) {
        _rollover_out(['status' => 'error', 'message' => 'You are viewing a past year (read-only). Return to the current year before running a rollover.']);
    }

    $oldYearId = (int)($_POST['old_year_id'] ?? 0);
    $newYearId = (int)($_POST['new_year_id'] ?? 0);
    $mode      = ($_POST['mode'] ?? 'carry_forward') === 'promote' ? 'promote' : 'carry_forward';
    $confirm   = trim($_POST['confirm'] ?? '');

    if ($confirm !== 'ROLLOVER') {
        _rollover_out(['status' => 'error', 'message' => 'You must type the word ROLLOVER exactly to confirm.']);
    }
    if (!$oldYearId || !$newYearId) {
        _rollover_out(['status' => 'error', 'message' => 'Please choose both the year to close and the new year.']);
    }
    if ($oldYearId === $newYearId) {
        _rollover_out(['status' => 'error', 'message' => 'The year to close and the new year must be different.']);
    }

    $conn->begin_transaction();
    $commitAttempted = false;
    try {
        // 1. Make the new year current AND lifecycle-correct (status + is_current
        //    together). Any currently active year is CLOSED; the chosen new year
        //    becomes active. Inline (not ay_switch_active) — that helper opens
        //    its own transaction, which cannot nest inside this one.
        $st = $conn->prepare("UPDATE academic_years SET is_current = 0, status = CASE WHEN status = 'active' THEN 'closed' ELSE status END WHERE id <> ?");
        $st->bind_param("i", $newYearId);
        $st->execute();
        $st->close();
        $st = $conn->prepare("UPDATE academic_years SET is_current = 1, status = 'active' WHERE id = ?");
        $st->bind_param("i", $newYearId);
        $st->execute();
        $st->close();

        $carried = 0; $promoted = 0; $graduated = 0; $skipped = 0;

        if ($mode === 'carry_forward') {
            // 2a. Copy every active, non-archived enrolment into the new year,
            //     SAME class. INSERT IGNORE skips anyone already enrolled there.
            $st = $conn->prepare("
                INSERT IGNORE INTO class_enrollments
                    (member_id, class_id, academic_year_id, enrolled_at, status, enrolled_by)
                SELECT ce.member_id, ce.class_id, ?, CURDATE(), 'active', ?
                FROM class_enrollments ce
                JOIN members m ON m.id = ce.member_id
                WHERE ce.academic_year_id = ?
                  AND ce.status = 'active'
                  AND m.status <> 'archived'
            ");
            $st->bind_param("iii", $newYearId, $adminId, $oldYearId);
            $st->execute();
            $carried = $st->affected_rows;
            $st->close();

        } else {
            // 2b. Promote one level. If none higher → graduate. If ambiguous
            //     (several classes at the next level) → skip for manual placement.
            $sel = $conn->prepare("
                SELECT ce.member_id, ce.class_id, c.level_order
                FROM class_enrollments ce
                JOIN members m ON m.id = ce.member_id
                JOIN classes c ON c.id = ce.class_id
                WHERE ce.academic_year_id = ?
                  AND ce.status = 'active'
                  AND m.status <> 'archived'
            ");
            $sel->bind_param("i", $oldYearId);
            $sel->execute();
            $rows = $sel->get_result();
            $sel->close();

            $insEnr = $conn->prepare("
                INSERT IGNORE INTO class_enrollments
                    (member_id, class_id, academic_year_id, enrolled_at, status, promoted_from, enrolled_by)
                VALUES (?, ?, ?, CURDATE(), 'active', ?, ?)
            ");
            $updMember  = $conn->prepare("UPDATE members SET current_class_id = ?, promoted_at = CURDATE() WHERE id = ?");
            $gradMember = $conn->prepare("UPDATE members SET academic_status = 'graduated' WHERE id = ?");

            while ($row = $rows->fetch_assoc()) {
                $memberId  = (int)$row['member_id'];
                $fromClass = (int)$row['class_id'];
                $level     = (int)$row['level_order'];

                $nx = $conn->prepare("SELECT id FROM classes WHERE level_order = ? AND is_active = 1");
                $nextLevel = $level + 1;
                $nx->bind_param("i", $nextLevel);
                $nx->execute();
                $nres = $nx->get_result();
                $targets = [];
                while ($tr = $nres->fetch_assoc()) { $targets[] = (int)$tr['id']; }
                $nx->close();

                if (count($targets) === 1) {
                    $toClass = $targets[0];
                    $insEnr->bind_param("iiiii", $memberId, $toClass, $newYearId, $fromClass, $adminId);
                    $insEnr->execute();
                    $updMember->bind_param("ii", $toClass, $memberId);
                    $updMember->execute();
                    $promoted++;
                } elseif (count($targets) === 0) {
                    $gradMember->bind_param("i", $memberId);
                    $gradMember->execute();
                    $graduated++;
                } else {
                    $skipped++;
                }
            }
            $insEnr->close();
            $updMember->close();
            $gradMember->close();
        }

        // 3. Copy year-scoped teacher assignments into the new year (R4).
        // Global assignments (academic_year_id IS NULL) already apply to every
        // year via AttendancePreparationService, so only copy the scoped ones.
        $taStmt = $conn->prepare(
            "INSERT INTO teacher_assignments
                (teacher_id, class_id, subject_id, academic_year_id, is_class_teacher, is_primary, assignment_role, assigned_by, assigned_at, is_active)
             SELECT source.teacher_id, source.class_id, source.subject_id, ?, source.is_class_teacher, source.is_primary, source.assignment_role, ?, NOW(), 1
             FROM teacher_assignments AS source
             WHERE source.academic_year_id = ? AND source.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM teacher_assignments AS target
                   WHERE target.teacher_id = source.teacher_id
                     AND target.class_id = source.class_id
                     AND COALESCE(target.subject_id, 0) = COALESCE(source.subject_id, 0)
                     AND target.academic_year_id = ?
               )"
        );
        if (!$taStmt) throw new RuntimeException('Teacher assignment copy could not be prepared.');
        try {
            if (!$taStmt->bind_param("iiii", $newYearId, $adminId, $oldYearId, $newYearId) || !$taStmt->execute()) {
                throw new RuntimeException('Teacher assignment copy failed.');
            }
            $copiedTA = (int)$taStmt->affected_rows;
            // An existing assignment is excluded above using the migration's
            // unique-key semantics. Do not ignore any SQL warning: in lax SQL
            // modes even a plain INSERT may otherwise silently coerce data.
            if ($conn->warning_count > 0) {
                throw new RuntimeException('Teacher assignment copy returned a data warning.');
            }
        } finally {
            $taStmt->close();
        }

        // 4. Mark the closed year's enrolments as completed (enrolment domain).
        $st = $conn->prepare("UPDATE class_enrollments SET status = 'completed' WHERE academic_year_id = ? AND status = 'active'");
        $st->bind_param("i", $oldYearId);
        $st->execute();
        $closedCount = $st->affected_rows;
        $st->close();

        $commitAttempted = true;
        if (!$conn->commit()) throw new RuntimeException('Rollover commit was not confirmed.');

        $report = [
            'mode'               => $mode,
            'carried'            => $carried,
            'promoted'           => $promoted,
            'graduated'          => $graduated,
            'skipped'            => $skipped,
            'closed'             => $closedCount,
            'teacher_assignments_copied' => $copiedTA,
        ];

        // Optional: log it (non-fatal).
        try {
            $lg = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, created_at) VALUES (?, ?, 'Year Rollover', ?, NOW())");
            if ($lg) {
                $uname = $_SESSION['admin_username'] ?? 'admin';
                $details = "Mode=$mode closed_year=$oldYearId new_year=$newYearId carried=$carried promoted=$promoted graduated=$graduated skipped=$skipped teacher_assignments_copied=$copiedTA";
                $lg->bind_param("iss", $adminId, $uname, $details);
                $lg->execute();
                $lg->close();
            }
        } catch (Exception $e) { /* logging is optional */ }

        $summary = ($mode === 'carry_forward')
            ? "$carried student(s) carried into the new year" . ($copiedTA ? " and $copiedTA teacher assignment(s) copied" : "") . "."
            : "$promoted promoted, $graduated graduated" . ($skipped ? ", $skipped need manual placement" : "") . ($copiedTA ? ", $copiedTA teacher assignment(s) copied" : "") . ".";
        _rollover_out([
            'status'  => 'success',
            'message' => 'Rollover complete. ' . $summary,
            'report'  => $report,
        ]);

    } catch (Throwable $e) {
        $rollbackConfirmed = false;
        try { $rollbackConfirmed = $conn->rollback(); } catch (Throwable $rollbackError) {
            error_log('Year rollover rollback could not be confirmed.');
        }
        error_log("Year rollover failed: " . $e->getMessage());
        $message = !$commitAttempted && $rollbackConfirmed
            ? 'The rollover failed and NOTHING was changed. The error was logged.'
            : 'The rollover outcome could not be confirmed. Do not retry until an administrator checks the year and enrolments.';
        _rollover_out(['status' => 'error', 'message' => $message]);
    }
}

// Unknown / unsupported request.
http_response_code(400);
_rollover_out(['status' => 'error', 'message' => 'Unknown action.']);
