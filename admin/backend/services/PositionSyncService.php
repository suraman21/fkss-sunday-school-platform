<?php
/**
 * Single source of truth for member ⇄ position assignments (format v2).
 *
 * Every writer that changes a member's responsibilities — the
 * registration form, the member edit form, the Super Admin hub — goes
 * through applyPositions(): it replaces the assignment rows, re-codes
 * the member through IdentityCodeService (v2 composition), derives the
 * legacy flag columns from staff_positions.legacy_flag (strangler
 * pattern) and refreshes the QR. Legacy entry points (Education dept
 * teacher assignment, approvals) call syncPositionFromFlag() so flags,
 * positions and codes always converge no matter where the change
 * started.
 *
 * Security & scale: prepared statements only; bounded IN-lists; the
 * whole change is one transaction with the audit row inside it.
 */
namespace App\Services;

use RuntimeException;

require_once __DIR__ . '/IdentityCodeService.php';
require_once __DIR__ . '/MemberCategory.php';
require_once __DIR__ . '/SecurityAuditService.php';

final class PositionSyncService
{
    /** Legacy member columns a position may drive (sql/020 mapping). */
    public const LEGACY_FLAGS = ['is_teacher', 'is_staff', 'is_committee', 'is_volunteer'];

    /**
     * Schema feature detection (progressive-rollout pattern): the
     * legacy_flag column ships with sql/020. Deployments that pulled the
     * code but have not run the migration yet must keep working — every
     * statement touching the column goes through this probe, cached per
     * process so it costs one indexed information_schema read per request.
     */
    private static ?bool $hasLegacyFlag = null;

