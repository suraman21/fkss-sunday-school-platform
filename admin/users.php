<?php
require_once __DIR__ . '/config.php';

// Only logged-in super admin is allowed here
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    echo 'Access denied. Only super admin can manage users.';
    exit;
}

$fullName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Super Admin';
$username = $_SESSION['admin_username'] ?? '';



// messages
$successMessage = isset($_GET['success']) ? $_GET['success'] : '';
$errorMessage   = isset($_GET['error']) ? $_GET['error'] : '';

// old form data (after failed submit)
$oldForm = $_SESSION['user_form_old'] ?? null;
unset($_SESSION['user_form_old']);

// editing?
$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editUser = null;

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editId]);
    $editUser = $stmt->fetch();
}

// delete confirm?
$deleteId = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;
$deleteUser = null;
if ($deleteId > 0) {
    $stmt = $pdo->prepare("SELECT id, username, full_name, role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $deleteId]);
    $deleteUser = $stmt->fetch();
}

// list all users
$stmt = $pdo->query("SELECT id, username, email, full_name, role, is_active, created_at FROM users ORDER BY id ASC");
$users = $stmt->fetchAll();

// helpers to decide form values (priority: oldForm -> editUser -> default)
function field_value($key, $editUser, $oldForm, $default = '')
{
    if ($oldForm !== null && array_key_exists($key, $oldForm)) {
        return $oldForm[$key];
    }
    if ($editUser !== null && array_key_exists($key, $editUser)) {
        return $editUser[$key];
    }
    return $default;
}

$currentRole = field_value('role', $editUser, $oldForm, 'school_admin');
$currentStatus = (int) field_value('is_active', $editUser, $oldForm, 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management - <?= SCHOOL_NAME_SHORT ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            background: #0f172a;
            color: #e5e7eb;
        }

        .sidebar {
            width: 240px;
            background: #020617;
            padding: 1.5rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .brand-logo {
            width: 38px;
            height: 38px;
            border-radius: 999px;
            background: linear-gradient(135deg, #16a34a, #22c55e);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #ffffff;
            font-size: 1rem;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
        }

        .brand-text span:first-child {
            font-size: 0.95rem;
            font-weight: 600;
        }

        .brand-text span:last-child {
            font-size: 0.75rem;
            color: #6b7280;
        }

        .nav-section-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6b7280;
            margin-bottom: 0.35rem;
        }

        .nav-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .nav-item {
            font-size: 0.85rem;
        }

        .nav-link {
            display: block;
            padding: 0.55rem 0.7rem;
            border-radius: 0.6rem;
            color: #9ca3af;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }

        .nav-link:hover {
            background: #020617;
            color: #e5e7eb;
        }

        .nav-link.active {
            background: linear-gradient(135deg, #16a34a, #22c55e);
            color: #ffffff;
        }

        .sidebar-footer {
            margin-top: auto;
            font-size: 0.8rem;
            color: #6b7280;
        }

        .main {
            flex: 1;
            padding: 1.5rem 1.75rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .topbar-title h1 {
            font-size: 1.25rem;
        }

        .topbar-title span {
            font-size: 0.8rem;
            color: #9ca3af;
        }

        .user-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            background: #020617;
            border: 1px solid #1f2937;
            font-size: 0.8rem;
            color: #e5e7eb;
        }

        .user-avatar {
            width: 26px;
            height: 26px;
            border-radius: 999px;
            background: #16a34a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .btn-secondary {
            display:inline-block;
            padding:0.45rem 0.9rem;
            border-radius:999px;
            font-size:0.8rem;
            font-weight:500;
            text-decoration:none;
            border:1px solid #22c55e;
            color:#bbf7d0;
            background:#022c22;
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.5fr);
            gap: 1rem;
        }

        @media (max-width: 960px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: #020617;
            border-radius: 0.9rem;
            padding: 1rem;
            border: 1px solid #1f2937;
        }

        .card h2 {
            font-size: 0.95rem;
            margin-bottom: 0.6rem;
        }

        .card p {
            font-size: 0.8rem;
            color: #9ca3af;
            margin-bottom: 0.75rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            margin-bottom: 0.6rem;
        }

        .form-group label {
            font-size: 0.8rem;
            color: #d1d5db;
        }

        .form-group input,
        .form-group select {
            padding: 0.5rem 0.7rem;
            border-radius: 0.6rem;
            border: 1px solid #1f2937;
            background: #030712;
            color: #e5e7eb;
            font-size: 0.85rem;
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #22c55e;
            box-shadow: 0 0 0 1px rgba(34,197,94,0.4);
        }

        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-wrapper input {
            width: 100%;
            padding-right: 2.3rem;
        }

        .toggle-pass {
            position: absolute;
            right: 0.45rem;
            border: none;
            background: none;
            color: #9ca3af;
            font-size: 0.78rem;
            cursor: pointer;
        }

        .btn-primary {
            padding: 0.55rem 1rem;
            border-radius: 999px;
            border: none;
            background: linear-gradient(135deg, #16a34a, #22c55e);
            color: #ffffff;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
        }

        .tag {
            display: inline-block;
            padding: 0.25rem 0.55rem;
            border-radius: 999px;
            font-size: 0.7rem;
            background: #022c22;
            color: #6ee7b7;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        th, td {
            padding: 0.5rem;
            text-align: left;
            border-bottom: 1px solid #111827;
        }

        th {
            color: #9ca3af;
            font-weight: 500;
        }

        .badge-active {
            display:inline-block;
            padding:0.15rem 0.5rem;
            border-radius:999px;
            font-size:0.7rem;
            background:#022c22;
            color:#6ee7b7;
        }

        .badge-inactive {
            display:inline-block;
            padding:0.15rem 0.5rem;
            border-radius:999px;
            font-size:0.7rem;
            background:#4b1d1d;
            color:#fecaca;
        }

        .actions a {
            color:#22c55e;
            text-decoration:none;
            font-size:0.78rem;
            margin-right:0.5rem;
        }

        .actions a:hover {
            text-decoration:underline;
        }

        .actions a.delete-link {
            color:#f97373;
        }

        .alert {
            padding:0.6rem 0.75rem;
            border-radius:0.8rem;
            font-size:0.8rem;
            margin-bottom:0.5rem;
        }
        .alert-success {
            background:#ecfdf3;
            color:#166534;
            border:1px solid #bbf7d0;
        }
        .alert-error {
            background:#fef2f2;
            color:#b91c1c;
            border:1px solid #fecaca;
        }
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
</head>
<body>
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-logo">WB</div>
            <div class="brand-text">
                <span><?= SCHOOL_NAME_SHORT ?> Panel</span>
                <span>Super Admin</span>
            </div>
        </div>

        <div>
            <div class="nav-section-title">Main</div>
            <ul class="nav-list">
                <li class="nav-item"><a href="dashboard.php" class="nav-link">Overview</a></li>
                <li class="nav-item"><a href="users.php" class="nav-link active">Users</a></li>
                <li class="nav-item"><a href="#" class="nav-link">Departments</a></li>
                <li class="nav-item"><a href="#" class="nav-link">System Settings</a></li>
            </ul>
        </div>

        <div class="sidebar-footer">
            <div>Logged in as:</div>
            <div><?php echo e($username); ?></div>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="topbar-title">
                <h1>User Management</h1>
                <span>Create and control all admin &amp; department accounts.</span>
            </div>

            <div class="user-actions">
                <a href="users.php" class="btn-secondary">+ New User</a>
                <div class="user-pill">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($fullName, 0, 1)); ?>
                    </div>
                    <span><?php echo e($username); ?></span>
                </div>
            </div>
        </header>

        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?php echo e($successMessage); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-error"><?php echo e($errorMessage); ?></div>
        <?php endif; ?>

        <section class="grid">
            <!-- LEFT: Create / edit form -->
            <div class="card">
                <h2><?php echo $editUser ? 'Edit User' : 'Create New User'; ?></h2>
                <p>
                    Use this form to create new users or update existing ones.
                    For password reset, select a user to edit and enter a new password.
                </p>

                <form method="post" action="backend/user-save.php" id="dcUserSaveForm"<?php if ($editUser): ?> data-orig-role="<?= e($editUser['role']) ?>" data-orig-active="<?= (int)$editUser['is_active'] ?>" data-username="<?= e($editUser['username']) ?>"<?php endif; ?>>
                    <?= csrfField() ?>
                    <input type="hidden" name="user_id" value="<?php echo $editUser ? (int)$editUser['id'] : ($oldForm['user_id'] ?? ''); ?>">

                    <div class="form-group">
                        <label for="full_name">Full name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            placeholder="Full name"
                            value="<?php echo e(field_value('full_name', $editUser, $oldForm)); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="username">Username</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Unique login username"
                            value="<?php echo e(field_value('username', $editUser, $oldForm)); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="email">Email (optional)</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="email@example.com"
                            value="<?php echo e(field_value('email', $editUser, $oldForm)); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="role">Role</label>
                        <select id="role" name="role">
                            <?php
                            $roles = [
                                'super_admin'   => 'Super Admin',
                                'school_admin'  => 'School Admin',
                                'hr_dept'       => 'HR Department',
                                'info_dept'     => 'Information Dept',
                                'edu_dept'      => 'Education Dept',
                                'finance_dept'  => 'Finance Dept',
                                'material_dept' => 'Material Dept',
                                'mezmur_dept' => 'Mezmur Dept',
                                'teacher'       => 'Teacher',
                                'attendance_taker' => 'Attendance Taker',
                                'content_editor' => 'Content Editor (Website)',
                                'accounts_office' => 'Accounts Office (ACM)',
                            ];
                            foreach ($roles as $value => $label) {
                                $selected = $currentRole === $value ? 'selected' : '';
                                echo "<option value=\"{$value}\" {$selected}>{$label}</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="password">
                            Password <?php echo $editUser ? '(leave blank to keep current)' : ''; ?>
                        </label>
                        <div class="password-wrapper">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="<?php echo $editUser ? 'New password (optional)' : 'Set initial password'; ?>"
                            >
                            <button
                                type="button"
                                class="toggle-pass"
                                data-toggle-password
                                data-target="password"
                            >Show</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm password</label>
                        <div class="password-wrapper">
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                placeholder="Confirm password"
                            >
                            <button
                                type="button"
                                class="toggle-pass"
                                data-toggle-password
                                data-target="confirm_password"
                            >Show</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="is_active">Status</label>
                        <select id="is_active" name="is_active">
                            <option value="1" <?php echo $currentStatus === 1 ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo $currentStatus === 0 ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-primary">
                        <?php echo $editUser ? 'Update User' : 'Create User'; ?>
                    </button>
                </form>
            </div>

            <!-- RIGHT: Users list -->
            <div class="card">
                <h2>Existing Users</h2>
                <p>
                    Users are loaded from the <span class="tag">users</span> table.  
                    Editing will not break existing data — updates are done by user ID.
                </p>

                <table style="margin-top:0.75rem;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$users): ?>
                        <tr>
                            <td colspan="5">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $row): ?>
                            <tr>
                                <td><?php echo (int)$row['id']; ?></td>
                                <td>
                                    <?php echo e($row['username']); ?>
                                    <br>
                                    <small style="color:#6b7280;">
                                        <?php echo e($row['full_name']); ?>
                                    </small>
                                </td>
                                <td><?php echo e($row['role']); ?></td>
                                <td>
                                    <?php if ((int)$row['is_active'] === 1): ?>
                                        <span class="badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="users.php?edit_id=<?php echo (int)$row['id']; ?>">Edit</a>
                                    <?php if ((int)$row['id'] !== (int)$_SESSION['admin_id']): ?>
                                        <a href="users.php?delete_id=<?php echo (int)$row['id']; ?>" class="delete-link">Delete</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($deleteUser): ?>
            <section style="margin-top:1rem;">
                <div class="card">
                    <h2>Confirm Delete User</h2>
                    <p>
                        You are about to delete user
                        <strong><?php echo e($deleteUser['username']); ?></strong>
                        (<?php echo e($deleteUser['full_name']); ?>, role:
                        <?php echo e($deleteUser['role']); ?>).
                        This action is permanent.
                    </p>
                    <p style="margin-top:0.5rem;">
                        To confirm, enter <strong>your</strong> super admin password.
                    </p>

                    <form method="post" action="backend/user-delete.php" style="margin-top:0.75rem;" id="dcDeleteUserForm" data-username="<?= e($deleteUser['username'] ?? '') ?>" data-fullname="<?= e($deleteUser['full_name'] ?? '') ?>" data-role="<?= e($deleteUser['role'] ?? '') ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="delete_user_id" value="<?php echo (int)$deleteUser['id']; ?>">

                        <div class="form-group">
                            <label for="superadmin_password">Your password</label>
                            <div class="password-wrapper">
                                <input
                                    type="password"
                                    id="superadmin_password"
                                    name="superadmin_password"
                                    required autocomplete="current-password"
                                    placeholder="Enter your super admin password"
                                >
                                <button
                                    type="button"
                                    class="toggle-pass"
                                    data-toggle-password
                                    data-target="superadmin_password"
                                >Show</button>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary">Confirm Delete</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <script>
        document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-target');
                var input = document.getElementById(targetId);
                if (!input) return;

                if (input.type === 'password') {
                    input.type = 'text';
                    btn.textContent = 'Hide';
                } else {
                    input.type = 'password';
                    btn.textContent = 'Show';
                }
            });
        });
    </script>
    <script src="/admin/js/danger_confirm.js?v=20261022dc4"></script>
    <script src="/admin/js/danger_user_forms.js?v=20261022dc"></script>
    <?php include __DIR__ . '/components/account_link.php'; ?>
</body>
</html>
