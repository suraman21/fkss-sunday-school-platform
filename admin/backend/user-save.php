<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/ProfileService.php';
require_once __DIR__ . '/services/AccountCredentialService.php';
require_once __DIR__ . '/services/SecurityAuditService.php';
require_once __DIR__ . '/services/SecurityRateLimiter.php';

// Detect if this is an AJAX request or a regular form submission
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (!$isAjax) {
    // Also detect fetch() calls — they send Accept: application/json or have no Referer form pattern
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'application/json') !== false) {
        $isAjax = true;
    }
}

// For AJAX: return JSON. For form POST: redirect back with flash message.
if ($isAjax) {
    header('Content-Type: application/json');
}

/**
 * Respond to the client — handles both AJAX (JSON) and form (redirect) requests
 */
function respond($status, $message, $extra = []) {
    global $isAjax;
    $data = array_merge(['status' => $status, 'message' => $message], $extra);
    
    if ($isAjax) {
        echo json_encode($data);
        exit;
    }
    
    // Form submission — redirect back with message in query string
    // super-admin.php and users.php both read $_GET['success'] and $_GET['error']
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $path = parse_url($referer, PHP_URL_PATH);
    $allowed = [ssms_app_url('admin/users.php'), ssms_app_url('admin/dashboard.php'),
        ssms_app_url('admin/dashboards/super-admin.php')];
    if (!is_string($path) || !in_array($path, $allowed, true)) {
        $path = ssms_app_url('admin/dashboards/super-admin.php');
    }
    $query = [];
    parse_str((string)(parse_url($referer, PHP_URL_QUERY) ?? ''), $query);
    unset($query['success'], $query['error']);
    $query[$status === 'success' ? 'success' : 'error'] = $message;
    $query['section'] = 'users';
    $redirect = $path . '?' . http_build_query($query);

    header('Location: ' . $redirect);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond('error', 'Invalid request method');
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    respond('error', 'Unauthorized');
}

$currentRole = $_SESSION['admin_role'] ?? '';



// CSRF protection — validate token from form or AJAX
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validateCsrf($csrfToken)) {
    respond('error', 'Security token expired. Please refresh the page and try again.');
}

foreach (['full_name','username','email','role','password','confirm_password','user_id','is_active','member_id'] as $field) {
    if (isset($_POST[$field]) && !is_string($_POST[$field])) respond('error', 'Invalid user data.');
}

// Collect inputs
$userId           = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$fullName         = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
$username         = isset($_POST['username']) ? trim($_POST['username']) : '';
$email            = isset($_POST['email']) ? trim($_POST['email']) : '';
$role             = isset($_POST['role']) ? trim($_POST['role']) : '';
$password         = isset($_POST['password']) ? $_POST['password'] : '';
$confirmPassword  = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
$isActive         = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$memberId         = isset($_POST['member_id']) && $_POST['member_id'] !== '' ? (int)$_POST['member_id'] : null;

// Valid roles and who can create them
$validRoles = [
    'super_admin',
    'school_admin',
    'info_dept',
    'hr_dept',
    'edu_dept',
    'finance_dept',
    'material_dept',
    'mezmur_dept',
    'teacher',
    'attendance_taker',
    // Website/CMS manager (2026-08-31): created by the super admin here;
    // content editors log in and land on the Website Content dashboard.
    'content_editor',
    // Department-owned takers (2026-08-28): departments create these
    // through api_dept_takers.php; admins may also create them here.
    'mezmur_attendance_taker',
    'hr_attendance_taker',
    // ACM console seat (S1): super_admin creates these here; member and
    // parent login roles are deliberately NOT listed — they are
    // provisioned only by approved activation tickets.
    'accounts_office',
];

// Role-based permissions for creating users
$rolePermissions = [
    'super_admin' => $validRoles, // Can create all
    'school_admin' => $validRoles, // Can create all
    'edu_dept' => ['teacher'], // Can only create teacher accounts
    // Department-owned attendance takers (mezmur_attendance_taker /
    // hr_attendance_taker) are created ONLY through the governed
    // api_dept_takers.php endpoint — the service layer enforces the
    // department attribution server-side. (The old entries that let
    // info/mezmur create shared attendance_takers here were dead code:
    // ROLE_MAP restricts this file to super_admin.)
];

// Check if current user can create this role
$allowedRolesToCreate = $rolePermissions[$currentRole] ?? [];

if (!in_array($role, $allowedRolesToCreate)) {
    respond('error', 'You do not have permission to create this type of user account.');
}

// Basic validation
if ($fullName === '' || $username === '' || $role === '') {
    respond('error', 'Full name, username and role are required.');
}

if (mb_strlen($fullName, 'UTF-8') > 100 || strlen($email) > 100
    || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
    || !in_array($isActive, [0, 1], true)) {
    respond('error', 'Check the name, email address and account status.');
}
if ($userId === (int)($_SESSION['admin_id'] ?? 0)
    && ($role !== $currentRole || $isActive !== 1)) {
    respond('error', 'You cannot disable your own account or change your own role.');
}

