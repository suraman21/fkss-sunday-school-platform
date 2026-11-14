<?php
/**
 * ACM S1 — Monthly audit report + attestation.
 * Roles: super_admin, accounts_office. Attestation requires a
 * designated independent reviewer (server-enforced); everyone with
 * console access can inspect the report.
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

$openCount = 0;
try {
    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
    $openCount = (int)($tickets->overviewStats($role, $actorId)['open'] ?? 0);
} catch (\Throwable $e) {
    $openCount = 0;
}
$month = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}

acm_head('Monthly audit');
acm_chrome_open('audit', 'Monthly audit', 'Office accountability, attested', $openCount);
?>
<div class="cd">
    <div class="ct"><i class="fa-solid fa-file-shield"></i> Report <span class="bg bg-n" id="repMonth"><?= e($month) ?></span></div>
    <form id="monthForm" class="acm-form" onsubmit="return false">
        <div><label class="flbl" for="f_month">Month</label><input class="inp" id="f_month" type="month" value="<?= e($month) ?>"></div>
        <div><label class="flbl">&nbsp;</label><button class="btn bo" id="loadBtn"><i class="fa-solid fa-rotate"></i> Load report</button></div>
    </form>
    <dl class="acm-kv" style="margin-top:.6rem">
        <dt>Period</dt><dd id="repPeriod">…</dd>
        <dt>Generated</dt><dd id="repGen">…</dd>
        <dt>Schema</dt><dd id="repSchema">…</dd>
        <dt>Snapshot fingerprint</dt><dd><code id="repHash" style="font-size:.72rem;word-break:break-all">…</code></dd>
    </dl>
    <div class="sg" style="margin-top:.6rem">
        <div class="sc"><div class="val" id="sumAudit">0</div><div class="lbl">Audit events</div></div>
        <div class="sc"><div class="val" id="sumTicket">0</div><div class="lbl">Ticket events</div></div>
        <div class="sc"><div class="val" id="sumModes">0</div><div class="lbl">Mode changes</div></div>
        <div class="sc"><div class="val" id="sumSelf">0</div><div class="lbl">Self-approved</div></div>
    </div>
    <div id="truncWarn"></div>
</div>

<div class="cd">
    <div class="ct"><i class="fa-solid fa-triangle-exclamation"></i> Self-approved decisions</div>
    <div class="tw"><table>
        <thead><tr><th>Ticket</th><th>Type</th><th>Decided by</th><th>At</th></tr></thead>
        <tbody id="selfRows"><tr><td colspan="4">Loading…</td></tr></tbody>
    </table></div>
</div>

<div class="cd">
    <div class="ct"><i class="fa-solid fa-clock-rotate-left"></i> Audit events <span style="font-weight:400;font-size:.75rem">(first 200 shown)</span></div>
    <div class="tw"><table>
        <thead><tr><th>At</th><th>User</th><th>Action</th><th>Detail</th></tr></thead>
        <tbody id="auditRows"><tr><td colspan="4">Loading…</td></tr></tbody>
    </table></div>
</div>

<div class="acm-grid2">
    <div class="cd">
        <div class="ct"><i class="fa-solid fa-building"></i> Mode changes</div>
        <div class="tw"><table>
            <thead><tr><th>Mode</th><th>Effective</th><th>By</th><th>At</th></tr></thead>
            <tbody id="modeRows"><tr><td colspan="4">Loading…</td></tr></tbody>
        </table></div>
        <div class="ct" style="margin-top:.8rem"><i class="fa-solid fa-pen-nib"></i> Attestations</div>
        <div class="tw"><table>
            <thead><tr><th>Kind</th><th>By</th><th>At</th><th>Note</th></tr></thead>
            <tbody id="attRows"><tr><td colspan="4">Loading…</td></tr></tbody>
        </table></div>
    </div>
    <div class="cd">
        <div class="ct"><i class="fa-solid fa-stamp"></i> Attest this month</div>
        <div id="attestGate">Checking reviewer status…</div>
        <form id="attestForm" class="acm-form" onsubmit="return false" style="display:none">
            <div class="full"><label class="flbl" for="att_note">Attestation note (required)</label>
                <textarea class="inp" id="att_note" rows="3" maxlength="2000" required placeholder="What did you review? Any exceptions?"></textarea></div>
            <div class="full"><button class="btn bp" id="attestBtn"><i class="fa-solid fa-stamp"></i> Attest snapshot</button></div>
            <div class="full" style="font-size:.75rem;color:var(--dim)">Attesting approves this exact snapshot (fingerprint above). If new office activity lands first, you must regenerate and review again. Corrections append a new attestation; nothing is ever edited.</div>
        </form>
    </div>
</div>

<script>
(function () {
    var month = <?= json_encode($month) ?>;
    var fingerprint = '';
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    async function load() {
        var d;
        try {
            d = await acmApi('office_audit', { month: month });
        } catch (err) { acmToast(err.message, false); return; }
        var r = d.report || {};
        fingerprint = r.report_hash || '';
        document.getElementById('repMonth').textContent = r.month || month;
        document.getElementById('repPeriod').textContent = (r.period_from || '') + ' → ' + (r.period_to || '');
        document.getElementById('repGen').textContent = r.generated_at || '';
        document.getElementById('repSchema').textContent = (r.schema_version || '') + ' / ' + (r.hash_algo || '');
        document.getElementById('repHash').textContent = fingerprint;
        var s = r.summary || {};
        document.getElementById('sumAudit').textContent = s.audit_events || 0;
        document.getElementById('sumTicket').textContent = s.ticket_events || 0;
        document.getElementById('sumModes').textContent = s.mode_changes || 0;
        document.getElementById('sumSelf').textContent = s.self_approved || 0;
        document.getElementById('truncWarn').innerHTML = s.truncated
            ? '<div class="acm-alert warn">Report hit the 5000-row cap and was truncated. Narrow follow-up queries with the office before attesting.</div>' : '';
        document.getElementById('selfRows').innerHTML = (r.self_approved || []).map(function (t) {
            return '<tr><td>' + esc(t.ticket_no) + '</td><td>' + esc(t.account_type) + '</td><td>' + esc(t.decider_name || '') + '</td><td>' + esc(t.decided_at || '') + '</td></tr>';
        }).join('') || '<tr><td colspan="4">None.</td></tr>';
        document.getElementById('auditRows').innerHTML = (r.audit_events || []).slice(0, 200).map(function (e) {
            return '<tr><td>' + esc(e.created_at) + '</td><td>' + esc(e.username || '') + '</td><td>' + esc(e.action) + '</td><td style="font-size:.72rem">' + esc(e.details || '') + '</td></tr>';
        }).join('') || '<tr><td colspan="4">No office activity.</td></tr>';
        document.getElementById('modeRows').innerHTML = (r.mode_changes || []).map(function (h) {
            return '<tr><td>' + esc(h.mode) + '</td><td>' + esc(h.effective_date) + '</td><td>' + esc(h.changed_name || '') + '</td><td>' + esc(h.changed_at) + '</td></tr>';
        }).join('') || '<tr><td colspan="4">None.</td></tr>';
        document.getElementById('attRows').innerHTML = (r.attestations || []).map(function (a) {
            return '<tr><td>' + esc(a.kind) + '</td><td>' + esc(a.attester_name || '') + '</td><td>' + esc(a.attested_at) + '</td><td>' + esc(a.note) + '</td></tr>';
        }).join('') || '<tr><td colspan="4">Unattested.</td></tr>';
    }

    async function gate() {
        try {
            var m = await acmApi('office_mode_get', {});
            if (m.is_reviewer) {
                document.getElementById('attestGate').style.display = 'none';
                document.getElementById('attestForm').style.display = '';
            } else {
                document.getElementById('attestGate').innerHTML = '<div class="acm-alert warn">Only a designated independent reviewer can attest. If no eligible reviewer exists, this month stays unattested.</div>';
            }
        } catch (err) {
            document.getElementById('attestGate').textContent = 'Could not check reviewer status.';
        }
    }

    document.getElementById('loadBtn').addEventListener('click', function () {
        month = document.getElementById('f_month').value || month;
        load();
    });
    document.getElementById('attestBtn').addEventListener('click', async function () {
        var note = document.getElementById('att_note').value;
        if (!note.trim()) { acmToast('Write an attestation note first.', false); return; }
        try {
            await acmApi('office_attest', { month: month, note: note, report_fingerprint: fingerprint }, 'POST');
            acmToast('Month attested.');
            document.getElementById('att_note').value = '';
            load();
        } catch (err) { acmToast(err.message, false); }
    });

    load();
    gate();
})();
</script>
<?php acm_chrome_close(); ?>
