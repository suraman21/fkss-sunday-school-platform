<?php
/**
 * ============================================================================
 * School Calendar — yearly preparation + "Day Off" days (P1)
 * ============================================================================
 * Class-day-only attendance (owner decisions 2026-10-20; plan
 * docs/planning/ATTENDANCE_CLASS_DAY_ENFORCEMENT_PLAN_2026-10-20.md).
 * Education dept prepares the year's holidays and marks/reopens Day Offs;
 * everyone else sees the statuses (read-only). Ethiopian calendar only:
 * every month label / day number comes from the API.
 * No data, no SQL, no CDN assets on this shell.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/SchoolCalendarService.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}
$userRole = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($userRole, \App\Services\SchoolCalendarService::READ_ROLES, true)) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}
$canWrite = in_array($userRole, \App\Services\SchoolCalendarService::WRITE_ROLES, true);
$csrfToken = generateCsrfToken();
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$roleLabels = [
    'super_admin' => 'Super Admin', 'school_admin' => 'School Admin', 'info_dept' => 'Information Department',
    'hr_dept' => 'HR Department', 'edu_dept' => 'Education Department', 'mezmur_dept' => 'Mezmur Department',
    'teacher' => 'Teacher', 'attendance_taker' => 'Attendance Taker',
];
$assetVer = '20261026lg';
$schoolName = defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'SSMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>School Calendar · <?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/admin/css/reports_v2.css?v=<?= $assetVer ?>">
<style>
.sc-wrap{padding:16px 20px 40px;max-width:1320px;margin:0 auto}
.sc-banner{background:#f0f9ff;border:1px solid #bae6fd;border-radius:var(--rc-radius);padding:10px 14px;font-size:13px;color:#0c4a6e;margin-bottom:14px;line-height:1.55}
.sc-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:16px}
@media (max-width:980px){.sc-grid{grid-template-columns:1fr}}
.sc-panel{background:#fff;border:1px solid var(--rc-line);border-radius:var(--rc-radius);box-shadow:var(--rc-shadow);padding:14px 16px;margin-bottom:16px}
.sc-panel h2{margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:var(--rc-muted)}
.sc-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:12px}
.sc-toolbar label{display:flex;flex-direction:column;font-size:11px;font-weight:600;color:var(--rc-muted);text-transform:uppercase;letter-spacing:.05em;gap:4px}
.sc-toolbar select{padding:7px 9px;border:1px solid var(--rc-line);border-radius:8px;background:#fff;font:inherit;font-size:13px;min-width:150px}
.sc-months{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.sc-month{padding:5px 11px;border:1px solid var(--rc-line);border-radius:999px;background:#fff;font-size:12px;cursor:pointer;white-space:nowrap}
.sc-month.active{background:var(--rc-primary);color:#fff;border-color:var(--rc-primary)}
.sc-month .cnt{opacity:.75;font-size:10px;margin-left:4px}
.sc-week{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}
.sc-wd{text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--rc-muted);padding:2px 0}
.sc-day{position:relative;min-height:78px;border:1px solid var(--rc-line);border-radius:10px;padding:6px 7px;background:#fff;cursor:default}
.sc-day.clickable{cursor:pointer}
.sc-day.clickable:hover{border-color:var(--rc-primary);box-shadow:0 2px 8px rgba(14,116,144,.15)}
.sc-day .n{font-size:15px;font-weight:700}
.sc-day .wd{font-size:10px;color:var(--rc-muted)}
.sc-day .tag{display:inline-block;margin-top:4px;padding:1px 7px;border-radius:999px;font-size:10px;font-weight:700;line-height:1.5}
.sc-day.st-ok{background:#f0fdf4;border-color:#bbf7d0}.sc-day.st-ok .tag{background:#dcfce7;color:#166534}
.sc-day.st-classday{background:#f0f9ff;border-color:#bae6fd}.sc-day.st-classday .tag{background:#dbeafe;color:#1e40af}
.sc-day.st-makeup{background:#f0fdfa;border-color:#99f6e4}.sc-day.st-makeup .tag{background:#ccfbf1;color:#0f766e}
.sc-day.st-holiday{background:#faf5ff;border-color:#e9d5ff}.sc-day.st-holiday .tag{background:#f3e8ff;color:#6b21a8}
.sc-day.st-dayoff{background:#fff7ed;border-color:#fed7aa}.sc-day.st-dayoff .tag{background:#ffedd5;color:#9a3412}
.sc-day.st-off{background:#f8fafc;color:#94a3b8}.sc-day.st-off .tag{background:#f1f5f9;color:#64748b}
.sc-day .why{margin-top:3px;font-size:10px;color:#7c2d12;line-height:1.35}
.sc-legend{display:flex;gap:10px;flex-wrap:wrap;font-size:11px;color:var(--rc-muted);margin-top:10px}
.sc-legend span{display:inline-flex;align-items:center;gap:4px}
.sc-legend i{width:11px;height:11px;border-radius:3px;display:inline-block;border:1px solid var(--rc-line)}
.sc-list{list-style:none;margin:0;padding:0;font-size:13px}
.sc-list li{padding:7px 0;border-bottom:1px solid var(--rc-line);display:flex;justify-content:space-between;gap:8px;align-items:flex-start}
.sc-list li:last-child{border-bottom:none}
.sc-list .t{font-weight:600}
.sc-list .s{font-size:11px;color:var(--rc-muted)}
.sc-form{display:flex;flex-direction:column;gap:8px;margin-top:10px}
.sc-form label{font-size:11px;font-weight:600;color:var(--rc-muted);text-transform:uppercase;letter-spacing:.05em;display:flex;flex-direction:column;gap:4px}
.sc-form input,.sc-form select,.sc-form textarea{padding:7px 9px;border:1px solid var(--rc-line);border-radius:8px;background:#fff;font:inherit;font-size:13px}
.sc-check{display:flex!important;flex-direction:row!important;align-items:center;gap:6px;text-transform:none!important;font-size:13px!important}
.sc-dialog{position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:16px;z-index:50}
.sc-dialogbox{background:#fff;border-radius:14px;max-width:460px;width:100%;padding:18px 20px;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.sc-dialogbox h2{margin:0 0 4px;font-size:17px}
.sc-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
.nc-toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:10px 16px;border-radius:10px;font-size:13px;z-index:60}
.nc-toast.nc-err{background:#b91c1c}.nc-toast.nc-ok{background:#166534}
</style>
</head>
<body>
<div class="rc" data-role="<?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?>"
     data-canwrite="<?= $canWrite ? '1' : '0' ?>">
  <header class="rc-header">
    <div class="rc-header-left">
      <a class="rc-iconbtn" href="/admin/dashboard.php" title="Back to dashboard" aria-label="Back">
        <svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
      </a>
      <div>
        <h1 class="rc-title">School Calendar <span class="rc-badge amh">የትምህርት ቤት ቀን መቁጠሪያ</span></h1>
        <div class="rc-subtitle"><?= htmlspecialchars($roleLabels[$userRole] ?? $userRole, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ethio_date_format($today, 'F j, Y'), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
    <div class="rc-header-right">
      <a class="rc-btn rc-btn-ghost rc-btn-sm" href="/admin/attendance_monthly.php">Monthly Attendance</a>
    </div>
  </header>

  <main class="sc-wrap">
    <div class="sc-banner">
      Attendance can be taken only on <strong>class days</strong> (from each class's timetable working days), inside the
      <strong>45-day</strong> backdating window. <strong>Day Off</strong> days and <strong>holidays</strong> have no classes.
      <?= $canWrite
          ? 'Education prepares the year here: enter the holidays, and mark a day off when classes cannot hold.'
          : 'Only the Education department can change holidays and day off days.' ?>
    </div>

    <div class="sc-toolbar">
      <label>Check as class
        <select id="sc-class"><option value="0">School view (all days)</option></select>
      </label>
      <label>Month (EC)
        <select id="sc-month"></select>
      </label>
      <label>Ethiopian year
        <select id="sc-year"></select>
      </label>
    </div>
    <div class="sc-months" id="sc-monthstrip"></div>

    <div class="sc-grid">
      <div>
        <div class="sc-panel">
          <h2 id="sc-gridtitle">Month</h2>
          <div class="sc-week" id="sc-weekdays"></div>
          <div class="sc-week" id="sc-days" style="margin-top:6px"></div>
          <div class="sc-legend" id="sc-legend"></div>
        </div>
      </div>
      <div>
        <div class="sc-panel">
          <h2>Holidays &amp; vacations <span class="amh" style="text-transform:none;letter-spacing:0">በዓላትና ዕረፋቶች</span></h2>
          <ul class="sc-list" id="sc-holidays"></ul>
          <?php if ($canWrite): ?>
          <form class="sc-form" id="sc-holiday-form">
            <label>Title (English)<input id="sc-h-title" maxlength="120" placeholder="e.g. Fasika"></label>
            <label>Title (Amharic)<input id="sc-h-title-am" maxlength="120" placeholder="ለምሳሌ ፋሲካ"></label>
            <label>From (Gregorian)<input id="sc-h-from" type="date"></label>
            <label>To (Gregorian, optional)<input id="sc-h-to" type="date"></label>
            <label>Type
              <select id="sc-h-type">
                <option value="holiday">Holiday</option>
                <option value="vacation">Vacation</option>
                <option value="closure">Closure</option>
                <option value="other">Other</option>
              </select>
            </label>
            <button class="rc-btn rc-btn-primary rc-btn-sm" type="submit">Add holiday</button>
          </form>
          <?php endif; ?>
        </div>

        <div class="sc-panel">
          <h2>Classes without working days <span class="amh" style="text-transform:none;letter-spacing:0">የስራ ቀን የሌላቸው ክፍሎች</span></h2>
          <div id="sc-unconfigured" class="sc-list" style="font-size:12px;color:var(--rc-muted)">—</div>
        </div>

        <div class="sc-panel">
          <h2>Day Off days <span class="amh" style="text-transform:none;letter-spacing:0">ዕረፍት ቀናት</span></h2>
          <ul class="sc-list" id="sc-dayoffs"></ul>
        </div>
      </div>
    </div>
  </main>
</div>

<div id="sc-dialog-host"></div>
<div id="sc-toast-host"></div>
<script>
  window.SCHOOL_CALENDAR_CFG = {
    api: '/admin/api_school_calendar.php',
    canWrite: <?= $canWrite ? 'true' : 'false' ?>,
    csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>,
    today: <?= json_encode($today->format('Y-m-d')) ?>
  };
</script>
<script src="/admin/js/wbws-calendar.js?v=<?= $assetVer ?>"></script>
<script src="/admin/js/danger_confirm.js?v=20261022dc4"></script>
<script src="/admin/js/school_calendar.js?v=<?= $assetVer ?>"></script>
</body>
</html>
