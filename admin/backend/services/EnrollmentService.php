<?php
/**
 * Single source of truth for year-scoped class enrollment.
 *
 * Used by HR registration, Excel import, and Education APIs.
 * One member may have only one *active* enrollment per academic year.
 */

namespace App\Services;

require_once __DIR__ . '/MemberCategory.php';
require_once __DIR__ . '/IdentityCodeService.php';
require_once __DIR__ . '/DepartmentStatusService.php';

class EnrollmentService
{

    /**
     * mysqli has no in_transaction() method. Callers owning an outer
     * transaction opt in explicitly; a savepoint isolates this operation
     * without committing or rolling back their unrelated work.
     */
    private static function transactionScope(\mysqli $conn, bool $withinTransaction, callable $work): array
    {
        $ownsTransaction = !$withinTransaction;
        $savepoint = 'ssms_enrollment_' . bin2hex(random_bytes(6));
        $started = false;
        try {
            if ($ownsTransaction) $conn->begin_transaction();
            else $conn->query('SAVEPOINT ' . $savepoint);
            $started = true;
            $result = $work();
            if (($result['status'] ?? '') !== 'success') {
                if ($ownsTransaction) $conn->rollback();
                else {
                    $conn->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $conn->query('RELEASE SAVEPOINT ' . $savepoint);
                }
                return $result;
            }
            if ($ownsTransaction) $conn->commit();
            else $conn->query('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (\Throwable $error) {
            if ($started) {
                try {
                    if ($ownsTransaction) $conn->rollback();
                    else {
                        $conn->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                        $conn->query('RELEASE SAVEPOINT ' . $savepoint);
                    }
                } catch (\Throwable $ignored) { /* connection may already be lost */ }
            }
            if ($error instanceof \DomainException) {
                return ['status'=>'error', 'message'=>$error->getMessage()];
            }
            if (function_exists('reportInternalError')) reportInternalError('Enrollment transaction failed', $error);
            else error_log('Enrollment transaction failed: ' . $error->getMessage());
            return ['status'=>'error', 'message'=>'Enrollment failed. No changes were made.'];
        }
    }

    private static function lockMember(\mysqli $conn, int $memberId): void
    {
        // A per-member lock serializes new enrollments as well as transfers;
        // locking only existing enrollments cannot protect a first enrollment.
        $stmt = $conn->prepare('SELECT id, status FROM members WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $memberId); $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$member || $member['status'] === 'archived') throw new \DomainException('Member not found or archived.');
    }

    public static function enroll(\mysqli $conn, int $memberId, int $classId, ?int $yearId = null, ?int $enrolledBy = null, bool $withinTransaction = false): array
    {
        if ($memberId <= 0 || $classId <= 0) return ['status'=>'error','message'=>'Member and class are required.'];
        return self::transactionScope($conn, $withinTransaction, static function () use ($conn, $memberId, $classId, $yearId, $enrolledBy): array {
            self::lockMember($conn, $memberId);
            return self::enrollInScope($conn, $memberId, $classId, $yearId, $enrolledBy);
        });
    }

    public static function transferByEnrollment(\mysqli $conn, int $enrollmentId, int $toClassId, int $yearId, int $enrolledBy, string $reason = '', bool $withinTransaction = false): array
    {
        return self::transactionScope($conn, $withinTransaction, static function () use ($conn, $enrollmentId, $toClassId, $yearId, $enrolledBy, $reason): array {
            $find = $conn->prepare('SELECT member_id FROM class_enrollments WHERE id=?');
            $find->bind_param('i', $enrollmentId); $find->execute();
            $source = $find->get_result()->fetch_assoc(); $find->close();
            if (!$source) return ['status'=>'error','message'=>'Enrollment not found.'];
            self::lockMember($conn, (int)$source['member_id']);
            return self::transferInScope($conn, $enrollmentId, $toClassId, $yearId, $enrolledBy, $reason);
        });
    }

    public static function activeYear(\mysqli $conn): ?array
    {
        if (function_exists('ay_resolve')) {
            $resolved = ay_resolve($conn);
            if (!empty($resolved['year']) && is_array($resolved['year'])) {
                return $resolved['year'];
            }
        }
        if (function_exists('ay_active_year')) {
            $y = ay_active_year($conn);
            if (is_array($y)) {
                return $y;
            }
        }
        try {
            $r = $conn->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
            return $r ? $r->fetch_assoc() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function resolveClass(\mysqli $conn, $idOrCode): ?array
    {
        if ($idOrCode === null || $idOrCode === '') {
            return null;
        }
        if (is_int($idOrCode) || (is_string($idOrCode) && ctype_digit(trim($idOrCode)))) {
            $id = (int)$idOrCode;
            if ($id <= 0) {
                return null;
            }
            $stmt = $conn->prepare("SELECT * FROM classes WHERE id = ? AND is_active = 1 LIMIT 1");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        }
        $label = trim((string)$idOrCode);
        if ($label === '') {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT * FROM classes
             WHERE is_active = 1
               AND (class_code = ? OR class_name = ? OR class_name_en = ?)
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('sss', $label, $label, $label);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Strict label lookup for bulk imports (Excel sync).
     *
     * Unlike resolveClass() — which treats a digit-only label as a class ID —
     * this matches ONLY by class_name, class_name_en or class_code, so a
     * grade label such as "5" can never land in the class whose id is 5.
     * Whitespace differences are ignored (class names typed in the
     * Education modal often carry irregular spaces, e.g. "ሣልሳይ (9ኛ ክፍል )").
     * Ambiguous labels are reported, never guessed.
     *
     * @param array<int,array<string,mixed>>|null $activeClasses optional preloaded active classes
     * @return array{status:string, class?:array<string,mixed>, matches?:int}
     *         status: ok | unknown | ambiguous
     */
    public static function resolveClassByLabel(?\mysqli $conn, string $label, ?array $activeClasses = null): array
    {
        $key = self::classLabelKey($label);
        if ($key === '') {
            return ['status' => 'unknown'];
        }
        if ($activeClasses === null) {
            $activeClasses = $conn !== null ? self::activeClassesForLookup($conn) : [];
        }
        $hits = [];
        foreach ($activeClasses as $class) {
            foreach (['class_name', 'class_name_en', 'class_code'] as $field) {
                $candidate = self::classLabelKey((string)($class[$field] ?? ''));
                if ($candidate !== '' && $candidate === $key) {
                    $hits[(int)$class['id']] = $class;
                    break;
                }
            }
        }
        if (count($hits) === 1) {
            return ['status' => 'ok', 'class' => reset($hits)];
        }
        return count($hits) === 0
            ? ['status' => 'unknown']
            : ['status' => 'ambiguous', 'matches' => count($hits)];
    }

    /** @return array<int,array<string,mixed>> active classes (id, names, code, age_group) */
    public static function activeClassesForLookup(\mysqli $conn): array
    {
        $rows = [];
        $res = $conn->query('SELECT id, class_name, class_name_en, class_code, age_group FROM classes WHERE is_active = 1');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        }
        return $rows;
    }

    /** Comparison key for class labels: case-folded, all whitespace removed. */
    public static function classLabelKey(string $label): string
    {
        $label = (string)preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $label);
        return function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    }

    /**
     * Enroll using a dropdown name, class_code, or numeric class id.
     */
    public static function enrollByLabel(\mysqli $conn, int $memberId, string $label, ?int $yearId = null, ?int $enrolledBy = null, bool $withinTransaction = false): array
    {
        $class = self::resolveClass($conn, $label);
        if (!$class) {
            return ['status' => 'error', 'message' => 'Unknown class: ' . $label];
        }
        return self::enroll($conn, $memberId, (int)$class['id'], $yearId, $enrolledBy, $withinTransaction);
    }

    /**
     * Enroll a member in a class for the active (or given) year.
     *
     * @return array{status:string,message:string,enrollment_id?:int,skipped?:bool,transferred?:bool}
     */
    private static function enrollInScope(\mysqli $conn, int $memberId, int $classId, ?int $yearId = null, ?int $enrolledBy = null): array
    {
        if ($memberId <= 0 || $classId <= 0) {
            return ['status' => 'error', 'message' => 'Member and class are required.'];
        }

        $class = self::resolveClass($conn, $classId);
        if (!$class) {
            return ['status' => 'error', 'message' => 'Class not found or inactive.'];
        }

        $year = null;
        if ($yearId) {
            $stmt = $conn->prepare("SELECT * FROM academic_years WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $yearId);
                $stmt->execute();
                $year = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        }
        if ($yearId && !$year) return ['status'=>'error','message'=>'Academic year not found.'];
        if (!$year) {
            $year = self::activeYear($conn);
        }
        if (!$year) {
            return ['status' => 'error', 'message' => 'No active academic year. Member saved without a class.'];
        }
        $yearId = (int)$year['id'];
        $enrolledBy = $enrolledBy ?: (int)($_SESSION['admin_id'] ?? 0);
        $today = date('Y-m-d');

        $existing = self::activeEnrollment($conn, $memberId, $yearId);
        if ($existing) {
            if ((int)$existing['class_id'] === (int)$class['id']) {
                return [
                    'status' => 'success',
                    'message' => 'Already enrolled in this class.',
                    'enrollment_id' => (int)$existing['id'],
                    'skipped' => true,
                ];
            }
            $xfer = self::transferByEnrollment($conn, (int)$existing['id'], (int)$class['id'], $yearId, $enrolledBy, 'Assigned from HR / Excel', true);
            if (($xfer['status'] ?? '') === 'success') {
                $xfer['transferred'] = true;
            }
            return $xfer;
        }

        $stmt = $conn->prepare(
            "INSERT INTO class_enrollments
                (member_id, class_id, academic_year_id, enrolled_at, status, enrolled_by)
             VALUES (?, ?, ?, ?, 'active', ?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), status='active', enrolled_by=VALUES(enrolled_by), enrolled_at=VALUES(enrolled_at)"
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Could not prepare enrollment.'];
        }
        $stmt->bind_param('iiisi', $memberId, $class['id'], $yearId, $today, $enrolledBy);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            if (function_exists('reportInternalError')) {
                reportInternalError('Enrollment insert failed', $error);
            }
            return ['status' => 'error', 'message' => 'Unable to save the enrollment.'];
        }
        $enrollId = (int)$stmt->insert_id;
        $stmt->close();

        if (function_exists('autoUpdateMemberClass')) {
            if (!autoUpdateMemberClass($conn, $memberId, (int)$class['id'], $yearId)) throw new \RuntimeException('Could not update member class.');
        }

        return [
            'status' => 'success',
            'message' => 'Enrolled in ' . ($class['class_name'] ?? 'class') . '.',
            'enrollment_id' => $enrollId,
        ];
    }

    public static function enrollByCode(\mysqli $conn, int $memberId, string $classCode, ?int $yearId = null, ?int $enrolledBy = null, bool $withinTransaction = false): array
    {
        $class = self::resolveClass($conn, $classCode);
        if (!$class) {
            return ['status' => 'error', 'message' => 'Unknown class code: ' . $classCode];
        }
        return self::enroll($conn, $memberId, (int)$class['id'], $yearId, $enrolledBy, $withinTransaction);
    }

    private static function transferInScope(\mysqli $conn, int $enrollmentId, int $toClassId, int $yearId, int $enrolledBy, string $reason = ''): array
    {
        $toClass = self::resolveClass($conn, $toClassId);
        if (!$toClass) {
            return ['status' => 'error', 'message' => 'Target class not found or inactive.'];
        }

        $stmt = $conn->prepare("SELECT * FROM class_enrollments WHERE id = ? LIMIT 1 FOR UPDATE");
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Enrollment not found.'];
        }
        $stmt->bind_param('i', $enrollmentId);
        $stmt->execute();
        $enr = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$enr) {
            return ['status' => 'error', 'message' => 'Enrollment not found.'];
        }

        if ((int)$enr['academic_year_id'] !== $yearId || $enr['status'] !== 'active') {
            return ['status'=>'error','message'=>'The source enrollment is not active in this academic year.'];
        }
        $memberId = (int)$enr['member_id'];
        $fromClass = (int)$enr['class_id'];
        if ($fromClass === (int)$toClass['id']) {
            return ['status' => 'success', 'message' => 'Already in target class.', 'skipped' => true];
        }

        $note = $reason !== '' ? $reason : 'Transferred';
        $today = date('Y-m-d');

        $up = $conn->prepare("UPDATE class_enrollments SET status='transferred', notes=CONCAT(IFNULL(notes,''), ' [', ?, ']') WHERE id = ?");
        if ($up) {
            $up->bind_param('si', $note, $enrollmentId);
            $up->execute();
            $up->close();
        }

        $ins = $conn->prepare(
            "INSERT INTO class_enrollments
                (member_id, class_id, academic_year_id, enrolled_at, status, notes, promoted_from, enrolled_by)
             VALUES (?, ?, ?, ?, 'active', ?, ?, ?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), status='active', notes=VALUES(notes), enrolled_by=VALUES(enrolled_by)"
        );
        if (!$ins) {
            throw new \RuntimeException('Could not create transfer enrollment.');
        }
        $ins->bind_param('iiissii', $memberId, $toClass['id'], $yearId, $today, $note, $fromClass, $enrolledBy);
        if (!$ins->execute()) {
            $err = $ins->error;
            $ins->close();
            throw new \RuntimeException('Transfer failed: ' . $err);
        }
        $newId = (int)$ins->insert_id;
        $ins->close();

        if (function_exists('autoUpdateMemberClass')) {
            if (!autoUpdateMemberClass($conn, $memberId, (int)$toClass['id'], $yearId)) throw new \RuntimeException('Could not update member class.');
        }


        return [
            'status' => 'success',
            'message' => 'Transferred to ' . ($toClass['class_name'] ?? 'class') . '.',
            'enrollment_id' => $newId,
            'transferred' => true,
        ];
    }

    /**
     * Compatibility adapter for existing import/enrollment callers.
     * $ageGroup accepts the stored value ('7_13','14_17','18_plus', legacy
     * 'under6') or a bare letter; it maps onto the ministry A/B/C sequence.
     */
    /**
     * Compatibility adapter. Returns NULL (pending code) when the age group
     * cannot be mapped to a category letter — codes are never guessed, the
     * Identity hub issues them once the category is known.
     */
    public static function generateMemberCode(\mysqli $conn, ?string $ageGroup = null): ?string
    {
        require_once __DIR__ . '/MemberCategory.php';
        require_once __DIR__ . '/IdentityCodeService.php';
        $letter = MemberCategory::letterFor($ageGroup);
        if ($letter === null) {
            return null;
        }
        return IdentityCodeService::allocateStudent($conn, $letter);
    }

    private static function activeEnrollment(\mysqli $conn, int $memberId, int $yearId): ?array
    {
        $stmt = $conn->prepare(
            "SELECT * FROM class_enrollments
             WHERE member_id = ? AND academic_year_id = ? AND status = 'active'
             ORDER BY id DESC LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $memberId, $yearId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Count active enrollments for a class in one year.
     * When $includeUnscoped is true, also count rows with NULL/0 year
     * (legacy imports that never stamped a year).
     */
    /**
     * Does this class carry grade history that must survive a delete?
     * True when any of its assessments has entered grades, or any teacher
     * submission packet exists for it (audit H4: history-preserving delete).
     */
    public static function classHasGradeHistory(\mysqli $conn, int $classId): bool
    {
        foreach (
            [
                "SELECT 1 FROM academic_records ar
                  JOIN assessments a ON a.id = ar.assessment_id
                 WHERE a.class_id = ? LIMIT 1",
                "SELECT 1 FROM grade_submissions WHERE class_id = ? LIMIT 1",
            ] as $sql
        ) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('i', $classId);
            $stmt->execute();
            $has = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($has) {
                return true;
            }
        }
        return false;
    }

    /**
     * Strict opt-in preparation read. The caller owns authorization and a
     * consistent read transaction; this is not a lease or readiness grant.
     * Preserve the legacy rollover policy without treating SQL failure as zero.
     * The preferred write year remains separate from the resolved roster year.
     * @return array{year_id:int,preferred_year_id:int,fallback:bool}
     */
    public static function resolvePreparationRosterYear(\mysqli $conn, int $classId, int $preferredYearId): array
    {
        self::requirePreparationIds($classId, $preferredYearId);
        $rows = self::preparationRows($conn,
            "SELECT COUNT(*) AS c FROM class_enrollments
             WHERE class_id = ? AND status = 'active'
               AND (academic_year_id = ? OR academic_year_id IS NULL OR academic_year_id = 0)",
            'ii', [$classId, $preferredYearId]);
        $out = ['year_id' => $preferredYearId, 'preferred_year_id' => $preferredYearId, 'fallback' => false];
        if ((int)$rows[0]['c'] > 0) return $out;
        $rows = self::preparationRows($conn,
            "SELECT academic_year_id, COUNT(*) AS cnt FROM class_enrollments
             WHERE class_id = ? AND status = 'active'
             GROUP BY academic_year_id ORDER BY cnt DESC, academic_year_id DESC LIMIT 1",
            'i', [$classId]);
        if ($rows === []) return $out; // Successful empty query, not an error.
        $resolved = (int)$rows[0]['academic_year_id'];
        if ($resolved <= 0 || $resolved > 9007199254740991) {
            throw new \RuntimeException('Preparation roster year is unavailable.');
        }
        $out['year_id'] = $resolved;
        $out['fallback'] = $resolved !== $preferredYearId;
        return $out;
    }

    /**
     * Only attendance UI/QR identity fields, no broader member/HR data.
     * $rosterYearId is server-resolved, NEVER the original attendance write year.
     * Existing enrollment and dated Education eligibility rules are unchanged.
     * @return list<array{member_id:int,student_name:string,father_name:string,member_code:string}>
     */
    // R5b — Sanitize member strings for preparation payloads. Never abort whole roster for one bad name; coerce to safe UTF-8.
    private static function sanitizePrepString(?string $v, int $max = 200): string {
        if ($v === null) return '';
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        $v = trim($v);
        if (!mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        if (mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
        return $v;
    }

    public static function fetchPreparationRoster(\mysqli $conn, int $classId, int $rosterYearId, string $date): array
    {
        self::requirePreparationIds($classId, $rosterYearId);
        $exclusion = DepartmentStatusService::exclusionSql($conn, 'edu', 'm.id', $date, true);
        $rows = self::preparationRows($conn,
            "SELECT m.id AS member_id, m.student_name, m.father_name, m.member_code
             FROM class_enrollments ce LEFT JOIN members m ON ce.member_id = m.id
             WHERE ce.class_id = ? AND ce.status = 'active'
               AND (ce.academic_year_id = ? OR ce.academic_year_id IS NULL OR ce.academic_year_id = 0)"
                . $exclusion . ' ORDER BY m.student_name, m.id',
            'ii', [$classId, $rosterYearId]);
        $seen = [];
        foreach ($rows as &$row) {
            $id = (int)$row['member_id'];
            if ($id <= 0 || $id > 9007199254740991 || isset($seen[$id])) {
                throw new \RuntimeException('Preparation roster contains an invalid or duplicate identity.');
            }
            $seen[$id] = true;
            $row = ['member_id' => $id,
                'student_name' => self::sanitizePrepString((string)$row['student_name'], 200),
                'father_name' => self::sanitizePrepString((string)$row['father_name'], 200) ?: '—',
                'member_code' => self::sanitizePrepString((string)$row['member_code'], 100) ?: '—',];
        }
        unset($row);
        return $rows;
    }

    /**
     * Normalized strict multi-date roster, SAME year/eligibility/name ordering
     * as fetchPreparationRoster, without per-date SQL/probe round trips.
     * Caller owns authorization, explicit server scope and coherent snapshot.
     * @return array{members:array,dates:array}
     */
    public static function fetchPreparationRosterDates(\mysqli $conn, int $classId, int $rosterYearId, array $dates): array
    {
        self::requirePreparationIds($classId, $rosterYearId);
        require_once __DIR__ . '/SchoolCalendarService.php';
        if (count($dates) > SchoolCalendarService::BACKDATE_DAYS + SchoolCalendarService::MOBILE_FUTURE_DAYS + 1) {
            throw new \InvalidArgumentException('Preparation batch exceeds the supported calendar budget.');
        }
        $exclusions = DepartmentStatusService::preparationExclusions($conn, 'edu', 'm.id', $dates);
        $parts = []; $params = []; $types = '';
        foreach ($exclusions as $date => $exclusion) {
            $literal = $conn->real_escape_string($date); // Already exact valid Gregorian date.
            $parts[] = "SELECT '{$literal}' AS on_date, m.id AS member_id
                FROM class_enrollments ce LEFT JOIN members m ON ce.member_id=m.id
                WHERE ce.class_id=? AND ce.status='active'
                  AND (ce.academic_year_id=? OR ce.academic_year_id IS NULL OR ce.academic_year_id=0)" . $exclusion;
            array_push($params, $classId, $rosterYearId); $types .= 'ii';
        }
        $rows = self::preparationRows($conn,
            'SELECT eligible.on_date, eligible.member_id FROM (' . implode(' UNION ALL ', $parts) . ') eligible
             LEFT JOIN members ordered_member ON eligible.member_id=ordered_member.id
             ORDER BY eligible.on_date, ordered_member.student_name, eligible.member_id', $types, $params);
        $byDate = array_fill_keys($dates, []); $seen = []; $ids = [];
        foreach ($rows as $row) {
            $date = $row['on_date']; $id = (int)$row['member_id'];
            if (!is_string($date) || !array_key_exists($date, $byDate)
                || $id <= 0 || $id > 9007199254740991 || isset($seen[$date][$id])) {
                throw new \RuntimeException('Preparation roster date/identity coverage is invalid or duplicated.');
            }
            $seen[$date][$id] = true; $ids[$id] = true; $byDate[$date][] = $id;
        }
        $members = []; $memberSeen = [];
        if ($ids !== []) {
            // Only validated positive integer identities, never client SQL.
            $idSql = implode(',', array_keys($ids));
            $identities = self::preparationRows($conn,
                'SELECT id AS member_id, student_name, father_name, member_code FROM members WHERE id IN (' . $idSql . ') ORDER BY id', '', []);
            foreach ($identities as $row) {
                $id = (int)$row['member_id'];
                if (!isset($ids[$id]) || isset($memberSeen[$id])) {
                    throw new \RuntimeException('Preparation member identities are incomplete or duplicated.');
                }
                $memberSeen[$id] = true;
                $members[] = ['member_id'=>$id, 'student_name'=>self::sanitizePrepString((string)$row['student_name'], 200),
                    'father_name'=>self::sanitizePrepString((string)$row['father_name'], 200) ?: '—', 'member_code'=>self::sanitizePrepString((string)$row['member_code'], 100) ?: '—',];
            }
            if (count($memberSeen) !== count($ids)) throw new \RuntimeException('Preparation member coverage is incomplete.');
        }
        $dateRows = [];
        foreach ($dates as $date) $dateRows[] = ['date'=>$date, 'member_ids'=>$byDate[$date]];
        return ['members'=>$members, 'dates'=>$dateRows];
    }

    private static function requirePreparationIds(int $classId, int $yearId): void
    {
        if ($classId <= 0 || $yearId <= 0 || $classId > 9007199254740991 || $yearId > 9007199254740991) {
            throw new \InvalidArgumentException('Explicit valid class and server-resolved year are required.');
        }
    }

    /** Fail closed in both mysqli reporting modes; never expose SQL errors. */
    private static function preparationRows(\mysqli $conn, string $sql, string $types, array $params): array
    {
        if (strlen($types) !== count($params)) {
            throw new \InvalidArgumentException('Preparation parameter binding is incomplete.');
        }
        $stmt = null;
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt || ($types !== '' && !$stmt->bind_param($types, ...$params)) || !$stmt->execute()) {
                throw new \RuntimeException('Preparation query failed.');
            }
            $result = $stmt->get_result();
            if (!$result) throw new \RuntimeException('Preparation result unavailable.');
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Roster preparation is unavailable.', 0, $error);
        } finally {
            if ($stmt instanceof \mysqli_stmt) $stmt->close();
        }
    }

    public static function countActive(\mysqli $conn, int $classId, ?int $yearId, bool $includeUnscoped = true): int
    {
        if ($classId <= 0) {
            return 0;
        }
        $yearId = $yearId ? (int)$yearId : 0;
        if ($yearId > 0 && $includeUnscoped) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c FROM class_enrollments
                 WHERE class_id = ? AND status = 'active'
                   AND (academic_year_id = ? OR academic_year_id IS NULL OR academic_year_id = 0)"
            );
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('ii', $classId, $yearId);
        } elseif ($yearId > 0) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c FROM class_enrollments
                 WHERE class_id = ? AND status = 'active' AND academic_year_id = ?"
            );
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('ii', $classId, $yearId);
        } else {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c FROM class_enrollments
                 WHERE class_id = ? AND status = 'active'"
            );
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('i', $classId);
        }
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $n;
    }

    /**
     * Which year should the roster for this class be read from?
     *
     * Prefer the active / requested year. If that year has nobody,
     * fall back to the year that actually has students (usually last year
     * before a rollover). Teachers must still see the children they teach.
     *
     * @return array{year_id:?int,count:int,fallback:bool,preferred_year_id:?int,preferred_count:int,year_name:?string}
     */
    public static function resolveRosterYear(\mysqli $conn, int $classId, ?int $preferredYearId): array
    {
        $preferredYearId = $preferredYearId ? (int)$preferredYearId : 0;
        $preferredCount = self::countActive($conn, $classId, $preferredYearId > 0 ? $preferredYearId : null, true);

        $out = [
            'year_id' => $preferredYearId > 0 ? $preferredYearId : null,
            'count' => $preferredCount,
            'fallback' => false,
            'preferred_year_id' => $preferredYearId > 0 ? $preferredYearId : null,
            'preferred_count' => $preferredCount,
            'year_name' => self::yearName($conn, $preferredYearId > 0 ? $preferredYearId : null),
        ];

        if ($preferredCount > 0) {
            return $out;
        }

        $stmt = $conn->prepare(
            "SELECT academic_year_id, COUNT(*) AS cnt
             FROM class_enrollments
             WHERE class_id = ? AND status = 'active'
             GROUP BY academic_year_id
             ORDER BY cnt DESC, academic_year_id DESC
             LIMIT 1"
        );
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return $out;
        }

        $altId = isset($row['academic_year_id']) && $row['academic_year_id'] !== null
            ? (int)$row['academic_year_id']
            : 0;
        $altCount = (int)$row['cnt'];
        if ($altCount <= 0) {
            return $out;
        }

        $out['year_id'] = $altId > 0 ? $altId : null;
        $out['count'] = $altCount;
        $out['fallback'] = ($preferredYearId > 0 && $altId !== $preferredYearId);
        $out['year_name'] = self::yearName($conn, $altId > 0 ? $altId : null);
        return $out;
    }

    public static function yearName(\mysqli $conn, ?int $yearId): ?string
    {
        $yearId = $yearId ? (int)$yearId : 0;
        if ($yearId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("SELECT year_name FROM academic_years WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $yearId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['year_name'] ?? null;
    }

    /**
     * Active students in a class for a resolved roster year.
     *
     * @param array{search?:string,gender?:string,member_type?:string,sort?:string,include_null_year?:bool} $opts
     * @return list<array<string,mixed>>
     */
    public static function fetchRoster(\mysqli $conn, int $classId, ?int $yearId, array $opts = []): array
    {
        if ($classId <= 0) {
            return [];
        }

        $search = trim((string)($opts['search'] ?? ''));
        $gender = trim((string)($opts['gender'] ?? ''));
        $memberType = trim((string)($opts['member_type'] ?? ''));
        $sort = trim((string)($opts['sort'] ?? 'name'));
        $includeNull = array_key_exists('include_null_year', $opts)
            ? (bool)$opts['include_null_year']
            : true;
        // Attendance alerting §11: members inactive in Education (per-department
        // status, Phase 1) are excluded from rosters/sheets/grades on the sheet
        // date ('on_date', default today). History screens that must still
        // list them pass 'include_dept_inactive' => true. No-op until
        // FEATURE_ATTENDANCE_V2 + sql/071 are live.
        $deptExclusion = !empty($opts['include_dept_inactive'])
            ? ''
            : DepartmentStatusService::exclusionSql($conn, 'edu', 'm.id', is_string($opts['on_date'] ?? null) ? $opts['on_date'] : null);

        $sql = "SELECT ce.id AS enrollment_id, ce.enrolled_at, ce.status AS enrollment_status,
                       ce.notes AS enrollment_notes, ce.academic_year_id,
                       m.id AS member_id, m.id, m.student_name, m.father_name, m.grandfather_name,
                       m.member_code, m.gender, m.age_group, m.date_of_birth, m.age,
                       m.baptismal_name, m.education_level, m.member_type,
                       m.is_teacher, m.is_staff, m.is_committee, m.is_volunteer,
                       m.student_photo_path, m.status, m.current_section
                FROM class_enrollments ce
                JOIN members m ON ce.member_id = m.id
                WHERE ce.class_id = ? AND ce.status = 'active'";
        $params = [$classId];
        $types = 'i';

        $yearId = $yearId ? (int)$yearId : 0;
        if ($yearId > 0 && $includeNull) {
            $sql .= " AND (ce.academic_year_id = ? OR ce.academic_year_id IS NULL OR ce.academic_year_id = 0)";
            $params[] = $yearId;
            $types .= 'i';
        } elseif ($yearId > 0) {
            $sql .= " AND ce.academic_year_id = ?";
            $params[] = $yearId;
            $types .= 'i';
        }
        $sql .= $deptExclusion;

        if ($search !== '') {
            $sql .= " AND (m.student_name LIKE ? OR m.father_name LIKE ? OR m.member_code LIKE ? OR m.baptismal_name LIKE ?)";
            $st = '%' . $search . '%';
            array_push($params, $st, $st, $st, $st);
            $types .= 'ssss';
        }
        if ($gender !== '' && in_array($gender, ['male', 'female'], true)) {
            $sql .= " AND m.gender = ?";
            $params[] = $gender;
            $types .= 's';
        }
        if ($memberType !== '' && in_array($memberType, ['regular', 'special_regular', 'honorary'], true)) {
            $sql .= " AND m.member_type = ?";
            $params[] = $memberType;
            $types .= 's';
        }

        $order = 'm.student_name';
        if ($sort === 'code') {
            $order = 'm.member_code';
        } elseif ($sort === 'date') {
            $order = 'ce.enrolled_at DESC';
        } elseif ($sort === 'gender') {
            $order = 'm.gender, m.student_name';
        }
        $sql .= " ORDER BY {$order}";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            // Slimmer query if a newer member column is missing on this database.
            $sql = "SELECT ce.id AS enrollment_id, ce.enrolled_at, ce.status AS enrollment_status,
                           ce.academic_year_id, m.id AS member_id, m.id, m.student_name,
                           m.father_name, m.member_code, m.gender
                    FROM class_enrollments ce
                    JOIN members m ON ce.member_id = m.id
                    WHERE ce.class_id = ? AND ce.status = 'active'";
            $params = [$classId];
            $types = 'i';
            if ($yearId > 0 && $includeNull) {
                $sql .= " AND (ce.academic_year_id = ? OR ce.academic_year_id IS NULL OR ce.academic_year_id = 0)";
                $params[] = $yearId;
                $types .= 'i';
            } elseif ($yearId > 0) {
                $sql .= " AND ce.academic_year_id = ?";
                $params[] = $yearId;
                $types .= 'i';
            }
            $sql .= $deptExclusion;
            $sql .= " ORDER BY m.student_name";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return [];
            }
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $rows = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $row['member_id'] = (int)$row['member_id'];
            $row['id'] = (int)$row['member_id'];
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * SQL fragment: student_count per class that matches resolveRosterYear.
     * $yearId is cast to int before interpolation.
     */
    public static function rosterCountJoinSql(int $yearId): string
    {
        $yearId = (int)$yearId;
        if ($yearId <= 0) {
            return "SELECT class_id, COUNT(*) AS cnt
                    FROM class_enrollments
                    WHERE status = 'active'
                    GROUP BY class_id";
        }
        return "SELECT class_id, COUNT(*) AS cnt
                FROM class_enrollments ce
                WHERE status = 'active'
                  AND (
                    academic_year_id = {$yearId}
                    OR academic_year_id IS NULL
                    OR academic_year_id = 0
                    OR NOT EXISTS (
                        SELECT 1 FROM class_enrollments x
                        WHERE x.class_id = ce.class_id
                          AND x.status = 'active'
                          AND (x.academic_year_id = {$yearId}
                               OR x.academic_year_id IS NULL
                               OR x.academic_year_id = 0)
                    )
                  )
                GROUP BY class_id";
    }
}
