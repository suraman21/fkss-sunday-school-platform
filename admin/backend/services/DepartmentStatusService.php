<?php
/**
 * Department status periods (Attendance alerting — Phase 1).
 *
 * A member is active or inactive PER DEPARTMENT (Education / Mezmur),
 * independently of the global `members.status` (which remains a manual
 * HR / Information decision). Periods live in `member_department_periods`:
 *
 *   - no row for (member, department)  =>  active (no back-fill required);
 *   - the open period (ended_at IS NULL) is the current status;
 *   - every change closes the open period and opens a new one, so a
 *     reactivation always starts a fresh period and a consecutive-absence
 *     streak can never bridge an inactive gap (requirements §11).
 *
 * Day semantics (used by rosters and, in Phase 2, by eligibility):
 *   inactive on day D  <=>  an inactive period has started_at < D+1
 *                           and (ended_at IS NULL or ended_at >= D+1).
 *   A member reactivated during D therefore counts as active on D.
 *
 * Everything degrades gracefully: when FEATURE_ATTENDANCE_V2 is off or the
 * table has not been created yet (owner runs sql/071 in phpMyAdmin), the
 * service reports `available() === false`, every member is "active" and
 * `exclusionSql()` returns '' so rosters behave exactly as before.
 */
namespace App\Services;

require_once __DIR__ . '/FeatureGate.php';
require_once __DIR__ . '/SecurityAuditService.php';
require_once __DIR__ . '/AssessmentFollowupService.php';

final class DepartmentStatusService
{
    public const DEPARTMENTS = ['edu', 'mezmur'];
    public const STATUSES = ['active', 'inactive'];
    public const SOURCES = ['manual', 'auto', 'rollout'];
    public const TABLE = 'member_department_periods';
    public const LABELS = ['edu' => 'Education', 'mezmur' => 'Mezmur'];

    /** @var array<int,bool> keyed by spl_object_id of the connection */
    private static array $tableCache = [];

    // ── availability ──────────────────────────────────────────────

    public static function enabled(): bool
    {
        return FeatureGate::isEnabled('attendance_v2');
    }

    public static function tableExists(\mysqli $conn): bool
    {
        $key = spl_object_id($conn);
        if (!array_key_exists($key, self::$tableCache)) {
            $exists = false;
            try {
                $res = $conn->query("SHOW TABLES LIKE '" . self::TABLE . "'");
                $exists = $res && $res->num_rows > 0;
            } catch (\Throwable $e) {
                $exists = false;
            }
            self::$tableCache[$key] = $exists;
        }
        return self::$tableCache[$key];
    }

    /** Feature flag on AND schema applied. */
    public static function available(\mysqli $conn): bool
    {
        return self::enabled() && self::tableExists($conn);
    }

    /** Test hook: forget the cached table lookup (e.g. after creating the table). */
    public static function resetCache(): void
    {
        self::$tableCache = [];
    }

    public static function isDepartment(string $dept): bool
    {
        return in_array($dept, self::DEPARTMENTS, true);
    }

    // ── reads ─────────────────────────────────────────────────────

