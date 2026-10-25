<?php
/**
 * Education timetable builder — per‑class working days, per‑class bell
 * schedule, weekly grid (class × period × ISO weekday) and dated makeup
 * classes, all scoped to one academic year.
 *
 * Rules (ported from the owner's eduid reference and the approved demo):
 *   • periods of one class may touch but never overlap;
 *   • a lesson may only be placed on one of the class's working days and on
 *     a period that belongs to that class;
 *   • a teacher cannot be in two classes whose periods overlap in time on
 *     the same weekday (checked across all classes of the year);
 *   • removing a working day / a period clears the lessons on it and
 *     reports how many were cleared;
 *   • makeup classes must be today or later and respect the same teacher
 *     busy rule against the weekly grid and other makeups on that date;
 *   • every write is recorded in activity_logs.
 *
 * Schema: sql/007_timetable.sql + sql/077_timetable_builder.sql.
 * Dates are stored Gregorian and rendered Ethiopian by the callers.
 */

namespace App\Services;

class TimetableService
{
    public const DEFAULT_DAYS = [6, 7];

    /** @var bool|null per-request cache for schemaReady() */
    private static ?bool $schemaReadyCache = null;

    /**
     * True when sql/077_timetable_builder.sql has been applied to this
     * database (timetable_class_days table + the 077 columns on
     * timetable_entries / timetable_periods). Schema is deployment-managed
     * (ensureSchema() is a no-op by design); on a database that has not run
     * 077 yet every public entry point below degrades to an empty/declined
     * response instead of fataling on missing tables — production may be
     * running this code before the owner has run the SQL in phpMyAdmin.
     */
    public static function schemaReady(\mysqli $conn): bool
    {
        if (self::$schemaReadyCache !== null) {
            return self::$schemaReadyCache;
        }
        $ready = false;
        if ($r = $conn->query(
            "SELECT
               (SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable_class_days'),
               (SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable_makeup_classes'),
               (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable_entries'
                   AND COLUMN_NAME = 'academic_year_id'),
               (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable_entries'
                   AND COLUMN_NAME = 'room'),
               (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable_periods'
                   AND COLUMN_NAME = 'class_id')"
        )) {
            $row = $r->fetch_row();
            $r->free();
            $ready = $row !== null
                && (int)$row[0] === 1 && (int)$row[1] === 1
                && (int)$row[2] === 1 && (int)$row[3] === 1 && (int)$row[4] === 1;
        }
        return self::$schemaReadyCache = $ready;
    }

    /** Uniform write refusal while sql/077 is missing. */
    private static function schemaNotInstalled(): array
    {
        return [
            'status'  => 'error',
            'message' => 'Timetable module is not installed yet. Run sql/077_timetable_builder.sql first.',
        ];
    }

    /** Compatibility hook; schema is deployment-managed by 007 + 077. */
    public static function ensureSchema(\mysqli $conn): void
    {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reads
    // ─────────────────────────────────────────────────────────────────────

    /** Everything the builder needs for one class. */
    public static function classSetup(\mysqli $conn, int $classId, int $yearId): array
    {
        if ($classId <= 0 || $yearId <= 0) {
            return ['status' => 'error', 'message' => 'Class and academic year are required.'];
        }
        if (!self::schemaReady($conn)) {
            return [
                'status'           => 'success',
                'available'        => false,
                'class_id'         => $classId,
                'academic_year_id' => $yearId,
                'working_days'     => self::DEFAULT_DAYS,
                'periods'          => [],
                'entries'          => [],
                'teachers'         => [],
                'subjects'         => self::subjects($conn),
                'makeups'          => [],
            ];
        }
        return [
            'status'           => 'success',
            'class_id'         => $classId,
            'academic_year_id' => $yearId,
            'working_days'     => self::workingDays($conn, $classId, $yearId),
            'days_saved'       => self::daysSaved($conn, $classId, $yearId),
            'periods'          => self::periodsFor($conn, $classId, $yearId),
            'entries'          => self::entriesFor($conn, $classId, $yearId),
            'teachers'         => self::teachersFor($conn, $classId, $yearId),
            'subjects'         => self::subjects($conn),
            'makeups'          => self::makeupsFor($conn, $classId, $yearId),
        ];
    }

    /** @return int[] ISO weekdays */
    public static function workingDays(\mysqli $conn, int $classId, int $yearId): array
    {
        $stmt = $conn->prepare(
            "SELECT working_days FROM timetable_class_days WHERE class_id = ? AND academic_year_id = ? LIMIT 1"
        );
        if (!$stmt) {
            return self::DEFAULT_DAYS;
        }
        $stmt->bind_param('ii', $classId, $yearId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return self::DEFAULT_DAYS;
        }
        return self::parseDays((string)$row['working_days']);
    }

    /**
     * True when the class has a saved working-days row for the year. Without
     * one, workingDays() shows DEFAULT_DAYS but the class-day attendance rule
     * treats the class as not configured, so the builder must offer Save.
     */
    private static function daysSaved(\mysqli $conn, int $classId, int $yearId): bool
    {
        $stmt = $conn->prepare(
            "SELECT 1 FROM timetable_class_days WHERE class_id = ? AND academic_year_id = ? LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $classId, $yearId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row !== null;
    }

    public static function periodsFor(\mysqli $conn, int $classId, int $yearId): array
    {
        $rows = [];
        $stmt = $conn->prepare(
            "SELECT id, label, start_time, end_time, is_break, sort_order
             FROM timetable_periods
             WHERE class_id = ? AND academic_year_id = ?
             ORDER BY start_time, id"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $classId, $yearId);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $rows[] = self::formatPeriod($row);
            }
            $stmt->close();
        }
        return $rows;
    }

    public static function entriesFor(\mysqli $conn, int $classId, int $yearId): array
    {
        $rows = [];
        $stmt = $conn->prepare(
            "SELECT e.id, e.class_id, e.period_id, e.day_of_week, e.subject_id, e.teacher_id, e.room,
                    s.subject_name, s.subject_name_en, u.full_name AS teacher_name
             FROM timetable_entries e
             LEFT JOIN subjects s ON s.id = e.subject_id
             LEFT JOIN users u ON u.id = e.teacher_id
             WHERE e.class_id = ? AND e.academic_year_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $classId, $yearId);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $rows[] = self::formatEntry($row);
            }
            $stmt->close();
        }
        return $rows;
    }

    /**
     * Teachers for the picker: every active teacher account, flagged with
     * whether they are assigned to this class this year and which subjects.
     */
    public static function teachersFor(\mysqli $conn, int $classId, int $yearId): array
    {
        $assigned = []; // teacher_id => [subject_id,...]
        $stmt = $conn->prepare(
            "SELECT teacher_id, subject_id FROM teacher_assignments
             WHERE class_id = ? AND is_active = 1 AND status = 'active'
               AND (academic_year_id = ? OR academic_year_id IS NULL)"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $classId, $yearId);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $tid = (int)$row['teacher_id'];
                $assigned[$tid] = $assigned[$tid] ?? [];
                if (!empty($row['subject_id'])) {
                    $assigned[$tid][] = (int)$row['subject_id'];
                }
            }
            $stmt->close();
        }
        $out = [];
        $r = $conn->query(
            "SELECT id, full_name, username FROM users
             WHERE role = 'teacher' AND is_active = 1 ORDER BY full_name"
        );
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $tid = (int)$row['id'];
                $out[] = [
                    'id'       => $tid,
                    'name'     => $row['full_name'] ?: $row['username'],
                    'assigned' => array_key_exists($tid, $assigned),
                    'subjects' => array_values(array_unique($assigned[$tid] ?? [])),
                ];
            }
        }
        usort($out, static function ($a, $b) {
            if ($a['assigned'] !== $b['assigned']) {
                return $a['assigned'] ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });
        return $out;
    }

    public static function subjects(\mysqli $conn): array
    {
        $out = [];
        $r = $conn->query(
            "SELECT id, subject_name, subject_name_en FROM subjects WHERE is_active = 1 ORDER BY subject_name"
        );
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $out[] = [
                    'id'      => (int)$row['id'],
                    'name'    => $row['subject_name'] ?? '',
                    'name_en' => $row['subject_name_en'] ?? '',
                ];
            }
        }
        return $out;
    }

    /** Upcoming (today or later) makeup classes of one class. */
    public static function makeupsFor(\mysqli $conn, int $classId, int $yearId): array
    {
        $rows = [];
        $stmt = $conn->prepare(
            "SELECT m.*, s.subject_name, s.subject_name_en, u.full_name AS teacher_name, c.class_name, c.class_name_en
             FROM timetable_makeup_classes m
             LEFT JOIN subjects s ON s.id = m.subject_id
             LEFT JOIN users u ON u.id = m.teacher_id
             JOIN classes c ON c.id = m.class_id
             WHERE m.class_id = ? AND m.academic_year_id = ? AND m.lesson_date >= CURDATE()
             ORDER BY m.lesson_date, m.start_time"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $classId, $yearId);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $rows[] = self::formatMakeup($row);
            }
            $stmt->close();
        }
        return $rows;
    }

    /**
     * Teacher weekly schedule + upcoming makeups (teacher dashboard, mobile later).
     */
    public static function teacherSchedule(\mysqli $conn, int $teacherId, int $yearId): array
    {
        $today0 = new \DateTime('now', new \DateTimeZone('Africa/Addis_Ababa'));
        if (!self::schemaReady($conn)) {
            return [
                'status'      => 'success',
                'available'   => false,
                'teacher_id'  => $teacherId,
                'today_iso'   => (int)$today0->format('N'),
                'today_date'  => $today0->format('Y-m-d'),
                'lessons'     => [],
                'makeups'     => [],
            ];
        }
        $lessons = [];
        if ($teacherId > 0 && $yearId > 0) {
            $stmt = $conn->prepare(
                "SELECT e.id, e.class_id, e.period_id, e.day_of_week, e.subject_id, e.teacher_id, e.room,
                        p.label AS period_label, p.start_time, p.end_time, p.is_break,
                        c.class_name, c.class_name_en,
                        s.subject_name, s.subject_name_en
                 FROM timetable_entries e
                 JOIN timetable_periods p ON p.id = e.period_id
                 JOIN classes c ON c.id = e.class_id
                 LEFT JOIN subjects s ON s.id = e.subject_id
                 WHERE e.teacher_id = ? AND e.academic_year_id = ?
                 ORDER BY e.day_of_week, p.start_time"
            );
            if ($stmt) {
                $stmt->bind_param('ii', $teacherId, $yearId);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) {
                    $lessons[] = [
                        'id'              => (int)$row['id'],
                        'class_id'        => (int)$row['class_id'],
                        'class_name'      => $row['class_name'] ?? '',
                        'class_name_en'   => $row['class_name_en'] ?? '',
                        'period_id'       => (int)$row['period_id'],
                        'period_label'    => $row['period_label'] ?? '',
                        'start_time'      => substr((string)$row['start_time'], 0, 5),
                        'end_time'        => substr((string)$row['end_time'], 0, 5),
                        'day_of_week'     => (int)$row['day_of_week'],
                        'subject_id'      => $row['subject_id'] ? (int)$row['subject_id'] : null,
                        'subject_name'    => $row['subject_name'] ?? '',
                        'subject_name_en' => $row['subject_name_en'] ?? '',
                        'room'            => $row['room'] ?? '',
                    ];
                }
                $stmt->close();
            }
        }
        // "Today" is Ethiopia's calendar day, not the DB server's CURDATE()
        // (shared hosting may run in another time zone) — the same date is
        // returned as today_date so the makeup filter and the label agree.
        $today = new \DateTime('now', new \DateTimeZone('Africa/Addis_Ababa'));
        $todayYmd = $today->format('Y-m-d');
        $makeups = [];
        if ($teacherId > 0 && $yearId > 0) {
            $stmt = $conn->prepare(
                "SELECT m.*, s.subject_name, s.subject_name_en, u.full_name AS teacher_name, c.class_name, c.class_name_en
                 FROM timetable_makeup_classes m
                 LEFT JOIN subjects s ON s.id = m.subject_id
                 LEFT JOIN users u ON u.id = m.teacher_id
                 JOIN classes c ON c.id = m.class_id
                 WHERE m.teacher_id = ? AND m.academic_year_id = ? AND m.lesson_date >= ?
                 ORDER BY m.lesson_date, m.start_time LIMIT 50"
            );
            if ($stmt) {
                $stmt->bind_param('iis', $teacherId, $yearId, $todayYmd);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) {
                    $makeups[] = self::formatMakeup($row);
                }
                $stmt->close();
            }
        }
        return [
            'status'      => 'success',
            'teacher_id'  => $teacherId,
            'today_iso'   => (int)$today->format('N'),
            'today_date'  => $todayYmd,
            'lessons'     => $lessons,
            'makeups'     => $makeups,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Writes
    // ─────────────────────────────────────────────────────────────────────

    /** @param int[] $days */
    public static function saveWorkingDays(\mysqli $conn, int $classId, int $yearId, array $days, array $actor): array
    {
        if (!self::schemaReady($conn)) {
            return self::schemaNotInstalled();
        }
        if ($classId <= 0 || $yearId <= 0) {
            return ['status' => 'error', 'message' => 'Class and academic year are required.'];
        }
        $days = array_values(array_unique(array_filter(array_map('intval', $days), static fn($d) => $d >= 1 && $d <= 7)));
        sort($days);
        $before = self::workingDays($conn, $classId, $yearId);
        $removed = array_values(array_diff($before, $days));
        $cleared = 0;
        if ($removed) {
            $in = implode(',', array_map('intval', $removed));
            $cnt = $conn->prepare(
                "SELECT COUNT(*) AS c FROM timetable_entries WHERE class_id = ? AND academic_year_id = ? AND day_of_week IN ($in)"
            );
            if ($cnt) {
                $cnt->bind_param('ii', $classId, $yearId);
                $cnt->execute();
                $cleared = (int)($cnt->get_result()->fetch_assoc()['c'] ?? 0);
                $cnt->close();
            }
            $del = $conn->prepare(
                "DELETE FROM timetable_entries WHERE class_id = ? AND academic_year_id = ? AND day_of_week IN ($in)"
            );
            if ($del) {
                $del->bind_param('ii', $classId, $yearId);
                $del->execute();
                $del->close();
            }
        }
        $csv = implode(',', $days);
        $uid = (int)($actor['id'] ?? 0);
        $stmt = $conn->prepare(
            "INSERT INTO timetable_class_days (class_id, academic_year_id, working_days, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE working_days = VALUES(working_days), updated_by = VALUES(updated_by)"
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not save working days.'];
        }
        $stmt->bind_param('iisi', $classId, $yearId, $csv, $uid);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Could not save working days.'];
        }
        self::log($conn, $actor, 'Timetable Working Days Saved', [
            'class_id' => $classId, 'year_id' => $yearId, 'days' => $csv, 'cleared' => $cleared,
        ], $classId);
        return [
            'status'       => 'success',
            'message'      => $cleared > 0 ? "Working days saved. {$cleared} lesson(s) on removed days were cleared." : 'Working days saved.',
            'working_days' => $days,
            'cleared'      => $cleared,
        ];
    }

    public static function savePeriod(\mysqli $conn, array $in, int $yearId, array $actor): array
    {
        if (!self::schemaReady($conn)) {
            return self::schemaNotInstalled();
        }
        $id = (int)($in['id'] ?? 0);
        $classId = (int)($in['class_id'] ?? 0);
        $label = trim((string)($in['label'] ?? ''));
        $start = self::normTime((string)($in['start_time'] ?? ''));
        $end = self::normTime((string)($in['end_time'] ?? ''));
        $isBreak = !empty($in['is_break']) && (string)$in['is_break'] !== '0' ? 1 : 0;
        if ($classId <= 0 || $yearId <= 0) {
            return ['status' => 'error', 'message' => 'Class and academic year are required.'];
        }
        if ($label === '' || !$start || !$end) {
            return ['status' => 'error', 'message' => 'Label, start time and end time are required.'];
        }
        if (mb_strlen($label) > 50) {
            return ['status' => 'error', 'message' => 'Label must be 50 characters or fewer.'];
        }
        if ($end <= $start) {
            return ['status' => 'error', 'message' => 'End time must be after start time.'];
        }
        if ($id > 0 && !self::periodBelongs($conn, $id, $classId, $yearId)) {
            return ['status' => 'error', 'message' => 'Period not found for this class.'];
        }
        $clash = self::overlappingPeriod($conn, $classId, $yearId, $start, $end, $id ?: null);
        if ($clash) {
            return [
                'status'  => 'error',
                'message' => 'Overlaps with ' . $clash['label'] . ' ('
                    . substr((string)$clash['start_time'], 0, 5) . '–'
                    . substr((string)$clash['end_time'], 0, 5) . '). Periods may touch but not overlap.',
            ];
        }
        if ($id > 0) {
            $stmt = $conn->prepare(
                "UPDATE timetable_periods SET label = ?, start_time = ?, end_time = ?, is_break = ? WHERE id = ?"
            );
            if (!$stmt) {
                return ['status' => 'error', 'message' => 'Could not update period.'];
            }
            $stmt->bind_param('sssii', $label, $start, $end, $isBreak, $id);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) {
                return ['status' => 'error', 'message' => 'Could not update period.'];
            }
            $cleared = 0;
            if ($isBreak) {
                $cleared = self::deleteEntriesForPeriod($conn, $id);
            }
            self::log($conn, $actor, 'Timetable Period Updated', [
                'class_id' => $classId, 'year_id' => $yearId, 'period_id' => $id, 'label' => $label,
                'start' => $start, 'end' => $end, 'is_break' => $isBreak, 'cleared' => $cleared,
            ], $classId);
            return ['status' => 'success', 'message' => 'Period updated.', 'period_id' => $id];
        }
        $sort = 1;
        $mx = $conn->query("SELECT COALESCE(MAX(sort_order), 0) AS m FROM timetable_periods");
        if ($mx) {
            $sort = (int)$mx->fetch_assoc()['m'] + 1;
        }
        $stmt = $conn->prepare(
            "INSERT INTO timetable_periods (class_id, academic_year_id, label, start_time, end_time, is_break, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not add period.'];
        }
        $stmt->bind_param('iisssii', $classId, $yearId, $label, $start, $end, $isBreak, $sort);
        $ok = $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Could not add period.'];
        }
        self::log($conn, $actor, 'Timetable Period Added', [
            'class_id' => $classId, 'year_id' => $yearId, 'period_id' => $newId, 'label' => $label,
            'start' => $start, 'end' => $end, 'is_break' => $isBreak,
        ], $classId);
        return ['status' => 'success', 'message' => 'Period added.', 'period_id' => $newId];
    }

    public static function deletePeriod(\mysqli $conn, int $id, int $classId, int $yearId, array $actor): array
    {
        if (!self::schemaReady($conn)) {
            return self::schemaNotInstalled();
        }
        if ($id <= 0 || !self::periodBelongs($conn, $id, $classId, $yearId)) {
            return ['status' => 'error', 'message' => 'Period not found for this class.'];
        }
        $cnt = self::deleteEntriesForPeriod($conn, $id);
        $del = $conn->prepare("DELETE FROM timetable_periods WHERE id = ?");
        if (!$del) {
            return ['status' => 'error', 'message' => 'Could not remove period.'];
        }
        $del->bind_param('i', $id);
        $ok = $del->execute();
        $del->close();
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Could not remove period.'];
        }
        self::log($conn, $actor, 'Timetable Period Deleted', [
            'class_id' => $classId, 'year_id' => $yearId, 'period_id' => $id, 'cleared' => $cnt,
        ], $classId);
        return [
            'status'  => 'success',
            'message' => $cnt > 0 ? "Period removed along with {$cnt} scheduled lesson(s)." : 'Period removed.',
        ];
    }

    /** Copy working days + bell schedule from another class (target lessons are cleared). */
    public static function copyBell(\mysqli $conn, int $fromClass, int $toClass, int $yearId, array $actor): array
    {
        if (!self::schemaReady($conn)) {
            return self::schemaNotInstalled();
        }
        if ($fromClass <= 0 || $toClass <= 0 || $yearId <= 0 || $fromClass === $toClass) {
            return ['status' => 'error', 'message' => 'Choose a different class to copy from.'];
        }
        $src = self::periodsFor($conn, $fromClass, $yearId);
        if (!$src) {
            return ['status' => 'error', 'message' => 'That class has no bell schedule yet.'];
        }
        $days = self::workingDays($conn, $fromClass, $yearId);
        $conn->begin_transaction();
        try {
            $delE = $conn->prepare("DELETE FROM timetable_entries WHERE class_id = ? AND academic_year_id = ?");
            $delE->bind_param('ii', $toClass, $yearId);
            $delE->execute();
            $cleared = $delE->affected_rows;
            $delE->close();
            $delP = $conn->prepare("DELETE FROM timetable_periods WHERE class_id = ? AND academic_year_id = ?");
            $delP->bind_param('ii', $toClass, $yearId);
            $delP->execute();
            $delP->close();
            $sort = 0;
            $mx = $conn->query("SELECT COALESCE(MAX(sort_order), 0) AS m FROM timetable_periods");
            if ($mx) {
                $sort = (int)$mx->fetch_assoc()['m'];
            }
            $ins = $conn->prepare(
                "INSERT INTO timetable_periods (class_id, academic_year_id, label, start_time, end_time, is_break, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($src as $p) {
                $sort++;
                $lbl = $p['label'];
                $st = $p['start_time'] . ':00';
                $en = $p['end_time'] . ':00';
                $br = $p['is_break'] ? 1 : 0;
                $ins->bind_param('iisssii', $toClass, $yearId, $lbl, $st, $en, $br, $sort);
                $ins->execute();
            }
            $ins->close();
            $csv = implode(',', $days);
            $uid = (int)($actor['id'] ?? 0);
            $wd = $conn->prepare(
                "INSERT INTO timetable_class_days (class_id, academic_year_id, working_days, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE working_days = VALUES(working_days), updated_by = VALUES(updated_by)"
            );
            $wd->bind_param('iisi', $toClass, $yearId, $csv, $uid);
            $wd->execute();
            $wd->close();
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            error_log('TimetableService::copyBell: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Could not copy the bell schedule.'];
        }
        self::log($conn, $actor, 'Timetable Bell Schedule Copied', [
            'from_class_id' => $fromClass, 'class_id' => $toClass, 'year_id' => $yearId,
            'periods' => count($src), 'days' => $csv, 'cleared' => $cleared,
        ], $toClass);
        return ['status' => 'success', 'message' => 'Bell schedule and working days copied.'];
    }

    public static function saveEntry(\mysqli $conn, array $in, int $yearId, array $actor): array
    {
        if (!self::schemaReady($conn)) {
            return self::schemaNotInstalled();
        }
        $classId = (int)($in['class_id'] ?? 0);
        $periodId = (int)($in['period_id'] ?? 0);
        $day = (int)($in['day_of_week'] ?? 0);
        $subjectId = (int)($in['subject_id'] ?? 0);
        $teacherId = (int)($in['teacher_id'] ?? 0);
        $room = trim((string)($in['room'] ?? ''));
        $room = $room !== '' ? mb_substr($room, 0, 50) : null;
        if ($classId <= 0 || $periodId <= 0 || $day < 1 || $day > 7 || $yearId <= 0) {
            return ['status' => 'error', 'message' => 'Class, period and weekday are required.'];
        }
        if ($subjectId <= 0 || $teacherId <= 0) {
            return ['status' => 'error', 'message' => 'Subject and teacher are required.'];
        }
        $period = self::periodRow($conn, $periodId);
        if (!$period || (int)$period['class_id'] !== $classId || (int)$period['academic_year_id'] !== $yearId) {
            return ['status' => 'error', 'message' => 'Period not found for this class.'];
        }
        if ((int)$period['is_break'] === 1) {
            return ['status' => 'error', 'message' => 'Lessons cannot be placed on a break.'];
        }
        if (!in_array($day, self::workingDays($conn, $classId, $yearId), true)) {
            return ['status' => 'error', 'message' => 'That weekday is not a working day of this class.'];
        }
        if (!self::rowExists($conn, "SELECT id FROM subjects WHERE id = ? AND is_active = 1", $subjectId)) {
            return ['status' => 'error', 'message' => 'Subject not found.'];
        }
        if (!self::rowExists($conn, "SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1", $teacherId)) {
            return ['status' => 'error', 'message' => 'Teacher not found.'];
        }
        $clash = self::teacherClash($conn, $teacherId, $yearId, $day, (string)$period['start_time'], (string)$period['end_time'], $classId);
        if ($clash) {
            return [
                'status'  => 'error',
                'message' => ($clash['teacher_name'] ?: 'That teacher') . ' already teaches '
                    . ($clash['class_name_en'] ?: $clash['class_name'] ?: 'another class') . ' on '
                    . self::dayName($day) . ' ' . substr((string)$clash['start_time'], 0, 5) . '–'
                    . substr((string)$clash['end_time'], 0, 5) . '. A teacher cannot be in two classes at the same time.',
            ];
        }
        $assigned = self::teacherAssignedToClass($conn, $teacherId, $classId, $yearId);

        $eid = 0;
        $existing = $conn->prepare(
            "SELECT id FROM timetable_entries WHERE class_id = ? AND period_id = ? AND day_of_week = ? LIMIT 1"
        );
        if ($existing) {
            $existing->bind_param('iii', $classId, $periodId, $day);
            $existing->execute();
            $row = $existing->get_result()->fetch_assoc();
            $existing->close();
            $eid = $row ? (int)$row['id'] : 0;
        }
        if ($eid) {
            $stmt = $conn->prepare(
                "UPDATE timetable_entries SET subject_id = ?, teacher_id = ?, room = ?, academic_year_id = ? WHERE id = ?"
            );
            if (!$stmt) {
                return ['status' => 'error', 'message' => 'Could not save lesson.'];
            }
            $stmt->bind_param('iisii', $subjectId, $teacherId, $room, $yearId, $eid);
            $ok = $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO timetable_entries (class_id, academic_year_id, period_id, day_of_week, subject_id, teacher_id, room)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                return ['status' => 'error', 'message' => 'Could not save lesson.'];
            }
            $stmt->bind_param('iiiiiis', $classId, $yearId, $periodId, $day, $subjectId, $teacherId, $room);
            $ok = $stmt->execute();
            $eid = (int)$stmt->insert_id;
            $stmt->close();
        }
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Could not save lesson.'];
        }
        self::log($conn, $actor, 'Timetable Lesson Saved', [
            'class_id' => $classId, 'year_id' => $yearId, 'period_id' => $periodId, 'day' => $day,
            'subject_id' => $subjectId, 'teacher_id' => $teacherId, 'room' => $room,
            'teacher_assigned_to_class' => $assigned ? 1 : 0,
        ], $classId);
        return [
            'status'   => 'success',
            'message'  => $assigned ? 'Lesson saved.' : 'Lesson saved. Note: this teacher is not assigned to this class.',
            'entry_id' => $eid,
            'warning'  => $assigned ? null : 'teacher_not_assigned',
        ];
    }

    public static function clearEntry(\mysqli $conn, int $classId, int $periodId, int $day, int $yearId, array $actor): array
    {
        if (!self::schemaReady($conn)) {
            return self::schemaNotInstalled();
        }
        if ($classId <= 0 || $periodId <= 0 || $day < 1 || $day > 7) {
            return ['status' => 'error', 'message' => 'Class, period and weekday are required.'];
        }
        $stmt = $conn->prepare(
            "DELETE FROM timetable_entries WHERE class_id = ? AND period_id = ? AND day_of_week = ? AND academic_year_id = ?"
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not clear lesson.'];
        }
        $stmt->bind_param('iiii', $classId, $periodId, $day, $yearId);
        $ok = $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Could not clear lesson.'];
        }
        if ($n > 0) {
            self::log($conn, $actor, 'Timetable Lesson Cleared', [
                'class_id' => $classId, 'year_id' => $yearId, 'period_id' => $periodId, 'day' => $day,
            ], $classId);
        }
        return ['status' => 'success', 'message' => 'Lesson cleared.'];
    }

    public static function saveMakeup(\mysqli $conn, array $in, int $yearId, array $actor): array
    {
        if (!self::schemaReady($conn)) {
            return self::schemaNotInstalled();
        }
        $classId = (int)($in['class_id'] ?? 0);
        $date = trim((string)($in['lesson_date'] ?? ''));
        $start = self::normTime((string)($in['start_time'] ?? ''));
        $end = self::normTime((string)($in['end_time'] ?? ''));
        $subjectId = (int)($in['subject_id'] ?? 0);
        $teacherId = (int)($in['teacher_id'] ?? 0);
        $room = trim((string)($in['room'] ?? ''));
        $room = $room !== '' ? mb_substr($room, 0, 50) : null;
        $reason = trim((string)($in['reason'] ?? ''));
        $reason = $reason !== '' ? mb_substr($reason, 0, 255) : null;
        if ($classId <= 0 || $yearId <= 0) {
            return ['status' => 'error', 'message' => 'Class and academic year are required.'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
            return ['status' => 'error', 'message' => 'A valid date is required.'];
        }
        $today = (new \DateTime('now', new \DateTimeZone('Africa/Addis_Ababa')))->format('Y-m-d');
        if ($date < $today) {
            return ['status' => 'error', 'message' => 'Makeup classes must be today or later.'];
        }
        if (!$start || !$end) {
            return ['status' => 'error', 'message' => 'Start and end time are required.'];
        }
        if ($end <= $start) {
            return ['status' => 'error', 'message' => 'End time must be after start time.'];
        }
        if ($subjectId <= 0 || $teacherId <= 0) {
            return ['status' => 'error', 'message' => 'Subject and teacher are required.'];
        }
        if (!self::rowExists($conn, "SELECT id FROM subjects WHERE id = ? AND is_active = 1", $subjectId)) {
            return ['status' => 'error', 'message' => 'Subject not found.'];
        }
        if (!self::rowExists($conn, "SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1", $teacherId)) {
            return ['status' => 'error', 'message' => 'Teacher not found.'];
        }
        $day = (int)(new \DateTime($date))->format('N');
        $clash = self::teacherClash($conn, $teacherId, $yearId, $day, $start, $end, 0);
        if ($clash) {
            return [
                'status'  => 'error',
                'message' => ($clash['teacher_name'] ?: 'That teacher') . ' teaches '
                    . ($clash['class_name_en'] ?: $clash['class_name'] ?: 'another class') . ' every '
                    . self::dayName($day) . ' ' . substr((string)$clash['start_time'], 0, 5) . '–'
                    . substr((string)$clash['end_time'], 0, 5) . '.',
            ];
        }
        $mk = $conn->prepare(
            "SELECT m.start_time, m.end_time, c.class_name, c.class_name_en
             FROM timetable_makeup_classes m JOIN classes c ON c.id = m.class_id
             WHERE m.teacher_id = ? AND m.lesson_date = ? AND m.start_time < ? AND m.end_time > ? LIMIT 1"
        );
        if ($mk) {
            $mk->bind_param('isss', $teacherId, $date, $end, $start);
            $mk->execute();
            $row = $mk->get_result()->fetch_assoc();
            $mk->close();
            if ($row) {
                return [
                    'status'  => 'error',
                    'message' => 'That teacher already has a makeup class for '
                        . ($row['class_name_en'] ?: $row['class_name']) . ' that day ('
                        . substr((string)$row['start_time'], 0, 5) . '–' . substr((string)$row['end_time'], 0, 5) . ').',
                ];
            }
        }
        $uid = (int)($actor['id'] ?? 0);
        $stmt = $conn->prepare(
            "INSERT INTO timetable_makeup_classes
                (class_id, academic_year_id, lesson_date, start_time, end_time, subject_id, teacher_id, room, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not save makeup class.'];
        }
        $stmt->bind_param('iisssiissi', $classId, $yearId, $date, $start, $end, $subjectId, $teacherId, $room, $reason, $uid);
        $ok = $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Could not save makeup class.'];
        }
        self::log($conn, $actor, 'Timetable Makeup Class Added', [
            'class_id' => $classId, 'year_id' => $yearId, 'makeup_id' => $newId, 'date' => $date,
            'start' => $start, 'end' => $end, 'subject_id' => $subjectId, 'teacher_id' => $teacherId,
        ], $classId);
        return ['status' => 'success', 'message' => 'Makeup class added.', 'makeup_id' => $newId];
    }

    public static function deleteMakeup(\mysqli $conn, int $id, int $yearId, array $actor): array
    {
        if (!self::schemaReady($conn)) {
            return self::schemaNotInstalled();
        }
        if ($id <= 0) {
            return ['status' => 'error', 'message' => 'Makeup class is required.'];
        }
        $row = null;
        $sel = $conn->prepare("SELECT id, class_id FROM timetable_makeup_classes WHERE id = ? AND academic_year_id = ? LIMIT 1");
        if ($sel) {
            $sel->bind_param('ii', $id, $yearId);
            $sel->execute();
            $row = $sel->get_result()->fetch_assoc();
            $sel->close();
        }
        if (!$row) {
            return ['status' => 'error', 'message' => 'Makeup class not found.'];
        }
        $del = $conn->prepare("DELETE FROM timetable_makeup_classes WHERE id = ?");
        if (!$del) {
            return ['status' => 'error', 'message' => 'Could not remove makeup class.'];
        }
        $del->bind_param('i', $id);
        $ok = $del->execute();
        $del->close();
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Could not remove makeup class.'];
        }
        self::log($conn, $actor, 'Timetable Makeup Class Removed', [
            'class_id' => (int)$row['class_id'], 'year_id' => $yearId, 'makeup_id' => $id,
        ], (int)$row['class_id']);
        return ['status' => 'success', 'message' => 'Makeup class removed.'];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Another class's lesson of the same teacher on the same weekday whose
     * period overlaps [$start,$end). Returns row or null.
     */
    private static function teacherClash(\mysqli $conn, int $teacherId, int $yearId, int $day, string $start, string $end, int $excludeClass): ?array
    {
        $stmt = $conn->prepare(
            "SELECT e.class_id, c.class_name, c.class_name_en, p.start_time, p.end_time, u.full_name AS teacher_name
             FROM timetable_entries e
             JOIN timetable_periods p ON p.id = e.period_id
             JOIN classes c ON c.id = e.class_id
             LEFT JOIN users u ON u.id = e.teacher_id
             WHERE e.teacher_id = ? AND e.academic_year_id = ? AND e.day_of_week = ? AND e.class_id <> ?
               AND p.start_time < ? AND p.end_time > ?
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('iiiiss', $teacherId, $yearId, $day, $excludeClass, $end, $start);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function overlappingPeriod(\mysqli $conn, int $classId, int $yearId, string $start, string $end, ?int $ignoreId): ?array
    {
        $sql = "SELECT id, label, start_time, end_time FROM timetable_periods
                WHERE class_id = ? AND academic_year_id = ? AND start_time < ? AND end_time > ?";
        if ($ignoreId) {
            $sql .= " AND id != ?";
        }
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        if ($ignoreId) {
            $stmt->bind_param('iissi', $classId, $yearId, $end, $start, $ignoreId);
        } else {
            $stmt->bind_param('iiss', $classId, $yearId, $end, $start);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function periodRow(\mysqli $conn, int $id): ?array
    {
        $stmt = $conn->prepare("SELECT id, class_id, academic_year_id, start_time, end_time, is_break FROM timetable_periods WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function periodBelongs(\mysqli $conn, int $id, int $classId, int $yearId): bool
    {
        $p = self::periodRow($conn, $id);
        return $p && (int)$p['class_id'] === $classId && (int)$p['academic_year_id'] === $yearId;
    }

    private static function deleteEntriesForPeriod(\mysqli $conn, int $periodId): int
    {
        $del = $conn->prepare("DELETE FROM timetable_entries WHERE period_id = ?");
        if (!$del) {
            return 0;
        }
        $del->bind_param('i', $periodId);
        $del->execute();
        $n = $del->affected_rows;
        $del->close();
        return max(0, (int)$n);
    }

    private static function teacherAssignedToClass(\mysqli $conn, int $teacherId, int $classId, int $yearId): bool
    {
        $stmt = $conn->prepare(
            "SELECT id FROM teacher_assignments
             WHERE teacher_id = ? AND class_id = ? AND is_active = 1 AND status = 'active'
               AND (academic_year_id = ? OR academic_year_id IS NULL) LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iii', $teacherId, $classId, $yearId);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $ok;
    }

    private static function rowExists(\mysqli $conn, string $sql, int $id): bool
    {
        $stmt = $conn->prepare($sql . ' LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $ok;
    }

    private static function log(\mysqli $conn, array $actor, string $action, array $details, int $entityId): void
    {
        $uid = (int)($actor['id'] ?? 0);
        $uname = (string)($actor['username'] ?? '');
        $ip = (string)($actor['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
        $json = json_encode($details, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare(
            "INSERT INTO activity_logs (user_id, username, action, details, entity_type, entity_id, ip_address)
             VALUES (?, ?, ?, ?, 'timetable', ?, ?)"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isssis', $uid, $uname, $action, $json, $entityId, $ip);
        $stmt->execute();
        $stmt->close();
    }

    /** @return int[] */
    public static function parseDays(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $d) {
            $d = (int)trim($d);
            if ($d >= 1 && $d <= 7 && !in_array($d, $out, true)) {
                $out[] = $d;
            }
        }
        sort($out);
        return $out;
    }

    public static function dayName(int $d): string
    {
        return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'][$d] ?? '';
    }

    private static function formatPeriod(array $row): array
    {
        return [
            'id'         => (int)$row['id'],
            'label'      => $row['label'] ?? '',
            'start_time' => substr((string)$row['start_time'], 0, 5),
            'end_time'   => substr((string)$row['end_time'], 0, 5),
            'is_break'   => (int)($row['is_break'] ?? 0) === 1,
            'sort_order' => (int)($row['sort_order'] ?? 0),
        ];
    }

    private static function formatEntry(array $row): array
    {
        return [
            'id'              => (int)$row['id'],
            'class_id'        => (int)$row['class_id'],
            'period_id'       => (int)$row['period_id'],
            'day_of_week'     => (int)$row['day_of_week'],
            'subject_id'      => $row['subject_id'] ? (int)$row['subject_id'] : null,
            'teacher_id'      => $row['teacher_id'] ? (int)$row['teacher_id'] : null,
            'room'            => $row['room'] ?? '',
            'subject_name'    => $row['subject_name'] ?? '',
            'subject_name_en' => $row['subject_name_en'] ?? '',
            'teacher_name'    => $row['teacher_name'] ?? '',
        ];
    }

    private static function formatMakeup(array $row): array
    {
        return [
            'id'              => (int)$row['id'],
            'class_id'        => (int)$row['class_id'],
            'class_name'      => $row['class_name'] ?? '',
            'class_name_en'   => $row['class_name_en'] ?? '',
            'lesson_date'     => (string)$row['lesson_date'],
            'day_of_week'     => (int)(new \DateTime((string)$row['lesson_date']))->format('N'),
            'start_time'      => substr((string)$row['start_time'], 0, 5),
            'end_time'        => substr((string)$row['end_time'], 0, 5),
            'subject_id'      => $row['subject_id'] ? (int)$row['subject_id'] : null,
            'subject_name'    => $row['subject_name'] ?? '',
            'subject_name_en' => $row['subject_name_en'] ?? '',
            'teacher_id'      => $row['teacher_id'] ? (int)$row['teacher_id'] : null,
            'teacher_name'    => $row['teacher_name'] ?? '',
            'room'            => $row['room'] ?? '',
            'reason'          => $row['reason'] ?? '',
        ];
    }

    private static function normTime(string $t): ?string
    {
        $t = trim($t);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d:00', $h, $min);
            }
        }
        return null;
    }
}