// Username format validation
$usernameError = validateUsername($username);
if ($usernameError) {
    respond('error', $usernameError);
}

if (!in_array($role, $validRoles, true)) {
    respond('error', 'Invalid role selected.');
}

// Password rules
$isCreating = ($userId === 0);

if ($isCreating) {
    if ($password === '') {
        respond('error', 'Password is required for new users.');
    }
}

// Password strength validation
if ($password !== '') {
    $pwErrors = validatePassword($password);
    if (!empty($pwErrors)) {
        respond('error', implode(' ', $pwErrors));
    }
}

if ($password !== '' && !hash_equals($password, $confirmPassword)) {
    respond('error', 'Passwords do not match.');
}


// Email: empty -> NULL
$emailDb = $email !== '' ? $email : null;

// Consume administrator-reset buckets before any profile fields are written.
if (!$isCreating && $password !== '') {
    $limiter = new \App\Services\SecurityRateLimiter(
        $pdo instanceof \PDO ? $pdo : null,
        ROOT_PATH . '/admin/uploads/cache'
    );
    $actorId = (int)($_SESSION['admin_id'] ?? 0);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateResults = [
        $limiter->consume('web-admin-password-reset-user', 'user:' . $actorId, 10, 3600),
        $limiter->consume('web-admin-password-reset-ip', 'ip:' . $ip, 10, 3600),
    ];
    foreach ($rateResults as $rate) {
        if (!$rate['allowed']) {
            if (!headers_sent()) {
                header('Retry-After: ' . max(1, (int)$rate['retry_after']));
            }
            respond('error', 'Too many password reset attempts. Please try again later.', [
                'code' => 'RATE_LIMITED',
                'retry_after' => max(1, (int)$rate['retry_after']),
            ]);
        }
    }
}

