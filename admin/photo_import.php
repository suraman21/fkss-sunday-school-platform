<?php
/**
 * Z2-Z5 — ZIP airlock import page.
 *
 * Self-contained maroon/gold page (no shared header/footer per codebase
 * convention). Lets an admin upload a mobile-exported ZIP, shows live
 * progress as the server ingests, and lists recent import batches with
 * counts and signature warnings.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_control.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
// access_control.php auto-runs auth + role gating on include; if we reach
// here the user is logged in. The ACL map in access_control.php already
// gates photo_import.php to the allowed roles, but we double-check in
// case the file is reached through a misnamed path.
$userName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Admin';
$role = (string)($_SESSION['admin_role'] ?? '');
$canImport = in_array($role, ['super_admin','school_admin','info_dept','hr_dept','photo_importer'], true);
if (!$canImport) { http_response_code(403); exit('Access denied.'); }

$schoolName = defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'FKSS';
$title = 'Import Photos (ZIP)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous">
<style>
*{box-sizing:border-box;}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;background:#faf6f2;color:#1e1b19;-webkit-font-smoothing:antialiased;}
:root{--maroon:#600000;--gold:#F0C000;--ink:#1e1b19;--muted:#6b6360;--surface:#fff;--border:rgba(96,0,0,.12);}
.nav{background:var(--maroon);color:#fff;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(96,0,0,.2);}
.nav a{color:var(--gold);text-decoration:none;font-weight:600;}
.nav .user{opacity:.85;font-size:13px;}
.wrap{max-width:960px;margin:24px auto;padding:0 20px;}
.hero{background:linear-gradient(135deg,var(--maroon) 0%,#7b1414 100%);color:#fff;border-radius:14px;padding:24px 28px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 8px 24px rgba(96,0,0,.15);margin-bottom:20px;}
.hero h1{margin:0;font-size:22px;} .hero p{margin:6px 0 0;opacity:.85;font-size:13px;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:16px;}
.card h2{margin:0 0 12px;font-size:16px;color:var(--maroon);}
.drop{border:2px dashed var(--maroon);border-radius:12px;padding:40px 20px;text-align:center;background:rgba(96,0,0,.03);transition:.2s;}
.drop.dragover{background:rgba(240,192,0,.1);border-color:var(--gold);}
.drop i{font-size:48px;color:var(--maroon);opacity:.5;margin-bottom:12px;}
.drop p{margin:4px 0;color:var(--muted);font-size:13px;}
.btn{padding:10px 16px;border-radius:8px;font-weight:600;font-size:13px;border:0;cursor:pointer;text-decoration:none;display:inline-block;}
.btn-primary{background:var(--maroon);color:#fff;}
.btn-primary:disabled{opacity:.5;cursor:not-allowed;}
.btn-gold{background:var(--gold);color:var(--ink);}
.progress{height:8px;background:var(--border);border-radius:4px;overflow:hidden;margin:12px 0;}
.progress>div{height:100%;background:var(--gold);width:0%;transition:width .2s;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);background:rgba(96,0,0,.03);}
.badge{padding:3px 8px;border-radius:8px;font-size:11px;font-weight:700;}
.badge-ok{background:rgba(0,128,0,.12);color:#0a5f0a;}
.badge-warn{background:rgba(240,192,0,.22);color:#7a5a00;}
.badge-err{background:rgba(200,0,0,.1);color:#900;}
.result{margin-top:12px;padding:12px;border-radius:8px;display:none;}
.result.ok{background:rgba(0,128,0,.08);color:#0a5f0a;display:block;}
.result.err{background:rgba(200,0,0,.08);color:#900;display:block;}
ul.errors{margin:8px 0 0 18px;padding:0;font-size:12px;}
.note{font-size:12px;color:var(--muted);line-height:1.5;}
.kv{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px;}
.kv .box{background:rgba(96,0,0,.04);border-radius:8px;padding:10px;text-align:center;}
.kv .n{font-size:20px;font-weight:800;color:var(--maroon);}
.kv .l{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:2px;}
.sig-warn{background:rgba(240,192,0,.1);border-left:3px solid var(--gold);padding:8px 12px;border-radius:4px;font-size:12px;color:#7a5a00;margin-top:10px;}
@media (max-width:700px){.kv{grid-template-columns:repeat(2,1fr);}.hero{flex-direction:column;align-items:flex-start;gap:10px;}}
</style>
</head>
<body>
<nav class="pr-nav nav">
  <div><a href="/admin/dashboard.php"><i class="fa-solid fa-arrow-left"></i> Dashboard</a> &nbsp;·&nbsp; <strong><?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></strong></div>
  <div class="user"><i class="fa-solid fa-user-circle"></i> <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>)</div>
</nav>

<div class="wrap">
  <div class="hero">
    <div>
      <h1><i class="fa-solid fa-file-zipper"></i> Import Photos (ZIP Airlock)</h1>
      <p>Upload a ZIP exported from the FKSS mobile app. Photos flow into the same review queue as online syncs.</p>
    </div>
    <a href="/admin/photo_review.php" class="btn btn-gold"><i class="fa-solid fa-gavel"></i> Review conflicts</a>
  </div>

  <div class="card">
    <h2><i class="fa-solid fa-upload"></i> Upload ZIP</h2>
    <div class="drop" id="drop">
      <i class="fa-solid fa-file-zipper"></i>
      <p><strong>Drag a ZIP here</strong> or click to choose</p>
      <p>Max 200 MB · format: <code>fkss-photos-*.zip</code></p>
      <input type="file" id="file" accept=".zip,application/zip" style="display:none">
      <div style="margin-top:14px">
        <button class="btn btn-primary" id="pick"><i class="fa-solid fa-folder-open"></i> Choose file…</button>
      </div>
    </div>
    <div class="progress" id="prog" style="display:none"><div id="bar"></div></div>
    <div class="result" id="result"></div>
    <p class="note" style="margin-top:12px">
      <i class="fa-solid fa-info-circle"></i>
      ZIPs are extracted server-side. Each photo is validated (size ≤ 2 MB, WebP/JPEG, SHA-256 matches manifest)
      and routed through the same audit + conflict pipeline as online uploads. Duplicate uploads (same client
      operation ID or same file hash from the same operator) are safely skipped.
    </p>
  </div>

  <div class="card">
    <h2><i class="fa-solid fa-clock-rotate-left"></i> Recent imports</h2>
    <div id="recent">Loading…</div>
  </div>
</div>

<script>
const drop = document.getElementById('drop');
const fileInput = document.getElementById('file');
const pick = document.getElementById('pick');
const prog = document.getElementById('prog');
const bar = document.getElementById('bar');
const result = document.getElementById('result');
pick.addEventListener('click', ()=>fileInput.click());
fileInput.addEventListener('change', ()=>{ if (fileInput.files[0]) upload(fileInput.files[0]); });
['dragenter','dragover'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.add('dragover');}));
['dragleave','drop'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.remove('dragover');}));
drop.addEventListener('drop',e=>{const f=e.dataTransfer.files[0];if(f)upload(f);});

async function upload(file) {
  if (!file.name.toLowerCase().endsWith('.zip')) {
    result.className='result err'; result.textContent='Please choose a .zip file.'; return;
  }
  result.className='result'; result.textContent='';
  prog.style.display='block'; bar.style.width='5%';
  const fd = new FormData(); fd.append('zip', file);
  const xhr = new XMLHttpRequest();
  xhr.open('POST', '/admin/api_photo_zip_import.php?action=upload');
  xhr.setRequestHeader('Accept', 'application/json');
  xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  xhr.upload.onprogress = e=>{ if(e.lengthComputable) bar.style.width=(90*e.loaded/e.total + 5)+'%'; };
  xhr.onload = ()=>{
    bar.style.width='100%';
    setTimeout(()=>prog.style.display='none',400);
    try {
      const j = JSON.parse(xhr.responseText);
      if (j.status === 'success') {
        let html = '<strong><i class="fa-solid fa-circle-check"></i> Import complete</strong> — '
          + j.committed + ' accepted, ' + j.conflicts + ' flagged for review, '
          + j.replays + ' duplicates skipped, ' + j.failures + ' failed.';
        if (j.sig_warning) html += '<div class="sig-warn"><i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(j.sig_warning) + '</div>';
        if (j.errors && j.errors.length) html += '<ul class="errors">' + j.errors.map(e=>'<li>'+escapeHtml(e)+'</li>').join('') + '</ul>';
        result.className='result ok'; result.innerHTML=html;
        loadRecent();
      } else if (j.status === 'session_expired' || j.action === 'reload') {
        result.className='result err'; result.textContent='Session expired — reloading to login…';
        setTimeout(()=>location.reload(), 800);
      } else {
        result.className='result err'; result.textContent='Import failed: '+(j.message||'unknown error');
      }
    } catch(e) {
      result.className='result err'; result.textContent='Upload failed (HTTP '+xhr.status+'): '+(xhr.responseText||'').slice(0,300);
    }
  };
  xhr.onerror = ()=>{ result.className='result err'; result.textContent='Network error.'; prog.style.display='none'; };
  xhr.send(fd);
}

function escapeHtml(s){return (s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);}
function humanBytes(n){if(n<1024)return n+' B';if(n<1024*1024)return(n/1024).toFixed(1)+' KB';return(n/1024/1024).toFixed(1)+' MB';}
function fmtDate(s){if(!s)return '—';try{return new Date(s.replace(' ','T')+'Z').toLocaleString();}catch(e){return s;}}

async function loadRecent() {
  const r = await fetch('/admin/api_photo_zip_import.php?action=recent', {credentials:'same-origin', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
  const j = await r.json();
  const root = document.getElementById('recent');
  if (j.status === 'session_expired' || j.action === 'reload') {
    root.innerHTML = '<p class="note">Session expired — reloading to login…</p>';
    setTimeout(()=>location.reload(), 800);
    return;
  }
  if (j.status !== 'success' || !j.batches.length) {
    root.innerHTML = '<p class="note">No imports yet on this server.</p>'; return;
  }
  root.innerHTML = '<table><thead><tr><th>When</th><th>Batch</th><th>Device</th><th>Status</th><th>Photos</th><th>Sig</th></tr></thead><tbody>'+
    j.batches.map(b=>{
      const sig = b.sig_status || ((b.manifest_sig_valid==='1' || b.manifest_sig_valid===1) ? 'verified' : 'unverified');
      const sigBadge = sig === 'verified'
        ? '<span class="badge badge-ok"><i class="fa-solid fa-shield-halved"></i> verified</span>'
        : sig === 'mismatch'
        ? '<span class="badge badge-err"><i class="fa-solid fa-shield-halved"></i> mismatch</span>'
        : '<span class="badge badge-warn"><i class="fa-solid fa-triangle-exclamation"></i> unverified</span>';
      const statusBadge = b.status === 'done'
        ? '<span class="badge badge-ok">done</span>'
        : '<span class="badge badge-err">'+escapeHtml(b.status)+'</span>';
      return '<tr><td>'+fmtDate(b.created_at)+'</td><td><code>'+escapeHtml((b.batch_uuid||'').slice(0,8))+'…</code></td>'
        +'<td><code>'+escapeHtml((b.device_id||'').slice(0,8))+'…</code></td>'
        +'<td>'+statusBadge+'</td>'
        +'<td>'+(b.committed_photos||0)+' / '+(b.total_photos||0)+'</td>'
        +'<td>'+sigBadge+'</td></tr>';
    }).join('')+'</tbody></table>';
}
loadRecent();
</script>
</body>
</html>