    public static function hasLegacyFlag(\mysqli $conn): bool
    {
        if (self::$hasLegacyFlag !== null) {
            return self::$hasLegacyFlag;
        }
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'staff_positions'
                   AND COLUMN_NAME = 'legacy_flag'"
            );
            if (!$stmt) {
                return self::$hasLegacyFlag = false;
            }
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            return self::$hasLegacyFlag = $count > 0;
        } catch (\Throwable $e) {
            return self::$hasLegacyFlag = false;
        }
    }

    /**
     * Whether staff_positions.department_id accepts NULL (free positions
     * ship with sql/020). Cached, like hasLegacyFlag().
     */
    private static ?bool $deptNullable = null;

    public static function departmentNullable(\mysqli $conn): bool
    {
        if (self::$deptNullable !== null) {
            return self::$deptNullable;
        }
        try {
            $stmt = $conn->prepare(
                "SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'staff_positions'
                   AND COLUMN_NAME = 'department_id'"
            );
            if (!$stmt) {
                return self::$deptNullable = false;
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return self::$deptNullable = (($row['n'] ?? 'NO') === 'YES');
        } catch (\Throwable $e) {
            return self::$deptNullable = false;
        }
    }

    /**
     * Replace a member's position assignments and re-code.
     *
     * @param list<int|numeric-string> $positionIds
     * @return array{status:string,message?:string,member_code?:string}
     */
    public static function applyPositions(\mysqli $conn, int $memberId, array $positionIds, int $actorId = 0): array
    {
        if ($memberId <= 0) {
            return ['status' => 'error', 'message' => 'Invalid member.'];
        }
        $ids = [];
        foreach ($positionIds as $raw) {
            $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id && !in_array($id, $ids, true)) {
                $ids[] = (int)$id;
            }
        }

        $conn->begin_transaction();
        try {
            // Validate: only ACTIVE positions can be assigned.
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $conn->prepare(
                    "SELECT COUNT(*) AS c FROM staff_positions
                     WHERE is_active = 1 AND id IN ($placeholders)"
                );
                $types = str_repeat('i', count($ids));
                $refs = [];
                foreach ($ids as $i => $v) { $refs[] = &$ids[$i]; }
                $stmt->bind_param($types, ...$refs);
                $stmt->execute();
                $found = (int)$stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
                if ($found !== count($ids)) {
                    throw new RuntimeException('One or more positions are not active.');
                }
            }

            $stmt = $conn->prepare('SELECT member_code, age_group FROM members WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $member = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$member) {
                throw new RuntimeException('Member not found.');
            }
            $oldCode = (string)($member['member_code'] ?? '');

            $clear = $conn->prepare('DELETE FROM member_staff_positions WHERE member_id = ?');
            $clear->bind_param('i', $memberId);
            $clear->execute();
            $clear->close();

            if ($ids !== []) {
                $insert = $conn->prepare(
                    'INSERT IGNORE INTO member_staff_positions (member_id, position_id, assigned_by) VALUES (?, ?, ?)'
                );
                foreach ($ids as $id) {
                    $insert->bind_param('iii', $memberId, $id, $actorId);
                    $insert->execute();
                }
                $insert->close();
            }

            $newCode = self::computeCode($conn, $memberId, (string)($member['age_group'] ?? ''));

            $update = $conn->prepare('UPDATE members SET member_code = ? WHERE id = ?');
            $update->bind_param('si', $newCode, $memberId);
            $update->execute();
            $update->close();

            if ($newCode !== $oldCode && $oldCode !== '') {
                $legacy = $conn->prepare('UPDATE members SET legacy_member_code = ? WHERE id = ?');
                $legacy->bind_param('si', $oldCode, $memberId);
                $legacy->execute();
                $legacy->close();

                $log = $conn->prepare(
                    'INSERT INTO member_code_migrations (member_id, old_code, new_code, reason)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE old_code = VALUES(old_code), new_code = VALUES(new_code), reason = VALUES(reason)'
                );
                $reason = 'position_sync';
                $log->bind_param('isss', $memberId, $oldCode, $newCode, $reason);
                $log->execute();
                $log->close();
            }

            self::deriveFlags($conn, $memberId);

            // Member type is manually selected, independent of positions
            // and their derived flags. Do not reclassify it here.

            SecurityAuditService::record(
                $conn,
                'Member Positions Synced',
                ['position_ids' => $ids, 'old_code' => $oldCode, 'new_code' => $newCode],
                'member',
                $memberId
            );

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            return ['status' => 'error', 'message' => 'Unable to save positions.'];
        }

        // QR outside the transaction: best-effort, never blocks the write.
        require_once __DIR__ . '/../../id_cards/libs/qr_loader.php';
        if (class_exists('QRcode')) {
            IdentityCodeService::regenerateQr($conn, $memberId);
        }

        return ['status' => 'success', 'member_code' => $newCode];
    }

    /**
     * Compute the v2 code for a member from their CURRENT assignments:
     * staff composition when positions exist, category letter otherwise,
     * null (pending) when the category is unresolvable — never guessed.
     */
    public static function computeCode(\mysqli $conn, int $memberId, string $ageGroup): ?string
    {
        $segments = IdentityCodeService::resolveStaffSegments($conn, $memberId);
        if ($segments !== null && $segments !== []) {
            return IdentityCodeService::allocateStaff($conn, $segments);
        }
        $letter = MemberCategory::letterFor($ageGroup);
        if ($letter === null) {
            return null;
        }
        return IdentityCodeService::allocateStudent($conn, $letter);
    }

    /**
     * Rewrite the legacy flag columns from the member's held positions
     * (staff_positions.legacy_flag). Keeps every pre-v2 consumer
     * (Teacher dashboard, Education stats, workflow) working unchanged.
     */
    public static function deriveFlags(\mysqli $conn, int $memberId): void
    {
        if (!self::hasLegacyFlag($conn)) {
            return; // pre-sql/020 deployment: nothing to derive yet
        }
        $held = [];
        $stmt = $conn->prepare(
            "SELECT DISTINCT sp.legacy_flag AS flag
             FROM member_staff_positions msp
             JOIN staff_positions sp ON sp.id = msp.position_id
            WHERE msp.member_id = ? AND sp.is_active = 1 AND sp.legacy_flag IS NOT NULL"
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $held[(string)$row['flag']] = true;
        }
        $stmt->close();

        $sets = [];
        $values = [];
        foreach (self::LEGACY_FLAGS as $flag) {
            $sets[] = "$flag = ?";
            $values[] = isset($held[$flag]) ? 1 : 0;
        }
        $values[] = $memberId;
        $update = $conn->prepare('UPDATE members SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $types = str_repeat('i', count($values));
        $update->bind_param($types, ...$values);
        $update->execute();
        $update->close();
    }

    /**
     * Convergent writer for legacy entry points: grant/remove the
     * position mapped to $flag (e.g. Edu dept assigning a teacher) and
     * re-code so flags, positions and codes never diverge. No-op when
     * the school has not defined a mapped position yet.
     */
    public static function syncPositionFromFlag(\mysqli $conn, int $memberId, string $flag, bool $held, int $actorId = 0): void
    {
        if (!in_array($flag, self::LEGACY_FLAGS, true) || $memberId <= 0) {
            return;
        }
        if (!self::hasLegacyFlag($conn)) {
            return; // pre-sql/020: the mapping column does not exist yet
        }
        $stmt = $conn->prepare(
            'SELECT id FROM staff_positions WHERE legacy_flag = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1'
        );
        $stmt->bind_param('s', $flag);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return; // school hasn't mapped this flag to a position yet
        }
        $positionId = (int)$row['id'];

        $check = $conn->prepare('SELECT 1 FROM member_staff_positions WHERE member_id = ? AND position_id = ?');
        $check->bind_param('ii', $memberId, $positionId);
        $check->execute();
        $has = (bool)$check->get_result()->fetch_row();
        $check->close();

        if ($held && !$has) {
            $current = self::currentPositionIds($conn, $memberId);
            $current[] = $positionId;
            self::applyPositions($conn, $memberId, $current, $actorId);
        } elseif (!$held && $has) {
            $current = array_values(array_diff(self::currentPositionIds($conn, $memberId), [$positionId]));
            self::applyPositions($conn, $memberId, $current, $actorId);
        }
    }

    /** @return list<int> */
    public static function currentPositionIds(\mysqli $conn, int $memberId): array
    {
        $ids = [];
        $stmt = $conn->prepare('SELECT position_id FROM member_staff_positions WHERE member_id = ?');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int)$row['position_id'];
        }
        $stmt->close();
        return $ids;
    }

    /**
     * Grouped, active position catalogue for form pickers
     * (departments first, then free positions).
     *
     * @return array{departments:list<array>,free:list<array>}
     */
    public static function catalogue(\mysqli $conn): array
    {
        $departments = [];
        $free = [];
        $result = $conn->query(
            'SELECT p.id, p.role_code, p.title_am, p.title_en, p.department_id,
                    d.code AS dept_code, d.name_am AS dept_am
             FROM staff_positions p
             LEFT JOIN departments d ON d.id = p.department_id AND d.is_active = 1
             WHERE p.is_active = 1
             ORDER BY (p.department_id IS NULL) ASC, d.sort_order ASC,
                      p.sort_order ASC, p.id ASC'
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if ($row['department_id'] === null) {
                    $free[] = $row;
                } else {
                    $departments[] = $row;
                }
            }
            $result->free();
        }
        return ['departments' => $departments, 'free' => $free];
    }
}
