<?php
/**
 * ACM S1 — Office governance. Mode, designated approvers, reviewers.
 * Roles: super_admin, accounts_office (mutations are super_admin-only,
 * enforced server-side in AcmOfficeService + api_acm.php).
 */
define('ACM_BOOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/acm_layout.php';
require_once __DIR__ . '/../acm/autoload.php';

use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Policy\AcmPolicy;

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    header('Location: index.php');
    exit;
}
$role = (string)($_SESSION['admin_role'] ?? '');
$actorId = (int)($_SESSION['admin_id'] ?? 0);
if (!in_array($role, AcmPolicy::consoleRoles(), true)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}
$isSuperAdmin = $role === 'super_admin';

$openCount = 0;
try {
    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
    $openCount = (int)($tickets->overviewStats($role, $actorId)['open'] ?? 0);
} catch (\Throwable $e) {
    $openCount = 0;
}

acm_head('Office');
acm_chrome_open('office', 'Office governance', 'Modes, approvers and reviewers', $openCount);
?>
<div class="cd">
    <div class="ct"><i class="fa-solid fa-building"></i> Office mode <span class="bg bg-n" id="modeChip">…</span></div>
    <dl class="acm-kv">
        <dt>Active officers</dt><dd id="officerCount">…</dd>
        <dt>You are</dt><dd id="myDesignations">…</dd>
    </dl>
    <?php if ($isSuperAdmin): ?>
    <form id="modeForm" class="acm-form" onsubmit="return false" style="margin-top:.8rem">
        <div><label class="flbl" for="m_mode">Mode</label>
            <select class="inp" id="m_mode" name="mode">
                <option value="separated">separated — maker–checker, needs 2+ officers</option>
                <option value="solo">solo — designated approvers only</option>
            </select></div>
        <div><label class="flbl" for="m_date">Effective date</label><input class="inp" id="m_date" name="effective_date" type="date" required></div>
        <div class="full"><label class="flbl" for="m_reason">Reason (required, permanent history)</label>
            <textarea class="inp" id="m_reason" rows="2" maxlength="2000" required></textarea></div>
        <div class="full"><button class="btn bp" id="modeBtn"><i class="fa-solid fa-rotate"></i> Switch mode</button></div>
    </form>
    <?php else: ?>
    <div style="font-size:.8rem;color:var(--dim);margin-top:.6rem">Only a Super Admin can switch the office mode.</div>
    <?php endif; ?>
    <div class="ct" style="margin-top:.8rem"><i class="fa-solid fa-clock-rotate-left"></i> Mode history</div>
    <div class="tw"><table>
        <thead><tr><th>Mode</th><th>Effective</th><th>Reason</th><th>Changed by</th><th>At</th></tr></thead>
        <tbody id="modeHistory"><tr><td colspan="5">Loading…</td></tr></tbody>
    </table></div>
</div>