    /**
     * @return array{department:string,status:string,since:?string,period_id:?int,reason:?string,source:?string,changed_by:?int}
     */
    public static function currentStatus(\mysqli $conn, int $memberId, string $dept): array
    {
        $base = ['department' => $dept, 'status' => 'active', 'since' => null, 'period_id' => null,
                 'reason' => null, 'source' => null, 'changed_by' => null];
        if (!self::isDepartment($dept) || $memberId <= 0 || !self::available($conn)) {
            return $base;
        }
        $stmt = $conn->prepare(
            'SELECT id, status, started_at, reason, source, changed_by FROM ' . self::TABLE .
            ' WHERE member_id = ? AND department = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1'
        );
        if (!$stmt) {
            return $base;
        }
        $stmt->bind_param('is', $memberId, $dept);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return $base;
        }
        return [
            'department' => $dept,
            'status' => (string)$row['status'],
            'since' => (string)$row['started_at'],
            'period_id' => (int)$row['id'],
            'reason' => $row['reason'] !== null ? (string)$row['reason'] : null,
            'source' => (string)$row['source'],
            'changed_by' => $row['changed_by'] !== null ? (int)$row['changed_by'] : null,
        ];
    }

    /** @return array<string,array> both departments keyed by code */
    public static function statuses(\mysqli $conn, int $memberId): array
    {
        $out = [];
        foreach (self::DEPARTMENTS as $dept) {
            $out[$dept] = self::currentStatus($conn, $memberId, $dept);
        }
        return $out;
    }

    /**
     * Bulk current statuses for many members (one query per department).
     * @param int[] $memberIds
     * @return array<int,array<string,string>> member_id => [dept => status]
     */
    public static function statusesFor(\mysqli $conn, array $memberIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn($i) => $i > 0)));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['edu' => 'active', 'mezmur' => 'active'];
        }
        if ($ids === [] || !self::available($conn)) {
            return $out;
        }
        $chunks = array_chunk($ids, 500);
        foreach ($chunks as $chunk) {
            $in = implode(',', $chunk);
            $res = $conn->query(
                'SELECT member_id, department, status FROM ' . self::TABLE .
                " WHERE ended_at IS NULL AND member_id IN ($in)"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $out[(int)$row['member_id']][(string)$row['department']] = (string)$row['status'];
                }
            }
        }
        return $out;
    }

    public static function isActiveOn(\mysqli $conn, int $memberId, string $dept, string $date): bool
    {
        if (!self::isDepartment($dept) || $memberId <= 0 || !self::available($conn)) {
            return true;
        }
        $next = self::nextDay($date);
        if ($next === null) {
            return true;
        }
        $stmt = $conn->prepare(
            'SELECT 1 FROM ' . self::TABLE .
            " WHERE member_id = ? AND department = ? AND status = 'inactive'
               AND started_at < ? AND (ended_at IS NULL OR ended_at >= ?) LIMIT 1"
        );
        if (!$stmt) {
            return true;
        }
        $stmt->bind_param('isss', $memberId, $dept, $next, $next);
        $stmt->execute();
        $inactive = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return !$inactive;
    }

    /**
     * Active date ranges inside [$from, $to] (inclusive, Y-m-d). Used by the
     * Phase 2 risk engine to restrict eligible sessions to active periods.
     * @return array<int,array{from:string,to:string}>
     */
    public static function activePeriods(\mysqli $conn, int $memberId, string $dept, string $from, string $to): array
    {
        if ($from > $to) {
            return [];
        }
        if (!self::isDepartment($dept) || $memberId <= 0 || !self::available($conn)) {
            return [['from' => $from, 'to' => $to]];
        }
        $toNext = self::nextDay($to) ?? $to;
        $stmt = $conn->prepare(
            'SELECT started_at, ended_at FROM ' . self::TABLE .
            " WHERE member_id = ? AND department = ? AND status = 'inactive'
               AND started_at < ? AND (ended_at IS NULL OR ended_at >= ?)
             ORDER BY started_at"
        );
        if (!$stmt) {
            return [['from' => $from, 'to' => $to]];
        }
        $stmt->bind_param('isss', $memberId, $dept, $toNext, $from);
        $stmt->execute();
        $res = $stmt->get_result();
        $ranges = [];
        $cursor = $from;
        while ($row = $res->fetch_assoc()) {
            $inFrom = substr((string)$row['started_at'], 0, 10);            // first inactive day
            $inTo = $row['ended_at'] === null ? null : substr((string)$row['ended_at'], 0, 10); // reactivation day (active again)
            if ($inFrom > $cursor) {
                $ranges[] = ['from' => $cursor, 'to' => self::prevDay(min($inFrom, self::nextDay($to) ?? $to))];
            }
            if ($inTo === null) {
                $cursor = self::nextDay($to) ?? $to; // inactive through the end
                break;
            }
            // Same-day reactivation (inTo == inFrom) leaves the day active.
            $cursor = max($cursor, $inTo);
        }
        $stmt->close();
        if ($cursor <= $to) {
            $ranges[] = ['from' => $cursor, 'to' => $to];
        }
        return array_values(array_filter($ranges, static fn($r) => $r['from'] <= $r['to']));
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function history(\mysqli $conn, int $memberId, ?string $dept = null, int $limit = 100): array
    {
        if ($memberId <= 0 || !self::available($conn)) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT p.id, p.department, p.status, p.started_at, p.ended_at, p.source, p.reason,
                       p.changed_by, p.warning_id, u.full_name AS changed_by_name
                FROM ' . self::TABLE . ' p
                LEFT JOIN users u ON u.id = p.changed_by
                WHERE p.member_id = ?';
        $types = 'i';
        $params = [$memberId];
        if ($dept !== null && self::isDepartment($dept)) {
            $sql .= ' AND p.department = ?';
            $types .= 's';
            $params[] = $dept;
        }
        $sql .= " ORDER BY p.started_at DESC, p.id DESC LIMIT $limit";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['changed_by'] = $row['changed_by'] !== null ? (int)$row['changed_by'] : null;
            $row['warning_id'] = $row['warning_id'] !== null ? (int)$row['warning_id'] : null;
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    // ── writes ────────────────────────────────────────────────────

    /**
     * Set the department status. Closes the open period and opens a new one.
     * Idempotent: setting the current status again changes nothing.
     *
     * @return array{changed:bool,status:string,previous:string,period_id:?int,message?:string}
     * @throws \DomainException on validation errors, \RuntimeException when unavailable
     */
    public static function setStatus(
        \mysqli $conn,
        int $memberId,
        string $dept,
        string $status,
        int $actorId,
        string $reason,
        string $source = 'manual',
        ?int $warningId = null,
        ?SecurityAuditActor $auditActor = null,
        bool $withinTransaction = false
    ): array {
        if (!self::available($conn)) {
            throw new \RuntimeException('Department status is not available yet (feature disabled or sql/071 not applied).');
        }
        if (!self::isDepartment($dept)) {
            throw new \DomainException('Unknown department.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new \DomainException('Status must be active or inactive.');
        }
        if (!in_array($source, self::SOURCES, true)) {
            throw new \DomainException('Unknown source.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }
        if ($source === 'manual' && mb_strlen($reason) < 3) {
            throw new \DomainException('A reason (at least 3 characters) is required.');
        }
        if ($memberId <= 0) {
            throw new \DomainException('Invalid member.');
        }

        // Callers that already hold a transaction pass $withinTransaction=true
        // (a nested START TRANSACTION would implicitly commit theirs).
        $ownTx = !$withinTransaction;
        if ($ownTx) {
            $conn->begin_transaction();
        }
        try {
            $lock = $conn->prepare('SELECT id, status FROM members WHERE id = ? FOR UPDATE');
            if (!$lock) {
                throw new \RuntimeException('Member lookup failed.');
            }
            $lock->bind_param('i', $memberId);
            $lock->execute();
            $member = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$member) {
                throw new \DomainException('Member not found.');
            }
            if ((string)$member['status'] === 'archived') {
                throw new \DomainException('Archived members cannot change department status.');
            }

            $open = $conn->prepare(
                'SELECT id, status FROM ' . self::TABLE .
                ' WHERE member_id = ? AND department = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $open->bind_param('is', $memberId, $dept);
            $open->execute();
            $current = $open->get_result()->fetch_assoc();
            $open->close();
            $previous = $current ? (string)$current['status'] : 'active';

            if ($previous === $status) {
                if ($ownTx) {
                    $conn->commit();
                }
                return ['changed' => false, 'status' => $status, 'previous' => $previous,
                        'period_id' => $current ? (int)$current['id'] : null,
                        'message' => 'Already ' . $status . '.'];
            }

            if ($current) {
                $close = $conn->prepare('UPDATE ' . self::TABLE . ' SET ended_at = NOW() WHERE id = ? AND ended_at IS NULL');
                $closeId = (int)$current['id'];
                $close->bind_param('i', $closeId);
                $close->execute();
                $close->close();
            }
            $ins = $conn->prepare(
                'INSERT INTO ' . self::TABLE .
                ' (member_id, department, status, started_at, ended_at, source, changed_by, reason, warning_id)
                  VALUES (?, ?, ?, NOW(), NULL, ?, ?, ?, ?)'
            );
            $changedBy = $actorId > 0 ? $actorId : null;
            $reasonOrNull = $reason !== '' ? $reason : null;
            $ins->bind_param('isssisi', $memberId, $dept, $status, $source, $changedBy, $reasonOrNull, $warningId);
            $ins->execute();
            $periodId = (int)$conn->insert_id;
            $ins->close();

            if ($ownTx) {
                $conn->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $conn->rollback();
            }
            throw $e;
        }

        $details = [
            'member_id' => $memberId,
            'department' => $dept,
            'from' => $previous,
            'to' => $status,
            'reason' => $reason,
            'source' => $source,
            'period_id' => $periodId,
        ];
        if ($warningId !== null) {
            $details['warning_id'] = $warningId;
        }
        try {
            if ($auditActor !== null) {
                SecurityAuditService::recordTrusted($conn, $auditActor, 'Department Status Changed', $details, 'member', $memberId);
            } else {
                SecurityAuditService::record($conn, 'Department Status Changed', $details, 'member', $memberId);
            }
        } catch (\Throwable $e) {
            error_log('Department status audit failed: ' . $e->getMessage());
        }

        // Attendance alerting Phase 5 (requirements §11–§12): an EDUCATION
        // reactivation opens follow-ups for the assessments missed during
        // the inactive period that was just closed ($current). Runs after the
        // commit, never throws, no-op until FEATURE_ATTENDANCE_V2 + sql/075.
        $followups = null;
        if ($dept === 'edu' && $status === 'active' && $previous === 'inactive' && $current && class_exists('\\App\\Services\\AssessmentFollowupService')) {
            $followups = AssessmentFollowupService::safeOnEducationReactivation($conn, $memberId, (int)$current['id'], $actorId, $auditActor);
        }

        return ['changed' => true, 'status' => $status, 'previous' => $previous, 'period_id' => $periodId, 'followups' => $followups];
    }

    // ── SQL helpers for rosters / denominators ────────────────────

    /**
     * SQL fragment (starting with " AND ") that excludes members inactive in
     * $dept on $onDate (default today). $memberIdExpr is the column holding
     * the member id in the outer query (e.g. "m.id", "ce.member_id").
     * Legacy default returns '' when unavailable. Opt-in preparation requires
     * an explicit valid date and complete eligibility schema when enabled.
     */
    public static function exclusionSql(\mysqli $conn, string $dept, string $memberIdExpr, ?string $onDate = null, bool $strictPreparation = false): string
    {
        return self::buildExclusionSql($conn, $dept, $memberIdExpr, $onDate, $strictPreparation, true);
    }

    /** Strict batch compiler; local schema proof only, no static cache/grant. */
    public static function preparationExclusions(\mysqli $conn, string $dept, string $memberIdExpr, array $dates): array
    {
        if (!$dates || !array_is_list($dates)) {
            throw new \InvalidArgumentException('Explicit preparation dates are required.');
        }
        $out = []; $probe = true;
        foreach ($dates as $date) {
            if (!is_string($date) || isset($out[$date])) {
                throw new \InvalidArgumentException('Unique explicit preparation dates are required.');
            }
            $out[$date] = self::buildExclusionSql($conn, $dept, $memberIdExpr, $date, true, $probe);
            $probe = false;
        }
        return $out;
    }

    /** One existing eligibility predicate for legacy/single-date/batch paths. */
    private static function buildExclusionSql(\mysqli $conn, string $dept, string $memberIdExpr, ?string $onDate, bool $strictPreparation, bool $probeColumns): string
    {
        if ($strictPreparation) {
            if (!self::isDepartment($dept) || $onDate === null
                || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $onDate)
                || !checkdate((int)substr($onDate, 5, 2), (int)substr($onDate, 8, 2), (int)substr($onDate, 0, 4))) {
                throw new \InvalidArgumentException('Explicit valid department and date are required for preparation.');
            }
        }
        if (!self::isDepartment($dept) || !($strictPreparation ? self::enabled() : self::available($conn))) {
            return '';
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $memberIdExpr)) {
            throw new \InvalidArgumentException('Unsafe member id expression.');
        }
        if ($strictPreparation && $probeColumns) {
            // Do not trust a permissive cached availability result. Probe every
            // eligibility column and surface failure in either mysqli mode.
            $stmt = null;
            try {
                $stmt = $conn->prepare('SELECT member_id, department, status, started_at, ended_at FROM ' . self::TABLE . ' LIMIT 0');
                if (!$stmt || !$stmt->execute() || !$stmt->get_result()) {
                    throw new \RuntimeException('Eligibility query failed.');
                }
            } catch (\Throwable $error) {
                throw new \RuntimeException('Preparation eligibility is unavailable.', 0, $error);
            } finally {
                if ($stmt instanceof \mysqli_stmt) $stmt->close();
            }
        }
        $next = self::nextDay($onDate ?? date('Y-m-d')) ?? date('Y-m-d', strtotime('tomorrow'));
        if ($strictPreparation && !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $next)) {
            throw new \RuntimeException('Preparation eligibility date cannot be represented.');
        }
        $nextSql = $conn->real_escape_string($next);
        return " AND NOT EXISTS (SELECT 1 FROM " . self::TABLE . " dsp
                     WHERE dsp.member_id = {$memberIdExpr}
                       AND dsp.department = '{$dept}'
                       AND dsp.status = 'inactive'
                       AND dsp.started_at < '{$nextSql}'
                       AND (dsp.ended_at IS NULL OR dsp.ended_at >= '{$nextSql}'))";
    }

    /** Count of members currently inactive in a department (0 when unavailable). */
    public static function inactiveCount(\mysqli $conn, string $dept): int
    {
        if (!self::isDepartment($dept) || !self::available($conn)) {
            return 0;
        }
        $stmt = $conn->prepare(
            'SELECT COUNT(DISTINCT p.member_id) FROM ' . self::TABLE . " p
             JOIN members m ON m.id = p.member_id AND m.status != 'archived'
             WHERE p.department = ? AND p.status = 'inactive' AND p.ended_at IS NULL"
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $dept);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return (int)($row[0] ?? 0);
    }

    // ── date helpers ──────────────────────────────────────────────

    private static function nextDay(string $date): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $ts = strtotime($date . ' +1 day');
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    private static function prevDay(string $date): string
    {
        $ts = strtotime($date . ' -1 day');
        return $ts === false ? $date : date('Y-m-d', $ts);
    }
}