try {
    // Check uniqueness of username/email
    if ($emailDb !== null) {
        $stmt = $pdo->prepare("
            SELECT id FROM users
            WHERE (username = :username OR email = :email)
            " . ($userId > 0 ? "AND id != :id" : "") . "
            LIMIT 1
        ");
        $params = [':username' => $username, ':email' => $emailDb];
        if ($userId > 0) $params[':id'] = $userId;
        $stmt->execute($params);
    } else {
        $stmt = $pdo->prepare("
            SELECT id FROM users
            WHERE username = :username
            " . ($userId > 0 ? "AND id != :id" : "") . "
            LIMIT 1
        ");
        $params = [':username' => $username];
        if ($userId > 0) $params[':id'] = $userId;
        $stmt->execute($params);
    }

    $existing = $stmt->fetch();
    if ($existing) {
        respond('error', 'Username or email already exists.');
    }

    if ($isCreating) {
        // Create new user
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, full_name, role, password_hash, is_active, member_id)
            VALUES (:username, :email, :full_name, :role, :password_hash, :is_active, :member_id)
        ");

        $stmt->execute([
            ':username'      => $username,
            ':email'         => $emailDb,
            ':full_name'     => $fullName,
            ':role'          => $role,
            ':password_hash' => $passwordHash,
            ':is_active'     => $isActive,
            ':member_id'     => $memberId,
        ]);

        $newUserId = $pdo->lastInsertId();

        respond('success', 'User created successfully.', ['user_id' => $newUserId]);

    } else {
        // Update existing user
        // Capture the previous account state first: teacher accounts carry a
        // suspend/restore lifecycle (assignments pause and come back), and the
        // super admin must get the same behaviour here as the teacher screens.
        $prev = $pdo->prepare("SELECT role, is_active FROM users WHERE id = :id LIMIT 1");
        $prev->execute([':id' => $userId]);
        $prevRow = $prev->fetch();
        if (!$prevRow) respond('error', 'User not found.');

        // S8: never resurrect a revoked (deactivated) ACM link
        // via the edit form (same guard as user-toggle.php:
        // gates read only the bit). Re-entry is a FRESH
        // activation ticket, never a revive.
        if ((int)$prevRow['is_active'] === 0 && (int)$isActive === 1) {
            try {
                $acmGuard = $pdo->prepare('SELECT status FROM acm_person_accounts WHERE user_id = :uid LIMIT 1');
                $acmGuard->execute([':uid' => $userId]);
                if ($acmGuard->fetchColumn() === 'revoked') {
                    respond('error', 'This account was deactivated and cannot be re-enabled; start a fresh activation.');
                }
            } catch (PDOException $e) {
                // 42S02/42S22: pre-ACM deployment — proceed.
                if ($e->getCode() !== '42S02' && $e->getCode() !== '42S22') {
                    respond('error', 'Could not verify account links.');
                }
            }
        }

        // One account mutation shared by the ordinary update path and the
        // credential service's transaction-aware administrator reset path.
        // Every statement here uses the same mysqli connection as the
        // credential repository and throws before that transaction can commit.
        $applyAccountMutation = static function () use (
            $conn,
            $fullName,
            $username,
            $emailDb,
            $role,
            $isActive,
            $memberId,
            $userId
        ): void {
            $statement = $conn->prepare(
                'UPDATE users
                    SET full_name = ?, username = ?, email = ?, role = ?,
                        is_active = ?, member_id = ?
                  WHERE id = ?'
            );
            if (!$statement) {
                throw new \App\Services\CredentialPersistenceException(
                    'Could not prepare account update.'
                );
            }
            $statement->bind_param(
                'ssssiii',
                $fullName,
                $username,
                $emailDb,
                $role,
                $isActive,
                $memberId,
                $userId
            );
            $updated = $statement->execute();
            $statement->close();
            if (!$updated) {
                throw new \App\Services\CredentialPersistenceException(
                    'Could not update account.'
                );
            }
        };

        $administrator = null;
        $credentialResult = null;
        if ($password !== '') {
            try {
                $administrator = \App\Services\AuthorizedAdministratorIdentity::fromTrustedAuthorization(
                    (int)($_SESSION['admin_id'] ?? 0)
                );
                $credentialService = new \App\Services\AccountCredentialService(
                    new \App\Services\MysqliCredentialRepository($conn)
                );
                $credentialResult = $credentialService
                    ->resetPasswordByAdministratorWithAccountMutation(
                        $administrator,
                        (int)$userId,
                        $password,
                        $confirmPassword,
                        $applyAccountMutation
                    );
            } catch (\App\Services\CredentialDomainException $error) {
                if ($error->reason() === 'USER_NOT_FOUND') {
                    respond('error', 'User not found.');
                }
                respond('error', 'Password reset request was rejected.', [
                    'code' => $error->reason(),
                ]);
            }
        } else {
            $applyAccountMutation();
        }

        // Browser-session state changes only after the database transaction has
        // committed, so rollback cannot leave the session ahead of the account.
        $isCurrentAdministrator = (int)$userId === (int)($_SESSION['admin_id'] ?? 0);
        $currentUsernameChanged = $isCurrentAdministrator
            && !hash_equals((string)($_SESSION['admin_username'] ?? ''), $username);
        if ($isCurrentAdministrator) {
            $_SESSION['admin_username'] = $username;
            $_SESSION['admin_full_name'] = $fullName;
            $_SESSION['AUTH_REVALIDATED_AT'] = time();
            if ($credentialResult instanceof \App\Services\CredentialMutationResult) {
                $_SESSION['AUTH_PASSWORD_VERSION'] = $credentialResult->passwordVersion();
            }
            if ($currentUsernameChanged || $credentialResult !== null) {
                session_regenerate_id(true);
            }
        }

        // Audit only safe result metadata after commit. Passwords, hashes,
        // tokens, and session identifiers never enter the event.
        if ($credentialResult instanceof \App\Services\CredentialMutationResult
            && $administrator instanceof \App\Services\AuthorizedAdministratorIdentity) {
            $actor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
                $administrator->actorUserId(),
                (string)($_SESSION['admin_username'] ?? ''),
                'web',
                $_SERVER
            );
            \App\Services\SecurityAuditService::recordTrusted(
                $conn,
                $actor,
                'ADMIN_PASSWORD_RESET',
                $credentialResult->auditContext(),
                'user',
                (int)$userId
            );
        }

        // Preserve the established teacher/member convergence after the atomic
        // account+credential commit. syncMemberTeacherFlag may invoke its own
        // transaction, so it must not run inside the credential transaction.
        $wasActive = (int)($prevRow['is_active'] ?? 1) === 1;
        if ($role === 'teacher' && isset($conn) && ($conn instanceof mysqli)) {
            require_once __DIR__ . '/services/AssignmentService.php';
            require_once __DIR__ . '/member_sync.php';
            if ($isActive === 1 && !$wasActive) {
                $ids = \App\Services\AssignmentService::latestSuspensionSnapshot(
                    $conn,
                    (int)$userId
                );
                $restored = \App\Services\AssignmentService::restoreTeacherAssignments(
                    $conn,
                    (int)$userId,
                    $ids
                );
                \App\Services\SecurityAuditService::record(
                    $conn,
                    'Teacher Reactivated',
                    ['restored_assignments' => $restored, 'from_snapshot' => $ids, 'via' => 'user-save'],
                    'user',
                    (int)$userId
                );
                if ($memberId) {
                    syncMemberTeacherFlag($conn, (int)$memberId, true);
                }
            } elseif ($isActive === 0 && $wasActive) {
                $ids = \App\Services\AssignmentService::suspendTeacherAssignments(
                    $conn,
                    (int)$userId
                );
                \App\Services\SecurityAuditService::record(
                    $conn,
                    'Teacher Suspended',
                    ['assignment_ids' => $ids, 'via' => 'user-save'],
                    'user',
                    (int)$userId
                );
                if ($memberId) {
                    syncMemberTeacherFlag($conn, (int)$memberId, false);
                }
            }
        }

        respond('success', 'User updated successfully.');
    }

} catch (Exception $e) {
    error_log("User save error: " . $e->getMessage());
    respond('error', 'Error saving user. Please try again.');
}
