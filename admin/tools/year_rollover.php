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
require_once __DIR__ . '/../backend/services/AttendancePreparationReadBoundary.php';

header('Content-Type: application/json; charset=utf-8');

function _rollover_out($arr) { echo json_encode($arr); exit; }

final class RolloverReadinessConfirmation extends DomainException {
    public function __construct(public array $details, public string $fingerprint) {
        parent::__construct('The target year has issues that affect attendance or grades. Review them and fix them, or explicitly confirm activation anyway.');
    }
}

/** INSERT IGNORE may suppress errors as well as duplicates; accept duplicates only. */
function _rollover_check_ignore_warnings(mysqli $conn, string $context): void {
    $count = $conn->warning_count;
    if ($count === 0) return;
    $warnings = $conn->get_warnings();
    if (!$warnings) throw new RuntimeException($context . ' warnings unavailable.');
    $inspected = 0;
    do {
        $inspected++;
        if ((int)$warnings->errno !== 1062) throw new RuntimeException($context . ' produced a warning.');
    } while ($warnings->next());
    // Servers may truncate details at max_error_count; omitted warnings are unknown.
    if ($inspected !== $count) throw new RuntimeException($context . ' warning details were truncated.');
}

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

    // This endpoint must not commit after a silently failed SQL statement,
    // even on hosts that globally disable mysqli exceptions.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $transactionStarted = false;
    $commitAttempted = false;
    try {
        // Next-transaction only: lock the target semester range (including an
        // empty range) against inserts until the decision commits or rolls back.
        if ($conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') !== true) throw new RuntimeException('Could not establish rollover isolation.');
        if (!$conn->begin_transaction()) throw new RuntimeException('Could not start rollover transaction.');
        $transactionStarted = true;
        // The atomicity promise is valid only when every participating table is
        // transactional. Hold metadata locks until commit; fail before any writes.
        try {
            // Migration 048's teacher-assignment trigger also updates users.
            $tables = ['academic_terms', 'academic_years', 'class_enrollments', 'members', 'teacher_assignments', 'users'];
            if ($mode === 'promote') $tables[] = 'classes';
            sort($tables, SORT_STRING);
            foreach ($tables as $table) \App\Services\AttendancePreparationReadBoundary::requireTransactionalTable($conn, $table);
        } catch (Throwable $storageError) {
            throw new DomainException('Rollover requires real InnoDB tables and readable storage metadata. Ask an administrator to check the database before retrying.', 0, $storageError);
        }
        // Lock and validate the lifecycle before changing any years or enrolments.
        // A missing target must not close every existing year, and a stale form
        // must not roll over a year that is no longer the active one.
        $years = $conn->query("SELECT id, status, is_current FROM academic_years ORDER BY id FOR UPDATE");
        if (!$years) throw new RuntimeException('Could not verify rollover years.');
        $oldYear = null; $newYear = null; $activeCount = 0;
        while ($year = $years->fetch_assoc()) {
            if ($year['status'] === 'active') $activeCount++;
            if ((int)$year['id'] === $oldYearId) $oldYear = $year;
            if ((int)$year['id'] === $newYearId) $newYear = $year;
        }
        $years->free();
        if (!$oldYear || !$newYear || $activeCount !== 1
            || $oldYear['status'] !== 'active' || (int)$oldYear['is_current'] !== 1
            || $newYear['status'] !== 'upcoming' || (int)$newYear['is_current'] !== 0) {
            throw new DomainException('Choose the current active year and an upcoming year. Refresh the page and check the year settings.');
        }
        // Validate locked current data, not a stale preview. Every warning
        // acknowledgement is bound to the exact target, mode, date and rows.
        if (!function_exists('ay_validate_target_year_data')) throw new RuntimeException('Year validation is unavailable.');
        $yearDetails = $conn->prepare('SELECT * FROM academic_years WHERE id=? FOR UPDATE');
        if (!$yearDetails || !$yearDetails->bind_param('i', $newYearId) || !$yearDetails->execute()) throw new RuntimeException('Year details unavailable.');
        $yearResult = $yearDetails->get_result();
        if (!$yearResult || !($targetDetails = $yearResult->fetch_assoc())) throw new RuntimeException('Year details unavailable.');
        $yearResult->free(); $yearDetails->close();
        $termStmt = $conn->prepare('SELECT * FROM academic_terms WHERE academic_year_id=? ORDER BY id FOR UPDATE');
        if (!$termStmt || !$termStmt->bind_param('i', $newYearId) || !$termStmt->execute()) throw new RuntimeException('Semester validation unavailable.');
        $termResult = $termStmt->get_result();
        if (!$termResult) throw new RuntimeException('Semester validation unavailable.');
        $terms = $termResult->fetch_all(MYSQLI_ASSOC);
        $termResult->free(); $termStmt->close();
        $validation = ay_validate_target_year_data($targetDetails, count($terms));
        $issues = array_merge($validation['errors'], $validation['warnings']);
        if ($issues) {
            $fingerprint = hash('sha256', json_encode([$oldYearId, $newYearId, $mode, date('Y-m-d'), $targetDetails, $terms, $issues], JSON_THROW_ON_ERROR));
            $ack = (string)($_POST['issues_fingerprint'] ?? '');
            $phrase = strtoupper(trim((string)($_POST['confirm_issues'] ?? '')));
            if ($phrase !== 'ACTIVATE ANYWAY' || !hash_equals($fingerprint, $ack)) {
                throw new RolloverReadinessConfirmation($issues, $fingerprint);
            }
        }
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
            _rollover_check_ignore_warnings($conn, 'Enrollment copy');
            $st->close();
            $check = $conn->prepare("SELECT COUNT(*) AS missing FROM class_enrollments src JOIN members m ON m.id=src.member_id WHERE src.academic_year_id=? AND src.status='active' AND m.status <> 'archived' AND NOT EXISTS (SELECT 1 FROM class_enrollments dest WHERE dest.member_id=src.member_id AND dest.class_id=src.class_id AND dest.academic_year_id=? AND dest.status='active')");
            if (!$check || !$check->bind_param('ii', $oldYearId, $newYearId) || !$check->execute()) throw new RuntimeException('Enrollment verification failed.');
            $checked = $check->get_result();
            if (!$checked || !($checkRow = $checked->fetch_assoc()) || (int)$checkRow['missing'] !== 0) throw new DomainException('Some destination enrollments are inactive or missing. Review the target year enrollments before retrying.');
            $checked->free(); $check->close();

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
                    $inserted = $insEnr->affected_rows;
                    _rollover_check_ignore_warnings($conn, 'Promotion enrollment');
                    if ($inserted === 0) {
                        $check = $conn->prepare("SELECT 1 FROM class_enrollments WHERE member_id=? AND class_id=? AND academic_year_id=? AND status='active' LIMIT 1");
                        if (!$check || !$check->bind_param('iii', $memberId, $toClass, $newYearId) || !$check->execute()) throw new RuntimeException('Promotion enrollment verification failed.');
                        $checked = $check->get_result();
                        if (!$checked || !$checked->fetch_assoc()) throw new DomainException('A destination enrollment is inactive or missing. Review the target year enrollments before retrying.');
                        $checked->free(); $check->close();
                    }
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
        $copiedTA = 0;
        $taStmt = $conn->prepare(
            "INSERT IGNORE INTO teacher_assignments
                (teacher_id, class_id, subject_id, academic_year_id, is_class_teacher, is_primary, assignment_role, assigned_by, assigned_at, is_active)
             SELECT teacher_id, class_id, subject_id, ?, is_class_teacher, is_primary, assignment_role, ?, NOW(), 1
             FROM teacher_assignments
             WHERE academic_year_id = ? AND is_active = 1"
        );
        if (!$taStmt) throw new RuntimeException('Teacher assignment copy could not be prepared.');
        try {
            if (!$taStmt->bind_param("iii", $newYearId, $adminId, $oldYearId) || !$taStmt->execute()) {
                throw new RuntimeException('Teacher assignment copy failed.');
            }
            $copiedTA = (int)$taStmt->affected_rows;
            // INSERT IGNORE is retained for already-existing assignments only.
            _rollover_check_ignore_warnings($conn, 'Teacher assignment copy');
        } finally {
            $taStmt->close();
        }
        // A duplicate on an unexpected unique index must not silently omit an
        // assignment. Inactive target rows cannot grant the access being copied;
        // do not silently reactivate them or close the old year.
        $taCheck = $conn->prepare(
            "SELECT COUNT(*) AS missing FROM teacher_assignments src
             WHERE src.academic_year_id = ? AND src.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM teacher_assignments dest
                   WHERE dest.academic_year_id = ? AND dest.is_active = 1 AND dest.teacher_id = src.teacher_id
                     AND dest.class_id = src.class_id
                     AND IFNULL(dest.subject_id, 0) = IFNULL(src.subject_id, 0)
               )"
        );
        if (!$taCheck) throw new RuntimeException('Teacher assignment verification could not be prepared.');
        try {
            if (!$taCheck->bind_param("ii", $oldYearId, $newYearId) || !$taCheck->execute()) {
                throw new RuntimeException('Teacher assignment verification failed.');
            }
            $result = $taCheck->get_result();
            if (!$result) throw new RuntimeException('Teacher assignment verification result unavailable.');
            $row = $result->fetch_assoc();
            $result->free();
            if (!$row || (int)$row['missing'] !== 0) {
                throw new DomainException('Some destination teacher assignments are inactive or missing. Review the target year assignments before retrying.');
            }
        } finally {
            $taCheck->close();
        }

        // 4. Mark the closed year's enrolments as completed (enrolment domain).
        $st = $conn->prepare("UPDATE class_enrollments SET status = 'completed' WHERE academic_year_id = ? AND status = 'active'");
        $st->bind_param("i", $oldYearId);
        $st->execute();
        $closedCount = $st->affected_rows;
        $st->close();

        // If commit fails or the connection drops, the outcome may be unknown.
        $commitAttempted = true;
        if (!$conn->commit()) throw new RuntimeException('Rollover commit was not confirmed.');
        $transactionStarted = false;

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
        } catch (Throwable $e) { /* logging is optional */ }

        $summary = ($mode === 'carry_forward')
            ? "$carried student(s) carried into the new year" . ($copiedTA ? " and $copiedTA teacher assignment(s) copied" : "") . "."
            : "$promoted promoted, $graduated graduated" . ($skipped ? ", $skipped need manual placement" : "") . ($copiedTA ? ", $copiedTA teacher assignment(s) copied" : "") . ".";
        _rollover_out([
            'status'  => 'success',
            'message' => 'Rollover complete. ' . $summary,
            'report'  => $report,
        ]);

    } catch (Throwable $e) {
        $rolledBack = !$transactionStarted;
        if ($transactionStarted) {
            try { $rolledBack = $conn->rollback(); } catch (Throwable $rollbackError) { $rolledBack = false; }
        }
        error_log("Year rollover failed: " . $e->getMessage());
        if ($commitAttempted || !$rolledBack) {
            $message = 'The rollover outcome could not be confirmed. Do not retry until an administrator checks the years and enrolments.';
        } elseif ($e instanceof DomainException) {
            $message = $e->getMessage();
        } else {
            $message = 'The rollover failed and NOTHING was changed. The error was logged.';
        }
        $response = ['status' => 'error', 'message' => $message];
        if (!$commitAttempted && $rolledBack && $e instanceof RolloverReadinessConfirmation) {
            $response['code'] = 'confirm_rollover_invalid';
            $response['needs_confirmation'] = true;
            $response['details'] = $e->details;
            $response['issues_fingerprint'] = $e->fingerprint;
        }
        _rollover_out($response);
    }
}

// Unknown / unsupported request.
http_response_code(400);
_rollover_out(['status' => 'error', 'message' => 'Unknown action.']);