<div class="acm-grid2">
    <div class="cd">
        <div class="ct"><i class="fa-solid fa-user-check"></i> Designated approvers</div>
        <div class="tw"><table>
            <thead><tr><th>User</th><th>Effective</th><th>Reason</th><?php if ($isSuperAdmin): ?><th></th><?php endif; ?></tr></thead>
            <tbody id="approverRows"><tr><td colspan="4">Loading…</td></tr></tbody>
        </table></div>
        <?php if ($isSuperAdmin): ?>
        <form id="approverForm" class="acm-form" onsubmit="return false" style="margin-top:.8rem">
            <div><label class="flbl" for="a_user">User</label><select class="inp" id="a_user"></select></div>
            <div><label class="flbl" for="a_search">Find user (min 2 chars)</label><input class="inp" id="a_search" maxlength="60" placeholder="Type to search…"></div>
            <div><label class="flbl" for="a_effective">Effective</label><input class="inp" id="a_effective" placeholder="YYYY-MM-DD or YYYY-MM-DD HH:MM" required></div>
            <div class="full"><label class="flbl" for="a_reason">Reason (required)</label><textarea class="inp" id="a_reason" rows="2" maxlength="2000" required></textarea></div>
            <div class="full"><button class="btn bp" id="approverBtn"><i class="fa-solid fa-plus"></i> Designate approver</button></div>
        </form>
        <?php endif; ?>
    </div>
    <div class="cd">
        <div class="ct"><i class="fa-solid fa-file-shield"></i> Independent reviewers</div>
        <div class="tw"><table>
            <thead><tr><th>User</th><th>Effective</th><th>Reason</th><?php if ($isSuperAdmin): ?><th></th><?php endif; ?></tr></thead>
            <tbody id="reviewerRows"><tr><td colspan="4">Loading…</td></tr></tbody>
        </table></div>
        <?php if ($isSuperAdmin): ?>
        <form id="reviewerForm" class="acm-form" onsubmit="return false" style="margin-top:.8rem">
            <div><label class="flbl" for="r_user">User</label><select class="inp" id="r_user"></select></div>
            <div><label class="flbl" for="r_search">Find user (min 2 chars)</label><input class="inp" id="r_search" maxlength="60" placeholder="Type to search…"></div>
            <div><label class="flbl" for="r_effective">Effective</label><input class="inp" id="r_effective" placeholder="YYYY-MM-DD or YYYY-MM-DD HH:MM" required></div>
            <div class="full"><label class="flbl" for="r_reason">Reason (required)</label><textarea class="inp" id="r_reason" rows="2" maxlength="2000" required></textarea></div>
            <div class="full"><button class="btn bp" id="reviewerBtn"><i class="fa-solid fa-plus"></i> Designate reviewer</button></div>
        </form>
        <?php else: ?>
        <div style="font-size:.8rem;color:var(--dim);margin-top:.6rem">Only a Super Admin can designate reviewers.</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<div class="cd" id="revokePanel" style="display:none">
    <div class="ct"><i class="fa-solid fa-user-xmark"></i> Revoke designation — <span id="revokeWho"></span></div>
    <form id="revokeForm" class="acm-form" onsubmit="return false">
        <div><label class="flbl" for="rv_effective">Effective</label><input class="inp" id="rv_effective" placeholder="YYYY-MM-DD or YYYY-MM-DD HH:MM" required></div>
        <div class="full"><label class="flbl" for="rv_reason">Reason (required, recorded)</label><textarea class="inp" id="rv_reason" rows="2" maxlength="2000" required></textarea></div>
        <div class="full acm-btn-row">
            <button class="btn bd" id="revokeConfirm"><i class="fa-solid fa-check"></i> Confirm revoke</button>
            <button class="btn bo" id="revokeCancel">Cancel</button>
        </div>
    </form>
</div>
<?php endif; ?>

