<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';
require_once __DIR__ . '/../backend/services/PhotoDevicePairing.php';
// session is already started and role checked in dashboard.php

// ------------------------------------------------------------
// Auth / session context
// ------------------------------------------------------------
$userName = (string) ($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Photo Department');
$userRole = (string) ($_SESSION['admin_role'] ?? 'Photo Department');
$username = $_SESSION['admin_username'] ?? '';

$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');

$isInDashboardSubdir = true; // this file lives under /admin/dashboards/
$ajaxPrefix = '../';

// ------------------------------------------------------------
// Helper: safe query + fetch
// ------------------------------------------------------------
function pd_fetch_all(mysqli $conn, string $sql, array $params = []): array {
    $rows = [];
    // Suppress prepare errors — photo tables (096/097) may not exist on
    // deployments that haven't run migrations yet, and we don't want the
    // whole dashboard to fatal. Each caller tolerates empty results.
    $stmt = @$conn->prepare($sql);
    if (!$stmt) return $rows;
    if (!empty($params)) {
        $types = ''; $bind = [];
        foreach ($params as $p) {
            if (is_int($p)) $types .= 'i';
            elseif (is_float($p)) $types .= 'd';
            else $types .= 's';
            $bind[] = $p;
        }
        $stmt->bind_param($types, ...$bind);
    }
    if (@$stmt->execute()) {
        $res = $stmt->get_result();
        if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function pd_fetch_one(mysqli $conn, string $sql, array $params = []): array {
    $rows = pd_fetch_all($conn, $sql, $params);
    return $rows[0] ?? [];
}

function pd_table_exists(mysqli $conn, string $table): bool {
    $r = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $r && $r->num_rows > 0;
}

// ------------------------------------------------------------
// KPI aggregation — shared "core" metrics pattern from HR/Info,
// scoped to photo-domain data.
// ------------------------------------------------------------
$kpi = [
    'total_members'        => 0,
    'members_with_photo'   => 0,
    'members_no_photo'     => 0,
    'coverage_pct'         => 0,
    'pending_review'       => 0,
    'pending_zip_items'    => 0,
    'total_batches'        => 0,
    'batches_today'        => 0,
    'total_receipts'       => 0,
    'receipts_today'       => 0,
    'storage_bytes'        => 0,
    'storage_mb'           => '0',
    'paired_devices'       => 0,
    'by_section'           => [
        '7_13'    => ['total' => 0, 'with' => 0],
        '14_17'   => ['total' => 0, 'with' => 0],
        '18_plus' => ['total' => 0, 'with' => 0],
    ],
];

$recentBatches = [];
$recentReceipts = [];
$pendingCandidates = [];
$allBatches = [];
$devices = [];
$missing = [];
$photoTablesReady = false;
$devicesTableReady = false;

if (isset($conn)) {
    // Coverage by age_section (7_13, 14_17, 18_plus) and overall
    $cov = pd_fetch_all($conn,
        "SELECT
            SUM(status != 'archived') AS total_m,
            SUM(student_photo_path IS NOT NULL AND student_photo_path != '' AND status != 'archived') AS with_p,
            SUM((student_photo_path IS NULL OR student_photo_path='') AND status != 'archived') AS no_p,
            SUM(age_group='7_13' AND status != 'archived') AS ag7_total,
            SUM(age_group='7_13' AND status != 'archived' AND student_photo_path IS NOT NULL AND student_photo_path!='') AS ag7_with,
            SUM(age_group='14_17' AND status != 'archived') AS ag14_total,
            SUM(age_group='14_17' AND status != 'archived' AND student_photo_path IS NOT NULL AND student_photo_path!='') AS ag14_with,
            SUM(age_group='18_plus' AND status != 'archived') AS ag18_total,
            SUM(age_group='18_plus' AND status != 'archived' AND student_photo_path IS NOT NULL AND student_photo_path!='') AS ag18_with
         FROM members");
    if (!empty($cov)) {
        $r = $cov[0];
        $kpi['total_members']      = (int)($r['total_m'] ?? 0);
        $kpi['members_with_photo'] = (int)($r['with_p'] ?? 0);
        $kpi['members_no_photo']   = (int)($r['no_p'] ?? 0);
        $kpi['by_section']['7_13']    = ['total' => (int)($r['ag7_total']  ?? 0), 'with' => (int)($r['ag7_with']  ?? 0)];
        $kpi['by_section']['14_17']   = ['total' => (int)($r['ag14_total'] ?? 0), 'with' => (int)($r['ag14_with'] ?? 0)];
        $kpi['by_section']['18_plus'] = ['total' => (int)($r['ag18_total'] ?? 0), 'with' => (int)($r['ag18_with'] ?? 0)];
        if ($kpi['total_members'] > 0) {
            $kpi['coverage_pct'] = (int) round(($kpi['members_with_photo'] / $kpi['total_members']) * 100);
        }
    }

    // ---- Photo pipeline KPIs (only if migration 096/097 tables exist) ----
    $photoTablesReady = pd_table_exists($conn, 'photo_upload_receipts')
                     && pd_table_exists($conn, 'member_photo_candidates')
                     && pd_table_exists($conn, 'photo_import_batches')
                     && pd_table_exists($conn, 'photo_import_items');
    $devicesTableReady = pd_table_exists($conn, 'photo_device_identities');

    if ($photoTablesReady) {
        // Pending review queue (cross-photographer conflicts awaiting decision)
        $pr = pd_fetch_one($conn,
            "SELECT COUNT(*) AS c FROM member_photo_candidates
             WHERE decision_pending = 1 AND archived = 0");
        $kpi['pending_review'] = (int)($pr['c'] ?? 0);

        // ZIP import items still pending
        $pz = pd_fetch_one($conn,
            "SELECT COUNT(*) AS c FROM photo_import_items WHERE decision = 'pending'");
        $kpi['pending_zip_items'] = (int)($pz['c'] ?? 0);

        // Batches
        $bt = pd_fetch_one($conn, "SELECT COUNT(*) AS c FROM photo_import_batches");
        $kpi['total_batches'] = (int)($bt['c'] ?? 0);
        $bt2 = pd_fetch_one($conn,
            "SELECT COUNT(*) AS c FROM photo_import_batches WHERE DATE(created_at) = CURDATE()");
        $kpi['batches_today'] = (int)($bt2['c'] ?? 0);

        // Receipts (all photos on server, regardless of path)
        $rc = pd_fetch_one($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(byte_size),0) AS bytes FROM photo_upload_receipts");
        $kpi['total_receipts'] = (int)($rc['c'] ?? 0);
        $kpi['storage_bytes']  = (int)($rc['bytes'] ?? 0);
        $kpi['storage_mb']     = number_format($kpi['storage_bytes'] / (1024*1024), 1);
        $rc2 = pd_fetch_one($conn,
            "SELECT COUNT(*) AS c FROM photo_upload_receipts WHERE DATE(created_at) = CURDATE()");
        $kpi['receipts_today'] = (int)($rc2['c'] ?? 0);

        // Recent batches (last 8)
        $recentBatches = pd_fetch_all($conn,
            "SELECT b.id, b.batch_uuid, b.source, b.total_photos, b.committed_photos,
                    b.status, b.manifest_sig_valid, b.manifest_json,
                    b.operator_admin_id, b.device_id, b.created_at, b.committed_at,
                    u.full_name AS operator_name
             FROM photo_import_batches b
             LEFT JOIN users u ON u.id = b.operator_admin_id
             ORDER BY b.created_at DESC LIMIT 8");

        // Pending review candidates (top 8)
        $pendingCandidates = pd_fetch_all($conn,
            "SELECT c.id, c.member_id, c.field, c.created_at, c.byte_size,
                    u.full_name AS operator_name,
                    m.student_name, m.father_name, m.grandfather_name,
                    m.member_code
             FROM member_photo_candidates c
             LEFT JOIN users u ON u.id = c.operator_admin_id
             LEFT JOIN members m ON m.id = c.member_id
             WHERE c.decision_pending = 1 AND c.archived = 0
             ORDER BY c.created_at ASC LIMIT 8");
    }

    // Paired devices (separate table from 097)
    if ($devicesTableReady) {
        $dv = pd_fetch_one($conn,
            "SELECT COUNT(DISTINCT device_id) AS c FROM photo_device_identities");
        $kpi['paired_devices'] = (int)($dv['c'] ?? 0);
    }

    // Missing-photos list (uses only members table — always available)
    // Also used by "All batches" history tab.
    if ($photoTablesReady) {
        $allBatches = pd_fetch_all($conn,
            "SELECT b.id, b.batch_uuid, b.source, b.total_photos, b.committed_photos,
                    b.status, b.manifest_sig_valid, b.manifest_json,
                    b.operator_admin_id, b.device_id, b.created_at, b.committed_at,
                    u.full_name AS operator_name
             FROM photo_import_batches b
             LEFT JOIN users u ON u.id = b.operator_admin_id
             ORDER BY b.created_at DESC LIMIT 100");

        $devices = pd_fetch_all($conn,
            "SELECT d.id, d.device_id, d.first_seen_at, d.last_seen_at,
                    u.full_name AS operator_name, u.username
             FROM photo_device_identities d
             LEFT JOIN users u ON u.id = d.operator_admin_id
             ORDER BY d.last_seen_at DESC LIMIT 100");
    }
}

// ------------------------------------------------------------
// Render helpers
// ------------------------------------------------------------
function pct(int $part, int $total): int {
    return $total > 0 ? (int) round(($part / $total) * 100) : 0;
}

$DEPT_COLOR = '#800000';    // maroon (brand primary)
$DEPT_ACCENT = '#f59e0b';   // gold  (brand accent)
$DEPT_DARK   = '#5c0000';
$DEPT_LIGHT  = '#a01010';
$DEPT_NAME_EN = 'Photo Department';
$DEPT_NAME_AM = 'የፎቶ መምሪያ';
$DEPT_SUB_AM  = 'የአባላት ፎቶ ማስተዳደሪያ · ከስልክ የመጣ · የዚፕ ጭነት';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($DEPT_NAME_EN) ?> · <?= defined('SCHOOL_NAME_SHORT') ? htmlspecialchars(SCHOOL_NAME_SHORT) : 'FKSS' ?></title>
<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
      integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous">
<script src="https://cdn.tailwindcss.com"></script>
<style>
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f8fafc; }
    .amharic-text { font-family: 'Nyala', 'Noto Sans Ethiopic', serif; }
    .sidebar-gradient { background: linear-gradient(180deg, <?= $DEPT_DARK ?>, <?= $DEPT_COLOR ?> 60%, <?= $DEPT_LIGHT ?>); }
    .stat-card { color: white; border-radius: 20px; box-shadow: 0 6px 18px rgba(0,0,0,.08); transition: transform .15s ease, box-shadow .15s ease; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,.12); }
    .panel { background: white; border-radius: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.05); border: 1px solid #e2e8f0; }
    .mobile-card { }
    .sec { display: none; }
    .sec.active, .sec.act, .sec.show { display: block; }
    .mobile-touch-target { min-height: 44px; }
    .quick-card { transition: all .2s ease; }
    .quick-card:hover { transform: translateY(-3px); box-shadow: 0 10px 24px rgba(0,0,0,.1); }
    .progress-bar { height: 8px; border-radius: 999px; background: #fef3c7; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #b45309, #f59e0b); transition: width .6s ease; }
    .badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .badge-amber { background: #fef3c7; color: #92400e; }
    .badge-red { background: #fee2e2; color: #991b1b; }
    .badge-green { background: #d1fae5; color: #065f46; }
    .badge-slate { background: #f1f5f9; color: #334155; }
    table.pd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    table.pd-table th { text-align: left; font-weight: 600; color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; padding: 10px 12px; border-bottom: 1px solid #e2e8f0; }
    table.pd-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #1e293b; }
    table.pd-table tr:last-child td { border-bottom: none; }
</style>
</head>
<body class="min-h-screen flex">

<!-- =================== SIDEBAR (desktop) =================== -->
<aside class="hidden md:flex sidebar-gradient text-white w-64 flex-col py-5 px-4">
    <div class="flex items-center mb-6">
        <div class="w-11 h-11 rounded-2xl bg-white/15 flex items-center justify-center shadow-md">
            <i class="fa-solid fa-camera-retro text-xl" style="color:<?= $DEPT_ACCENT ?>"></i>
        </div>
        <div class="ml-3">
            <div class="text-sm font-bold"><?= htmlspecialchars($DEPT_NAME_EN) ?></div>
                <div class="text-[11px] text-amber-100 amharic-text"><?= defined('SCHOOL_NAME_SHORT_AM') ? SCHOOL_NAME_SHORT_AM : 'FKSS' ?> <?= $DEPT_NAME_AM ?></div>
        </div>
    </div>

    <nav class="flex-1 flex flex-col space-y-1 text-sm">
        <button data-section="dashboard"
           class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition w-full border-none cursor-pointer text-white text-left">
            <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                <i class="fa-solid fa-gauge text-sm"></i>
            </span>
            <span class="font-semibold">Dashboard</span>
        </button>

        <a href="/admin/photo_import.php"
           class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition text-white">
            <span class="w-8 h-8 rounded-xl bg-amber-500/25 flex items-center justify-center">
                <i class="fa-solid fa-file-zipper text-sm text-amber-300"></i>
            </span>
            <span class="font-semibold">ZIP Import (Airlock)</span>
        </a>

        <a href="/admin/photo_review.php"
           class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition text-white">
            <span class="w-8 h-8 rounded-xl <?= $kpi['pending_review'] > 0 ? 'bg-red-500/30 animate-pulse' : 'bg-amber-500/25' ?> flex items-center justify-center relative">
                <i class="fa-solid fa-camera-retro text-sm text-amber-300"></i>
                <?php if ($kpi['pending_review'] > 0): ?>
                <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-red-500 text-[9px] flex items-center justify-center font-bold"><?= $kpi['pending_review'] > 9 ? '9+' : $kpi['pending_review'] ?></span>
                <?php endif; ?>
            </span>
            <span class="font-semibold">Photo Review</span>
        </a>

        <button data-section="coverage"
           class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition w-full border-none cursor-pointer text-white text-left">
            <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                <i class="fa-solid fa-chart-pie text-sm"></i>
            </span>
            <span class="font-semibold">Capture Coverage</span>
        </button>

        <button data-section="history"
           class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition w-full border-none cursor-pointer text-white text-left">
            <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                <i class="fa-solid fa-clock-rotate-left text-sm"></i>
            </span>
            <span class="font-semibold">Import History / Audit</span>
        </button>

        <button data-section="devices"
           class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition w-full border-none cursor-pointer text-white text-left">
            <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                <i class="fa-solid fa-mobile-screen-button text-sm"></i>
            </span>
            <span class="font-semibold">Paired Devices</span>
        </button>

        <button onclick="navigateToSection('profile')" data-section="profile"
           class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition w-full border-none cursor-pointer text-white text-left">
            <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                <i class="fa-solid fa-user-gear text-sm"></i>
            </span>
            <span class="font-semibold">My Profile</span>
        </button>
    </nav>

    <div class="mt-5 space-y-2">
        <?php
        require_once __DIR__ . '/../components/sidebar_profile_item.php';
        renderSidebarUserCard($userName, 'Photo Dept', $todayFormatted, strtoupper(substr($userName, 0, 1)), "linear-gradient(135deg,{$DEPT_DARK},{$DEPT_COLOR})");
        ?>
        <a href="/admin/logout.php"
           class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl bg-red-500/95 text-white text-xs font-semibold shadow hover:bg-red-600 transition">
            <span>Logout</span>
            <i class="fa-solid fa-power-off text-xs"></i>
        </a>
    </div>
</aside>

<!-- =================== MAIN =================== -->
<div class="flex-1 flex flex-col min-w-0">

    <!-- Mobile header -->
    <div class="mobile-sticky-header md:hidden flex items-center justify-between sidebar-gradient text-white px-4 py-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-white/15 rounded-full flex items-center justify-center">
                <i class="fa-solid fa-camera-retro text-lg" style="color:<?= $DEPT_ACCENT ?>"></i>
            </div>
            <div>
                <div class="text-sm font-bold"><?= htmlspecialchars($DEPT_NAME_EN) ?></div>
                <div class="text-[11px] text-amber-100 amharic-text"><?= $DEPT_NAME_AM ?></div>
            </div>
        </div>
        <div class="text-right text-[11px]">
            <div class="font-semibold"><?= htmlspecialchars($todayFormatted) ?></div>
            <div class="text-amber-100"><?= htmlspecialchars($userName) ?></div>
        </div>
    </div>

    <!-- Desktop header -->
    <header class="hidden md:flex text-white px-3 sm:px-6 py-3 sm:py-4 items-center justify-between shadow-md" style="background:<?= $DEPT_COLOR ?>">
        <div class="flex items-center gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <span class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center">
                        <i class="fa-solid fa-camera-retro text-lg" style="color:<?= $DEPT_ACCENT ?>"></i>
                    </span>
                    <div>
                        <h1 class="text-base sm:text-lg font-bold"><?= htmlspecialchars($DEPT_NAME_EN) ?> Overview</h1>
                        <p class="text-[11px] sm:text-xs text-amber-100 amharic-text"><?= $DEPT_SUB_AM ?></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3 text-xs">
            <span class="px-3 py-1.5 rounded-full border border-amber-200/40 text-amber-100 bg-white/10">
                <i class="fa-regular fa-calendar mr-1"></i><?= htmlspecialchars($todayFormatted) ?>
            </span>
            <?php if ($kpi['pending_review'] > 0): ?>
            <a href="/admin/photo_review.php" class="px-3 py-1.5 rounded-full bg-red-500 text-white font-semibold animate-pulse">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i><?= $kpi['pending_review'] ?> pending review
            </a>
            <?php endif; ?>
        </div>
    </header>

    <!-- Scrollable content -->
    <main class="flex-1 overflow-y-auto p-3 sm:p-6 space-y-4">

        <!-- ============ DASHBOARD SECTION ============ -->
        <section id="section-dashboard" class="sec active">

            <?php if (!$photoTablesReady): ?>
            <div class="panel p-4 border-l-4 border-amber-500 bg-amber-50">
                <div class="flex items-start gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-amber-600 mt-0.5"></i>
                    <div>
                        <h3 class="text-sm font-semibold text-amber-900">Photo pipeline migrations not installed</h3>
                        <p class="text-xs text-amber-800 mt-1">The photo upload / review / ZIP-import tables (migrations <code>096_mobile_photo_uploads.sql</code> and <code>097_photo_device_pairing.sql</code>) are missing on this server. Coverage KPIs from the <em>members</em> table are shown below; import/review/batch KPIs will appear once migrations are applied.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- KPI grid -->
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-800">Department Overview</h2>
                    <p class="text-[11px] text-slate-500">Live photo pipeline statistics</p>
                </div>
                <span class="px-3 py-1.5 rounded-full border border-amber-200 text-amber-700 bg-amber-50 text-xs">
                    <i class="fa-solid fa-bolt mr-1"></i>Live
                </span>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-4">
                <!-- Coverage -->
                <div class="stat-card p-3 sm:p-4 flex flex-col justify-between" style="background:linear-gradient(135deg,<?= $DEPT_DARK ?>,<?= $DEPT_COLOR ?>,<?= $DEPT_LIGHT ?>)">
                    <div class="flex items-center justify-between">
                        <div class="text-xs uppercase tracking-wide">Capture Coverage</div>
                        <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                            <i class="fa-solid fa-camera text-sm"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-2xl sm:text-3xl font-bold"><?= $kpi['coverage_pct'] ?>%</div>
                    <div class="mt-1 text-[11px] opacity-80">
                        <?= $kpi['members_with_photo'] ?> / <?= $kpi['total_members'] ?> members
                    </div>
                </div>

                <!-- Pending Review -->
                <a href="/admin/photo_review.php" class="stat-card bg-gradient-to-tr from-red-500 via-rose-500 to-orange-400 p-3 sm:p-4 flex flex-col justify-between" style="text-decoration:none;color:inherit">
                    <div class="flex items-center justify-between">
                        <div class="text-xs uppercase tracking-wide">Pending Review</div>
                        <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                            <i class="fa-solid fa-triangle-exclamation text-sm"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-2xl sm:text-3xl font-bold"><?= $kpi['pending_review'] + $kpi['pending_zip_items'] ?></div>
                    <div class="mt-1 text-[11px] opacity-90 flex gap-3 flex-wrap">
                        <span>Conflicts <b><?= $kpi['pending_review'] ?></b></span>
                        <span>ZIP items <b><?= $kpi['pending_zip_items'] ?></b></span>
                    </div>
                </a>

                <!-- Photos on server -->
                <div class="stat-card bg-gradient-to-tr from-emerald-600 via-emerald-500 to-teal-400 p-3 sm:p-4 flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <div class="text-xs uppercase tracking-wide">Photos on Server</div>
                        <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                            <i class="fa-solid fa-images text-sm"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-2xl sm:text-3xl font-bold"><?= number_format($kpi['total_receipts']) ?></div>
                    <div class="mt-1 text-[11px] opacity-90">
                        <b><?= $kpi['receipts_today'] ?></b> today · <?= $kpi['storage_mb'] ?> MB stored
                    </div>
                </div>

                <!-- Batches -->
                <div class="stat-card bg-gradient-to-tr from-amber-500 via-orange-400 to-yellow-400 p-3 sm:p-4 flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <div class="text-xs uppercase tracking-wide">ZIP Batches</div>
                        <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                            <i class="fa-solid fa-file-zipper text-sm"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-2xl sm:text-3xl font-bold"><?= $kpi['total_batches'] ?></div>
                    <div class="mt-1 text-[11px] opacity-90">
                        <b><?= $kpi['batches_today'] ?></b> uploaded today · <?= $kpi['paired_devices'] ?> paired devices
                    </div>
                </div>
            </div>

            <!-- Quick actions -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <a href="/admin/photo_import.php" class="quick-card panel p-5 flex items-center gap-4" style="text-decoration:none;color:inherit">
                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#fef3c7;color:#b45309">
                        <i class="fa-solid fa-file-zipper text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-800">Import a ZIP (Airlock)</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Upload a signed bundle from a field device, verify manifest HMAC, ingest photos.</p>
                    </div>
                    <i class="fa-solid fa-chevron-right text-slate-400"></i>
                </a>
                <a href="/admin/photo_review.php" class="quick-card panel p-5 flex items-center gap-4" style="text-decoration:none;color:inherit">
                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#fee2e2;color:#991b1b">
                        <i class="fa-solid fa-camera-retro text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-800">Review &amp; Resolve Conflicts</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Pick the winning shot when multiple photographers captured the same member.</p>
                    </div>
                    <i class="fa-solid fa-chevron-right text-slate-400"></i>
                </a>
                <button onclick="navigateToSection('coverage')" class="quick-card panel p-5 flex items-center gap-4 text-left w-full border-none cursor-pointer" style="background:white">
                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#d1fae5;color:#065f46">
                        <i class="fa-solid fa-chart-pie text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-800">View Capture Coverage</h3>
                        <p class="text-xs text-slate-500 mt-0.5">See which sections and members are missing photos, prioritise the next visit.</p>
                    </div>
                    <i class="fa-solid fa-chevron-right text-slate-400"></i>
                </button>
            </div>

            <!-- Two-column: pending queue + recent batches -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Pending review -->
                <div class="panel p-4 sm:p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-xl bg-red-100 flex items-center justify-center text-red-600"><i class="fa-solid fa-clock"></i></span>
                            Pending Review Queue
                        </h3>
                        <a href="/admin/photo_review.php" class="text-xs font-semibold text-red-600 hover:text-red-800">Open all <i class="fa-solid fa-arrow-right ml-1"></i></a>
                    </div>
                    <?php if (empty($pendingCandidates)): ?>
                        <div class="text-center py-8 text-slate-400">
                            <i class="fa-solid fa-circle-check text-4xl mb-2 text-emerald-400"></i>
                            <p class="text-sm">No photos pending review — all caught up!</p>
                        </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                    <table class="pd-table">
                        <thead><tr><th>Member</th><th>Field</th><th>Operator</th><th>When</th></tr></thead>
                        <tbody>
                        <?php foreach ($pendingCandidates as $c): ?>
                            <tr>
                                <td>
                                    <div class="font-semibold text-slate-800 text-xs"><?= htmlspecialchars(trim(($c['student_name'] ?? '') . ' ' . ($c['father_name'] ?? ''))) ?></div>
                                    <div class="text-[11px] text-slate-500"><?= htmlspecialchars($c['member_code'] ?? '—') ?></div>
                                </td>
                                <td><span class="badge badge-amber"><?= htmlspecialchars($c['field'] === 'guardian_photo' ? 'Guardian' : 'Student') ?></span></td>
                                <td class="text-xs text-slate-600"><?= htmlspecialchars($c['operator_name'] ?? '—') ?></td>
                                <td class="text-[11px] text-slate-500"><?= htmlspecialchars(substr($c['created_at'] ?? '', 0, 16)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent batches -->
                <div class="panel p-4 sm:p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-xl bg-amber-100 flex items-center justify-center text-amber-600"><i class="fa-solid fa-file-zipper"></i></span>
                            Recent ZIP Batches
                        </h3>
                        <button onclick="navigateToSection('history')" class="text-xs font-semibold text-amber-700 hover:text-amber-900">View history <i class="fa-solid fa-arrow-right ml-1"></i></button>
                    </div>
                    <?php if (empty($recentBatches)): ?>
                        <div class="text-center py-8 text-slate-400">
                            <i class="fa-solid fa-inbox text-4xl mb-2"></i>
                            <p class="text-sm">No batches imported yet.</p>
                        </div>
                    <?php else: ?>
                    <div class="space-y-2">
                    <?php foreach ($recentBatches as $b):
                        $status = $b['status'] ?? 'uploaded';
                        if ($status === 'done' || $status === 'committed')      $statusBadge = '<span class="badge badge-green">Committed</span>';
                        elseif ($status === 'uploaded')   $statusBadge = '<span class="badge badge-amber">Awaiting review</span>';
                        elseif ($status === 'failed')     $statusBadge = '<span class="badge badge-red">Failed</span>';
                        else                              $statusBadge = '<span class="badge badge-slate">' . htmlspecialchars($status) . '</span>';
                        $pct = ((int)($b['total_photos'] ?? 0) > 0)
                            ? (int) round(((int)($b['committed_photos'] ?? 0) / (int)$b['total_photos']) * 100)
                            : 0;
                    ?>
                        <div class="p-3 rounded-xl border border-slate-200 hover:border-amber-300 hover:bg-amber-50/30 transition">
                            <div class="flex items-start justify-between mb-1">
                                <div class="text-xs font-semibold text-slate-700">
                                    <i class="fa-solid fa-file-zipper mr-1 text-amber-500"></i>
                                    Batch #<?= (int)$b['id'] ?>
                                    <span class="text-slate-400 font-normal">· <?= htmlspecialchars($b['operator_name'] ?? '—') ?></span>
                                </div>
                                <?= $statusBadge ?>
                            </div>
                            <div class="text-[11px] text-slate-500 mb-2"><?= htmlspecialchars(substr($b['created_at'] ?? '', 0, 16)) ?></div>
                            <div class="flex items-center gap-2 text-[11px]">
                                <span class="text-slate-600"><b><?= (int)$b['committed_photos'] ?></b>/<?= (int)$b['total_photos'] ?> committed</span>
                                <div class="progress-bar flex-1"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
                                <span class="font-semibold text-amber-700"><?= $pct ?>%</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ============ COVERAGE SECTION ============ -->
        <section id="section-coverage" class="sec">
            <div class="mb-4">
                <h2 class="text-lg sm:text-xl font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-chart-pie" style="color:<?= $DEPT_ACCENT ?>"></i> Capture Coverage
                </h2>
                <p class="text-[11px] text-slate-500">Which sections still need student photos. Guardians counted separately when the column is populated.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <?php
                $sections = [
                    '7_13'    => ['label' => 'Children (A)', 'am' => 'ህጻናት', 'color' => 'sky'],
                    '14_17'   => ['label' => 'Youth (B)',    'am' => 'ማዕከላዊያን', 'color' => 'amber'],
                    '18_plus' => ['label' => 'Adults (C)',   'am' => 'አዋቂዎች', 'color' => 'rose'],
                ];
                $colorMap = [
                    'sky'   => ['bg' => 'bg-sky-50', 'bd' => 'border-sky-100', 'tx' => 'text-sky-700', 'bar' => '#0ea5e9'],
                    'amber' => ['bg' => 'bg-amber-50','bd' => 'border-amber-100','tx' => 'text-amber-700','bar' => '#f59e0b'],
                    'rose'  => ['bg' => 'bg-rose-50', 'bd' => 'border-rose-100', 'tx' => 'text-rose-700', 'bar' => '#e11d48'],
                ];
                foreach ($sections as $key => $s):
                    $total = $kpi['by_section'][$key]['total'];
                    $with  = $kpi['by_section'][$key]['with'];
                    $p = pct($with, $total);
                    $c = $colorMap[$s['color']];
                ?>
                <div class="panel p-5">
                    <div class="<?= $c['tx'] ?> text-[11px] amharic-text font-semibold"><?= $s['am'] ?></div>
                    <div class="text-sm font-bold text-slate-800 mt-0.5"><?= $s['label'] ?></div>
                    <div class="flex items-end justify-between mt-3">
                        <div>
                            <div class="text-3xl font-bold text-slate-900"><?= $p ?>%</div>
                            <div class="text-[11px] text-slate-500"><?= $with ?> / <?= $total ?> covered</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xl font-bold <?= $c['tx'] ?>"><?= $total - $with ?></div>
                            <div class="text-[10px] text-slate-500">missing</div>
                        </div>
                    </div>
                    <div class="progress-bar mt-3" style="background:#f1f5f9"><div class="h-full rounded-full" style="width:<?= $p ?>%;background:<?= $c['bar'] ?>"></div></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="panel p-4 sm:p-5">
                <h3 class="text-sm font-semibold text-slate-800 mb-3 flex items-center gap-2">
                    <span class="w-7 h-7 rounded-xl bg-red-100 flex items-center justify-center text-red-600"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    Members Missing Photos (sample, oldest-first)
                </h3>
                <?php
                $missing = pd_fetch_all($conn,
                    "SELECT id, student_name, father_name, grandfather_name, member_code, age_group, gender
                     FROM members
                     WHERE status != 'archived'
                       AND (student_photo_path IS NULL OR student_photo_path = '')
                     ORDER BY id ASC LIMIT 30");
                if (empty($missing)):
                ?>
                <div class="text-center py-8 text-emerald-600">
                    <i class="fa-solid fa-circle-check text-4xl mb-2"></i>
                    <p class="text-sm font-semibold">Every active member has a photo — coverage is 100%!</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                <table class="pd-table">
                    <thead><tr><th>#</th><th>Name</th><th>Code</th><th>Section</th><th>Gender</th></tr></thead>
                    <tbody>
                    <?php foreach ($missing as $i => $m):
                        switch ($m['age_group'] ?? '') {
                            case '7_13':    $ageLabel = 'Children (A)'; break;
                            case '14_17':   $ageLabel = 'Youth (B)'; break;
                            case '18_plus': $ageLabel = 'Adults (C)'; break;
                            default:        $ageLabel = '—';
                        }
                    ?>
                        <tr>
                            <td class="text-slate-400 text-xs"><?= $i+1 ?></td>
                            <td><div class="font-semibold text-xs"><?= htmlspecialchars(trim(($m['student_name']??'').' '.($m['father_name']??'').' '.($m['grandfather_name']??''))) ?></div></td>
                            <td class="text-xs text-slate-600"><?= htmlspecialchars($m['member_code'] ?? '—') ?></td>
                            <td><span class="badge badge-slate"><?= htmlspecialchars($ageLabel) ?></span></td>
                            <td class="text-xs text-slate-600"><?= htmlspecialchars(ucfirst($m['gender'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ============ HISTORY / AUDIT SECTION ============ -->
        <section id="section-history" class="sec">
            <div class="mb-4">
                <h2 class="text-lg sm:text-xl font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left" style="color:<?= $DEPT_ACCENT ?>"></i> Import History &amp; Audit
                </h2>
                <p class="text-[11px] text-slate-500">Every ZIP airlock import and mobile-upload batch is logged here for chain-of-custody.</p>
            </div>

            <div class="panel p-4 sm:p-5">
                <?php if (!$photoTablesReady): ?>
                <div class="text-center py-8 text-amber-700">
                    <i class="fa-solid fa-triangle-exclamation text-4xl mb-2"></i>
                    <p class="text-sm font-semibold">Photo pipeline tables not installed yet.</p>
                    <p class="text-xs mt-1 text-amber-600">Run migrations <code>096_mobile_photo_uploads.sql</code> and <code>097_photo_device_pairing.sql</code> to enable import history.</p>
                </div>
                <?php elseif (empty($allBatches)): ?>
                <div class="text-center py-8 text-slate-400">
                    <i class="fa-solid fa-inbox text-4xl mb-2"></i>
                    <p class="text-sm">No import history yet.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                <table class="pd-table">
                    <thead><tr><th>#</th><th>Operator</th><th>Source</th><th>Photos</th><th>Sig</th><th>Status</th><th>Uploaded</th><th>Committed</th></tr></thead>
                    <tbody>
                    <?php foreach ($allBatches as $b):
                        $sigStatus = \App\Services\PhotoDevicePairing::sigStatusForBatch($conn, $b);
                        $status = $b['status'] ?? 'uploaded';
                        if ($status === 'done' || $status === 'committed')      $badge = '<span class="badge badge-green">Committed</span>';
                        elseif ($status === 'uploaded')   $badge = '<span class="badge badge-amber">Awaiting</span>';
                        elseif ($status === 'failed')     $badge = '<span class="badge badge-red">Failed</span>';
                        else                              $badge = '<span class="badge badge-slate">' . htmlspecialchars($status) . '</span>';
                    ?>
                        <tr>
                            <td class="font-mono text-xs text-slate-500">#<?= (int)$b['id'] ?></td>
                            <td class="text-xs font-semibold"><?= htmlspecialchars($b['operator_name'] ?? '—') ?></td>
                            <td><span class="badge badge-slate"><?= htmlspecialchars(strtoupper($b['source'] ?? 'zip')) ?></span></td>
                            <td class="text-xs"><b><?= (int)$b['committed_photos'] ?></b> / <?= (int)$b['total_photos'] ?></td>
                            <td><?php if ($sigStatus === 'verified'): ?><span class="badge badge-green"><i class="fa-solid fa-shield-halved mr-1"></i>Valid</span><?php elseif ($sigStatus === 'mismatch'): ?><span class="badge badge-red"><i class="fa-solid fa-shield-halved mr-1"></i>Mismatch</span><?php else: ?><span class="badge badge-amber"><i class="fa-solid fa-shield-halved mr-1"></i>Unverified</span><?php endif; ?></td>
                            <td><?= $badge ?></td>
                            <td class="text-[11px] text-slate-500"><?= htmlspecialchars(substr($b['created_at'] ?? '', 0, 16)) ?></td>
                            <td class="text-[11px] text-slate-500"><?= $b['committed_at'] ? htmlspecialchars(substr($b['committed_at'], 0, 16)) : '<span class="text-slate-400">—</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ============ DEVICES SECTION ============ -->
        <section id="section-devices" class="sec">
            <div class="mb-4">
                <h2 class="text-lg sm:text-xl font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-mobile-screen-button" style="color:<?= $DEPT_ACCENT ?>"></i> Paired Devices
                </h2>
                <p class="text-[11px] text-slate-500">Field phones that have completed HMAC pairing (for signed ZIP airlock manifests). Only this department can view the full list.</p>
            </div>

            <div class="panel p-4 sm:p-5">
                <?php if (!$devicesTableReady): ?>
                <div class="text-center py-8 text-amber-700">
                    <i class="fa-solid fa-triangle-exclamation text-4xl mb-2"></i>
                    <p class="text-sm font-semibold">Device-pairing table not installed yet.</p>
                    <p class="text-xs mt-1 text-amber-600">Run migration <code>097_photo_device_pairing.sql</code> to enable device visibility.</p>
                </div>
                <?php elseif (empty($devices)): ?>
                <div class="text-center py-8 text-slate-400">
                    <i class="fa-solid fa-mobile-screen text-4xl mb-2"></i>
                    <p class="text-sm">No devices paired yet. Pairing happens automatically on first successful photo upload from the mobile app.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                <table class="pd-table">
                    <thead><tr><th>Operator</th><th>Device ID</th><th>First seen</th><th>Last seen</th></tr></thead>
                    <tbody>
                    <?php foreach ($devices as $d): ?>
                        <tr>
                            <td class="text-xs font-semibold"><?= htmlspecialchars($d['operator_name'] ?? $d['username'] ?? '—') ?></td>
                            <td class="font-mono text-[11px] text-slate-600"><?= htmlspecialchars($d['device_id']) ?></td>
                            <td class="text-[11px] text-slate-500"><?= htmlspecialchars(substr($d['first_seen_at'] ?? '', 0, 16)) ?></td>
                            <td class="text-[11px] text-slate-500"><?= htmlspecialchars(substr($d['last_seen_at'] ?? '', 0, 16)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ============ PROFILE SECTION ============ -->
        <?php
        require_once __DIR__ . '/../components/profile_tab_section.php';
        renderProfileTabSection('section-profile', 'sec', false, 'section');
        ?>

    </main>
</div>

<!-- Mobile bottom nav -->
<nav class="md:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 flex justify-around py-2 z-40" style="padding-bottom:calc(.5rem + env(safe-area-inset-bottom))">
    <button data-section="dashboard" class="flex flex-col items-center gap-0.5 text-xs px-3 py-1 text-amber-700">
        <i class="fa-solid fa-gauge text-base"></i><span>Home</span>
    </button>
    <a href="/admin/photo_import.php" class="flex flex-col items-center gap-0.5 text-xs px-3 py-1 text-slate-500">
        <i class="fa-solid fa-file-zipper text-base"></i><span>Import</span>
    </a>
    <a href="/admin/photo_review.php" class="flex flex-col items-center gap-0.5 text-xs px-3 py-1 text-slate-500 relative">
        <i class="fa-solid fa-camera-retro text-base"></i><span>Review</span>
        <?php if ($kpi['pending_review'] > 0): ?>
        <span class="absolute top-0 right-1 w-4 h-4 rounded-full bg-red-500 text-white text-[9px] flex items-center justify-center font-bold"><?= $kpi['pending_review'] > 9 ? '9+' : $kpi['pending_review'] ?></span>
        <?php endif; ?>
    </a>
    <button data-section="profile" class="flex flex-col items-center gap-0.5 text-xs px-3 py-1 text-slate-500">
        <i class="fa-solid fa-user-gear text-base"></i><span>Me</span>
    </button>
</nav>

<script>
function navigateToSection(name) {
    document.querySelectorAll('.sec').forEach(s => s.classList.remove('active','act','show'));
    const target = document.getElementById('section-' + name);
    if (target) target.classList.add('active');
    document.querySelectorAll('[data-section]').forEach(btn => {
        const isOn = btn.getAttribute('data-section') === name;
        btn.classList.toggle('bg-white/20', isOn);
        btn.classList.toggle('bg-white/10', !isOn);
    });
    // scroll main to top
    const main = document.querySelector('main');
    if (main) main.scrollTop = 0;
    if (name === 'profile' && typeof window.loadTabProfile === 'function') {
        window.loadTabProfile();
    }
    return false;
}
document.querySelectorAll('[data-section]').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        navigateToSection(btn.getAttribute('data-section'));
    });
});
</script>
</body>
</html>
<?php
// end of photo_dept.php
