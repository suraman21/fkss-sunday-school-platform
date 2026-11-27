<?php
/**
 * ACM console chrome — the school_admin design system (dark + light).
 * Include-only fragment: pages must define('ACM_BOOT', true) first.
 * Theme choice shares school_admin's localStorage key (wbws-theme) so
 * dark/light follows the visitor across both consoles.
 */
if (!defined('ACM_BOOT')) {
    http_response_code(404);
    exit;
}

function acm_head(string $title): void
{
    $assetVer = defined('SSMS_ASSET_VER') ? (string)SSMS_ASSET_VER : 'p0';
    $csrf = function_exists('generateCsrfToken') ? generateCsrfToken() : '';
    // S12: light is the DEFAULT now. An accounts office works in daylight,
    // often on a shared machine or a projector while explaining a case to a
    // parent, and a dark surface is harder to read there. Dark is kept in
    // full: a stored preference still wins, and the toggle still works.
    echo '<!DOCTYPE html><html lang="en" data-theme="light"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="csrf-token" content="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' · Access Control</title>'
        . '<link rel="icon" href="data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><text y=\'.9em\' font-size=\'90\'>🛡️</text></svg>">'
        . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"'
        . ' integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous">'
        . '<link rel="stylesheet" href="/admin/css/acm.css?v=' . urlencode($assetVer) . '">'
        // S12 design layer: extends acm.css (tokens, primitives, the
        // verification card). Loaded after it so it can refine, never fight.
        . '<link rel="stylesheet" href="/admin/css/acm-v2.css?v=' . urlencode($assetVer) . '">'
        . '<link rel="stylesheet" href="/admin/css/mobile.css?v=' . urlencode($assetVer) . '">'
        . '<script>(function(){try{var s=localStorage.getItem("wbws-theme");'
        . 'if(s==="light"||s==="dark"){document.documentElement.setAttribute("data-theme",s);}}catch(e){}})();</script>'
        // The console body is the app shell; mark it so the v2 CSS can scope
        // anything that must not leak into other admin pages.
        ;
    if (!function_exists('_hRgb') && is_file(__DIR__ . '/theme.php')) {
        include __DIR__ . '/theme.php';
    }
    echo '</head>';
}

function acm_status_bg(string $status): string
{
    $map = [
        'submitted' => 'bg-w', 'in_review' => 'bg-info',
        'changes_requested' => 'bg-p', 'approved' => 'bg-ok',
        'code_issued' => 'bg-info', 'grant_verified' => 'bg-info',
        'account_pending_device' => 'bg-p', 'active' => 'bg-ok',
        'expired' => 'bg-w', 'activation_failed' => 'bg-bad',
        'canceled' => 'bg-n', 'revoked' => 'bg-bad',
        'provisioned' => 'bg-ok', 'rejected' => 'bg-bad',
        // S10 staff-enrollment request statuses.
        'requested' => 'bg-w', 'consumed' => 'bg-ok',
    ];
    return $map[$status] ?? 'bg-n';
}

