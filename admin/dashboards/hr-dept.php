<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';
// session is already started and role checked in dashboard.php

// ------------------------------------------------------------
// Auth / session context
// ------------------------------------------------------------
$userName = (string) ($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'HR Department');
$userRole = (string) ($_SESSION['admin_role'] ?? 'HR Department');
$username = $_SESSION['admin_username'] ?? '';

$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');

// Determine path prefix for AJAX calls
$isInDashboardSubdir = false;
if (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/dashboards/') !== false) {
    $isInDashboardSubdir = true;
}
$ajaxPrefix = $isInDashboardSubdir ? '../' : '';

// ------------------------------------------------------------
// Helper: safe query + fetch helpers
// ------------------------------------------------------------
function db_fetch_all_assoc(mysqli $conn, string $sql, array $params = []): array
{
    $rows = [];
    if (!$stmt = $conn->prepare($sql)) {
        return $rows;
    }
    if (!empty($params)) {
        $types = '';
        $bind  = [];
        foreach ($params as $p) {
            if (is_int($p)) {
                $types .= 'i';
            } elseif (is_float($p)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $bind[] = $p;
        }
        $stmt->bind_param($types, ...$bind);
    }
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
    }
    $stmt->close();
    return $rows;
}

// ------------------------------------------------------------
// Live data: members, stats, recent
// ------------------------------------------------------------
$membersList = [];
$recentMembers = [];
$genderCounts = ['male' => 0, 'female' => 0];
$sectionCounts = [
    '7_13'     => 0,
    '14_17'    => 0,
    '18_plus'  => 0,
];
$statusCounts = [
    'active'   => 0,
    'warning'  => 0,
    'inactive' => 0,
    'archived' => 0,
];

$totalMembers = 0;
$memberTypeRegular = 0;
$memberTypeSpecial = 0; // member_type = special_regular (student + role)
$memberTypeWaiting = 0; // registration waiting (no ID yet)
$memberTypeHonor   = 0;

$atRiskStudents = 0; // placeholder: define logic later

if (isset($conn)) {
    // Pull latest 400 members for table (paginate if needed)
    $membersList = db_fetch_all_assoc(
        $conn,
        "SELECT 
            id,
            member_code,
            registration_type,
            member_type,
            membership_tier,
            archive_type,
            status,
            age_group,
            current_section,
            student_name,
            father_name,
            grandfather_name,
            baptismal_name,
            gender,
            phone_number,
            alt_phone_number,
            guardian_name,
            guardian_phone1,
            guardian_phone2,
            city,
            sub_city,
            woreda,
            mender,
            block_number,
            house_number,
            work_profession,
            education_level,
            registered_at,
            created_at
         FROM members
         WHERE status != 'archived'
         ORDER BY id DESC
         LIMIT 50"
    );

    // Recent 10
    $recentMembers = db_fetch_all_assoc(
        $conn,
        "SELECT 
            id,
            student_name,
            father_name,
            grandfather_name,
            member_type,
            membership_tier,
            status,
            current_section,
            created_at
         FROM members
         WHERE status != 'archived'
         ORDER BY id DESC
         LIMIT 10"
    );

    // Eligible new members (temporary, 6+ months) count for badge
    $eligibleNewMembersCount = 0;
    try {
        $r = $conn->query("SELECT COUNT(*) AS c FROM members WHERE membership_tier='temporary' AND status!='archived' AND registered_at <= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)");
        if ($r && $row = $r->fetch_assoc()) $eligibleNewMembersCount = (int)$row['c'];
    } catch (Throwable $e) {}

    // Eligible for upgrade (6 months+)
    $eligibleUpgrades = db_fetch_all_assoc(
        $conn,
        "SELECT 
            id, 
            student_name, 
            father_name, 
            grandfather_name, 
            phone_number, 
            registered_at
         FROM members
         WHERE membership_tier = 'temporary' 
           AND status != 'archived' 
           AND registered_at <= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         ORDER BY registered_at ASC"
    );

    // Aggregations - Total excludes archived, but we count archived separately
    $aggCounts = db_fetch_all_assoc(
        $conn,
        "SELECT 
            SUM(status != 'archived')           AS total_members,
            SUM(member_type='regular' AND status != 'archived')      AS regular_cnt,
            SUM(member_type='special_regular' AND status != 'archived') AS special_cnt,
            SUM(member_type='honorary' AND status != 'archived')     AS honor_cnt,
            SUM(registration_type='waiting' AND status != 'archived') AS waiting_cnt,
            SUM(gender='male' AND status != 'archived')              AS male_cnt,
            SUM(gender='female' AND status != 'archived')            AS female_cnt,
            SUM(status='active')            AS active_cnt,
            SUM(status='warning')           AS warning_cnt,
            SUM(status='inactive')          AS inactive_cnt,
            SUM(status='archived')          AS archived_cnt,
            SUM(age_group='7_13' AND status != 'archived')           AS ag_7_13_cnt,
            SUM(age_group='14_17' AND status != 'archived')          AS ag_14_17_cnt,
            SUM(age_group='18_plus' AND status != 'archived')        AS ag_18_plus_cnt
         FROM members"
    );

    if (!empty($aggCounts)) {
        $row = $aggCounts[0];
        $totalMembers      = (int) ($row['total_members'] ?? 0);
        $memberTypeRegular = (int) ($row['regular_cnt'] ?? 0);
        $memberTypeHonor   = (int) ($row['honor_cnt'] ?? 0);
        $memberTypeSpecial = (int) ($row['special_cnt'] ?? 0);
        $memberTypeWaiting = (int) ($row['waiting_cnt'] ?? 0);

        $genderCounts['male']   = (int) ($row['male_cnt'] ?? 0);
        $genderCounts['female'] = (int) ($row['female_cnt'] ?? 0);

        $statusCounts['active']   = (int) ($row['active_cnt'] ?? 0);
        $statusCounts['warning']  = (int) ($row['warning_cnt'] ?? 0);
        $statusCounts['inactive'] = (int) ($row['inactive_cnt'] ?? 0);
        $statusCounts['archived'] = (int) ($row['archived_cnt'] ?? 0);

        $sectionCounts['7_13']     = (int) ($row['ag_7_13_cnt'] ?? 0);
        $sectionCounts['14_17']    = (int) ($row['ag_14_17_cnt'] ?? 0);
        $sectionCounts['18_plus']  = (int) ($row['ag_18_plus_cnt'] ?? 0);
    }

    // Example "at risk" heuristic placeholder: warning + inactive (non-archived)
    $atRiskStudents = $statusCounts['warning'] + $statusCounts['inactive'];
}

// For display labels mapping
require_once __DIR__ . '/../backend/services/MemberCategory.php';

// Attendance alerting Phase 2: open warnings per department (server-side, cheap GROUP BY).
require_once __DIR__ . '/../backend/services/AttendanceWarningService.php';
require_once __DIR__ . '/../backend/services/AttendanceMonthRollupService.php';
// Attendance alerting Phase 4: current EC month roll-up (Education + Mezmur) for the "Monthly Attendance" card.
$attendanceMonthCard = \App\Services\AttendanceMonthRollupService::dashboardCard($conn, ['edu', 'mezmur']);
$attendanceWarningsAvailable = false;
$attendanceWarningCounts = ['edu' => ['total' => 0, 'critical' => 0], 'mezmur' => ['total' => 0, 'critical' => 0]];
try {
    $attendanceWarningsAvailable = \App\Services\AttendanceWarningService::available($conn);
    if ($attendanceWarningsAvailable) {
        $attendanceWarningCounts = \App\Services\AttendanceWarningService::openCounts($conn);
    }
} catch (\Throwable $e) {
    error_log('Dashboard attendance warning counts failed: ' . $e->getMessage());
}
function sectionLabelFromGroup(?string $ageGroup): string
{
    $letter = \App\Services\MemberCategory::letterFor($ageGroup);
    return $letter === null ? '' : \App\Services\MemberCategory::labelAm($letter)
        . ' (' . $letter . ')';
}

// ------------------------------------------------------------
// Recent members normalized for UI
// ------------------------------------------------------------
$recentMembers = array_map(function ($row) {
    $name = trim(($row['student_name'] ?? '') . ' ' . ($row['father_name'] ?? '') . ' ' . ($row['grandfather_name'] ?? ''));
    return [
        'name'   => $name ?: '—',
        'type'   => $row['member_type'] ?? 'መደበኛ',
        'status' => ucfirst($row['status'] ?? 'Active'),
        'section'=> $row['current_section'] ?? '',
        'date'   => !empty($row['created_at']) ? ethio_date_format(new DateTime($row['created_at'], new DateTimeZone('Africa/Addis_Ababa')), 'M j, Y') : '',
    ];
}, $recentMembers);

// ------------------------------------------------------------
// Helpers: next member code (simple illustrative logic)
// ------------------------------------------------------------
function generate_next_member_code(mysqli $conn): string
{
    $res = db_fetch_all_assoc($conn, "SELECT member_code FROM members WHERE member_code IS NOT NULL AND member_code <> '' ORDER BY id DESC LIMIT 1");
    $last = $res[0]['member_code'] ?? null;
    if (!$last) return '0001';
    $num = preg_replace('/\D/', '', $last);
    $next = str_pad((int)$num + 1, 4, '0', STR_PAD_LEFT);
    return $next;
}

$nextMemberCode = isset($conn) ? generate_next_member_code($conn) : '0001';

