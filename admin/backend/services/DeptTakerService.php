<?php
/**
 * Department-owned attendance takers.
 *
 * Product rule (2026-08-28): each department owns its OWN attendance
 * takers and its OWN attendance data. They share the workflow, never
 * the accounts or the records:
 *
 *   • mezmur_dept  creates/manages  mezmur_attendance_taker
 *   • hr_dept      creates/manages  hr_attendance_taker
 *   • edu keeps the existing teacher + attendance_taker setup,
 *     untouched.
 *
 * Security model:
 *   - Role attribution is enforced HERE (service layer), never only
 *     in the UI. A department can only create/toggle/list its own
 *     taker type; admins manage both.
 *   - Advanced username validation: strict format, reserved names,
 *     case-insensitive uniqueness AND normalized-collision rejection
 *     (so "john.doe" cannot shadow "johndoe") — prevents account
 *     confusion/impersonation across departments.
 *   - Passwords hashed with password_hash; hashes never leave here.
 *   - Every create/toggle is audit-logged.
 */

namespace App\Services;

// Hardening (not a live defect): this service records an audit trail after
// the row has already been written. Its sole current caller does load the
// audit class, so the path is safe today -- but that is the exact shape of
// finding Q. Declared here so the guarantee does not depend on the caller.
require_once __DIR__ . '/SecurityAuditService.php';

class DeptTakerService
{
    public const ROLE_MEZMUR_TAKER = 'mezmur_attendance_taker';
    public const ROLE_HR_TAKER = 'hr_attendance_taker';

    private const ADMIN_ROLES = ['super_admin', 'school_admin'];

    /** Reserved username labels — nobody may register these. */
    private const RESERVED_USERNAMES = [
        'admin', 'administrator', 'admins', 'root', 'super', 'superadmin',
        'super_admin', 'schooladmin', 'school_admin', 'system', 'support',
        'moderator', 'official', 'fkss', 'wbws', 'arkeon', 'info',
        'info_dept', 'hr', 'mezmur', 'edu', 'finance', 'teacher',
        'attendance', 'taker', 'null', 'anonymous',
    ];

    /**
     * Which taker roles a creator role may manage. Departments see
     * exactly their own type; admins see both. Nobody else sees any.
     */
    public static function managedRoles(string $creatorRole): array
    {
        switch ($creatorRole) {
            case 'mezmur_dept':
                return [self::ROLE_MEZMUR_TAKER];
            case 'hr_dept':
                return [self::ROLE_HR_TAKER];
            case 'super_admin':
            case 'school_admin':
                return [self::ROLE_MEZMUR_TAKER, self::ROLE_HR_TAKER];
            default:
                return [];
        }
    }

    public static function roleLabel(string $role): string
    {
        return [
            self::ROLE_MEZMUR_TAKER => 'Mezmur Attendance Taker',
            self::ROLE_HR_TAKER => 'HR Attendance Taker',
        ][$role] ?? $role;
    }

    /**
     * Advanced username validation. Returns an error message, or null
     * when the username is safe to create.
     */
    public static function validateUsername(\mysqli $conn, string $username): ?string
    {
        $u = strtolower(trim($username));
        if ($u === '') {
            return 'Username is required.';
        }
        if (strlen($u) < 3 || strlen($u) > 30) {
            return 'Username must be 3–30 characters.';
        }
        if (!preg_match('/^[a-z][a-z0-9._]*[a-z0-9]$/', $u)) {
            return 'Use lowercase letters, numbers, dots and underscores only; it must start with a letter and end with a letter or number.';
        }
        if (strpos($u, '..') !== false || strpos($u, '._') !== false || strpos($u, '_.') !== false || strpos($u, '__') !== false) {
            return 'Username contains a confusing character sequence.';
        }
        if (in_array($u, self::RESERVED_USERNAMES, true)) {
            return 'That username is reserved.';
        }
        foreach (self::RESERVED_USERNAMES as $reserved) {
            if (strpos($u, $reserved) === 0 && strlen($u) <= strlen($reserved) + 4) {
                return 'That username is too close to a reserved name.';
            }
        }

        // Exact (case-insensitive) duplicate check.
        $stmt = $conn->prepare('SELECT id FROM users WHERE LOWER(username) = ? LIMIT 1');
        $stmt->bind_param('s', $u);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            return 'That username is already taken.';
        }

