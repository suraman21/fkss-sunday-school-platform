<?php
/** Strict scoped facts only; caller owns authorization and consistent storage. */
namespace App\Services;

require_once __DIR__ . '/SubmissionService.php';
require_once __DIR__ . '/FeatureGate.php';
require_once __DIR__ . '/SchoolCalendarService.php';

final class AttendancePreparationWorkflow
{
    /** Every table read here must be declared by the preparation boundary. */
    public static function tables(): array
    {
        $tables = ['attendance', 'grade_submissions'];
        if (FeatureGate::isEnabled('attendance_v2')) {
            $tables[] = 'attendance_sessions';
            $tables[] = 'attendance_session_records';
        }
        return $tables;
    }

    /**
     * No legacy permissive query/availability readers, writes or grants.
     * Original row years are facts, never inferred from date/roster/write year.
     * Output is NOT capture permission: authority/calendar/closed-year rules
     * and established staff override still belong to the scoped endpoint.
     */
    public static function read(\mysqli $conn, int $classId, int $writeYearId, array $dates): array
    {
        if ($classId <= 0 || $writeYearId <= 0 || $classId > 9007199254740991 || $writeYearId > 9007199254740991
            || !$dates || !array_is_list($dates)
            || count($dates) > SchoolCalendarService::BACKDATE_DAYS + SchoolCalendarService::MOBILE_FUTURE_DAYS + 1) {
            throw new \InvalidArgumentException('Explicit bounded preparation workflow scope is required.');
        }
        $days = [];
        foreach ($dates as $date) {
            if (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
                || !checkdate((int)substr($date,5,2), (int)substr($date,8,2), (int)substr($date,0,4))
                || isset($days[$date])) {
                throw new \InvalidArgumentException('Unique exact Gregorian preparation dates are required.');
            }
            $days[$date] = ['date'=>$date, 'records'=>[], 'packet'=>null, 'lesson_derived'=>false,
                'origin_year_ids'=>[], 'origin_unknown'=>false];
        }
        $placeholders = implode(',', array_fill(0, count($dates), '?'));
        $params = array_merge([$classId], $dates);
        $types = 'i' . str_repeat('s', count($dates));
        $seen = [];
        $records = self::rows($conn, 'SELECT attendance_date,member_id,academic_year_id,status,notes FROM attendance
            WHERE class_id=? AND attendance_date IN (' . $placeholders . ') ORDER BY attendance_date,member_id,academic_year_id', $types, $params);
        foreach ($records as $row) {
            $date = $row['attendance_date'];
            if (!isset($days[$date])) throw new \RuntimeException('Attendance preparation coverage is invalid.');
            $id = self::positiveId($row['member_id']);
            $year = self::originYear($row['academic_year_id']);
            $key = $id . ':' . ($year ?? 'unknown');
            if (isset($seen[$date][$key]) || !in_array($row['status'], ['present','absent','late','excused','holiday'], true)) {
                throw new \RuntimeException('Attendance preparation rows are duplicated or unsupported.');
            }
            $seen[$date][$key] = true;
            self::addOrigin($days[$date], $year);
            $days[$date]['records'][] = ['member_id'=>$id, 'academic_year_id'=>$year,
                'status'=>$row['status'], 'note'=>(string)$row['notes']];
        }
        // ALL packet origins matter; only latest id controls existing workflow.
        $packets = self::rows($conn, "SELECT id,attendance_date,academic_year_id,status FROM grade_submissions
            WHERE class_id=? AND submission_type='attendance' AND attendance_date IN (" . $placeholders . ')
            ORDER BY attendance_date,id DESC', $types, $params);
        $packetIds = [];
        foreach ($packets as $row) {
            $date = $row['attendance_date']; $id = self::positiveId($row['id']);
            if (!isset($days[$date]) || isset($packetIds[$id])) throw new \RuntimeException('Attendance packet coverage is invalid or duplicated.');
            $packetIds[$id] = true; $year = self::originYear($row['academic_year_id']);
            self::addOrigin($days[$date], $year);
            if ($days[$date]['packet'] === null) {
                $raw = strtolower(trim((string)$row['status']));
                $supported = in_array($raw, ['incomplete','submitted','approved','rejected','revision_needed','draft'], true);
                $days[$date]['packet'] = ['id'=>$id, 'academic_year_id'=>$year,
                    'status'=>SubmissionService::normalizeStatus($row['status']), 'status_supported'=>$supported];
            }
        }
        $lessonsEnabled = FeatureGate::isEnabled('attendance_v2');
        if ($lessonsEnabled) {
            // Enabled schema must exist; OFF never probes or enables it.
            self::rows($conn, 'SELECT session_id,member_id,status FROM attendance_session_records LIMIT 0', '', []);
            $lessons = self::rows($conn, 'SELECT session_date,academic_year_id,status FROM attendance_sessions
                WHERE class_id=? AND session_date IN (' . $placeholders . ')', $types, $params);
            foreach ($lessons as $row) {
                $date = $row['session_date'];
                if (!isset($days[$date]) || !in_array($row['status'], ['submitted','cancelled'], true)) {
                    throw new \RuntimeException('Lesson preparation coverage or status is unsupported.');
                }
                if ($row['status'] === 'cancelled') continue;
                $days[$date]['lesson_derived'] = true;
                self::addOrigin($days[$date], self::originYear($row['academic_year_id']));
            }
        }
        foreach ($days as &$day) {
            $years = array_keys($day['origin_year_ids']); sort($years, SORT_NUMERIC);
            $day['origin_year_ids'] = $years;
            $day['origin_matches_write_year'] = !$day['origin_unknown'] && ($years === [] || $years === [$writeYearId]);
            $packet = $day['packet'];
            $day['resolved_status'] = $packet !== null ? $packet['status'] : ($day['records'] !== [] ? SubmissionService::STATUS_SUBMITTED : null);
            $day['workflow_supported'] = $packet === null || $packet['status_supported'];
            $day['teacher_workflow_open'] = $day['workflow_supported'] && ($day['resolved_status'] === null || SubmissionService::statusIsOpen($day['resolved_status']));
        }
        unset($day);
        return ['lessons_enabled'=>$lessonsEnabled, 'days'=>array_values($days)];
    }

    private static function positiveId(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)
            || (float)$value > 9007199254740991) throw new \RuntimeException('Preparation identity is unsupported.');
        return (int)$value;
    }

    private static function originYear(mixed $value): ?int
    {
        if ($value === null || $value === 0 || $value === '0') return null;
        return self::positiveId($value);
    }

    private static function addOrigin(array &$day, ?int $year): void
    {
        if ($year === null) $day['origin_unknown'] = true;
        else $day['origin_year_ids'][$year] = true;
    }

    private static function rows(\mysqli $conn, string $sql, string $types, array $params): array
    {
        $stmt = null;
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt || ($types !== '' && !$stmt->bind_param($types, ...$params)) || !$stmt->execute()) {
                throw new \RuntimeException('Preparation workflow query failed.');
            }
            $result = $stmt->get_result();
            if (!$result) throw new \RuntimeException('Preparation workflow result unavailable.');
            try { return $result->fetch_all(MYSQLI_ASSOC); }
            finally { $result->free(); }
        } catch (\Throwable $error) {
            throw new \RuntimeException('Preparation workflow is unavailable.', 0, $error);
        } finally {
            if ($stmt) $stmt->close();
        }
    }
}
