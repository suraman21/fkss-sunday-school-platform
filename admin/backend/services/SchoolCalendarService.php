<?php
/**
 * School calendar — class-day-only attendance rules.
 * Owner decisions 2026-10-20 (ATTENDANCE_CLASS_DAY_ENFORCEMENT_PLAN_2026-10-20.md):
 *   • attendance only on class days taken from the timetable;
 *   • classes whose working days are not configured are BLOCKED (no fallback);
 *   • backdating window: 45 days (BACKDATE_DAYS);
 *   • "Day Off" days (school_day_offs, latest row wins) can also cancel makeup
 *     classes — the department chooses per entry (cancel_makeup);
 *   • a Day Off cannot be marked once attendance exists for that date;
 *   • Day Off / reopen only while the date has not passed (Addis time);
 *   • Education dept only writes; everyone else reads statuses for the UI.
 *
 * Deployment-safe: until sql/078 exists the service returns "ok" exactly like
 * the system behaved before (the rule activates with the migration).
 */

namespace App\Services;

require_once __DIR__ . '/SecurityAuditService.php';

class SchoolCalendarService
{
    public const BACKDATE_DAYS = 45;

    public const HOLIDAYS = 'school_holidays';
    public const DAY_OFFS = 'school_day_offs';

    /** Writer roles for holidays + Day Offs (owner decision 5). */
    public const WRITE_ROLES = ['edu_dept'];
    /** Roles that see date statuses in attendance UIs. */
    public const READ_ROLES = ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'attendance_taker', 'hr_dept', 'info_dept'];

    private static ?bool $tablesReady = null;
    private static ?bool $timetableTablesReady = null;
    /** @var array{from:string,to:string,off:array<string,array>,hol:array<int,array>,mk:array<string,int>,mkClass:array<string,bool>}|null */
    private static ?array $range = null;
    /** @var array<string,(int[]|null)> memo of workingDays per "class:year" */
    private static array $wdMemo = [];

    // ─────────────────────────────────────────────────────────────────────
    // Read model
    // ─────────────────────────────────────────────────────────────────────

    public static function resetCache(): void
    {
        self::$tablesReady = null;
        self::$timetableTablesReady = null;
        self::$range = null;
        self::$wdMemo = [];
        self::$yearMemo = null;
        self::$yearMemoSet = false;
    }

    /** True when the per-request range cache already answers for this date. */
    private static function covers(string $date): bool
    {
        return self::$range !== null
            && $date >= self::$range['from'] && $date <= self::$range['to'];
    }

    /**
     * Load every day-off / holiday / makeup fact for a date window ONCE
     * (F-P1). Per-day lookups inside the window then answer from memory —
     * the Year Calendar page drops from ~736 SQL queries to 4.
     */
    private static function primeRange(\mysqli $conn, string $from, string $to): void
    {
        if (self::$range !== null && self::$range['from'] <= $from && self::$range['to'] >= $to) {
            return;
        }
        $off = []; $hol = []; $mk = []; $mkClass = [];
        if (self::tablesReady($conn)) {
            foreach (self::dayOffs($conn, $from, $to) as $row) {
                // dayOffs() already resolves "latest row per date wins" and
                // keeps only the effective Day Offs (reopened days drop out —
                // exactly what dayOffStatus() reports per date).
                $off[$row['date']] = [
                    'is_off'      => true,
                    'cancel_makeup' => $row['cancel_makeup'],
                    'reason'      => $row['reason'],
                    'can_change'  => $row['can_change'],
                ];
            }
            foreach (self::holidays($conn, $from, $to) as $row) {
                $hol[] = $row; // matched per date; highest id wins (holidayOn rule)
            }
        }
        if (self::timetableReady($conn)) {
            try {
                $stmt = $conn->prepare(
                    'SELECT class_id, lesson_date FROM timetable_makeup_classes
                      WHERE lesson_date >= ? AND lesson_date <= ?'
                );
                if ($stmt) {
                    $stmt->bind_param('ss', $from, $to);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $d = substr((string)$row['lesson_date'], 0, 10);
                        $mk[$d] = ($mk[$d] ?? 0) + 1;
                        $mkClass[(int)$row['class_id'] . ':' . $d] = true;
                    }
                    $stmt->close();
                }
            } catch (\Throwable $e) {
                // makeups are an enhancement — never break the calendar
            }
        }
        self::$range = ['from' => $from, 'to' => $to, 'off' => $off, 'hol' => $hol, 'mk' => $mk, 'mkClass' => $mkClass];
    }

    private static function invalidateRange(): void
    {
        self::$range = null;
        self::$wdMemo = [];
    }

    public static function tablesReady(\mysqli $conn): bool
    {
        if (self::$tablesReady !== null) {
            return self::$tablesReady;
        }
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('" . self::HOLIDAYS . "','" . self::DAY_OFFS . "')"
        );
        if (!$stmt) {
            return self::$tablesReady = false;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return self::$tablesReady = $row !== null && (int)$row[0] === 2;
    }

    /** True when the timetable module's class-days table exists. */
    private static function timetableReady(\mysqli $conn): bool
    {
        if (self::$timetableTablesReady !== null) {
            return self::$timetableTablesReady;
        }
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timetable_class_days'"
        );
        if (!$stmt) {
            return self::$timetableTablesReady = false;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return self::$timetableTablesReady = $row !== null && (int)$row[0] === 1;
    }

    /**
     * The one rule. Returns:
     *  {ok, code: OK|FUTURE_DATE|BACKDATE_LIMIT|NOT_CONFIGURED|NOT_CLASS_DAY|
     *   OUT_OF_YEAR|HOLIDAY|DAY_OFF, date, label (EC), detail, is_makeup}
     */
    /**
     * Save verdict for a (class, date): the day-type rule PLUS the date
     * window (no future dates, 45-day backdating). Used by the save paths
     * and the attendance UIs' date_status.
     */
    public static function classDayStatus(\mysqli $conn, int $classId, string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['date' => $date, 'label' => $date, 'is_makeup' => false,
                'ok' => false, 'code' => 'INVALID_DATE', 'detail' => 'A valid date is required.'];
        }
        $today = self::today();
        if ($date > $today) {
            return ['date' => $date, 'label' => self::ecLabel($date), 'is_makeup' => false,
                'ok' => false, 'code' => 'FUTURE_DATE', 'detail' => 'Attendance opens on the class day.'];
        }
        // The 45-day backdating window belongs to this feature: it activates
        // with sql/078 like everything else, so a code pull that has not run
        // the migration yet behaves exactly as before (audit F-P2).
        if (self::tablesReady($conn)) {
            $minDate = date('Y-m-d', strtotime($today . ' -' . self::BACKDATE_DAYS . ' days'));
            if ($date < $minDate) {
                return ['date' => $date, 'label' => self::ecLabel($date), 'is_makeup' => false,
                    'ok' => false, 'code' => 'BACKDATE_LIMIT',
                    'detail' => 'Attendance can be recorded up to ' . self::BACKDATE_DAYS . ' days back.'];
            }
        }
        return self::dayType($conn, $classId, $date);
    }

    /**
     * Day-type verdict WITHOUT the date window: what kind of day is this?
     * {ok, code: OK|DAY_OFF|HOLIDAY|NOT_CLASS_DAY|NOT_CONFIGURED|OUT_OF_YEAR,
     *  detail, is_makeup}. The Year Calendar grid uses this so a future day
     * still shows its Day Off / holiday status.
     */
    public static function dayType(\mysqli $conn, int $classId, string $date): array
    {
        $base = [
            'date'      => $date,
            'label'     => self::ecLabel($date),
            'detail'    => '',
            'is_makeup' => false,
        ];
        if (!self::tablesReady($conn)) {
            // sql/078 not applied yet — behaviour identical to before.
            return array_merge($base, ['ok' => true, 'code' => 'OK']);
        }

        $hasMakeup = self::hasMakeup($conn, $classId, $date);

        // "Day Off" layer (latest row per date wins).
        $off = self::dayOffStatus($conn, $date);
        if ($off['is_off']) {
            if ($hasMakeup && !$off['cancel_makeup']) {
                return array_merge($base, ['ok' => true, 'code' => 'OK', 'is_makeup' => true,
                    'detail' => 'Makeup class day (the Day Off does not cancel makeups).']);
            }
            return array_merge($base, ['ok' => false, 'code' => 'DAY_OFF',
                'detail' => 'Day Off: ' . ($off['reason'] !== '' ? $off['reason'] : 'classes are off for the whole school')]);
        }

        // Planned holiday layer (makeup classes punch through — plan §1.7).
        $holiday = self::holidayOn($conn, $date);
        if ($holiday !== null) {
            if ($hasMakeup) {
                return array_merge($base, ['ok' => true, 'code' => 'OK', 'is_makeup' => true, 'detail' => 'Makeup class day.']);
            }
            return array_merge($base, ['ok' => false, 'code' => 'HOLIDAY', 'detail' => 'Holiday: ' . $holiday]);
        }

        // Timetable class-day layer (strict — owner decision 1).
        if (self::timetableReady($conn)) {
            $days = self::workingDays($conn, $classId);
            if ($days === null) {
                return array_merge($base, ['ok' => false, 'code' => 'NOT_CONFIGURED',
                    'detail' => 'Education has not set this class’s working days yet.']);
            }
            $dow = (int)(new \DateTime($date))->format('N');
            if (!in_array($dow, $days, true)) {
                if ($hasMakeup) {
                    return array_merge($base, ['ok' => true, 'code' => 'OK', 'is_makeup' => true, 'detail' => 'Makeup class day.']);
                }
                return array_merge($base, ['ok' => false, 'code' => 'NOT_CLASS_DAY',
                    'detail' => 'This class does not meet on ' . self::dayName($dow) . 's.']);
            }
        }
        return array_merge($base, ['ok' => true, 'code' => 'OK']);
    }

    /**
     * Status map for one EC month: [Y-m-d => status] — fast path for grids.
     * Unknown/absent keys mean "outside the month".
     */
    public static function classDaysForMonth(\mysqli $conn, int $classId, string $fromYmd, string $toYmd): array
    {
        self::primeRange($conn, $fromYmd, $toYmd);
        $out = [];
        $d = new \DateTime($fromYmd);
        $end = new \DateTime($toYmd);
        while ($d <= $end) {
            $ymd = $d->format('Y-m-d');
            $out[$ymd] = self::dayType($conn, $classId, $ymd);
            $d->modify('+1 day');
        }
        return $out;
    }

    /** Latest Day Off row for a date. */
    public static function dayOffStatus(\mysqli $conn, string $date): array
    {
        $out = ['is_off' => false, 'cancel_makeup' => true, 'reason' => '', 'can_change' => $date >= self::today()];
        if (!self::tablesReady($conn)) {
            return $out;
        }
        if (self::covers($date)) {
            return isset(self::$range['off'][$date])
                ? array_merge($out, self::$range['off'][$date])
                : $out;
        }
        $stmt = $conn->prepare(
            'SELECT is_off, cancel_makeup, reason FROM ' . self::DAY_OFFS .
            ' WHERE day_date = ? ORDER BY id DESC LIMIT 1'
        );
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $out['is_off'] = (int)$row['is_off'] === 1;
            $out['cancel_makeup'] = (int)$row['cancel_makeup'] === 1;
            $out['reason'] = (string)($row['reason'] ?? '');
        }
        return $out;
    }

    /** Holiday title covering a date, or null. */
    public static function holidayOn(\mysqli $conn, string $date): ?string
    {
        if (!self::tablesReady($conn)) {
            return null;
        }
        if (self::covers($date)) {
            $best = null;
            foreach (self::$range['hol'] as $row) {
                if ($row['date_from'] <= $date && $row['date_to'] >= $date) {
                    $best = $row; // list order ends with the highest id — matches ORDER BY id DESC
                }
            }
            return $best !== null ? (string)($best['title_am'] ?: $best['title']) : null;
        }
        $stmt = $conn->prepare(
            'SELECT title, title_am FROM ' . self::HOLIDAYS .
            ' WHERE date_from <= ? AND date_to >= ? ORDER BY id DESC LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $date, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (string)($row['title_am'] ?: $row['title']) : null;
    }

    /** @return int[]|null ISO weekdays, or null when the class is not configured (owner decision 1: no fallback). */
    public static function workingDays(\mysqli $conn, int $classId): ?array
    {
        $year = self::activeYearRow($conn);
        if ($year === null) {
            return null;
        }
        $memoKey = $classId . ':' . $year['id'];
        if (array_key_exists($memoKey, self::$wdMemo)) {
            return self::$wdMemo[$memoKey];
        }
        $stmt = $conn->prepare(
            'SELECT working_days FROM timetable_class_days WHERE class_id = ? AND academic_year_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $classId, $year['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return self::$wdMemo[$memoKey] = null;
        }
        $days = [];
        foreach (explode(',', (string)$row['working_days']) as $part) {
            $n = (int)trim($part);
            if ($n >= 1 && $n <= 7) {
                $days[] = $n;
            }
        }
        return self::$wdMemo[$memoKey] = ($days === [] ? null : array_values(array_unique($days)));
    }

    /**
     * Timetable-linked school view (owner request 2026-10-20): for every date
     * in the range, what kind of day is it for the WHOLE school?
     *   type 'off'     — Day Off or holiday (classes do not hold);
     *   type 'school'  — the timetable puts at least one active class on this
     *                    weekday (or a dated makeup class exists);
     *   type 'neutral' — no class meets and the day is not declared off.
     * @return array<string,array{type:string,kind?:string,label?:string,detail?:string,classes?:int}>
     */
    public static function schoolDaysForRange(\mysqli $conn, string $from, string $to): array
    {
        self::primeRange($conn, $from, $to);
        $weekdayCounts = self::weekdayClassCounts($conn);   // ISO weekday => classes meeting
        $makeupCounts = self::makeupClassCounts(self::$range['mkClass'] ?? []); // Y-m-d => classes with a makeup
        $out = [];
        $d = new \DateTime($from);
        $end = new \DateTime($to);
        while ($d <= $end) {
            $ymd = $d->format('Y-m-d');
            $entry = null;
            if (self::tablesReady($conn)) {
                $off = self::dayOffStatus($conn, $ymd);
                if ($off['is_off']) {
                    $entry = ['type' => 'off', 'kind' => 'day_off', 'label' => 'Day Off',
                        'detail' => $off['reason'] !== '' ? $off['reason'] : 'classes are off for the whole school'];
                } else {
                    $holiday = self::holidayOn($conn, $ymd);
                    if ($holiday !== null) {
                        $entry = ['type' => 'off', 'kind' => 'holiday', 'label' => 'Holiday', 'detail' => $holiday];
                    }
                }
            }
            if ($entry === null) {
                $dow = (int)$d->format('N');
                $classes = $weekdayCounts[$dow] ?? 0;
                $entry = self::schoolDayEntry($classes, $makeupCounts[$ymd] ?? 0);
            }
            $out[$ymd] = $entry;
            $d->modify('+1 day');
        }
        return $out;
    }

    /**
     * School-view entry for a day that is not off. $classes = active classes
     * whose saved working days include the weekday; $makeupClasses = classes
     * with at least one makeup lesson on the date. A makeup day keeps the
     * regular count in its text.
     *
     * @return array{type:string,kind:string,label:string,detail:string,classes?:int,makeup_classes?:int}
     */
    public static function schoolDayEntry(int $classes, int $makeupClasses): array
    {
        $meet = $classes . ($classes === 1 ? ' class meets' : ' classes meet');
        if ($makeupClasses > 0) {
            $makeup = $makeupClasses . ' makeup class' . ($makeupClasses === 1 ? '' : 'es');
            return ['type' => 'school', 'kind' => 'makeup', 'label' => 'School day',
                'detail' => $classes > 0 ? $meet . ' · ' . $makeup : $makeup,
                'classes' => $classes, 'makeup_classes' => $makeupClasses];
        }
        if ($classes > 0) {
            return ['type' => 'school', 'kind' => 'regular', 'label' => 'School day',
                'detail' => $meet, 'classes' => $classes];
        }
        return ['type' => 'neutral', 'kind' => 'none', 'label' => 'Neutral',
            'detail' => 'No class meets on this day'];
    }

    /**
     * Y-m-d => number of distinct classes with at least one makeup lesson.
     * Makeups are stored one row per lesson, keyed here as "classId:Y-m-d".
     *
     * @param array<string,bool> $classDateKeys
     * @return array<string,int>
     */
    public static function makeupClassCounts(array $classDateKeys): array
    {
        $out = [];
        foreach (array_keys($classDateKeys) as $key) {
            $date = substr((string)$key, (int)strpos((string)$key, ':') + 1);
            $out[$date] = ($out[$date] ?? 0) + 1;
        }
        return $out;
    }

    /** ISO weekday => number of active classes whose timetable working days include it. */
    public static function weekdayClassCounts(\mysqli $conn): array
    {
        $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
        if (!self::timetableReady($conn)) {
            return $counts;
        }
        $year = self::activeYearRow($conn);
        if ($year === null) {
            return $counts;
        }
        $stmt = $conn->prepare(
            'SELECT t.working_days FROM timetable_class_days t
               JOIN classes c ON c.id = t.class_id
              WHERE c.is_active = 1 AND t.academic_year_id = ?'
        );
        if (!$stmt) {
            return $counts;
        }
        $stmt->bind_param('i', $year['id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            foreach (explode(',', (string)$row['working_days']) as $part) {
                $n = (int)trim($part);
                if ($n >= 1 && $n <= 7) {
                    $counts[$n]++;
                }
            }
        }
        $stmt->close();
        return $counts;
    }

    /** Classes without a working-days row for the active year (Year Calendar page). */
    public static function unconfiguredClasses(\mysqli $conn): array
    {
        if (!self::timetableReady($conn)) {
            return [];
        }
        $year = self::activeYearRow($conn);
        if ($year === null) {
            return [];
        }
        $res = $conn->query(
            "SELECT c.id, c.class_name, c.class_name_en
               FROM classes c
               WHERE c.is_active = 1
                 AND NOT EXISTS (SELECT 1 FROM timetable_class_days t
                                  WHERE t.class_id = c.id AND t.academic_year_id = " . (int)$year['id'] . ")
              ORDER BY c.level_order, c.class_name"
        );
        $out = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[] = ['id' => (int)$row['id'], 'name' => (string)($row['class_name_en'] ?: $row['class_name'])];
            }
            $res->free();
        }
        return $out;
    }

    /** @return array{from:string,to:string}|null */
    public static function activeYearWindow(\mysqli $conn): ?array
    {
        $row = self::activeYearRow($conn);
        if ($row === null || $row['from'] === null || $row['to'] === null) {
            return null;
        }
        return ['from' => $row['from'], 'to' => $row['to']];
    }

    /** @return array{id:int,from:?string,to:?string}|null */
    private static ?array $yearMemo = null;
    private static bool $yearMemoSet = false;

    private static function activeYearRow(\mysqli $conn): ?array
    {
        if (self::$yearMemoSet) {
            return self::$yearMemo;
        }
        self::$yearMemoSet = true;
        $res = $conn->query(
            "SELECT id, start_date, end_date FROM academic_years
              WHERE status = 'active' ORDER BY id DESC LIMIT 1"
        );
        if (!$res || !($row = $res->fetch_assoc())) {
            $res = $conn->query(
                "SELECT id, start_date, end_date FROM academic_years
                  WHERE is_current = 1 ORDER BY id DESC LIMIT 1"
            );
            if (!$res || !($row = $res->fetch_assoc())) {
                return self::$yearMemo = null;
            }
        }
        return self::$yearMemo = [
            'id'   => (int)$row['id'],
            'from' => ($row['start_date'] !== null && $row['start_date'] !== '') ? substr((string)$row['start_date'], 0, 10) : null,
            'to'   => ($row['end_date'] !== null && $row['end_date'] !== '') ? substr((string)$row['end_date'], 0, 10) : null,
        ];
    }

    private static function hasMakeup(\mysqli $conn, int $classId, string $date): bool
    {
        if (self::covers($date)) {
            return isset(self::$range['mkClass'][$classId . ':' . $date]);
        }
        $stmt = $conn->prepare(
            "SELECT 1 FROM timetable_makeup_classes WHERE class_id = ? AND lesson_date = ? LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $classId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row !== null;
    }

    /** True when any attendance (class/day or lesson) already exists for the date (owner decision 4). */
    public static function attendanceExists(\mysqli $conn, string $date): bool
    {
        foreach ([
            ['SELECT 1 FROM attendance WHERE attendance_date = ? LIMIT 1', $date],
            ['SELECT 1 FROM attendance_sessions WHERE session_date = ? LIMIT 1', $date],
        ] as [$sql, $arg]) {
            try {
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param('s', $arg);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                if ($row !== null) {
                    return true;
                }
            } catch (\Throwable $e) {
                continue; // a missing table must never break the decision flow
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Writes (Education dept only — enforced by the API layer)
    // ─────────────────────────────────────────────────────────────────────

    /** @return array{ok:bool,message?:string,id?:int} */
    public static function saveHoliday(\mysqli $conn, array $in, ?SecurityAuditActor $actor = null): array
    {
        if (!self::tablesReady($conn)) {
            return ['ok' => false, 'message' => 'School calendar is not installed yet. Run sql/078_school_calendar.sql first.'];
        }
        $id = (int)($in['id'] ?? 0);
        $title = trim((string)($in['title'] ?? ''));
        $titleAm = trim((string)($in['title_am'] ?? ''));
        $from = (string)($in['date_from'] ?? '');
        $to = (string)($in['date_to'] ?? '');
        $type = (string)($in['type'] ?? 'holiday');
        if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            return ['ok' => false, 'message' => 'A title and a start date are required.'];
        }
        if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = $from;
        }
        if ($to < $from) {
            return ['ok' => false, 'message' => 'The end date cannot be before the start date.'];
        }
        if ($to < self::today()) {
            return ['ok' => false, 'message' => 'Holidays can only be set for today or later.'];
        }
        if (!in_array($type, ['holiday', 'vacation', 'closure', 'other'], true)) {
            $type = 'holiday';
        }
        if ($id > 0) {
            $stmt = $conn->prepare(
                'UPDATE ' . self::HOLIDAYS . ' SET title = ?, title_am = ?, date_from = ?, date_to = ?, type = ? WHERE id = ?'
            );
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO ' . self::HOLIDAYS . ' (title, title_am, date_from, date_to, type, created_by) VALUES (?, ?, ?, ?, ?, ?)'
            );
        }
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Could not save the holiday.'];
        }
        if ($id > 0) {
            $stmt->bind_param('sssssi', $title, $titleAm, $from, $to, $type, $id);
        } else {
            $createdBy = $actor ? $actor->userId() : null;
            $stmt->bind_param('sssssi', $title, $titleAm, $from, $to, $type, $createdBy);
        }
        $okExec = $stmt->execute();
        $newId = $id > 0 ? $id : (int)$conn->insert_id;
        $stmt->close();
        if (!$okExec) {
            return ['ok' => false, 'message' => 'Could not save the holiday.'];
        }
        self::invalidateRange();
        self::audit($conn, $actor, 'school_holiday_saved', [
            'id' => $newId, 'title' => $title, 'date_from' => $from, 'date_to' => $to, 'type' => $type,
        ], 'school_calendar', $newId);
        return ['ok' => true, 'id' => $newId, 'message' => 'Holiday saved.'];
    }

    public static function deleteHoliday(\mysqli $conn, int $id, ?SecurityAuditActor $actor = null): array
    {
        if (!self::tablesReady($conn)) {
            return ['ok' => false, 'message' => 'School calendar is not installed yet.'];
        }
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Holiday id is required.'];
        }
        // Holidays may be edited/deleted freely: they gate eligibility going
        // forward and never rewrote historical attendance rows. The date_from/
        // date_to values are read only for the audit trail.
        $stmt = $conn->prepare('SELECT date_from, date_to FROM ' . self::HOLIDAYS . ' WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Could not load the holiday.'];
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => 'Holiday not found.'];
        }
        $stmt = $conn->prepare('DELETE FROM ' . self::HOLIDAYS . ' WHERE id = ?');
        $stmt->bind_param('i', $id);
        $okExec = $stmt->execute();
        $stmt->close();
        if (!$okExec) {
            return ['ok' => false, 'message' => 'Could not delete the holiday.'];
        }
        self::invalidateRange();
        self::audit($conn, $actor, 'school_holiday_deleted', [
            'id' => $id, 'date_from' => $row['date_from'], 'date_to' => $row['date_to'],
        ], 'school_calendar', $id);
        return ['ok' => true, 'message' => 'Holiday deleted.'];
    }

    /** Mark a Day Off (owner decisions 3 + 4). */
    public static function markDayOff(\mysqli $conn, string $date, bool $cancelMakeup, string $reason, ?SecurityAuditActor $actor = null): array
    {
        return self::writeDayOff($conn, $date, true, $cancelMakeup, $reason, $actor);
    }

    /** Reopen a Day Off — only while the date has not passed (owner rule). */
    public static function reopenDayOff(\mysqli $conn, string $date, string $reason, ?SecurityAuditActor $actor = null): array
    {
        return self::writeDayOff($conn, $date, false, false, $reason, $actor);
    }

    private static function writeDayOff(\mysqli $conn, string $date, bool $isOff, bool $cancelMakeup, string $reason, ?SecurityAuditActor $actor): array
    {
        if (!self::tablesReady($conn)) {
            return ['ok' => false, 'message' => 'School calendar is not installed yet. Run sql/078_school_calendar.sql first.'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'message' => 'A valid date is required.'];
        }
        $today = self::today();
        if ($date < $today) {
            return ['ok' => false, 'message' => 'A day can only be marked or reopened before it passes.'];
        }
        if ($isOff && self::attendanceExists($conn, $date)) {
            return ['ok' => false, 'code' => 'ATTENDANCE_EXISTS',
                'message' => 'Attendance has already been saved for this day, so it cannot be marked as a day off.'];
        }
        $current = self::dayOffStatus($conn, $date);
        if ($isOff && $current['is_off']) {
            return ['ok' => false, 'message' => 'This day is already a day off.'];
        }
        if (!$isOff && !$current['is_off']) {
            return ['ok' => false, 'message' => 'This day is not a day off.'];
        }
        $reason = trim($reason);
        if ($isOff && $reason === '') {
            return ['ok' => false, 'message' => 'A reason is required when marking a day off.'];
        }
        $stmt = $conn->prepare(
            'INSERT INTO ' . self::DAY_OFFS . ' (day_date, is_off, cancel_makeup, reason, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Could not save the day off.'];
        }
        $isOffInt = $isOff ? 1 : 0;
        $cancelInt = $cancelMakeup ? 1 : 0;
        $createdBy = $actor ? $actor->userId() : null;
        $stmt->bind_param('siisi', $date, $isOffInt, $cancelInt, $reason, $createdBy);
        $okExec = $stmt->execute();
        $stmt->close();
        if (!$okExec) {
            return ['ok' => false, 'message' => 'Could not save the day off.'];
        }
        self::invalidateRange();
        self::audit($conn, $actor, $isOff ? 'day_off_marked' : 'day_off_reopened', [
            'date' => $date, 'cancel_makeup' => $cancelMakeup, 'reason' => $reason,
        ], 'school_calendar', 0);
        return ['ok' => true, 'message' => $isOff ? 'Day off marked.' : 'Day reopened.'];
    }

    /** Holidays in a date window (inclusive). */
    public static function holidays(\mysqli $conn, string $from, string $to): array
    {
        if (!self::tablesReady($conn)) {
            return [];
        }
        $stmt = $conn->prepare(
            'SELECT id, title, title_am, date_from, date_to, type, created_at
               FROM ' . self::HOLIDAYS . ' WHERE date_to >= ? AND date_from <= ? ORDER BY date_from, id'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'id'        => (int)$row['id'],
                'title'     => (string)$row['title'],
                'title_am'  => (string)($row['title_am'] ?? ''),
                'date_from' => substr((string)$row['date_from'], 0, 10),
                'date_to'   => substr((string)$row['date_to'], 0, 10),
                'type'      => (string)$row['type'],
                'created_at'=> (string)$row['created_at'],
            ];
        }
        $stmt->close();
        return $out;
    }

    /** Effective Day Off days in a window (latest row per date, only is_off rows). */
    public static function dayOffs(\mysqli $conn, string $from, string $to): array
    {
        if (!self::tablesReady($conn)) {
            return [];
        }
        $stmt = $conn->prepare(
            'SELECT d.day_date, d.is_off, d.cancel_makeup, d.reason, d.created_at, d.created_by
               FROM ' . self::DAY_OFFS . ' d
               JOIN (SELECT day_date, MAX(id) AS mid FROM ' . self::DAY_OFFS . '
                      WHERE day_date >= ? AND day_date <= ? GROUP BY day_date) latest
                 ON latest.mid = d.id
              WHERE d.is_off = 1
              ORDER BY d.day_date'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'date'          => substr((string)$row['day_date'], 0, 10),
                'cancel_makeup' => (int)$row['cancel_makeup'] === 1,
                'reason'        => (string)($row['reason'] ?? ''),
                'created_at'    => (string)$row['created_at'],
                'can_change'    => substr((string)$row['day_date'], 0, 10) >= self::today(),
            ];
        }
        $stmt->close();
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    public static function today(): string
    {
        return (new \DateTime('now', new \DateTimeZone('Africa/Addis_Ababa')))->format('Y-m-d');
    }

    public static function ecLabel(string $date): string
    {
        return ethio_date_format($date, 'F j, Y');
    }

    public static function dayName(int $isoDow): string
    {
        static $names = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        return $names[$isoDow] ?? '';
    }

    private static function audit(\mysqli $conn, ?SecurityAuditActor $actor, string $action, array $details, string $entity, int $entityId): void
    {
        try {
            if ($actor !== null) {
                SecurityAuditService::recordTrusted($conn, $actor, $action, $details, $entity, $entityId);
            } else {
                SecurityAuditService::record($conn, $action, $details, $entity, $entityId);
            }
        } catch (\Throwable $e) {
            error_log('SchoolCalendarService audit failed: ' . $e->getMessage());
        }
    }
}