        // Normalized-collision check: "john.doe" vs "johndoe" / "john_doe".
        $normalized = str_replace(['.', '_', '-'], '', $u);
        $stmt = $conn->prepare('SELECT username FROM users');
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $existing = str_replace(['.', '_', '-'], '', strtolower((string)$row['username']));
                if ($existing === $normalized) {
                    return 'That username is too similar to an existing account (“' . $row['username'] . '”). Choose a clearly different one.';
                }
            }
        }
        return null;
    }

    /**
     * Create a department taker. $requestedRole must be one of the
     * creator's managed roles — enforced here, not in the UI.
     */
    public static function create(
        \mysqli $conn,
        array $auth,
        string $requestedRole,
        string $fullName,
        string $username,
        string $password
    ): array {
        // HR attendance was RETIRED (2026-10-07): no new HR taker
        // accounts are created. Existing accounts keep working (login,
        // retirement notice); reports live on the HR web dashboard.
        if ($requestedRole === self::ROLE_HR_TAKER) {
            return [
                'ok' => false,
                'message' => 'HR attendance was retired — no new HR taker accounts are created. Existing accounts keep working.',
            ];
        }
        $creatorRole = (string)($auth['role'] ?? '');
        $managed = self::managedRoles($creatorRole);
        if (!in_array($requestedRole, $managed, true)) {
            return ['ok' => false, 'message' => 'You can only create taker accounts for your own department.'];
        }

        $fullName = trim($fullName);
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
            return ['ok' => false, 'message' => 'Full name must be 2–100 characters.'];
        }
        // Delegates to the shared policy. This used to hardcode 12, which
        // silently diverged from PasswordPolicy the moment the floor moved:
        // the department screen would have refused a 6-character password
        // the API accepts, with a different message.
        $policyErrors = \App\Services\PasswordPolicy::errors($password);
        if ($policyErrors !== []) {
            return ['ok' => false, 'message' => $policyErrors[0]];
        }

        $usernameError = self::validateUsername($conn, $username);
        if ($usernameError !== null) {
            return ['ok' => false, 'message' => $usernameError];
        }
        $u = strtolower(trim($username));

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            'INSERT INTO users (username, full_name, role, password_hash, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->bind_param('ssss', $u, $fullName, $requestedRole, $hash);
        if (!$stmt->execute()) {
            // Unique race or constraint issue — never leak SQL detail.
            return ['ok' => false, 'message' => 'Could not create the account. The username may already exist.'];
        }
        $newId = (int)$stmt->insert_id;

        SecurityAuditService::record($conn, 'Dept Taker Created', [
            'taker_role' => $requestedRole,
            'username' => $u,
        ], 'user', $newId);

        return [
            'ok' => true,
            'message' => self::roleLabel($requestedRole) . ' account created.',
            'id' => $newId,
        ];
    }

    /** Toggle a taker active flag — departments only touch their own type. */
    public static function toggle(\mysqli $conn, array $auth, int $userId): array
    {
        $creatorRole = (string)($auth['role'] ?? '');
        $managed = self::managedRoles($creatorRole);
        if (empty($managed)) {
            return ['ok' => false, 'message' => 'You do not have permission to manage taker accounts.'];
        }

        $in = implode(',', array_fill(0, count($managed), '?'));
        $types = str_repeat('s', count($managed));
        $stmt = $conn->prepare("SELECT id, role, is_active FROM users WHERE id = ? AND role IN ($in) LIMIT 1");
        $stmt->bind_param('i' . $types, $userId, ...$managed);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            // Either missing or another department's taker — same answer,
            // so account existence across departments never leaks.
            return ['ok' => false, 'message' => 'That account is not one of your department’s takers.'];
        }

        $newState = ((int)$row['is_active'] === 1) ? 0 : 1;
        $upd = $conn->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $upd->bind_param('ii', $newState, $userId);
        $upd->execute();

        SecurityAuditService::record($conn, 'Dept Taker Toggled', [
            'taker_role' => $row['role'],
            'is_active' => $newState,
        ], 'user', $userId);

        return [
            'ok' => true,
            'message' => $newState ? 'Taker account activated.' : 'Taker account deactivated.',
            'is_active' => $newState,
        ];
    }

    /** List the creator's own takers only. */
    public static function listTakers(\mysqli $conn, array $auth): array
    {
        $managed = self::managedRoles((string)($auth['role'] ?? ''));
        if (empty($managed)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($managed), '?'));
        $types = str_repeat('s', count($managed));
        $stmt = $conn->prepare(
            "SELECT u.id, u.username, u.full_name, u.role, u.is_active, u.created_at
             FROM users u WHERE u.role IN ($in) ORDER BY u.created_at DESC"
        );
        $stmt->bind_param($types, ...$managed);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['is_active'] = (int)$row['is_active'];
                $row['role_label'] = self::roleLabel((string)$row['role']);
                $out[] = $row;
            }
        }
        return $out;
    }
}
