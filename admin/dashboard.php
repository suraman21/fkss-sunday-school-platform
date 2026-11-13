<?php
/**
 * Dashboard Router
 * School Management School Management System
 * 
 * Routes users to their appropriate department dashboard based on role.
 * Displays a beautiful error page if dashboard is not ready.
 */

require_once __DIR__ . '/config.php';

// If not logged in, redirect to login page
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$role = $_SESSION['admin_role'] ?? '';
$fullName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'User';
$username = $_SESSION['admin_username'] ?? '';

$roleFeature = \App\Services\FeatureGate::forRoleDashboard($role);
if ($roleFeature !== null && !\App\Services\FeatureGate::isEnabled($roleFeature)) {
    http_response_code(403);
    showDashboardNotReady($roleFeature . ' feature disabled', $fullName, $username);
    exit;
}

// If impersonating from school_admin, inject a floating "Back to School Admin" button
$isImpersonating = !empty($_SESSION['original_admin_role']);
if ($isImpersonating) {
    // Register shutdown function to inject the button HTML at the end of the page
    register_shutdown_function(function() {
        echo '
        <div id="impersonateBar" style="position:fixed;bottom:16px;right:16px;z-index:1300;display:flex;align-items:center;gap:8px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#000;padding:8px 16px;border-radius:12px;box-shadow:0 8px 24px rgba(245,158,11,0.4);font-family:Segoe UI,Arial,sans-serif;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;border:2px solid rgba(255,255,255,0.3)" onclick="restoreToAdmin()">
            <span style="font-size:16px">🔙</span>
            <span>Back to School Admin</span>
            <span style="font-size:10px;opacity:0.7;margin-left:4px">(Viewing as ' . htmlspecialchars(str_replace("_", " ", $_SESSION["admin_role"] ?? "")) . ')</span>
        </div>
        <script>
        async function restoreToAdmin(){
            try{
                const fd=new FormData();fd.append("action","restore");fd.append("csrf_token",' . json_encode(generateCsrfToken()) . ');
                const r=await fetch("/admin/api_impersonate.php",{method:"POST",body:fd,credentials:"same-origin"});
                const d=await r.json();
                if(d.status==="success")window.location.href="/admin/dashboard.php";
                else alert(d.message||"Failed to restore");
            }catch(e){alert("Network error");}
        }
        </script>';
    });
}

// Route user to the correct dashboard file
switch ($role) {
    case 'super_admin':
        require __DIR__ . '/dashboards/super-admin.php';
        break;

    case 'school_admin':
        $schoolAdminFile = __DIR__ . '/dashboards/school_admin.php';
        if (file_exists($schoolAdminFile)) {
            require $schoolAdminFile;
        } else {
            // Fallback to super-admin dashboard if school_admin.php is missing
            require __DIR__ . '/dashboards/super-admin.php';
        }
        break;

    case 'hr_dept':
        require __DIR__ . '/dashboards/hr-dept.php';
        break;

    case 'info_dept':
        require __DIR__ . '/dashboards/info-dept.php';
        break;

    case 'edu_dept':
        require __DIR__ . '/dashboards/edu_dept.php';
        break;

    case 'finance_dept':
        // ── MIGRATED → New separated frontend ──
        header('Location: /frontend/pages/finance_dept.php');
        exit;

    case 'material_dept':
        require __DIR__ . '/dashboards/material_department.php';
        break;

    case 'mezmur_dept':
        // ── Separated frontend shell (same pattern as finance) ──
        header('Location: /frontend/pages/mezmur_dept.php');
        exit;

    case 'teacher':
        require __DIR__ . '/dashboards/teacher.php';
        break;

    case 'attendance_taker':
        require __DIR__ . '/dashboards/attendance_taker.php';
        break;
    case 'mezmur_attendance_taker':
    case 'hr_attendance_taker':
        // Department-owned takers record from the mobile app; this
        // page is the web landing (account info + guidance).
        require __DIR__ . '/dashboards/dept_taker.php';
        break;

    case 'content_editor':
        require __DIR__ . '/dashboards/content_editor.php';
        break;

    case 'registrar':
    case 'account_activator':
        require __DIR__ . '/acm_overview.php';
        break;

    default:
        // Show beautiful error page for unknown roles
        showDashboardNotReady($role, $fullName, $username);
        break;
}

// ── Inject AI Chatbot Widget on ALL dashboards ──
$_aiWidgetFile = __DIR__ . '/dashboards/ai_chatbot_widget.php';
if (feature_enabled('ai') && file_exists($_aiWidgetFile) && isLoggedIn()) {
    include $_aiWidgetFile;
}

/**
 * Display a beautiful "Dashboard Not Ready" error page
 */
