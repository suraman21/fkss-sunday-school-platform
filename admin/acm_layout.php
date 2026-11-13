<?php
/**
 * ACM P0 shared console chrome.
 * Include-only fragment: pages must define('ACM_BOOT', true) first.
 * Reuses the school-admin visual system (super_admin.css classes,
 * theme.php brand variables, Font Awesome) so the console looks
 * like the rest of the admin instead of a foreign product.
 */
if (!defined('ACM_BOOT')) {
    http_response_code(404);
    exit;
}

function acm_head(string $title): void
{
    $assetVer = defined('SSMS_ASSET_VER') ? (string)SSMS_ASSET_VER : 'p0';
    $csrf = function_exists('generateCsrfToken') ? generateCsrfToken() : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="csrf-token" content="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' · Access Control</title>'
        . '<link rel="icon" href="data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><text y=\'.9em\' font-size=\'90\'>🛡️</text></svg>">'
        . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"'
        . ' integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous">'
        . '<link rel="stylesheet" href="/admin/css/super_admin.css?v=' . urlencode($assetVer) . '">'
        . '<link rel="stylesheet" href="/admin/css/acm.css?v=' . urlencode($assetVer) . '">';
    if (!function_exists('_hRgb') && is_file(__DIR__ . '/theme.php')) {
        include __DIR__ . '/theme.php';
    }
    echo '</head>';
}

function acm_chrome_open(string $active, string $title, string $sub, int $openCount = 0): void
{
    $fullName = (string)($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'User');
    $roleLabel = \App\Services\Acm\AcmPolicy::roleLabel((string)($_SESSION['admin_role'] ?? ''));
    $initial = strtoupper(mb_substr($fullName, 0, 1));
    $nav = [
        'overview' => ['acm_overview.php', 'fa-gauge-high', 'Overview'],
        'queue'    => ['acm_queue.php', 'fa-inbox', 'Activation queue'],
        'persons'  => ['acm_person.php', 'fa-users', 'Persons & accounts'],
    ];
    echo '<body><aside class="sb"><div class="brand"><div class="brand-logo"><i class="fa-solid fa-shield-halved"></i></div>'
        . '<div><div class="brand-txt">Access Control</div><div class="brand-sub">ACM Console · P0</div></div></div>'
        . '<nav class="nav-sec"><div class="nav-title">Manage</div><ul class="nav-list">';
    foreach ($nav as $key => $item) {
        $cls = $key === $active ? 'nav-link active' : 'nav-link';
        $badge = ($key === 'queue' && $openCount > 0)
            ? ' <span class="acm-badge">' . (int)$openCount . '</span>' : '';
        echo '<li><a class="' . $cls . '" href="/admin/' . $item[0] . '">'
            . '<i class="fa-solid ' . $item[1] . '"></i><span>' . $item[2] . '</span>' . $badge . '</a></li>';
    }
    echo '</ul></nav><div class="sb-footer"><div class="user-card"><div class="user-av">'
        . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') . '</div><div><div class="user-name">'
        . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '</div><div class="user-role">'
        . htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') . '</div></div></div>'
        . '<a class="nav-link" href="/admin/dashboard.php"><i class="fa-solid fa-arrow-left"></i><span>Back to dashboard</span></a>'
        . '<a class="logout-btn" href="/admin/logout.php" style="margin-top:.5rem"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>'
        . '</div></aside><main class="main"><div class="topbar"><div><h1>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><div class="topbar-sub">'
        . htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') . '</div></div>'
        . '<div class="status-badge"><span class="status-dot"></span> ACM P0</div></div>'
        . '<div class="content">';
}

function acm_chrome_close(): void
{
    echo '</div></main>'
        . '<div id="acmToast" class="acm-toast" role="status" aria-live="polite"></div>'
        . '<script>'
        . 'function acmCsrf(){var m=document.querySelector(\'meta[name="csrf-token"]\');return m?m.content:"";}'
        . 'function acmToast(msg,ok){var t=document.getElementById("acmToast");t.textContent=msg;'
        . 't.className="acm-toast show "+(ok===false?"err":"ok");clearTimeout(t._h);'
        . 't._h=setTimeout(function(){t.className="acm-toast";},3600);}'
        . 'async function acmApi(action,params,method){method=method||"GET";'
        . 'var url="/admin/api_acm.php",opt={credentials:"same-origin",headers:{}};'
        . 'if(method==="GET"){url+="?action="+encodeURIComponent(action);'
        . 'if(params){for(var k in params){url+="&"+encodeURIComponent(k)+"="+encodeURIComponent(params[k]);}}}'
        . 'else{var fd=new FormData();fd.append("action",action);fd.append("csrf_token",acmCsrf());'
        . 'if(params){for(var k2 in params){if(params[k2]!==undefined&&params[k2]!==null){fd.append(k2,params[k2]);}}}'
        . 'opt.method="POST";opt.body=fd;}'
        . 'var r=await fetch(url,opt);var d=await r.json().catch(function(){return{status:"error",message:"Bad response"};});'
        . 'if(d.status!=="success"){throw new Error(d.message||"Request failed");}return d;}'
        . '</script></body></html>';
}