function acm_chrome_open(string $active, string $title, string $sub, int $openCount = 0, string $badgeNav = ''): void
{
    $fullName = (string)($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'User');
    $roleLabel = \SSMS\Acm\Policy\AcmPolicy::roleLabel((string)($_SESSION['admin_role'] ?? ''));
    $initial = strtoupper(mb_substr($fullName, 0, 1));
    if ($badgeNav === '') {
        // Backwards compatible: callers that passed a count meant the queue.
        $badgeNav = 'queue';
    }
    $nav = [
        'overview' => ['acm_overview.php', 'fa-gauge-high', 'Overview'],
        'queue'    => ['acm_queue.php', 'fa-inbox', 'Activation queue'],
        // S11: member-initiated requests. Separate from 'queue' because
        // these are unverified claims, not office-created tickets.
        'requests' => ['acm_requests.php', 'fa-user-clock', 'Activation requests'],
        // S11: the way back in for a lost or replaced device. Grouped with
        // the queue because it produces a ticket, not an account.
        'recovery' => ['acm_device_recovery.php', 'fa-mobile-screen', 'Device recovery'],
        'persons'  => ['acm_person.php', 'fa-users', 'Persons & accounts'],
        'staff_enroll' => ['acm_staff_enroll.php', 'fa-id-badge', 'Staff enrollment'],
        'office'   => ['acm_office.php', 'fa-building', 'Office'],
        'audit'    => ['acm_audit.php', 'fa-file-shield', 'Monthly audit'],
    ];
    echo '<a class="v2-skip" href="#acmMain">Skip to content</a>'
        // Fallback first, real implementation later in the document. A page
        // whose script fires before the footer still gets a working (silent)
        // toast instead of a ReferenceError that would abort the handler.
        . '<script>if(typeof acmToast!=="function"){window.acmToast=function(){}};</script>'
        . '<body><aside><div class="brand"><div class="brand-logo"><i class="fa-solid fa-shield-halved"></i></div>'
        . '<div><div class="brand-txt">Access Control</div><div class="brand-sub">Account Command Center</div></div></div>'
        . '<div class="nt">Manage</div>';
    foreach ($nav as $key => $item) {
        $cls = $key === $active ? 'np active' : 'np';
        // S12: the badge follows the count's owner. A number shown against
        // the wrong menu item is worse than no number.
        $badge = ($key === $badgeNav && $openCount > 0)
            ? '<span class="badge">' . (int)$openCount . '</span>' : '';
        echo '<a class="' . $cls . '" href="/admin/' . $item[0] . '">'
            . '<i class="fa-solid ' . $item[1] . '"></i><span>' . $item[2] . '</span>' . $badge . '</a>';
    }
    echo '<div class="nt">Account</div>'
        . '<a class="np" href="/admin/dashboard.php"><i class="fa-solid fa-arrow-left"></i><span>Back to dashboard</span></a>'
        . '<a class="np" href="/admin/logout.php" style="color:var(--bad)"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>'
        . '<div class="uc"><div class="ua">'
        . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') . '</div><div><div class="un">'
        . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '</div><div class="ur">'
        . htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') . '</div></div></div>'
        . '</aside><main id="acmMain" tabindex="-1"><div class="topbar"><div class="topbar-left"><h1>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>'
        . htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') . '</p></div>'
        . '<div class="topbar-right">'
        . '<button class="icon-btn" onclick="toggleTheme()" title="Toggle theme"><i class="fa-solid fa-sun" id="themeIcon"></i></button>'
        . '<a class="btn bo bs" href="/admin/dashboard.php"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>'
        . '</div></div>'
        . '<div class="acm-mobnav">';
    foreach ($nav as $key => $item) {
        $cls = $key === $active ? 'btn bp bs' : 'btn bo bs';
        echo '<a class="' . $cls . '" href="/admin/' . $item[0] . '"><i class="fa-solid ' . $item[1] . '"></i> ' . $item[2] . '</a>';
    }
    echo '</div>';
}

function acm_chrome_close(): void
{
    echo '</main>'
        . '<div id="acmToast" class="acm-toast" role="status" aria-live="polite"></div>'
        . '<script>'
        . 'function acmCsrf(){var m=document.querySelector(\'meta[name="csrf-token"]\');return m?m.content:"";}'
        . 'function acmToast(msg,ok){var t=document.getElementById("acmToast");t.textContent=msg;'
        . 't.className="acm-toast show "+(ok===false?"err":"ok");clearTimeout(t._h);'
        . 't._h=setTimeout(function(){t.className="acm-toast";},3600);}'
        . 'function toggleTheme(){var h=document.documentElement,c=h.getAttribute("data-theme"),n=c==="dark"?"light":"dark";'
        . 'h.setAttribute("data-theme",n);try{localStorage.setItem("wbws-theme",n);}catch(e){}'
        . 'document.getElementById("themeIcon").className=n==="dark"?"fa-solid fa-sun":"fa-solid fa-moon";}'
        . 'function acmSyncThemeIcon(){var c=document.documentElement.getAttribute("data-theme");'
        . 'var i=document.getElementById("themeIcon");if(i){i.className=c==="dark"?"fa-solid fa-sun":"fa-solid fa-moon";}}'
        . 'acmSyncThemeIcon();'
        . '(function(){var s=null;try{s=localStorage.getItem("wbws-theme");}catch(e){}'
        . 'if(s==="light"){document.getElementById("themeIcon").className="fa-solid fa-moon";}})();'
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