$hrClasses = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        $cr = $conn->query("SELECT id, class_name, class_name_en, class_code FROM classes WHERE is_active = 1 ORDER BY level_order, class_name");
        if ($cr) {
            while ($crow = $cr->fetch_assoc()) {
                $hrClasses[] = $crow;
            }
        }
    } catch (Throwable $e) {
        $hrClasses = [];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HR Department - <?= SCHOOL_NAME_SHORT_AM ?></title>
    <?= wbws_calendar_scripts($conn) ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- CSRF Token for AJAX requests -->
    <meta name="csrf-token" content="<?= generateCsrfToken() ?>">
    <script>
        // Global CSRF token for fetch requests
        const CSRF_TOKEN = '<?= generateCsrfToken() ?>';
        function openDataSyncModal() {
            var m = document.getElementById('dataSyncModal');
            if (!m) {
                alert('Excel Data Sync is not available. Please refresh the page.');
                return false;
            }
            m.classList.remove('hidden');
            m.style.display = 'flex';
            m.style.zIndex = '10000';
            document.body.style.overflow = 'hidden';
            return false;
        }
        function closeDataSyncModal() {
            var m = document.getElementById('dataSyncModal');
            if (!m) return;
            m.classList.add('hidden');
            m.style.display = 'none';
            document.body.style.overflow = '';
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeDataSyncModal();
        });
    </script>

    <link rel="icon"
          href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⛪</text></svg>">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/admin/js/chart.umd.min.js"></script>
    <script src="/admin/js/request-id.js"></script>
    <script src="/admin/js/danger_confirm.js?v=20261022dc4"></script>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous"/>

    <style>
        /* Form Theme Overrides */
        .theme-temporary {
            background-color: #fffbeb !important; /* amber-50 */
            border-color: #fcd34d !important; /* amber-300 */
        }
        .theme-temporary .rounded-xl.p-3, 
        .theme-temporary .rounded-xl.p-4 {
            background-color: #fef3c7 !important; /* amber-100 */
            border-color: #fde68a !important; /* amber-200 */
        }
        .theme-temporary h4.text-slate-800,
        .theme-temporary h4.text-emerald-900 {
            color: #92400e !important; /* amber-800 */
        }
        .theme-temporary .bg-slate-200, 
        .theme-temporary .bg-emerald-100 {
            background-color: #fde68a !important; /* amber-200 */
            color: #b45309 !important; /* amber-700 */
        }

        .theme-permanent {
            background-color: #ecfdf5 !important; /* emerald-50 */
            border-color: #6ee7b7 !important; /* emerald-300 */
        }
        .theme-permanent .rounded-xl.p-3, 
        .theme-permanent .rounded-xl.p-4 {
            background-color: #d1fae5 !important; /* emerald-100 */
            border-color: #a7f3d0 !important; /* emerald-200 */
        }
        .theme-permanent h4.text-slate-800,
        .theme-permanent h4.text-emerald-900 {
            color: #065f46 !important; /* emerald-800 */
        }
        .theme-permanent .bg-slate-200, 
        .theme-permanent .bg-emerald-100 {
            background-color: #a7f3d0 !important; /* emerald-200 */
            color: #047857 !important; /* emerald-700 */
        }

        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap');

        :root {
            --emerald-600: #059669;
            --emerald-700: #047857;
            --emerald-800: #065f46;
            --card-radius: 1.25rem;
        }

        body {
            font-family: 'Poppins', sans-serif;
            -webkit-font-smoothing: antialiased;
            background: #f5f7fb;
        }

        .amharic-text {
            font-family: 'Noto Serif Ethiopic', serif;
        }

        .sidebar-gradient {
            width: 260px;
            background: linear-gradient(180deg, #064e3b 0%, #047857 40%, #16a34a 100%);
            position: sticky;
            top: 0;
            align-self: flex-start;
            height: 100vh;
            overflow-y: auto;
            color: #ecfdf5;
            padding: 18px 16px 20px;
        }

        .stat-card {
            border-radius: 1.5rem;
            color: #ffffff;
            box-shadow: 0 12px 25px rgba(15, 118, 110, 0.35);
            transition: transform 0.18s ease-out, box-shadow 0.18s ease-out;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 32px rgba(15, 118, 110, 0.45);
        }

        .panel {
            border-radius: 1rem;
            background: #ffffff;
            box-shadow: 0 8px 20px rgba(148, 163, 184, 0.35);
        }


        /* Family Link workspace */
        .fam-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.6rem}
        .fam-kpi{display:flex;gap:.6rem;align-items:flex-start;background:#fff;border:1px solid #e2e8f0;border-radius:1rem;padding:.7rem .8rem;transition:box-shadow .15s,transform .15s}
        .fam-kpi-click{cursor:pointer}.fam-kpi-click:hover{box-shadow:0 8px 20px rgba(148,163,184,.3);transform:translateY(-2px)}
        .fam-kpi-icon{width:2.1rem;height:2.1rem;border-radius:.8rem;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .fam-kpi-value{font-size:1.25rem;font-weight:800;color:#0f172a;line-height:1.1}.fam-kpi-label{font-size:.72rem;font-weight:600;color:#334155}.fam-kpi-hint{font-size:.65rem;color:#94a3b8}
        .fam-tabs{display:flex;gap:.25rem;border-bottom:1px solid #e2e8f0;margin-bottom:.9rem;overflow-x:auto}
        .fam-tab{padding:.5rem .8rem;font-size:.75rem;font-weight:600;color:#64748b;border-bottom:2px solid transparent;white-space:nowrap;display:flex;align-items:center;gap:.35rem}
        .fam-tab:hover{color:#0f172a}.fam-tab-on{color:#047857;border-bottom-color:#059669}
        .fam-tab-count{font-size:.62rem;font-weight:800;background:#f59e0b;color:#0f172a;border-radius:999px;padding:.05rem .4rem}
        .fam-input{width:100%;padding:.5rem .75rem;border:1px solid #e2e8f0;border-radius:.75rem;font-size:.75rem;color:#0f172a;background:#fff}
        .fam-input:focus{outline:none;border-color:#34d399;box-shadow:0 0 0 3px rgba(52,211,153,.2)}.fam-input-sm{padding:.3rem .5rem;font-size:.7rem;border-radius:.5rem;width:auto}
        .fam-chip{padding:.3rem .65rem;border-radius:999px;border:1px solid #e2e8f0;background:#fff;font-size:.68rem;font-weight:600;color:#64748b}
        .fam-chip:hover{border-color:#cbd5e1}.fam-chip-on{background:#0f172a;color:#fff;border-color:#0f172a}
        .fam-btn{padding:.4rem .7rem;border-radius:.6rem;border:1px solid #e2e8f0;background:#fff;font-size:.72rem;font-weight:600;color:#334155;display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap}
        .fam-btn:hover{background:#f8fafc;border-color:#cbd5e1}.fam-btn:disabled{opacity:.55;cursor:not-allowed}
        .fam-btn-primary{background:#059669;border-color:#059669;color:#fff}.fam-btn-primary:hover{background:#047857;border-color:#047857}
        .fam-btn-danger{color:#be123c}.fam-btn-danger:hover{background:#fff1f2;border-color:#fecdd3}.fam-btn-sm{padding:.25rem .5rem;font-size:.66rem}
        .fam-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.74rem}
        .fam-table th{text-align:left;font-size:.62rem;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;padding:.4rem .6rem;border-bottom:1px solid #e2e8f0;font-weight:700}
        .fam-table td{padding:.55rem .6rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
        .fam-row{cursor:pointer}.fam-row:hover td{background:#f0fdf4}.fam-table-sm td{padding:.4rem .5rem}
        .fam-pill{display:inline-block;font-size:.64rem;background:#f1f5f9;color:#334155;border-radius:999px;padding:.1rem .5rem;margin:.1rem .15rem .1rem 0}.fam-pill-more{background:#e2e8f0}.fam-pill-ok{background:#dcfce7;color:#166534;font-weight:600}
        .fam-count{display:inline-flex;min-width:1.4rem;height:1.4rem;align-items:center;justify-content:center;border-radius:999px;background:#f1f5f9;color:#334155;font-weight:800;font-size:.68rem;margin-right:.25rem}.fam-count-multi{background:#d1fae5;color:#047857}
        .fam-empty{text-align:center;padding:2rem 1rem;color:#475569;font-size:.78rem;background:#f8fafc;border:1px dashed #e2e8f0;border-radius:1rem}.fam-empty-sm{padding:.8rem}
        .fam-note{display:flex;gap:1rem;align-items:flex-start;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.9rem;padding:.75rem .9rem;font-size:.72rem;color:#475569}
        .fam-note-warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
        .fam-progress{height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;margin-top:.4rem}.fam-progress div{height:100%;width:40%;background:#059669;animation:famSlide 1.1s infinite linear}
        @keyframes famSlide{from{transform:translateX(-100%)}to{transform:translateX(250%)}}
        .fam-sug{background:#fff;border:1px solid #fde68a;border-radius:1rem;padding:.8rem}
        .fam-sug-hd{display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem}
        .fam-score{font-weight:800;font-size:.72rem;padding:.15rem .5rem;border-radius:999px}.fam-score-hi{background:#fee2e2;color:#b91c1c}.fam-score-mid{background:#fef3c7;color:#b45309}
        .fam-ev{font-size:.66rem;color:#475569;background:#f1f5f9;border-radius:999px;padding:.1rem .45rem;margin-left:.2rem}.fam-ev-strong{background:#dcfce7;color:#166534}.fam-ev-muted{color:#94a3b8;background:transparent}
        .fam-cmp-grid{display:grid;grid-template-columns:1fr auto 1fr;gap:.6rem;align-items:stretch}
        .fam-cmp{background:#f8fafc;border:1px solid #e2e8f0;border-radius:.8rem;padding:.6rem .7rem;font-size:.74rem}
        .fam-cmp-vs{align-self:center;font-size:.65rem;font-weight:800;color:#94a3b8}
        @media (max-width:640px){.fam-cmp-grid{grid-template-columns:1fr}.fam-cmp-vs{text-align:center}.hide-sm{display:none}}
        .fam-activity{list-style:none;margin:0;padding:0}.fam-activity li{display:flex;gap:.6rem;padding:.5rem 0;border-bottom:1px solid #f1f5f9}.fam-activity li i{width:1.2rem;text-align:center;margin-top:.15rem}
        .fam-drawer{position:fixed;inset:0;z-index:70;display:none}.fam-drawer.open{display:block}
        .fam-drawer-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.45)}
        .fam-drawer-panel{position:absolute;top:0;right:0;bottom:0;width:100%;max-width:720px;background:#f8fafc;display:flex;flex-direction:column;box-shadow:-10px 0 40px rgba(0,0,0,.2);animation:sheet-slide-left .25s ease-out}
        .fam-drawer-hd{display:flex;align-items:center;gap:.6rem;padding:.75rem 1rem;background:#fff;border-bottom:1px solid #e2e8f0}
        .fam-drawer-body{flex:1;overflow-y:auto;padding:1rem}
        .fam-gh{display:flex;gap:.8rem;align-items:center;margin-bottom:.9rem}
        .fam-avatar{width:3rem;height:3rem;border-radius:1rem;background:linear-gradient(135deg,#059669,#0ea5e9);color:#fff;font-weight:800;font-size:1.2rem;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .fam-card{background:#fff;border:1px solid #e2e8f0;border-radius:1rem;padding:.9rem;margin-bottom:.8rem}
        .fam-card-hd{display:flex;justify-content:space-between;align-items:center;font-size:.78rem;font-weight:700;color:#0f172a;margin-bottom:.6rem}
        .fam-form{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem}.fam-form .fam-field:first-child{grid-column:1 / -1}
        .fam-field span{display:block;font-size:.62rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.2rem}
        .fam-form-actions{grid-column:1 / -1;display:flex;justify-content:flex-end;margin-top:.2rem}
        @media (max-width:640px){.fam-form{grid-template-columns:1fr 1fr}}
        .fam-dropdown{position:absolute;z-index:50;left:0;right:0;margin-top:2px;max-height:240px;overflow:auto;background:#fff;border:1px solid #e2e8f0;border-radius:.7rem;box-shadow:0 10px 30px rgba(15,23,42,.15)}
        .fam-dd-item{display:block;width:100%;text-align:left;padding:.5rem .7rem;border:0;background:transparent;font-size:.72rem;color:#0f172a;border-bottom:1px solid #f1f5f9;cursor:pointer}.fam-dd-item:hover{background:#f0fdf4}
        .fam-dd-empty{padding:.5rem .7rem;font-size:.7rem;color:#94a3b8}
        .fam-toast{position:fixed;left:50%;bottom:1.5rem;transform:translateX(-50%) translateY(20px);opacity:0;background:#0f172a;color:#fff;font-size:.75rem;padding:.6rem 1rem;border-radius:.8rem;z-index:200;transition:all .25s;max-width:90vw}
        .fam-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}.fam-toast-err{background:#be123c}
        .content-section { display: none; }
        .content-section.active { display: block; }

        /* Mobile / app-style tweaks */
        @media (max-width: 768px) {
            body {
                background: #eef2f7;
                padding-bottom: 76px; /* space for bottom nav */
            }

            .mobile-touch-target {
                min-height: 48px;
                min-width: 48px;
            }

            .mobile-card {
                border-radius: 1.25rem;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.10);
                background: rgba(255, 255, 255, 0.92);
                backdrop-filter: blur(6px);
            }

            .panel {
                border-radius: 1.25rem;
            }
        }

        /* Settings tabs */
        .stab{padding:7px 14px;border-radius:10px;font-size:11px;font-weight:500;cursor:pointer;border:1px solid #e2e8f0;background:#fff;color:#64748b;transition:all .15s;white-space:nowrap}
        .stab:hover{background:#f8fafc;border-color:#cbd5e1}
        .stab-on{background:#0f172a!important;color:#fff!important;border-color:#0f172a!important}
        .settings-pane{animation:fadeSlide .25s ease-out}
        @keyframes fadeSlide{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
        .sys-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f1f5f9}
        .sys-row:last-child{border-bottom:none}
        .sys-label{font-size:11px;color:#64748b}.sys-val{font-size:12px;font-weight:600;color:#1e293b}
        /* Attendance tabs */
        .atab{padding:7px 14px;border-radius:10px;font-size:11px;font-weight:500;cursor:pointer;border:1px solid #fed7aa;background:#fff;color:#9a3412;transition:all .15s;white-space:nowrap}
        .atab:hover{background:#fff7ed;border-color:#fb923c}
        .atab-on{background:#ea580c!important;color:#fff!important;border-color:#ea580c!important}
        .att-pane{animation:fadeSlide .25s ease-out}
        .att-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:600}
        .att-present{background:#d1fae5;color:#065f46}.att-absent{background:#fee2e2;color:#991b1b}
        .att-late{background:#fef3c7;color:#92400e}.att-excused{background:#dbeafe;color:#1e40af}

        .bottom-nav-shadow {
            box-shadow: 0 -4px 16px rgba(15, 23, 42, 0.25);
        }

        .nav-pill-active {
            background: rgba(255, 223, 0, 1);
        }

        /* Modal / sheet */
        .sheet {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: flex-end;
            justify-content: center;
            z-index: 80;
        }
        .sheet.open { display: flex; }
        .sheet .sheet-body {
            width: 100%;
            max-width: 100%;
            height: 100%;
            background: #f8fafc;
            border-radius: 0;
            box-shadow: 0 -10px 40px rgba(0,0,0,0.2);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            animation: sheet-slide-up 0.3s ease-out;
        }

        @keyframes sheet-slide-up {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Desktop: Side panel style */
        @media (min-width: 768px) {
            .sheet {
                align-items: stretch;
                justify-content: flex-end;
                background: rgba(0, 0, 0, 0.4);
            }
            .sheet .sheet-body {
                max-width: 800px;
                height: 100%;
                border-radius: 0;
                border-left: 1px solid #e2e8f0;
                animation: sheet-slide-left 0.3s ease-out;
            }
        }

        @keyframes sheet-slide-left {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        /* Desktop: Make it cover the main content area (right side) */
        @media (min-width: 768px) {
            .sheet {
                left: 16rem; /* Offset by sidebar width (w-64 = 16rem) */
                width: calc(100% - 16rem);
                background: transparent; /* No overlay needed if it covers content */
            }
            .sheet .sheet-body {
                max-width: 100%;
                border-left: 1px solid #e2e8f0;
            }
        }

        /* Animations */
        .toast-enter {
            animation: toast-in 0.25s ease-out;
        }
        @keyframes toast-in {
            from { transform: translateY(-16px); opacity: 0; }
            to   { transform: translateY(0); opacity: 1; }
        }

        /* Badge colors */
        .badge-active   { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .badge-warning  { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
        .badge-inactive { background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; }
        .badge-archived { background: #e2e8f0; color: #334155; border: 1px solid #cbd5e1; }

        /* Advanced search pills */
        .filter-pill {
            border: 1px solid #e2e8f0;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            color: #475569;
            cursor: pointer;
        }
        .filter-pill.active {
            background: #d1fae5;
            border-color: #34d399;
            color: #065f46;
        }

        /* Editable row icons */
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #fff;
            transition: all 0.12s ease;
        }
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(15,23,42,0.12);
            border-color: #cbd5e1;
        }

        /* Sticky top bar mobile */
        .mobile-sticky-header {
            position: sticky;
            top: 0;
            z-index: 30;
            background: linear-gradient(180deg, #047857 0%, #0f766e 100%);
            color: #fff;
            padding: 12px 16px;
        }

        /* Hide scrollbar for bottom nav */
        .hide-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .hide-scrollbar::-webkit-scrollbar {
            display: none;
        }

        /* Active bottom nav button */
        .nav-bottom-active {
            background: rgba(255, 255, 255, 0.2);
            opacity: 1 !important;
        }

        /* Inline labels */
        .label-soft {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #94a3b8;
            font-weight: 700;
        }
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
<?php include __DIR__ . "/../theme.php"; ?>
</head>
<body class="bg-slate-100">
<?php if (function_exists("ay_context_bar_html")) echo ay_context_bar_html($conn ?? null); ?>

<div class="min-h-screen flex flex-col md:flex-row">

    <!-- Desktop Sidebar -->
    <aside class="hidden md:flex sidebar-gradient school-sidebar text-white w-64 flex-col py-5 px-4">
        <div class="flex items-center mb-6">
            <div class="w-11 h-11 rounded-2xl bg-white/15 flex items-center justify-center shadow-md">
                <i class="fa-solid fa-id-card-clip text-xl"></i>
            </div>
            <div class="ml-3">
                <div class="text-sm font-bold amharic-text">HR Department</div>
                <div class="text-[11px] text-emerald-100 amharic-text"><?= SCHOOL_NAME_SHORT_AM ?> የሰው ሀይል አስተዳደር (HR)</div>
            </div>
        </div>

        <nav class="flex-1 flex flex-col space-y-1 text-sm">
            <a href="#" data-section="dashboard"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-gauge text-sm"></i>
                </span>
                <span class="font-semibold">Dashboard</span>
            </a>

            <a href="#" data-section="members"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-users text-sm"></i>
                </span>
                <span class="font-semibold">All Members</span>
            </a>

            <a href="#" data-section="family"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-people-roof text-sm"></i>
                </span>
                <span class="font-semibold">Family Link</span>
                <span id="famQueueBadge" class="ml-auto hidden text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-amber-400 text-slate-900"></span>
            </a>

            <a href="#" data-section="newmembers"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-user-plus text-sm"></i>
                </span>
                <span class="font-semibold">New Members</span>
                <?php if (!empty($eligibleNewMembersCount)): ?>
                <span class="ml-auto px-2 py-0.5 rounded-full bg-emerald-400 text-emerald-900 text-[10px] font-bold"><?= (int)$eligibleNewMembersCount ?></span>
                <?php endif; ?>
            </a>

            <button data-section="manage"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-pen-to-square text-sm"></i>
                </span>
                <span class="font-semibold">Manage Members</span>
            </button>

            <button data-section="archive"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-regular fa-folder-open text-sm"></i>
                </span>
                <span class="font-semibold">Old Members Archive</span>
            </button>

                <button data-section="idcards"
                        class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                    <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                        <i class="fa-solid fa-id-card text-sm"></i>
                    </span>
                    <span class="font-semibold">ID Cards</span>
                </button>

            <a href="/admin/groups.php"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-layer-group text-sm"></i>
                </span>
                <span class="font-semibold">Groups</span>
            </a>





            <button type="button" onclick="return openDataSyncModal();"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-file-excel text-sm"></i>
                </span>
                <span class="font-semibold">Data Sync (Excel)</span>
            </button>

            <a href="/admin/telegram_registration.php"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-sky-500/30 flex items-center justify-center">
                    <i class="fa-brands fa-telegram text-sm"></i>
                </span>
                <span class="font-semibold">Telegram Registration</span>
            </a>

            <button data-section="reports"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-file-lines text-sm"></i>
                </span>
                <span class="font-semibold">Exports & Reports</span>
            </button>

            <button data-section="settings"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-gear text-sm"></i>
                </span>
                <span class="font-semibold">Settings</span>
            </button>

            <button data-comm-open="inbox"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-teal-500/30 flex items-center justify-center">
                    <i class="fa-solid fa-comments text-sm"></i>
                </span>
                <span class="font-semibold">Communication</span>
            </button>

            <button onclick="navigateToSection('profile')"
               data-section="profile"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition text-white text-left w-full border-none cursor-pointer">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-user-gear text-sm"></i>
                </span>
                <span class="font-semibold">My Profile</span>
            </button>

        </nav>

        <div class="mt-5 space-y-2">
            <?php
            require_once __DIR__ . '/../components/sidebar_profile_item.php';
            renderSidebarUserCard($userName, 'HR Dept', $todayFormatted ?? '', strtoupper(substr($userName, 0, 1)), 'linear-gradient(135deg,#059669,#10b981)');
            ?>
            <a href="/admin/logout.php"
               class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl bg-red-500/95 text-white text-xs font-semibold shadow hover:bg-red-600 transition">
                <span>Logout</span>
                <i class="fa-solid fa-power-off text-xs"></i>
            </a>
        </div>
    </aside>

    <!-- Main -->
    <div class="flex-1 flex flex-col">
        <!-- Mobile top header -->
        <div class="mobile-sticky-header md:hidden flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/15 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-circle-info text-lg"></i>
                </div>
                <div>
                    <div class="text-sm font-bold">HR Department</div>
                    <div class="text-[11px] text-emerald-100 amharic-text">የሰው ሀይል አስተዳደር · መመዝገብ · መቆጣጠር</div>
                </div>
            </div>
            <div class="text-right text-[11px]">
                <div class="font-semibold"><?= e($todayFormatted) ?></div>
                <div class="text-emerald-100"><?= e($userName) ?></div>
            </div>
        </div>

        <header class="hidden md:flex bg-emerald-700 text-white px-3 sm:px-6 py-3 sm:py-4 items-center justify-between shadow-md">
            <div class="flex items-center gap-3">
                <button class="md:hidden w-9 h-9 rounded-full bg-white/10 flex items-center justify-center">
                    <i class="fa-solid fa-bars text-sm"></i>
                </button>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center">
                            <i class="fa-solid fa-circle-info text-lg"></i>
                        </span>
                        <div>
                            <h1 class="text-base sm:text-lg font-bold amharic-text">
                                HR Department
                            </h1>
                            <p class="text-[11px] sm:text-xs text-emerald-100 amharic-text">
                                የሰው ሀይል አስተዳደር · የአባላት መመዝገብ እና መቆጣጠር
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <!-- Notification Bell -->
                <?php 
                include __DIR__ . '/../components/notification_center.php';
                echo renderNotificationCenter();
                ?>
                
                <div class="hidden sm:flex flex-col text-right text-xs">
                    <span class="font-semibold"><?= e($todayFormatted) ?></span>
                    <span class="text-emerald-100"><?= e($userName) ?></span>
                </div>
                <div class="w-9 h-9 rounded-full bg-white/95 flex items-center justify-center text-xs font-bold text-emerald-700 sm:hidden">
                    <?= strtoupper(substr($userName, 0, 1)) ?>
                </div>
            </div>
        </header>

        <main class="flex-1 p-3 sm:p-5 space-y-4 sm:space-y-5">

            <!-- DASHBOARD -->
            <section id="section-dashboard" class="content-section active">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2 sm:mb-3">
                    <div>
                        <div class="flex items-center gap-2 text-emerald-700">
                            <i class="fa-solid fa-gauge-high text-sm"></i>
                            <span class="uppercase tracking-wide text-[11px] font-semibold">Dashboard</span>
                        </div>
                        <h2 class="text-lg sm:text-xl font-bold text-slate-800">
                            HR Department Overview
                        </h2>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="px-3 py-1.5 rounded-full border border-emerald-200 text-emerald-700 bg-white">
                            Live statistics
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-4">
                    <div class="stat-card p-3 sm:p-4 flex flex-col justify-between" style="background:linear-gradient(135deg, <?= THEME_PRIMARY_DARK ?>, <?= THEME_PRIMARY ?>, <?= THEME_PRIMARY_LIGHT ?>)">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">Total Members</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-users text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold">
                            <?= $totalMembers ?>
                        </div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-80">
                            Active (non-archived) members
                        </div>
                    </div>

                    <div class="stat-card p-3 sm:p-4 flex flex-col justify-between" style="background:linear-gradient(135deg, <?= THEME_ACCENT ?>, <?= THEME_ACCENT_2 ?>)">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">By Member Type</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-tags text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-[11px] sm:text-xs space-y-0.5">
                            <div class="flex justify-between">
                                <span>መደበኛ</span>
                                <span class="font-semibold"><?= $memberTypeRegular ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>ልዩ መደበኛ</span>
                                <span class="font-semibold"><?= $memberTypeSpecial ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>የክብር አባላት</span>
                                <span class="font-semibold"><?= $memberTypeHonor ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-gradient-to-tr from-amber-500 via-orange-400 to-yellow-400 p-3 sm:p-4 flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">By Status</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-heart-pulse text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-[11px] sm:text-xs space-y-0.5">
                            <div class="flex justify-between">
                                <span>Active</span>
                                <span class="font-semibold"><?= $statusCounts['active'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Warning</span>
                                <span class="font-semibold"><?= $statusCounts['warning'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Inactive</span>
                                <span class="font-semibold"><?= $statusCounts['inactive'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Archived</span>
                                <span class="font-semibold"><?= $statusCounts['archived'] ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance alerting Phase 2: "Open Attendance Warnings" replaces the
                         members.status-based "At-risk" heuristic (requirements §4–§6, §9). -->
                    <a href="/admin/attendance_warnings.php" class="stat-card bg-gradient-to-tr from-red-500 via-rose-500 to-orange-400 p-3 sm:p-4 flex flex-col justify-between" id="aw-dashboard-card" title="Open the attendance warnings list" style="text-decoration:none;color:inherit">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">Open Attendance Warnings</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-triangle-exclamation text-sm"></i>
                            </div>
                        </div>
                        <?php if ($attendanceWarningsAvailable): ?>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold" data-aw-total>
                            <?= (int)$attendanceWarningCounts['edu']['total'] + (int)$attendanceWarningCounts['mezmur']['total'] ?>
                        </div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-90 flex gap-3 flex-wrap">
                            <span>Education <b data-aw-edu><?= (int)$attendanceWarningCounts['edu']['total'] ?></b><?php if ((int)$attendanceWarningCounts['edu']['critical'] > 0): ?> <span class="opacity-80">(<?= (int)$attendanceWarningCounts['edu']['critical'] ?> critical)</span><?php endif; ?></span>
                            <span>Mezmur <b data-aw-mezmur><?= (int)$attendanceWarningCounts['mezmur']['total'] ?></b><?php if ((int)$attendanceWarningCounts['mezmur']['critical'] > 0): ?> <span class="opacity-80">(<?= (int)$attendanceWarningCounts['mezmur']['critical'] ?> critical)</span><?php endif; ?></span>
                        </div>
                        <?php else: ?>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold">—</div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-80">Not available yet (feature flag / sql/072)</div>
                        <?php endif; ?>
                    </a>
                    <!-- Attendance alerting Phase 4: Monthly Attendance (current Ethiopian month; both departments, read-only). -->
                    <a href="/admin/attendance_monthly.php" class="stat-card bg-gradient-to-tr from-teal-600 via-teal-500 to-emerald-400 p-3 sm:p-4 flex flex-col justify-between" id="am-dashboard-card" title="Monthly attendance report (Ethiopian months)" style="text-decoration:none;color:inherit">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">Monthly Attendance · <?= htmlspecialchars($attendanceMonthCard['month_label_am'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-calendar-days text-sm"></i>
                            </div>
                        </div>
                        <?php if ($attendanceMonthCard['available']): ?>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold" data-am-below><?= (int)$attendanceMonthCard['below'] ?></div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-90 flex gap-3 flex-wrap">
                            <span>below threshold</span>
                            <span>Education <b><?= (int)($attendanceMonthCard['per_dept']['edu']['below'] ?? 0) ?></b></span>
                            <span>Mezmur <b><?= (int)($attendanceMonthCard['per_dept']['mezmur']['below'] ?? 0) ?></b></span>
                            <?php if ($attendanceMonthCard['avg_rate'] !== null): ?><span>avg <b><?= $attendanceMonthCard['avg_rate'] ?>%</b></span><?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold">—</div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-80">Not available yet (feature flag / sql/074)</div>
                        <?php endif; ?>
                    </a>
                </div>

                <!-- Eligible Upgrades Widget -->
                <?php if (!empty($eligibleUpgrades)): ?>
                <div class="mb-4 panel p-4 border-l-4 border-amber-500 bg-amber-50 rounded-2xl">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-amber-900 flex items-center gap-2">
                            <i class="fa-solid fa-bell text-amber-500"></i>
                            Eligible for Permanent Upgrade (6+ Months)
                        </h3>
                        <span class="px-2.5 py-0.5 rounded-full bg-amber-200 text-amber-800 text-xs font-bold">
                            <?= count($eligibleUpgrades) ?> Pending
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="text-xs text-amber-700 bg-amber-100/50 uppercase">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Name</th>
                                    <th class="px-3 py-2 font-medium">Registered</th>
                                    <th class="px-3 py-2 font-medium">Phone</th>
                                    <th class="px-3 py-2 font-medium text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-amber-200/50">
                                <?php foreach ($eligibleUpgrades as $m): ?>
                                <tr class="hover:bg-amber-100/50 transition">
                                    <td class="px-3 py-2 font-medium text-amber-900">
                                        <?= htmlspecialchars(trim($m['student_name'].' '.$m['father_name'].' '.$m['grandfather_name'])) ?>
                                    </td>
                                    <td class="px-3 py-2 text-amber-800 text-xs">
                                        <?= date('M j, Y', strtotime($m['registered_at'])) ?>
                                    </td>
                                    <td class="px-3 py-2 text-amber-800 text-xs">
                                        <?= htmlspecialchars($m['phone_number'] ?: 'N/A') ?>
                                    </td>
                                    <td class="px-3 py-2 text-right"></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                    <div class="panel p-4 mobile-card">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600">
                                        <i class="fa-solid fa-child"></i>
                                    </span>
                                    <span>Section Distribution (by current section)</span>
                                </h3>
                                <p class="text-[11px] text-slate-500">
                                    Live numbers from members table
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-3 mt-3">
                            <div class="p-3 rounded-2xl bg-sky-50 border border-sky-100">
                                <div class="text-[11px] text-sky-700 amharic-text">ህጻናት (A)</div>
                                <div class="text-xl font-bold text-sky-900 mt-1"><?= $sectionCounts['7_13'] ?></div>
                                <div class="text-[11px] text-sky-500">Section</div>
                            </div>
                            <div class="p-3 rounded-2xl bg-amber-50 border border-amber-100">
                                <div class="text-[11px] text-amber-700 amharic-text">ማዕከላዊያን (B)</div>
                                <div class="text-xl font-bold text-amber-900 mt-1"><?= $sectionCounts['14_17'] ?></div>
                                <div class="text-[11px] text-amber-500">Section</div>
                            </div>
                            <div class="p-3 rounded-2xl bg-rose-50 border border-rose-100">
                                <div class="text-[11px] text-rose-700 amharic-text">ወጣቶች (C)</div>
                                <div class="text-xl font-bold text-rose-900 mt-1"><?= $sectionCounts['18_plus'] ?></div>
                                <div class="text-[11px] text-rose-500">Section</div>
                            </div>
                        </div>

                        <div class="mt-4 h-28">
                            <canvas id="sectionChart"></canvas>
                        </div>
                    </div>

                    <div class="panel p-4 mobile-card">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-xl bg-sky-100 flex items-center justify-center text-sky-600">
                                        <i class="fa-solid fa-venus-mars"></i>
                                    </span>
                                    <span>Gender Distribution</span>
                                </h3>
                                <p class="text-[11px] text-slate-500">
                                    Visual balance of male vs female members
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 h-28">
                            <canvas id="genderChart"></canvas>
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-2 text-[11px]">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-sky-500"></span>
                                <span>Male</span>
                                <span class="ml-auto font-semibold"><?= $genderCounts['male'] ?></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                <span>Female</span>
                                <span class="ml-auto font-semibold"><?= $genderCounts['female'] ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel p-4 mobile-card">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </span>
                                <span>Recent Members</span>
                            </h3>
                            <p class="text-[11px] text-slate-500">
                                Latest registrations handled by the HR Department
                            </p>
                        </div>
                        <button class="hidden sm:inline-flex items-center gap-1 text-[11px] text-emerald-700 hover:text-emerald-900">
                            View all →
                        </button>
                    </div>

                    <div class="overflow-x-auto -mx-2 sm:mx-0">
                        <table class="min-w-full text-[11px] sm:text-xs">
                            <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200">
                                <th class="py-2 px-2 sm:px-3">Name</th>
                                <th class="py-2 px-2 sm:px-3">Type</th>
                                <th class="py-2 px-2 sm:px-3">Status</th>
                                <th class="py-2 px-2 sm:px-3">Section</th>
                                <th class="py-2 px-2 sm:px-3">Registered</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php foreach ($recentMembers as $row): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="py-2 px-2 sm:px-3 amharic-text"><?= e($row['name']) ?></td>
                                    <td class="py-2 px-2 sm:px-3 amharic-text">
                                        <?= e($row['type']) ?>
                                    </td>
                                    <td class="py-2 px-2 sm:px-3">
                                        <?php
                                        $statusChip = 'badge-active';
                                        if (stripos($row['status'], 'Warning') !== false) $statusChip = 'badge-warning';
                                        if (stripos($row['status'], 'Inactive') !== false) $statusChip = 'badge-inactive';
                                        ?>
                                        <span class="chip <?= $statusChip ?>"><?= e($row['status']) ?></span>
                                    </td>
                                    <td class="py-2 px-2 sm:px-3 amharic-text">
                                        <?= esc($row['section'], '—') ?>
                                    </td>
                                    <td class="py-2 px-2 sm:px-3">
                                        <?= e($row['date'] ?? '') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ALL MEMBERS -->
            <section id="section-members" class="content-section">
                <div id="membersHeaderSection" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4 sticky top-0 z-50 bg-white shadow-sm border-b border-slate-200 py-3 px-4 -mx-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600">
                                <i class="fa-solid fa-users"></i>
                            </span>
                            <span>All Members</span>
                        </h3>
                        <p class="text-[11px] text-slate-500 hidden sm:block">
                            Full registration and management for HR Department members.
                        </p>
                    </div>
                    <div class="flex gap-2 bg-slate-100 p-1 rounded-2xl border border-slate-200">
                        <button type="button" id="btnDataSync"
                                onclick="return openDataSyncModal();"
                                class="mobile-touch-target inline-flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-blue-600 text-xs sm:text-sm font-semibold transition-all hover:bg-white hover:shadow-sm">
                            <i class="fa-solid fa-file-excel text-xs"></i>
                            <span class="hidden sm:inline">Data Sync</span>
                        </button>
                        <div class="w-px bg-slate-200 my-1"></div>
                        <button type="button"
                                id="btnRegisterTemporary"
                                onclick="toggleMemberRegistrationForm(true, 'temporary')"
                                class="mobile-touch-target inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-slate-600 text-xs sm:text-sm font-semibold transition-all hover:bg-white hover:shadow-sm">
                            <i class="fa-solid fa-hourglass-half text-xs"></i>
                            <span>Register Temporary</span>
                        </button>
                        <button type="button"
                                id="btnRegisterPermanent"
                                onclick="toggleMemberRegistrationForm(true, 'permanent')"
                                class="mobile-touch-target inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-slate-600 text-xs sm:text-sm font-semibold transition-all hover:bg-white hover:shadow-sm">
                            <i class="fa-solid fa-user-check text-xs"></i>
                            <span>Register Permanent</span>
                        </button>
                    </div>
                </div>

                <div id="membersListPlaceholder" class="space-y-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div>
                            <h2 class="text-base md:text-lg font-semibold text-slate-900 amharic-text">
                                ሁሉም አባላት (All Members)
                            </h2>
                            <p class="text-xs text-slate-500">
                                Use search and filters to find members. All results are paginated.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2 md:justify-end w-full md:w-auto">
                            <div class="relative flex-1 min-w-[240px]">
                                <input autocomplete="off" id="memberSearchInput"
                                       type="text"
                                       class="pl-9 pr-3 py-2 rounded-xl border border-slate-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 w-full"
                                       placeholder="Search any field (name, code, phone, profession, education, city...)">
                                <span class="absolute left-3 top-2.5 text-slate-400 text-sm">🔍</span>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <select id="filterRegistrationType"
                                        class="text-xs border border-slate-200 rounded-xl px-2 py-2 bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                    <option value="">Reg. Type (All)</option>
                                    <option value="waiting">Waiting</option>
                                    <option value="transfer">Transfer</option>
                                    <option value="direct">Direct</option>
                                </select>

                                <select id="filterMemberType"
                                        class="text-xs border border-slate-200 rounded-xl px-2 py-2 bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                    <option value="">Member Type (All)</option>
                                    <option value="regular">Regular</option>
                                    <option value="special_regular">Special Regular</option>
                                    <option value="honorary">Honorary</option>
                                </select>

                                <select id="filterStatus"
                                        class="text-xs border border-slate-200 rounded-xl px-2 py-2 bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                    <option value="">Status (All)</option>
                                    <option value="active">Active</option>
                                    <option value="warning">Warning</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="archived">Archived</option>
                                </select>

                                <select id="filterGender"
                                        class="text-xs border border-slate-200 rounded-xl px-2 py-2 bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                    <option value="">Gender (All)</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </select>

                                <select id="filterAgeGroup"
                                        class="text-xs border border-slate-200 rounded-xl px-2 py-2 bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                    <option value="">Section (All)</option>
                                    <?php foreach (\App\Services\MemberCategory::sections() as $hrCode => $hrSec): ?><option value="<?= $hrCode ?>"><?= e($hrSec['am']) ?> (<?= e($hrSec['letter']) ?>)</option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white/90 backdrop-blur rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-xs">
                                <thead class="bg-slate-50 border-b border-slate-100">
                                <tr class="text-[11px] text-slate-500">
                                    <th class="px-3 py-2 text-left">#</th>
                                    <th class="px-3 py-2 text-left">Member</th>
                                    <th class="px-3 py-2 text-left">Code</th>
                                    <th class="px-3 py-2 text-left">Reg. Type</th>
                                    <th class="px-3 py-2 text-left">Member Type</th>
                                    <th class="px-3 py-2 text-left">Gender</th>
                                    <th class="px-3 py-2 text-left">Section</th>
                                    <th class="px-3 py-2 text-left">Status</th>
                                    <th class="px-3 py-2 text-left">Phone</th>
                                    <th class="px-3 py-2 text-left">Location</th>
                                    <th class="px-3 py-2 text-left">Actions</th>
                                </tr>
                                </thead>
                                <tbody id="membersTableBody" class="divide-y divide-slate-100"></tbody>
                            </table>
                        </div>
                        <div class="px-3 py-2 border-t border-slate-100 text-[10px] text-slate-400 flex items-center justify-between">
                            <span id="membersVisibleCount"></span>
                        </div>
                    </div>
                </div>

                <!-- Registration form -->
                <div id="memberRegistrationWrapper" class="panel p-4 sm:p-5 mobile-card hidden">
                    <form id="memberRegistrationForm"
                          method="post"
                          action="hr_register_member.php"
                          enctype="multipart/form-data"
                          onsubmit="handleMemberFormSubmitWithCheck(event)">
                        <input type="hidden" name="registration_request_id" value="">

                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
                            <div>
                                <h3 id="registrationFormTitle" class="text-sm font-semibold text-slate-800 flex items-center gap-2 transition-colors duration-300">
                                    <span id="registrationFormIconWrapper" class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600 transition-colors duration-300">
                                        <i id="registrationFormIcon" class="fa-solid fa-user-pen text-xs"></i>
                                    </span>
                                    <span id="registrationFormTitleText">Register New Member</span>
                                </h3>
                                <p class="text-[11px] text-slate-500">
                                    Live logic: Waiting has pending ID; Direct/Transfer auto-assign ID + card-ready.
                                </p>
                            </div>
                            <button type="button"
                                    onclick="toggleMemberRegistrationForm(false)"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full border border-slate-200 text-[11px] text-slate-600 hover:bg-slate-50">
                                <i class="fa-solid fa-xmark text-[10px]"></i>
                                <span>Close form</span>
                            </button>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 sm:p-4">
                                <h4 class="text-xs font-semibold text-slate-800 mb-2 flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-slate-200 flex items-center justify-center">
                                        <i class="fa-solid fa-barcode text-[10px]"></i>
                                    </span>
                                    <span>Auto Member ID</span>
                                </h4>

                                <p class="text-[11px] text-slate-500 mb-1">
                                    Next Member ID:
                                    <span id="nextMemberIdDisplay" class="font-semibold text-slate-800"><?= e($nextMemberCode) ?></span>
                                </p>
                                <p class="text-[10px] text-slate-400">
                                    Waiting-list members will not get an ID until they complete 3 months.
                                </p>

                                <input type="hidden" name="student_id" id="studentIdField" value="">
                            </div>

                            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 sm:p-4 permanent-only">
                                <h4 class="text-xs font-semibold text-emerald-900 mb-2 flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-emerald-100 flex items-center justify-center">
                                        <i class="fa-solid fa-clipboard-check text-[10px]"></i>
                                    </span>
                                    <span>Registration Background *</span>
                                </h4>

                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    <button type="button"
                                            class="registration-type-btn w-full text-left px-3 py-2 rounded-xl border border-emerald-300 bg-white text-[11px] sm:text-xs"
                                            data-registration-type="waiting"
                                            onclick="selectRegistrationType('waiting', this)">
                                        <div class="amharic-text text-[13px]">አዲስ ተመዝጋቢ</div>
                                        <div class="text-[10px] text-slate-500">Waiting (list)</div>
                                    </button>

                                    <button type="button"
                                            class="registration-type-btn w-full text-left px-3 py-2 rounded-xl border border-slate-200 bg-white text-[11px] sm:text-xs"
                                            data-registration-type="transfer"
                                            onclick="selectRegistrationType('transfer', this)">
                                        <div class="amharic-text text-[13px]">የተዛወረ</div>
                                        <div class="text-[10px] text-slate-500">Transfer</div>
                                    </button>

                                    <button type="button"
                                            class="registration-type-btn w-full text-left px-3 py-2 rounded-xl border border-slate-200 bg-white text-[11px] sm:text-xs"
                                            data-registration-type="direct"
                                            onclick="selectRegistrationType('direct', this)">
                                        <div class="amharic-text text-[13px]">ቀጥታ መመዝገብ</div>
                                        <div class="text-[10px] text-slate-500">Direct</div>
                                    </button>
                                </div>

                                <input type="hidden" name="registration_type" id="registrationTypeField" value="waiting">
                                <input type="hidden" name="membership_tier" id="membershipTierField" value="permanent">
                                <input type="hidden" name="upgrade_member_id" id="upgradeMemberIdField" value="0">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 sm:p-4">
                                <h4 class="text-xs font-semibold text-emerald-900 mb-2 flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-emerald-100 flex items-center justify-center">
                                        <i class="fa-solid fa-user-tag text-[10px]"></i>
                                    </span>
                                    <span>Member Type *</span>
                                </h4>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                    <button type="button"
                                            class="member-type-btn w-full text-left px-3 py-2 rounded-xl border border-emerald-300 bg-white text-[11px] sm:text-xs"
                                            data-member-type="regular"
                                            onclick="selectMemberTypeFull('regular', this)">
                                        <div class="amharic-text text-[13px]">መደበኛ</div>
                                        <div class="text-[10px] text-slate-500">Regular</div>
                                    </button>

                                    <button type="button"
                                            class="member-type-btn w-full text-left px-3 py-2 rounded-xl border border-slate-200 bg-white text-[11px] sm:text-xs"
                                            data-member-type="special_regular"
                                            onclick="selectMemberTypeFull('special_regular', this)">
                                        <div class="amharic-text text-[13px]">ልዩ መደበኛ</div>
                                        <div class="text-[10px] text-slate-500">Student + role</div>
                                    </button>

                                    <button type="button"
                                            class="member-type-btn w-full text-left px-3 py-2 rounded-xl border border-slate-200 bg-white text-[11px] sm:text-xs"
                                            data-member-type="honorary"
                                            onclick="selectMemberTypeFull('honorary', this)">
                                        <div class="amharic-text text-[13px]">የክብር አባላት</div>
                                        <div class="text-[10px] text-slate-500">Honorary</div>
                                    </button>
                                </div>

                                <input type="hidden" name="member_type" id="memberTypeFieldFull" value="regular">
                            </div>

                            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 sm:p-4">
                                <h4 class="text-xs font-semibold text-slate-800 mb-2 flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-slate-200 flex items-center justify-center">
                                        <i class="fa-solid fa-circle-info text-[10px]"></i>
                                    </span>
                                    <span>Status</span>
                                </h4>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Member Status
                                        </label>
                                        <select name="status"
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                            <option value="active">Active</option>
                                            <option value="warning">Warning</option>
                                            <option value="inactive">Inactive</option>
                                        </select>
                                    </div>

                                    <div class="text-[10px] text-slate-500 flex items-center">
                                        <span>Section equals age group (auto from DOB).</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Registration Date for Existing Members -->
                        <div class="permanent-only bg-blue-50 border border-blue-200 rounded-xl p-3 sm:p-4 mb-4">
                            <h4 class="text-xs font-semibold text-blue-900 mb-2 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-blue-200 flex items-center justify-center">
                                    <i class="fa-solid fa-calendar-plus text-[10px]"></i>
                                </span>
                                <span>Original Registration Date (For Existing Members)</span>
                            </h4>
                            <p class="text-[10px] text-blue-700 mb-3">
                                <i class="fa-solid fa-info-circle mr-1"></i>
                                If this member was registered before the system was created, enter their original registration date. Leave empty for new members (today's date will be used).
                            </p>

                            <div class="flex items-center gap-3 mb-3">
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" id="useCustomRegDate" onchange="toggleCustomRegDate()" 
                                           class="rounded border-blue-300 text-blue-600 focus:ring-blue-200">
                                    <span class="text-xs font-medium text-blue-800">Set custom registration date</span>
                                </label>
                            </div>

                            <div id="customRegDateFields" class="hidden">
                                <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                    Registration Date (Ethiopian Calendar - E.C.)
                                </label>
                                <div class="grid grid-cols-3 gap-2">
                                    <input type="number" name="reg_date_day" id="regDateDay"
                                           min="1" max="30"
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-blue-200 text-xs focus:ring-blue-200 focus:border-blue-400 bg-white"
                                           placeholder="Day">

                                    <select name="reg_date_month" id="regDateMonth"
                                            class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-blue-200 text-xs focus:ring-blue-200 focus:border-blue-400 bg-white">
                                        <option value="">Month</option>
                                        <option value="1">መስከረም (1)</option>
                                        <option value="2">ጥቅምት (2)</option>
                                        <option value="3">ኅዳር (3)</option>
                                        <option value="4">ታኅሣሥ (4)</option>
                                        <option value="5">ጥር (5)</option>
                                        <option value="6">የካቲት (6)</option>
                                        <option value="7">መጋቢት (7)</option>
                                        <option value="8">ሚያዝያ (8)</option>
                                        <option value="9">ግንቦት (9)</option>
                                        <option value="10">ሰኔ (10)</option>
                                        <option value="11">ሐምሌ (11)</option>
                                        <option value="12">ነሐሴ (12)</option>
                                        <option value="13">ጳጉሜ (13)</option>
                                    </select>

                                    <input type="number" name="reg_date_year" id="regDateYear"
                                           min="1990" max="2020"
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-blue-200 text-xs focus:ring-blue-200 focus:border-blue-400 bg-white"
                                           placeholder="Year (E.C.)">
                                </div>
                                <p class="mt-2 text-[10px] text-blue-600">
                                    <i class="fa-solid fa-calculator mr-1"></i>
                                    <span id="regDateDuration">Enter date to see membership duration</span>
                                </p>
                            </div>
                        </div>

                        <?php
                        // Identity v2: responsibilities come from the Super
                        // Admin catalogue (departments + free positions).
                        require_once __DIR__ . '/../backend/services/PositionSyncService.php';
                        $idCat = \App\Services\PositionSyncService::catalogue($conn);
                        ?>
                        <div id="positionPickerSection"
                             class="bg-amber-50 border border-amber-200 rounded-xl p-3 sm:p-4 mb-4 permanent-only" data-conditional="positions">
                            <h4 class="text-xs font-semibold text-amber-900 mb-2 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-amber-200 flex items-center justify-center">
                                    <i class="fa-solid fa-user-gear text-[10px]"></i>
                                </span>
                                <span>Positions &amp; Responsibilities (optional)</span>
                            </h4>
                            <p class="text-[11px] text-amber-800 mb-2">
                                Available for regular and special regular members. Selected positions
                                build the member's identity code (e.g. <span class="font-mono">DT-48291</span>)
                                and keep the legacy role flags in sync automatically.
                            </p>
                            <?php if (empty($idCat['departments']) && empty($idCat['free'])): ?>
                                <p class="text-[11px] text-amber-700">No positions defined yet — the Super Admin creates them under Identity &amp; Codes.</p>
                            <?php else: ?>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2 text-[11px]">
                                <?php if (!empty($idCat['free'])): ?>
                                <div class="col-span-full text-[10px] font-semibold text-amber-900 uppercase tracking-wide mt-1">School-wide positions</div>
                                <?php foreach ($idCat['free'] as $pos): ?>
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="position_ids[]" value="<?= (int)$pos['id'] ?>" class="rounded border-amber-300 text-amber-600">
                                        <span class="eth"><?= htmlspecialchars($pos['title_am']) ?></span>
                                        <span class="text-amber-600 font-mono">[<?= htmlspecialchars($pos['role_code']) ?>]</span>
                                    </label>
                                <?php endforeach; endif; ?>
                                <?php
                                $lastDept = null;
                                foreach ($idCat['departments'] as $pos):
                                    if ($pos['dept_code'] !== $lastDept):
                                        $lastDept = $pos['dept_code']; ?>
                                <div class="col-span-full text-[10px] font-semibold text-amber-900 uppercase tracking-wide mt-1"><?= htmlspecialchars($pos['dept_code']) ?> — <?= htmlspecialchars($pos['dept_am'] ?? '') ?></div>
                                <?php endif; ?>
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="position_ids[]" value="<?= (int)$pos['id'] ?>" class="rounded border-amber-300 text-amber-600">
                                        <span class="eth"><?= htmlspecialchars($pos['title_am']) ?></span>
                                        <span class="text-amber-600 font-mono">[<?= htmlspecialchars($pos['role_code']) ?>]</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <!-- Personal Info -->
                        <div class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 mb-4">
                            <h4 class="text-xs font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-600">
                                    <i class="fa-solid fa-user"></i>
                                </span>
                                <span>Personal Information</span>
                            </h4>

                            <div class="grid grid-cols-1 lg:grid-cols-[auto,minmax(0,1fr)] gap-4">
                                <div class="flex flex-col items-center gap-2">
                                    <div class="w-24 h-32 rounded-2xl bg-slate-50 border-2 border-yellow-400 overflow-hidden flex items-center justify-center">
                                        <img id="studentPhotoPreview" src="" alt="Preview"
                                             class="hidden w-full h-full object-cover">
                                        <span id="studentPhotoPlaceholder" class="text-[11px] text-slate-400 text-center px-2">
                                            3×4 Photo
                                        </span>
                                    </div>
                                    <label class="text-[11px] text-emerald-700 cursor-pointer">
                                        <span class="px-3 py-1.5 rounded-xl border border-emerald-300 bg-emerald-50 hover:bg-emerald-100">
                                            Upload Member Photo
                                        </span>
                                        <input type="file" name="student_photo" accept="image/*" class="hidden"
                                               onchange="previewImage(this, 'studentPhotoPreview', 'studentPhotoPlaceholder')">
                                    </label>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div class="md:col-span-2">
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Full Name (Amharic) *
                                        </label>
                                        <input type="text" name="full_name_am" required
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                               placeholder="ሙሉ ስም (ስም አባት ወይም አያት)"
                                               title="Enter full name separated by spaces: First Father Grandfather">
                                        <p class="text-[10px] text-slate-400 mt-1">Separate First, Father, and Grandfather names with spaces</p>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Christian Name (የክርስትና ስም)
                                        </label>
                                        <input type="text" name="baptismal_name"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Date of Birth (E.C.)
                                        </label>
                                        <div class="grid grid-cols-3 gap-2">
                                            <input type="number" name="dob_day" id="dobDay"
                                                   min="1" max="30"
                                                   class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400"
                                                   placeholder="Day"
                                                   oninput="calculateAgeSection()">

                                            <select name="dob_month" id="dobMonth"
                                                    class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400"
                                                    onchange="calculateAgeSection()">
                                                <option value="">Month</option>
                                            </select>

                                            <input type="number" name="dob_year" id="dobYear"
                                                   min="1950" max="2100"
                                                   class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400"
                                                   placeholder="Year (E.C.)"
                                                   oninput="calculateAgeSection()">
                                        </div>
                                        <p class="mt-1 text-[10px] text-slate-400">
                                            Section auto-calculated (uses current Ethiopian year).
                                        </p>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Age (Auto)
                                        </label>
                                        <input type="text" id="ageDisplay" readonly
                                               class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-slate-50 text-slate-700">
                                        <input type="hidden" name="age" id="ageField">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Section (Auto)
                                        </label>
                                        <input type="text" id="sectionDisplay" readonly
                                               class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-slate-50 text-slate-700">
                                        <input type="hidden" name="current_section" id="currentSectionField">
                                        <input type="hidden" name="age_group" id="ageGroupField">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Gender *
                                        </label>
                                        <select name="gender" required
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                        </select>
                                    </div>

                                    <div class="permanent-only">
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Education Level
                                        </label>
                                        <select name="education_level"
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                            <option value="">Select</option>
                                            <option value="kg">KG</option>
                                            <option value="elementary">Elementary (1–8)</option>
                                            <option value="high">High School (9–12)</option>
                                            <option value="tvet_level1">TVET Level I</option>
                                            <option value="tvet_level2">TVET Level II</option>
                                            <option value="tvet_level3">TVET Level III</option>
                                            <option value="diploma">Diploma</option>
                                            <option value="advanced_diploma">Advanced Diploma</option>
                                            <option value="degree">Degree</option>
                                            <option value="masters">Masters</option>
                                            <option value="phd">PhD</option>
                                            <option value="other">ሌላ</option>
                                        </select>
                                    </div>

                                    <div class="permanent-only">
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            <i class="fa-solid fa-cross text-emerald-500 mr-1"></i>
                                            Spiritual Education (የመንፈሳዊ ትምህርት ደረጃ)
                                        </label>
                                        <select name="spiritual_education"
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-emerald-200 text-xs focus:ring-emerald-200 focus:border-emerald-400 bg-emerald-50">
                                            <option value="">Select Level</option>
                                            <option value="grade_1">1ኛ ክፍል (Grade 1)</option>
                                            <option value="grade_2">2ኛ ክፍል (Grade 2)</option>
                                            <option value="grade_3">3ኛ ክፍል (Grade 3)</option>
                                            <option value="grade_4">4ኛ ክፍል (Grade 4)</option>
                                            <option value="grade_5">5ኛ ክፍል (Grade 5)</option>
                                            <option value="grade_6">6ኛ ክፍል (Grade 6)</option>
                                            <option value="grade_7">7ኛ ክፍል (Grade 7)</option>
                                            <option value="grade_8">8ኛ ክፍል (Grade 8)</option>
                                            <option value="grade_9">9ኛ ክፍል (Grade 9)</option>
                                            <option value="grade_10">10ኛ ክፍል (Grade 10)</option>
                                            <option value="grade_11">11ኛ ክፍል (Grade 11)</option>
                                            <option value="grade_12">12ኛ ክፍል (Grade 12)</option>
                                            <option value="diploma">ዲፕሎማ (Diploma)</option>
                                            <option value="degree">ዲግሪ (Degree)</option>
                                        </select>
                                    </div>

                                    <div class="permanent-only md:col-span-2" data-conditional="class">
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            <i class="fa-solid fa-school text-violet-500 mr-1"></i>
                                            Education Class (optional)
                                        </label>
                                        <select name="class_id" id="hrClassId"
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-violet-200 text-xs focus:ring-violet-200 focus:border-violet-400 bg-violet-50">
                                            <option value="">— Do not assign a class —</option>
                                            <?php foreach ($hrClasses as $hc): ?>
                                            <option value="<?= (int)$hc['id'] ?>">
                                                <?= e($hc['class_name']) ?>
                                                <?php if (!empty($hc['class_name_en'])): ?> (<?= e($hc['class_name_en']) ?>)<?php endif; ?>
                                                <?php if (!empty($hc['class_code'])): ?> — <?= e($hc['class_code']) ?><?php endif; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="text-[10px] text-slate-400 mt-1">
                                            Assigns the member to this Education class for the active academic year. Education can still transfer or unenroll them.
                                            <?php if (empty($hrClasses)): ?>
                                            <span class="text-amber-600">No classes found — Education must create classes first.</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Address -->
                        <div class="permanent-only bg-white border border-slate-200 rounded-xl p-3 sm:p-4 mb-4">
                            <h4 class="text-xs font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-sky-100 flex items-center justify-center text-sky-600">
                                    <i class="fa-solid fa-location-dot"></i>
                                </span>
                                <span>Member Address</span>
                            </h4>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        City / Region *
                                    </label>
                                    <select name="city" id="cityField"
                                            class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400"
                                            onchange="updateSubCities()">
                                        <option value="">Select city</option>
                                        <option value="addis_ababa">Addis Ababa</option>
                                        <option value="oromia">Oromia</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        Sub City / Town
                                    </label>
                                    <select name="sub_city" id="subCityField"
                                            class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                        <option value="">Select sub city</option>
                                    </select>
                                </div>

                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Woreda
                                        </label>
                                        <input type="number" name="woreda" min="1" max="20"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Mender
                                        </label>
                                        <input type="number" name="mender" min="1" max="8"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Block
                                        </label>
                                        <input type="text" name="block_number" maxlength="4"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        House Number
                                    </label>
                                    <input type="text" name="house_number" maxlength="4"
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                </div>

                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        Work / Skill / Profession (searchable)
                                    </label>
                                    <input type="text" name="work_profession"
                                           list="professionsList"
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                           placeholder="IT, Singer, Designer…">
                                    <datalist id="professionsList">
                                        <option value="IT">
                                        <option value="Teacher">
                                        <option value="Nurse">
                                        <option value="Doctor">
                                        <option value="Engineer">
                                        <option value="Driver">
                                        <option value="Farmer">
                                        <option value="Singer">
                                        <option value="Designer">
                                        <option value="Carpenter">
                                        <option value="Tailor">
                                        <option value="Accountant">
                                        <option value="Merchant">
                                    </datalist>
                                </div>
                            </div>
                        </div>

                        <!-- Phones -->
                        <div class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 mb-4">
                            <h4 class="text-xs font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-600">
                                    <i class="fa-solid fa-phone"></i>
                                </span>
                                <span>Phone Numbers</span>
                            </h4>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        Phone Number *
                                    </label>
                                    <input type="tel" name="phone_number" required
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                           placeholder="+251 9…">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                        Other Phone Number
                                    </label>
                                    <input type="tel" name="alt_phone_number"
                                           class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                           placeholder="Optional">
                                </div>
                            </div>
                        </div>

                        <!-- Guardian -->
                        <div class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 mb-4">
                            <h4 class="text-xs font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-amber-100 flex items-center justify-center text-amber-600">
                                    <i class="fa-solid fa-user-shield"></i>
                                </span>
                                <span>Guardian / Parent Information</span>
                            </h4>

                            <div class="grid grid-cols-1 lg:grid-cols-[auto,minmax(0,1fr)] gap-4">
                                <div class="flex flex-col items-center gap-2">
                                    <div class="w-24 h-32 rounded-2xl bg-slate-50 border-2 border-yellow-400 overflow-hidden flex items-center justify-center">
                                        <img id="guardianPhotoPreview" src="" alt="Preview"
                                             class="hidden w-full h-full object-cover">
                                        <span id="guardianPhotoPlaceholder" class="text-[11px] text-slate-400 text-center px-2">
                                            3×4 Guardian Photo
                                        </span>
                                    </div>
                                    <label class="text-[11px] text-amber-700 cursor-pointer">
                                        <span class="px-3 py-1.5 rounded-xl border border-amber-300 bg-amber-50 hover:bg-amber-100">
                                            Upload Guardian Photo
                                        </span>
                                        <input type="file" name="guardian_photo" accept="image/*" class="hidden"
                                               onchange="previewImage(this, 'guardianPhotoPreview', 'guardianPhotoPlaceholder')">
                                    </label>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div class="md:col-span-2" style="position:relative">
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            <i class="fa-solid fa-people-roof text-emerald-600"></i> Already-registered parent? Search and pick to link a sibling (optional)
                                        </label>
                                        <input type="hidden" name="guardian_link_id" id="regGuardianLinkId" value="0">
                                        <input type="text" id="regGuardianPick" autocomplete="off"
                                               placeholder="Search guardians… pick one to skip re-typing"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400">
                                        <div id="regGuardianPickList" class="fam-dropdown" style="display:none"></div>
                                        <div id="regGuardianPicked" style="display:none;margin-top:.4rem;font-size:.72rem;color:#065f46;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:.6rem;padding:.4rem .6rem"></div>
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Guardian Full Name *
                                        </label>
                                        <input type="text" name="guardian_name" required
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Guardian Phone 1 *
                                        </label>
                                        <input type="tel" name="guardian_phone1" required
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                               placeholder="+251 9…">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Guardian Phone 2
                                        </label>
                                        <input type="tel" name="guardian_phone2"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs placeholder:text-[11px] focus:ring-emerald-200 focus:border-emerald-400"
                                               placeholder="Optional">
                                    </div>

                                    <div class="md:col-span-2 flex items-center justify-between gap-2">
                                        <span class="text-[11px] text-slate-500">
                                            Guardian Address (same structure as member).
                                        </span>
                                        <button type="button"
                                                onclick="copyMemberAddressToGuardian()"
                                                class="px-3 py-1.5 rounded-full border border-emerald-200 text-[11px] text-emerald-700 bg-emerald-50 hover:bg-emerald-100">
                                            Same as member
                                        </button>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            City / Region
                                        </label>
                                        <select name="guardian_city" id="guardianCityField"
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400"
                                                onchange="updateGuardianSubCities()">
                                            <option value="">Select city</option>
                                            <option value="addis_ababa">Addis Ababa</option>
                                            <option value="oromia">Oromia</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Sub City / Town
                                        </label>
                                        <select name="guardian_sub_city" id="guardianSubCityField"
                                                class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                            <option value="">Select sub city</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Woreda
                                        </label>
                                        <input type="number" name="guardian_woreda" id="guardianWoredaField"
                                               min="1" max="50"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Mender
                                        </label>
                                        <input type="number" name="guardian_mender" id="guardianMenderField"
                                               min="1" max="50"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            Block
                                        </label>
                                        <input type="text" name="guardian_block_number" id="guardianBlockField"
                                               maxlength="3"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-700 mb-1">
                                            House Number
                                        </label>
                                        <input type="text" name="guardian_house" id="guardianHouseField"
                                               maxlength="4"
                                               class="mobile-touch-target w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Documents -->
                        <div class="permanent-only bg-white border border-slate-200 rounded-xl p-3 sm:p-4 mb-6">
                            <h4 class="text-xs font-semibold text-slate-800 mb-3 flex items-center gap-2">
                                <span class="w-6 h-6 rounded-lg bg-slate-200 flex items-center justify-center text-slate-700">
                                    <i class="fa-solid fa-file-arrow-up text-[10px]"></i>
                                </span>
                                <span>Upload Documents</span>
                            </h4>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-[11px]">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">
                                        Previous School Records
                                    </label>
                                    <input type="file" name="doc_school_records"
                                           accept=".pdf,.doc,.docx,image/*"
                                           class="block w-full text-xs text-slate-600">
                                </div>

                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">
                                        Spiritual Education Document
                                    </label>
                                    <input type="file" name="doc_spiritual"
                                           accept=".pdf,.doc,.docx,image/*"
                                           class="block w-full text-xs text-slate-600">
                                </div>

                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">
                                        Signed Form
                                        <span class="text-red-500">*</span>
                                        <span class="block text-[10px] text-slate-400">
                                            Required for all registration types except Waiting list.
                                        </span>
                                    </label>
                                    <input type="file" name="doc_signed_form" id="docSignedFormField"
                                           accept=".pdf,.doc,.docx,image/*"
                                           class="block w-full text-xs text-slate-600">
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <p class="text-[11px] text-slate-500">
                                *Required. Waiting-list: signed form optional; full ID + card only after 3 months (backend logic later).
                            </p>
                            <div class="flex gap-2 justify-end">
                                <button type="button"
                                        onclick="resetMemberForm()"
                                        class="px-3 py-2 rounded-xl border border-slate-200 text-xs text-slate-600 bg-white hover:bg-slate-50">
                                    Clear
                                </button>
                                <button type="submit"
                                        class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-semibold shadow hover:bg-emerald-700 active:scale-95">
                                    Save Member
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            <!-- NEW MEMBERS (temporary, promotion tracking) -->
            <section id="section-family" class="content-section">
                <div class="panel p-4 mobile-card">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600"><i class="fa-solid fa-people-roof"></i></span>
                                <span>Family Link</span>
                            </h3>
                            <p class="text-xs text-slate-500 mt-0.5">One guardian record per parent; every child linked to it. Edit the guardian once — all siblings follow. Nothing is merged without HR approval.</p>
                        </div>
                        <div class="text-[11px] text-slate-400 sm:text-right">
                            <div><i class="fa-regular fa-lightbulb text-amber-500"></i> Registering a child? Use the <b>guardian picker</b> on the registration form to attach an existing parent.</div>
                        </div>
                    </div>

                    <div id="famStats" class="fam-kpis mb-4"></div>

                    <div class="fam-tabs" role="tablist">
                        <button type="button" id="famTab-families" class="fam-tab fam-tab-on" onclick="FamilyLink.tab('families')"><i class="fa-solid fa-people-roof"></i> Families</button>
                        <button type="button" id="famTab-review" class="fam-tab" onclick="FamilyLink.tab('review')"><i class="fa-solid fa-scale-balanced"></i> Needs review <span id="famTabReviewCount" class="fam-tab-count" style="display:none"></span></button>
                        <button type="button" id="famTab-unlinked" class="fam-tab" onclick="FamilyLink.tab('unlinked')"><i class="fa-solid fa-link-slash"></i> Not linked yet <span id="famTabUnlinkedCount" class="fam-tab-count" style="display:none"></span></button>
                        <button type="button" id="famTab-activity" class="fam-tab" onclick="FamilyLink.tab('activity')"><i class="fa-solid fa-clock-rotate-left"></i> Activity</button>
                    </div>

                    <!-- Families -->
                    <div id="famPane-families">
                        <div class="flex flex-col md:flex-row md:items-center gap-2 mb-3">
                            <div class="relative flex-1">
                                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" id="famSearch" autocomplete="off" placeholder="Search guardian by name, alias or phone (e.g. 0911…, አስቴር)…" class="fam-input" style="padding-left:2rem">
                            </div>
                            <div id="famFilters" class="flex flex-wrap gap-1">
                                <button type="button" class="fam-chip fam-chip-on" data-filter="all">All</button>
                                <button type="button" class="fam-chip" data-filter="multi" title="Guardians with two or more linked children">2+ children</button>
                                <button type="button" class="fam-chip" data-filter="single">1 child</button>
                                <button type="button" class="fam-chip" data-filter="orphan" title="Guardian records with no linked member">No children</button>
                                <button type="button" class="fam-chip" data-filter="nophone">Missing phone</button>
                                <button type="button" class="fam-chip" data-filter="recent">Updated 30 d</button>
                            </div>
                        </div>
                        <div class="flex items-center justify-between mb-2"><div id="famListMeta" class="text-[11px] text-slate-400"></div><div id="famPager" class="flex items-center gap-2"></div></div>
                        <div id="famList"></div>
                    </div>

                    <!-- Needs review -->
                    <div id="famPane-review" style="display:none">
                        <div class="fam-note mb-3">
                            <div class="flex-1"><b>How this works.</b> Pairs below look like the same parent recorded twice (same phone, near-identical names). Compare them side by side, open either record or a child, then <b>merge</b> (children move to the kept record, the other spelling becomes an alias, reversible) or mark them <b>different people</b>.</div>
                            <div class="text-right shrink-0">
                                <button type="button" id="famRematchBtn" class="fam-btn" onclick="FamilyLink.runRematch()"><i class="fa-solid fa-magnifying-glass-arrow-right"></i> Run re-match now</button>
                                <div id="famLastRematch" class="text-[11px] text-slate-400 mt-1"></div>
                                <div id="famRematchBar" class="fam-progress" style="display:none"><div></div></div>
                            </div>
                        </div>
                        <div id="famQueue" class="space-y-3"></div>
                    </div>

                    <!-- Not linked yet -->
                    <div id="famPane-unlinked" style="display:none">
                        <div class="fam-note mb-3">
                            <div class="flex-1"><b>Members registered before Family Link.</b> They have a guardian name/phone typed on their record but no guardian record yet. <b>Link</b> matches each one to an existing guardian (same phone or near-identical name) or creates a new guardian record; near-misses go to <i>Needs review</i>. Safe to run any time.</div>
                            <div class="shrink-0"><button type="button" id="famLinkAllBtn" class="fam-btn fam-btn-primary" style="display:none" onclick="FamilyLink.linkAll()"><i class="fa-solid fa-wand-magic-sparkles"></i> Link all</button></div>
                        </div>
                        <div class="flex items-center justify-between mb-2"><div id="famUnlinkedMeta" class="text-[11px] text-slate-400"></div><div id="famUnlinkedPager" class="flex items-center gap-2"></div></div>
                        <div id="famUnlinked"></div>
                    </div>

                    <!-- Activity -->
                    <div id="famPane-activity" style="display:none">
                        <div id="famActivity"></div>
                    </div>
                </div>
            </section>

            <!-- Guardian drawer (opens over the Family Link page; member profiles open above it) -->
            <div id="famDrawer" class="fam-drawer" aria-hidden="true">
                <div class="fam-drawer-backdrop" onclick="FamilyLink.close()"></div>
                <div class="fam-drawer-panel" role="dialog" aria-label="Guardian">
                    <div class="fam-drawer-hd">
                        <button type="button" class="fam-btn fam-btn-sm" onclick="FamilyLink.close()" title="Back to families"><i class="fa-solid fa-arrow-left"></i> <span class="hidden sm:inline">Families</span></button>
                        <div class="min-w-0 flex-1"><div class="label-soft">Guardian</div><div id="famDrawerTitle" class="text-sm font-bold text-slate-800 truncate">…</div></div>
                        <button type="button" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500" onclick="FamilyLink.close()" title="Close"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <div id="famDrawerBody" class="fam-drawer-body"></div>
                </div>
            </div>

            <section id="section-newmembers" class="content-section">
                <div class="panel p-4 mobile-card">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600">
                                    <i class="fa-solid fa-user-plus"></i>
                                </span>
                                <span>New Members (Temporary)</span>
                            </h3>
                            <p class="text-xs text-slate-500">
                                Track temporary members. Green badge = eligible for promotion (6+ months).
                            </p>
                        </div>
                    </div>

                    <!-- Filter bar -->
                    <div class="bg-white border border-slate-200 rounded-xl p-3 mb-4 shadow-sm">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
                            <div class="relative">
                                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" id="newMembersSearch" autocomplete="off" placeholder="Search name, code, phone..."
                                       class="w-full pl-9 pr-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-emerald-200 focus:border-emerald-400">
                            </div>
                            <select id="newMembersFilterStatus" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="warning">Warning</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <select id="newMembersFilterAgeGroup" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Age Groups</option>
                                <?php foreach (\App\Services\MemberCategory::sections() as $hrCode => $hrSec): ?><option value="<?= $hrCode ?>"><?= e($hrSec['am']) ?> (<?= e($hrSec['ages']) ?>)</option><?php endforeach; ?>
                            </select>
                            <select id="newMembersFilterEligible" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All</option>
                                <option value="1">Eligible only (6+ months)</option>
                            </select>
                        </div>
                        <div class="mt-3 flex flex-col sm:flex-row gap-2 justify-end">
                            <button type="button" onclick="resetNewMembersFilters()" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 bg-white hover:bg-slate-50">Reset</button>
                            <button onclick="applyNewMembersFilters()" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700">Filter</button>
                        </div>
                    </div>

                    <div class="overflow-x-auto border rounded-xl bg-white shadow-sm">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-slate-50 text-[11px] text-slate-500 uppercase tracking-wider">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Member</th>
                                    <th class="px-4 py-3 font-semibold">Code</th>
                                    <th class="px-4 py-3 font-semibold">Registered</th>
                                    <th class="px-4 py-3 font-semibold">Months</th>
                                    <th class="px-4 py-3 font-semibold">Status</th>
                                    <th class="px-4 py-3 font-semibold text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="newMembersTableBody" class="divide-y divide-slate-100 text-xs text-slate-700">
                                <tr><td colspan="6" class="p-4 text-center text-slate-400">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="newMembersPagination" class="mt-3 flex flex-col sm:flex-row items-center justify-between gap-3" aria-live="polite"></div>
                </div>
            </section>

            <!-- MANAGE (edit, archive) -->
            <section id="section-manage" class="content-section">
                <div class="panel p-4 mobile-card">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </span>
                                <span>Manage Members</span>
                            </h3>
                            <p class="text-xs text-slate-500">
                                Search, Edit, and Manage all active members.
                            </p>
                        </div>
                    </div>

                    <!-- Search & Filter Bar (Duplicated from All Members for convenience) -->
                    <div class="bg-white border border-slate-200 rounded-xl p-3 mb-4 shadow-sm">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
                            <div class="relative">
                                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" id="manageSearchInput" autocomplete="off" placeholder="Search anything: names, phone, address, guardian, profession, ID..."
                                       class="w-full pl-9 pr-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-indigo-200 focus:border-indigo-400">
                            </div>
                            <select id="manageFilterType" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Registration Types</option>
                                <option value="waiting">Waiting</option>
                                <option value="direct">Direct</option>
                                <option value="transfer">Transfer</option>
                            </select>
                            <select id="manageFilterStatus" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="warning">Warning</option>
                                <option value="inactive">Inactive</option>
                                <option value="archived">Archived</option>
                            </select>
                            <select id="manageFilterMemberType" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Member Types</option>
                                <option value="regular">Regular</option>
                                <option value="honorary">Honorary</option>
                                <option value="special_regular">Special Regular</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <select id="manageFilterGender" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                            <select id="manageFilterCity" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Cities</option>
                                <option value="addis_ababa">Addis Ababa</option>
                                <option value="oromia">Oromia</option>
                            </select>
                            <select id="manageFilterAgeGroup" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Age Groups</option>
                                <?php foreach (\App\Services\MemberCategory::sections() as $hrCode => $hrSec): ?><option value="<?= $hrCode ?>"><?= e($hrSec['am']) ?> (<?= e($hrSec['ages']) ?>)</option><?php endforeach; ?>
                            </select>
                            <select id="manageFilterEducation" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Education Levels</option>
                                <option value="illiterate">Illiterate</option>
                                <option value="elementary">Elementary</option>
                                <option value="high_school">High School</option>
                                <option value="certificate">Certificate</option>
                                <option value="diploma">Diploma</option>
                                <option value="degree">Degree</option>
                                <option value="masters">Masters</option>
                                <option value="phd">PhD</option>
                            </select>
                        </div>
                        <div class="mt-3 flex flex-col sm:flex-row gap-2 justify-end">
                            <button type="button" onclick="resetManageFilters()" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 bg-white hover:bg-slate-50">Reset</button>
                            <button onclick="applyManageFilters()" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700">Filter</button>
                        </div>
                    </div>

                    <!-- Members List Table -->
                    <div class="overflow-x-auto border rounded-xl bg-white shadow-sm">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-slate-50 text-[11px] text-slate-500 uppercase tracking-wider">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Member</th>
                                    <th class="px-4 py-3 font-semibold">Type</th>
                                    <th class="px-4 py-3 font-semibold">Status</th>
                                    <th class="px-4 py-3 font-semibold text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="manageMembersTableBody" class="divide-y divide-slate-100 text-xs text-slate-700">
                                <!-- Populated by JS -->
                                <tr><td colspan="4" class="p-4 text-center text-slate-400">Loading members...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="manageMembersPagination" class="mt-3 flex flex-col sm:flex-row items-center justify-between gap-3" aria-live="polite"></div>
                </div>
            </section>

<script src="/admin/js/paginated-list.js?v=20261022p"></script>
<script>window.WBWS_SECTIONS=<?= json_encode(array_map(static function($sec){return $sec['am'].' ('.$sec['letter'].')';}, \App\Services\MemberCategory::sections()), JSON_UNESCAPED_UNICODE) ?>;</script>
        <script src="/admin/js/all-members.js?v=20261022p" defer></script>
<script src="/admin/js/manage-members.js" defer></script>
<script src="/admin/js/guardian_picker.js?v=<?= SSMS_ASSET_VER ?>"></script>
<script src="/admin/js/member_nav.js?v=<?= SSMS_ASSET_VER ?>"></script>
<script src="/admin/js/department_status.js?v=<?= SSMS_ASSET_VER ?>-20261022r"></script>
<script src="/admin/js/family_link.js?v=<?= SSMS_ASSET_VER ?>-20261022u"></script>

<script src="/admin/js/new-members.js?v=20261022r" defer></script>
<script src="/frontend/js/member-picker.js?v=20261022p" defer></script>
<script src="/admin/js/id-card-directory.js?v=20261022r" defer></script>
<script src="/admin/js/archive-members.js" defer></script>

            <!-- ARCHIVE -->
            <section id="section-archive" class="content-section">
                <div class="panel p-4 mobile-card">
                    <!-- Header -->
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 mb-1">
                                <i class="fa-solid fa-box-archive text-amber-500 mr-2"></i>Old Members Archive
                            </h3>
                            <p class="text-xs text-slate-500">
                                Archived / left members. You can restore them back to active if they return.
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span id="archivedCount" class="px-3 py-1.5 bg-amber-100 text-amber-700 rounded-full text-xs font-bold">
                                Loading...
                            </span>
                            <button onclick="loadArchivedMembers()" class="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition">
                                <i class="fa-solid fa-sync"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Search -->
                    <div class="mb-4">
                        <div class="relative">
                            <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                            <input type="text" id="archiveSearch" autocomplete="off" placeholder="Search archived members..." 
                                   oninput="filterArchivedMembers()"
                                   class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition">
                        </div>
                    </div>
                    <!-- Archive Tabs -->
                    <div class="flex gap-2 mb-4 border-b border-slate-200 pb-2">
                        <button onclick="switchArchiveTab('permanent_archive')" id="tab_permanent_archive" class="px-4 py-2 text-sm font-semibold text-amber-700 border-b-2 border-amber-500">
                            Permanent Archive
                        </button>
                        <button onclick="switchArchiveTab('failed_observation')" id="tab_failed_observation" class="px-4 py-2 text-sm font-semibold text-slate-500 border-b-2 border-transparent hover:text-amber-700">
                            Failed Observation
                        </button>
                    </div>
                    
                    <!-- Archived Members List -->
                    <div id="archivedMembersList" class="space-y-3">
                        <div class="text-center py-8 text-slate-400">
                            <i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i>
                            <p class="text-sm">Loading archived members...</p>
                        </div>
                    </div>
                    <div id="archivedMembersPagination" class="mt-3 flex items-center justify-between gap-3" aria-live="polite"></div>
                </div>
            </section>

            <!-- ID CARDS -->
          <!-- ID CARD SECTION START -->
<section id="section-idcards" class="content-section hidden">
    <div class="panel p-4 mobile-card">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-sm font-semibold text-slate-800 mb-1">ID Card Management</h3>
                <p class="text-xs text-slate-500">Generate and Print Digital IDs for eligible members.</p>
            </div>
            <button type="button" onclick="loadIdCardMembers()" class="p-2 text-slate-500 hover:text-slate-700">
                <i class="fa-solid fa-sync"></i>
            </button>
        </div>

        <div class="flex flex-col sm:flex-row gap-2 mb-4">
            <input autocomplete="off" type="search" inputmode="search" id="idCardMemberSearch" name="fkss_card_search"
                   data-form-type="other" data-1p-ignore
                   class="flex-1 px-3 py-2 border border-slate-200 rounded-xl text-sm"
                   placeholder="Search eligible members by name, code, or phone">
            <button type="button" onclick="loadIdCardMembers()" class="px-3 py-2 border border-slate-200 rounded-xl text-xs font-semibold">Refresh</button>
        </div>

        <div class="overflow-x-auto border rounded-lg">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                    <tr>
                        <th class="px-4 py-3 font-medium">Member Name</th>
                        <th class="px-4 py-3 font-medium">Code</th>
                        <th class="px-4 py-3 font-medium">Member Type</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium text-right">Action</th>
                    </tr>
                </thead>
                <tbody id="idCardMembersBody" class="divide-y divide-slate-100 text-sm text-slate-700">
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Loading eligible members…</td></tr>
                </tbody>
            </table>
        </div>
        <div id="idCardMembersPagination" class="mt-3 flex items-center justify-between gap-3" aria-live="polite"></div>
    </div>
</section>
<!-- ID CARD SECTION END -->

            <!-- GROUPS -->
            <section id="section-groups" class="content-section">
                <div class="panel p-4 mobile-card">
                    <h3 class="text-sm font-semibold text-slate-800 mb-1">Groups</h3>
                    <p class="text-xs text-slate-500">
                        Group & section management UI placeholder. Add CRUD later.
                    </p>
                </div>
            </section>

            <!-- ATTENDANCE -->
            <!-- ════════════════════════════════════════════════════════
                 ATTENDANCE HISTORY — READ-ONLY. HR attendance was
                 retired (2026-10-07): HR reads combined Education +
                 Mezmur reports (Reports section) instead. Everything
                 recorded before the retirement stays readable here;
                 nothing can be written or reviewed anymore.
            ═════════════════════════════════════════════════════════ -->

                        <!-- REPORTS -->
            <section id="section-reports" class="content-section">
                <!-- ════════════════════════════════════════════════════
                     ATTENDANCE REPORTS — HR reads combined Education +
                     Mezmur attendance (HR's own attendance was retired
                     2026-10-07). Read-only, served by api_hr_reports.php.
                ════════════════════════════════════════════════════ -->
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden mb-6">
                    <div class="p-5 sm:p-6 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                                <i class="fa-solid fa-calendar-check text-emerald-600"></i> Attendance Reports
                            </h2>
                            <p class="text-xs text-slate-500 mt-1">HR analyses the attendance recorded by the Education and Mezmur departments — the same Analytics Hub the Information department uses. HR does not take attendance.</p>
                        </div>
                    </div>

                    <!-- Shared Attendance Analytics Hub (Education + Mezmur) -->
                    <div class="p-5 sm:p-6">
                        <?php include __DIR__ . '/sections/attendance_analytics_hub.php'; ?>
                    </div>

                    <!-- Per-member month view -->
                    <div class="px-5 sm:px-6 pb-5 sm:pb-6 border-t border-slate-100 pt-4">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                            <h3 class="text-sm font-bold text-slate-700"><i class="fa-solid fa-user-check mr-1 text-slate-400"></i> Member view</h3>
                            <input id="hrRepMonth" type="month" class="px-3 py-2 border border-slate-200 rounded-xl text-sm" aria-label="Report month" onchange="HrReports.loadSummary()">
                        </div>
                        <div class="flex flex-col sm:flex-row gap-2 mb-3">
                            <input id="hrRepSearch" type="text" placeholder="Search a member by name or code…" autocomplete="off"
                                   class="flex-1 px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                   onkeyup="if(event.key==='Enter') HrReports.searchMembers()">
                            <button type="button" onclick="HrReports.searchMembers()" class="px-4 py-2 bg-emerald-600 text-white rounded-xl text-sm font-semibold hover:bg-emerald-700 transition">
                                <i class="fa-solid fa-magnifying-glass mr-1"></i> Search
                            </button>
                        </div>
                        <div id="hrRepMemberResults" class="mb-3"></div>
                        <div id="hrRepMemberDetail"></div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600"><i class="fa-solid fa-file-lines"></i></span>
                            <span>Exports & Reports</span>
                        </h3>
                        <p class="text-xs text-slate-500 amharic-text">ሪፖርቶችና ወጪዎች</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="/admin/reports_v2.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-teal-600 to-emerald-600 text-white text-xs font-semibold shadow hover:from-teal-700 hover:to-emerald-700 transition">
                            <i class="fa-solid fa-table-list"></i> New Reporting Centre
                            <span class="px-1.5 py-0.5 rounded-full bg-white/25 text-[9px] font-bold tracking-wide">BETA</span>
                        </a>
                        <a href="/admin/reports.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-xs font-semibold shadow hover:from-blue-700 hover:to-indigo-700 transition">
                            <i class="fa-solid fa-chart-line"></i> Open Advanced Analytics Center
                            <i class="fa-solid fa-arrow-up-right-from-square text-[10px] opacity-70"></i>
                        </a>
                    </div>
                </div>

                <!-- Quick notice -->
                <div class="panel p-3 mb-4" style="background:linear-gradient(135deg,#eff6ff,#eef2ff);border:1px solid #c7d2fe;">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-chart-pie text-blue-600"></i>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-blue-800">Advanced Analytics Available</div>
                            <div class="text-[10px] text-blue-600">Interactive charts, multi-filter data explorer, and export to CSV, PDF & Word — all in one place.</div>
                        </div>
                    </div>
                </div>

                <!-- Quick Export Buttons -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                    <a href="/admin/export_pdf.php?format=csv&filter=all" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-emerald-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-users text-emerald-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">All Members</div>
                                <div class="text-[10px] text-slate-500">Export full member list as CSV</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-emerald-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=csv&filter=active" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-user-check text-blue-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Active Members Only</div>
                                <div class="text-[10px] text-slate-500">Active status members</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-blue-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=pdf&filter=all" target="_blank" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-file-pdf text-red-500 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">PDF Report</div>
                                <div class="text-[10px] text-slate-500">Print-ready member list</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-red-500 font-medium"><i class="fa-solid fa-print mr-1"></i>Open & Print</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=csv&filter=waiting" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-hourglass-half text-amber-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Waiting Members</div>
                                <div class="text-[10px] text-slate-500">Registration type = waiting</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-amber-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=csv&filter=no_id" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-id-card text-red-500 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Members Without ID Card</div>
                                <div class="text-[10px] text-slate-500">ID card not generated yet</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-red-500 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=docx&filter=all" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-indigo-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-file-word text-indigo-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Word Document</div>
                                <div class="text-[10px] text-slate-500">All members as Word doc</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-indigo-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download DOC</span></div>
                    </a>
                </div>

                <!-- Summary Stats Report -->
                <div class="panel p-4">
                    <h4 class="text-sm font-semibold text-slate-700 mb-3"><i class="fa-solid fa-chart-bar mr-1 text-blue-500"></i> Quick Summary Stats</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center mb-4">
                        <div class="bg-emerald-50 p-3 rounded-xl"><div class="text-lg font-bold text-emerald-700"><?= $totalMembers ?></div><div class="text-[10px] text-emerald-600">Total Active</div></div>
                        <div class="bg-blue-50 p-3 rounded-xl"><div class="text-lg font-bold text-blue-700"><?= $genderCounts['male'] ?></div><div class="text-[10px] text-blue-600">Male</div></div>
                        <div class="bg-pink-50 p-3 rounded-xl"><div class="text-lg font-bold text-pink-700"><?= $genderCounts['female'] ?></div><div class="text-[10px] text-pink-600">Female</div></div>
                        <div class="bg-amber-50 p-3 rounded-xl"><div class="text-lg font-bold text-amber-700"><?= $memberTypeWaiting ?></div><div class="text-[10px] text-amber-600">Waiting</div></div>
                    </div>
                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div class="bg-sky-50 p-3 rounded-xl"><div class="text-lg font-bold text-sky-700"><?= $sectionCounts['7_13'] ?></div><div class="text-[10px] text-sky-600 amharic-text">ህጻናት (A)</div></div>
                        <div class="bg-amber-50 p-3 rounded-xl"><div class="text-lg font-bold text-amber-700"><?= $sectionCounts['14_17'] ?></div><div class="text-[10px] text-amber-600 amharic-text">ማዕከላዊያን (B)</div></div>
                        <div class="bg-rose-50 p-3 rounded-xl"><div class="text-lg font-bold text-rose-700"><?= $sectionCounts['18_plus'] ?></div><div class="text-[10px] text-rose-600 amharic-text">ወጣቶች (C)</div></div>
                    </div>
                </div>
            </section>

            <!-- SETTINGS -->
            <section id="section-settings" class="content-section">

                <!-- Settings Header -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                            <span class="w-9 h-9 rounded-2xl bg-slate-100 flex items-center justify-center"><i class="fa-solid fa-gear text-slate-600"></i></span>
                            Settings
                        </h2>
                        <p class="text-xs text-slate-500 amharic-text mt-1">የክፍል ቅንብሮች</p>
                    </div>
                </div>

                <!-- Settings Sub-Tabs -->
                <div class="flex gap-2 mb-5 overflow-x-auto hide-scrollbar pb-1">
                    <button onclick="showSettingsTab(this,'stProfile')" class="stab stab-on"><i class="fa-solid fa-user mr-1"></i>My Profile</button>
                    <button onclick="showSettingsTab(this,'stDept')" class="stab"><i class="fa-solid fa-building mr-1"></i>Department</button>
                    <button onclick="showSettingsTab(this,'stPrefs')" class="stab"><i class="fa-solid fa-sliders mr-1"></i>Preferences</button>
                    <button onclick="showSettingsTab(this,'stSystem')" class="stab"><i class="fa-solid fa-server mr-1"></i>System</button>
                </div>

                <!-- ===== COMMON ACCOUNT PAGE ===== -->
                <div id="stProfile" class="settings-pane">
                    <div class="panel p-6 text-center">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-emerald-400 to-teal-600 flex items-center justify-center mx-auto mb-4 shadow-lg">
                            <i class="fa-solid fa-user-shield text-2xl text-white" aria-hidden="true"></i>
                        </div>
                        <h3 class="font-bold text-slate-800 text-lg mb-2">Profile &amp; account security</h3>
                        <p class="text-sm text-slate-500 max-w-xl mx-auto mb-5">Manage your name, email, username, private profile image, and password in the shared account center.</p>
                        <a href="<?= e(ssms_app_url('admin/account.php')) ?>" class="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-600 text-white rounded-xl text-xs font-semibold hover:bg-emerald-700 transition focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2">
                            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Open My Profile
                        </a>
                    </div>
                </div>

                <!-- ===== DEPARTMENT INFO ===== -->
                <div id="stDept" class="settings-pane" style="display:none">
                    <div class="panel p-5">
                        <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-building text-blue-500"></i> Department & Church Information</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Department Name (English)</label>
                                <input type="text" id="deptNameEn" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="HR Department">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Department Name (Amharic)</label>
                                <input type="text" id="deptNameAm" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm amharic-text focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="ማብራሪያ ክፍል">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Church/School Name (English)</label>
                                <input type="text" id="churchNameEn" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="<?= SCHOOL_TRANSLATION_EN ?> <?= SCHOOL_TYPE ?>">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Church/School Name (Amharic)</label>
                                <input type="text" id="churchNameAm" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm amharic-text focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="<?= SCHOOL_NAME_AMHARIC ?>">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Department Description</label>
                                <textarea id="deptDesc" rows="3" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="Brief description of the department..."></textarea>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button onclick="saveDeptSettings()" class="px-5 py-2.5 bg-blue-600 text-white rounded-xl text-xs font-semibold hover:bg-blue-700 transition flex items-center gap-2">
                                <i class="fa-solid fa-save"></i> Save Department Info
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ===== SYSTEM PREFERENCES ===== -->
                <div id="stPrefs" class="settings-pane" style="display:none">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <!-- Member Code Settings -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-barcode text-violet-500"></i> Member Code Format</h4>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Code Prefix</label>
                                    <input type="text" id="codePrefix" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-violet-200 focus:border-violet-400" placeholder="e.g. WB (leave empty for no prefix)">
                                    <p class="text-[10px] text-slate-400 mt-1">Result: <span id="codePreview" class="font-mono font-bold text-violet-600">0001</span></p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Number of Digits</label>
                                    <select id="codeDigits" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-violet-200 focus:border-violet-400" onchange="updateCodePreview()">
                                        <option value="3">3 digits (001)</option>
                                        <option value="4" selected>4 digits (0001)</option>
                                        <option value="5">5 digits (00001)</option>
                                        <option value="6">6 digits (000001)</option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-3">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="autoGenCode" class="sr-only peer" checked>
                                        <div class="w-9 h-5 bg-slate-200 peer-focus:ring-2 peer-focus:ring-violet-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-violet-500"></div>
                                    </label>
                                    <span class="text-xs text-slate-600">Auto-generate member codes on registration</span>
                                </div>
                            </div>
                        </div>

                        <!-- Default Values -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-list-check text-teal-500"></i> Registration Defaults</h4>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Default Age Group</label>
                                    <select id="defAgeGroup" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-200 focus:border-teal-400">
                                        <option value="">None (manual selection)</option>
                                        <?php foreach (\App\Services\MemberCategory::sections() as $hrCode => $hrSec): ?><option value="<?= $hrCode ?>"><?= e($hrSec['am']) ?> (<?= e($hrSec['ages']) ?>)</option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Default Member Type</label>
                                    <select id="defMemType" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-200 focus:border-teal-400">
                                        <option value="regular">Regular</option>
                                        <option value="honorary">Honorary</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Default Registration Type</label>
                                    <select id="defRegType" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-200 focus:border-teal-400">
                                        <option value="direct">Direct</option>
                                        <option value="transfer">Transfer</option>
                                        <option value="waiting">Waiting</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Validation Rules -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-shield-check text-rose-500"></i> Validation Rules</h4>
                            <div class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="phoneRequired" class="sr-only peer">
                                        <div class="w-9 h-5 bg-slate-200 peer-focus:ring-2 peer-focus:ring-rose-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-rose-500"></div>
                                    </label>
                                    <span class="text-xs text-slate-600">Require phone number on registration</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="idAutoGen" class="sr-only peer">
                                        <div class="w-9 h-5 bg-slate-200 peer-focus:ring-2 peer-focus:ring-rose-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-rose-500"></div>
                                    </label>
                                    <span class="text-xs text-slate-600">Auto-generate ID cards on registration</span>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Guardian Required Under Age</label>
                                    <select id="guardianAge" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-rose-200 focus:border-rose-400">
                                        <option value="0">Not required</option>
                                        <option value="10">Under 10</option>
                                        <option value="14">Under 14</option>
                                        <option value="16">Under 16</option>
                                        <option value="18">Under 18 (all minors)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Save Prefs Button -->
                        <div class="panel p-5 flex items-center justify-between">
                            <div>
                                <h4 class="font-semibold text-slate-700 flex items-center gap-2"><i class="fa-solid fa-floppy-disk text-emerald-500"></i> Save All Preferences</h4>
                                <p class="text-[10px] text-slate-400 mt-1">Click to save member code format, defaults, and validation rules</p>
                            </div>
                            <button onclick="savePreferences()" class="px-5 py-2.5 bg-emerald-600 text-white rounded-xl text-xs font-semibold hover:bg-emerald-700 transition flex items-center gap-2">
                                <i class="fa-solid fa-save"></i> Save Preferences
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ===== SYSTEM / DATA MANAGEMENT ===== -->
                <div id="stSystem" class="settings-pane" style="display:none">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <!-- System Stats -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-chart-simple text-indigo-500"></i> System Statistics</h4>
                            <div id="sysStats" class="space-y-3">
                                <div class="text-center py-4"><div class="inline-block w-5 h-5 border-2 border-slate-200 border-t-emerald-500 rounded-full animate-spin"></div><p class="text-xs text-slate-400 mt-2">Loading...</p></div>
                            </div>
                        </div>

                        <!-- Data Management -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-database text-cyan-500"></i> Data Management</h4>
                            <div class="space-y-3">
                                <a href="/admin/export_pdf.php?format=csv&filter=all" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-emerald-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-emerald-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-file-csv text-emerald-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Export All Members (CSV)</div><div class="text-[10px] text-slate-400">Download complete member database</div></div>
                                </a>
                                <a href="/admin/export_pdf.php?format=pdf&filter=all" target="_blank" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-red-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-red-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-file-pdf text-red-500"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Full PDF Report</div><div class="text-[10px] text-slate-400">Print-ready member list</div></div>
                                </a>
                                <div onclick="clearCache()" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-amber-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-amber-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-broom text-amber-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Clear Cache</div><div class="text-[10px] text-slate-400">Remove temporary files and rate limit data</div></div>
                                </div>
                                <a href="/admin/system_health.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-blue-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-blue-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-stethoscope text-blue-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">System Health Check</div><div class="text-[10px] text-slate-400">Database diagnostics & auto-fix</div></div>
                                </a>
                                <a href="/admin/reports.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-violet-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-violet-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-chart-line text-violet-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Advanced Analytics</div><div class="text-[10px] text-slate-400">Charts, reports, filtered exports</div></div>
                                </a>
                            </div>
                        </div>

                        <!-- Recent Activity -->
                        <div class="panel p-5 lg:col-span-2">
                            <h4 class="font-semibold text-slate-700 mb-3 flex items-center gap-2"><i class="fa-solid fa-timeline text-orange-500"></i> Recent Activity Log</h4>
                            <div id="sysActivity" class="text-xs text-slate-400">Loading...</div>
                        </div>
                    </div>
                </div>

            </section>

            <!-- ═══ PROFILE TAB ═══ -->
            <?php
            require_once __DIR__ . '/../components/profile_tab_section.php';
            renderProfileTabSection('section-profile', 'content-section');
            ?>
        </main>

        <!-- Bottom Nav (mobile) -->
        <nav class="md:hidden fixed bottom-0 left-0 right-0 bg-emerald-700 text-white bottom-nav-shadow z-50">
            <div class="flex overflow-x-auto hide-scrollbar px-2 py-2 gap-1">
                <button data-section="dashboard"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target nav-bottom-active">
                    <i class="fa-solid fa-chart-pie text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Dashboard</span>
                </button>
                <button data-section="members"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-users text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Members</span>
                </button>
                <button data-section="manage"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-pen-to-square text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Manage</span>
                </button>
                <button data-section="archive"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-box-archive text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Archive</span>
                </button>
                <button data-section="idcards"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-id-card text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">ID Cards</span>
                </button>
                <button data-section="groups"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-layer-group text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Groups</span>
                </button>
                <button data-section="reports"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-file-lines text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Reports</span>
                </button>
                <button data-section="settings"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-gear text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Settings</span>
                </button>
                <button data-section="profile"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80 text-white border-none bg-transparent cursor-pointer">
                    <i class="fa-solid fa-user-gear text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Profile</span>
                </button>
            </div>
        </nav>
    </div>
</div>

<!-- Loading overlay -->
<div id="formLoadingOverlay"
     class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-2xl shadow-lg px-6 py-4 flex items-center gap-3">
        <div class="animate-spin rounded-full h-6 w-6 border-2 border-emerald-500 border-t-transparent"></div>
        <div class="text-sm font-medium text-emerald-700">
            Saving member, please wait…
        </div>
    </div>
</div>

<!-- Success toast -->
<div id="memberSuccessToast" class="fixed inset-x-0 top-0 z-[60] hidden">
    <div class="mx-auto mt-4 w-full max-w-3xl px-4">
        <div class="toast-enter bg-emerald-500 text-white px-5 py-4 rounded-2xl shadow-2xl
                text-[15px] font-semibold flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="text-xl">✅</span>
                <span id="memberSuccessToastText">Member registered successfully.</span>
            </div>
            <button type="button"
                    onclick="document.getElementById('memberSuccessToast').classList.add('hidden')"
                    class="text-xs uppercase tracking-wide bg-emerald-600/70 hover:bg-emerald-700 px-3 py-1 rounded-xl">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Manage sheet -->
<div id="manageSheet" class="sheet">
    <div class="sheet-body flex flex-col h-full bg-slate-50">
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 bg-white border-b border-slate-200 sticky top-0 z-10">
            <div>
                <div class="label-soft">Manage Member</div>
                <div class="text-lg font-bold text-slate-800" id="manageSheetTitle">Member Details</div>
            </div>
            <button class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 transition" onclick="closeManageSheet()">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        
        <!-- Content -->
        <div id="manageSheetContent" class="flex-1 overflow-y-auto p-6">
            Loading...
        </div>
    </div>
</div>

<script>
    const emerald = '#16a34a';
    const emeraldLight = '#22c55e';
    const gold = '#facc15';
    const sky = '#0ea5e9';
    const rose = '#f97373';

    // Simple section navigation (sidebar + bottom nav)
    function navigateToSection(name) {
        closeManageSheet(); // Close any open modal
        // Retired/unknown sections (old bookmarks) fall back to the dashboard.
        if (!document.getElementById('section-' + name)) name = 'dashboard';
        document.querySelectorAll('.content-section').forEach(sec => sec.classList.remove('active'));
        const target = document.getElementById('section-' + name);
        if (target) target.classList.add('active');
        if (name === 'manage' && typeof window.loadManageMembers === 'function') {
            window.loadManageMembers();
        }
        if (name === 'newmembers') {
            if (typeof window.loadNewMembers === 'function') window.loadNewMembers();
        }
        if (name === 'family' && window.FamilyLink) {
            FamilyLink.init();
        }
        if (name === 'reports') {
            try { HrReports.init(); } catch (e) { console.error(e); }
            try { if (window.AttendanceHub) AttendanceHub.init(); } catch (e) { console.error(e); }
        }
        if (name === 'profile' && typeof window.loadTabProfile === 'function') {
            window.loadTabProfile();
        }

        // Update URL so refresh stays on this section
        const url = new URL(window.location);
        url.searchParams.set('section', name);
        history.replaceState(null, '', url);

        // Highlight desktop pills
        document.querySelectorAll('aside [data-section]').forEach(btn => {
            btn.classList.remove('nav-pill-active', 'bg-white/20');
        });
        document.querySelectorAll('aside [data-section="' + name + '"]').forEach(btn => {
            btn.classList.add('nav-pill-active', 'bg-white/20');
        });

        // Highlight bottom nav
        document.querySelectorAll('nav [data-section]').forEach(btn => {
            btn.classList.remove('opacity-100');
            btn.classList.add('opacity-80');
        });
        document.querySelectorAll('nav [data-section="' + name + '"]').forEach(btn => {
            btn.classList.remove('opacity-80');
            btn.classList.add('opacity-100');
        });
    }

    window.navigateToSection = navigateToSection;
    window.switchTab = navigateToSection;
    window.nav = navigateToSection;
    window.switchSection = navigateToSection;
    window.showSection = navigateToSection;

    document.querySelectorAll('[data-section]').forEach(el => {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            const name = this.getAttribute('data-section');
            if (name) navigateToSection(name);
        });
    });

    // Initialize default section
    const urlParams = new URLSearchParams(window.location.search);
    const sectionParam = urlParams.get('section');
    if (sectionParam) {
        navigateToSection(sectionParam);
    } else {
        navigateToSection('dashboard');
    }
    const memberParam = parseInt(urlParams.get('member') || '0', 10);
    if (memberParam > 0 && typeof openManageSheet === 'function') {
        openManageSheet(memberParam);
        const cleanUrl = new URL(window.location); cleanUrl.searchParams.delete('member'); history.replaceState(null, '', cleanUrl);
    }

    // Charts
    document.addEventListener('DOMContentLoaded', () => {
        const sectionCtx = document.getElementById('sectionChart').getContext('2d');
        new Chart(sectionCtx, {
            type: 'bar',
            data: {
                labels: ['ህጻናት (A)', 'ማዕከላዊያን (B)', 'ወጣቶች (C)'],
                datasets: [{
                    data: [
                        <?= (int)$sectionCounts['7_13'] ?>,
                        <?= (int)$sectionCounts['14_17'] ?>,
                        <?= (int)$sectionCounts['18_plus'] ?>
                    ],
                    backgroundColor: [
                        'rgba(34,197,94,0.9)',
                        'rgba(250,204,21,0.9)',
                        'rgba(248,113,113,0.9)'
                    ],
                    borderRadius: 12,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {legend: {display: false}},
                scales: {
                    x: {
                        grid: {display: false},
                        ticks: {color: '#64748b', font: {size: 10}}
                    },
                    y: {
                        grid: {color: '#e2e8f0'},
                        ticks: {color: '#64748b', font: {size: 10}, precision: 0}
                    }
                }
            }
        });

        const genderCtx = document.getElementById('genderChart').getContext('2d');
        new Chart(genderCtx, {
            type: 'bar',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: [<?= (int)$genderCounts['male'] ?>, <?= (int)$genderCounts['female'] ?>],
                    backgroundColor: ['rgba(59,130,246,0.9)', 'rgba(244,63,94,0.9)'],
                    borderRadius: 12,
                    borderWidth: 0
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {legend: {display: false}},
                scales: {
                    x: {
                        grid: {color: '#e2e8f0'},
                        ticks: {color: '#64748b', font: {size: 10}, precision: 0}
                    },
                    y: {
                        grid: {display: false},
                        ticks: {color: '#64748b', font: {size: 10}}
                    }
                }
            }
        });
    });

    // --- Member registration UI helpers ---

    function toggleMemberRegistrationForm(show, tier = 'permanent', title = null) {
        const wrapper = document.getElementById('memberRegistrationWrapper');
        const list = document.getElementById('membersListPlaceholder');
        const formTitle = document.getElementById('registrationFormTitle');
        const formTitleText = document.getElementById('registrationFormTitleText');
        const formIconWrapper = document.getElementById('registrationFormIconWrapper');
        const formIcon = document.getElementById('registrationFormIcon');
        const tierInput = document.getElementById('membershipTierField');
        const upgradeIdInput = document.getElementById('upgradeMemberIdField');
        
        const btnTemp = document.getElementById('btnRegisterTemporary');
        const btnPerm = document.getElementById('btnRegisterPermanent');

        if (!wrapper || !list) return;

        if (show === true) {
            if (tierInput) tierInput.value = tier;
            
            // Remove previous theme classes
            wrapper.classList.remove('theme-temporary', 'theme-permanent', 'border-2', 'shadow-lg');
            formTitle.classList.remove('text-emerald-800', 'text-amber-800', 'text-slate-800');
            formIconWrapper.classList.remove('bg-emerald-100', 'text-emerald-600', 'bg-amber-100', 'text-amber-600');
            
            // Reset buttons
            if (btnTemp && btnPerm) {
                btnTemp.className = "mobile-touch-target inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-slate-500 text-xs sm:text-sm font-semibold transition-all hover:text-slate-700";
                btnPerm.className = "mobile-touch-target inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-slate-500 text-xs sm:text-sm font-semibold transition-all hover:text-slate-700";
            }
            
            if (tier === 'temporary') {
                if (formTitleText) formTitleText.textContent = 'Register Temporary Member';
                
                // Apply Temporary (Amber) Theme
                wrapper.classList.add('theme-temporary', 'border-2', 'shadow-lg');
                formTitle.classList.add('text-amber-800');
                formIconWrapper.classList.add('bg-amber-100', 'text-amber-600');
                if (formIcon) formIcon.className = 'fa-solid fa-hourglass-half text-xs';
                if (btnTemp) btnTemp.className = "mobile-touch-target inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-amber-700 bg-white shadow-sm border border-amber-200 text-xs sm:text-sm font-bold transition-all transform scale-105 ring-2 ring-amber-100 ring-offset-1";
                
                document.querySelectorAll('.permanent-only').forEach(el => el.classList.add('hidden'));
                // Temporary members are always 'waiting' registration type.
                const rtField = document.getElementById('registrationTypeField');
                if (rtField) rtField.value = 'waiting';
                // Clear upgrade ID
                if (upgradeIdInput) upgradeIdInput.value = '0';
            } else if (tier === 'permanent') {
                if (formTitleText) formTitleText.textContent = title || 'Register Permanent Member';
                
                // Apply Permanent (Emerald) Theme
                wrapper.classList.add('theme-permanent', 'border-2', 'shadow-lg');
                formTitle.classList.add('text-emerald-800');
                formIconWrapper.classList.add('bg-emerald-100', 'text-emerald-600');
                if (formIcon) formIcon.className = 'fa-solid fa-user-check text-xs';
                if (btnPerm) btnPerm.className = "mobile-touch-target inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-emerald-700 bg-white shadow-sm border border-emerald-200 text-xs sm:text-sm font-bold transition-all transform scale-105 ring-2 ring-emerald-100 ring-offset-1";
                
                document.querySelectorAll('.permanent-only').forEach(el => el.classList.remove('hidden'));
                if (!title && upgradeIdInput) upgradeIdInput.value = '0'; // reset if not an upgrade
            }

            wrapper.classList.remove('hidden');
            list.classList.add('hidden');
            updateRegistrationConditionalSections();
            navigateToSection('members');
        } else if (show === false) {
            wrapper.classList.add('hidden');
            list.classList.remove('hidden');
            if (upgradeIdInput) upgradeIdInput.value = '0';
            
            // Reset buttons to initial inactive look
            if (btnTemp && btnPerm) {
                btnTemp.className = "mobile-touch-target inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-slate-600 text-xs sm:text-sm font-semibold transition-all hover:bg-white hover:shadow-sm";
                btnPerm.className = "mobile-touch-target inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-slate-600 text-xs sm:text-sm font-semibold transition-all hover:bg-white hover:shadow-sm";
            }
        } else {
            if (wrapper.classList.contains('hidden')) {
                toggleMemberRegistrationForm(true, tier);
            } else {
                toggleMemberRegistrationForm(false);
            }
        }
    }

    function setFormField(f, name, value) {
        if (!f || !f[name]) return;
        var el = f[name];
        if (el.type === 'checkbox' || el.type === 'radio') {
            el.checked = !!value;
        } else {
            el.value = (value == null ? '' : value);
        }
    }

    function prefillForm(m) {
        if (!m) return false;
        var f = document.getElementById('memberRegistrationForm');
        if (!f) return false;
        // Combined full name (required field) — SSOT: members.student_name + father_name + grandfather_name
        var fullName = [m.student_name, m.father_name, m.grandfather_name].filter(function (x) { return x != null && String(x).trim() !== ''; }).join(' ').trim();
        if (fullName) setFormField(f, 'full_name_am', fullName);
        // Direct 1:1 mappings (member column -> form field)
        var direct = [
            ['baptismal_name','baptismal_name'],
            ['gender','gender'],
            ['age_group','age_group'],
            ['current_section','current_section'],
            ['member_type','member_type'],
            ['registration_type','registration_type'],
            ['status','status'],
            ['phone_number','phone_number'],
            ['alt_phone_number','alt_phone_number'],
            ['guardian_name','guardian_name'],
            ['guardian_phone1','guardian_phone1'],
            ['guardian_phone2','guardian_phone2'],
            ['city','city'],
            ['sub_city','sub_city'],
            ['woreda','woreda'],
            ['mender','mender'],
            ['block_number','block_number'],
            ['house_number','house_number'],
            ['education_level','education_level'],
            ['spiritual_education','spiritual_education'],
            ['member_code','student_id']
        ];
        direct.forEach(function (pair) {
            if (m[pair[0]] != null && String(m[pair[0]]).trim() !== '') {
                setFormField(f, pair[1], m[pair[0]]);
            }
        });
        // membership_tier forced to permanent on promotion
        setFormField(f, 'membership_tier', 'permanent');
        // Sync member-type button UI + conditional sections
        var mt = (m && m.member_type) ? String(m.member_type) : '';
        if (mt) {
            var btn = document.querySelector('.member-type-btn[data-member-type="' + mt + '"]');
            if (btn) selectMemberTypeFull(mt, btn);
            else updateRegistrationConditionalSections();
        } else {
            updateRegistrationConditionalSections();
        }
        return true;
    }

    function openUpgradeModal(memberId, memberData, eligible, overrideReason) {
        eligible = (eligible !== false);
        // Open the form immediately (never block promotion on data loading)
        toggleMemberRegistrationForm(true, 'permanent', 'Upgrade to Permanent Member');
        var upgradeIdInput = document.getElementById('upgradeMemberIdField');
        if (upgradeIdInput) upgradeIdInput.value = memberId;

        // Override reason (for non-eligible promotions) — captured for audit
        var existingReason = document.getElementById('promotion_override_reason');
        if (existingReason) existingReason.remove();
        if (!eligible) {
            // Reason comes from the early-promotion confirmation dialog (new-members.js).
            var reason = String(overrideReason || '');
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.id = 'promotion_override_reason';
            hidden.name = 'promotion_override_reason';
            hidden.value = reason.trim().slice(0, 500);
            var f = document.getElementById('memberRegistrationForm');
            if (f) f.appendChild(hidden);
        }

        // 1) Prefer the already-loaded member object (SSOT cache) — no network round-trip
        var m = (memberData && typeof memberData === 'object') ? memberData : null;
        if (m) {
            if (prefillForm(m)) {
                if (typeof showToast === 'function') showToast('Existing member data prefilled. Please verify and upload the signed form.', 'info');
            }
            return;
        }

        // 2) Fallback: fetch from authoritative endpoint
        fetch('/admin/info_manage_member.php?id=' + encodeURIComponent(memberId) + '&format=json&v=' + new Date().getTime())
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.status === 'success' && data.member) {
                    if (prefillForm(data.member)) {
                        if (typeof showToast === 'function') showToast('Existing member data prefilled. Please verify and upload the signed form.', 'info');
                    }
                } else {
                    console.warn('Member prefill unavailable:', data);
                    if (typeof showToast === 'function') showToast('Member data could not be prefilled. Please fill the form manually.', 'warning');
                }
            })
            .catch(function (err) {
                console.error('openUpgradeModal fetch failed:', err);
                if (typeof showToast === 'function') showToast('Member data could not be loaded. Please fill the form manually.', 'warning');
            });
    }

    function selectRegistrationType(type, btn) {
        const hidden = document.getElementById('registrationTypeField');
        if (hidden) hidden.value = type;

        const idDisplay = document.getElementById('nextMemberIdDisplay');
        const idField = document.getElementById('studentIdField');
        const signedInput = document.getElementById('docSignedFormField');

        // Waiting list: no ID + signed form not required
        if (type === 'waiting') {
            if (idDisplay) idDisplay.textContent = 'Pending';
            if (idField) idField.value = '';
        } else {
            if (idDisplay) idDisplay.textContent = idDisplay.textContent || '<?= e($nextMemberCode) ?>';
            if (idField) idField.value = idField.value || '<?= e($nextMemberCode) ?>';
        }

        // Signed form required ONLY for transfer or direct
        if (signedInput) signedInput.required = (type === 'transfer' || type === 'direct');

        document.querySelectorAll('.registration-type-btn').forEach(b => {
            b.classList.remove('ring-2', 'ring-emerald-500', 'bg-emerald-50', 'border-emerald-300');
            b.classList.add('border-slate-200', 'bg-white');
        });

        if (btn) {
            btn.classList.remove('border-slate-200', 'bg-white');
            btn.classList.add('ring-2', 'ring-emerald-500', 'bg-emerald-50', 'border-emerald-300');
        }
    }

    function selectMemberTypeFull(type, btn) {
        const hidden = document.getElementById('memberTypeFieldFull');
        if (hidden) hidden.value = type;

        document.querySelectorAll('.member-type-btn').forEach(b => {
            b.classList.remove('ring-2', 'ring-emerald-500', 'bg-emerald-50', 'border-emerald-300');
            b.classList.add('border-slate-200', 'bg-white');
        });

        if (btn) {
            btn.classList.remove('border-slate-200', 'bg-white');
            btn.classList.add('ring-2', 'ring-emerald-500', 'bg-emerald-50', 'border-emerald-300');
        }
        updateRegistrationConditionalSections();
    }

    function updateRegistrationConditionalSections() {
        const memberType = (document.getElementById('memberTypeFieldFull') || {}).value || 'regular';
        const tier = (document.getElementById('membershipTierField') || {}).value || 'permanent';
        const isHonorary = (memberType === 'honorary');
        const isTemporary = (tier === 'temporary');
        // Positions section: only for regular/special_regular AND permanent tier
        const posSec = document.getElementById('positionPickerSection');
        if (posSec) {
            if (isHonorary || isTemporary) posSec.classList.add('hidden');
            else posSec.classList.remove('hidden');
        }
        // Class assignment: hidden for honorary (already permanent-only gated for tier)
        document.querySelectorAll('[data-conditional="class"]').forEach(el => {
            if (isHonorary) el.classList.add('hidden');
            else if (!isTemporary) el.classList.remove('hidden');
        });
    }

    function previewImage(input, imgId, placeholderId) {
        const file = input.files && input.files[0];
        const img = document.getElementById(imgId);
        const placeholder = document.getElementById(placeholderId);

        if (!img || !placeholder) return;

        if (file) {
            const reader = new FileReader();
            reader.onload = e => {
                img.src = e.target.result;
                img.classList.remove('hidden');
                placeholder.classList.add('hidden');
            };
            reader.readAsDataURL(file);
        } else {
            img.src = '';
            img.classList.add('hidden');
            placeholder.classList.remove('hidden');
        }
    }

    function resetMemberForm() {
        const form = document.getElementById('memberRegistrationForm');
        if (!form) return;
        form.reset();

        // Default registration type: waiting
        const defaultRegBtn = document.querySelector('.registration-type-btn[data-registration-type="waiting"]');
        if (defaultRegBtn) selectRegistrationType('waiting', defaultRegBtn);

        // Default member type: regular
        const defaultTypeBtn = document.querySelector('.member-type-btn[data-member-type="regular"]');
        if (defaultTypeBtn) selectMemberTypeFull('regular', defaultTypeBtn);

        // Clear previews
        previewImage({files: []}, 'studentPhotoPreview', 'studentPhotoPlaceholder');
        previewImage({files: []}, 'guardianPhotoPreview', 'guardianPhotoPlaceholder');

        // Reset age/section
        ['ageDisplay','ageField','sectionDisplay','currentSectionField','ageGroupField'].forEach(id=>{
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
    }

    // Ethiopian months for DOB dropdown
    function initEthiopianMonths() {
        const monthSelect = document.getElementById('dobMonth');
        if (!monthSelect) return;

        const months = [
            {value: '1', label: 'መስከረም'},
            {value: '2', label: 'ጥቅምት'},
            {value: '3', label: 'ህዳር'},
            {value: '4', label: 'ታኅሣስ'},
            {value: '5', label: 'ጥር'},
            {value: '6', label: 'የካቲት'},
            {value: '7', label: 'መጋቢት'},
            {value: '8', label: 'ሚያዝያ'},
            {value: '9', label: 'ግንቦት'},
            {value: '10', label: 'ሰኔ'},
            {value: '11', label: 'ሐምሌ'},
            {value: '12', label: 'ነሐሴ'},
            {value: '13', label: 'ጳጉሜ'}
        ];

        monthSelect.innerHTML = '<option value="">Month</option>';
        months.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.value;
            opt.textContent = m.label;
            monthSelect.appendChild(opt);
        });
    }

    // Calculate age & section (same value used as school section)
    function calculateAgeSection() {
        const year = parseInt(document.getElementById('dobYear')?.value || '0', 10);
        const currentYearEC = <?php echo (int) ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), 'Y'); ?>;

        if (!year || year > currentYearEC) {
            ['ageDisplay','ageField','sectionDisplay','currentSectionField','ageGroupField'].forEach(id=>{
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            return;
        }

        let age = currentYearEC - year;
        if (age < 0) age = 0;

        // Age is informational only. Category (ህጻናት/ማዕከላዊያን/ወጣቶች) and
        // section are assigned manually by staff — no automatic assignment.
        if (document.getElementById('ageDisplay')) document.getElementById('ageDisplay').value = age.toString();
        if (document.getElementById('ageField')) document.getElementById('ageField').value = age.toString();
    }

    // Toggle custom registration date fields
    function toggleCustomRegDate() {
        const checkbox = document.getElementById('useCustomRegDate');
        const fieldsDiv = document.getElementById('customRegDateFields');
        
        if (checkbox && fieldsDiv) {
            if (checkbox.checked) {
                fieldsDiv.classList.remove('hidden');
            } else {
                fieldsDiv.classList.add('hidden');
                // Clear fields when hidden
                document.getElementById('regDateDay').value = '';
                document.getElementById('regDateMonth').value = '';
                document.getElementById('regDateYear').value = '';
                document.getElementById('regDateDuration').textContent = 'Enter date to see membership duration';
            }
        }
    }

    // Calculate membership duration from registration date
    function calculateRegDuration() {
        const day = parseInt(document.getElementById('regDateDay')?.value || '0', 10);
        const month = parseInt(document.getElementById('regDateMonth')?.value || '0', 10);
        const year = parseInt(document.getElementById('regDateYear')?.value || '0', 10);
        const durationEl = document.getElementById('regDateDuration');
        
        if (!durationEl) return;
        
        const currentYearEC = <?php echo (int) ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), 'Y'); ?>;
        const currentMonthEC = <?php echo (int) ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), 'n'); ?>;
        
        if (!year || year <= 0 || year > currentYearEC) {
            durationEl.textContent = 'Enter date to see membership duration';
            return;
        }
        
        // Calculate duration
        let years = currentYearEC - year;
        let months = currentMonthEC - (month || 1);
        
        if (months < 0) {
            years--;
            months += 13; // Ethiopian calendar has 13 months
        }
        
        let durationText = '';
        if (years > 0 && months > 0) {
            durationText = `${years} ዓመት ${months} ወር (${years} year${years > 1 ? 's' : ''} ${months} month${months > 1 ? 's' : ''})`;
        } else if (years > 0) {
            durationText = `${years} ዓመት (${years} year${years > 1 ? 's' : ''})`;
        } else if (months > 0) {
            durationText = `${months} ወር (${months} month${months > 1 ? 's' : ''})`;
        } else {
            durationText = 'Less than a month';
        }
        
        durationEl.innerHTML = '<i class="fa-solid fa-clock mr-1"></i> Membership duration: <strong>' + durationText + '</strong>';
    }

    // Add event listeners for registration date fields
    document.addEventListener('DOMContentLoaded', function() {
        ['regDateDay', 'regDateMonth', 'regDateYear'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', calculateRegDuration);
                el.addEventListener('change', calculateRegDuration);
            }
        });
    });

    // City -> Sub city mapping
    const CITY_SUBCITY_MAP = {
        addis_ababa: [
            'Arada', 'Bole', 'Nifas Silk-Lafto', 'Kirkos', 'Lideta',
            'Yeka', 'Kolfe Keranio', 'Gullele', 'Akaki Kality', 'Addis Ketema'
        ],
        oromia: [
            'koye feche', 'Adama', 'Bishoftu', 'Jimma', 'Shashamane', 'Ambo',
            'Asella', 'Nekemte', 'Holeta'
        ]
    };

    function updateSubCities() {
        const city = document.getElementById('cityField')?.value || '';
        const subSelect = document.getElementById('subCityField');
        if (!subSelect) return;

        subSelect.innerHTML = '<option value="">Select sub city</option>';

        if (CITY_SUBCITY_MAP[city]) {
            CITY_SUBCITY_MAP[city].forEach(name => {
                const opt = document.createElement('option');
                opt.value = name.toLowerCase().replace(/\s+/g, '_');
                opt.textContent = name;
                subSelect.appendChild(opt);
            });
        }
    }

    function updateGuardianSubCities() {
        const city = document.getElementById('guardianCityField')?.value || '';
        const subSelect = document.getElementById('guardianSubCityField');
        if (!subSelect) return;

        subSelect.innerHTML = '<option value="">Select sub city</option>';

        if (CITY_SUBCITY_MAP[city]) {
            CITY_SUBCITY_MAP[city].forEach(name => {
                const opt = document.createElement('option');
                opt.value = name.toLowerCase().replace(/\s+/g, '_');
                opt.textContent = name;
                subSelect.appendChild(opt);
            });
        }
    }

    function copyMemberAddressToGuardian() {
        const city = document.getElementById('cityField')?.value || '';
        const subCity = document.getElementById('subCityField')?.value || '';
        const woreda = document.querySelector('[name="woreda"]')?.value || '';
        const mender = document.querySelector('[name="mender"]')?.value || '';
        const block = document.querySelector('[name="block_number"]')?.value || '';
        const house = document.querySelector('[name="house_number"]')?.value || '';

        const gCity = document.getElementById('guardianCityField');
        const gSubCity = document.getElementById('guardianSubCityField');
        const gWoreda = document.getElementById('guardianWoredaField');
        const gMender = document.getElementById('guardianMenderField');
        const gBlock = document.getElementById('guardianBlockField');
        const gHouse = document.getElementById('guardianHouseField');

        if (gCity) {
            gCity.value = city;
            updateGuardianSubCities();
        }
        setTimeout(() => {
            if (gSubCity && subCity) gSubCity.value = subCity;
        }, 50);

        if (gWoreda) gWoreda.value = woreda;
        if (gMender) gMender.value = mender;
        if (gBlock) gBlock.value = block;
        if (gHouse) gHouse.value = house;
    }

    // Init defaults on load
    document.addEventListener('DOMContentLoaded', () => {
        // Default registration type: waiting
        const defaultRegBtn = document.querySelector('.registration-type-btn[data-registration-type="waiting"]');
        if (defaultRegBtn) selectRegistrationType('waiting', defaultRegBtn);

        // Default member type: regular
        const defaultTypeBtn = document.querySelector('.member-type-btn[data-member-type="regular"]');
        if (defaultTypeBtn) selectMemberTypeFull('regular', defaultTypeBtn);

        // Ethiopian months
        initEthiopianMonths();
    });

    function showMemberSuccessToast(message) {
        const toast = document.getElementById('memberSuccessToast');
        if (!toast) return;

        const span = document.getElementById('memberSuccessToastText');
        if (span && message) span.textContent = message;

        toast.classList.remove('hidden');
        setTimeout(() => toast.classList.add('hidden'), 4000);
    }

    function handleMemberFormSubmit(event) {
        event.preventDefault();

        const form = document.getElementById('memberRegistrationForm');
        if (!form) return;

        const overlay = document.getElementById('formLoadingOverlay');
        if (overlay) overlay.classList.remove('hidden');

        ensureRegistrationRequestId(form);
        const formData = new FormData(form);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('<?php echo $ajaxPrefix; ?>hr_register_member.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        })
            .then(response => {
                const ct = response.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    throw { _type: 'session_expired', message: 'Session expired. The page will reload.' };
                }
                return response.json();
            })
            .then(data => {
                if (overlay) overlay.classList.add('hidden');

                if (data && (data.status === 'session_expired' || data.status === 'csrf_expired' || data.action === 'reload')) {
                    alert(data.message || 'Session expired. The page will reload.');
                    window.location.reload();
                    return;
                }

                if (data && data.status === 'success') {
                    resetMemberForm();
                    showMemberSuccessToast(data.message || 'Member registered successfully.');
                    const wrapper = document.getElementById('memberRegistrationWrapper');
                    if (wrapper) wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    alert(data && data.message ? data.message : 'Saving failed. Please try again.');
                }
            })
            .catch(err => {
                if (overlay) overlay.classList.add('hidden');
                if (err && err._type === 'session_expired') {
                    alert(err.message); window.location.reload(); return;
                }
                if (err instanceof SyntaxError) {
                    alert('Session may have expired. The page will reload.'); window.location.reload(); return;
                }
                console.error(err);
                alert('Connection error. Please check your internet and try again.\n\nIf this keeps happening, refresh the page first.');
            });
    }

    // Advanced search / filters — handled by all-members.js (PaginatedList)

    // Manage sheet (edit)
    function openManageSheet(id) {
        const sheet = document.getElementById('manageSheet');
        const content = document.getElementById('manageSheetContent');
        const title = document.getElementById('manageSheetTitle');
        if (!sheet || !content) return;

        sheet.classList.add('open');
        content.innerHTML = 'Loading...';

        const cacheBust = Date.now();
        fetch('/admin/info_manage_member.php?id=' + encodeURIComponent(id) + '&embed=1&v=' + cacheBust)
            .then(res => res.text())
            .then(html => {
                content.innerHTML = html;
                if (window.MemberNav) MemberNav.afterLoad(id);
                if (window.DeptStatus) DeptStatus.mount(content);
                if (title) title.textContent = 'Member #' + id;
                // Default to Preview tab
                switchManageTab('preview');
            })
            .catch(() => {
                content.innerHTML = '<div class="text-red-600 text-sm">Failed to load member.</div>';
            });
    }

    function closeManageSheet() {
        const sheet = document.getElementById('manageSheet');
        if (sheet) sheet.classList.remove('open');
        if (window.MemberNav) MemberNav.reset();
    }

    // Archive action - Show verification modal
    let archiveMemberId = null;
    let archiveMemberName = '';
    
    function archiveMember(id, name) {
        archiveMemberId = id;
        archiveMemberName = name || 'this member';
        
        // Update modal content
        document.getElementById('archiveMemberName').textContent = archiveMemberName;
        document.getElementById('archiveReason').value = '';
        document.getElementById('archiveNotes').value = '';
        document.getElementById('archiveConfirmText').value = '';
        document.getElementById('confirmArchiveBtn').disabled = true;
        
        // Show modal
        document.getElementById('archiveModal').classList.remove('hidden');
        document.getElementById('archiveModal').classList.add('flex');
    }
    
    function closeArchiveModal() {
        document.getElementById('archiveModal').classList.add('hidden');
        document.getElementById('archiveModal').classList.remove('flex');
        archiveMemberId = null;
    }
    
    function checkArchiveConfirmation() {
        const confirmText = document.getElementById('archiveConfirmText').value.trim();
        const reason = document.getElementById('archiveReason').value;
        const btn = document.getElementById('confirmArchiveBtn');
        
        // Must type "ARCHIVE" and select a reason
        if (confirmText === 'ARCHIVE' && reason !== '') {
            btn.disabled = false;
            btn.classList.remove('bg-slate-300', 'cursor-not-allowed');
            btn.classList.add('bg-red-600', 'hover:bg-red-700');
        } else {
            btn.disabled = true;
            btn.classList.add('bg-slate-300', 'cursor-not-allowed');
            btn.classList.remove('bg-red-600', 'hover:bg-red-700');
        }
    }
    
    // Danger confirmation dialog (admin/js/danger_confirm.js) — owner decisions 2026-10-21.
    function dcAsk(o) {
        if (window.DangerConfirm && window.DangerConfirm.ask) return window.DangerConfirm.ask(o);
        var m = 'The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.';
        if (typeof showToast === 'function') showToast(m, 'error'); else console.error(m);
        return Promise.resolve(false);
    }

    async function confirmArchive() {
        if (!archiveMemberId) return;
        
        const reason = document.getElementById('archiveReason').value;
        const notes = document.getElementById('archiveNotes').value;
        const btn = document.getElementById('confirmArchiveBtn');
        const rsel = document.getElementById('archiveReason');
        const rtxt = rsel && rsel.selectedIndex >= 0 ? rsel.options[rsel.selectedIndex].text : reason;
        const dc = await dcAsk({tier:'medium', title:'Archive ' + archiveMemberName + '?', object: archiveMemberName,
            summary:'The member is moved out of the active lists. Reason: ' + rtxt + '.', intent:'I want to archive this member',
            consequences:['The member disappears from active member lists, class rosters, attendance sheets and new reports.','Teachers and the mobile app no longer show this member.','Family links stay but the member is no longer counted as active.'],
            keeps:['All history (attendance, marks, documents) is kept.','You can restore the member from the Archive list.'],
            phrase:'ARCHIVE MEMBER', confirmLabel:'Archive member'});
        if (!dc) return;
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Archiving...';
        
        fetch('/admin/info_archive_member.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                id: archiveMemberId,
                reason: reason,
                notes: notes,
                csrf_token: CSRF_TOKEN
            })
        }).then(r=>r.json()).then(data=>{
            if (data.status === 'success') {
                closeArchiveModal();
                showToast('✓ ' + data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message || 'Archive failed.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-box-archive mr-2"></i> Archive Member';
            }
        }).catch(()=>{
            showToast('Network error. Please try again.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-box-archive mr-2"></i> Archive Member';
        });
    }
    
    // Restore member from archive
    async function restoreMember(id, name) {
        if (!(await dcAsk({tier:'low', title:'Restore ' + name + ' to active members?', summary:'The member appears again in active lists, rosters and attendance.', confirmLabel:'Restore member'}))) return;
        
        fetch('/admin/info_restore_member.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id, csrf_token: CSRF_TOKEN})
        }).then(r=>r.json()).then(data=>{
            if (data.status === 'success') {
                showToast('✓ ' + data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message || 'Restore failed.', 'error');
            }
        }).catch(()=>{
            showToast('Network error.', 'error');
        });
    }
    
    // Toast notification
    function showToast(message, type) {
        const toast = document.createElement('div');
        toast.className = 'fixed top-4 right-4 z-[200] px-6 py-4 rounded-xl shadow-2xl text-white font-semibold text-sm transform transition-all duration-300 translate-x-full';
        toast.style.background = type === 'success' ? 'linear-gradient(135deg, #059669, #10b981)' : 'linear-gradient(135deg, #dc2626, #ef4444)';
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => toast.classList.remove('translate-x-full'), 100);
        setTimeout(() => {
            toast.classList.add('translate-x-full');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // ---------------------------------------------------------
    // Shared text-escaping helper used by attendance and management views.
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = String(text ?? '');
        return div.innerHTML;
    }
    function escapeAttr(text) {
        return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // MANAGE SHEET FUNCTIONS (Tabs, Preview, Edit)
    // ---------------------------------------------------------
    function switchManageTab(tab) {
        const previewBtn = document.getElementById('tab-preview');
        const editBtn = document.getElementById('tab-edit');
        const viewPreview = document.getElementById('view-preview');
        const editForm = document.getElementById('view-edit');

        if (!previewBtn || !editBtn || !viewPreview || !editForm) return;

        if (tab === 'preview') {
            previewBtn.classList.add('border-emerald-600', 'text-emerald-700');
            previewBtn.classList.remove('border-transparent', 'text-slate-500');
            editBtn.classList.remove('border-emerald-600', 'text-emerald-700');
            editBtn.classList.add('border-transparent', 'text-slate-500');
            
            viewPreview.classList.remove('hidden');
            viewPreview.style.display = 'block';
            editForm.classList.add('hidden');
            editForm.style.display = 'none';
        } else {
            editBtn.classList.add('border-emerald-600', 'text-emerald-700');
            editBtn.classList.remove('border-transparent', 'text-slate-500');
            previewBtn.classList.remove('border-emerald-600', 'text-emerald-700');
            previewBtn.classList.add('border-transparent', 'text-slate-500');
            
            editForm.classList.remove('hidden');
            editForm.style.display = 'block';
            viewPreview.classList.add('hidden');
            viewPreview.style.display = 'none';
        }
    }

    // In-page Image Viewer Logic
    let viewerScale = 1;
    let viewerRotation = 0;
    let viewerPx = 0, viewerPy = 0;
    let viewerIsDragging = false;
    let viewerStartX, viewerStartY;

    function openDocFullscreen(src) {
        const overlay = document.getElementById('imageViewerOverlay');
        const img = document.getElementById('viewer-img');
        if(!overlay || !img) return;

        // Reset state
        viewerScale = 1;
        viewerRotation = 0;
        viewerPx = 0;
        viewerPy = 0;
        
        // Resolve absolute path
        const fullUrl = new URL(src, window.location.href).href;
        img.src = fullUrl;
        updateViewerTransform();

        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
    }

    function closeDocFullscreen() {
        const overlay = document.getElementById('imageViewerOverlay');
        if(overlay) {
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
        }
    }

    function updateViewerTransform() {
        const img = document.getElementById('viewer-img');
        if(img) {
            img.style.transform = `translate(${viewerPx}px, ${viewerPy}px) scale(${viewerScale}) rotate(${viewerRotation}deg)`;
        }
    }

    function adjustViewerZoom(delta) {
        viewerScale += delta;
        if(viewerScale < 0.1) viewerScale = 0.1;
        if(viewerScale > 5) viewerScale = 5;
        updateViewerTransform();
    }

    function resetViewerView() {
        viewerScale = 1;
        viewerRotation = 0;
        viewerPx = 0;
        viewerPy = 0;
        updateViewerTransform();
    }

    function rotateViewerImg() {
        viewerRotation += 90;
        updateViewerTransform();
    }

    // Setup viewer event listeners once
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('img-container');
        if(!container) return;

        // Wheel Zoom
        container.addEventListener('wheel', (e) => {
            e.preventDefault();
            adjustViewerZoom(e.deltaY > 0 ? -0.1 : 0.1);
        }, { passive: false });

        // Drag Panning
        container.addEventListener('mousedown', (e) => {
            viewerIsDragging = true;
            viewerStartX = e.clientX - viewerPx;
            viewerStartY = e.clientY - viewerPy;
            container.style.cursor = 'grabbing';
        });

        window.addEventListener('mousemove', (e) => {
            if(!viewerIsDragging) return;
            e.preventDefault();
            viewerPx = e.clientX - viewerStartX;
            viewerPy = e.clientY - viewerStartY;
            updateViewerTransform();
        });

        window.addEventListener('mouseup', () => {
            viewerIsDragging = false;
            if(container) container.style.cursor = 'grab';
        });
    });

    function submitEditForm(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        formData.append('csrf_token', CSRF_TOKEN);
        const btn = form.querySelector('button[type="submit"]');
        
        if(btn) {
            btn.disabled = true;
            btn.textContent = 'Saving...';
        }

        fetch('/admin/info_manage_member.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'success') {
                alert('Saved successfully!');
                closeManageSheet();
                window.location.reload(); // Refresh to see changes
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            alert('Network error');
            console.error(err);
        })
        .finally(() => {
            if(btn) {
                btn.disabled = false;
                btn.textContent = 'Save Changes';
            }
        });
    }
</script>

<!-- Archive Verification Modal -->
<div id="archiveModal" class="fixed inset-0 z-[150] bg-slate-900/80 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden animate-in">
        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-box-archive text-white text-xl"></i>
                </div>
                <div class="text-white">
                    <h3 class="font-bold text-lg">Archive Member</h3>
                    <p class="text-white/80 text-sm">Move to old members</p>
                </div>
            </div>
        </div>
        
        <!-- Body -->
        <div class="p-6">
            <!-- Warning -->
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-5">
                <div class="flex gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500 text-lg mt-0.5"></i>
                    <div>
                        <p class="text-amber-800 font-semibold text-sm mb-1">You are about to archive:</p>
                        <p id="archiveMemberName" class="text-amber-900 font-bold text-base">Member Name</p>
                    </div>
                </div>
            </div>
            
            <!-- Reason -->
            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    <i class="fa-solid fa-clipboard-list mr-1 text-slate-400"></i> Reason for archiving *
                </label>
                <select id="archiveReason" onchange="checkArchiveConfirmation()" 
                        class="w-full px-4 py-3 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    <option value="">-- Select a reason --</option>
                    <option value="left_school">ከትምህርት ቤት ወጥቷል/ች (Left School)</option>
                    <option value="graduated">ተመርቋል/ች (Graduated)</option>
                    <option value="transferred">ወደ ሌላ ቦታ ተዛውሯል/ች (Transferred)</option>
                    <option value="inactive_long">ረጅም ጊዜ አልተገኘም/ች (Long Inactive)</option>
                    <option value="failed_observation">የሙከራ ጊዜውን አላጠናቀቀም/ች (Failed Observation)</option>
                    <option value="deceased">አርፏል/ች (Deceased)</option>
                    <option value="other">ሌላ (Other)</option>
                </select>
            </div>
            
            <!-- Notes -->
            <div class="mb-5">
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    <i class="fa-solid fa-note-sticky mr-1 text-slate-400"></i> Additional Notes (Optional)
                </label>
                <textarea id="archiveNotes" rows="2" placeholder="Any additional information..."
                          class="w-full px-4 py-3 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 resize-none"></textarea>
            </div>
            
            <!-- Confirmation -->
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-5">
                <label class="block text-sm font-semibold text-red-700 mb-2">
                    <i class="fa-solid fa-keyboard mr-1"></i> Type "ARCHIVE" to confirm
                </label>
                <input type="text" id="archiveConfirmText" oninput="checkArchiveConfirmation()" 
                       placeholder="Type ARCHIVE here..."
                       class="w-full px-4 py-3 border-2 border-red-200 rounded-xl text-sm font-mono text-center uppercase tracking-widest focus:ring-2 focus:ring-red-500 focus:border-red-500">
            </div>
        </div>
        
        <!-- Footer -->
        <div class="bg-slate-50 px-6 py-4 flex gap-3">
            <button onclick="closeArchiveModal()" 
                    class="flex-1 px-4 py-3 bg-white border border-slate-200 text-slate-600 font-semibold rounded-xl hover:bg-slate-100 transition">
                <i class="fa-solid fa-times mr-2"></i> Cancel
            </button>
            <button id="confirmArchiveBtn" onclick="confirmArchive()" disabled
                    class="flex-1 px-4 py-3 bg-slate-300 text-white font-semibold rounded-xl cursor-not-allowed transition">
                <i class="fa-solid fa-box-archive mr-2"></i> Archive Member
            </button>
        </div>
    </div>
</div>

<!-- Duplicate Detection Modal -->
<div id="duplicateModal" class="fixed inset-0 z-[160] bg-slate-900/80 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full max-h-[90vh] overflow-hidden animate-in">
        <!-- Header -->
        <div class="bg-gradient-to-r from-red-500 to-rose-500 px-6 py-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-user-group text-white text-xl"></i>
                </div>
                <div class="text-white">
                    <h3 class="font-bold text-lg">Duplicate Member Found!</h3>
                    <p class="text-white/80 text-sm">This member may already exist</p>
                </div>
            </div>
        </div>
        
        <!-- Body -->
        <div class="p-6 overflow-y-auto max-h-[60vh]">
            <!-- Warning -->
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
                <div class="flex gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-red-500 text-lg mt-0.5"></i>
                    <div>
                        <p class="text-red-800 font-semibold text-sm">A similar member already exists in the system!</p>
                        <p class="text-red-600 text-xs mt-1">Please review the existing member(s) below before registering.</p>
                    </div>
                </div>
            </div>
            
            <!-- Duplicate Member Cards -->
            <div id="duplicateMembersList" class="space-y-3">
                <!-- Dynamically populated -->
            </div>
        </div>
        
        <!-- Footer -->
        <div class="bg-slate-50 px-6 py-4 border-t">
            <p class="text-xs text-slate-500 mb-3 text-center">
                <i class="fa-solid fa-info-circle mr-1"></i>
                If this is a different person, document why before continuing.
            </p>
            <label for="duplicateOverrideReason" class="block text-xs font-semibold text-slate-600 mb-1">
                Duplicate override reason
            </label>
            <textarea id="duplicateOverrideReason" maxlength="500" rows="2"
                      class="w-full border border-slate-200 rounded-lg p-2 text-sm mb-3"
                      placeholder="Example: verified different birth date and guardian"></textarea>
            <div class="flex gap-3">
                <button onclick="closeDuplicateModal()" 
                        class="flex-1 px-4 py-3 bg-white border border-slate-200 text-slate-600 font-semibold rounded-xl hover:bg-slate-100 transition">
                    <i class="fa-solid fa-times mr-2"></i> Cancel
                </button>
                <button onclick="proceedWithRegistration()" 
                        class="flex-1 px-4 py-3 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-xl transition">
                    <i class="fa-solid fa-user-plus mr-2"></i> Register Anyway
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ---------------------------------------------------------
// DUPLICATE DETECTION SYSTEM
// ---------------------------------------------------------
let duplicateCheckPending = false;
let duplicateCheckTimer = null;
let skipDuplicateCheck = false;
let pendingFormData = null;

function ensureRegistrationRequestId(form) {
    return window.SsmsRequestId?.ensure(form, 'registration_request_id') || '';
}

function registrationIdentity(form) {
    const parts = (form?.querySelector('input[name="full_name_am"]')?.value || '')
        .trim().split(/\s+/u).filter(Boolean);
    return {
        studentName: parts[0] || '',
        fatherName: parts[1] || '',
        grandfatherName: parts.slice(2).join(' '),
        phone: form?.querySelector('input[name="phone_number"]')?.value?.trim() || ''
    };
}

function requestDuplicateCheck({ studentName, fatherName, grandfatherName, phone }) {
    const body = new URLSearchParams({
        student_name: studentName,
        father_name: fatherName,
        grandfather_name: grandfatherName,
        phone: phone,
        csrf_token: CSRF_TOKEN
    });
    return fetch('/admin/api_check_duplicate.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: body.toString()
    }).then(async response => {
        const data = await response.json();
        if (!response.ok || data.status !== 'success') {
            throw new Error(data.message || 'Duplicate check failed');
        }
        return data;
    });
}

// Check for duplicates when identity fields change
function setupDuplicateCheck() {
    const form = document.getElementById('memberRegistrationForm');
    const fullNameField = form?.querySelector('input[name="full_name_am"]');
    const phoneField = form?.querySelector('input[name="phone_number"]');
    const fields = [fullNameField, phoneField];
    
    fields.forEach(field => {
        if (field) {
            field.addEventListener('blur', () => {
                clearTimeout(duplicateCheckTimer);
                duplicateCheckTimer = setTimeout(checkForDuplicates, 500);
            });
        }
    });
}

function checkForDuplicates() {
    if (skipDuplicateCheck) return;
    
    const form = document.getElementById('memberRegistrationForm');
    const { studentName, fatherName, grandfatherName, phone } = registrationIdentity(form);

    // Need at least student and father name
    if (studentName.length < 2 || fatherName.length < 2) return;
    
    duplicateCheckPending = true;
    
    requestDuplicateCheck({ studentName, fatherName, grandfatherName, phone })
        .then(data => {
            duplicateCheckPending = false;
            if (data.found && data.matches && data.matches.length > 0) {
                showDuplicateWarning(data.matches);
            }
        })
        .catch(() => {
            duplicateCheckPending = false;
        });
}

function showDuplicateWarning(matches) {
    const container = document.getElementById('duplicateMembersList');
    if (!container) return;
    
    container.innerHTML = matches.map(m => {
        const fullName = (m.student_name || '') + ' ' + (m.father_name || '') + ' ' + (m.grandfather_name || '');
        const isArchived = m.is_archived || m.status === 'archived';
        const statusClass = isArchived ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700';
        const statusText = isArchived ? 'ARCHIVED' : 'ACTIVE';
        const photoHtml = m.student_photo_path 
            ? `<img src="${escapeAttr(m.student_photo_path)}" class="w-full h-full object-cover">`
            : `<i class="fa-solid fa-user text-slate-400 text-xl"></i>`;
        
        return `
            <div class="bg-white border-2 ${isArchived ? 'border-amber-300' : 'border-emerald-300'} rounded-xl p-4">
                <div class="flex gap-4">
                    <div class="w-16 h-20 rounded-lg bg-slate-100 overflow-hidden flex items-center justify-center flex-shrink-0">
                        ${photoHtml}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2 mb-2">
                            <h4 class="font-bold text-slate-800 text-sm">${escapeHtml(fullName)}</h4>
                            <span class="px-2 py-1 ${statusClass} rounded-lg text-[10px] font-bold flex-shrink-0">
                                ${statusText}
                            </span>
                        </div>
                        <div class="space-y-1 text-xs text-slate-600">
                            <p><i class="fa-solid fa-id-card w-4 text-slate-400"></i> ${escapeHtml(m.member_code || 'No ID')}</p>
                            <p><i class="fa-solid fa-users w-4 text-slate-400"></i> ${escapeHtml(m.current_section || m.age_group || '—')}</p>
                            <p><i class="fa-solid fa-phone w-4 text-slate-400"></i> ${escapeHtml(m.phone_number || '—')}</p>
                            ${m.match_reasons ? `<p class="text-red-500 text-[10px] mt-1"><i class="fa-solid fa-exclamation-circle"></i> ${escapeHtml(m.match_reasons.join(', '))}</p>` : ''}
                        </div>
                        <div class="mt-3 flex gap-2">
                            ${isArchived 
                                ? `<button onclick="restoreAndClose(${m.id}, '${fullName.replace(/'/g, "\\'")}')" 
                                        class="flex-1 px-3 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-xs font-bold transition">
                                    <i class="fa-solid fa-rotate-left mr-1"></i> Restore This Member
                                   </button>`
                                : `<button onclick="viewExistingMember(${m.id})" 
                                        class="flex-1 px-3 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-xs font-bold transition">
                                    <i class="fa-solid fa-eye mr-1"></i> View Member
                                   </button>`
                            }
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    // Show modal
    document.getElementById('duplicateModal').classList.remove('hidden');
    document.getElementById('duplicateModal').classList.add('flex');
}

function closeDuplicateModal() {
    document.getElementById('duplicateModal').classList.add('hidden');
    document.getElementById('duplicateModal').classList.remove('flex');
    const reason = document.getElementById('duplicateOverrideReason');
    if (reason) reason.value = '';
    pendingFormData = null;
}

function proceedWithRegistration() {
    const reasonField = document.getElementById('duplicateOverrideReason');
    const overrideReason = reasonField?.value?.trim() || '';
    if (!overrideReason) {
        alert('Enter why this is a different person before registering.');
        reasonField?.focus();
        return;
    }
    skipDuplicateCheck = true;
    
    // Save form data BEFORE closing modal (closeDuplicateModal sets pendingFormData = null)
    const formDataToSubmit = pendingFormData;
    if (formDataToSubmit) {
        formDataToSubmit.set('duplicate_override', '1');
        formDataToSubmit.set('duplicate_override_reason', overrideReason);
    }
    
    closeDuplicateModal();
    
    // Submit with the saved copy
    if (formDataToSubmit) {
        submitRegistrationForm(formDataToSubmit);
    }
    
    pendingFormData = null;
    setTimeout(() => { skipDuplicateCheck = false; }, 2000);
}

function restoreAndClose(id, name) {
    closeDuplicateModal();
    // Clear the registration form
    resetMemberForm();
    toggleMemberRegistrationForm(false);
    
    // Restore the member
    restoreMember(id, name);
}

function viewExistingMember(id) {
    closeDuplicateModal();
    // Clear and close registration form
    resetMemberForm();
    toggleMemberRegistrationForm(false);
    
    // Open manage sheet
    openManageSheet(id);
}

// Modified form submit to check duplicates first
function handleMemberFormSubmitWithCheck(event) {
    event.preventDefault();
    
    if (skipDuplicateCheck) {
        // Submit normally
        handleMemberFormSubmit(event);
        return;
    }
    
    const form = document.getElementById('memberRegistrationForm');
    if (!form) return;
    
    const { studentName, fatherName, grandfatherName, phone } = registrationIdentity(form);

    // Store form data for later submission. Keep this ID across network retries.
    ensureRegistrationRequestId(form);
    pendingFormData = new FormData(form);
    
    const overlay = document.getElementById('formLoadingOverlay');
    if (overlay) overlay.classList.remove('hidden');
    
    requestDuplicateCheck({ studentName, fatherName, grandfatherName, phone })
        .then(data => {
            if (overlay) overlay.classList.add('hidden');
            
            if (data.found && data.matches && data.matches.length > 0) {
                // Show duplicate warning modal
                showDuplicateWarning(data.matches);
            } else {
                // No duplicates, proceed with registration
                submitRegistrationForm(pendingFormData);
                pendingFormData = null;
            }
        })
        .catch(() => {
            if (overlay) overlay.classList.add('hidden');
            // On error, proceed anyway
            submitRegistrationForm(pendingFormData);
            pendingFormData = null;
        });
}

function submitRegistrationForm(formData) {
    const overlay = document.getElementById('formLoadingOverlay');
    if (overlay) overlay.classList.remove('hidden');
    
    if (!formData.has('registration_request_id')) {
        const form = document.getElementById('memberRegistrationForm');
        formData.append('registration_request_id', ensureRegistrationRequestId(form));
    }
    // CRITICAL: Add CSRF token — the form doesn't have a hidden field for it
    if (!formData.has('csrf_token')) {
        formData.append('csrf_token', CSRF_TOKEN);
    }
    
    fetch('/admin/hr_register_member.php', {
        method: 'POST',
        body: formData,
        headers: { 'Accept': 'application/json' }
    })
    .then(response => {
        // ── Key fix: check for non-JSON responses before parsing ──
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            // Server returned HTML (likely a redirect to login page)
            if (response.redirected || response.url.includes('index.php')) {
                throw { _type: 'session_expired', message: 'Session expired. Redirecting to login...' };
            }
            throw { _type: 'server_error', message: 'Server returned an unexpected response. Please try again.' };
        }
        return response.json();
    })
    .then(data => {
        if (overlay) overlay.classList.add('hidden');
        
        if (!data || typeof data !== 'object') {
            alert('Unexpected server response. Please try again.');
            return;
        }
        
        // ── Handle session expiration ──
        if (data.status === 'session_expired' || data.action === 'reload') {
            alert(data.message || 'Your session has expired. The page will reload.');
            window.location.reload();
            return;
        }
        
        // ── Handle CSRF token expiration ──
        if (data.status === 'csrf_expired') {
            alert(data.message || 'Security token expired. The page will reload.');
            window.location.reload();
            return;
        }
        
        // ── Handle success ──
        if (data.status === 'success') {
            resetMemberForm();
            showMemberSuccessToast(data.message || 'Member registered successfully.');
            if (typeof window.loadNewMembers === 'function') window.loadNewMembers();
            const wrapper = document.getElementById('memberRegistrationWrapper');
            if (wrapper) wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        
        // ── Handle known errors with real messages ──
        alert(data.message || 'Registration failed. Please try again.');
    })
    .catch(err => {
        if (overlay) overlay.classList.add('hidden');
        
        // ── Structured errors from our checks above ──
        if (err && err._type === 'session_expired') {
            alert(err.message);
            window.location.reload();
            return;
        }
        if (err && err._type === 'server_error') {
            alert(err.message);
            return;
        }
        
        // ── Actual network errors ──
        console.error('Registration error:', err);
        
        // Check if it's a JSON parse error (server returned HTML)
        if (err instanceof SyntaxError && err.message.includes('Unexpected token')) {
            alert('Your session may have expired. The page will reload.');
            window.location.reload();
            return;
        }
        
        // Check if offline
        if (!navigator.onLine) {
            alert('You appear to be offline. Please check your internet connection and try again.');
            return;
        }
        
        // Generic network error
        alert('Connection error. Please check your internet and try again.\n\nIf this keeps happening, try refreshing the page first.');
    });
}

// Initialize duplicate check on page load
document.addEventListener('DOMContentLoaded', setupDuplicateCheck);

// ============================================================
// SETTINGS SECTION FUNCTIONS
// ============================================================
function showSettingsTab(btn, id) {
    document.querySelectorAll('.settings-pane').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.stab').forEach(b => b.classList.remove('stab-on'));
    document.getElementById(id).style.display = 'block';
    btn.classList.add('stab-on');
    if (id === 'stDept' && !document.getElementById('deptNameEn').value) loadDeptSettings();
    if (id === 'stPrefs' && !document.getElementById('codeDigits')._loaded) loadPreferences();
    if (id === 'stSystem') loadSystemInfo();
}

function settingsToast(msg, ok) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:12px;color:#fff;font-size:13px;z-index:var(--z-toast);background:' + (ok !== false ? '#16a34a' : '#dc2626');
    t.innerHTML = '<i class="fa-solid fa-' + (ok !== false ? 'check-circle' : 'exclamation-circle') + ' mr-2"></i>' + msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
}

function sApiPost(action, data) {
    return fetch('/admin/api_settings.php?action=' + action, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(r => r.json());
}
function sApiGet(action) {
    return fetch('/admin/api_settings.php?action=' + action, { credentials: 'same-origin' }).then(r => r.json());
}

// --- Department ---
function loadDeptSettings() {
    sApiGet('dept_get').then(d => {
        if (d.status !== 'success') return;
        const s = d.settings;
        document.getElementById('deptNameEn').value = s.dept_name_en || '';
        document.getElementById('deptNameAm').value = s.dept_name_am || '';
        document.getElementById('churchNameEn').value = s.church_name_en || '';
        document.getElementById('churchNameAm').value = s.church_name_am || '';
        document.getElementById('deptDesc').value = s.dept_description || '';
    });
}

function saveDeptSettings() {
    sApiPost('dept_save', {
        dept_name_en: document.getElementById('deptNameEn').value, dept_name_am: document.getElementById('deptNameAm').value,
        church_name_en: document.getElementById('churchNameEn').value, church_name_am: document.getElementById('churchNameAm').value,
        dept_description: document.getElementById('deptDesc').value
    }).then(d => settingsToast(d.message, d.status === 'success')).catch(() => settingsToast('Network error', false));
}

// --- Preferences ---
function loadPreferences() {
    document.getElementById('codeDigits')._loaded = true;
    sApiGet('dept_get').then(d => {
        if (d.status !== 'success') return;
        const s = d.settings;
        document.getElementById('codePrefix').value = s.member_code_prefix || '';
        document.getElementById('codeDigits').value = s.member_code_digits || '4';
        document.getElementById('autoGenCode').checked = s.auto_generate_code === '1';
        document.getElementById('defAgeGroup').value = s.default_age_group || '';
        document.getElementById('defMemType').value = s.default_member_type || 'regular';
        document.getElementById('defRegType').value = s.default_registration_type || 'direct';
        document.getElementById('phoneRequired').checked = s.phone_required === '1';
        document.getElementById('idAutoGen').checked = s.id_card_auto_generate === '1';
        document.getElementById('guardianAge').value = s.guardian_required_under || '14';
        updateCodePreview();
    });
}

function updateCodePreview() {
    const prefix = document.getElementById('codePrefix') ? document.getElementById('codePrefix').value : '';
    const digits = parseInt(document.getElementById('codeDigits').value || '4');
    const preview = document.getElementById('codePreview');
    if (preview) preview.textContent = prefix + '1'.padStart(digits, '0');
}

function savePreferences() {
    sApiPost('dept_save', {
        member_code_prefix: document.getElementById('codePrefix').value, member_code_digits: document.getElementById('codeDigits').value,
        auto_generate_code: document.getElementById('autoGenCode').checked ? '1' : '0', default_age_group: document.getElementById('defAgeGroup').value,
        default_member_type: document.getElementById('defMemType').value, default_registration_type: document.getElementById('defRegType').value,
        phone_required: document.getElementById('phoneRequired').checked ? '1' : '0', id_card_auto_generate: document.getElementById('idAutoGen').checked ? '1' : '0',
        guardian_required_under: document.getElementById('guardianAge').value
    }).then(d => settingsToast(d.message, d.status === 'success')).catch(() => settingsToast('Network error', false));
}

// --- System Info ---
function loadSystemInfo() {
    sApiGet('system_info').then(d => {
        if (d.status !== 'success') return;
        const i = d.info, m = i.members, u = i.users, db = i.database, c = i.cache;
        document.getElementById('sysStats').innerHTML =
            '<div class="sys-row"><span class="sys-label">Total Members</span><span class="sys-val">' + (m.total || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Active Members</span><span class="sys-val" style="color:#16a34a">' + (m.active || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Archived</span><span class="sys-val" style="color:#94a3b8">' + (m.archived || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">User Accounts</span><span class="sys-val">' + (u.total || 0) + ' (' + (u.active || 0) + ' active)</span></div>' +
            '<div class="sys-row"><span class="sys-label">Database Size</span><span class="sys-val">' + (db.size_mb || '?') + ' MB</span></div>' +
            '<div class="sys-row"><span class="sys-label">Tables</span><span class="sys-val">' + (i.tables || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Total DB Rows</span><span class="sys-val">' + Number(db.total_rows || 0).toLocaleString() + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Photos</span><span class="sys-val">' + (i.photos || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Cache</span><span class="sys-val">' + (c.files || 0) + ' files (' + (c.size_kb || 0) + ' KB)</span></div>' +
            '<div class="sys-row"><span class="sys-label">PHP</span><span class="sys-val">' + (i.php_version || '?') + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Server</span><span class="sys-val" style="font-size:10px">' + escapeHtml(i.server || '?') + '</span></div>';
        const act = i.recent_activity || [];
        document.getElementById('sysActivity').innerHTML = act.length === 0 ? '<p class="text-xs text-slate-400">No recent activity</p>' :
            '<div class="space-y-2">' + act.map(a =>
                '<div class="flex items-center gap-3 p-2 rounded-lg bg-slate-50">' +
                '<div class="w-7 h-7 bg-blue-100 rounded-full flex items-center justify-center"><i class="fa-solid fa-clock-rotate-left text-blue-500 text-[10px]"></i></div>' +
                '<div class="flex-1"><span class="text-xs font-medium text-slate-700">' + escapeHtml(a.username || '') + '</span> <span class="text-[10px] text-slate-400">' + escapeHtml(a.action || '') + '</span></div>' +
                '<span class="text-[10px] text-slate-400">' + (a.created_at ? ((typeof WBWSCalendar!=='undefined'&&WBWSCalendar.formatDateTime)?WBWSCalendar.formatDateTime(String(a.created_at).replace(' ','T')):String(a.created_at)) : '') + '</span></div>'
            ).join('') + '</div>';
    }).catch(() => { document.getElementById('sysStats').innerHTML = '<p class="text-xs text-red-400">Failed to load</p>'; });
}

async function clearCache() {
    if (!(await dcAsk({tier:'low', title:'Clear all cache files?', summary:'Saved report and statistics caches are removed and rebuilt the next time they are needed. Pages may load slower for a moment.', confirmLabel:'Clear cache'}))) return;
    sApiPost('clear_cache', {}).then(d => { settingsToast(d.message, d.status === 'success'); if (d.status === 'success') loadSystemInfo(); }).catch(() => settingsToast('Error', false));
}

/* ═══════════════════════════════════════════════════════════════
   HrReports — combined Attendance Reports (Education + Mezmur).
   HR's own attendance was retired (2026-10-07); the department
   READS attendance taken by the other two departments instead.
   All reads go through the governed api_hr_reports.php endpoint.
════════════════════════════════════════════════════════════════ */
const HrReports = (function () {
    'use strict';

    const API = '<?= $ajaxPrefix ?>api_hr_reports.php';
    let initialized = false;

    function $(id) { return document.getElementById(id); }
    function esc(s) { return escapeHtml(s == null ? '' : String(s)); }

    function apiGet(query) {
        return fetch(API + '?' + query, { credentials: 'same-origin' })
            .then(r => r.json());
    }

    function currentMonth() {
        const el = $('hrRepMonth');
        if (el && el.value) return el.value;
        const now = new Date();
        return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    }

    // The month selector drives the per-member view only; department-level
    // analytics come from the shared Attendance Analytics Hub above.
    let openMemberId = 0;
    function loadSummary() {
        const month = currentMonth();
        if ($('hrRepMonth')) $('hrRepMonth').value = month;
        if (openMemberId > 0) openMember(openMemberId);
    }

    function searchMembers() {
        const q = ($('hrRepSearch') && $('hrRepSearch').value || '').trim();
        const results = $('hrRepMemberResults');
        const detail = $('hrRepMemberDetail');
        if (detail) detail.innerHTML = '';
        if (q.length < 2) {
            if (results) results.innerHTML = '<p class="text-xs text-slate-400">Type at least 2 characters to search.</p>';
            return;
        }
        if (results) results.innerHTML = '<p class="text-xs text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Searching…</p>';
        apiGet('action=member_search&q=' + encodeURIComponent(q)).then(d => {
            if (d.status !== 'success' || !(d.items || []).length) {
                if (results) results.innerHTML = '<p class="text-xs text-slate-400">No members found.</p>';
                return;
            }
            if (results) results.innerHTML = '<div class="flex flex-wrap gap-2">' + d.items.filter(m => Number.isSafeInteger(Number(m.id)) && Number(m.id) > 0).map(m =>
                '<button type="button" onclick="HrReports.openMember(' + Number(m.id) + ')" class="px-3 py-1.5 bg-slate-100 hover:bg-emerald-50 hover:border-emerald-300 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 transition">' +
                esc(m.student_name + ' ' + (m.father_name || '')) +
                (m.member_code ? ' <span class="text-slate-400">· ' + esc(m.member_code) + '</span>' : '') +
                '</button>'
            ).join('') + '</div>';
        }).catch(() => {
            if (results) results.innerHTML = '<p class="text-xs text-slate-400">Connection error. Try again.</p>';
        });
    }

    function statusBadge(status) {
        const meta = {
            present: ['bg-emerald-100 text-emerald-700', 'Present'],
            absent: ['bg-red-100 text-red-700', 'Absent'],
            late: ['bg-amber-100 text-amber-700', 'Late'],
            excused: ['bg-blue-100 text-blue-700', 'Excused']
        }[status] || ['bg-slate-100 text-slate-600', status];
        return '<span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold ' + meta[0] + '">' + esc(meta[1]) + '</span>';
    }

    function openMember(memberId) {
        openMemberId = Number(memberId) || 0;
        const month = currentMonth();
        const box = $('hrRepMemberDetail');
        if (!box) return;
        box.innerHTML = '<p class="text-xs text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Loading member attendance…</p>';
        apiGet('action=member_detail&member_id=' + encodeURIComponent(memberId) + '&month=' + encodeURIComponent(month)).then(d => {
            if (d.status !== 'success' || !d.member) {
                box.innerHTML = '<p class="text-xs text-slate-400">' + esc(d.message || 'Member not found.') + '</p>';
                return;
            }
            const m = d.member;
            const rows = []
                .concat((d.education || []).map(r => ({ date: r.date, source: 'Education', label: r.class_name || '—', status: r.status })))
                .concat((d.mezmur || []).map(r => ({ date: r.date, source: 'Mezmur', label: 'Section', status: r.status })))
                .sort((a, b) => (a.date < b.date ? 1 : -1));
            let html = '<div class="bg-slate-50 rounded-2xl border border-slate-200 p-4">' +
                '<div class="flex flex-wrap items-center gap-2 mb-3">' +
                '<b class="text-sm text-slate-800">' + esc(m.student_name + ' ' + (m.father_name || '')) + '</b>' +
                (m.member_code ? '<span class="text-xs text-slate-400">' + esc(m.member_code) + '</span>' : '') +
                (m.current_section ? '<span class="px-2 py-0.5 rounded-full bg-slate-200 text-slate-600 text-[11px] font-semibold">' + esc(m.current_section) + '</span>' : '') +
                '<span class="text-xs text-slate-400">· ' + esc(month) + '</span></div>';
            if (!rows.length) {
                html += '<p class="text-xs text-slate-400">No attendance recorded for this member in ' + esc(month) + '.</p>';
            } else {
                html += '<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">' +
                    '<th class="py-2 pr-3">Date</th><th class="py-2 pr-3">Source</th><th class="py-2 pr-3">Class / Section</th><th class="py-2">Status</th></tr></thead><tbody>' +
                    rows.map(r =>
                        '<tr class="border-b border-slate-50">' +
                        '<td class="py-2 pr-3 whitespace-nowrap">' + esc(r.date) + '</td>' +
                        '<td class="py-2 pr-3"><span class="px-2 py-0.5 rounded-full text-[11px] font-semibold ' + (r.source === 'Education' ? 'bg-indigo-100 text-indigo-700' : 'bg-violet-100 text-violet-700') + '">' + r.source + '</span></td>' +
                        '<td class="py-2 pr-3">' + esc(r.label) + '</td>' +
                        '<td class="py-2">' + statusBadge(r.status) + '</td></tr>'
                    ).join('') + '</tbody></table></div>';
            }
            html += '</div>';
            box.innerHTML = html;
        }).catch(() => {
            box.innerHTML = '<p class="text-xs text-slate-400">Connection error. Try again.</p>';
        });
    }

    function init() {
        if (initialized) return;
        initialized = true;
        loadSummary();
    }

    return {
        init: init,
        loadSummary: loadSummary,
        searchMembers: searchMembers,
        openMember: openMember
    };
})();

// Keep the settings preference preview reactive.
(function() {
    if (document.getElementById('codePrefix')) document.getElementById('codePrefix').addEventListener('input', updateCodePreview);
    if (document.getElementById('codeDigits')) document.getElementById('codeDigits').addEventListener('change', updateCodePreview);
})();
</script>

<style>
@keyframes animate-in {
    from { opacity: 0; transform: scale(0.95) translateY(-10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.animate-in { animation: animate-in 0.2s ease-out; }
</style>

<!-- Image Viewer Overlay -->
<div id="imageViewerOverlay" class="fixed inset-0 z-[100] bg-slate-900/95 hidden items-center justify-center backdrop-blur-sm">
    <div id="img-container" class="w-full h-full flex items-center justify-center cursor-grab overflow-hidden relative">
        <img id="viewer-img" src="" class="max-w-[90%] max-h-[90%] shadow-2xl transition-transform duration-100 ease-out" draggable="false">
    </div>

    <div class="fixed bottom-8 left-1/2 -translate-x-1/2 bg-slate-800/90 backdrop-blur border border-white/10 rounded-full px-4 py-2 flex items-center gap-4 shadow-xl z-[101]">
        <button onclick="adjustViewerZoom(-0.2)" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Zoom Out">
            <i class="fa-solid fa-minus"></i>
        </button>
        <button onclick="resetViewerView()" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Reset">
            <i class="fa-solid fa-compress"></i>
        </button>
        <button onclick="adjustViewerZoom(0.2)" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Zoom In">
            <i class="fa-solid fa-plus"></i>
        </button>
        <div class="w-px h-6 bg-slate-600"></div>
        <button onclick="rotateViewerImg()" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Rotate">
            <i class="fa-solid fa-rotate-right"></i>
        </button>
        <button onclick="closeDocFullscreen()" class="w-10 h-10 rounded-full bg-red-500/20 hover:bg-red-500 text-red-200 hover:text-white flex items-center justify-center transition" title="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
</div>

<!-- DATA SYNC MODAL (EXPORT/IMPORT) -->
<div id="dataSyncModal" class="fixed inset-0 hidden items-center justify-center p-4" style="display:none;z-index:10000;background:rgba(15,23,42,0.6);" onclick="if(event.target===this)closeDataSyncModal();">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl overflow-hidden flex flex-col max-h-[90vh]">
        <!-- Header -->
        <div class="px-6 py-4 flex items-center justify-between sticky top-0 z-10" style="background:linear-gradient(135deg,#064e3b,#047857);color:#fff">
            <div>
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-file-excel"></i>
                    Excel Sync
                </h3>
                <p class="text-xs text-emerald-100 mt-0.5">Download members, edit in Excel, then upload to apply changes.</p>
            </div>
            <button type="button" onclick="closeDataSyncModal()" class="w-8 h-8 rounded-lg bg-white/15 hover:bg-white/25 flex items-center justify-center transition" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        
        <!-- Body -->
        <div class="p-6 overflow-y-auto space-y-6">
            <p class="text-sm text-slate-600">Filled cells update the member. Blank cells are left unchanged. Member codes cannot be edited. Use the <span class="font-medium text-slate-800">Class</span> dropdown to assign an Education class (leave it empty to add the member without a class). Date of Birth is the Ethiopian date (dd/mm/yyyy); age section is calculated from it. Editable workbooks are limited to 2,000 rows to match the safe import transaction limit; complete CSV downloads stream the full roster.</p>
            <p class="text-sm text-slate-600"><span class="font-medium text-slate-800">Check file</span> first: it lists every problem and saves nothing. <span class="font-medium text-slate-800">Start Import</span> saves the whole file only when every row is valid — if any row has a problem, nothing is saved.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Temporary Members Column -->
                <div class="border-2 border-amber-200 rounded-2xl p-5 bg-amber-50/30">
                    <h4 class="font-bold text-amber-800 mb-4 flex items-center gap-2 text-lg">
                        <i class="fa-solid fa-hourglass-half text-amber-500"></i> Temporary Members
                    </h4>
                    
                    <div class="bg-white rounded-xl p-4 shadow-sm mb-4">
                        <p class="text-xs text-slate-500 mb-3">Download the template with fields specific to Temporary members.</p>
                        <a href="/admin/api_export_members.php?tier=temporary" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-amber-100 text-amber-800 rounded-lg font-semibold hover:bg-amber-200 transition border border-amber-300">
                            <i class="fa-solid fa-download"></i> Download Editable Template
                        </a>
                        <a href="/admin/api_export_members.php?tier=temporary&amp;format=csv" class="mt-2 w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-white text-amber-800 rounded-lg font-semibold hover:bg-amber-50 transition border border-amber-200">
                            <i class="fa-solid fa-file-csv"></i> Download Complete CSV
                        </a>
                    </div>
                    
                    <div class="bg-white rounded-xl p-4 shadow-sm">
                        <p class="text-xs text-slate-500 mb-3">Upload updated Temporary member data.</p>
                        <form id="formSyncTemporary" class="w-full">
                            <input type="file" id="import_file_temp" accept=".xlsx" class="hidden">
                            <label for="import_file_temp" class="cursor-pointer w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-slate-50 border border-slate-300 text-slate-700 rounded-lg font-medium hover:bg-slate-100 transition mb-2">
                                <i class="fa-solid fa-folder-open"></i> <span id="fileNameDisplayTemp" class="truncate max-w-[200px]">Select Excel File</span>
                            </label>
                            <button type="button" id="btnCheckTemp" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 mb-2 bg-white text-amber-800 rounded-lg font-semibold hover:bg-amber-50 transition border border-amber-300 disabled:opacity-50">
                                <i class="fa-solid fa-list-check"></i> Check file
                            </button>
                            <button type="submit" id="btnSyncTemp" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-amber-600 text-white rounded-lg font-bold hover:bg-amber-700 transition disabled:opacity-50">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Start Import
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Permanent Members Column -->
                <div class="border-2 border-emerald-200 rounded-2xl p-5 bg-emerald-50/30">
                    <h4 class="font-bold text-emerald-800 mb-4 flex items-center gap-2 text-lg">
                        <i class="fa-solid fa-user-check text-emerald-500"></i> Permanent Members
                    </h4>
                    
                    <div class="bg-white rounded-xl p-4 shadow-sm mb-4">
                        <p class="text-xs text-slate-500 mb-3">Download the full template for Permanent members.</p>
                        <a href="/admin/api_export_members.php?tier=permanent" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-emerald-100 text-emerald-800 rounded-lg font-semibold hover:bg-emerald-200 transition border border-emerald-300">
                            <i class="fa-solid fa-download"></i> Download Editable Template
                        </a>
                        <a href="/admin/api_export_members.php?tier=permanent&amp;format=csv" class="mt-2 w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-white text-emerald-800 rounded-lg font-semibold hover:bg-emerald-50 transition border border-emerald-200">
                            <i class="fa-solid fa-file-csv"></i> Download Complete CSV
                        </a>
                    </div>
                    
                    <div class="bg-white rounded-xl p-4 shadow-sm">
                        <p class="text-xs text-slate-500 mb-3">Upload updated Permanent member data.</p>
                        <form id="formSyncPermanent" class="w-full">
                            <input type="file" id="import_file_perm" accept=".xlsx" class="hidden">
                            <label for="import_file_perm" class="cursor-pointer w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-slate-50 border border-slate-300 text-slate-700 rounded-lg font-medium hover:bg-slate-100 transition mb-2">
                                <i class="fa-solid fa-folder-open"></i> <span id="fileNameDisplayPerm" class="truncate max-w-[200px]">Select Excel File</span>
                            </label>
                            <button type="button" id="btnCheckPerm" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 mb-2 bg-white text-emerald-800 rounded-lg font-semibold hover:bg-emerald-50 transition border border-emerald-300 disabled:opacity-50">
                                <i class="fa-solid fa-list-check"></i> Check file
                            </button>
                            <button type="submit" id="btnSyncPerm" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-emerald-600 text-white rounded-lg font-bold hover:bg-emerald-700 transition disabled:opacity-50">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Start Import
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Import Status/Logs -->
            <div id="dataSyncStatusContainer" class="hidden">
                <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Import Logs</h4>
                <div id="dataSyncStatusLog" class="bg-slate-900 text-green-400 font-mono text-xs p-3 rounded-lg max-h-72 overflow-y-auto break-words" aria-live="polite">
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" integrity="sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw" crossorigin="anonymous"></script>
<script src="/admin/js/hr_data_sync.js?v=20261022r"></script>
<?php include __DIR__ . '/../components/comm/comm_section.php'; ?>
<script>
// Explain a permission bounce (access_control.php → index.php → dashboard.php?error=access_denied).
(function () {
    try {
        const u = new URL(window.location.href);
        if (u.searchParams.get('error') === 'access_denied') {
            if (typeof showToast === 'function') showToast('You do not have permission to open that page.', 'error');
            u.searchParams.delete('error');
            history.replaceState(null, '', u.toString());
        }
    } catch (e) { /* decorative */ }
})();
</script>
</body>
</html>
