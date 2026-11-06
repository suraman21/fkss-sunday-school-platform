<?php
/**
 * School Admin Dashboard — <?= SCHOOL_NAME ?>
 * ═══════════════════════════════════════════════════════════════
 * ADVANCED v3 — Futuristic glass UI with full feature set
 * ═══════════════════════════════════════════════════════════════
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';
require_once __DIR__ . '/../backend/services/MemberCategory.php';

$fullName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'School Admin';
$username = $_SESSION['admin_username'] ?? '';
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');
$greeting = ((int)$today->format('H') < 12) ? 'Good Morning' : (((int)$today->format('H') < 17) ? 'Good Afternoon' : 'Good Evening');
$initials = strtoupper(substr($fullName, 0, 1));
$csrfToken = generateCsrfToken(); // always issue a token: login.php resets the session and this dashboard renders before any other generator runs
$isImpersonating = !empty($_SESSION['original_admin_role']);

// ── Stats ──
$stats = ['total'=>0,'active'=>0,'warning'=>0,'inactive'=>0,'pending'=>0,'transfer'=>0,'direct'=>0,'male'=>0,'female'=>0,'sections'=>0,'teachers'=>0,'users'=>0];
if(isset($conn)){
    $r=$conn->query("SELECT COUNT(*) t,SUM(status='active') a,SUM(status='warning') w,SUM(status='inactive') i,SUM(registration_type='waiting') p,SUM(registration_type='transfer') tr,SUM(registration_type='direct') d,SUM(gender='male') m,SUM(gender='female') f FROM members WHERE status!='archived'");
    if($r&&$row=$r->fetch_assoc()){$stats['total']=(int)($row['t']??0);$stats['active']=(int)($row['a']??0);$stats['warning']=(int)($row['w']??0);$stats['inactive']=(int)($row['i']??0);$stats['pending']=(int)($row['p']??0);$stats['transfer']=(int)($row['tr']??0);$stats['direct']=(int)($row['d']??0);$stats['male']=(int)($row['m']??0);$stats['female']=(int)($row['f']??0);}
    $r=$conn->query("SELECT COUNT(DISTINCT current_section) c FROM members WHERE status!='archived' AND current_section IS NOT NULL AND current_section!=''");
    if($r&&$row=$r->fetch_assoc())$stats['sections']=(int)($row['c']??0);
    $r=$conn->query("SELECT SUM(is_teacher=1) t FROM members WHERE status!='archived'");
    if($r&&$row=$r->fetch_assoc())$stats['teachers']=(int)($row['t']??0);
    $r=$conn->query("SELECT COUNT(*) c FROM users WHERE is_active=1");
    if($r&&$row=$r->fetch_assoc())$stats['users']=(int)($row['c']??0);
}

// ── Attendance today ──
$attToday=['present'=>0,'absent'=>0,'late'=>0,'total'=>0];
if(isset($conn)){try{$td=date('Y-m-d');$r=$conn->query("SELECT SUM(status='present') p,SUM(status='absent') a,SUM(status='late') l,COUNT(*) t FROM attendance WHERE attendance_date='$td'");if($r&&$row=$r->fetch_assoc()){$attToday['present']=(int)($row['p']??0);$attToday['absent']=(int)($row['a']??0);$attToday['late']=(int)($row['l']??0);$attToday['total']=(int)($row['t']??0);}}catch(Exception $e){}}
$attRate=$attToday['total']>0?round(($attToday['present']/$attToday['total'])*100,1):0;

// ── Weekly attendance (last 7 days) ──
$weeklyAtt=[];
if(isset($conn)){try{for($i=6;$i>=0;$i--){$d=date('Y-m-d',strtotime("-$i days"));$r=$conn->query("SELECT SUM(status='present') p,SUM(status='absent') a,SUM(status='late') l,COUNT(*) t FROM attendance WHERE attendance_date='$d'");$row=$r?$r->fetch_assoc():null;$weeklyAtt[]=['date'=>$d,'day'=>date('D',strtotime($d)),'present'=>(int)($row['p']??0),'absent'=>(int)($row['a']??0),'late'=>(int)($row['l']??0),'total'=>(int)($row['t']??0)];}}catch(Exception $e){}}

// ── Section distribution ──
$sectionDist=[];if(isset($conn)){$r=$conn->query("SELECT COALESCE(current_section,'Unassigned') s,COUNT(*) c FROM members WHERE status!='archived' GROUP BY current_section ORDER BY c DESC");if($r)while($row=$r->fetch_assoc())$sectionDist[]=$row;}

// ── Users list ──
$usersList=[];if(isset($conn)){$r=$conn->query("SELECT id,username,full_name,role,is_active,created_at,last_login FROM users ORDER BY id DESC");if($r)while($row=$r->fetch_assoc()){$row['status']=$row['is_active']?'active':'inactive';$usersList[]=$row;}}

// ── Recent registrations ──
$recentRegs=0;$recentMembers=[];
if(isset($conn)){
    try{$r=$conn->query("SELECT COUNT(*) c FROM members WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status!='archived'");if($r&&$row=$r->fetch_assoc())$recentRegs=(int)$row['c'];}catch(Exception $e){}
    try{$r=$conn->query("SELECT id,member_code,student_name,father_name,gender,status,current_section,registration_type,created_at FROM members WHERE status!='archived' ORDER BY created_at DESC LIMIT 10");if($r)while($row=$r->fetch_assoc())$recentMembers[]=$row;}catch(Exception $e){}
}

// ── Department stats ──
$deptStats=[];
if(isset($conn)){
    // Finance
    try{$r=$conn->query("SELECT COALESCE(SUM(amount),0) total FROM finance_transactions WHERE type='income' AND YEAR(transaction_date)=YEAR(CURDATE())");$deptStats['finance_income']=$r?($r->fetch_assoc()['total']??0):0;}catch(Exception $e){$deptStats['finance_income']=0;}
    try{$r=$conn->query("SELECT COUNT(*) c FROM finance_transactions WHERE MONTH(transaction_date)=MONTH(CURDATE())");$deptStats['finance_txn_month']=$r?(int)($r->fetch_assoc()['c']??0):0;}catch(Exception $e){$deptStats['finance_txn_month']=0;}
    // Material
    try{$r=$conn->query("SELECT COUNT(*) c FROM material_items WHERE is_active=1");$deptStats['material_items']=$r?(int)($r->fetch_assoc()['c']??0):0;}catch(Exception $e){$deptStats['material_items']=0;}
    try{$r=$conn->query("SELECT COUNT(*) c FROM material_requests WHERE status='pending'");$deptStats['material_pending']=$r?(int)($r->fetch_assoc()['c']??0):0;}catch(Exception $e){$deptStats['material_pending']=0;}
    try{$r=$conn->query("SELECT COUNT(*) c FROM mezmur_hymns WHERE status='active'");$deptStats['mezmur_hymns']=$r?(int)($r->fetch_assoc()['c']??0):0;}catch(Exception $e){$deptStats['mezmur_hymns']=0;}
    try{$r=$conn->query("SELECT COUNT(DISTINCT category) c FROM mezmur_hymns");$deptStats['mezmur_categories']=$r?(int)($r->fetch_assoc()['c']??0):0;}catch(Exception $e){$deptStats['mezmur_categories']=0;}
    // Education
    try{$r=$conn->query("SELECT COUNT(*) c FROM classes WHERE is_active=1");$deptStats['edu_classes']=$r?(int)($r->fetch_assoc()['c']??0):0;}catch(Exception $e){$deptStats['edu_classes']=0;}
    try{$r=$conn->query("SELECT COUNT(*) c FROM class_enrollments WHERE status='active'");$deptStats['edu_enrolled']=$r?(int)($r->fetch_assoc()['c']??0):0;}catch(Exception $e){$deptStats['edu_enrolled']=0;}
}

// ── System health ──
$dbSize=0;$tableCount=0;
if(isset($conn)){
    try{$dbName=$conn->query("SELECT DATABASE()")->fetch_row()[0];$r=$conn->query("SELECT COUNT(*) c, ROUND(SUM(data_length+index_length)/1024/1024,2) s FROM information_schema.TABLES WHERE table_schema='$dbName'");if($r&&$row=$r->fetch_assoc()){$tableCount=(int)$row['c'];$dbSize=(float)$row['s'];}}catch(Exception $e){}
}

$mPct=$stats['total']>0?round($stats['male']/$stats['total']*100):0;$fPct=100-$mPct;
$mStroke=round($mPct*3.14);$fStroke=round($fPct*3.14);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">
<title>School Admin — <?= SCHOOL_NAME_SHORT ?></title>
<?= wbws_calendar_scripts($conn) ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⛪</text></svg>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous"/>
<script src="/admin/js/request-id.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" integrity="sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js" integrity="sha384-fCAW/rDWORTbQXSiB7mOg0QtQ5c+r0f544y6XoKjuVva0nMBlCpNUjiFeG5iMdS3" crossorigin="anonymous"></script>
<script src="/admin/js/request-id.js"></script>
<script src="/admin/js/report-format.js"></script>
<script src="/admin/js/danger_confirm.js?v=20261022dc4"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;500;600;700&family=Noto+Serif+Ethiopic:wght@400;600;700&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
:root,[data-theme="dark"]{
--ac:#06b6d4;--ac2:#8b5cf6;--bg:#07080d;--sb:#0a0c14;--card:rgba(255,255,255,0.025);--cb:rgba(255,255,255,0.06);
--text:#c8cdd5;--dim:#5e6778;--bright:#f0f2f5;--ok:#10b981;--warn:#f59e0b;--bad:#ef4444;--info:#06b6d4;--purple:#8b5cf6;--pink:#ec4899;
--glass:rgba(12,15,25,0.7);--glass-border:rgba(255,255,255,0.08);--table-hover:rgba(6,182,212,0.02);
}
[data-theme="light"]{
--ac:#0891b2;--ac2:#7c3aed;--bg:#f3f4f6;--sb:#ffffff;--card:rgba(255,255,255,0.8);--cb:rgba(0,0,0,0.08);
--text:#374151;--dim:#9ca3af;--bright:#111827;--ok:#059669;--warn:#d97706;--bad:#dc2626;--info:#0891b2;--purple:#7c3aed;--pink:#db2777;
--glass:rgba(255,255,255,0.8);--glass-border:rgba(0,0,0,0.06);--table-hover:rgba(0,0,0,0.02);
}
body{font-family:'DM Sans',system-ui,sans-serif;min-height:100vh;display:flex;background:var(--bg);color:var(--text);overflow-x:hidden;transition:background .3s,color .3s}
.amharic{font-family:'Noto Sans Ethiopic','Noto Serif Ethiopic',sans-serif}
[data-theme="dark"] body::before{content:'';position:fixed;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(ellipse at 20% 50%,rgba(6,182,212,0.04) 0%,transparent 50%),radial-gradient(ellipse at 80% 20%,rgba(139,92,246,0.03) 0%,transparent 50%);animation:drift 30s ease-in-out infinite;pointer-events:none;z-index:0}
@keyframes drift{0%,100%{transform:translate(0,0)}33%{transform:translate(2%,-2%)}66%{transform:translate(-1%,1%)}}

/* SIDEBAR */
aside{width:260px;background:var(--sb);padding:1.25rem 1rem;display:flex;flex-direction:column;gap:.4rem;position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0;z-index:20;border-right:1px solid var(--cb);transition:background .3s}
aside::-webkit-scrollbar{width:3px}aside::-webkit-scrollbar-thumb{background:rgba(128,128,128,0.2);border-radius:99px}
.brand{display:flex;align-items:center;gap:.75rem;padding:.5rem .5rem 1rem;border-bottom:1px solid var(--cb);margin-bottom:.5rem}
.brand-logo{width:42px;height:42px;border-radius:13px;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:1rem;box-shadow:0 0 20px rgba(6,182,212,0.3);position:relative}
.nt{font-size:.58rem;text-transform:uppercase;letter-spacing:.12em;color:var(--dim);padding:.65rem .75rem .3rem;font-weight:600}
.np{display:flex;align-items:center;gap:.7rem;padding:.55rem .75rem;border-radius:10px;color:var(--dim);text-decoration:none;font-size:.8rem;cursor:pointer;transition:all .2s;border:none;background:none;width:100%;text-align:left;position:relative;font-weight:500;font-family:inherit}
.np:hover{background:rgba(128,128,128,0.08);color:var(--text)}
.np.active{background:linear-gradient(135deg,rgba(6,182,212,0.12),rgba(139,92,246,0.06));color:var(--ac);font-weight:600;border:1px solid rgba(6,182,212,0.15)}
.np.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:55%;border-radius:0 4px 4px 0;background:linear-gradient(180deg,var(--ac),var(--ac2))}
.np i{width:18px;text-align:center;font-size:.8rem}
.np .badge{margin-left:auto;background:var(--ac);color:#fff;font-size:.55rem;padding:1px 6px;border-radius:99px;font-weight:700;min-width:18px;text-align:center}
.uc{margin-top:auto;display:flex;align-items:center;gap:.6rem;padding:.7rem;border-radius:12px;background:var(--card);border:1px solid var(--cb)}
.ua{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.8rem;flex-shrink:0}

/* HEADER BAR */
.topbar{display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;flex-wrap:wrap}
.topbar-left{flex:1;min-width:200px}
.topbar-right{display:flex;align-items:center;gap:.5rem}
.search-box{position:relative;width:280px}
.search-box input{width:100%;padding:.5rem .85rem .5rem 2.2rem;background:var(--card);border:1px solid var(--cb);border-radius:10px;color:var(--bright);font-size:.78rem;outline:none;font-family:inherit;transition:border .2s}
.search-box input:focus{border-color:var(--ac)}
.search-box i{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--dim);font-size:.75rem}
.search-results{position:absolute;top:100%;left:0;right:0;background:var(--sb);border:1px solid var(--cb);border-radius:10px;margin-top:4px;max-height:300px;overflow-y:auto;z-index:60;display:none;box-shadow:0 15px 40px rgba(0,0,0,0.3)}
.search-results.show{display:block}
.sr-item{padding:.5rem .75rem;font-size:.75rem;cursor:pointer;border-bottom:1px solid var(--cb);transition:background .15s;display:flex;align-items:center;gap:.5rem}
.sr-item:hover{background:var(--table-hover)}
.icon-btn{width:36px;height:36px;border-radius:10px;border:1px solid var(--cb);background:var(--card);color:var(--dim);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;position:relative;font-size:.85rem}
.icon-btn:hover{border-color:var(--ac);color:var(--ac)}
.notif-count{position:absolute;top:-4px;right:-4px;background:var(--bad);color:#fff;font-size:.5rem;width:16px;height:16px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700}
.notif-drop{position:absolute;top:100%;right:0;width:320px;background:var(--sb);border:1px solid var(--cb);border-radius:12px;margin-top:6px;max-height:350px;overflow-y:auto;z-index:60;display:none;box-shadow:0 15px 40px rgba(0,0,0,0.3)}
.notif-drop.show{display:block}
.notif-item{padding:.6rem .75rem;font-size:.72rem;border-bottom:1px solid var(--cb);display:flex;gap:.5rem}
.notif-item:hover{background:var(--table-hover)}
.notif-dot{width:6px;height:6px;border-radius:50%;background:var(--ac);flex-shrink:0;margin-top:5px}

/* IMPERSONATE BAR */
.imp-bar{background:linear-gradient(90deg,rgba(245,158,11,.12),rgba(239,68,68,.08));border:1px solid rgba(245,158,11,.2);border-radius:10px;padding:.5rem 1rem;margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;font-size:.75rem;color:var(--warn)}

/* MAIN */
main{flex:1;padding:1.25rem 1.75rem 6rem;overflow-y:auto;max-width:calc(100vw - 260px);position:relative;z-index:1}
.cs{display:none;animation:fadeUp .35s ease}.cs.active{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* STAT CARDS */
.sg{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:.75rem;margin-bottom:1.25rem}
.sc{background:var(--card);border:1px solid var(--cb);border-radius:14px;padding:1rem;transition:all .3s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden;backdrop-filter:blur(10px)}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,var(--glass-border),transparent)}
.sc:hover{border-color:rgba(128,128,128,0.15);transform:translateY(-2px);box-shadow:0 10px 30px rgba(0,0,0,0.15)}
.sc .ico{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.9rem;margin-bottom:.5rem}
.sc .val{font-size:1.5rem;font-weight:700;color:var(--bright);letter-spacing:-.02em;line-height:1}
.sc .lbl{font-size:.65rem;color:var(--dim);margin-top:.3rem;font-weight:500}
.sc .trend{font-size:.55rem;font-weight:600;margin-top:.2rem;display:inline-flex;align-items:center;gap:3px;padding:2px 6px;border-radius:6px;background:rgba(16,185,129,0.1);color:var(--ok)}

/* GLASS CARDS */
.cd{background:var(--card);border:1px solid var(--cb);border-radius:14px;padding:1.15rem;margin-bottom:.85rem;position:relative;overflow:hidden;backdrop-filter:blur(10px)}
.cd::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,var(--glass-border),transparent)}
.ct{font-size:.88rem;font-weight:600;color:var(--bright);margin-bottom:.75rem;display:flex;align-items:center;gap:.5rem}

/* TABLES */
.tw{overflow-x:auto;border-radius:10px;border:1px solid var(--cb)}
table{width:100%;border-collapse:collapse;font-size:.75rem}
thead{background:rgba(128,128,128,0.04)}
th{padding:.6rem .75rem;text-align:left;color:var(--dim);font-weight:600;font-size:.62rem;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap;cursor:pointer;user-select:none;transition:color .2s}
th:hover{color:var(--ac)}th.sa::after{content:' ↑';color:var(--ac)}th.sd::after{content:' ↓';color:var(--ac)}
td{padding:.55rem .75rem;border-top:1px solid var(--cb);white-space:nowrap;max-width:180px;overflow:hidden;text-overflow:ellipsis}
tr{transition:background .15s}tr:hover td{background:var(--table-hover)}

/* INPUTS & BUTTONS */
.inp{background:var(--card);border:1px solid var(--cb);border-radius:9px;padding:.5rem .75rem;color:var(--bright);font-size:.78rem;font-family:inherit;outline:none;transition:all .2s}
.inp:focus{border-color:var(--ac);box-shadow:0 0 0 3px rgba(6,182,212,0.06)}.inp::placeholder{color:var(--dim)}
select.inp{cursor:pointer}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .9rem;border-radius:9px;font-size:.75rem;font-weight:600;cursor:pointer;border:none;transition:all .2s;font-family:inherit}
.bp{background:linear-gradient(135deg,var(--ac),var(--ac2));color:#fff;box-shadow:0 4px 14px rgba(6,182,212,0.2)}.bp:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(6,182,212,0.3)}
.bo{background:transparent;border:1px solid var(--cb);color:var(--text)}.bo:hover{border-color:var(--ac);color:var(--ac)}
.bs{padding:.3rem .65rem;font-size:.68rem}
.bg{display:inline-flex;align-items:center;padding:.15rem .45rem;border-radius:99px;font-size:.6rem;font-weight:600;letter-spacing:.02em}
.bg-ok{background:rgba(16,185,129,.12);color:var(--ok)}.bg-w{background:rgba(245,158,11,.1);color:var(--warn)}.bg-bad{background:rgba(239,68,68,.1);color:var(--bad)}
.bg-info{background:rgba(6,182,212,.1);color:var(--info)}.bg-p{background:rgba(139,92,246,.1);color:var(--purple)}
.progress{height:5px;border-radius:99px;background:rgba(128,128,128,0.08);overflow:hidden}
.progress-bar{height:100%;border-radius:99px;transition:width .8s cubic-bezier(.4,0,.2,1)}
.ring-wrap{position:relative;width:110px;height:110px;margin:0 auto}
.ring-wrap svg{transform:rotate(-90deg)}
.ring-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.ring-center .rv{font-size:1.3rem;font-weight:700;color:var(--bright);line-height:1}
.ring-center .rl{font-size:.55rem;color:var(--dim)}
.pulse{width:7px;height:7px;border-radius:50%;background:var(--ok);display:inline-block;position:relative}
.pulse::after{content:'';position:absolute;inset:-3px;border-radius:50%;border:2px solid var(--ok);animation:pr 2s ease-out infinite}
@keyframes pr{0%{opacity:1;transform:scale(1)}100%{opacity:0;transform:scale(2)}}
.flbl{font-size:.58rem;color:var(--dim);display:block;margin-bottom:.2rem;text-transform:uppercase;letter-spacing:.05em;font-weight:600}

/* CHART */
.chart-bar{display:flex;align-items:flex-end;gap:4px;height:100px;padding-top:.5rem}
.chart-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px}
.chart-col .bar{width:100%;border-radius:4px 4px 0 0;min-height:2px;transition:height .6s cubic-bezier(.4,0,.2,1)}
.chart-col .day{font-size:.55rem;color:var(--dim);font-weight:500}
.chart-col .num{font-size:.55rem;color:var(--bright);font-weight:600}

/* QUICK ACTIONS */
.qa-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.5rem}
.qa-btn{display:flex;flex-direction:column;align-items:center;gap:.4rem;padding:.75rem .5rem;border-radius:12px;border:1px solid var(--cb);background:var(--card);cursor:pointer;transition:all .2s;text-decoration:none;color:var(--text)}
.qa-btn:hover{border-color:var(--ac);transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,0.1)}
.qa-btn i{font-size:1.1rem}
.qa-btn span{font-size:.65rem;font-weight:500}

/* DEPT CARDS */
.dept-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem}
.dept-card{background:var(--card);border:1px solid var(--cb);border-radius:14px;padding:1rem;position:relative;overflow:hidden;transition:all .2s}
.dept-card:hover{border-color:rgba(128,128,128,0.15);transform:translateY(-2px)}
.dept-header{display:flex;align-items:center;gap:.5rem;margin-bottom:.65rem}
.dept-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.85rem}
.dept-name{font-size:.8rem;font-weight:600;color:var(--bright)}
.dept-role{font-size:.55rem;color:var(--dim)}
.dept-stats{display:flex;gap:.75rem;margin-bottom:.65rem}
.dept-stat{display:flex;flex-direction:column}
.dept-stat .ds-val{font-size:1.1rem;font-weight:700;color:var(--bright)}
.dept-stat .ds-lbl{font-size:.55rem;color:var(--dim)}
.dept-switch{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .6rem;border-radius:8px;background:linear-gradient(135deg,var(--ac),var(--ac2));color:#fff;font-size:.6rem;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all .2s}
.dept-switch:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(6,182,212,0.3)}

