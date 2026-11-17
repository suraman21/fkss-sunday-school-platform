<?php
/**
 * Photo Review — multi-photographer conflict resolution dashboard.
 *
 * Lists every candidate photo flagged `decision_pending=1` (different
 * operators uploaded photos for the same member). Admin picks Accept
 * (set as current) or Reject (archive) for each. Self-contained page
 * (no include of header/footer since admin shell doesn't expose those
 * as standalone partials) — maroon/gold on-brand with the v2 redesign.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_control.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
// access_control.php auto-runs auth + role gating on include.
$userName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Admin';
$role = (string)($_SESSION['admin_role'] ?? '');
$canDecide = in_array($role, ['super_admin', 'school_admin', 'info_dept', 'hr_dept', 'photo_importer'], true);
if (!$canDecide) { http_response_code(403); exit('Access denied.'); }

$schoolName = defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'FKSS';
$title = 'Photo Review';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous">
<style>
* { box-sizing: border-box; }
body {
  margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI",
    Roboto, "Helvetica Neue", Arial, sans-serif;
  background: #faf6f2; color: #1e1b19; -webkit-font-smoothing: antialiased;
}
:root {
  --maroon: #600000; --gold: #F0C000; --ink: #1e1b19; --muted: #6b6360;
  --surface: #fff; --surface2: #faf6f2; --border: rgba(96,0,0,.12);
}
.pr-nav {
  background: var(--maroon); color: #fff; padding: 12px 20px;
  display: flex; align-items: center; justify-content: space-between;
  box-shadow: 0 2px 8px rgba(96,0,0,.2);
}
.pr-nav a { color: var(--gold); text-decoration: none; font-weight: 600; }
.pr-nav .user { opacity: .85; font-size: 13px; }
.wrap { max-width: 1200px; margin: 24px auto; padding: 0 20px; }
.hero {
  background: linear-gradient(135deg, var(--maroon) 0%, #7b1414 100%);
  color: #fff; border-radius: 14px; padding: 24px 28px;
  display: flex; justify-content: space-between; align-items: center;
  box-shadow: 0 8px 24px rgba(96,0,0,.15); margin-bottom: 20px;
}
.hero h1 { margin: 0; font-size: 22px; }
.hero p { margin: 6px 0 0; opacity: .85; font-size: 13px; }
.btn { padding: 10px 16px; border-radius: 8px; font-weight: 600;
  font-size: 13px; border: 0; cursor: pointer; text-decoration: none;
  display: inline-block; }
.btn-primary { background: var(--gold); color: var(--ink); }
.btn-accept { background: var(--maroon); color: #fff; width: 100%; }
.btn-reject { background: #fff; color: var(--maroon);
  border: 1px solid var(--border); width: 100%; }
.btn:hover:not(:disabled) { filter: brightness(.95); }
.btn:disabled { opacity: .6; cursor: wait; }
.empty {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 12px; padding: 60px 24px; text-align: center; color: var(--muted);
}
.card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 12px; padding: 20px; margin-bottom: 16px;
  display: grid; grid-template-columns: 1fr 280px 280px 180px; gap: 20px;
  align-items: center;
}
.card h3 { margin: 0 0 4px; color: var(--ink); font-size: 17px; }
.meta { font-size: 12px; color: var(--muted); }
.code-badge { background: var(--gold); color: var(--ink);
  padding: 2px 8px; border-radius: 10px; font-weight: 700; margin-right: 6px;
  font-size: 11px; }
.thumb { background: #000; border-radius: 8px; overflow: hidden;
  aspect-ratio: 3/4; display: flex; align-items: center; justify-content: center;
  position: relative; }
.thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.thumb .ph { color: rgba(255,255,255,.5); font-size: 12px; }
.thumb-label {
  text-align: center; font-size: 11px; color: var(--muted); margin-top: 4px;
  text-transform: uppercase; letter-spacing: .06em;
}
.thumb.new .thumb-label { color: var(--maroon); font-weight: 700; }
.actions { display: flex; flex-direction: column; gap: 8px; }
.sub { font-size: 11px; color: var(--muted); margin-top: 4px; }
.badge-cur { background: rgba(240,192,0,.18); color: #7a5a00;
  font-size: 10px; padding: 2px 8px; border-radius: 8px; font-weight: 700;
  display: inline-block; margin-top: 4px; }
.badge-new { background: rgba(96,0,0,.08); color: var(--maroon);
  font-size: 10px; padding: 2px 8px; border-radius: 8px; font-weight: 700;
  display: inline-block; margin-top: 4px; }
.card[data-resolved="1"] { opacity: .4; transition: opacity .3s; }
@media (max-width: 900px) {
  .card { grid-template-columns: 1fr; }
  .thumb { max-width: 220px; }
}
</style>
</head>
<body>
<nav class="pr-nav">
  <div>
    <a href="/admin/dashboard.php"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
    &nbsp;·&nbsp; <strong><?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></strong>
  </div>
  <div class="user">
    <i class="fa-solid fa-user-circle"></i>
    <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?>
    (<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>)
  </div>
</nav>

<div class="wrap">
  <div class="hero">
    <div>
      <h1><i class="fa-solid fa-camera-retro"></i> Photo Review</h1>
      <p>Resolve photos where more than one photographer captured a member. Pick the better shot.</p>
    </div>
    <div style="display:flex;gap:8px;">
      <a class="btn btn-primary" href="/admin/photo_import.php"><i class="fa-solid fa-file-zipper"></i> Import ZIP</a>
      <button class="btn btn-primary" onclick="load()"><i class="fa-solid fa-rotate"></i> Refresh</button>
    </div>
  </div>
  <div id="list">Loading…</div>
</div>

<script>
async function load() {
  try {
    const r = await fetch('/admin/api_photo_candidates.php?action=list-pending', {credentials:'same-origin'});
    const j = await r.json();
    if (j.status === 'session_expired' || j.action === 'reload') {
      document.getElementById('list').innerHTML =
        '<div class="empty">Session expired — reloading to login…</div>';
      setTimeout(()=>location.reload(), 800);
      return;
    }
    if (j.status !== 'success') {
      document.getElementById('list').innerHTML =
        '<div class="empty">Error loading pending reviews: '+esc(j.message||'unknown error')+'</div>';
      return;
    }
    render(j.items || []);
  } catch (e) {
    document.getElementById('list').innerHTML =
      '<div class="empty">Could not reach the server.</div>';
  }
}
function humanBytes(n){if(n<1024)return n+' B';if(n<1024*1024)return(n/1024).toFixed(1)+' KB';return(n/1024/1024).toFixed(2)+' MB';}
function esc(s){return(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);}
function render(items) {
  const root = document.getElementById('list');
  if (!items.length) {
    root.innerHTML = '<div class="empty"><i class="fa-solid fa-circle-check" style="font-size:48px;color:var(--gold);margin-bottom:12px"></i><br>All caught up — no photos pending review.</div>';
    return;
  }
  root.innerHTML = items.map(it => {
    const newThumb = '<div class="thumb new"><img src="'+esc(it.path)+'?r='+it.id+'" alt="" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'<div class=ph>Unavailable</div>\'"></div>' +
      '<div class="thumb-label">New candidate</div>' +
      '<div class="badge-new"><i class="fa-solid fa-user"></i> '+esc(it.operator_name)+' · '+humanBytes(it.byte_size)+'</div>';
    const curThumb = it.has_current
      ? '<div class="thumb"><img src="'+esc(it.current_path)+'?r=c'+it.id+'" alt="" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'<div class=ph>None</div>\'"></div>' +
        '<div class="thumb-label">Current photo</div>' +
        '<div class="badge-cur">Existing</div>'
      : '<div class="thumb"><div class="ph"><i class="fa-solid fa-image" style="font-size:32px;opacity:.5"></i></div></div>' +
        '<div class="thumb-label">Current</div>' +
        '<div class="badge-cur">No photo yet</div>';
    return '<div class="card" data-id="'+it.id+'">' +
      '<div><h3>'+esc(it.member_name)+' '+esc(it.father_name||'')+'</h3>' +
        '<div class="meta"><span class="code-badge">'+esc(it.member_code||'—')+'</span>'+(it.field==='guardian_photo'?'Guardian photo':'Student photo')+'</div>' +
        '<div class="sub">New shot: '+esc(it.taken_at||'unknown')+'</div></div>' +
      '<div>'+curThumb+'</div>' +
      '<div>'+newThumb+'</div>' +
      '<div class="actions">' +
        '<button class="btn btn-accept" onclick="decide('+it.id+',\'accept\',this)"><i class="fa-solid fa-check"></i> Use new</button>' +
        '<button class="btn btn-reject" onclick="decide('+it.id+',\'reject\',this)"><i class="fa-solid fa-xmark"></i> Keep current</button>' +
      '</div></div>';
  }).join('');
}
async function decide(id, decision, btn) {
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
  try {
    const r = await fetch('/admin/api_photo_candidates.php?action=decide',{
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({candidate_id:id, decision:decision}),
    });
    const j = await r.json();
    if (j.status !== 'success') {
      alert(j.message||'Could not save.');
      btn.disabled=false; btn.innerHTML=decision==='accept'?'<i class="fa-solid fa-check"></i> Use new':'<i class="fa-solid fa-xmark"></i> Keep current';
      return;
    }
    const card=btn.closest('.card'); card.setAttribute('data-resolved','1');
    setTimeout(()=>card.remove(),400);
  } catch(e) {
    alert('Network error.'); btn.disabled=false;
    btn.innerHTML=decision==='accept'?'<i class="fa-solid fa-check"></i> Use new':'<i class="fa-solid fa-xmark"></i> Keep current';
  }
}
load();
</script>
</body>
</html>