function showDashboardNotReady($role, $fullName, $username) {
    $roleDisplay = !empty($role) ? e($role) : 'Unknown';
    $nameDisplay = e($fullName);
    $userDisplay = e($username);
    $initials = strtoupper(substr($fullName, 0, 1));
    
    // Get Ethiopian date if available
    $todayFormatted = date('F j, Y');
    if (file_exists(__DIR__ . '/backend/ethiopian_date.php')) {
        require_once __DIR__ . '/backend/ethiopian_date.php';
        $today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
        $todayFormatted = ethio_date_format($today, 'F j, Y');
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Not Ready - <?= SCHOOL_NAME_SHORT ?></title>
    
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⛪</text></svg>">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous"/>
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap');
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .amharic-text {
            font-family: 'Noto Serif Ethiopic', serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            color: #e5e7eb;
            padding: 1rem;
        }

        .error-container {
            width: 100%;
            max-width: 600px;
            text-align: center;
        }

        .error-icon-wrapper {
            position: relative;
            display: inline-block;
            margin-bottom: 2rem;
        }

        .error-icon {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(239, 68, 68, 0.1));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: #fbbf24;
            border: 3px solid rgba(245, 158, 11, 0.3);
            animation: pulse-glow 2s ease-in-out infinite;
        }

        @keyframes pulse-glow {
            0%, 100% {
                box-shadow: 0 0 20px rgba(245, 158, 11, 0.2);
            }
            50% {
                box-shadow: 0 0 40px rgba(245, 158, 11, 0.4);
            }
        }

        .error-badge {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: white;
            border: 3px solid #0f172a;
            animation: bounce 1s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .error-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 0.5rem;
        }

        .error-title-amharic {
            font-size: 1.25rem;
            color: #fbbf24;
            margin-bottom: 1.5rem;
        }

        .error-message {
            font-size: 1rem;
            color: #94a3b8;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            font-size: 0.85rem;
            color: #e2e8f0;
            margin-bottom: 2rem;
        }

        .role-badge i {
            color: #fbbf24;
        }

        .user-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 1rem;
            margin-bottom: 2rem;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #16a34a, #22c55e);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: 700;
            color: white;
        }

        .user-details {
            text-align: left;
        }

        .user-name {
            font-weight: 600;
            color: #f1f5f9;
        }

        .user-role {
            font-size: 0.8rem;
            color: #64748b;
        }

        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 999px;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            min-width: 200px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #16a34a, #22c55e);
            color: white;
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.35);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(34, 197, 94, 0.45);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.15);
            color: #e2e8f0;
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.25);
        }

        .btn-danger {
            background: transparent;
            border: 1px solid #ef4444;
            color: #fca5a5;
        }

        .btn-danger:hover {
            background: #ef4444;
            color: white;
        }

        .help-text {
            margin-top: 2rem;
            font-size: 0.8rem;
            color: #64748b;
        }

        .help-text a {
            color: #22c55e;
            text-decoration: none;
        }

        .help-text a:hover {
            text-decoration: underline;
        }

        .countdown-text {
            margin-top: 1.5rem;
            font-size: 0.85rem;
            color: #64748b;
        }

        .countdown-text span {
            color: #fbbf24;
            font-weight: 600;
        }

        /* Decorative elements */
        .decoration {
            position: fixed;
            pointer-events: none;
            opacity: 0.1;
        }

        .decoration-1 {
            top: 10%;
            left: 5%;
            font-size: 8rem;
            transform: rotate(-15deg);
        }

        .decoration-2 {
            bottom: 10%;
            right: 5%;
            font-size: 6rem;
            transform: rotate(15deg);
        }

        /* Mobile Responsive */
        @media (max-width: 640px) {
            .error-icon {
                width: 100px;
                height: 100px;
                font-size: 2.5rem;
            }

            .error-title {
                font-size: 1.5rem;
            }

            .error-title-amharic {
                font-size: 1.1rem;
            }

            .error-message {
                font-size: 0.9rem;
            }

            .user-info {
                flex-direction: column;
                text-align: center;
            }

            .user-details {
                text-align: center;
            }

            .decoration {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Decorative elements -->
    <div class="decoration decoration-1">⛪</div>
    <div class="decoration decoration-2">✝️</div>

    <div class="error-container">
        <div class="error-icon-wrapper">
            <div class="error-icon">
                <i class="fa-solid fa-hammer"></i>
            </div>
            <div class="error-badge">
                <i class="fa-solid fa-clock"></i>
            </div>
        </div>

        <h1 class="error-title">Dashboard Under Construction</h1>
        <p class="error-title-amharic amharic-text">ዳሽቦርዱ በግንባታ ላይ ነው</p>

        <p class="error-message">
            The dashboard for your role is currently being developed. 
            Our team is working hard to bring you a great experience. 
            Please check back soon or contact an administrator for assistance.
        </p>

        <div class="role-badge">
            <i class="fa-solid fa-user-tag"></i>
            <span>Your Role: <strong><?= $roleDisplay ?></strong></span>
        </div>

        <div class="user-info">
            <div class="user-avatar"><?= $initials ?></div>
            <div class="user-details">
                <div class="user-name"><?= $nameDisplay ?></div>
                <div class="user-role"><?= $userDisplay ?> • <?= $todayFormatted ?></div>
            </div>
        </div>

        <div class="action-buttons">
            <a href="index.php" class="btn btn-primary">
                <i class="fa-solid fa-house"></i>
                Go to Home
            </a>
            <button onclick="location.reload()" class="btn btn-secondary">
                <i class="fa-solid fa-rotate"></i>
                Refresh Page
            </button>
            <a href="logout.php" class="btn btn-danger">
                <i class="fa-solid fa-right-from-bracket"></i>
                Logout
            </a>
        </div>

        <p class="help-text">
            Need help? Contact the <a href="#">system administrator</a> or 
            <a href="#">super admin</a> for access.
        </p>

        <p class="countdown-text">
            <i class="fa-solid fa-info-circle"></i>
            This page will auto-refresh in <span id="countdown">60</span> seconds
        </p>
    </div>

    <script>
        // Auto-refresh countdown
        let seconds = 60;
        const countdownEl = document.getElementById('countdown');
        
        const timer = setInterval(() => {
            seconds--;
            countdownEl.textContent = seconds;
            
            if (seconds <= 0) {
                clearInterval(timer);
                location.reload();
            }
        }, 1000);
    </script>
    <?php include __DIR__ . '/components/account_link.php'; ?>
</body>
</html>
<?php
}