/* TIMELINE */
.tl-item{display:flex;gap:.6rem;padding:.45rem 0;border-bottom:1px solid var(--cb)}
.tl-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px}
.tl-body{flex:1;min-width:0}
.tl-text{font-size:.72rem;color:var(--text)}
.tl-time{font-size:.58rem;color:var(--dim)}

/* MODAL, TOAST, BOTTOM NAV */
/* Cache-hardening fallback (values = themes/design-system.css). The canonical
   scale lives there via mobile.css @import; this inline copy guarantees modal
   stacking even when a browser serves a stale cached mobile.css (no ?v= before
   2026-10-08) or the import fails. Keep in sync with themes/design-system.css. */
:root{--z-content:1;--z-sticky:100;--z-header:200;--z-nav:900;--z-dock:950;--z-fab:1000;--z-toast:1100;--z-overlay:1200;--z-impersonate:1300;--z-tooltip:1400}
.mo{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);z-index:var(--z-overlay);align-items:center;justify-content:center;padding:1rem}
.mo.show{display:flex}
.md{background:var(--sb);border:1px solid var(--cb);border-radius:18px;padding:1.25rem;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 25px 50px rgba(0,0,0,0.4)}
.md h3{font-size:1rem;font-weight:700;color:var(--bright);margin-bottom:.85rem}
.toast{position:fixed;top:1.5rem;right:1.5rem;padding:.6rem 1rem;border-radius:10px;color:#fff;font-size:.75rem;font-weight:600;z-index:200;transform:translateX(120%);transition:transform .3s;display:flex;align-items:center;gap:.5rem;border:1px solid rgba(255,255,255,0.1)}
.toast.show{transform:translateX(0)}.ts{background:rgba(16,185,129,0.9)}.te{background:rgba(239,68,68,0.9)}
.bn{display:none;position:fixed;bottom:0;left:0;right:0;background:var(--sb);border-top:1px solid var(--cb);padding:.3rem 0;z-index:50;backdrop-filter:blur(20px)}
.bn-inner{display:flex;justify-content:space-around;max-width:500px;margin:0 auto}
.bn button,.bn a{display:flex;flex-direction:column;align-items:center;gap:.1rem;background:none;border:none;color:var(--dim);font-size:.5rem;padding:.2rem .4rem;cursor:pointer;text-decoration:none;transition:color .2s;font-family:inherit}
.bn button.active{color:var(--ac)}.bn button i,.bn a i{font-size:.95rem}

/* HEALTH INDICATOR */
.health-dot{width:10px;height:10px;border-radius:50%;display:inline-block}
.health-ok{background:var(--ok);box-shadow:0 0 6px var(--ok)}.health-warn{background:var(--warn);box-shadow:0 0 6px var(--warn)}

/* COUNTER ANIMATION */
.count-up{display:inline-block}

@media(max-width:768px){aside{display:none}main{max-width:100%;padding:1rem .85rem 5rem}.bn{display:block}.sg{grid-template-columns:repeat(2,1fr);gap:.5rem}.sc .val{font-size:1.1rem}.two-col{grid-template-columns:1fr!important}.three-col{grid-template-columns:1fr!important}.search-box{width:100%}.topbar{flex-direction:column;align-items:stretch}.topbar-right{justify-content:flex-end}.dept-grid{grid-template-columns:1fr 1fr}}
@media print{aside,.bn,.no-print,.topbar-right{display:none!important}main{max-width:100%;padding:0}body{background:#fff;color:#000}body::before{display:none}.sc,.cd{border:1px solid #ddd;background:#fff;color:#000}.sc .val,.ct{color:#000}}
</style>
<link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
<?php include __DIR__ . "/../theme.php"; ?>
</head>
<body>
<?php if (function_exists("ay_context_bar_html")) echo ay_context_bar_html($conn ?? null); ?>

<!-- SIDEBAR -->
<aside>
<div class="brand"><div class="brand-logo"><i class="fa-solid fa-church"></i></div><div style="display:flex;flex-direction:column;gap:1px"><span style="font-size:.88rem;font-weight:700;color:var(--bright)"><?= ADMIN_PANEL_TITLE ?></span><span class="amharic" style="font-size:.6rem;color:var(--dim)">ት/ቤት አስተዳዳሪ</span></div></div>
<div class="nt">Main</div>
<button class="np active" data-section="dashboard"><i class="fa-solid fa-gauge-high"></i> Overview <?php if($recentRegs>0):?><span class="badge"><?=$recentRegs?></span><?php endif;?></button>
<button class="np" data-section="members"><i class="fa-solid fa-users"></i> Members <span class="badge" style="background:var(--purple)"><?=$stats['total']?></span></button>
<button class="np" data-section="attendance"><i class="fa-solid fa-clipboard-check"></i> Attendance</button>
<button class="np" data-section="classes"><i class="fa-solid fa-chalkboard"></i> Classes</button>
<button class="np" data-section="academicyear"><i class="fa-solid fa-calendar-days"></i> Academic Year</button>
<div class="nt">Administration</div>
<button class="np" data-section="departments"><i class="fa-solid fa-building"></i> Departments</button>
<button class="np" data-section="staff"><i class="fa-solid fa-user-gear"></i> Staff & Users</button>
<button class="np" data-section="reports"><i class="fa-solid fa-chart-line"></i> Reports & Export</button>
<button class="np" data-section="system"><i class="fa-solid fa-server"></i> System Health</button>
<button class="np" data-comm-open="inbox"><i class="fa-solid fa-comments"></i> Communication</button>
<button type="button" class="np" data-section="profile" style="margin-top:.2rem;color:var(--bright)"><i class="fa-solid fa-user-gear" style="color:#38bdf8"></i> My Profile</button>
<?php
require_once __DIR__ . '/../components/sidebar_profile_item.php';
renderSidebarUserCard($fullName, 'School Admin', $todayFormatted, $initials, 'linear-gradient(135deg,#0284c7,#38bdf8)');
?>
<a href="/admin/logout.php" class="np" style="color:var(--bad);margin-top:.2rem"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
</aside>

<main>
<?php if($isImpersonating):?>
<div class="imp-bar"><span><i class="fa-solid fa-mask"></i> Viewing as <strong><?=ucwords(str_replace('_',' ',$_SESSION['admin_role']))?></strong> — You are still School Admin</span><button class="btn bs" style="background:var(--warn);color:#000;font-size:.65rem" onclick="restoreRole()"><i class="fa-solid fa-arrow-left"></i> Back to School Admin</button></div>
<?php endif;?>

<!-- HEADER BAR -->
<div class="topbar no-print">
<div class="topbar-left">
<h1 style="font-size:1.4rem;font-weight:700;color:var(--bright);letter-spacing:-.02em;line-height:1.2"><?=$greeting?>, <?=e(explode(' ',$fullName)[0])?></h1>
<p style="color:var(--dim);font-size:.75rem;margin-top:.25rem;display:flex;align-items:center;gap:.5rem"><span class="pulse"></span> <?=$todayFormatted?></p>
</div>
<div class="topbar-right">
<div class="search-box"><i class="fa-solid fa-search"></i><input id="globalSearch" placeholder="Search members, sections..." oninput="globalSearchHandler(this.value)" autocomplete="off"><div class="search-results" id="searchResults"></div></div>
<?php include __DIR__ . '/../components/notification_center.php'; ?><?= renderNotificationCenter() ?>
<button class="icon-btn" onclick="toggleTheme()" title="Toggle theme"><i class="fa-solid fa-sun" id="themeIcon"></i></button>
<button class="icon-btn" onclick="window.print()" title="Print"><i class="fa-solid fa-print"></i></button>
</div>
</div>

<!-- ═══ OVERVIEW ═══ -->
<div id="section-dashboard" class="cs active">
<!-- Stats -->
<div class="sg">
<div class="sc"><div class="ico" style="background:rgba(6,182,212,.1);color:var(--info)"><i class="fa-solid fa-users"></i></div><div class="val"><span class="count-up" data-target="<?=$stats['total']?>">0</span></div><div class="lbl">Total Members</div><?php if($recentRegs>0):?><div class="trend"><i class="fa-solid fa-arrow-up" style="font-size:.45rem"></i> +<?=$recentRegs?> this week</div><?php endif;?></div>
<div class="sc"><div class="ico" style="background:rgba(16,185,129,.1);color:var(--ok)"><i class="fa-solid fa-user-check"></i></div><div class="val"><span class="count-up" data-target="<?=$stats['active']?>">0</span></div><div class="lbl">Active</div><?php if($stats['total']>0):?><div class="trend"><?=round($stats['active']/$stats['total']*100)?>%</div><?php endif;?></div>
<div class="sc"><div class="ico" style="background:rgba(245,158,11,.1);color:var(--warn)"><i class="fa-solid fa-hourglass-half"></i></div><div class="val"><span class="count-up" data-target="<?=$stats['pending']?>">0</span></div><div class="lbl">Pending</div></div>
<div class="sc"><div class="ico" style="background:rgba(239,68,68,.08);color:var(--bad)"><i class="fa-solid fa-triangle-exclamation"></i></div><div class="val"><span class="count-up" data-target="<?=$stats['warning']+$stats['inactive']?>">0</span></div><div class="lbl">Warning+Inactive</div></div>
<div class="sc"><div class="ico" style="background:rgba(139,92,246,.1);color:var(--purple)"><i class="fa-solid fa-layer-group"></i></div><div class="val"><span class="count-up" data-target="<?=$stats['sections']?>">0</span></div><div class="lbl">Sections</div></div>
<div class="sc"><div class="ico" style="background:rgba(236,72,153,.08);color:var(--pink)"><i class="fa-solid fa-chalkboard-teacher"></i></div><div class="val"><span class="count-up" data-target="<?=$stats['teachers']?>">0</span></div><div class="lbl">Teachers</div></div>
</div>

<!-- Quick Actions -->
<div class="cd"><div class="ct"><i class="fa-solid fa-bolt" style="color:var(--warn)"></i> Quick Actions</div>
<div class="qa-grid">
<a class="qa-btn" onclick="nav('members')"><i class="fa-solid fa-users" style="color:var(--ac)"></i><span>View Members</span></a>
<a class="qa-btn" onclick="document.getElementById('quickAddModal').classList.add('show')"><i class="fa-solid fa-user-plus" style="color:var(--ok)"></i><span>Add Member</span></a>
<a class="qa-btn" onclick="nav('attendance')"><i class="fa-solid fa-clipboard-check" style="color:var(--purple)"></i><span>Attendance</span></a>
<a class="qa-btn" onclick="nav('departments')"><i class="fa-solid fa-building" style="color:var(--warn)"></i><span>Departments</span></a>
<a class="qa-btn" onclick="nav('reports')"><i class="fa-solid fa-chart-line" style="color:var(--pink)"></i><span>Reports</span></a>
<a class="qa-btn" onclick="nav('system')"><i class="fa-solid fa-server" style="color:var(--dim)"></i><span>System</span></a>
</div></div>

<!-- Attendance Row: Today + Weekly Chart -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem" class="two-col">
<div class="cd">
<div class="ct"><i class="fa-solid fa-clipboard-check" style="color:var(--ac)"></i> Today's Attendance</div>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;margin-bottom:.75rem">
<div style="text-align:center;padding:.4rem;border-radius:8px;background:rgba(16,185,129,0.05)"><div style="font-size:1.2rem;font-weight:700;color:var(--ok)"><?=$attToday['present']?></div><div style="font-size:.58rem;color:var(--dim)">Present</div></div>
<div style="text-align:center;padding:.4rem;border-radius:8px;background:rgba(239,68,68,0.04)"><div style="font-size:1.2rem;font-weight:700;color:var(--bad)"><?=$attToday['absent']?></div><div style="font-size:.58rem;color:var(--dim)">Absent</div></div>
<div style="text-align:center;padding:.4rem;border-radius:8px;background:rgba(245,158,11,0.04)"><div style="font-size:1.2rem;font-weight:700;color:var(--warn)"><?=$attToday['late']?></div><div style="font-size:.58rem;color:var(--dim)">Late</div></div>
<div style="text-align:center;padding:.4rem;border-radius:8px;background:rgba(6,182,212,0.04)"><div style="font-size:1.2rem;font-weight:700;color:var(--ac)"><?=$attRate?>%</div><div style="font-size:.58rem;color:var(--dim)">Rate</div></div>
</div>
<div class="progress" style="height:6px"><div class="progress-bar" style="width:<?=$attRate?>%;background:linear-gradient(90deg,var(--ok),var(--ac))"></div></div>
</div>
<div class="cd">
<div class="ct"><i class="fa-solid fa-chart-column" style="color:var(--purple)"></i> Weekly Attendance</div>
<?php $maxAtt=max(array_map(fn($d)=>$d['total'],$weeklyAtt)?:[1]);?>
<div class="chart-bar">
<?php foreach($weeklyAtt as $w):$h=$maxAtt>0?max(2,round($w['present']/$maxAtt*80)):2;?>
<div class="chart-col"><div class="num"><?=$w['present']?></div><div class="bar" style="height:<?=$h?>px;background:linear-gradient(180deg,var(--ac),var(--ok))"></div><div class="day"><?=$w['day']?></div></div>
<?php endforeach;?>
</div>
</div>
</div>

<!-- Gender + Sections -->
<div style="display:grid;grid-template-columns:.8fr 1.2fr;gap:.75rem;margin-bottom:.75rem" class="two-col">
<div class="cd" style="display:flex;flex-direction:column;align-items:center;justify-content:center">
<div class="ct" style="width:100%"><i class="fa-solid fa-venus-mars" style="color:var(--purple)"></i> Gender</div>
<div class="ring-wrap"><svg width="110" height="110" viewBox="0 0 120 120"><circle cx="60" cy="60" r="50" fill="none" stroke="rgba(128,128,128,0.06)" stroke-width="11"/><circle cx="60" cy="60" r="50" fill="none" stroke="var(--info)" stroke-width="11" stroke-dasharray="<?=$mStroke?> 314" stroke-linecap="round"/><circle cx="60" cy="60" r="50" fill="none" stroke="var(--pink)" stroke-width="11" stroke-dasharray="<?=$fStroke?> 314" stroke-dashoffset="-<?=$mStroke?>" stroke-linecap="round"/></svg><div class="ring-center"><div class="rv"><?=$stats['total']?></div><div class="rl">Total</div></div></div>
<div style="display:flex;gap:1rem;margin-top:.6rem;font-size:.65rem"><span style="display:flex;align-items:center;gap:3px"><span style="width:7px;height:7px;border-radius:50%;background:var(--info)"></span> Male <?=$stats['male']?> (<?=$mPct?>%)</span><span style="display:flex;align-items:center;gap:3px"><span style="width:7px;height:7px;border-radius:50%;background:var(--pink)"></span> Female <?=$stats['female']?> (<?=$fPct?>%)</span></div>
</div>
<div class="cd"><div class="ct"><i class="fa-solid fa-chart-bar" style="color:var(--warn)"></i> Sections</div><div id="secBars"></div></div>
</div>

<!-- Recent Members -->
<div class="cd">
<div class="ct"><i class="fa-solid fa-clock-rotate-left" style="color:var(--ok)"></i> Recent Registrations <span style="font-size:.6rem;color:var(--dim);font-weight:400;margin-left:auto">Last 10</span></div>
<?php if(count($recentMembers)>0):?>
<div class="tw"><table><thead><tr><th>Code</th><th>Name</th><th>Father</th><th>Gender</th><th>Section</th><th>Status</th><th>Registered</th></tr></thead><tbody>
<?php foreach($recentMembers as $m):?>
<tr><td><span class="bg bg-info"><?=e($m['member_code']??'')?></span></td><td style="font-weight:600;color:var(--bright)"><?=e($m['student_name']??'')?></td><td><?=e($m['father_name']??'')?></td><td><?=$m['gender']==='male'?'<span style="color:var(--info)">♂</span>':'<span style="color:var(--pink)">♀</span>'?></td><td><?=e($m['current_section']??'—')?></td><td><span class="bg bg-<?=$m['status']==='active'?'ok':($m['status']==='warning'?'w':'bad')?>"><?=e($m['status'])?></span></td><td style="font-size:.65rem;color:var(--dim)"><?=date('M j',strtotime($m['created_at']))?></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php else:?><p style="font-size:.75rem;color:var(--dim);text-align:center;padding:1rem">No recent registrations</p><?php endif;?>
</div>
</div>

<!-- ═══ MEMBERS ═══ -->
<div id="section-members" class="cs">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem;flex-wrap:wrap;gap:.5rem">
<h2 style="font-size:1.1rem;font-weight:700;color:var(--bright)"><i class="fa-solid fa-users" style="color:var(--ac)"></i> Members Browser</h2>
<div style="display:flex;gap:.35rem;flex-wrap:wrap" class="no-print"><button class="btn bo bs" onclick="exportData('excel')" title="Export the visible page"><i class="fa-solid fa-file-excel" style="color:var(--ok)"></i> Page Excel</button><button class="btn bo bs" onclick="exportData('pdf')" title="Export the visible page"><i class="fa-solid fa-file-pdf" style="color:var(--bad)"></i> Page PDF</button><button class="btn bo bs" onclick="exportData('word')" title="Export the visible page"><i class="fa-solid fa-file-word" style="color:var(--info)"></i> Page Word</button><a class="btn bo bs" href="/admin/api_export_members.php?tier=all&amp;format=csv"><i class="fa-solid fa-download"></i> Complete CSV</a></div>
</div>
<div class="cd no-print"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem"><div class="ct" style="margin-bottom:0"><i class="fa-solid fa-filter" style="color:var(--ac)"></i> Filters</div><button class="btn bs bo" onclick="resetFilters()"><i class="fa-solid fa-rotate-left"></i> Reset</button></div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:.55rem">
<div><label class="flbl">Search</label><input autocomplete="off" type="text" id="fSearch" class="inp" style="width:100%" placeholder="Name, code, phone..." oninput="applyFilters()"></div>
<div><label class="flbl">Status</label><select id="fStatus" class="inp" style="width:100%" onchange="applyFilters()"><option value="">All</option><option value="active">Active</option><option value="warning">Warning</option><option value="inactive">Inactive</option></select></div>
<div><label class="flbl">Gender</label><select id="fGender" class="inp" style="width:100%" onchange="applyFilters()"><option value="">All</option><option value="male">Male</option><option value="female">Female</option></select></div>
<div><label class="flbl">Section</label><select id="fSection" class="inp" style="width:100%" onchange="applyFilters()"><option value="">All</option></select></div>
<div><label class="flbl">Reg Type</label><select id="fRegType" class="inp" style="width:100%" onchange="applyFilters()"><option value="">All</option><option value="waiting">Waiting</option><option value="transfer">Transfer</option><option value="direct">Direct</option></select></div>
<div><label class="flbl">Member Type</label><select id="fMemberType" class="inp" style="width:100%" onchange="applyFilters()"><option value="">All</option><option value="regular">Regular</option><option value="special_regular">Special Regular</option><option value="honorary">Honorary</option></select></div>
<div><label class="flbl">Age Group</label><select id="fAgeGroup" class="inp" style="width:100%" onchange="applyFilters()"><option value="">All</option><?php foreach (\App\Services\MemberCategory::sections() as $saCode => $saSec): ?><option value="<?= $saCode ?>"><?= e($saSec['am']) ?> (<?= e($saSec['letter']) ?>)</option><?php endforeach; ?></select></div>
<div><label class="flbl">City</label><input autocomplete="off" type="text" id="fCity" class="inp" style="width:100%" placeholder="City..." oninput="applyFilters()"></div>
</div></div>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;gap:.3rem"><div id="resultCount" style="font-size:.72rem;color:var(--dim)">Loading...</div><div style="display:flex;align-items:center;gap:.4rem"><label style="font-size:.6rem;color:var(--dim)">Per page:</label><select id="perPage" class="inp bs" onchange="saveMemberPerPage();applyFilters()" style="width:auto" aria-label="Rows per page"><option value="25">25</option><option value="50" selected>50</option><option value="100">100</option><option value="200">200</option></select></div></div>
<div class="tw"><table id="mTbl"><thead><tr><th data-col="member_code" onclick="sortBy('member_code')">Code</th><th data-col="student_name" onclick="sortBy('student_name')">Name</th><th data-col="father_name" onclick="sortBy('father_name')">Father</th><th data-col="gender" onclick="sortBy('gender')">Gen</th><th data-col="age_group" onclick="sortBy('age_group')">Age</th><th data-col="current_section" onclick="sortBy('current_section')">Section</th><th data-col="status" onclick="sortBy('status')">Status</th><th data-col="registration_type" onclick="sortBy('registration_type')">Reg</th><th data-col="phone_number" onclick="sortBy('phone_number')">Phone</th><th data-col="city" onclick="sortBy('city')">City</th><th data-col="created_at" onclick="sortBy('created_at')">Date</th><th>⋯</th></tr></thead><tbody id="mBody"></tbody></table></div>
<div id="pag" style="display:flex;justify-content:center;gap:.35rem;margin-top:.75rem;flex-wrap:wrap"></div>
</div>

<!-- ═══ ATTENDANCE ═══ -->
<div id="section-attendance" class="cs">
<h2 style="font-size:1.1rem;font-weight:700;color:var(--bright);margin-bottom:.85rem"><i class="fa-solid fa-clipboard-check" style="color:var(--ac)"></i> Attendance Reports</h2>
<div class="cd no-print"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.55rem">
<div><label class="flbl">From</label><input type="date" id="attFrom" class="inp" style="width:100%"></div>
<div><label class="flbl">To</label><input type="date" id="attTo" class="inp" style="width:100%"></div>
<div><label class="flbl">Section</label><select id="attSection" class="inp" style="width:100%"><option value="">All</option></select></div>
<div style="display:flex;align-items:flex-end"><button class="btn bp" onclick="loadAttReport()" style="width:100%"><i class="fa-solid fa-search"></i> Generate</button></div>
</div></div>
<div class="sg" id="attStats"></div>
<div class="cd" id="attCard" style="display:none"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem"><div class="ct" style="margin-bottom:0">Details</div><button class="btn bo bs" onclick="exportAtt()"><i class="fa-solid fa-download"></i> Export</button></div><div class="tw"><table><thead><tr><th>Name</th><th>Section</th><th>Present</th><th>Absent</th><th>Late</th><th>Rate</th></tr></thead><tbody id="attBody"></tbody></table></div></div>

<!-- Attendance policy (Attendance alerting — Phase 1). School Admin edits; Super Admin reads. -->
<div class="cd no-print" id="attPolicyCard">
<div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem">
<div class="ct" style="margin-bottom:0"><i class="fa-solid fa-sliders" style="color:var(--ac)"></i> Attendance policy (per department)</div>
<span id="attPolicyState" style="font-size:.7rem;color:var(--muted,#94a3b8)"></span>
</div>
<p style="font-size:.72rem;color:var(--muted,#94a3b8);margin-bottom:.6rem">Consecutive-absence stages (standard → prolonged → critical), the low-attendance threshold and the minimum number of sessions before a rate counts. Warnings go to the department, HR and Information; the member's global status is never changed automatically.</p>
<div id="attPolicyGrid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:.7rem"></div>
</div>
</div>

<!-- ═══ CLASSES ═══ -->
<div id="section-classes" class="cs">
<h2 style="font-size:1.1rem;font-weight:700;color:var(--bright);margin-bottom:.85rem"><i class="fa-solid fa-chalkboard" style="color:var(--ac)"></i> Classes & Enrollment</h2>
<div id="classesContent"><div class="cd" style="text-align:center;padding:2rem;color:var(--dim)"><i class="fa-solid fa-spinner fa-spin" style="color:var(--ac)"></i> Loading...</div></div>
</div>

<!-- ═══ ACADEMIC YEAR ═══ -->
<div id="section-academicyear" class="cs">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem;flex-wrap:wrap;gap:.5rem"><div><h2 style="font-size:1.1rem;font-weight:700;color:var(--bright)"><i class="fa-solid fa-calendar-days" style="color:var(--purple)"></i> Academic Year & Semesters</h2><p style="font-size:.72rem;color:var(--dim)">Create the school year, set the active one, and manage semesters. Only one year is <b>Active</b> at a time.</p></div><div style="display:flex;gap:.4rem;flex-wrap:wrap"><button class="btn bo bs" onclick="toggleYearHelp()" title="Explain what each button here does"><i class="fa-solid fa-circle-question"></i> Help</button><button class="btn bo bs" onclick="openRolloverModal()" title="Start the next school year and move students forward in one safe step"><i class="fa-solid fa-rotate"></i> Year Rollover</button><a class="btn bo bs" href="/admin/school_calendar.php" title="Yearly calendar: holidays and Day Off days (attendance is taken only on class days)"><i class="fa-solid fa-calendar-check"></i> School Calendar</a><button class="btn bp bs" onclick="openYearModal()"><i class="fa-solid fa-plus"></i> Add Year</button></div></div>
<div id="rolloverHint" style="display:none;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.35);border-radius:10px;padding:.7rem .9rem;margin-bottom:.85rem;font-size:.76rem;align-items:center;gap:.7rem;flex-wrap:wrap"><i class="fa-solid fa-calendar-check" style="color:var(--warn)"></i><span id="rolloverHintText" style="color:var(--text);flex:1;min-width:200px"></span><button class="btn bp bs" onclick="openRolloverModal()"><i class="fa-solid fa-rotate"></i> Run Year Rollover</button><button class="btn bo bs" onclick="document.getElementById('rolloverHint').style.display='none'" title="Hide until the page is opened again">Dismiss</button></div>
<div id="yearHelp" style="display:none;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.25);border-radius:10px;padding:.85rem 1rem;margin-bottom:.85rem;font-size:.76rem;line-height:1.5">
<div style="font-weight:700;color:var(--bright);margin-bottom:.4rem"><i class="fa-solid fa-circle-info" style="color:#60a5fa"></i> What each control does</div>
<ul style="margin:0;padding-left:1.1rem;color:var(--dim)">
<li><b>Add Year</b> — creates the next school year. It starts as <b>Upcoming</b> (not live). The very first year you ever create becomes Active automatically. <i>Reversible: an empty Upcoming year can be deleted.</i></li>
<li><b>Set Active</b> (✓ on an Upcoming year) — makes that year the live one and <b>closes</b> the current year. You must type <b>SWITCH</b> to confirm. <i>Changes what everyone sees; reversible only by switching again.</i></li>
<li><b>Reopen</b> (↺ on a Closed year) — makes a past year live again (rare, for fixing mistakes). You must type <b>REOPEN</b>. <i>New records then belong to that reopened year.</i></li>
<li><b>Year Rollover</b> — the normal way to start a new year: it closes the current year and carries every student into the new year (same class, or promoted one level) in one safe, all-or-nothing step. You must type <b>ROLLOVER</b>. <i>Not auto-reversible — take a backup first.</i></li>
<li><b>Delete</b> (🗑 on an Upcoming year) — removes an empty future year. <i>Only works if it has no students/records. Active and Closed years cannot be deleted.</i></li>
<li><b>View another year</b> (top bar) — opens a past year <b>read-only</b> to look at old data. A loud amber banner shows you are in the past; <b>Return to Current Year</b> brings you back in one click. <i>Never changes any data.</i></li>
</ul></div>
<div class="cd"><div class="tw"><table><thead><tr><th>Year Name</th><th>EC</th><th>GC</th><th>Start</th><th>End</th><th>Semesters</th><th>Status</th><th>Actions</th></tr></thead><tbody id="yearBody"><tr><td colspan="8" style="text-align:center;padding:1.25rem;color:var(--dim)"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</td></tr></tbody></table></div></div>
<div id="termArea" style="margin-top:.75rem"></div>
</div>

<!-- ═══ DEPARTMENTS ═══ -->
<div id="section-departments" class="cs">
<h2 style="font-size:1.1rem;font-weight:700;color:var(--bright);margin-bottom:.85rem"><i class="fa-solid fa-building" style="color:var(--ac)"></i> Departments Overview</h2>
<div class="dept-grid">
<div class="dept-card"><div class="dept-header"><div class="dept-icon" style="background:rgba(236,72,153,.1);color:var(--pink)"><i class="fa-solid fa-users-gear"></i></div><div><div class="dept-name">HR Department</div><div class="dept-role">hr_dept</div></div></div><div class="dept-stats"><div class="dept-stat"><div class="ds-val"><?=$stats['total']?></div><div class="ds-lbl">Members</div></div><div class="dept-stat"><div class="ds-val">Manage</div><div class="ds-lbl">Registration</div></div></div><button class="dept-switch" onclick="switchRole('hr_dept')"><i class="fa-solid fa-arrow-right-to-bracket"></i> Open as HR Dept</button></div>

<div class="dept-card"><div class="dept-header"><div class="dept-icon" style="background:rgba(6,182,212,.1);color:var(--info)"><i class="fa-solid fa-info-circle"></i></div><div><div class="dept-name">Information Dept</div><div class="dept-role">info_dept</div></div></div><div class="dept-stats"><div class="dept-stat"><div class="ds-val"><?=$stats['total']?></div><div class="ds-lbl">Members</div></div><div class="dept-stat"><div class="ds-val"><?=$stats['sections']?></div><div class="ds-lbl">Sections</div></div></div><button class="dept-switch" onclick="switchRole('info_dept')"><i class="fa-solid fa-arrow-right-to-bracket"></i> Open as Info Dept</button></div>

<div class="dept-card"><div class="dept-header"><div class="dept-icon" style="background:rgba(139,92,246,.1);color:var(--purple)"><i class="fa-solid fa-graduation-cap"></i></div><div><div class="dept-name">Education Dept</div><div class="dept-role">edu_dept</div></div></div><div class="dept-stats"><div class="dept-stat"><div class="ds-val"><?=$deptStats['edu_classes']?></div><div class="ds-lbl">Classes</div></div><div class="dept-stat"><div class="ds-val"><?=$deptStats['edu_enrolled']?></div><div class="ds-lbl">Enrolled</div></div></div><button class="dept-switch" onclick="switchRole('edu_dept')"><i class="fa-solid fa-arrow-right-to-bracket"></i> Open as Edu Dept</button></div>

<div class="dept-card"><div class="dept-header"><div class="dept-icon" style="background:rgba(16,185,129,.1);color:var(--ok)"><i class="fa-solid fa-coins"></i></div><div><div class="dept-name">Finance Dept</div><div class="dept-role">finance_dept</div></div></div><div class="dept-stats"><div class="dept-stat"><div class="ds-val"><?=number_format($deptStats['finance_income'],0)?></div><div class="ds-lbl">Year Income</div></div><div class="dept-stat"><div class="ds-val"><?=$deptStats['finance_txn_month']?></div><div class="ds-lbl">Month Txns</div></div></div><button class="dept-switch" onclick="switchRole('finance_dept')"><i class="fa-solid fa-arrow-right-to-bracket"></i> Open as Finance</button></div>

<div class="dept-card"><div class="dept-header"><div class="dept-icon" style="background:rgba(245,158,11,.1);color:var(--warn)"><i class="fa-solid fa-boxes-stacked"></i></div><div><div class="dept-name">Material Dept</div><div class="dept-role">material_dept</div></div></div><div class="dept-stats"><div class="dept-stat"><div class="ds-val"><?=$deptStats['material_items']?></div><div class="ds-lbl">Items</div></div><div class="dept-stat"><div class="ds-val"><?=$deptStats['material_pending']?></div><div class="ds-lbl">Pending Req</div></div></div><button class="dept-switch" onclick="switchRole('material_dept')"><i class="fa-solid fa-arrow-right-to-bracket"></i> Open as Material</button></div>
<div class="dept-card"><div class="dept-header"><div class="dept-icon" style="background:rgba(6,182,212,.1);color:#06b6d4"><i class="fa-solid fa-music"></i></div><div><div class="dept-name">Mezmur Dept</div><div class="dept-role">mezmur_dept</div></div></div><div class="dept-stats"><div class="dept-stat"><div class="ds-val"><?=$deptStats['mezmur_hymns']?></div><div class="ds-lbl">Hymns</div></div><div class="dept-stat"><div class="ds-val"><?=$deptStats['mezmur_categories']?></div><div class="ds-lbl">Categories</div></div></div><button class="dept-switch" onclick="switchRole('mezmur_dept')"><i class="fa-solid fa-arrow-right-to-bracket"></i> Open as Mezmur</button></div>

<div class="dept-card"><div class="dept-header"><div class="dept-icon" style="background:rgba(236,72,153,.08);color:var(--pink)"><i class="fa-solid fa-chalkboard-teacher"></i></div><div><div class="dept-name">Teacher Panel</div><div class="dept-role">teacher</div></div></div><div class="dept-stats"><div class="dept-stat"><div class="ds-val"><?=$stats['teachers']?></div><div class="ds-lbl">Teachers</div></div></div><button class="dept-switch" onclick="switchRole('teacher')"><i class="fa-solid fa-arrow-right-to-bracket"></i> Open as Teacher</button></div>

<div class="dept-card"><div class="dept-header"><div class="dept-icon" style="background:rgba(128,128,128,.1);color:var(--dim)"><i class="fa-solid fa-clipboard-user"></i></div><div><div class="dept-name">Attendance Taker</div><div class="dept-role">attendance_taker</div></div></div><div class="dept-stats"><div class="dept-stat"><div class="ds-val"><?=$attToday['total']?></div><div class="ds-lbl">Today</div></div></div><button class="dept-switch" onclick="switchRole('attendance_taker')"><i class="fa-solid fa-arrow-right-to-bracket"></i> Open as Att. Taker</button></div>
</div>
</div>

<!-- ═══ STAFF ═══ -->
<div id="section-staff" class="cs">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem;flex-wrap:wrap;gap:.5rem"><h2 style="font-size:1.1rem;font-weight:700;color:var(--bright)"><i class="fa-solid fa-user-gear" style="color:var(--ac)"></i> Staff & Users</h2><button class="btn bp bs" onclick="openAddUser()"><i class="fa-solid fa-plus"></i> Add User</button></div>
<div class="cd"><div class="tw"><table><thead><tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead><tbody id="usersBody"></tbody></table></div></div>
</div>

<!-- ═══ REPORTS ═══ -->
<div id="section-reports" class="cs">
<h2 style="font-size:1.1rem;font-weight:700;color:var(--bright);margin-bottom:.85rem"><i class="fa-solid fa-chart-line" style="color:var(--ac)"></i> Reports & Analytics</h2>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem" class="two-col">
<div class="cd"><div class="ct"><i class="fa-solid fa-download" style="color:var(--info)"></i> Quick Export</div><p style="font-size:.72rem;color:var(--dim);margin-bottom:.75rem">Stream the complete member roster without loading it into this page.</p><div style="display:flex;flex-wrap:wrap;gap:.35rem"><a class="btn bo bs" href="/admin/api_export_members.php?tier=all&amp;format=csv"><i class="fa-solid fa-file-csv" style="color:var(--ok)"></i> Complete CSV</a></div></div>
<div class="cd"><div class="ct"><i class="fa-solid fa-wand-magic-sparkles" style="color:var(--purple)"></i> Custom Report</div><div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-bottom:.6rem"><div><label class="flbl">Type</label><select id="crType" class="inp" style="width:100%"><option value="sections">Sections</option><option value="gender">Gender</option><option value="registration">Registration</option></select></div><div><label class="flbl">Format</label><select id="crFmt" class="inp" style="width:100%"><option value="pdf">PDF</option><option value="excel">Excel</option><option value="word">Word</option></select></div></div><button class="btn bp bs" onclick="genCustomReport()"><i class="fa-solid fa-download"></i> Generate</button></div>
</div>
<div class="cd"><div class="ct"><i class="fa-solid fa-chart-bar" style="color:var(--warn)"></i> Membership Analysis</div><div id="membershipAnalysis" style="font-size:.78rem;color:var(--dim)">Load Members first.</div></div>
</div>

<!-- ═══ SYSTEM HEALTH ═══ -->
<div id="section-system" class="cs">
<h2 style="font-size:1.1rem;font-weight:700;color:var(--bright);margin-bottom:.85rem"><i class="fa-solid fa-server" style="color:var(--ac)"></i> System Health</h2>
<div class="sg">
<div class="sc"><div class="ico" style="background:rgba(16,185,129,.1);color:var(--ok)"><i class="fa-solid fa-database"></i></div><div class="val" style="font-size:1.2rem"><?=$dbSize?> MB</div><div class="lbl">Database Size</div></div>
<div class="sc"><div class="ico" style="background:rgba(6,182,212,.1);color:var(--info)"><i class="fa-solid fa-table"></i></div><div class="val" style="font-size:1.2rem"><?=$tableCount?></div><div class="lbl">Tables</div></div>
<div class="sc"><div class="ico" style="background:rgba(16,185,129,.1);color:var(--ok)"><i class="fa-solid fa-circle-check"></i></div><div class="val" style="font-size:1rem"><span class="health-dot health-ok"></span></div><div class="lbl">DB Connection</div></div>
<div class="sc"><div class="ico" style="background:rgba(139,92,246,.1);color:var(--purple)"><i class="fa-solid fa-users-gear"></i></div><div class="val" style="font-size:1.2rem"><?=$stats['users']?></div><div class="lbl">Active Users</div></div>
</div>
<div class="cd"><div class="ct"><i class="fa-solid fa-shield-halved" style="color:var(--ok)"></i> Security Status</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;font-size:.72rem" class="two-col">
<div style="padding:.4rem;display:flex;align-items:center;gap:.4rem"><span class="health-dot health-ok"></span> CSRF Protection Active</div>
<div style="padding:.4rem;display:flex;align-items:center;gap:.4rem"><span class="health-dot health-ok"></span> Session Security Active</div>
<div style="padding:.4rem;display:flex;align-items:center;gap:.4rem"><span class="health-dot health-ok"></span> SQL Injection Protection</div>
<div style="padding:.4rem;display:flex;align-items:center;gap:.4rem"><span class="health-dot health-ok"></span> Rate Limiting Active</div>
<div style="padding:.4rem;display:flex;align-items:center;gap:.4rem"><span class="health-dot health-ok"></span> XSS Protection Active</div>
<div style="padding:.4rem;display:flex;align-items:center;gap:.4rem"><span class="health-dot health-ok"></span> Password Hashing (bcrypt)</div>
</div></div>
</div>

<!-- ═══ PROFILE TAB ═══ -->
<?php
require_once __DIR__ . '/../components/profile_tab_section.php';
renderProfileTabSection('section-profile', 'cs');
?>

</main>

<!-- MODALS -->
<div class="mo" id="yearModal"><div class="md" style="max-width:560px">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem"><h3 id="yearModalTitle" style="margin:0"><i class="fa-solid fa-calendar" style="color:var(--purple)"></i> Academic Year</h3><button onclick="document.getElementById('yearModal').classList.remove('show')" style="background:none;border:none;color:var(--dim);font-size:1.1rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button></div>
<div style="display:flex;flex-direction:column;gap:.55rem">
<input type="hidden" id="yearFormId" value="0">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem">
<div><label class="flbl">Year Name *</label><input id="yearName" class="inp amharic" style="width:100%" placeholder="e.g. 2018 ዓ.ም."></div>
<div><label class="flbl">EC Year (Ethiopian) *</label><input type="number" id="yearEc" class="inp" style="width:100%" oninput="syncGcFromEc()"></div>
</div>
<div><label class="flbl">GC Year <span style="color:var(--dim);font-weight:400">(auto — reference only)</span></label><input id="yearGc" class="inp" style="width:100%;opacity:.65;cursor:not-allowed" readonly tabindex="-1" placeholder="auto from EC year"></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem">
<div><label class="flbl">Start Date</label><input type="date" id="yearStart" class="inp" style="width:100%"></div>
<div><label class="flbl">End Date</label><input type="date" id="yearEnd" class="inp" style="width:100%"></div>
</div>
<div style="border-top:1px solid var(--bd);padding-top:.55rem;margin-top:.15rem">
<label class="flbl" style="display:block;margin-bottom:.35rem"><i class="fa-solid fa-scale-balanced" style="color:var(--purple)"></i> Semester Weights for the Annual Result</label>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem">
<div><label class="flbl" style="font-weight:400">Semester 1 (%)</label><input type="number" id="yearS1Weight" class="inp" style="width:100%" min="0" max="100" step="0.01" value="50" oninput="updateWeightTotal()"></div>
<div><label class="flbl" style="font-weight:400">Semester 2 (%)</label><input type="number" id="yearS2Weight" class="inp" style="width:100%" min="0" max="100" step="0.01" value="50" oninput="updateWeightTotal()"></div>
</div>
<div id="yearWeightMsg" style="font-size:.68rem;margin-top:.35rem"></div>
<div style="font-size:.68rem;color:var(--dim);margin-top:.3rem">Used only for subjects marked <b>Full Year</b>, which combine both semesters into one annual result. Subjects marked <b>Semester Only</b> are unaffected. The two values must total exactly 100%. Each year keeps its own weights, so past years stay reproducible.</div>
</div>
<div style="font-size:.68rem;color:var(--dim);background:rgba(59,130,246,.08);border-left:3px solid #60a5fa;padding:.45rem .6rem;border-radius:4px">A new year is created as <b>Upcoming</b> and two semesters are added automatically. It stays inactive until you use the <b>Set Active</b> (✓) button on the year list — that safely closes the current year and switches over. <b>The very first year</b> you create becomes active automatically. Start/end dates are optional but when set they must be valid, and semester dates must fall inside them.</div>
<button class="btn bp" onclick="saveYear()"><i class="fa-solid fa-save"></i> Save Academic Year</button>
</div></div></div>

<!-- YEAR ROLLOVER MODAL (styled, in-dashboard) -->
<div class="mo" id="rolloverModal"><div class="md" style="max-width:560px">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem"><h3 style="margin:0"><i class="fa-solid fa-rotate" style="color:var(--purple)"></i> Year Rollover</h3><button onclick="document.getElementById('rolloverModal').classList.remove('show')" style="background:none;border:none;color:var(--dim);font-size:1.1rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button></div>
<div style="display:flex;flex-direction:column;gap:.6rem">
<div style="font-size:.72rem;background:rgba(245,158,11,.1);border-left:3px solid #f59e0b;color:var(--dim);padding:.5rem .65rem;border-radius:4px"><b>Take a backup first.</b> This closes the current year and moves every active student into the new year in one all-or-nothing step. If the new year doesn't exist yet, close this and use <b>Add Year</b> first.</div>
<div><label class="flbl">Year to CLOSE <span style="color:var(--dim);font-weight:400">(students move OUT of this year)</span></label><select id="roOld" class="inp" style="width:100%" onchange="updateRolloverPreview()"></select></div>
<div><label class="flbl">New year to OPEN <span style="color:var(--dim);font-weight:400">(becomes the Active year)</span></label><select id="roNew" class="inp" style="width:100%"></select></div>
<div><label class="flbl">How should students move?</label>
<label style="display:flex;gap:.45rem;align-items:flex-start;font-size:.76rem;margin:.25rem 0;cursor:pointer"><input type="radio" name="roMode" value="carry_forward" checked style="margin-top:.15rem"><span><b>Carry forward</b> (recommended) — everyone stays in the same class, in the new year. Promote individuals later.</span></label>
<label style="display:flex;gap:.45rem;align-items:flex-start;font-size:.76rem;cursor:pointer"><input type="radio" name="roMode" value="promote" style="margin-top:.15rem"><span><b>Promote one level</b> — everyone moves up one class level; the top class is marked graduated. Use only if each level has exactly one class.</span></label>
</div>
<div id="roPreview" style="font-size:.75rem;color:var(--dim);background:rgba(148,163,184,.08);border-radius:6px;padding:.45rem .6rem">Loading…</div>
<div style="font-size:.74rem;color:var(--dim)"><i class="fa-solid fa-shield-halved"></i> After you press Run, a 3-step confirmation explains what will happen and asks you to type <code>ROLLOVER</code>.</div>
<button class="btn bp" id="roRunBtn" onclick="runRollover()"><i class="fa-solid fa-rotate"></i> Run Year Rollover</button>
</div>
</div></div>

<div class="mo" id="memberModal"><div class="md" style="max-width:680px">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
<h3 style="margin:0"><i class="fa-solid fa-id-card" style="color:var(--ac)"></i> Member Profile</h3>
<div style="display:flex;align-items:center;gap:.3rem">
<button class="btn bs bo" onclick="exportMemberProfile('pdf')" title="Export PDF"><i class="fa-solid fa-file-pdf" style="color:var(--bad)"></i> PDF</button>
<button class="btn bs bo" onclick="exportMemberProfile('word')" title="Export Word"><i class="fa-solid fa-file-word" style="color:var(--info)"></i> Word</button>
<button onclick="document.getElementById('memberModal').classList.remove('show')" style="background:none;border:none;color:var(--dim);font-size:1.1rem;cursor:pointer;margin-left:.3rem"><i class="fa-solid fa-xmark"></i></button>
</div></div>
<div id="memberDetail"></div>
</div></div>
<div class="mo" id="addUserModal"><div class="md"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem"><h3 style="margin:0"><i class="fa-solid fa-user-plus" style="color:var(--ok)"></i> Add User</h3><button onclick="document.getElementById('addUserModal').classList.remove('show')" style="background:none;border:none;color:var(--dim);font-size:1.1rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button></div><div style="display:flex;flex-direction:column;gap:.55rem"><div><label class="flbl">Full Name</label><input id="nuName" class="inp" style="width:100%" autocomplete="off"></div><div><label class="flbl">Username</label><input id="nuUser" class="inp" style="width:100%" autocomplete="off"></div><div><label class="flbl">Password</label><input id="nuPass" type="password" class="inp" style="width:100%" minlength="12" maxlength="72" autocomplete="new-password"></div><div><label class="flbl">Role</label><select id="nuRole" class="inp" style="width:100%"><option value="school_admin">School Admin</option><option value="hr_dept">HR Dept</option><option value="info_dept">Info Dept</option><option value="edu_dept">Edu Dept</option><option value="finance_dept">Finance Dept</option><option value="material_dept">Material Dept</option><option value="mezmur_dept">Mezmur Dept</option><option value="teacher">Teacher</option><option value="attendance_taker">Attendance Taker</option></select></div><button class="btn bp" onclick="saveNewUser()"><i class="fa-solid fa-save"></i> Create</button></div></div></div>

<!-- Quick Add Member Modal -->
<div class="mo" id="quickAddModal"><div class="md"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem"><h3 style="margin:0"><i class="fa-solid fa-user-plus" style="color:var(--ac)"></i> Quick Add Member</h3><button onclick="document.getElementById('quickAddModal').classList.remove('show')" style="background:none;border:none;color:var(--dim);font-size:1.1rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button></div><div style="display:flex;flex-direction:column;gap:.55rem"><div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem"><div><label class="flbl">Student Name *</label><input id="qmName" class="inp" style="width:100%"></div><div><label class="flbl">Father Name *</label><input id="qmFather" class="inp" style="width:100%"></div></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem"><div><label class="flbl">Gender *</label><select id="qmGender" class="inp" style="width:100%"><option value="">Select</option><option value="male">Male</option><option value="female">Female</option></select></div><div><label class="flbl">Phone</label><input id="qmPhone" class="inp" style="width:100%"></div></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem"><div><label class="flbl">Section</label><select id="qmSection" class="inp" style="width:100%"><option value="">Select</option></select></div><div><label class="flbl">Registration Type</label><select id="qmRegType" class="inp" style="width:100%"><option value="waiting">Waiting</option><option value="direct">Direct</option><option value="transfer">Transfer</option></select></div></div><button id="quickAddButton" type="button" class="btn bp" onclick="quickAddMember()"><i class="fa-solid fa-save"></i> Add Member</button></div></div></div>

<!-- BOTTOM NAV -->
<?php
$navItems = [
    [
        ['icon' => 'fa-solid fa-gauge-high', 'label' => 'Home', 'attrs' => 'data-section="dashboard"', 'active' => true],
        ['icon' => 'fa-solid fa-users', 'label' => 'Members', 'attrs' => 'data-section="members"'],
        ['icon' => 'fa-solid fa-school', 'label' => 'Classes', 'attrs' => 'data-section="classes"'],
        ['icon' => 'fa-solid fa-clipboard-check', 'label' => 'Attend', 'attrs' => 'data-section="attendance"'],
    ],
    [
        ['icon' => 'fa-solid fa-building', 'label' => 'Depts', 'attrs' => 'data-section="departments"'],
        ['icon' => 'fa-solid fa-user-tie', 'label' => 'Staff', 'attrs' => 'data-section="staff"'],
        ['icon' => 'fa-solid fa-chart-line', 'label' => 'Reports', 'attrs' => 'data-section="reports"'],
        ['icon' => 'fa-solid fa-gear', 'label' => 'System', 'attrs' => 'data-section="system"'],
        ['icon' => 'fa-solid fa-comments', 'label' => 'Comms', 'attrs' => 'data-comm-open="inbox"'],
    ],
    [
        ['icon' => 'fa-solid fa-user-gear', 'label' => 'Profile', 'attrs' => 'data-section="profile"'],
        ['icon' => 'fa-solid fa-right-from-bracket', 'label' => 'Exit', 'href' => '/admin/logout.php', 'exit' => true],
    ],
];
require __DIR__ . '/../components/bottom_nav.php';
?>
<?php include __DIR__ . '/../components/comm/comm_section.php'; ?>
<script>
(function(){
    const sc=document.getElementById('bnScroll'),sl=document.getElementById('bnScrollL'),sr=document.getElementById('bnScrollR');
    if(!sc)return;
    function upd(){sl.classList.toggle('visible',sc.scrollLeft>10);sr.classList.toggle('visible',sc.scrollLeft<sc.scrollWidth-sc.clientWidth-10);}
    sc.addEventListener('scroll',upd,{passive:true});setTimeout(upd,100);
    sc.querySelectorAll('.wbws-bnav-btn[data-section]').forEach(b=>{
        b.addEventListener('click',function(){
            const s=this.dataset.section;
            if(typeof nav==='function')nav(s);
            sc.querySelectorAll('.wbws-bnav-btn').forEach(x=>x.classList.remove('active'));
            this.classList.add('active');
        });
    });
})();
</script>
<div class="toast" id="toast"></div>

<script>
const CSRF='<?=$csrfToken?>';
let allMembers=[],filteredMembers=[],curPage=1,sortCol='id',sortDir='desc';
let memberFirstPage=1;
// Phase 3 paging: per-page choice remembered; Load more appends the next page.
function memberPerPage(){return Math.min(200,Math.max(1,parseInt(document.getElementById('perPage')?.value||50,10)));}
function saveMemberPerPage(){try{localStorage.setItem('fkss_pp_school_members',String(memberPerPage()));}catch(_){}}
(function(){try{const v=localStorage.getItem('fkss_pp_school_members');const s=document.getElementById('perPage');if(s&&v&&[...s.options].some(o=>o.value===v))s.value=v;}catch(_){}})();
let memberTotal=0,memberPages=1,memberNextCursor=null,memberCursors={1:null},memberFilterTimer=null,memberOptionsLoaded=false,globalSearchMembers=[];
const secDist=<?=json_encode($sectionDist, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>;
const memberStats=<?=json_encode($stats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>;
const usersData=<?=json_encode($usersList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>;

// ANIMATED COUNTERS
function animateCounters(){document.querySelectorAll('.count-up').forEach(el=>{const target=parseInt(el.dataset.target)||0;if(!target){el.textContent='0';return;}const dur=800,start=performance.now();const step=ts=>{const p=Math.min((ts-start)/dur,1);el.textContent=Math.floor(p*target).toLocaleString();if(p<1)requestAnimationFrame(step);else el.textContent=target.toLocaleString();};requestAnimationFrame(step);});}

// THEME TOGGLE
function toggleTheme(){const h=document.documentElement,c=h.getAttribute('data-theme'),n=c==='dark'?'light':'dark';h.setAttribute('data-theme',n);localStorage.setItem('wbws-theme',n);document.getElementById('themeIcon').className=n==='dark'?'fa-solid fa-sun':'fa-solid fa-moon';}
(function(){const s=localStorage.getItem('wbws-theme');if(s){document.documentElement.setAttribute('data-theme',s);if(s==='light')document.getElementById('themeIcon').className='fa-solid fa-moon';}})();

// NAVIGATION
function nav(name){document.querySelectorAll('.cs').forEach(s=>s.classList.remove('active'));const t=document.getElementById('section-'+name);if(t)t.classList.add('active');document.querySelectorAll('aside .np').forEach(b=>b.classList.remove('active'));document.querySelectorAll('aside [data-section="'+name+'"]').forEach(b=>b.classList.add('active'));document.querySelectorAll('.bn button').forEach(b=>b.classList.remove('active'));document.querySelectorAll('.bn [data-section="'+name+'"]').forEach(b=>b.classList.add('active'));if(name==='members'&&!allMembers.length)loadMembers();if(name==='classes')loadClasses();if(name==='academicyear')loadYears();if(name==='staff')renderUsers();if(name==='reports')loadAnalysis();if(name==='profile'&&typeof window.loadTabProfile==='function')window.loadTabProfile();const _u=new URL(window.location);_u.searchParams.set('section',name);history.replaceState(null,'',_u);}
window.nav = nav;
window.switchSection = nav;
window.navigateToSection = nav;
window.showSection = nav;
window.switchTab = nav;
document.querySelectorAll('[data-section]').forEach(el=>{el.addEventListener('click',function(e){e.preventDefault();const n=this.getAttribute('data-section');if(n)nav(n);});});

// GLOBAL SEARCH
let searchTimer;
function globalSearchHandler(q){clearTimeout(searchTimer);const box=document.getElementById('searchResults');q=q.trim();if(q.length<2){box.classList.remove('show');return;}searchTimer=setTimeout(async()=>{try{const params=new URLSearchParams({q:q,limit:'8',page:'1'});const r=await fetch('/admin/api_list_members.php?'+params.toString(),{credentials:'same-origin',headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok||d.status!=='success')throw new Error(d.message||'Search failed');globalSearchMembers=d.members||[];showSearchResults(globalSearchMembers);}catch(_){globalSearchMembers=[];showSearchResults([]);}},250);}
function showSearchResults(matches){const box=document.getElementById('searchResults');if(!matches.length){box.innerHTML='<div class="sr-item" style="color:var(--dim)">No results</div>';box.classList.add('show');return;}box.innerHTML=matches.map(m=>`<div class="sr-item" onclick="viewMember(${parseInt(m.id,10)});document.getElementById('searchResults').classList.remove('show');document.getElementById('globalSearch').value='';"><span class="bg bg-info" style="font-size:.55rem">${esc(m.member_code||'')}</span><span style="font-weight:500;color:var(--bright)">${esc(m.student_name)}</span><span style="color:var(--dim);font-size:.62rem">${esc(m.current_section||'')}</span></div>`).join('');box.classList.add('show');}
document.addEventListener('click',e=>{if(!e.target.closest('.search-box'))document.getElementById('searchResults').classList.remove('show');});

// NOTIFICATIONS
/* P72: old inline notification bell removed — shared Notification Center in the topbar */
/* P72: old inline notification bell removed — shared Notification Center in the topbar */
/* P72: old inline notification bell removed — shared Notification Center in the topbar */
/* P72: old inline notification bell removed — shared Notification Center in the topbar */

// IMPERSONATE
// Danger confirmation dialog (admin/js/danger_confirm.js) — owner decisions 2026-10-21.
function dcAsk(o){if(window.DangerConfirm&&window.DangerConfirm.ask)return window.DangerConfirm.ask(o);toast('The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.','e');return Promise.resolve(false);}
async function switchRole(role){const rn=role.replace(/_/g,' ');if(!(await dcAsk({tier:'low',title:'Open the '+rn+' dashboard?',summary:'You will act as '+rn+' until you return. Actions you take there are recorded under your account.',confirmLabel:'Switch dashboard'})))return;try{const fd=new FormData();fd.append('action','switch');fd.append('role',role);fd.append('csrf_token',CSRF);const r=await fetch('/admin/api_impersonate.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();if(d.status==='success'){toast('Switching...','s');setTimeout(()=>window.location.href='/admin/dashboard.php',400);}else toast(d.message||'Failed','e');}catch(e){toast('Network error','e');}}
async function restoreRole(){try{const fd=new FormData();fd.append('action','restore');fd.append('csrf_token',CSRF);const r=await fetch('/admin/api_impersonate.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();if(d.status==='success')window.location.href='/admin/dashboard.php';else toast(d.message||'Failed','e');}catch(e){toast('Failed','e');}}

// SECTION BARS
(function(){const el=document.getElementById('secBars');if(!el||!secDist.length){if(el)el.innerHTML='<p style="font-size:.72rem;color:var(--dim)">No data</p>';return;}const mx=Math.max(...secDist.map(s=>parseInt(s.c)));const clrs=['var(--ac)','var(--ok)','var(--purple)','var(--warn)','var(--pink)','#f97316','#06b6d4','#84cc16'];el.innerHTML=secDist.slice(0,8).map((s,i)=>{const p=mx>0?Math.round(parseInt(s.c)/mx*100):0;return`<div style="margin-bottom:.5rem"><div style="display:flex;justify-content:space-between;font-size:.65rem;margin-bottom:.15rem"><span style="color:var(--text);font-weight:500">${s.s}</span><span style="font-weight:700;color:${clrs[i%8]}">${s.c}</span></div><div class="progress"><div class="progress-bar" style="width:${p}%;background:${clrs[i%8]}"></div></div></div>`;}).join('');})();

// MEMBERS
async function loadMembers(page=1,append=false){
    const rc=document.getElementById('resultCount');
    const pp=memberPerPage();
    const params=new URLSearchParams({page:String(page),limit:String(pp),sort:sortCol,direction:sortDir});
    const filters={fSearch:'q',fStatus:'status',fGender:'gender',fSection:'current_section',fRegType:'registration_type',fMemberType:'member_type',fAgeGroup:'age_group',fCity:'city'};
    Object.entries(filters).forEach(([id,key])=>{const value=document.getElementById(id)?.value.trim()||'';if(value)params.set(key,value);});
    if(!memberOptionsLoaded)params.set('include_options','1');
    if(page>1)params.set('include_total','0');
    if(page>1&&sortCol==='id'&&sortDir==='desc'&&memberCursors[page])params.set('cursor',String(memberCursors[page]));
    if(window.memberListRequest)window.memberListRequest.abort();
    window.memberListRequest=new AbortController();
    try{
        const r=await fetch('/admin/api_list_members.php?'+params.toString(),{credentials:'same-origin',headers:{Accept:'application/json'},signal:window.memberListRequest.signal});
        let d;try{d=await r.json();}catch(_){throw new Error('Server returned an unexpected response (HTTP '+r.status+').');}
        if(!r.ok||d.status!=='success')throw new Error(d.message||'Could not load members');
        const rows=Array.isArray(d.members)?d.members:[];
        allMembers=append?allMembers.concat(rows):rows;
        filteredMembers=allMembers;
        if(d.total!==null&&d.total!==undefined)memberTotal=Number(d.total)||0;if(d.pages!==null&&d.pages!==undefined)memberPages=Number(d.pages)||1;curPage=Number(d.page)||page||1;memberNextCursor=Number(d.next_cursor)||null;
        if(!append)memberFirstPage=curPage;
        if(!memberOptionsLoaded&&d.options?.sections){populateDropdowns(d.options.sections);memberOptionsLoaded=true;}
        renderMembers();
    }catch(e){
        if(e.name==='AbortError')return;
        if(rc)rc.innerHTML='<span style="color:var(--bad)">Could not load members. '+esc(e.message||'')+'</span>';
        toast('Failed to load members','e');
    }
}
function populateDropdowns(sections){const secs=[...new Set((sections||[]).filter(Boolean))].sort();['fSection','attSection','qmSection'].forEach(id=>{const s=document.getElementById(id);if(!s)return;const v=s.value;const def=id==='qmSection'?'<option value="">Select</option>':'<option value="">All</option>';s.innerHTML=def+secs.map(x=>`<option value="${esc(x)}">${esc(x)}</option>`).join('');s.value=v;});}
function applyFilters(){clearTimeout(memberFilterTimer);memberCursors={1:null};memberFilterTimer=setTimeout(()=>loadMembers(1),300);}
function sortBy(col){if(sortCol===col)sortDir=sortDir==='asc'?'desc':'asc';else{sortCol=col;sortDir='asc';}document.querySelectorAll('#mTbl th').forEach(th=>th.classList.remove('sa','sd'));const th=document.querySelector('#mTbl th[data-col="'+col+'"]');if(th)th.classList.add(sortDir==='asc'?'sa':'sd');memberCursors={1:null};loadMembers(1);}
function renderMembers(){const pp=memberPerPage();const pg=allMembers;const first=memberTotal&&pg.length?((memberFirstPage-1)*pp)+1:0,last=first?Math.min(memberTotal,first+pg.length-1):0;document.getElementById('resultCount').innerHTML=`Showing <span style="color:var(--bright);font-weight:600">${first}–${last}</span> of ${memberTotal}`;const tb=document.getElementById('mBody');if(!pg.length){tb.innerHTML='<tr><td colspan="12" style="text-align:center;padding:2rem;color:var(--dim)">No members found</td></tr>';}else{tb.innerHTML=pg.map(m=>`<tr><td><span class="bg bg-info">${esc(m.member_code||'—')}</span></td><td style="font-weight:600;color:var(--bright)">${esc(m.student_name||'')}</td><td>${esc(m.father_name||'')}</td><td>${m.gender==='male'?'<span style="color:var(--info)">♂</span>':'<span style="color:var(--pink)">♀</span>'}</td><td>${esc(fmtAge(m.age_group))}</td><td>${esc(m.current_section||'—')}</td><td>${stBg(m.status)}</td><td>${rgBg(m.registration_type)}</td><td style="font-size:.68rem">${esc(m.phone_number||'—')}</td><td>${esc(m.city||'—')}</td><td style="font-size:.62rem;color:var(--dim)">${fmtDate(m.created_at)}</td><td><button class="btn bo bs" onclick="viewMember(${parseInt(m.id,10)})"><i class="fa-solid fa-eye"></i></button></td></tr>`).join('');}const p=document.getElementById('pag');if(memberPages<=1){p.innerHTML='';return;}const left=Math.max(0,memberTotal-last);const pos=memberFirstPage===curPage?`Page ${curPage} of ${memberPages}`:`Pages ${memberFirstPage}–${curPage} of ${memberPages}`;p.innerHTML=`<button class="btn bo bs" data-fk-pager-prev onclick="goP(${memberFirstPage-1})" ${memberFirstPage<=1?'disabled':''}>‹ Previous</button><span data-fk-pager-pos style="padding:.3rem;color:var(--dim)">${pos}</span><button class="btn bo bs" data-fk-pager-next onclick="goP(${curPage+1})" ${curPage>=memberPages?'disabled':''}>Next ›</button>`+(left>0?`<button class="btn bs" data-fk-pager-more onclick="loadMoreMembers(this)" style="margin-left:.6rem">Load more (${left} left)</button>`:'');}
function loadMoreMembers(btn){if(curPage>=memberPages)return;const page=curPage+1;if(sortCol==='id'&&sortDir==='desc'&&memberNextCursor)memberCursors[page]=memberNextCursor;if(btn){btn.disabled=true;btn.textContent='Loading…';}loadMembers(page,true);}
function goP(page){if(page<1||page>memberPages)return;if(page===curPage+1&&sortCol==='id'&&sortDir==='desc'){if(!memberNextCursor)return;memberCursors[page]=memberNextCursor;}loadMembers(page);window.scrollTo({top:0,behavior:'smooth'});}
function resetFilters(){['fSearch','fCity'].forEach(id=>{const e=document.getElementById(id);if(e)e.value='';});['fStatus','fGender','fSection','fRegType','fMemberType','fAgeGroup'].forEach(id=>{const e=document.getElementById(id);if(e)e.value='';});memberCursors={1:null};loadMembers(1);}
let currentViewMember=null;
function viewMember(id){const m=allMembers.find(x=>x.id==id)||globalSearchMembers.find(x=>x.id==id);if(!m)return;currentViewMember=m;
const sec=(t,ico,clr,fields)=>`<div style="margin-bottom:.85rem"><div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:${clr};font-weight:700;margin-bottom:.45rem;display:flex;align-items:center;gap:.35rem"><i class="fa-solid fa-${ico}" style="font-size:.65rem"></i> ${t}</div><div style="display:grid;grid-template-columns:1fr 1fr;gap:.35rem .75rem">${fields.map(([l,v])=>`<div style="padding:.3rem 0"><span style="color:var(--dim);font-size:.65rem;display:block;letter-spacing:.03em">${l}</span><span style="font-size:.82rem;color:var(--bright)">${v||'—'}</span></div>`).join('')}</div></div>`;
document.getElementById('memberDetail').innerHTML=`
<div style="display:flex;align-items:center;gap:.85rem;padding:.85rem;background:linear-gradient(135deg,rgba(6,182,212,.06),rgba(139,92,246,.04));border-radius:12px;margin-bottom:.85rem">
<div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;font-weight:700;flex-shrink:0">${(m.student_name||'?')[0].toUpperCase()}</div>
<div style="flex:1;min-width:0"><div style="font-size:1.05rem;font-weight:700;color:var(--bright)">${esc(m.student_name||'')} ${esc(m.father_name||'')} ${esc(m.grandfather_name||'')}</div>
<div style="font-size:.72rem;color:var(--dim);display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.2rem"><span class="bg bg-info">${esc(m.member_code||'—')}</span> ${stBg(m.status)} ${rgBg(m.registration_type)} <span style="color:${m.gender==='male'?'var(--info)':'var(--pink)'}">${m.gender==='male'?'♂ Male':'♀ Female'}</span></div></div></div>
${sec('Personal Information','user','var(--ac)',[['Full Name',`<strong>${esc(m.student_name)}</strong>`],['Father Name',esc(m.father_name)],['Grandfather Name',esc(m.grandfather_name)],['Christian Name (የክርስትና ስም)',esc(m.baptismal_name)],['Gender',m.gender==='male'?'♂ Male':'♀ Female'],['Age Group',fmtAge(m.age_group)]])}
${sec('Church & Education','graduation-cap','var(--purple)',[['Current Section',esc(m.current_section)],['Member Type',esc(m.member_type)],['Registration Type',esc(m.registration_type)],['Education Level',esc(m.education_level)],['Profession',esc(m.work_profession)],['Member Code',esc(m.member_code)]])}
${sec('Contact Details','phone','var(--ok)',[['Phone Number',esc(m.phone_number)],['Alt Phone',esc(m.alt_phone_number)],['Guardian Name',esc(m.guardian_name)],['Guardian Phone 1',esc(m.guardian_phone1)]])}
${sec('Address','location-dot','var(--warn)',[['City',esc(m.city)],['Sub City',esc(m.sub_city)],['Woreda',esc(m.woreda)],['Mender',esc(m.mender)],['Block Number',esc(m.block_number)],['House Number',esc(m.house_number)]])}
<div style="text-align:right;font-size:.65rem;color:var(--dim);margin-top:.5rem;padding-top:.4rem;border-top:1px solid var(--cb)">Registered: ${fmtDate(m.created_at)} • Member ID: ${m.id}</div>`;
document.getElementById('memberModal').classList.add('show');}


// QUICK ADD MEMBER
let quickAddInFlight=false;
let quickAddRequestId='';
function getQuickAddRequestId(){if(!quickAddRequestId)quickAddRequestId=window.SsmsRequestId?.create()||'';return quickAddRequestId;}
async function quickAddMember(){if(quickAddInFlight)return;const nm=document.getElementById('qmName').value.trim(),fn=document.getElementById('qmFather').value.trim(),ge=document.getElementById('qmGender').value,ph=document.getElementById('qmPhone').value.trim(),se=document.getElementById('qmSection').value,rt=document.getElementById('qmRegType').value;if(!nm||!fn||!ge)return toast('Name, father, gender required','e');const button=document.getElementById('quickAddButton');quickAddInFlight=true;if(button)button.disabled=true;try{const fd=new FormData();fd.append('registration_profile','quick_add');fd.append('registration_request_id',getQuickAddRequestId());fd.append('full_name_am',nm+' '+fn);fd.append('gender',ge);fd.append('phone_number',ph);fd.append('current_section',se);fd.append('registration_type',rt);fd.append('csrf_token',CSRF);const r=await fetch('/admin/hr_register_member.php',{method:'POST',body:fd,credentials:'same-origin',headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok||d.status!=='success')throw new Error(d.message||'Registration failed');toast('Member added!','s');quickAddRequestId='';document.getElementById('quickAddModal').classList.remove('show');['qmName','qmFather','qmPhone'].forEach(id=>document.getElementById(id).value='');document.getElementById('qmGender').value='';allMembers=[];loadMembers();}catch(e){toast(e.message||'Network error','e');}finally{quickAddInFlight=false;if(button)button.disabled=false;}}

// ATTENDANCE
async function loadAttReport(){const from=document.getElementById('attFrom').value,to=document.getElementById('attTo').value,sec=document.getElementById('attSection').value;if(!from||!to)return toast('Select dates','e');try{const r=await fetch(`/admin/api_attendance_info.php?action=report&from=${from}&to=${to}&section=${encodeURIComponent(sec)}`,{credentials:'same-origin'});const d=await r.json();if(d.status==='success'&&d.data){const dt=d.data;document.getElementById('attStats').innerHTML=`<div class="sc"><div class="ico" style="background:rgba(16,185,129,.1);color:var(--ok)"><i class="fa-solid fa-check"></i></div><div class="val">${dt.total_present||0}</div><div class="lbl">Present</div></div><div class="sc"><div class="ico" style="background:rgba(239,68,68,.08);color:var(--bad)"><i class="fa-solid fa-xmark"></i></div><div class="val">${dt.total_absent||0}</div><div class="lbl">Absent</div></div><div class="sc"><div class="ico" style="background:rgba(245,158,11,.08);color:var(--warn)"><i class="fa-solid fa-clock"></i></div><div class="val">${dt.total_late||0}</div><div class="lbl">Late</div></div><div class="sc"><div class="ico" style="background:rgba(6,182,212,.1);color:var(--ac)"><i class="fa-solid fa-percent"></i></div><div class="val">${dt.avg_rate||0}%</div><div class="lbl">Rate</div></div>`;if(dt.members?.length){document.getElementById('attBody').innerHTML=dt.members.map(m=>`<tr><td style="font-weight:500">${esc(m.name)}</td><td>${esc(m.section||'—')}</td><td style="color:var(--ok)">${m.present||0}</td><td style="color:var(--bad)">${m.absent||0}</td><td style="color:var(--warn)">${m.late||0}</td><td><strong>${m.rate||0}%</strong></td></tr>`).join('');document.getElementById('attCard').style.display='block';}}else document.getElementById('attStats').innerHTML='<div class="cd" style="grid-column:1/-1;text-align:center;padding:1rem;color:var(--dim)">No data found</div>';}catch(e){toast('Error','e');}}
function exportAtt(){const rows=[];document.querySelectorAll('#attBody tr').forEach(tr=>{rows.push([...tr.querySelectorAll('td')].map(td=>td.textContent.trim()));});if(!rows.length)return toast('No data','e');expTbl(['Name','Section','Present','Absent','Late','Rate'],rows,'Attendance_Report','excel');}

// CLASSES
async function loadClasses(){const el=document.getElementById('classesContent');try{const r=await fetch('/admin/api_education.php?action=dashboard',{credentials:'same-origin'});const d=await r.json();if(d.status==='success'){const cls=d.data?.classes||[];if(!cls.length){el.innerHTML='<div class="cd" style="text-align:center;padding:2rem"><i class="fa-solid fa-chalkboard" style="font-size:1.5rem;color:var(--dim);display:block;margin-bottom:.5rem"></i><p style="color:var(--dim);font-size:.75rem">No classes yet. Managed by Education Dept.</p></div>';return;}el.innerHTML=`<div class="sg"><div class="sc"><div class="ico" style="background:rgba(6,182,212,.1);color:var(--ac)"><i class="fa-solid fa-chalkboard"></i></div><div class="val">${cls.length}</div><div class="lbl">Classes</div></div><div class="sc"><div class="ico" style="background:rgba(16,185,129,.1);color:var(--ok)"><i class="fa-solid fa-user-graduate"></i></div><div class="val">${cls.reduce((s,c)=>s+(parseInt(c.student_count)||0),0)}</div><div class="lbl">Enrolled</div></div></div><div class="cd"><div class="ct"><i class="fa-solid fa-list" style="color:var(--ac)"></i> Classes</div><div class="tw"><table><thead><tr><th>Class</th><th>Level</th><th>Students</th><th>Teacher</th></tr></thead><tbody>${cls.map(c=>`<tr><td style="font-weight:600;color:var(--bright)">${esc(c.class_name||c.name||'—')}</td><td>${esc(c.level||c.grade_level||'—')}</td><td><span class="bg bg-info">${c.student_count||0}</span></td><td>${esc(c.teacher_name||'—')}</td></tr>`).join('')}</tbody></table></div></div>`;}else el.innerHTML='<div class="cd" style="text-align:center;padding:1.5rem;color:var(--dim)">Could not load</div>';}catch(e){el.innerHTML='<div class="cd" style="text-align:center;padding:1.5rem;color:var(--dim)"><i class="fa-solid fa-info-circle" style="color:var(--warn)"></i> Education setup needed</div>';}}

// STAFF
function renderUsers(){document.getElementById('usersBody').innerHTML=usersData.map(u=>`<tr><td style="color:var(--dim)">${u.id}</td><td style="font-weight:600;color:var(--bright)">${esc(u.username)}</td><td>${esc(u.full_name||'—')}</td><td>${rlBg(u.role)}</td><td>${stBg(u.status)}</td><td style="font-size:.62rem;color:var(--dim)">${u.last_login?fmtDate(u.last_login):'Never'}</td><td><button class="btn bs ${u.status==='active'?'bo':''}" style="${u.status!=='active'?'background:var(--ok);color:#fff':''}" onclick="toggleUser(${u.id},'${u.status}')"><i class="fa-solid fa-${u.status==='active'?'ban':'check'}"></i></button></td></tr>`).join('');}
function openAddUser(){document.getElementById('addUserModal').classList.add('show');}
async function saveNewUser(){const nm=document.getElementById('nuName').value.trim(),un=document.getElementById('nuUser').value.trim(),pw=document.getElementById('nuPass').value,rl=document.getElementById('nuRole').value;if(!nm||!un||!pw)return toast('All fields required','e');if(pw.length<12)return toast('Password must be at least 12 characters','e');if(new TextEncoder().encode(pw).length>72)return toast('Password is too long','e');try{const fd=new FormData();fd.append('full_name',nm);fd.append('username',un);fd.append('password',pw);fd.append('role',rl);fd.append('status','active');fd.append('csrf_token',CSRF);const r=await fetch('/admin/backend/user-save.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();if(d.status==='success'||d.success){toast('User created!','s');document.getElementById('addUserModal').classList.remove('show');usersData.unshift({id:d.id||Date.now(),username:un,full_name:nm,role:rl,status:'active',last_login:null});renderUsers();['nuName','nuUser','nuPass'].forEach(id=>document.getElementById(id).value='');}else toast(d.message||'Failed','e');}catch(e){toast('Network error','e');}}
async function toggleUser(id,cur){const act=cur==='active'?'deactivate':'activate';const dc=await dcAsk(act==='deactivate'?{tier:'medium',title:'Deactivate this user account?',summary:'The account is switched off but not deleted.',intent:'I want to deactivate this account',consequences:['The user can no longer log in on the web dashboard or the mobile app.','Work assigned to this account (classes, tasks) stops reaching them.'],keeps:['Everything they recorded stays and keeps their name.','You can activate the account again later.'],phrase:'DEACTIVATE USER',confirmLabel:'Deactivate account'}:{tier:'low',title:'Activate this user account?',summary:'The user can log in again with their existing password.',confirmLabel:'Activate account'});if(!dc)return;try{const fd=new FormData();fd.append('id',id);fd.append('action',act);fd.append('csrf_token',CSRF);const r=await fetch('/admin/backend/user-toggle.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();if(d.status==='success'||d.success){const u=usersData.find(x=>x.id==id);if(u)u.status=cur==='active'?'inactive':'active';renderUsers();toast('Done','s');}}catch(e){toast('Failed','e');}}

// REPORTS
function loadAnalysis(){buildAnalysis();}
function buildAnalysis(){const el=document.getElementById('membershipAnalysis');const bySt={active:Number(memberStats.active)||0,warning:Number(memberStats.warning)||0,inactive:Number(memberStats.inactive)||0},byG={male:Number(memberStats.male)||0,female:Number(memberStats.female)||0},bySec=Object.fromEntries(secDist.map(item=>[item.s||'Unassigned',Number(item.c)||0])),summary={total:Number(memberStats.total)||0,pending:Number(memberStats.pending)||0,teachers:Number(memberStats.teachers)||0,sections:Number(memberStats.sections)||0};const row=(obj,clr)=>Object.entries(obj).map(([k,v])=>`<div style="display:flex;justify-content:space-between;font-size:.72rem;padding:.3rem 0;border-bottom:1px solid var(--cb)"><span>${esc(k.replace(/_/g,' '))}</span><span style="font-weight:700;color:${clr}">${v}</span></div>`).join('');el.innerHTML=`<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem" class="two-col"><div><div class="flbl" style="margin-bottom:.4rem;color:var(--info)"><i class="fa-solid fa-signal"></i> Status</div>${row(bySt,'var(--bright)')}</div><div><div class="flbl" style="margin-bottom:.4rem;color:var(--purple)"><i class="fa-solid fa-venus-mars"></i> Gender</div>${row(byG,'var(--bright)')}</div><div><div class="flbl" style="margin-bottom:.4rem;color:var(--warn)"><i class="fa-solid fa-layer-group"></i> Section</div>${row(bySec,'var(--bright)')}</div><div><div class="flbl" style="margin-bottom:.4rem;color:var(--ok)"><i class="fa-solid fa-users"></i> Summary</div>${row(summary,'var(--bright)')}</div></div>`;}

// ═══════════════════════════════════════════════════════
// ULTRA PROFESSIONAL EXPORT ENGINE
// ═══════════════════════════════════════════════════════

// ── Analytics builder ──
function buildAnalyticsData(members){
    const total=members.length;
    const bySt={active:0,warning:0,inactive:0};
    const byG={Male:0,Female:0};
    const bySec={};
    const byRt={Waiting:0,Transfer:0,Direct:0};
    const byAg={};
    const byCity={};
    members.forEach(m=>{
        if(bySt[m.status]!==undefined)bySt[m.status]++;
        if(m.gender==='male')byG.Male++;else byG.Female++;
        const sec=m.current_section||'Unassigned';bySec[sec]=(bySec[sec]||0)+1;
        const rt=(m.registration_type||'unknown').charAt(0).toUpperCase()+(m.registration_type||'unknown').slice(1);
        if(byRt[rt]!==undefined)byRt[rt]++;
        const ag=fmtAge(m.age_group);byAg[ag]=(byAg[ag]||0)+1;
        const ct=m.city||'Unknown';byCity[ct]=(byCity[ct]||0)+1;
    });
    return{total,bySt,byG,bySec:Object.entries(bySec).sort((a,b)=>b[1]-a[1]),byRt,byAg:Object.entries(byAg),byCity:Object.entries(byCity).sort((a,b)=>b[1]-a[1]).slice(0,10)};
}

// ── SVG chart generators (for PDF/Word embedding) ──
function svgBar(data,w,h,colors){
    const max=Math.max(...data.map(d=>d[1]),1);
    const bw=Math.min(40,Math.floor((w-40)/data.length)-6);
    let svg=`<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">`;
    svg+=`<rect width="${w}" height="${h}" fill="#f8fafc" rx="8"/>`;
    const base=h-28;
    data.forEach(([label,val],i)=>{
        const bh=Math.max(3,Math.round(val/max*(base-20)));
        const x=25+i*(bw+6);
        const clr=colors[i%colors.length];
        svg+=`<rect x="${x}" y="${base-bh}" width="${bw}" height="${bh}" fill="${clr}" rx="3"/>`;
        svg+=`<text x="${x+bw/2}" y="${base-bh-4}" text-anchor="middle" font-size="8" font-family="Arial" fill="#374151" font-weight="bold">${val}</text>`;
        svg+=`<text x="${x+bw/2}" y="${base+12}" text-anchor="middle" font-size="6.5" font-family="Arial" fill="#6b7280">${label.length>8?label.slice(0,8)+'…':label}</text>`;
    });
    svg+=`</svg>`;return svg;
}
function svgDonut(data,size,colors){
    const total=data.reduce((s,d)=>s+d[1],0)||1;
    const r=size/2-12,cx=size/2,cy=size/2;
    let svg=`<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">`;
    svg+=`<rect width="${size}" height="${size}" fill="#f8fafc" rx="8"/>`;
    let cumAngle=-90;
    data.forEach(([label,val],i)=>{
        const pct=val/total;const angle=pct*360;
        const startRad=(cumAngle)*Math.PI/180;
        const endRad=(cumAngle+angle)*Math.PI/180;
        const x1=cx+r*Math.cos(startRad),y1=cy+r*Math.sin(startRad);
        const x2=cx+r*Math.cos(endRad),y2=cy+r*Math.sin(endRad);
        const large=angle>180?1:0;
        const ir=r*0.55;
        const ix1=cx+ir*Math.cos(endRad),iy1=cy+ir*Math.sin(endRad);
        const ix2=cx+ir*Math.cos(startRad),iy2=cy+ir*Math.sin(startRad);
        svg+=`<path d="M${x1},${y1} A${r},${r} 0 ${large} 1 ${x2},${y2} L${ix1},${iy1} A${ir},${ir} 0 ${large} 0 ${ix2},${iy2} Z" fill="${colors[i%colors.length]}"/>`;
        cumAngle+=angle;
    });
    svg+=`<text x="${cx}" y="${cy-3}" text-anchor="middle" font-size="16" font-family="Arial" font-weight="bold" fill="#111827">${total}</text>`;
    svg+=`<text x="${cx}" y="${cy+10}" text-anchor="middle" font-size="7" font-family="Arial" fill="#6b7280">Total</text>`;
    // Legend
    data.forEach(([label,val],i)=>{
        const ly=size-20+i*0;
    });
    svg+=`</svg>`;return svg;
}

// ── Color palettes ──
const PDF_COLORS={primary:[6,182,212],secondary:[139,92,246],ok:[16,185,129],warn:[245,158,11],bad:[239,68,68],pink:[236,72,153]};
const CHART_COLORS=['#06b6d4','#8b5cf6','#10b981','#f59e0b','#ef4444','#ec4899','#f97316','#84cc16','#6366f1','#14b8a6'];

// ═══ PDF HEADER (Info-Dept Style) ═══
function pdfHeader(doc,title,subtitle){
    const pw=doc.internal.pageSize.getWidth();
    // Green gradient header
    doc.setFillColor(22,163,74);doc.rect(0,0,pw,32,'F');
    doc.setFillColor(13,148,136);doc.rect(0,28,pw,4,'F');
    // Title
    doc.setFontSize(15);doc.setFont(undefined,'bold');doc.setTextColor(255,255,255);
    doc.text('<?= addslashes(SCHOOL_NAME) ?>',14,14);
    doc.setFontSize(9);doc.setFont(undefined,'normal');doc.setTextColor(220,252,231);
    doc.text(title||'Member Report',14,21);
    doc.setFontSize(7);doc.setTextColor(187,247,208);
    doc.text((subtitle?subtitle+' | ':'')+'Generated: '+genStamp(),14,27);
    doc.setTextColor(0,0,0);
    return 38;
}

// ═══ PDF FOOTER ═══
function pdfFooter(doc){
    const pw=doc.internal.pageSize.getWidth();
    const ph=doc.internal.pageSize.getHeight();
    const pages=doc.internal.getNumberOfPages();
    for(let i=1;i<=pages;i++){
        doc.setPage(i);
        doc.setDrawColor(22,163,74);doc.setLineWidth(0.5);doc.line(14,ph-14,pw-14,ph-14);
        doc.setFontSize(7);doc.setTextColor(148,163,184);
        doc.text('<?= addslashes(SCHOOL_NAME) ?> — Confidential',14,ph-8);
        doc.text('Page '+i+' of '+pages,pw-35,ph-8);
    }
}

// ═══ PDF STAT BOX ═══
function pdfStatBox(doc,x,y,w,label,value,color){
    doc.setFillColor(240,253,244);doc.roundedRect(x,y,w,18,3,3,'F');
    doc.setDrawColor(209,250,229);doc.roundedRect(x,y,w,18,3,3,'S');
    doc.setFillColor(...color);doc.roundedRect(x+3,y+3,3,12,1,1,'F');
    doc.setFontSize(13);doc.setFont(undefined,'bold');doc.setTextColor(17,24,39);
    doc.text(String(value),x+11,y+10);
    doc.setFontSize(7);doc.setFont(undefined,'normal');doc.setTextColor(107,114,128);
    doc.text(label,x+11,y+15);
}

// ═══ EXPORT MEMBER PROFILE (Single member) ═══
function exportMemberProfile(fmt){
    const m=currentViewMember;if(!m)return toast('No member selected','e');
    if(fmt==='pdf')exportMemberPDF(m);
    else if(fmt==='word')exportMemberWord(m);
}
function exportMemberPDF(m){
    // Print-based PDF that fully supports Amharic (like info-dept)
    const w=window.open('','_blank','width=800,height=900');
    w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Member Profile — ${esc(m.student_name)}</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    @page{size:A4;margin:12mm 14mm}
    @media print{.no-print{display:none!important}body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Noto Sans Ethiopic',Segoe UI,Arial,sans-serif;font-size:11pt;color:#1e293b;line-height:1.5}
    .toolbar{padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;gap:8px;align-items:center}
    .toolbar button,.toolbar a{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:.15s}
    .btn-g{background:#16a34a;color:white}.btn-g:hover{background:#15803d}
    .btn-o{background:#f1f5f9;color:#475569}.btn-o:hover{background:#e2e8f0}
    .header{background:linear-gradient(135deg,#16a34a,#0d9488);color:white;padding:16px 22px;margin-bottom:12px}
    .header h1{font-size:15pt;margin:0 0 3px}.header p{font-size:9pt;color:#d1fae5;margin:0}
    .profile{background:#f0fdf4;border:1px solid #d1fae5;border-radius:10px;padding:14px;margin:0 0 14px;display:flex;align-items:center;gap:14px}
    .avatar{width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#16a34a,#0d9488);color:white;text-align:center;line-height:52px;font-size:20pt;font-weight:bold;flex-shrink:0}
    .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:8pt;font-weight:600;margin-right:3px}
    .b-ok{background:#d1fae5;color:#065f46}.b-w{background:#fef3c7;color:#92400e}.b-bad{background:#fee2e2;color:#991b1b}.b-info{background:#e0f2fe;color:#0369a1}
    .sec{margin-bottom:12px}.sec-title{font-size:10pt;text-transform:uppercase;letter-spacing:1px;font-weight:700;padding-bottom:4px;border-bottom:2.5px solid;margin-bottom:6px}
    .st1{color:#16a34a;border-color:#16a34a}.st2{color:#8b5cf6;border-color:#8b5cf6}.st3{color:#0891b2;border-color:#0891b2}.st4{color:#d97706;border-color:#d97706}
    table.f{width:100%;border-collapse:collapse}table.f td{padding:5px 8px;border-bottom:1px solid #f1f5f9;font-size:10pt}
    .lbl{color:#6b7280;font-size:8pt;text-transform:uppercase;letter-spacing:0.5px;display:block}.val{color:#111827;font-weight:500}
    .footer{margin-top:16px;padding-top:6px;border-top:2px solid #16a34a;color:#94a3b8;font-size:8pt;text-align:center}
    </style><link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
</head><body>
    <div class="toolbar no-print"><button class="btn-g" onclick="window.print()">🖨️ Print / Save as PDF</button><button class="btn-o" onclick="window.close()">← Close</button><span style="color:#64748b;font-size:11px;margin-left:8px">Tip: Select "Save as PDF" in print dialog</span></div>
    <div class="header"><h1><?= SCHOOL_NAME_SHORT ?> <?= SCHOOL_TYPE ?> — Member Profile</h1><p>Official Member Record • Generated: ${genStamp()}</p></div>
    <div class="profile"><div class="avatar">${(m.student_name||'?')[0].toUpperCase()}</div><div>
    <div style="font-size:14pt;font-weight:bold">${esc(m.student_name||'')} ${esc(m.father_name||'')} ${esc(m.grandfather_name||'')}</div>
    <div style="margin-top:4px"><span class="badge b-info">${esc(m.member_code||'—')}</span><span class="badge b-${m.status==='active'?'ok':(m.status==='warning'?'w':'bad')}">${(m.status||'—').toUpperCase()}</span><span class="badge b-info">${(m.registration_type||'').toUpperCase()}</span><span class="badge b-info">${m.gender==='male'?'Male':'Female'}</span></div></div></div>
    ${[{t:'Personal Information',c:'st1',f:[['Full Name',m.student_name],['Father Name',m.father_name],['Grandfather Name',m.grandfather_name],['Christian Name (የክርስትና ስም)',m.baptismal_name],['Gender',m.gender==='male'?'Male':'Female'],['Age Group',fmtAge(m.age_group)]]},
    {t:'Church & Education',c:'st2',f:[['Current Section',m.current_section],['Member Type',m.member_type],['Registration Type',m.registration_type],['Education Level',m.education_level],['Profession',m.work_profession],['Member Code',m.member_code]]},
    {t:'Contact Details',c:'st3',f:[['Phone Number',m.phone_number],['Alt Phone',m.alt_phone_number],['Guardian Name',m.guardian_name],['Guardian Phone',m.guardian_phone1]]},
    {t:'Address Information',c:'st4',f:[['City',m.city],['Sub City',m.sub_city],['Woreda',m.woreda],['Mender',m.mender],['Block Number',m.block_number],['House Number',m.house_number]]}
    ].map(s=>'<div class="sec"><div class="sec-title '+s.c+'">'+s.t+'</div><table class="f">'+s.f.map(([l,v],i)=>i%2===0?'<tr><td style="width:50%"><span class="lbl">'+l+'</span><span class="val">'+(esc(v)||'—')+'</span></td>'+(s.f[i+1]?'<td><span class="lbl">'+s.f[i+1][0]+'</span><span class="val">'+(esc(s.f[i+1][1])||'—')+'</span></td>':'<td></td>')+'</tr>':'').filter(Boolean).join('')+'</table></div>').join('')}
    <div class="footer"><?= SCHOOL_NAME ?> • Member ID: ${m.id} • Registered: ${fmtDate(m.created_at)} • Generated: ${genStamp()}</div>
    <script>setTimeout(()=>window.print(),600)<\/script></body></html>`);
    w.document.close();
    toast('Print dialog opening — choose "Save as PDF"','s');
}

function exportMemberWord(m){
    const html=`<html><head><meta charset="utf-8"><style>
    @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;500;600;700&display=swap');
    @page{margin:18mm 14mm}body{font-family:'Noto Sans Ethiopic',Segoe UI,Arial,sans-serif;color:#1f2937;font-size:11pt;line-height:1.5}
    .header{background:linear-gradient(135deg,#16a34a,#0d9488);color:white;padding:18px 22px;border-radius:8px;margin-bottom:14px}
    .header h1{margin:0;font-size:16pt;color:white}.header p{margin:3px 0 0;color:#d1fae5;font-size:9pt}
    .profile-card{background:#f0fdf4;border:1px solid #d1fae5;border-radius:8px;padding:14px;margin-bottom:14px;display:flex;align-items:center;gap:14px}
    .avatar{width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#16a34a,#0d9488);color:white;text-align:center;line-height:48px;font-size:18pt;font-weight:bold}
    .badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:8pt;font-weight:600;margin-right:4px}
    .bg-active{background:#d1fae5;color:#065f46}.bg-warning{background:#fef3c7;color:#92400e}.bg-inactive{background:#fee2e2;color:#991b1b}
    .bg-info{background:#e0f2fe;color:#0369a1}
    .section{margin-bottom:14px}.section-title{font-size:10pt;text-transform:uppercase;letter-spacing:1px;font-weight:700;margin-bottom:6px;padding-bottom:4px;border-bottom:2px solid}
    .st-personal{color:#16a34a;border-color:#16a34a}.st-church{color:#8b5cf6;border-color:#8b5cf6}.st-contact{color:#0891b2;border-color:#0891b2}.st-address{color:#d97706;border-color:#d97706}
    table.fields{width:100%;border-collapse:collapse;font-size:10pt}table.fields td{padding:5px 8px;border-bottom:1px solid #f3f4f6}
    .label{color:#6b7280;font-size:8pt;text-transform:uppercase;letter-spacing:0.5px}.value{color:#111827;font-weight:500;font-size:10pt}
    .footer{margin-top:16px;padding-top:8px;border-top:2px solid #16a34a;color:#9ca3af;font-size:8pt;text-align:center}
    </style><link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
</head><body>
    <div class="header"><h1><?= SCHOOL_NAME_SHORT ?> Member Profile</h1><p><?= SCHOOL_TAGLINE ?> — Official Record</p></div>
    <div class="profile-card"><div class="avatar">${(m.student_name||'?')[0].toUpperCase()}</div><div>
    <div style="font-size:14pt;font-weight:bold;color:#111827">${esc(m.student_name||'')} ${esc(m.father_name||'')} ${esc(m.grandfather_name||'')}</div>
    <div style="margin-top:4px"><span class="badge bg-info">${esc(m.member_code||'—')}</span><span class="badge bg-${m.status==='active'?'active':(m.status==='warning'?'warning':'inactive')}">${(m.status||'—').toUpperCase()}</span><span class="badge bg-info">${(m.registration_type||'—').toUpperCase()}</span><span class="badge bg-info">${m.gender==='male'?'Male':'Female'}</span></div>
    </div></div>
    ${[{t:'Personal Information',c:'personal',f:[['Full Name',m.student_name],['Father Name',m.father_name],['Grandfather Name',m.grandfather_name],['Christian Name (የክርስትና ስም)',m.baptismal_name],['Gender',m.gender==='male'?'Male':'Female'],['Age Group',fmtAge(m.age_group)]]},
    {t:'Church & Education',c:'church',f:[['Current Section',m.current_section],['Member Type',m.member_type],['Registration Type',m.registration_type],['Education Level',m.education_level],['Profession',m.work_profession],['Member Code',m.member_code]]},
    {t:'Contact Details',c:'contact',f:[['Phone Number',m.phone_number],['Alt Phone',m.alt_phone_number],['Guardian Name',m.guardian_name],['Guardian Phone',m.guardian_phone1]]},
    {t:'Address Information',c:'address',f:[['City',m.city],['Sub City',m.sub_city],['Woreda',m.woreda],['Mender',m.mender],['Block Number',m.block_number],['House Number',m.house_number]]}
    ].map(s=>`<div class="section"><div class="section-title st-${s.c}">${s.t}</div><table class="fields">${s.f.map(([l,v],i)=>i%2===0?`<tr><td style="width:25%"><span class="label">${l}</span><br><span class="value">${esc(v)||'—'}</span></td>${s.f[i+1]?`<td style="width:25%"><span class="label">${s.f[i+1][0]}</span><br><span class="value">${esc(s.f[i+1][1])||'—'}</span></td>`:'<td></td>'}</tr>`:'').filter(Boolean).join('')}</table></div>`).join('')}
    <div class="footer">Generated: ${genStamp()} | Member ID: ${m.id} | <?= SCHOOL_NAME ?> | Registered: ${fmtDate(m.created_at)}</div>
    </body></html>`;
    dlFile(html,'<?= MEMBER_CODE_PREFIX ?>_Member_'+(m.student_name||'profile').replace(/\s/g,'_')+'.doc','application/msword');
    toast('Member profile exported as Word','s');
}

// ═══ MAIN DATA EXPORT (filtered members list + analytics) ═══
function exportData(fmt){
    if(!allMembers.length){loadMembers().then(()=>exportData(fmt));return;}
    const data=filteredMembers.length?filteredMembers:allMembers;
    if(fmt==='csv')exportCSV(data);
    else if(fmt==='excel')exportExcelPro(data);
    else if(fmt==='pdf')exportPDFPro(data);
    else if(fmt==='word')exportWordPro(data);
}
function exportFullReport(fmt){if(!allMembers.length){loadMembers().then(()=>exportFullReport(fmt));return;}exportData(fmt);}

// ── CSV ──
function exportCSV(data){
    const h=['Code','Name','Father Name','Grandfather','Christian Name (የክርስትና ስም)','Gender','Age Group','Section','Status','Reg Type','Member Type','Phone','Alt Phone','Guardian','Guardian Phone','City','Sub City','Woreda','Education','Profession','Registered'];
    const rows=data.map(m=>[m.member_code,m.student_name,m.father_name,m.grandfather_name,m.baptismal_name,m.gender,fmtAge(m.age_group),m.current_section,m.status,m.registration_type,m.member_type,m.phone_number,m.alt_phone_number,m.guardian_name,m.guardian_phone1,m.city,m.sub_city,m.woreda,m.education_level,m.work_profession,m.created_at?fmtDate(m.created_at):''].map(v=>v||''));
    let csv=h.join(',')+'\n'+rows.map(r=>r.map(c=>`"${(c+'').replace(/"/g,'""')}"`).join(',')).join('\n');
    dlFile(csv,'<?= MEMBER_CODE_PREFIX ?>_Members_Full_'+new Date().toISOString().slice(0,10)+'.csv','text/csv');
    toast('CSV exported with all fields','s');
}

// ── PROFESSIONAL EXCEL ──
function exportExcelPro(data){
    const a=buildAnalyticsData(data);
    const wb=XLSX.utils.book_new();

    // Sheet 1: Summary Dashboard
    const summary=[
        ['<?= strtoupper(SCHOOL_NAME) ?>'],['Member Data Report'],
        ['Generated: '+genStamp()],[''],
        ['OVERVIEW STATISTICS'],
        ['Total Members','Active','Warning','Inactive','Pending','Male','Female','Sections'],
        [a.total,a.bySt.active,a.bySt.warning,a.bySt.inactive,data.filter(m=>m.registration_type==='waiting').length,a.byG.Male,a.byG.Female,a.bySec.length],
        [''],['SECTION DISTRIBUTION'],['Section','Count','Percentage'],...a.bySec.map(([s,c])=>[s,c,(c/a.total*100).toFixed(1)+'%']),
        [''],['GENDER ANALYSIS'],['Gender','Count','Percentage'],['Male',a.byG.Male,(a.byG.Male/a.total*100).toFixed(1)+'%'],['Female',a.byG.Female,(a.byG.Female/a.total*100).toFixed(1)+'%'],
        [''],['AGE GROUP DISTRIBUTION'],['Age Group','Count','Percentage'],...a.byAg.map(([g,c])=>[g,c,(c/a.total*100).toFixed(1)+'%']),
        [''],['REGISTRATION TYPES'],['Type','Count','Percentage'],...Object.entries(a.byRt).map(([t,c])=>[t,c,(c/a.total*100).toFixed(1)+'%']),
        [''],['TOP CITIES'],['City','Count'],...a.byCity.map(([c,n])=>[c,n])
    ];
    const ws1=XLSX.utils.aoa_to_sheet(summary);
    ws1['!cols']=[{wch:28},{wch:14},{wch:14}];
    ws1['!merges']=[{s:{r:0,c:0},e:{r:0,c:2}},{s:{r:1,c:0},e:{r:1,c:2}}];
    XLSX.utils.book_append_sheet(wb,ws1,'📊 Summary');

    // Sheet 2: Full Member Data
    const headers=['Code','Name','Father','Grandfather','Christian Name (የክርስትና ስም)','Gender','Age Group','Section','Status','Reg Type','Member Type','Phone','Alt Phone','Guardian','Guardian Phone','City','Sub City','Woreda','Education','Profession','Registered'];
    const rows=data.map(m=>[m.member_code,m.student_name,m.father_name,m.grandfather_name,m.baptismal_name,m.gender,fmtAge(m.age_group),m.current_section,m.status,m.registration_type,m.member_type,m.phone_number,m.alt_phone_number,m.guardian_name,m.guardian_phone1,m.city,m.sub_city,m.woreda,m.education_level,m.work_profession,m.created_at?fmtDate(m.created_at):''].map(v=>v||''));
    const ws2=XLSX.utils.aoa_to_sheet([headers,...rows]);
    ws2['!cols']=headers.map(h=>({wch:h.length<8?12:18}));
    ws2['!autofilter']={ref:'A1:U1'};
    XLSX.utils.book_append_sheet(wb,ws2,'📋 All Members');

    // Sheet 3: By Section
    const ws3=XLSX.utils.aoa_to_sheet([['Section','Members','Percentage'],...a.bySec.map(([s,c])=>[s,c,(c/a.total*100).toFixed(1)+'%'])]);
    ws3['!cols']=[{wch:25},{wch:12},{wch:12}];
    XLSX.utils.book_append_sheet(wb,ws3,'📌 Sections');

    XLSX.writeFile(wb,'<?= MEMBER_CODE_PREFIX ?>_Members_Report_'+new Date().toISOString().slice(0,10)+'.xlsx');
    toast('Professional Excel exported with 3 sheets + analytics','s');
}

// ── PROFESSIONAL PDF (Print-based — full Amharic support) ──
function exportPDFPro(data){
    const a=buildAnalyticsData(data);
    const isFiltered=filteredMembers.length>0&&filteredMembers.length!==allMembers.length;
    const w=window.open('','_blank','width=1100,height=900');
    w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title><?= SCHOOL_NAME_SHORT ?> Member Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    @page{size:A4 landscape;margin:8mm 10mm}
    @media print{.no-print{display:none!important}body{-webkit-print-color-adjust:exact;print-color-adjust:exact}table{page-break-inside:auto}tr{page-break-inside:avoid}thead{display:table-header-group}}
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Noto Sans Ethiopic',Segoe UI,Calibri,sans-serif;font-size:9pt;color:#1e293b}
    .toolbar{padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;gap:8px;align-items:center}
    .toolbar button,.toolbar a{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:.15s}
    .btn-g{background:#16a34a;color:white}.btn-g:hover{background:#15803d}
    .btn-b{background:#2563eb;color:white}.btn-b:hover{background:#1d4ed8}
    .btn-o{background:#f1f5f9;color:#475569}.btn-o:hover{background:#e2e8f0}
    .header{background:linear-gradient(135deg,#16a34a,#0d9488);color:white;padding:14px 20px;margin-bottom:8px}
    .header h1{font-size:15pt;margin:0 0 2px}.header .meta{font-size:8pt;color:#d1fae5}
    .summary{display:flex;flex-wrap:wrap;gap:6px;padding:6px 20px 12px}
    .pill{padding:5px 14px;border-radius:8px;font-size:9pt;background:#f0fdf4;border:1px solid #d1fae5}
    .pill b{color:#16a34a;font-size:12pt}
    .sec-title{font-size:9pt;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin:10px 0 4px 0;padding-bottom:3px;border-bottom:2px solid}
    .s1{color:#16a34a;border-color:#16a34a}.s2{color:#8b5cf6;border-color:#8b5cf6}.s3{color:#0891b2;border-color:#0891b2}.s4{color:#d97706;border-color:#d97706}
    .analytics{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 10px;padding:0 4px}
    .analytics table{flex:1;min-width:160px;border-collapse:collapse;font-size:8pt}
    .analytics th{background:#16a34a;color:white;padding:4px 8px;text-align:left;font-size:7pt;text-transform:uppercase;letter-spacing:.3px}
    .analytics td{padding:3px 8px;border-bottom:1px solid #e7e7e7;font-size:8pt}.analytics tr:nth-child(even) td{background:#f0fdf4}
    table.main{width:100%;border-collapse:collapse;margin-top:6px}
    table.main th{background:#16a34a;color:white;font-size:7.5pt;padding:5px;text-align:left;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
    table.main td{padding:4px 5px;font-size:8pt;border-bottom:1px solid #e7e7e7}
    table.main tr:nth-child(even) td{background:#f8fdf9}
    table.main tr:hover td{background:#ecfdf5}
    .status{padding:2px 6px;border-radius:8px;font-size:7pt;font-weight:600}
    .s-active{background:#d1fae5;color:#065f46}.s-warning{background:#fef3c7;color:#92400e}.s-inactive{background:#fee2e2;color:#991b1b}
    .footer{text-align:center;padding:8px;font-size:7pt;color:#94a3b8;border-top:2px solid #16a34a;margin-top:10px}
    </style><link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
</head><body>
    <div class="toolbar no-print"><button class="btn-g" onclick="window.print()">🖨️ Print / Save as PDF</button><button class="btn-o" onclick="window.close()">← Close</button><span style="color:#64748b;font-size:11px;margin-left:8px">Tip: In print dialog, select "Save as PDF"</span></div>
    <div class="header"><h1><?= SCHOOL_NAME_SHORT ?> <?= SCHOOL_TYPE ?> — Member Report</h1>
    <div class="meta">${isFiltered?'Filtered: '+data.length+' of '+allMembers.length+' members':'Complete Report: '+data.length+' members'} | Generated: ${genStamp()}</div></div>
    <div class="summary">
    <div class="pill"><b>${a.total}</b> Total</div>
    <div class="pill"><b>${a.bySt.active}</b> Active</div>
    <div class="pill"><b>${a.bySt.warning}</b> Warning</div>
    <div class="pill"><b>${a.bySt.inactive}</b> Inactive</div>
    <div class="pill"><b>${a.byG.Male}</b> Male</div>
    <div class="pill"><b>${a.byG.Female}</b> Female</div>
    <div class="pill"><b>${a.bySec.length}</b> Sections</div>
    </div>
    <div class="analytics">
    <table><thead><tr><th colspan="3" style="background:#8b5cf6">Section Distribution</th></tr><tr><th>Section</th><th>Count</th><th>%</th></tr></thead><tbody>${a.bySec.slice(0,10).map(([s,c])=>'<tr><td>'+esc(s)+'</td><td style="font-weight:bold">'+c+'</td><td>'+(c/a.total*100).toFixed(1)+'%</td></tr>').join('')}</tbody></table>
    <table><thead><tr><th colspan="3" style="background:#0891b2">Age Groups</th></tr><tr><th>Group</th><th>Count</th><th>%</th></tr></thead><tbody>${a.byAg.map(([g,c])=>'<tr><td>'+g+'</td><td style="font-weight:bold">'+c+'</td><td>'+(c/a.total*100).toFixed(1)+'%</td></tr>').join('')}</tbody></table>
    <table><thead><tr><th colspan="3" style="background:#d97706">Registration</th></tr><tr><th>Type</th><th>Count</th><th>%</th></tr></thead><tbody>${Object.entries(a.byRt).map(([t,c])=>'<tr><td>'+t+'</td><td style="font-weight:bold">'+c+'</td><td>'+(c/a.total*100).toFixed(1)+'%</td></tr>').join('')}</tbody></table>
    ${a.byCity.length?'<table><thead><tr><th colspan="2" style="background:#ec4899">Top Cities</th></tr><tr><th>City</th><th>Count</th></tr></thead><tbody>'+a.byCity.slice(0,8).map(([c,n])=>'<tr><td>'+esc(c)+'</td><td style="font-weight:bold">'+n+'</td></tr>').join('')+'</tbody></table>':''}
    </div>
    <div class="sec-title s1">Complete Member List</div>
    <table class="main"><thead><tr><th>#</th><th>Code</th><th>Full Name</th><th>Father</th><th>Gender</th><th>Age</th><th>Section</th><th>Status</th><th>Reg Type</th><th>Phone</th><th>City</th><th>Sub City</th><th>Education</th></tr></thead><tbody>
    ${data.map((m,i)=>{const sc=m.status==='active'?'s-active':(m.status==='warning'?'s-warning':'s-inactive');return'<tr><td style="color:#9ca3af">'+(i+1)+'</td><td style="font-family:monospace;font-size:7.5pt">'+esc(m.member_code||'')+'</td><td style="font-weight:600">'+esc(m.student_name||'')+'</td><td>'+esc(m.father_name||'')+'</td><td>'+(m.gender==='male'?'M':'F')+'</td><td>'+fmtAge(m.age_group)+'</td><td>'+esc(m.current_section||'—')+'</td><td><span class="status '+sc+'">'+(m.status||'')+'</span></td><td>'+esc(m.registration_type||'')+'</td><td>'+esc(m.phone_number||'')+'</td><td>'+esc(m.city||'')+'</td><td>'+esc(m.sub_city||'')+'</td><td>'+esc(m.education_level||'')+'</td></tr>';}).join('')}
    </tbody></table>
    <div class="footer"><?= SCHOOL_NAME ?> — Confidential Report — ${genStamp()} — ${data.length} members</div>
    <script>setTimeout(()=>window.print(),600)<\/script></body></html>`);
    w.document.close();
    toast('Print dialog opening — choose "Save as PDF"','s');
}

// ── PROFESSIONAL WORD ──
function exportWordPro(data){
    const a=buildAnalyticsData(data);
    const isFiltered=filteredMembers.length>0&&filteredMembers.length!==allMembers.length;
    const html=`<html><head><meta charset="utf-8"><style>
    @page{margin:15mm 12mm;size:A4}body{font-family:'Noto Sans Ethiopic',Segoe UI,Arial,sans-serif;color:#1f2937;font-size:10pt;line-height:1.5}
    .header{background:linear-gradient(135deg,#16a34a,#0d9488);color:white;padding:18px 22px;border-radius:8px;margin-bottom:14px;page-break-inside:avoid}
    .header h1{margin:0;font-size:16pt;color:white;letter-spacing:-0.5px}.header p{margin:3px 0 0;color:#94a3b8;font-size:8pt}
    .header .date{color:#64748b;font-size:7pt;margin-top:6px}
    .stats{display:flex;gap:8px;margin-bottom:14px;page-break-inside:avoid}
    .stat-box{flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;text-align:center}
    .stat-box .num{font-size:18pt;font-weight:bold;color:#111827}.stat-box .lbl{font-size:7pt;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px}
    .section-title{font-size:10pt;font-weight:700;text-transform:uppercase;letter-spacing:1px;padding-bottom:4px;border-bottom:2px solid;margin:14px 0 6px;page-break-after:avoid}
    .st-primary{color:#06b6d4;border-color:#06b6d4}.st-purple{color:#8b5cf6;border-color:#8b5cf6}
    .st-green{color:#10b981;border-color:#10b981}.st-amber{color:#f59e0b;border-color:#f59e0b}.st-pink{color:#ec4899;border-color:#ec4899}
    table{width:100%;border-collapse:collapse;font-size:8pt;margin-bottom:10px;page-break-inside:auto}
    th{background:#16a34a;color:white;padding:6px 8px;text-align:left;font-size:7pt;text-transform:uppercase;letter-spacing:0.5px}
    td{padding:5px 8px;border-bottom:1px solid #f3f4f6}.alt{background:#f8fafc}
    .mini-table th{font-size:6.5pt;padding:4px 6px}.mini-table td{padding:3px 6px;font-size:7.5pt}
    .two-col{display:flex;gap:12px}.two-col>div{flex:1}
    .bar-row{display:flex;align-items:center;gap:6px;margin-bottom:4px;font-size:7.5pt}
    .bar-label{width:80px;text-align:right;color:#6b7280}.bar-val{font-weight:bold;width:30px;color:#111827}
    .bar-track{flex:1;height:10px;background:#e2e8f0;border-radius:5px;overflow:hidden}.bar-fill{height:100%;border-radius:5px}
    .badge{display:inline-block;padding:1px 6px;border-radius:10px;font-size:6.5pt;font-weight:600}
    .bg-active{background:#d1fae5;color:#065f46}.bg-warning{background:#fef3c7;color:#92400e}.bg-inactive{background:#fee2e2;color:#991b1b}
    .footer{margin-top:20px;padding-top:6px;border-top:2px solid #16a34a;color:#9ca3af;font-size:7pt;text-align:center}
    </style><link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
</head><body>
    <div class="header"><h1>⛪ <?= SCHOOL_NAME_SHORT ?> <?= SCHOOL_TYPE ?> — Member Report</h1>
    <p>${isFiltered?'Filtered Report: '+data.length+' of '+allMembers.length+' members':'Complete Report: '+data.length+' members'}</p>
    <div class="date">Generated: ${genStamp()}</div></div>

    <div class="stats">
    <div class="stat-box"><div class="num" style="color:#06b6d4">${a.total}</div><div class="lbl">Total Members</div></div>
    <div class="stat-box"><div class="num" style="color:#10b981">${a.bySt.active}</div><div class="lbl">Active</div></div>
    <div class="stat-box"><div class="num" style="color:#f59e0b">${a.bySt.warning}</div><div class="lbl">Warning</div></div>
    <div class="stat-box"><div class="num" style="color:#ef4444">${a.bySt.inactive}</div><div class="lbl">Inactive</div></div>
    <div class="stat-box"><div class="num" style="color:#8b5cf6">${a.byG.Male}</div><div class="lbl">Male</div></div>
    <div class="stat-box"><div class="num" style="color:#ec4899">${a.byG.Female}</div><div class="lbl">Female</div></div>
    </div>

    <div class="two-col">
    <div><div class="section-title st-purple">Section Distribution</div>
    ${a.bySec.slice(0,10).map(([s,c])=>{const pct=a.total>0?(c/a.total*100).toFixed(0):0;return`<div class="bar-row"><span class="bar-label">${esc(s)}</span><span class="bar-val">${c}</span><div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:#8b5cf6"></div></div><span style="font-size:6pt;color:#6b7280;width:28px">${pct}%</span></div>`;}).join('')}</div>
    <div><div class="section-title st-green">Age Group Analysis</div>
    <table class="mini-table"><tr><th>Age Group</th><th>Count</th><th>Share</th></tr>
    ${a.byAg.map(([g,c],i)=>`<tr${i%2?' class="alt"':''}><td>${g}</td><td style="font-weight:bold">${c}</td><td>${(c/a.total*100).toFixed(1)}%</td></tr>`).join('')}</table>
    <div class="section-title st-amber">Registration Types</div>
    <table class="mini-table"><tr><th>Type</th><th>Count</th><th>Share</th></tr>
    ${Object.entries(a.byRt).map(([t,c],i)=>`<tr${i%2?' class="alt"':''}><td>${t}</td><td style="font-weight:bold">${c}</td><td>${(c/a.total*100).toFixed(1)}%</td></tr>`).join('')}</table></div></div>

    ${a.byCity.length?`<div class="section-title st-pink">Top Cities</div>
    <div class="two-col">${a.byCity.slice(0,5).map(([c,n])=>`<div class="bar-row"><span class="bar-label">${esc(c)}</span><span class="bar-val">${n}</span><div class="bar-track"><div class="bar-fill" style="width:${a.total>0?(n/a.byCity[0][1]*100).toFixed(0):0}%;background:#ec4899"></div></div></div>`).join('')}${a.byCity.length>5?a.byCity.slice(5,10).map(([c,n])=>`<div class="bar-row"><span class="bar-label">${esc(c)}</span><span class="bar-val">${n}</span><div class="bar-track"><div class="bar-fill" style="width:${a.total>0?(n/a.byCity[0][1]*100).toFixed(0):0}%;background:#ec4899"></div></div></div>`).join(''):''}</div>`:''}

    <div class="section-title st-primary" style="margin-top:16px">Complete Member Data</div>
    <table><thead><tr><th>#</th><th>Code</th><th>Name</th><th>Father</th><th>Gender</th><th>Age</th><th>Section</th><th>Status</th><th>Reg</th><th>Phone</th><th>City</th></tr></thead><tbody>
    ${data.map((m,i)=>`<tr${i%2?' class="alt"':''}>
    <td style="color:#9ca3af">${i+1}</td>
    <td><span class="badge" style="background:#e0f2fe;color:#0369a1">${esc(m.member_code||'—')}</span></td>
    <td style="font-weight:600">${esc(m.student_name||'')}</td>
    <td>${esc(m.father_name||'')}</td>
    <td>${m.gender==='male'?'♂':'♀'}</td>
    <td>${fmtAge(m.age_group)}</td>
    <td>${esc(m.current_section||'—')}</td>
    <td><span class="badge bg-${m.status==='active'?'active':(m.status==='warning'?'warning':'inactive')}">${(m.status||'—').toUpperCase()}</span></td>
    <td>${esc(m.registration_type||'—')}</td>
    <td style="font-size:7pt">${esc(m.phone_number||'—')}</td>
    <td>${esc(m.city||'—')}</td></tr>`).join('')}
    </tbody></table>
    <div class="footer"><?= SCHOOL_NAME ?> — Confidential Report — Generated ${genStamp()} — Total: ${data.length} members</div>
    </body></html>`;
    dlFile(html,'<?= MEMBER_CODE_PREFIX ?>_Members_Report_'+new Date().toISOString().slice(0,10)+'.doc','application/msword');
    toast('Professional Word report exported with analytics','s');
}

// ── CUSTOM REPORT GENERATOR ──
function genCustomReport(){
    const tp=document.getElementById('crType').value,fmt=document.getElementById('crFmt').value;
    const total=Number(memberStats.total)||0;
    const a={total:total,bySt:{active:Number(memberStats.active)||0,warning:Number(memberStats.warning)||0,inactive:Number(memberStats.inactive)||0},byG:{Male:Number(memberStats.male)||0,Female:Number(memberStats.female)||0},bySec:secDist.map(item=>[item.s||'Unassigned',Number(item.c)||0]),byRt:{Waiting:Number(memberStats.pending)||0,Transfer:Number(memberStats.transfer)||0,Direct:Number(memberStats.direct)||0}};
    let h,r,fn,title;const pct=c=>a.total>0?(c/a.total*100).toFixed(1)+'%':'0.0%';
    switch(tp){
        case'sections':h=['Section','Count','Percentage','Visualization'];title='Section Distribution Report';
            r=a.bySec.map(([s,c])=>[s,c,pct(c),'█'.repeat(a.total>0?Math.round(c/a.total*20):0)]);fn='Sections';break;
        case'gender':h=['Gender','Count','Percentage'];title='Gender Analysis Report';
            r=[['Male',a.byG.Male,pct(a.byG.Male)],['Female',a.byG.Female,pct(a.byG.Female)]];fn='Gender';break;
        case'registration':h=['Type','Count','Percentage'];title='Registration Types Report';
            r=Object.entries(a.byRt).map(([t,c])=>[t,c,pct(c)]);fn='Registration';break;
        default:exportData(fmt);return;
    }
    if(fmt==='pdf'){
        const{jsPDF}=window.jspdf;const doc=new jsPDF('p','mm','a4');
        const pw=doc.internal.pageSize.getWidth();
        let y=pdfHeader(doc,title,`${a.total} total members analyzed`);
        pdfStatBox(doc,14,y,(pw-32)/3,'Total Members',a.total,PDF_COLORS.primary);
        pdfStatBox(doc,14+(pw-32)/3+4,y,(pw-32)/3,'Active',a.bySt.active,PDF_COLORS.ok);
        pdfStatBox(doc,14+((pw-32)/3+4)*2,y,(pw-32)/3,'Sections',a.bySec.length,PDF_COLORS.secondary);
        y+=25;
        doc.autoTable({startY:y,margin:{left:14,right:14},head:[h.slice(0,3)],body:r.map(x=>x.slice(0,3)),
            styles:{fontSize:8,cellPadding:3,lineColor:[226,232,240],lineWidth:0.2},
            headStyles:{fillColor:[22,163,74],textColor:255,fontStyle:'bold'},alternateRowStyles:{fillColor:[248,250,252]}});
        pdfFooter(doc);doc.save('<?= MEMBER_CODE_PREFIX ?>_'+fn+'_Report.pdf');
    }else if(fmt==='excel'){
        const wb=XLSX.utils.book_new();
        const ws=XLSX.utils.aoa_to_sheet([['<?= SCHOOL_NAME_SHORT ?> Sunday School — '+title],['Generated: '+genStamp()],[''],[h],...r.map(x=>[x])]);
        ws['!cols']=h.map(()=>({wch:20}));ws['!merges']=[{s:{r:0,c:0},e:{r:0,c:h.length-1}}];
        XLSX.utils.book_append_sheet(wb,ws,fn);XLSX.writeFile(wb,'<?= MEMBER_CODE_PREFIX ?>_'+fn+'_Report.xlsx');
    }else if(fmt==='word'){
        const html=`<html><head><meta charset="utf-8"><style>body{font-family:Segoe UI,Arial;font-size:10pt;color:#1f2937}
        .header{background:linear-gradient(135deg,#16a34a,#0d9488);color:white;padding:16px 20px;border-radius:8px;margin-bottom:12px}
        .header h1{margin:0;font-size:14pt;color:#06b6d4}.header p{margin:3px 0;color:#94a3b8;font-size:8pt}
        table{width:100%;border-collapse:collapse;margin-top:10px}th{background:#06b6d4;color:white;padding:6px 10px;text-align:left;font-size:8pt}
        td{padding:5px 10px;border-bottom:1px solid #e5e7eb;font-size:9pt}tr:nth-child(even){background:#f8fafc}
        .footer{margin-top:15px;padding-top:5px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:7pt;text-align:center}
        </style><link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
</head><body><div class="header"><h1>⛪ ${title}</h1><p>${allMembers.length} members analyzed • ${genDate()}</p></div>
        <table><tr>${h.slice(0,3).map(x=>'<th>'+x+'</th>').join('')}</tr>
        ${r.map((x,i)=>'<tr'+(i%2?' style="background:#f8fafc"':'')+'>'+x.slice(0,3).map(c=>'<td>'+esc(c+'')+'</td>').join('')+'</tr>').join('')}</table>
        <div class="footer"><?= SCHOOL_NAME ?> — Generated ${genStamp()}</div></body></html>`;
        dlFile(html,'<?= MEMBER_CODE_PREFIX ?>_'+fn+'_Report.doc','application/msword');
    }
    toast(fn+' report generated as '+fmt.toUpperCase(),'s');
}

// HELPERS
/* ─── Academic Year & Semester management (school_admin) ─── */
<?php
// Editable date suggestions per EC year, from the national calendar pattern:
// semester 1 starts Meskerem 16 (≈ Sep 26), the year ends Sene 30 (≈ Jul 7).
// Computed with the real converter server-side so JS never re-implements the
// Ethiopian calendar. The admin adjusts when the Ministry publishes exact dates.
$__ecDefaults = [];
try {
    $__curEc = (int)ethio_date_format($today, 'Y');
    foreach (range($__curEc - 2, $__curEc + 6) as $__ey) {
        $__ecDefaults[(string)$__ey] = [
            'start' => ethiopian_to_gregorian($__ey, 1, 16)->format('Y-m-d'),
            'end'   => ethiopian_to_gregorian($__ey, 12, 30)->format('Y-m-d'),
        ];
    }
} catch (Throwable $__e) { $__ecDefaults = []; }
?>
const EC_YEAR_DEFAULTS=<?= json_encode($__ecDefaults) ?>;
window._yearData={};window._termData={};window._termYearId=0;
async function loadYears(){
    const tb=document.getElementById('yearBody');if(!tb)return;
    try{
        const r=await fetch('/admin/api_education.php?action=get_academic_years',{credentials:'same-origin'});const d=await r.json();
        if(d.status==='success'){
            const y=d.years||[];window._yearData={};y.forEach(x=>window._yearData[x.id]=x);
            updateRolloverHint(y);
            tb.innerHTML=y.length?y.map(x=>{
                const st=(x.status||(x.is_current==1?'active':'upcoming'));
                const badge=st==='active'?'<span class="bg" style="background:rgba(16,185,129,.15);color:var(--ok)">Active</span>':(st==='closed'?'<span class="bg" style="background:rgba(128,128,128,.15);color:var(--dim)">Closed</span>':'<span class="bg" style="background:rgba(59,130,246,.15);color:#60a5fa">Upcoming</span>');
                let acts=`<button class="btn bo bs" onclick="viewTerms(${parseInt(x.id)})" title="Semesters"><i class="fa-solid fa-calendar-week"></i></button> <button class="btn bo bs" onclick="editYearById(${parseInt(x.id)})" title="Edit"><i class="fa-solid fa-pen"></i></button>`;
                if(st!=='active'){acts+=` <button class="btn bo bs" style="color:var(--ok)" onclick="setCurrentYear(${parseInt(x.id)})" title="${st==='closed'?'Reopen and set active':'Set as active year'}"><i class="fa-solid fa-${st==='closed'?'rotate-left':'check'}"></i></button>`;}
                if(st==='upcoming'){acts+=` <button class="btn bo bs" style="color:var(--bad)" onclick="deleteYear(${parseInt(x.id)})" title="Delete empty year"><i class="fa-solid fa-trash"></i></button>`;}
                return `<tr><td style="font-weight:600" class="amharic">${esc(x.year_name)}</td><td>${esc(String(x.ec_year||'—'))}</td><td style="font-size:.72rem">${esc(x.year_gc||'—')}</td><td style="font-size:.72rem">${fmtDate(x.start_date)}</td><td style="font-size:.72rem">${fmtDate(x.end_date)}</td><td><span class="bg bg-info">${parseInt(x.term_count)||0}</span></td><td>${badge}</td><td style="white-space:nowrap">${acts}</td></tr>`;
            }).join(''):'<tr><td colspan="8" style="text-align:center;padding:1.25rem;color:var(--dim)">No academic years yet. Click "Add Year".</td></tr>';
        }else tb.innerHTML='<tr><td colspan="8" style="text-align:center;padding:1.25rem;color:var(--dim)">Could not load</td></tr>';
    }catch(e){tb.innerHTML='<tr><td colspan="8" style="text-align:center;padding:1.25rem;color:var(--dim)">Error loading years</td></tr>';}
}
/* Year-end reminder (PowerSchool EOY pattern): if the ACTIVE year carries an
   end_date that has passed within the last 30 days, nudge the admin to run the
   Year Rollover. Never runs anything automatically. */
function updateRolloverHint(years){
    const hint=document.getElementById('rolloverHint'),txt=document.getElementById('rolloverHintText');
    if(!hint||!txt)return;
    let show=false,msg='';
    try{
        const act=years.find(x=>(x.status||(x.is_current==1?'active':'upcoming'))==='active');
        if(act&&act.end_date){
            const today=new Date();today.setHours(0,0,0,0);
            const end=new Date(act.end_date+'T00:00:00');
            const days=Math.floor((today-end)/86400000);
            if(days>=0&&days<=30){
                show=true;
                msg='The active year ('+act.year_name+') ended '+fmtDate(act.end_date)+(days===0?' today':' '+days+' day'+(days===1?'':'s')+' ago')+' — run the Year Rollover to start the next school year.';
            }
        }
    }catch(e){}
    txt.textContent=msg;
    hint.style.display=show?'flex':'none';
}
function syncGcFromEc(){
    // GC year is DERIVED from the Ethiopian year (E → GC span E+7 / E+8),
    // NOT taken from the browser's Gregorian clock. Reference only.
    const ec=parseInt(document.getElementById('yearEc').value,10);
    if(!isNaN(ec)&&ec>0){const g=ec+7;document.getElementById('yearGc').value=g+'/'+(g+1);}
}
/* Live feedback on the semester weight pair. This mirrors the server rule so
   the user sees the problem while typing; the server still decides. */
function updateWeightTotal(){
    const s1el=document.getElementById('yearS1Weight'),s2el=document.getElementById('yearS2Weight');
    const msg=document.getElementById('yearWeightMsg');
    if(!s1el||!s2el||!msg)return true;
    const s1=parseFloat(s1el.value),s2=parseFloat(s2el.value);
    if(isNaN(s1)||isNaN(s2)){msg.innerHTML='<span style="color:var(--dim)">Enter both semester weights.</span>';return false;}
    if(s1<0||s2<0||s1>100||s2>100){msg.innerHTML='<span style="color:#f87171"><i class="fa-solid fa-circle-exclamation"></i> Each weight must be between 0 and 100.</span>';return false;}
    const total=Math.round((s1+s2)*100)/100;
    if(total!==100){
        msg.innerHTML='<span style="color:#f87171"><i class="fa-solid fa-circle-exclamation"></i> Total is '+total+'% — it must be exactly 100%.</span>';
        return false;
    }
    msg.innerHTML='<span style="color:#4ade80"><i class="fa-solid fa-circle-check"></i> Total 100% — valid.</span>';
    return true;
}
function openYearModal(){
    const currentEc=<?= (int)ethio_date_format($today, 'Y') ?>;
    let ecYear=currentEc;
    /* Suggest a year name that does NOT already exist. If the current
       Ethiopian year (or a later one) is already saved, suggest the NEXT
       year — otherwise the prefill always collided with the UNIQUE
       year_name constraint and every save after the first one failed. */
    try{
        let maxEc=0;Object.values(window._yearData||{}).forEach(y=>{const e=parseInt(y.ec_year,10);if(!isNaN(e)&&e>maxEc)maxEc=e;});
        if(maxEc>=currentEc){ecYear=maxEc+1;}
    }catch(e){}
    document.getElementById('yearFormId').value=0;
    document.getElementById('yearName').value=ecYear+' ዓ.ም.';
    document.getElementById('yearEc').value=ecYear;
    syncGcFromEc(); // derive GC from EC, not the browser clock
    /* Prefill the dates with the national-calendar suggestion for this EC year
       (Meskerem 16 → Sene 30). Fully editable; clearing them stays allowed. */
    const ecDef=window.EC_YEAR_DEFAULTS?.[String(ecYear)];
    document.getElementById('yearStart').value=ecDef?ecDef.start:'';
    document.getElementById('yearEnd').value=ecDef?ecDef.end:'';
    document.getElementById('yearS1Weight').value=50;
    document.getElementById('yearS2Weight').value=50;
    updateWeightTotal();
    document.getElementById('yearModalTitle').innerHTML='<i class="fa-solid fa-calendar"></i> New Academic Year';
    document.getElementById('yearModal').classList.add('show');
}
function editYearById(id){const y=window._yearData?.[id];if(!y)return;
    document.getElementById('yearFormId').value=y.id;
    document.getElementById('yearName').value=y.year_name||'';
    document.getElementById('yearEc').value=y.ec_year||'';
    document.getElementById('yearGc').value=y.year_gc||'';
    document.getElementById('yearStart').value=y.start_date||'';
    document.getElementById('yearEnd').value=y.end_date||'';
    /* Show what this year is actually stored with, not an assumed 50/50. */
    document.getElementById('yearS1Weight').value=(y.s1_weight_pct!==undefined&&y.s1_weight_pct!==null)?parseFloat(y.s1_weight_pct):50;
    document.getElementById('yearS2Weight').value=(y.s2_weight_pct!==undefined&&y.s2_weight_pct!==null)?parseFloat(y.s2_weight_pct):50;
    updateWeightTotal();
    document.getElementById('yearModalTitle').innerHTML='<i class="fa-solid fa-pen"></i> Edit Academic Year';
    document.getElementById('yearModal').classList.add('show');
}
async function saveYear(){
    const fd=new FormData();fd.append('action','save_academic_year');
    fd.append('id',document.getElementById('yearFormId').value);
    fd.append('year_name',document.getElementById('yearName').value);
    fd.append('ec_year',document.getElementById('yearEc').value);
    fd.append('year_gc',document.getElementById('yearGc').value);
    fd.append('start_date',document.getElementById('yearStart').value);
    fd.append('end_date',document.getElementById('yearEnd').value);
    if(!updateWeightTotal()){toast('Semester weights must total exactly 100%','e');return;}
    fd.append('s1_weight_pct',document.getElementById('yearS1Weight').value);
    fd.append('s2_weight_pct',document.getElementById('yearS2Weight').value);
    fd.append('csrf_token',CSRF);
    try{const r=await fetch('/admin/api_education.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();
    if(d.status==='success'){toast('Academic year saved!','s');(d.warnings||[]).forEach(w=>toast(w,'e'));document.getElementById('yearModal').classList.remove('show');loadYears();}
    else toast(d.message||'Save failed','e');}catch(e){toast('Network error','e');}
}
async function setCurrentYear(id){
    const y=window._yearData?.[id];const st=y?(y.status||(y.is_current==1?'active':'upcoming')):'upcoming';
    if(st==='active'){toast('That year is already the active year','s');return;}
    const reopen=(st==='closed');const phrase=reopen?'REOPEN':'SWITCH';
    const nm=y?y.year_name:'';
    const dc=await dcAsk(reopen?{tier:'high',title:'Reopen the closed year '+nm+'?',object:nm,
        summary:'This year is CLOSED (a past year). Reopening it makes it the ACTIVE year again.',intent:'I want to reopen this past year',
        consequences:['The current active year is closed.','NEW attendance, enrolments and grades will be stamped to '+nm+'.','Everyone sees '+nm+' as the current year in the web dashboard and the mobile app.'],
        keeps:['No records are deleted.','Only do this to correct a mistake.'],phrase:'REOPEN',confirmLabel:'Reopen '+nm}
      :{tier:'high',title:'Make '+nm+' the active year?',object:nm,
        summary:'Switching the active year CLOSES the current year.',intent:'I want to switch the active year',
        consequences:['The current active year is closed and becomes read-only history.','New attendance, enrolments and grades will belong to '+nm+'.','To carry students into the new year use Year Rollover instead.'],
        keeps:['No records are deleted.'],phrase:'SWITCH',confirmLabel:'Switch to '+nm});
    if(!dc)return;
    const fd=new FormData();fd.append('action','set_current_year');fd.append('year_id',id);fd.append('confirm',phrase);fd.append('csrf_token',CSRF);
    try{
        const r=await fetch('/admin/api_education.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();
        if(d.code==='confirm_activate_empty'){
            const dc2=await dcAsk({tier:'high',title:'Activate EMPTY year "'+nm+'"?',object:nm,summary:d.message||'This year has no students or teachers.',intent:'I want to activate an empty year anyway',consequences:['The new active year will be EMPTY — mobile teachers will see no classes and no students.','Attendance preparation will fail until you enrol students.','In most cases you should close this dialog and use Year Rollover instead, which copies every student safely.'],keeps:['No records are deleted.','You can still run Year Rollover afterward to fill the year.'],phrase:'ACTIVATE EMPTY',confirmLabel:'Activate empty year anyway'});
            if(!dc2)return;
            const fd2=new FormData();fd2.append('action','set_current_year');fd2.append('year_id',id);fd2.append('confirm','ACTIVATE EMPTY');fd2.append('csrf_token',CSRF);
            const r2=await fetch('/admin/api_education.php',{method:'POST',body:fd2,credentials:'same-origin'});const d2=await r2.json();
            toast(d2.message||(d2.status==='success'?'Active year changed':'Failed'),d2.status==='success'?'s':'e');
            if(d2.status==='success'){loadYears(); if(window._ayToastUpdate) window._ayToastUpdate();}
            return;
        }
        toast(d.message||(d.status==='success'?'Active year changed':'Failed'),d.status==='success'?'s':'e');
        if(d.status==='success'){loadYears(); if(window._ayToastUpdate) window._ayToastUpdate();}
    }catch(e){toast('Error','e');}
}
async function deleteYear(id){
    const y=window._yearData?.[id];const nm=y?y.year_name:'';
    const dc=await dcAsk({tier:'medium',title:'Delete the upcoming year '+nm+'?',object:nm,summary:'Only a year with NO records can be deleted.',intent:'I want to delete this year',consequences:['The year and its semesters are deleted permanently.','If the year already has any records, the system refuses.'],irreversible:true,phrase:'DELETE YEAR',confirmLabel:'Delete year'});if(!dc)return;
    const fd=new FormData();fd.append('action','delete_year');fd.append('year_id',id);fd.append('csrf_token',CSRF);
    try{const r=await fetch('/admin/api_education.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();
        toast(d.message||(d.status==='success'?'Deleted':'Failed'),d.status==='success'?'s':'e');
        if(d.status==='success')loadYears();
    }catch(e){toast('Error','e');}
}
function toggleYearHelp(){const h=document.getElementById('yearHelp');if(h)h.style.display=(h.style.display==='none'||!h.style.display)?'block':'none';}
/* ─── Year Rollover (styled modal, backed by /admin/tools/year_rollover.php) ─── */
async function openRolloverModal(){
    const m=document.getElementById('rolloverModal');if(!m)return;
    const roc=document.getElementById('roConfirm'); if(roc) roc.value='';
    document.getElementById('roOld').innerHTML='<option>Loading…</option>';
    document.getElementById('roNew').innerHTML='<option>Loading…</option>';
    document.getElementById('roPreview').textContent='Loading…';
    m.classList.add('show');
    try{
        const r=await fetch('/admin/tools/year_rollover.php?action=preview',{credentials:'same-origin'});const d=await r.json();
        if(d.status!=='success'){toast(d.message||'Could not load years','e');return;}
        const years=d.years||[];window._roCurrentId=d.current_id;
        const stTag=x=>{const s=x.status||(x.is_current==1?'active':'upcoming');return s==='active'?'Active':(s==='closed'?'Closed':'Upcoming');};
        const oldOpts=years.map(x=>`<option value="${parseInt(x.id)}" ${parseInt(x.id)===parseInt(d.current_id)?'selected':''}>${esc(x.year_name)} · ${stTag(x)}</option>`).join('');
        // Default "new year" = an Upcoming year that is NOT the one being closed, else blank.
        const newOpts=years.map(x=>{const s=x.status||(x.is_current==1?'active':'upcoming');const sel=(s==='upcoming'&&parseInt(x.id)!==parseInt(d.current_id))?'selected':'';return `<option value="${parseInt(x.id)}" ${sel}>${esc(x.year_name)} · ${stTag(x)}</option>`;}).join('');
        document.getElementById('roOld').innerHTML=oldOpts;
        document.getElementById('roNew').innerHTML=newOpts;
        updateRolloverPreview();
    }catch(e){toast('Network error loading rollover','e');}
}
async function updateRolloverPreview(){
    const oldId=document.getElementById('roOld')?.value;if(!oldId)return;
    document.getElementById('roPreview').textContent='Counting students…';
    try{
        const r=await fetch('/admin/tools/year_rollover.php?action=preview&old_year_id='+encodeURIComponent(oldId),{credentials:'same-origin'});const d=await r.json();
        if(d.status==='success'){document.getElementById('roPreview').innerHTML=`About <b>${parseInt(d.count)||0}</b> active student(s) will move out of the selected year.`;}
    }catch(e){document.getElementById('roPreview').textContent='';}
}
async function runRollover(){
    const oldId=document.getElementById('roOld').value, newId=document.getElementById('roNew').value;
    const mode=(document.querySelector('input[name="roMode"]:checked')||{}).value||'carry_forward';
    if(!oldId||!newId){toast('Choose both years','e');return;}
    if(oldId===newId){toast('The two years must be different','e');return;}
    const sel=id=>{const e=document.getElementById(id);return e&&e.selectedIndex>=0?e.options[e.selectedIndex].text.replace(/\s*·\s*(Active|Upcoming|Closed|Current)\s*$/i,''):'';};
    const oldN=sel('roOld'), newN=sel('roNew'), promote=(mode==='promote');
    const dc=await dcAsk({tier:'high',title:'Run Year Rollover from '+oldN+' to '+newN+'?',object:oldN+'  →  '+newN,
        summary:'Closes the current year and carries every student into the new year in one all-or-nothing step.',intent:'I want to run the year rollover',
        consequences:[oldN+' is CLOSED and becomes read-only history.',newN+' becomes the ACTIVE year for everyone (web and mobile app).',
            promote?'Every active student is PROMOTED one class level in '+newN+'.':'Every active student keeps the same class in '+newN+'.',
            'All current enrolments of '+oldN+' are closed.'],
        keeps:['Grades and attendance of '+oldN+' stay in history.','If any step fails, nothing is changed.'],
        phrase:'ROLLOVER',confirmLabel:'Run Year Rollover'});
    if(!dc)return;
    const btn=document.getElementById('roRunBtn');btn.disabled=true;btn.style.opacity=.6;
    const fd=new FormData();fd.append('action','run');fd.append('old_year_id',oldId);fd.append('new_year_id',newId);fd.append('mode',mode);fd.append('confirm','ROLLOVER');fd.append('csrf_token',CSRF);
    try{
        const r=await fetch('/admin/tools/year_rollover.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();
        toast(d.message||(d.status==='success'?'Rollover complete':'Failed'),d.status==='success'?'s':'e');
        if(d.status==='success'){document.getElementById('rolloverModal').classList.remove('show');loadYears();}
    }catch(e){toast('Network error','e');}
    finally{btn.disabled=false;btn.style.opacity=1;}
}
async function viewTerms(yearId){
    window._termYearId=yearId;const area=document.getElementById('termArea');
    area.innerHTML='<div class="cd" style="text-align:center;padding:1.25rem;color:var(--dim)"><i class="fa-solid fa-spinner fa-spin"></i> Loading semesters...</div>';
    try{const r=await fetch('/admin/api_education.php?action=get_terms&year_id='+yearId,{credentials:'same-origin'});const d=await r.json();
    const terms=d.terms||[];window._termData={};terms.forEach(t=>window._termData[t.id]=t);
    const yn=window._yearData?.[yearId]?.year_name||'';
    /* "Dates suggest now" hint: only for the ACTIVE year, only when today
       falls inside a semester's saved date window. Dates suggest — the
       admin still decides (Banner/Canvas current-term pattern). */
    const isActiveYear=Object.values(window._yearData||{}).some(x=>parseInt(x.id)===parseInt(yearId)&&((x.status||(x.is_current==1?'active':'upcoming'))==='active'));
    const tToday=new Date();tToday.setHours(0,0,0,0);
    area.innerHTML=`<div class="cd"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;flex-wrap:wrap;gap:.5rem"><div class="ct" style="margin:0"><i class="fa-solid fa-calendar-week" style="color:var(--purple)"></i> Semesters — <span class="amharic">${esc(yn)}</span></div><button class="btn bp bs" onclick="openTermModal(0)"><i class="fa-solid fa-plus"></i> Add Semester</button></div>${terms.length?`<div class="tw"><table><thead><tr><th>Name</th><th>#</th><th>Start</th><th>End</th><th>Current</th><th>Actions</th></tr></thead><tbody>${terms.map(t=>{const inWin=isActiveYear&&t.start_date&&t.end_date&&tToday>=new Date(t.start_date+'T00:00:00')&&tToday<=new Date(t.end_date+'T00:00:00');const reop=isActiveYear&&t.is_current!=1&&parseInt(t.is_reopened||0)===1;return `<tr><td class="amharic" style="font-weight:600">${esc(t.term_name)}</td><td>${esc(String(t.term_number||''))}</td><td style="font-size:.72rem">${fmtDate(t.start_date)}</td><td style="font-size:.72rem">${fmtDate(t.end_date)}</td><td>${t.is_current==1?'<span class="bg" style="background:rgba(16,185,129,.15);color:var(--ok)">Current</span>':`<button class="btn bo bs" onclick="setCurrentTerm(${parseInt(t.id)})">Set</button>${inWin?'<div style="font-size:.6rem;color:var(--warn);margin-top:.25rem" title="Today falls inside this semester\'s saved dates">📅 dates suggest now</div>':''}`}${reop?`<div style="margin-top:.25rem"><span class="bg" style="background:rgba(245,158,11,.15);color:var(--warn)">Reopened for corrections</span> <button class="btn bo bs" style="font-size:.62rem" onclick="reopenTerm(${parseInt(t.id)},false)">Close corrections</button></div>`:(isActiveYear&&t.is_current!=1?`<div style="margin-top:.25rem"><button class="btn bo bs" style="font-size:.62rem" title="Let teachers edit this closed semester (e.g. fix a wrong mark) until it is closed again or a new current semester is set" onclick="reopenTerm(${parseInt(t.id)},true)">Reopen for corrections</button></div>`:'')}</td><td style="white-space:nowrap"><button class="btn bo bs" onclick="openTermModal(${parseInt(t.id)})"><i class="fa-solid fa-pen"></i></button> <button class="btn bo bs" style="color:var(--bad)" onclick="deleteTerm(${parseInt(t.id)})"><i class="fa-solid fa-trash"></i></button></td></tr>`}).join('')}</tbody></table></div>`:'<div style="text-align:center;color:var(--dim);font-size:.78rem;padding:1rem">No semesters yet.</div>'}</div>`;
    }catch(e){area.innerHTML='<div class="cd" style="text-align:center;padding:1rem;color:var(--dim)">Error loading semesters</div>';}
}
function openTermModal(termId){
    const t=termId?window._termData?.[termId]:null;const isEdit=!!t;
    document.getElementById('termModal')?.remove();
    const h=`<div class="mo show" id="termModal"><div class="md" style="max-width:460px"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem"><h3 style="margin:0"><i class="fa-solid fa-calendar-week" style="color:var(--purple)"></i> ${isEdit?'Edit':'Add'} Semester</h3><button onclick="document.getElementById('termModal').remove()" style="background:none;border:none;color:var(--dim);font-size:1.1rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button></div><div style="display:flex;flex-direction:column;gap:.55rem"><div style="display:grid;grid-template-columns:2fr 1fr;gap:.55rem"><div><label class="flbl">Semester Name *</label><input id="termName" class="inp amharic" style="width:100%" value="${isEdit?esc(t.term_name):''}" placeholder="e.g. 1ኛ ሴሚስተር"></div><div><label class="flbl">#</label><input type="number" id="termNumber" class="inp" style="width:100%" min="1" max="4" value="${isEdit?(t.term_number||1):1}"></div></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem"><div><label class="flbl">Start</label><input type="date" id="termStart" class="inp" style="width:100%" value="${isEdit&&t.start_date?t.start_date:''}"></div><div><label class="flbl">End</label><input type="date" id="termEnd" class="inp" style="width:100%" value="${isEdit&&t.end_date?t.end_date:''}"></div></div><button class="btn bp" onclick="saveTerm(${isEdit?parseInt(t.id):0})"><i class="fa-solid fa-save"></i> ${isEdit?'Update':'Create'} Semester</button></div></div></div>`;
    document.body.insertAdjacentHTML('beforeend',h);
}
async function saveTerm(termId){
    const name=document.getElementById('termName').value.trim();if(!name)return toast('Semester name required','e');
    const fd=new FormData();fd.append('action','save_term');fd.append('term_id',termId);fd.append('academic_year_id',window._termYearId);
    fd.append('term_name',name);fd.append('term_number',document.getElementById('termNumber').value||1);
    fd.append('start_date',document.getElementById('termStart').value);fd.append('end_date',document.getElementById('termEnd').value);fd.append('csrf_token',CSRF);
    try{const r=await fetch('/admin/api_education.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();
    if(d.status==='success'){toast('Semester saved!','s');document.getElementById('termModal')?.remove();viewTerms(window._termYearId);loadYears();}
    else toast(d.message||'Failed','e');}catch(e){toast('Network error','e');}
}
async function reopenTerm(id,open){const dc=await dcAsk(open?{tier:'medium',title:'Reopen this semester for corrections?',summary:'A closed semester is opened again so marks can be corrected.',intent:'I want to reopen this semester',consequences:['Teachers can change marks of this semester again.','Report cards for this semester may change until you close it again.'],keeps:['No records are deleted.'],phrase:'REOPEN SEMESTER',confirmLabel:'Reopen semester'}:{tier:'low',title:'Close this semester again?',summary:'Marks of this semester become read-only again.',confirmLabel:'Close semester'});if(!dc)return;const fd=new FormData();fd.append('action',open?'reopen_term':'close_term_reopen');fd.append('term_id',id);fd.append('csrf_token',CSRF);try{const r=await fetch('/admin/api_education.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();toast(d.message||(d.status==='success'?'Updated':'Failed'),d.status==='success'?'s':'e');if(d.status==='success')viewTerms(window._termYearId);}catch(e){toast('Error','e');}}
async function setCurrentTerm(id){const dc=await dcAsk({tier:'medium',title:'Make this the current semester?',summary:'The current semester decides where new work is recorded.',intent:'I want to change the current semester',consequences:['New assessments and grades are recorded in this semester.','Teachers, report cards and the mobile app switch to this semester.'],keeps:['No records are deleted or moved.'],phrase:'SET SEMESTER',confirmLabel:'Set current semester'});if(!dc)return;const fd=new FormData();fd.append('action','set_current_term');fd.append('term_id',id);fd.append('csrf_token',CSRF);try{const r=await fetch('/admin/api_education.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();toast(d.message||(d.status==='success'?'Updated':'Failed'),d.status==='success'?'s':'e');if(d.status==='success')viewTerms(window._termYearId);}catch(e){toast('Error','e');}}
async function deleteTerm(id){const dc=await dcAsk({tier:'high',title:'Delete this semester?',summary:'The semester is removed from the academic year.',intent:'I want to delete this semester',consequences:()=>fetch('/admin/api_education.php?action=impact_delete_term&term_id='+id,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{if(!d||d.status!=='success')throw new Error('x');const c=d.counts||{};if(d.blocked)return['This semester has '+c.assessments+' assessment(s), '+c.grades+' grade record(s) and '+c.grade_submissions+' mark list(s). The system will REFUSE to delete it to protect that history.'];return['The semester is deleted permanently.','It has no assessments, grades or mark lists.'];}),fallbackConsequences:['If the semester has assessments, grades or mark lists, the system refuses.','Otherwise the semester is deleted permanently.'],irreversible:true,phrase:'DELETE SEMESTER',confirmLabel:'Delete semester'});if(!dc)return;const fd=new FormData();fd.append('action','delete_term');fd.append('term_id',id);fd.append('confirm_phrase',dc.phrase);fd.append('csrf_token',CSRF);try{const r=await fetch('/admin/api_education.php',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();toast(d.message||(d.status==='success'?'Deleted':'Failed'),d.status==='success'?'s':'e');if(d.status==='success')viewTerms(window._termYearId);}catch(e){toast('Error','e');}}
function esc(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');}
function toast(msg,type){const t=document.getElementById('toast'),i=document.createElement('i'),s=document.createElement('span');t.className='toast '+(type==='s'?'ts':'te')+' show';i.className='fa-solid fa-'+(type==='s'?'check-circle':'exclamation-circle');s.textContent=String(msg||'');t.replaceChildren(i,s);setTimeout(()=>t.classList.remove('show'),3500);}
function dlFile(content,filename,mime){const b=new Blob([content],{type:mime+';charset=utf-8'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=filename;a.click();URL.revokeObjectURL(a.href);}
function stBg(s){const m={active:'bg-ok',warning:'bg-w',inactive:'bg-bad'};return`<span class="bg ${m[s]||'bg-info'}">${s||'—'}</span>`;}
function rgBg(t){const m={waiting:'bg-w',transfer:'bg-info',direct:'bg-ok'};return`<span class="bg ${m[t]||'bg-info'}">${t||'—'}</span>`;}
function rlBg(r){const m={super_admin:'bg-bad',school_admin:'bg-info',hr_dept:'bg-pink',info_dept:'bg-ok',edu_dept:'bg-p',finance_dept:'bg-w',material_dept:'bg-p',mezmur_dept:'bg-pink',teacher:'bg-info',attendance_taker:'bg-ok'};return`<span class="bg ${m[r]||'bg-info'}">${(r||'').replace(/_/g,' ')}</span>`;}
function fmtAge(g){const m=<?= json_encode(array_map(static function($sec){return $sec['am'].' ('.$sec['letter'].')';},\App\Services\MemberCategory::sections()), JSON_UNESCAPED_UNICODE) ?>;return m[g]||g||'—';}
function fmtDate(d){if(!d)return'—';if(typeof WBWSCalendar!=='undefined')return WBWSCalendar.formatDate(d,'medium');try{return new Date(d).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}catch(e){return d;}}
// Shared report formatting keeps optional calendar failures out of export flows.
function genStamp(){return typeof WBWSReportFormat!=='undefined'?WBWSReportFormat.timestamp():new Date().toISOString();}
function genDate(){return typeof WBWSReportFormat!=='undefined'?WBWSReportFormat.longDate():new Date().toISOString().slice(0,10);}

// INIT
document.addEventListener('DOMContentLoaded',()=>{
    animateCounters();
    const today=new Date(),wk=new Date(today);wk.setDate(wk.getDate()-7);
    document.getElementById('attTo').value=today.toISOString().slice(0,10);
    document.getElementById('attFrom').value=wk.toISOString().slice(0,10);
    const hash=window.location.hash.replace('#','');const _sp=new URLSearchParams(window.location.search).get('section');if(_sp)nav(_sp);else if(hash)nav(hash);
});
document.querySelectorAll('.mo').forEach(mo=>{mo.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');});});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.mo.show').forEach(m=>m.classList.remove('show'));});
</script>
<script>
// ── Attendance policy card (Attendance alerting — Phase 1) ──
(function(){
  const grid=document.getElementById('attPolicyGrid'), state=document.getElementById('attPolicyState');
  if(!grid) return;
  const FIELDS=[
    ['consecutive_threshold','Standard warning after (sessions)',1,60,1],
    ['prolonged_threshold','Prolonged warning after (sessions)',1,60,1],
    ['critical_threshold','Critical warning after (sessions)',1,60,1],
    ['low_rate_threshold','Low attendance below (%)',0.01,100,0.01],
    ['min_sessions','Minimum sessions before rate counts',1,30,1]
  ];
  let loaded=false;
  function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
  function render(d){
    const can=!!d.can_edit;
    state.textContent=d.available?(can?'Editable by School Admin':'Read-only'):'Not enabled yet (feature flag / sql 071) — showing defaults';
    grid.innerHTML=['edu','mezmur'].map(dep=>{
      const p=d.policies[dep]||{};
      let h='<div style="border:1px solid var(--border,#334155);border-radius:12px;padding:.7rem"><div style="font-weight:700;color:var(--bright);margin-bottom:.5rem">'+esc(p.label||dep)+'</div>';
      FIELDS.forEach(f=>{h+='<div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.4rem"><label class="flbl" style="margin:0;flex:1">'+esc(f[1])+'</label><input type="number" class="inp" style="width:90px" data-pol="'+dep+'" data-f="'+f[0]+'" min="'+f[2]+'" max="'+f[3]+'" step="'+f[4]+'" value="'+esc(p[f[0]])+'"'+(can?'':' disabled')+'></div>';});
      h+='<div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.5rem"><label class="flbl" style="margin:0;flex:1">Auto-deactivate at critical stage</label><input type="checkbox" data-pol="'+dep+'" data-f="auto_deactivate"'+(p.auto_deactivate?' checked':'')+(can?'':' disabled')+'></div>';
      if(can) h+='<button class="btn bp bs" style="width:100%" onclick="saveAttPolicy(\''+dep+'\')"><i class="fa-solid fa-floppy-disk"></i> Save '+esc(p.label||dep)+'</button>';
      h+='</div>'; return h;}).join('');
  }
  window.loadAttPolicy=function(){
    fetch('/admin/api_attendance_policy.php?action=get',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(d.status!=='success'){grid.innerHTML='<div style="font-size:.75rem;color:var(--danger,#f87171)">'+esc(d.message||'Unavailable')+'</div>';return;}
      loaded=true; render(d);
    }).catch(()=>{grid.innerHTML='<div style="font-size:.75rem">Could not load the attendance policy.</div>';});
  };
  window.saveAttPolicy=async function(dep){
    const dc=await dcAsk({tier:'medium',title:'Save the attendance alert rules for '+dep+'?',summary:'These thresholds decide when members get attendance warnings.',intent:'I want to change the alert rules',consequences:['Warnings, risk levels and alerts for every member of this department are recalculated with the new rules.','Members may enter or leave the warning lists right away.'],keeps:['Attendance records themselves are not changed.','You can change the rules back at any time.'],phrase:'SAVE POLICY',confirmLabel:'Save alert rules'});
    if(!dc)return;
    const body={action:'save',department:dep,csrf_token:CSRF};
    grid.querySelectorAll('[data-pol="'+dep+'"]').forEach(el=>{body[el.getAttribute('data-f')]=el.type==='checkbox'?(el.checked?1:0):el.value;});
    fetch('/admin/api_attendance_policy.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(body)})
      .then(r=>r.json()).then(d=>{toast(d.message||(d.status==='success'?'Saved':'Error'),d.status==='success'?'s':'e'); if(d.status==='success') render(Object.assign({can_edit:true,available:true},d));})
      .catch(()=>toast('Network error','e'));
  };
  const nav=document.querySelector('[data-section="attendance"]');
  if(nav) nav.addEventListener('click',()=>{ if(!loaded) setTimeout(loadAttPolicy,150); });
  if(document.getElementById('section-attendance')&&document.getElementById('section-attendance').classList.contains('active')) loadAttPolicy();
})();
</script>
<?php include __DIR__ . '/../components/account_link.php'; ?>
<script>
// Explain a permission bounce (access_control.php → index.php → dashboard.php?error=access_denied).
(function () {
    try {
        const u = new URL(window.location.href);
        if (u.searchParams.get('error') === 'access_denied') {
            if (typeof toast === 'function') toast('You do not have permission to open that page.', 'e'); else alert('You do not have permission to open that page.');
            u.searchParams.delete('error');
            history.replaceState(null, '', u.toString());
        }
    } catch (e) { /* decorative */ }
})();
</script>
</body></html>
