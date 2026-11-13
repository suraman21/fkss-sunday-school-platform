<?php
/** Transaction-owned Set Current Year action. No DDL or external side effects. */
namespace App\Services;

require_once __DIR__ . '/AttendancePreparationReadBoundary.php';

final class AcademicYearActivation
{
    /**
     * @return array<string,mixed> JSON-ready result. Caller enforces role, CSRF and POST.
     * The acknowledgement binds the warnings to the locked state, not a preview.
     */
    public static function run(\mysqli $conn, int $targetId, string $confirm, string $ack): array
    {
        $started = false;
        $configured = false;
        $commitAttempted = false;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            if (!$conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')) {
                throw new \RuntimeException('Activation isolation unavailable.');
            }
            $configured = true;
            if (!$conn->begin_transaction()) throw new \RuntimeException('Activation transaction unavailable.');
            $started = true;
            $configured = false;
            try {
                foreach (['academic_terms', 'academic_years', 'class_enrollments', 'teacher_assignments'] as $table) {
                    AttendancePreparationReadBoundary::requireTransactionalTable($conn, $table);
                }
            } catch (\Throwable $e) {
                throw new \DomainException('Year activation requires readable, real InnoDB year, semester, enrolment and assignment tables. Ask an administrator to check the database.', 0, $e);
            }

            // Lock all lifecycle rows in the same order as rollover. This also
            // serializes a simultaneous rollover or another year switch.
            $rs = $conn->query('SELECT * FROM academic_years ORDER BY id FOR UPDATE');
            $years = $rs->fetch_all(MYSQLI_ASSOC);
            $rs->free();
            $target = null; $active = [];
            foreach ($years as $year) {
                $status = $year['status'] ?? null;
                $current = $year['is_current'] ?? null;
                if (!in_array($status, ['active', 'upcoming', 'closed'], true)
                    || !in_array((string)$current, ['0', '1'], true)
                    || (($status === 'active') !== ((int)$current === 1))) {
                    throw new \DomainException('Academic year state is inconsistent. Ask an administrator to check it before switching.');
                }
                if ($status === 'active') $active[] = $year;
                if ((int)$year['id'] === $targetId) $target = $year;
            }
            if (!$target) throw new \DomainException('That academic year does not exist.');
            if (count($active) > 1) throw new \DomainException('More than one academic year is active. Ask an administrator to check it before switching.');
            if ($target['status'] === 'active') {
                if (!$conn->rollback()) throw new \RuntimeException('Activation cleanup failed.');
                $started = false;
                return ['status'=>'success', 'message'=>'That year is already the active year.', 'closed_year'=>null];
            }
            $reopen = $target['status'] === 'closed';
            $initial = $reopen ? 'REOPEN' : 'SWITCH';
            if (!in_array($confirm, [$initial, 'ACTIVATE EMPTY', 'ACTIVATE ANYWAY'], true)) {
                throw new ActivationConfirmation([
                    'status'=>'error', 'code'=>$reopen ? 'confirm_reopen' : 'confirm_switch',
                    'needs_confirmation'=>true, 'reopen'=>$reopen, 'target_name'=>$target['year_name'] ?? '',
                    'message'=>$reopen
                        ? 'This year is CLOSED. Reopening it closes the current year. Type REOPEN to confirm.'
                        : 'Switching the active year closes the current year. Type SWITCH to confirm.'
                ]);
            }
            if (!function_exists('ay_validate_target_year_data')) throw new \RuntimeException('Year validation unavailable.');

            // Lock semester and roster ranges, including empty ranges, through
            // the final write. Repeatable read prevents an insert from racing an
            // empty-year decision on InnoDB (indexes may widen the lock scope).
            $stmt = $conn->prepare('SELECT * FROM academic_terms WHERE academic_year_id=? ORDER BY id FOR UPDATE');
            $stmt->bind_param('i', $targetId); $stmt->execute();
            $termsResult = $stmt->get_result(); $terms = $termsResult->fetch_all(MYSQLI_ASSOC);
            $termsResult->free(); $stmt->close();
            $enrolments = self::lockedCount($conn, "SELECT COUNT(*) c FROM class_enrollments WHERE academic_year_id=? AND status='active' FOR UPDATE", $targetId);
            $assignments = self::lockedCount($conn, 'SELECT COUNT(*) c FROM teacher_assignments WHERE academic_year_id=? AND is_active=1 FOR UPDATE', $targetId);
            $empty = !$reopen && $enrolments === 0 && $assignments === 0;
            $validation = \ay_validate_target_year_data($target, count($terms));
            $structural = array_merge($validation['errors'], $validation['warnings']);
            $issues = $structural;
            if ($empty) {
                array_unshift($issues, 'This year has no students and no teacher assignments. Mobile rosters will be empty and attendance preparation will fail until students are enrolled. Use Year Rollover to carry students safely.');
            }
            if ($issues) {
                $phrase = $structural ? 'ACTIVATE ANYWAY' : 'ACTIVATE EMPTY';
                $fingerprint = hash('sha256', json_encode(['year-activation-v1', date('Y-m-d'), $targetId, $reopen, $years, $terms, $enrolments, $assignments, $issues, $phrase], JSON_THROW_ON_ERROR));
                if ($confirm !== $phrase || !hash_equals($fingerprint, $ack)) {
                    $changed = $ack !== '' ? 'Year settings or the active year changed, or the confirmation expired. Review the current warnings again. ' : '';
                    throw new ActivationConfirmation([
                        'status'=>'error', 'code'=>$structural ? 'confirm_activate_invalid' : 'confirm_activate_empty',
                        'needs_confirmation'=>true, 'target_name'=>$target['year_name'] ?? '', 'reopen'=>$reopen,
                        'message'=>$changed.'Review all issues before switching. To activate anyway, type '.$phrase.'.',
                        'details'=>$issues, 'issues_fingerprint'=>$fingerprint,
                    ]);
                }
            } elseif ($confirm !== $initial) {
                // An old override is never repurposed as a normal switch if
                // warnings disappear between the two requests.
                throw new \DomainException('Year settings changed. Start the switch again and confirm the current state.');
            }

            $priorName = $active[0]['year_name'] ?? null;
            $conn->query("UPDATE academic_years SET status='closed', is_current=0 WHERE status='active'");
            $stmt = $conn->prepare("UPDATE academic_years SET status='active', is_current=1 WHERE id=?");
            $stmt->bind_param('i', $targetId); $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new \RuntimeException('Target year was not activated.');
            $stmt->close();
            $commitAttempted = true;
            if (!$conn->commit()) throw new \RuntimeException('Activation commit not confirmed.');
            $started = false;
            return ['status'=>'success', 'message'=>'Active year changed.', 'closed_year'=>$priorName, 'warnings'=>$issues];
        } catch (\Throwable $e) {
            if ($configured) { try { $conn->close(); } catch (\Throwable $ignored) {} }
            $rolledBack = !$started;
            if ($started) {
                try { $rolledBack = $conn->rollback(); } catch (\Throwable $ignored) { $rolledBack = false; }
                if (!$rolledBack) { try { $conn->close(); } catch (\Throwable $ignored) {} }
            }
            if ($commitAttempted || !$rolledBack) {
                return ['status'=>'error', 'message'=>'The year switch outcome could not be confirmed. Do not retry until an administrator checks the active year.'];
            }
            if ($e instanceof ActivationConfirmation) return $e->response;
            if ($e instanceof \DomainException) return ['status'=>'error', 'message'=>$e->getMessage()];
            error_log('Year activation failed: '.$e->getMessage());
            return ['status'=>'error', 'message'=>'The year switch failed. No changes were made.'];
        }
    }

    private static function lockedCount(\mysqli $conn, string $query, int $yearId): int
    {
        $stmt = $conn->prepare($query); $stmt->bind_param('i', $yearId); $stmt->execute();
        $result = $stmt->get_result(); $row = $result->fetch_assoc();
        $result->free(); $stmt->close();
        if (!isset($row['c'])) throw new \RuntimeException('Year readiness count unavailable.');
        return (int)$row['c'];
    }
}

final class ActivationConfirmation extends \RuntimeException
{
    public function __construct(public array $response) { parent::__construct('Year activation confirmation required.'); }
}