<script>
(function () {
    var isSuper = <?= $isSuperAdmin ? 'true' : 'false' ?>;
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    function fillUsers(sel, users) {
        sel.innerHTML = '';
        users.forEach(function (u) {
            var o = document.createElement('option');
            o.value = u.id;
            o.textContent = u.username + ' — ' + (u.full_name || '') + ' (' + u.role + ')';
            sel.appendChild(o);
        });
        if (!users.length) {
            var o = document.createElement('option');
            o.value = '';
            o.textContent = 'No matches — search above';
            sel.appendChild(o);
        }
    }

    function wireSearch(inputId, selId) {
        var inp = document.getElementById(inputId), sel = document.getElementById(selId);
        if (!inp || !sel) return;
        var t = null;
        inp.addEventListener('input', function () {
            clearTimeout(t);
            t = setTimeout(async function () {
                var q = inp.value.trim();
                if (q.length < 2) return;
                try {
                    var d = await acmApi('office_users', { q: q });
                    fillUsers(sel, d.users || []);
                } catch (err) { acmToast(err.message, false); }
            }, 300);
        });
    }

    function desigRows(tbodyId, rows, kind) {
        var tb = document.getElementById(tbodyId);
        if (!rows.length) { tb.innerHTML = '<tr><td colspan="4">None designated.</td></tr>'; return; }
        tb.innerHTML = rows.map(function (r) {
            var rev = isSuper ? '<td><button class="btn bd bs" data-revoke="' + kind + '" data-uid="' + r.user_id + '">Revoke</button></td>' : '';
            return '<tr><td>' + esc(r.username) + '</td><td>' + esc(r.effective_from) + '</td><td>' + esc(r.designate_reason || '') + '</td>' + rev + '</tr>';
        }).join('');
    }

    async function load() {
        try {
            var m = await acmApi('office_mode_get', {});
            document.getElementById('modeChip').textContent = m.mode + ' mode';
            document.getElementById('officerCount').textContent = m.active_officers;
            var tags = [];
            if (m.is_designee) tags.push('designated approver');
            if (m.is_reviewer) tags.push('independent reviewer');
            document.getElementById('myDesignations').textContent = tags.length ? tags.join(', ') : 'officer (no designations)';
            var mh = document.getElementById('modeHistory');
            mh.innerHTML = (m.history || []).map(function (h) {
                return '<tr><td>' + esc(h.mode) + '</td><td>' + esc(h.effective_date) + '</td><td>' + esc(h.reason) + '</td><td>' + esc(h.changed_name || '') + '</td><td>' + esc(h.changed_at) + '</td></tr>';
            }).join('') || '<tr><td colspan="5">No switches recorded.</td></tr>';
            var sm = document.getElementById('m_mode');
            if (sm) sm.value = m.mode;
        } catch (err) { acmToast(err.message, false); }
        try {
            var a = await acmApi('approvers_list', {});
            desigRows('approverRows', a.approvers || [], 'approvers');
        } catch (err) { acmToast(err.message, false); }
        try {
            var r = await acmApi('reviewers_list', {});
            desigRows('reviewerRows', r.reviewers || [], 'reviewers');
        } catch (err) { acmToast(err.message, false); }
    }

    wireSearch('a_search', 'a_user');
    wireSearch('r_search', 'r_user');

    async function designate(kind) {
        var p = kind === 'approvers'
            ? { user_id: document.getElementById('a_user').value, effective: document.getElementById('a_effective').value, reason: document.getElementById('a_reason').value }
            : { user_id: document.getElementById('r_user').value, effective: document.getElementById('r_effective').value, reason: document.getElementById('r_reason').value };
        if (!p.user_id) { acmToast('Search and choose a user first.', false); return; }
        try {
            await acmApi(kind + '_designate', p, 'POST');
            acmToast('Designated.');
            load();
        } catch (err) { acmToast(err.message, false); }
    }

    var revokeCtx = { kind: '', uid: '', who: '' };
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-revoke]');
        if (!b) return;
        revokeCtx.kind = b.getAttribute('data-revoke');
        revokeCtx.uid = b.getAttribute('data-uid');
        revokeCtx.who = b.closest('tr').querySelector('td').textContent;
        document.getElementById('revokeWho').textContent = revokeCtx.who + ' (' + revokeCtx.kind + ')';
        document.getElementById('rv_effective').value = new Date().toISOString().slice(0, 16).replace('T', ' ');
        document.getElementById('rv_reason').value = '';
        document.getElementById('revokePanel').style.display = '';
        document.getElementById('revokePanel').scrollIntoView();
    });
    var rc = document.getElementById('revokeCancel');
    if (rc) rc.addEventListener('click', function () {
        document.getElementById('revokePanel').style.display = 'none';
    });
    var rf = document.getElementById('revokeConfirm');
    if (rf) rf.addEventListener('click', async function () {
        var reason = document.getElementById('rv_reason').value.trim();
        var effective = document.getElementById('rv_effective').value.trim();
        if (reason.length < 3) { acmToast('A reason is required.', false); return; }
        try {
            await acmApi(revokeCtx.kind + '_revoke', {
                user_id: revokeCtx.uid,
                reason: reason,
                effective: effective
            }, 'POST');
            acmToast('Revoked.');
            document.getElementById('revokePanel').style.display = 'none';
            load();
        } catch (err) { acmToast(err.message, false); }
    });

    var ab = document.getElementById('approverBtn');
    if (ab) ab.addEventListener('click', function () { designate('approvers'); });
    var rb = document.getElementById('reviewerBtn');
    if (rb) rb.addEventListener('click', function () { designate('reviewers'); });
    var mb = document.getElementById('modeBtn');
    if (mb) mb.addEventListener('click', async function () {
        try {
            await acmApi('office_mode_set', {
                mode: document.getElementById('m_mode').value,
                effective_date: document.getElementById('m_date').value,
                reason: document.getElementById('m_reason').value
            }, 'POST');
            acmToast('Office mode switched.');
            load();
        } catch (err) { acmToast(err.message, false); }
    });

    load();
})();
</script>
<?php acm_chrome_close(); ?>
