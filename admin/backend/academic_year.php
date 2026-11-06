<?php
/**
 * ════════════════════════════════════════════════════════════════
 * ACADEMIC YEAR CONTEXT — the SINGLE source of truth for "which year"
 * ════════════════════════════════════════════════════════════════
 * Loaded by config.php on every request. Every place that used to run
 *   SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1
 * must instead call ay_resolve()/ay_active_year() here.
 *
 * MODEL:
 *   academic_years.status = 'upcoming' | 'active' | 'closed'  (source of truth)
 *   is_current            = derived mirror (1 when status='active')
 *   EXACTLY ONE row is 'active' at a time.
 *
 * THE EFFECTIVE YEAR for a request:
 *   - If the user is time-travelling (viewing a past year) → that year, READ-ONLY.
 *   - Otherwise → the single active year, WRITABLE.
 *
 * WRITE RULE (anti-corruption core):
 *   New/updated year-scoped rows ALWAYS stamp the ACTIVE year id — never a
 *   past year — and writes are refused while time-travelling or when the
 *   target year is not active. Viewing the past ≠ writing to the past.
 * ════════════════════════════════════════════════════════════════
 */

if (!function_exists('ay_ensure_schema')) {

/**
 * Compatibility hook retained for callers. Schema is deployment-managed by
 * migrations 004 and 013; request handling never inspects or mutates it.
 */
function ay_ensure_schema($conn): void {
}

/**
 * The single ACTIVE year row (writable target). Cached per request.
 * Returns the associative row, or null if none exists.
 */
function ay_active_year($conn) {
    static $cache = null; static $loaded = false;
    if ($loaded) return $cache;
    $loaded = true;
    ay_ensure_schema($conn);
    try {
        // Prefer status; fall back to is_current for safety.
        $r = $conn->query("SELECT * FROM `academic_years` WHERE `status`='active' ORDER BY id DESC LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        if (!$row) {
            $r = $conn->query("SELECT * FROM `academic_years` WHERE `is_current`=1 ORDER BY id DESC LIMIT 1");
            $row = $r ? $r->fetch_assoc() : null;
        }
        $cache = $row ?: null;
    } catch (Throwable $e) { $cache = null; }
    return $cache;
}

/**
 * Fetch a single year row by id (or null).
 */
function ay_year_by_id($conn, $id) {
    $id = (int)$id;
    if ($id <= 0) return null;
    try {
        $stmt = $conn->prepare("SELECT * FROM `academic_years` WHERE id=? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * RESOLVE the effective academic-year context for THIS request.
 * Returns:
 *   [ 'active' => row|null, 'active_id' => int,
 *     'year' => row|null,  'id' => int, 'ec_year' => int|null,
 *     'is_readonly' => bool, 'viewing_id' => int|null ]
 */
function ay_resolve($conn) {
    static $ctx = null;
    if ($ctx !== null) return $ctx;

    $active = ay_active_year($conn);
    $activeId = $active ? (int)$active['id'] : 0;

    $viewId = isset($_SESSION['ay_view_year_id']) ? (int)$_SESSION['ay_view_year_id'] : 0;
    // A view that equals the active year (or is invalid) is not time-travel.
    $year = $active;
    $readonly = false;
    $viewingId = null;

    if ($viewId > 0 && $viewId !== $activeId) {
        $vrow = ay_year_by_id($conn, $viewId);
        if ($vrow) {
            $year = $vrow;
            $readonly = true;          // viewing a non-active year is ALWAYS read-only
            $viewingId = (int)$vrow['id'];
        } else {
            // Stale/invalid view id — drop it.
            unset($_SESSION['ay_view_year_id']);
        }
    }

    $ctx = [
        'active'      => $active,
        'active_id'   => $activeId,
        'year'        => $year,
        'id'          => $year ? (int)$year['id'] : 0,
        'ec_year'     => $year && $year['ec_year'] !== null ? (int)$year['ec_year'] : null,
        'is_readonly' => $readonly,
        'viewing_id'  => $viewingId,
    ];
    return $ctx;
}

/** True when the current request is time-travelling (viewing a past year). */
function ay_is_readonly($conn) {
    return ay_resolve($conn)['is_readonly'];
}

/**
 * WRITE GUARD. Call at the top of any handler that writes year-scoped data.
 * - Refuses (HTTP 403 JSON + exit) when time-travelling.
 * - Returns the ACTIVE year id to stamp on the new/updated rows.
 * $silent=true returns false instead of exiting (for non-JSON contexts).
 */
function ay_require_writable($conn, $silent = false) {
    $ctx = ay_resolve($conn);
    if ($ctx['is_readonly']) {
        if ($silent) return false;
        if (!headers_sent()) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode([
            'status'  => 'error',
            'message' => 'You are viewing a PAST academic year (read-only). Return to the current year to make changes.',
            'code'    => 'year_readonly'
        ]);
        exit;
    }
    if ($ctx['active_id'] <= 0) {
        if ($silent) return false;
        if (!headers_sent()) { http_response_code(409); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode([
            'status'  => 'error',
            'message' => 'No active academic year is set. A School Admin must set the current year first.',
            'code'    => 'no_active_year'
        ]);
        exit;
    }
    return $ctx['active_id'];
}

/**
 * Lighter write guard for writes that are NOT year-stamped (global catalog
 * edits, status flips) but must still be refused while time-travelling.
 * - Refuses (HTTP 403 JSON + exit) when viewing a past year.
 * - Does NOT require an active year to exist.
 * $silent=true returns false instead of exiting.
 */
function ay_block_if_readonly($conn, $silent = false) {
    $ctx = ay_resolve($conn);
    if ($ctx['is_readonly']) {
        if ($silent) return false;
        if (!headers_sent()) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode([
            'status'  => 'error',
            'message' => 'You are viewing a PAST academic year (read-only). Return to the current year to make changes.',
            'code'    => 'year_readonly'
        ]);
        exit;
    }
    return true;
}

/** Only School Admin (owner) and Super Admin (break-glass) manage the year lifecycle. */
function ay_can_manage_years() {
    $role = $_SESSION['admin_role'] ?? '';
    return in_array($role, ['super_admin', 'school_admin'], true);
}

/**
 * ATOMIC, SAFE change of the active year with explicit legal transitions.
 * Legal: upcoming→active (closes the prior active), active→active (no-op),
 *        closed→active ONLY when $reopen=true (deliberate corrective action).
 * Returns ['status'=>'success'|'error', 'message'=>..., 'closed_year'=>name|null].
 */
function ay_switch_active($conn, $targetYearId, $reopen = false) {
    $targetYearId = (int)$targetYearId;
    $target = ay_year_by_id($conn, $targetYearId);
    if (!$target) return ['status' => 'error', 'message' => 'That academic year does not exist.'];

    $tstatus = $target['status'] ?? ($target['is_current'] ? 'active' : 'upcoming');
    if ($tstatus === 'active') {
        return ['status' => 'success', 'message' => 'That year is already the active year.', 'closed_year' => null];
    }
    if ($tstatus === 'closed' && !$reopen) {
        return ['status' => 'error', 'message' => 'That year is closed. Use the explicit "Reopen" action to make a closed year active again.', 'code' => 'needs_reopen'];
    }

    try {
        $conn->begin_transaction();

        // Close whatever is currently active (the prior year).
        $priorName = null;
        $pr = $conn->query("SELECT year_name FROM `academic_years` WHERE `status`='active' LIMIT 1");
        if ($pr && ($prow = $pr->fetch_assoc())) { $priorName = $prow['year_name']; }
        $conn->query("UPDATE `academic_years` SET `status`='closed', `is_current`=0 WHERE `status`='active'");

        // Activate the target.
        $stmt = $conn->prepare("UPDATE `academic_years` SET `status`='active', `is_current`=1 WHERE id=?");
        if (!$stmt) { throw new Exception($conn->error); }
        $stmt->bind_param('i', $targetYearId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        return ['status' => 'success', 'message' => 'Active year changed.', 'closed_year' => $priorName];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $r) {}
        error_log('ay_switch_active failed: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Could not change the active year. No changes were made.'];
    }
}

/**
 * R3 — Validate a target year before it becomes Active.
 * Returns ['ok'=>bool, 'errors'=>string[], 'warnings'=>string[]]
 * Hard errors = missing dates / inverted dates / duplicate ec_year.
 * Warnings = no semesters / weights not 100 / today outside range.
 * Caller decides whether to block or require stronger confirmation.
 */
function ay_validate_target_year($conn, $targetYearId) {
    $errors = []; $warnings = [];
    $y = ay_year_by_id($conn, (int)$targetYearId);
    if (!$y) return ['ok'=>false, 'errors'=>['That academic year does not exist.'], 'warnings'=>[]];
    $start = $y['start_date'] ?? null;
    $end   = $y['end_date'] ?? null;
    if (empty($start) || empty($end)) {
        $errors[] = 'The year "' . ($y['year_name']??'') . '" has no start/end dates — set the School Year dates (Meskerem → Sene) before activating, or attendance calendar will have no class days.';
    } elseif (strtotime($start) > strtotime($end)) {
        $errors[] = 'Start date (' . $start . ') is after end date (' . $end . ') — correct the dates before activating.';
    } else {
        $today = date('Y-m-d');
        if ($today < $start || $today > $end) {
            $warnings[] = 'Today (' . $today . ') is outside this year\'s date window (' . $start . ' → ' . $end . ') — mobile attendance preparation requires today ∈ [start_date, end_date]; teachers may see "Year not current" until the window is corrected.';
        }
    }
    // Semester / weight checks
    try {
        $tc = 0;
        $r = $conn->query("SELECT COUNT(*) c FROM academic_terms WHERE academic_year_id=" . (int)$targetYearId);
        if ($r) { $row=$r->fetch_assoc(); $tc=(int)($row['c']??0); }
        if ($tc === 0) {
            $warnings[] = 'This year has no semesters (academic_terms) — create at least Semester 1 & 2, or term-scoped assessments and reports will have nowhere to belong.';
        }
        $s1 = isset($y['s1_weight_pct']) ? (float)$y['s1_weight_pct'] : null;
        $s2 = isset($y['s2_weight_pct']) ? (float)$y['s2_weight_pct'] : null;
        if ($s1 !== null && $s2 !== null) {
            $tot = round($s1 + $s2, 2);
            if ($tot != 100.00) $warnings[] = 'Semester weights total ' . $tot . '% (must be 100%) — the annual score weighting will be wrong until corrected.';
        }
    } catch (Throwable $e) {}
    return ['ok'=> empty($errors), 'errors'=>$errors, 'warnings'=>$warnings];
}

/** Years for a selector dropdown: [{id, year_name, ec_year, status, is_active}]. */
function ay_year_list($conn) {
    ay_ensure_schema($conn);
    $out = [];
    try {
        $r = $conn->query("SELECT id, year_name, ec_year, status, is_current FROM `academic_years` ORDER BY COALESCE(ec_year,0) DESC, id DESC");
        if ($r) while ($row = $r->fetch_assoc()) {
            $out[] = [
                'id'        => (int)$row['id'],
                'year_name' => $row['year_name'],
                'ec_year'   => $row['ec_year'] !== null ? (int)$row['ec_year'] : null,
                'status'    => $row['status'] ?? ($row['is_current'] ? 'active' : 'upcoming'),
                'is_active' => (($row['status'] ?? '') === 'active') || (int)$row['is_current'] === 1,
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * The loud, impossible-to-miss "viewing past year" banner.
 * Returns '' when in the normal active year (nothing to show).
 * Echo this near the top of <body> on every dashboard.
 */
function ay_banner_html($conn) {
    $ctx = ay_resolve($conn);
    if (!$ctx['is_readonly'] || !$ctx['year']) return '';
    $viewName = $ctx['year']['year_name'] ?? '';
    $activeName = $ctx['active'] ? ($ctx['active']['year_name'] ?? '') : 'current';
    $vn = htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8');
    $an = htmlspecialchars($activeName, ENT_QUOTES, 'UTF-8');
    return
    '<div id="ayTimeTravelBanner" style="position:sticky;top:0;left:0;right:0;z-index:9998;'
    . 'background:repeating-linear-gradient(45deg,#f59e0b,#f59e0b 18px,#d97706 18px,#d97706 36px);'
    . 'color:#1a1200;font-family:system-ui,Segoe UI,sans-serif;font-weight:800;'
    . 'padding:.6rem 1rem;display:flex;align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap;'
    . 'box-shadow:0 3px 10px rgba(0,0,0,.3);border-bottom:3px solid #92400e;letter-spacing:.2px">'
    . '<span style="font-size:.95rem">📅 VIEWING PAST YEAR: <span class="amharic">' . $vn . '</span> — READ ONLY. '
    . 'You are NOT in the current year.</span>'
    . '<button onclick="ayReturnToCurrent()" style="background:#1a1200;color:#fde68a;border:0;border-radius:8px;'
    . 'padding:.45rem .9rem;font-weight:800;cursor:pointer;font-family:inherit;white-space:nowrap">'
    . '↩ Return to Current Year (' . $an . ')</button>'
    . '</div>'
    . '<script>'
    . 'function ayReturnToCurrent(){var f=new FormData();f.append("action","clear");'
    . 'f.append("csrf_token",(window.CSRF||(window.APP&&window.APP.csrf)||""));'
    . 'fetch("/admin/api_year_context.php",{method:"POST",body:f,credentials:"same-origin"})'
    . '.then(function(){location.reload();}).catch(function(){location.reload();});}'
    . 'document.addEventListener("DOMContentLoaded",function(){'
    . 'document.querySelectorAll(\'button,.btn,input[type=submit],a.btn\').forEach(function(el){'
    . 'var t=(el.textContent||"").toLowerCase();'
    . 'if(/save|add|edit|delete|create|remove|record|submit|enroll|promote|approve|reject|assign/.test(t)'
    . '&&!/return to current/.test(t)){el.setAttribute("disabled","disabled");el.style.opacity=".45";'
    . 'el.style.pointerEvents="none";el.title="Read-only: viewing a past year";}});});'
    . '</script>';
}

/**
 * The full academic-year CONTEXT BAR for dashboards. Combines:
 *   - NORMAL mode: a slim year selector to jump into read-only "time-travel".
 *   - TIME-TRAVEL mode: the loud amber READ-ONLY banner + one-click return,
 *     and auto-disables write controls on the page.
 * Self-contained (embeds the CSRF token server-side) so it works on every
 * dashboard. It injects a NATIVE-LOOKING SIDEBAR BUTTON (cloning an existing
 * nav item's classes) that opens a modal year-switcher — no permanent top bar.
 * The loud read-only banner appears ONLY while actually viewing a past year.
 * Echo it right after <body>.
 */
function ay_context_bar_html($conn) {
    $ctx   = ay_resolve($conn);
    $years = ay_year_list($conn);

    $active     = $ctx['active'];
    $activeId   = (int)$ctx['active_id'];
    $activeName = $active ? ($active['year_name'] ?? '') : '';
    $an = htmlspecialchars($activeName !== '' ? $activeName : '—', ENT_QUOTES, 'UTF-8');

    $isRO     = ($ctx['is_readonly'] && $ctx['year']);
    $viewName = $isRO ? htmlspecialchars($ctx['year']['year_name'] ?? '', ENT_QUOTES, 'UTF-8') : '';

    $token   = function_exists('generateCsrfToken') ? generateCsrfToken() : ($_SESSION['csrf_token'] ?? '');
    $tokenJs = json_encode($token);

    // Sidebar-button label = the year you are effectively in (viewed year when
    // time-travelling, else the active year). Kept short and distinct from the
    // "Academic Year" management nav item.
    $btnLabelJs = json_encode(htmlspecialchars(($isRO ? ($ctx['year']['year_name'] ?? '') : $activeName) ?: 'Year', ENT_QUOTES, 'UTF-8'));

    // OTHER years = possible read-only "time-travel" targets.
    $targets = [];
    foreach ($years as $y) { if ((int)$y['id'] !== $activeId) $targets[] = $y; }

    // Selector (or hint) shown inside the modal.
    if (empty($targets)) {
        $selector = '<div style="font-size:.78rem;opacity:.8;background:rgba(148,163,184,.1);border-radius:8px;padding:.55rem .65rem">'
            . '🕘 No past years yet — a past year will appear here (read-only) after you run a <b>Year Rollover</b>.</div>';
    } else {
        $opts = '<option value="0" selected>— Stay in the current year —</option>';
        foreach ($targets as $y) {
            $sid = (int)$y['id'];
            $st  = $y['status'] ?? 'upcoming';
            $lbl = $st === 'closed' ? 'Closed — view only' : ($st === 'upcoming' ? 'Upcoming — not started yet' : 'View only');
            $opts .= '<option value="' . $sid . '">' . htmlspecialchars($y['year_name'] . '  ·  ' . $lbl, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $selector = '<label style="font-size:.8rem;display:block;margin-bottom:.35rem;opacity:.9">🕘 Open a past year (read-only):</label>'
            . '<select onchange="if(this.value!=0){ayCtx.go(this.value);}" '
            . 'style="width:100%;background:#1e293b;color:#e2e8f0;border:1px solid #334155;border-radius:8px;padding:.5rem;font-family:inherit;font-size:.82rem;cursor:pointer">'
            . $opts . '</select>';
    }

    // Read-only notice inside the modal (only when time-travelling).
    $roBox = '';
    if ($isRO) {
        $roBox = '<div style="background:rgba(245,158,11,.12);border:1px solid #f59e0b;border-radius:8px;padding:.55rem .65rem;font-size:.78rem;margin-bottom:.7rem;color:#fbbf24">'
            . 'You are currently viewing <b class="amharic">' . $viewName . '</b> — <b>READ ONLY</b>. Nothing here is saved.'
            . '<button type="button" onclick="ayCtx.go(0)" style="margin-top:.55rem;background:#1a1200;color:#fde68a;border:0;border-radius:8px;padding:.4rem .8rem;font-weight:800;cursor:pointer;font-family:inherit">↩ Return to Current Year</button>'
            . '</div>';
    }

    // The modal (hidden until the sidebar button opens it).
    $modal = '<div id="ayYearModal" style="display:none;position:fixed;inset:0;z-index:1200;background:rgba(0,0,0,.55);'
        . 'backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:1rem;font-family:system-ui,Segoe UI,sans-serif" '
        . 'onclick="if(event.target===this)ayCloseYearModal()">'
        . '<div style="background:#0f172a;color:#e2e8f0;max-width:460px;width:100%;border:1px solid #334155;border-radius:14px;'
        . 'padding:1.1rem 1.25rem;box-shadow:0 20px 60px rgba(0,0,0,.5)">'
        . '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.7rem">'
        . '<h3 style="margin:0;font-size:1rem">📅 Academic Year</h3>'
        . '<button type="button" onclick="ayCloseYearModal()" style="background:none;border:0;color:#94a3b8;font-size:1.4rem;cursor:pointer;line-height:1">&times;</button>'
        . '</div>'
        . '<div style="font-size:.84rem;margin-bottom:.7rem">You are in: <b style="color:#34d399" class="amharic">' . $an . '</b> <span style="opacity:.7">· Active (current year)</span></div>'
        . $roBox . $selector
        . '<div style="font-size:.72rem;opacity:.6;margin-top:.6rem">Opening a past year is read-only — it never changes your live data.</div>'
        . '</div></div>';

    // Shared JS: ayCtx helper + modal open/close + native sidebar-button injection.
    $roJs = $isRO ? 'true' : 'false';
    $js = '<script>'
        . 'window.ayCtx=window.ayCtx||{csrf:' . $tokenJs . ',go:function(id){var f=new FormData();f.append("action",id?"set":"clear");if(id)f.append("year_id",id);f.append("csrf_token",this.csrf);fetch("/admin/api_year_context.php",{method:"POST",body:f,credentials:"same-origin"}).then(function(){location.reload();}).catch(function(){location.reload();});}};'
        . 'window.ayOpenYearModal=function(){var m=document.getElementById("ayYearModal");if(m)m.style.display="flex";};'
        . 'window.ayCloseYearModal=function(){var m=document.getElementById("ayYearModal");if(m)m.style.display="none";};'
        . '(function(){var RO=' . $roJs . ',LBL=' . $btnLabelJs . ';'
        . 'function inject(){'
        . 'if(document.getElementById("ayNavBtn"))return;'
        . 'var nav=document.querySelector("aside nav")||document.querySelector("nav.flex-1")||document.querySelector("aside")||document.querySelector(".school-sidebar");'
        . 'var sample=nav?nav.querySelector(".np,a,button"):null;'
        . 'var btn=document.createElement("button");btn.id="ayNavBtn";btn.type="button";'
        . 'if(sample&&sample.className){btn.className=sample.className.split(" ").filter(function(c){return c&&!/active|selected|current/i.test(c);}).join(" ");}'
        . 'btn.style.cursor="pointer";btn.style.width="100%";btn.style.textAlign="left";'
        . 'var chip=RO?\' <span style="margin-left:auto;background:#f59e0b;color:#1a1200;font-size:.55rem;font-weight:800;padding:.08rem .35rem;border-radius:6px">PAST</span>\':\'\';'
        . 'btn.innerHTML=\'<span style="display:inline-flex;align-items:center;gap:.5rem;width:100%">📅 <span>\'+LBL+\'</span>\'+chip+\'</span>\';'
        . 'btn.title="View the current or a past academic year";'
        . 'btn.onclick=function(e){e.preventDefault();e.stopPropagation();window.ayOpenYearModal();};'
        . 'if(nav){'
        . 'var sb=document.querySelector("aside")||nav;'
        . 'var foot=sb.querySelector(".uc")||sb.querySelector(".user-card")||sb.querySelector(".sidebar-footer");'
        . 'if(!foot){var la=sb.querySelectorAll("a,button");for(var i=la.length-1;i>=0;i--){if(/log\\s*out/i.test(la[i].textContent||"")){foot=la[i];break;}}}'
        . 'if(foot&&foot.parentNode){foot.parentNode.insertBefore(btn,foot);}'  // just ABOVE the user-card / logout = end of the list
        . 'else{(document.querySelector("aside nav")||nav).appendChild(btn);}'
        . '}'
        . 'else{btn.style.position="fixed";btn.style.left="12px";btn.style.bottom="12px";btn.style.zIndex="80";btn.style.width="auto";btn.style.background=RO?"#f59e0b":"#0f172a";btn.style.color=RO?"#1a1200":"#e2e8f0";btn.style.border="1px solid #334155";btn.style.borderRadius="10px";btn.style.padding=".5rem .8rem";btn.style.fontFamily="system-ui,sans-serif";btn.style.boxShadow="0 4px 12px rgba(0,0,0,.3)";document.body.appendChild(btn);}'
        . '}'
        . 'if(document.readyState!=="loading")inject();else document.addEventListener("DOMContentLoaded",inject);'
        . 'setTimeout(inject,400);})();'
        . '</script>';

    // When time-travelling, ALSO show the loud fixed banner + disable writes.
    $banner = '';
    if ($isRO) {
        $banner =
        '<div id="ayTimeTravelBanner" style="position:fixed;top:0;left:0;right:0;z-index:95;'
        . 'background:repeating-linear-gradient(45deg,#f59e0b,#f59e0b 18px,#d97706 18px,#d97706 36px);'
        . 'color:#1a1200;font-family:system-ui,Segoe UI,sans-serif;font-weight:800;'
        . 'padding:.55rem 1rem;display:flex;align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap;'
        . 'box-shadow:0 3px 10px rgba(0,0,0,.3);border-bottom:3px solid #92400e">'
        . '<span style="font-size:.92rem">📅 VIEWING PAST YEAR: <span class="amharic">' . $viewName . '</span> — READ ONLY.</span>'
        . '<button type="button" onclick="ayCtx.go(0)" style="background:#1a1200;color:#fde68a;border:0;border-radius:8px;'
        . 'padding:.4rem .85rem;font-weight:800;cursor:pointer;font-family:inherit;white-space:nowrap">↩ Return to Current Year</button>'
        . '</div>'
        . '<script>document.addEventListener("DOMContentLoaded",function(){'
        . 'var b=document.getElementById("ayTimeTravelBanner");if(b)document.body.style.paddingTop=b.offsetHeight+"px";'
        . 'document.querySelectorAll(\'button,.btn,input[type=submit],a.btn\').forEach(function(el){'
        . 'if(el.id==="ayNavBtn")return;'
        . 'var t=(el.textContent||"").toLowerCase();'
        . 'if(/save|add|edit|delete|create|remove|record|submit|enroll|promote|approve|reject|assign|set current|set active|reopen|rollover/.test(t)'
        . '&&!/return to current/.test(t)){el.setAttribute("disabled","disabled");el.style.opacity=".45";'
        . 'el.style.pointerEvents="none";el.title="Read-only: viewing a past year";}});});</script>';
    }

    return $banner . $modal . $js;
}

} // end function guard
