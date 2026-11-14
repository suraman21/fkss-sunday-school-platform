<?php
/**
 * ACM S1 — Ticket detail. Identity evidence + manual decision.
 * Roles: super_admin, accounts_office.
 */
define('ACM_BOOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/acm_layout.php';
require_once __DIR__ . '/../acm/autoload.php';

use SSMS\Acm\Accounts\AccountProvisioner;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Activation\ActivationService;
use SSMS\Acm\Device\DeviceCrypto;
use SSMS\Acm\Device\DeviceService;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Office\AcmOfficeService;
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

$ticket = null;
$openCount = 0;
$pageError = '';
try {
    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
    $ticket = $tickets->get((int)($_GET['id'] ?? 0));
    $openCount = (int)($tickets->overviewStats($role, $actorId)['open'] ?? 0);
} catch (\Throwable $e) {
    error_log('ACM ticket failure: ' . $e->getMessage());
    $pageError = 'Ticket is temporarily unavailable.';
}
if ($ticket === null && $pageError === '') {
    http_response_code(404);
    echo 'Ticket was not found.';
    exit;
}

$status = (string)($ticket['status'] ?? '');
$isOpen = in_array($status, ['submitted', 'in_review', 'changes_requested'], true);
$isMine = $ticket !== null && (int)($ticket['submitter_id'] ?? 0) === $actorId;
$canClaim = $isOpen && $status === 'submitted' && AcmPolicy::can($role, 'ticket', 'claim');
$canRelease = $status === 'in_review' && AcmPolicy::can($role, 'ticket', 'claim')
    && ($role === 'super_admin' || (int)($ticket['assignee_id'] ?? 0) === $actorId);
$canNote = $isOpen && AcmPolicy::can($role, 'ticket', 'note');
$officeMode = 'separated';
$isDesignee = false;
try {
    $officeSvc = new AcmOfficeService($pdo);
    $officeMode = $officeSvc->mode();
    $isDesignee = $officeSvc->isActiveApprover($actorId);
} catch (\Throwable $e) {
    $officeMode = 'separated';
}
// Separated: maker-checker by user ID. Solo: designee-only, and a
// designee may self-decide with a recorded reason (server-enforced,
// flagged in the monthly audit).
$modeAllowsDecide = $officeMode === 'solo' ? $isDesignee : !$isMine;
$canDecide = $isOpen && AcmPolicy::can($role, 'ticket', 'approve') && $modeAllowsDecide;
$soloSelf = $isOpen && $isMine && $officeMode === 'solo' && $isDesignee;
$dups = $ticket['live_duplicates'] ?? [];
$hasDupWarnings = !empty($dups['same_phone']) || !empty($dups['same_name_dob']);
$od = !empty($ticket['sla_due']) && strtotime((string)$ticket['sla_due']) < time() && $isOpen;
// S3 ceremony panel: issuing needs an issuable state + code.issue;
// viewing needs code.view. codeStatus() throws pre-082, so the
// panel stays hidden until the migration lands (safe reader).
$canIssueCode = in_array($status, AcmTicketService::ISSUABLE_STATUSES, true)
    && AcmPolicy::can($role, 'code', 'issue');
$canCancel = in_array($status, AcmTicketService::CANCELABLE_STATUSES, true)
    && AcmPolicy::can($role, 'ticket', 'cancel');
$codeInfo = null;
if ($ticket !== null && AcmPolicy::can($role, 'code', 'view')) {
    try {
        $codeInfo = (new ActivationService(
            $pdo, $tickets, new AcmPersonService($pdo)
        ))->codeStatus((int)$ticket['id']);
    } catch (\Throwable $e) {
        $codeInfo = null;
    }
}
$showCeremony = $codeInfo !== null && in_array($status, [
    'approved', 'code_issued', 'grant_verified', 'account_pending_device',
    'expired', 'activation_failed',
], true);
// S4 device panel: ticket account's registered devices. Null rows =
// registry unavailable (pre-084 safe reader, like codeStatus above).
// The page only LISTS (revoke goes through api_acm, which owns the
// mysqli-backed family cascade), so no revoker is injected here.
$canManageDevices = AcmPolicy::can($role, 'device', 'manage');
$deviceRows = null;
$deviceUserId = null;
// S5: live bound-family counts per device uuid. Separate try: a
// counts failure (pre-086) hides the BADGES, never the S4 rows.
$boundFamilyCounts = null;
if ($ticket !== null && $canManageDevices) {
    try {
        $deviceUserId = (new AccountProvisioner($pdo))->userIdFor(
            (int)$ticket['person_id'], (string)$ticket['account_type']
        );
        $deviceRows = $deviceUserId === null ? [] : (new DeviceService($pdo, $tickets))->listForUser(
            $deviceUserId
        );
    } catch (\Throwable $e) {
        $deviceRows = null;
    }
    if ($deviceRows !== null && $deviceUserId !== null) {
        try {
            $boundFamilyCounts = (new DeviceService($pdo, $tickets))->boundFamilyCounts($deviceUserId);
        } catch (\Throwable $e) {
            $boundFamilyCounts = null;
        }
    }
}
$showDevices = $canManageDevices && $deviceUserId !== null;

acm_head((string)($ticket['ticket_no'] ?? 'Ticket'));
acm_chrome_open('queue', (string)($ticket['ticket_no'] ?? 'Ticket'), 'Activation review', $openCount);
?>
<?php if ($pageError !== ''): ?>
<div class="acm-alert err"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($pageError) ?></div>
<?php endif; ?>

<div class="cd">
    <div class="ct"><i class="fa-solid fa-ticket"></i> <?= e((string)($ticket['ticket_no'] ?? '')) ?>
        <span class="bg <?= acm_status_bg($status) ?>"><?= e(str_replace('_', ' ', $status)) ?></span>
        <span class="bg bg-n"><?= e((string)($ticket['account_type'] ?? '')) ?> account</span>
        <span class="bg bg-n"><?= e($officeMode) ?> mode</span>
        <?php if (!empty($ticket['self_approved'])): ?>
        <span class="bg bg-p">self-approved</span>
        <?php endif; ?>
    </div>
    <dl class="acm-kv">
        <dt>Person</dt><dd><a href="/admin/acm_person.php?id=<?= (int)$ticket['person_id'] ?>"><span class="amharic"><?= e((string)($ticket['full_name_am'] ?? '')) ?></span></a></dd>
        <dt>Submitted by</dt><dd><?= e((string)($ticket['submitter_name'] ?? '—')) ?> · <?= e((string)($ticket['created_at'] ?? '')) ?></dd>
        <dt>SLA due</dt><dd class="<?= $od ? 'acm-overdue' : '' ?>"><?= e((string)($ticket['sla_due'] ?? '—')) ?><?= $od ? ' · OVERDUE' : '' ?></dd>
        <dt>Assignee</dt><dd><?= e((string)($ticket['assignee_name'] ?? 'Unclaimed')) ?></dd>
        <?php if (!empty($ticket['decided_by'])): ?>
        <dt>Decided by</dt><dd><?= e((string)($ticket['decider_name'] ?? '')) ?> · <?= e((string)($ticket['decided_at'] ?? '')) ?></dd>
        <dt>Decision note</dt><dd><?= e((string)($ticket['decision_note'] ?? '—')) ?></dd>
        <?php endif; ?>
    </dl>
    <?php if ($isMine && $isOpen && $officeMode !== 'solo'): ?>
    <div class="acm-alert warn" style="margin-top:.8rem"><i class="fa-solid fa-scale-balanced"></i> Maker–checker: you submitted this ticket, so the decision buttons are disabled for you.</div>
    <?php endif; ?>
    <?php if ($officeMode === 'solo' && $isOpen && !$isDesignee): ?>
    <div class="acm-alert warn" style="margin-top:.8rem"><i class="fa-solid fa-user-lock"></i> Solo mode: only a designated approver can decide tickets.</div>
    <?php endif; ?>
    <?php if ($soloSelf): ?>
    <div class="acm-alert warn" style="margin-top:.8rem"><i class="fa-solid fa-triangle-exclamation"></i> Solo self-approval: a written reason is required and this decision will be flagged in the monthly audit.</div>
    <?php endif; ?>
    <div class="acm-btn-row">
        <?php if ($canClaim): ?>
        <button class="btn bo" data-act="claim"><i class="fa-solid fa-hand"></i> Claim for review</button>
        <?php endif; ?>
        <?php if ($canRelease): ?>
        <button class="btn bo" data-act="release"><i class="fa-solid fa-rotate-left"></i> Release claim</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($hasDupWarnings): ?>
<div class="acm-alert warn"><i class="fa-solid fa-triangle-exclamation"></i> <strong>Duplicate warnings are open.</strong>
    Verify the physical parent/member ID before approving — approval requires the confirmation checkbox below.
    <?php if (!empty($dups['same_phone'])): ?>
    <br>Same phone: <?= e(implode(', ', array_map(function ($r) { return '#' . (int)$r['id'] . ' ' . (string)($r['full_name_am'] ?? ''); }, $dups['same_phone']))) ?>
    <?php endif; ?>
    <?php if (!empty($dups['same_name_dob'])): ?>
    <br>Same name + birth date: <?= e(implode(', ', array_map(function ($r) { return '#' . (int)$r['id']; }, $dups['same_name_dob']))) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="acm-grid2">
    <div class="cd">
        <div class="ct"><i class="fa-solid fa-id-card"></i> Person evidence</div>
        <dl class="acm-kv">
            <dt>Name (AM)</dt><dd><span class="amharic"><?= e((string)($ticket['full_name_am'] ?? '')) ?></span></dd>
            <dt>Name (EN)</dt><dd><?= e((string)($ticket['full_name_en'] ?? '—')) ?></dd>
            <dt>Father</dt><dd><?= e((string)($ticket['father_name'] ?? '—')) ?></dd>
            <dt>Phone</dt><dd><?= e((string)($ticket['phone'] ?? '—')) ?></dd>
            <dt>Email</dt><dd><?= e((string)($ticket['email'] ?? '—')) ?></dd>
            <dt>ID document</dt><dd><?= e(trim((string)($ticket['id_doc_type'] ?? '') . ' ' . (string)($ticket['id_doc_ref'] ?? '')) ?: '—') ?></dd>
            <dt>Birth date</dt><dd><?= e((string)($ticket['date_of_birth'] ?? '—')) ?></dd>
        </dl>
    </div>
    <div class="cd">
        <div class="ct"><i class="fa-solid fa-link"></i> Linked records</div>
        <?php $member = $ticket['member'] ?? null; $guardian = $ticket['guardian'] ?? null; ?>
        <?php if (is_array($member)): ?>
        <dl class="acm-kv">
            <dt>Member</dt><dd><span class="amharic"><?= e((string)($member['full_name_am'] ?? '')) ?></span> (<?= e((string)($member['student_name'] ?? '')) ?>)</dd>
            <dt>Code</dt><dd><?= e((string)($member['member_code'] ?? '—')) ?> · <?= e((string)($member['registration_type'] ?? '')) ?></dd>
            <dt>Section</dt><dd><?= e((string)($member['current_section'] ?? '—')) ?> · <?= e((string)($member['education_level'] ?? '')) ?></dd>
            <dt>Baptismal</dt><dd><?= e((string)($member['baptismal_name'] ?? '—')) ?></dd>
        </dl>
        <?php else: ?>
        <div style="font-size:.8rem;color:var(--dim)">No member record linked.</div>
        <?php endif; ?>
        <?php if (is_array($guardian)): ?>
        <dl class="acm-kv" style="margin-top:.7rem">
            <dt>Guardian</dt><dd><?= e((string)($guardian['display_name'] ?? '')) ?></dd>
            <dt>Phones</dt><dd><?= e((string)($guardian['phone_primary'] ?? '—')) ?> / <?= e((string)($guardian['phone_alt'] ?? '—')) ?></dd>
            <dt>Address</dt><dd><?= e(trim((string)($guardian['city'] ?? '') . ' ' . (string)($guardian['sub_city'] ?? '') . ' ' . (string)($guardian['woreda'] ?? '')) ?: '—') ?></dd>
        </dl>
        <?php else: ?>
        <div style="font-size:.8rem;color:var(--dim);margin-top:.5rem">No guardian record linked.</div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($ticket['family_links'])): ?>
<div class="cd">
    <div class="ct"><i class="fa-solid fa-people-roof"></i> Family links (member ↔ guardians)</div>
    <div class="tw"><table>
        <thead><tr><th>Guardian</th><th>Relation</th><th>Primary</th><th>Phone</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($ticket['family_links'] as $link): ?>
            <tr>
                <td><?= e((string)($link['display_name'] ?? '')) ?></td>
                <td><?= e((string)($link['relation'] ?? '')) ?></td>
                <td><?= !empty($link['is_primary']) ? 'Yes' : 'No' ?></td>
                <td><?= e((string)($link['phone_primary'] ?? '—')) ?></td>
                <td><?= e((string)($link['status'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($canNote || $canDecide): ?>
<div class="cd">
    <div class="ct"><i class="fa-solid fa-gavel"></i> Review actions</div>
    <div class="full" style="margin-bottom:.6rem">
        <label class="flbl" for="acmNote">Note / reason<?= $soloSelf ? ' (required — solo self-approval)' : ' (required to reject or request changes)' ?></label>
        <textarea class="inp" id="acmNote" rows="3" maxlength="2000" placeholder="What did you verify?"></textarea>
    </div>
    <?php if ($canDecide && $hasDupWarnings): ?>
    <label class="acm-check" style="margin-bottom:.6rem"><input type="checkbox" id="acmVerified"> I physically verified the parent/member ID against these details and accept the duplicate warnings.</label>
    <?php endif; ?>
    <div class="acm-btn-row">
        <?php if ($canNote): ?>
        <button class="btn bo" data-act="note"><i class="fa-solid fa-comment"></i> Add note</button>
        <?php endif; ?>
        <?php if ($canDecide): ?>
        <button class="btn bp" data-act="approve"><i class="fa-solid fa-check"></i> Approve</button>
        <button class="btn bo" data-act="request_changes"><i class="fa-solid fa-pen"></i> Request changes</button>
        <button class="btn bd" data-act="reject"><i class="fa-solid fa-xmark"></i> Reject</button>
        <?php endif; ?>
    </div>
    <?php if ($canDecide): ?>
    <div style="font-size:.75rem;color:var(--dim);margin-top:.5rem"><i class="fa-solid fa-circle-info"></i> Approval authorizes code issuance — no account is created until the user completes activation in the app.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($showCeremony): ?>
<div class="cd">
    <div class="ct"><i class="fa-solid fa-key"></i> Activation code</div>
    <?php $liveCode = is_array($codeInfo['active'] ?? null) ? $codeInfo['active'] : null; ?>
    <?php $lastCode = is_array($codeInfo['last'] ?? null) ? $codeInfo['last'] : null; ?>
    <?php if ($liveCode !== null): ?>
    <dl class="acm-kv">
        <dt>Selector</dt><dd><?= e((string)($liveCode['selector'] ?? '')) ?> · <?= e((string)($liveCode['prefix'] ?? '')) ?> · <?= e((string)($liveCode['purpose'] ?? '')) ?></dd>
        <dt>Expires</dt><dd><?= e((string)($liveCode['expires_at'] ?? '')) ?><?= empty($liveCode['live']) ? ' · EXPIRED — reissue to resume' : '' ?></dd>
        <dt>Attempts left</dt><dd><?= max(0, (int)($liveCode['max_attempts'] ?? 0) - (int)($liveCode['attempts'] ?? 0)) ?> of <?= (int)($liveCode['max_attempts'] ?? 0) ?></dd>
    </dl>
    <?php elseif ($lastCode !== null): ?>
    <dl class="acm-kv">
        <dt>Last code</dt><dd><?= e((string)($lastCode['selector'] ?? '')) ?> · <?= e(str_replace('_', ' ', (string)($lastCode['status'] ?? ''))) ?></dd>
        <dt>Attempts used</dt><dd><?= (int)($lastCode['attempts'] ?? 0) ?> of <?= (int)($lastCode['max_attempts'] ?? 0) ?></dd>
    </dl>
    <?php else: ?>
    <div style="font-size:.8rem;color:var(--dim)">No code issued yet.</div>
    <?php endif; ?>
    <?php if ((int)($codeInfo['velocity_1h'] ?? 0) >= (int)($codeInfo['velocity_limit'] ?? 30)): ?>
    <div class="acm-alert err" style="margin-top:.6rem"><i class="fa-solid fa-triangle-exclamation"></i> Unusual verification activity in the last hour (<?= (int)($codeInfo['velocity_1h'] ?? 0) ?> failures). This ticket may be under attack — consider canceling it.</div>
    <?php endif; ?>
    <div id="acmCodeOnce" class="acm-alert warn" style="display:none;margin-top:.6rem"></div>
    <div class="acm-btn-row" style="margin-top:.6rem">
        <?php if ($canIssueCode): ?>
        <select class="inp" id="acmIssuePurpose" aria-label="Code purpose" style="max-width:12rem">
            <option value="activation">Activation code</option>
            <option value="device_add">Add-a-device code</option>
            <option value="reset">Recovery code</option>
        </select>
        <button class="btn bp" id="acmIssueBtn"><i class="fa-solid fa-key"></i> <?= $liveCode !== null ? 'Reissue code' : 'Issue code' ?></button>
        <?php endif; ?>
        <?php if ($canCancel): ?>
        <input class="inp" id="acmCancelReason" maxlength="2000" placeholder="Cancel reason (required)" style="flex:1;min-width:12rem" aria-label="Cancel reason">
        <button class="btn bd" id="acmCancelBtn"><i class="fa-solid fa-ban"></i> Cancel ticket</button>
        <?php endif; ?>
    </div>
    <?php if ($canIssueCode): ?>
    <div style="font-size:.75rem;color:var(--dim);margin-top:.5rem"><i class="fa-solid fa-eye"></i> The full code shows once — read it to the user in person, then it is masked forever. Reissuing kills the previous code.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($showDevices): ?>
<div class="cd">
    <div class="ct"><i class="fa-solid fa-mobile-screen"></i> Registered devices</div>
    <?php if ($deviceRows === null): ?>
    <div style="font-size:.8rem;color:var(--dim)">Device registry unavailable (migration pending).</div>
    <?php elseif ($deviceRows === []): ?>
    <div style="font-size:.8rem;color:var(--dim)">No devices registered yet.</div>
    <?php else: ?>
    <dl class="acm-kv">
        <?php foreach ($deviceRows as $dr): ?>
        <dt><?= e((string)($dr['name'] ?? 'Unnamed device')) ?> <span class="bg bg-n"><?= e((string)($dr['status'] ?? '')) ?></span><?php if (is_array($boundFamilyCounts)): ?> <span class="bg bg-n" title="Live refresh families requiring a device proof"><?= (int)($boundFamilyCounts[(string)($dr['uuid'] ?? '')] ?? 0) ?> bound</span><?php endif; ?></dt>
        <dd><?= e(DeviceCrypto::shortFingerprint((string)($dr['fingerprint'] ?? ''))) ?> · <?= e((string)($dr['alg'] ?? '')) ?> · last seen <?= e((string)($dr['last_seen'] ?? 'never')) ?>
            <?php if (($dr['status'] ?? '') !== 'revoked'): ?>
            <button class="btn bd" data-revoke="<?= e((string)($dr['uuid'] ?? '')) ?>" style="margin-left:.6rem">Revoke</button>
            <?php endif; ?>
        </dd>
        <?php endforeach; ?>
    </dl>
    <div style="margin-top:.6rem"><input class="inp" id="acmRevokeReason" maxlength="2000" placeholder="Revoke reason (required)" style="width:100%" aria-label="Revoke reason"></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="cd">
    <div class="ct"><i class="fa-solid fa-clock-rotate-left"></i> Timeline</div>
    <ul class="acm-timeline">
        <?php foreach (($ticket['events'] ?? []) as $ev): ?>
        <li><strong><?= e(str_replace('_', ' ', (string)($ev['event'] ?? ''))) ?></strong>
            <?php if (!empty($ev['actor_name'])): ?> by <?= e((string)$ev['actor_name']) ?><?php endif; ?>
            <?php if (!empty($ev['note'])): ?> — <?= e((string)$ev['note']) ?><?php endif; ?>
            <div class="t-meta"><?= e((string)($ev['created_at'] ?? '')) ?></div>
        </li>
        <?php endforeach; ?>
    </ul>
</div>

<script>
(function () {
    var id = <?= (int)$ticket['id'] ?>;
    function note() { var el = document.getElementById('acmNote'); return el ? el.value : ''; }
    document.querySelectorAll('[data-act]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var act = btn.getAttribute('data-act');
            btn.disabled = true;
            try {
                if (act === 'claim' || act === 'release') {
                    await acmApi(act, { id: id }, 'POST');
                } else if (act === 'note') {
                    if (!note().trim()) { acmToast('Write a note first.', false); btn.disabled = false; return; }
                    await acmApi('note', { id: id, note: note() }, 'POST');
                } else {
                    if (btn.getAttribute('data-armed') !== '1') {
                        btn.setAttribute('data-armed', '1');
                        var orig = btn.innerHTML;
                        btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Click again to confirm';
                        btn._dt = setTimeout(function () {
                            btn.setAttribute('data-armed', '');
                            btn.innerHTML = orig;
                        }, 5000);
                        btn.disabled = false;
                        return;
                    }
                    clearTimeout(btn._dt);
                    var params = { id: id, decision: act, note: note() };
                    var v = document.getElementById('acmVerified');
                    if (v) { params.verified_parent_id = v.checked ? 1 : 0; }
                    await acmApi('decide', params, 'POST');
                }
                window.location.reload();
            } catch (err) {
                acmToast(err.message || 'Action failed.', false);
                btn.disabled = false;
            }
        });
    });
    // S3 ceremony buttons (no data-act: custom handlers, no reload on issue).
    var issueBtn = document.getElementById('acmIssueBtn');
    if (issueBtn) {
        issueBtn.addEventListener('click', async function () {
            issueBtn.disabled = true;
            try {
                var purposeEl = document.getElementById('acmIssuePurpose');
                var purpose = purposeEl ? purposeEl.value : 'activation';
                var d = await acmApi('code_issue', { id: id, purpose: purpose }, 'POST');
                var box = document.getElementById('acmCodeOnce');
                var code = (d.code && d.code.display) ? d.code.display : '';
                box.style.display = 'block';
                box.textContent = 'ONE-TIME CODE: ' + code
                    + ' (expires ' + ((d.code && d.code.expires_at) ? d.code.expires_at : '?')
                    + '). Read it to the user in person — reloading this page masks it forever.';
                issueBtn.innerHTML = '<i class="fa-solid fa-key"></i> Reissue code';
                issueBtn.disabled = false;
            } catch (err) {
                acmToast(err.message || 'Action failed.', false);
                issueBtn.disabled = false;
            }
        });
    }
    var cancelBtn = document.getElementById('acmCancelBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', async function () {
            var reasonEl = document.getElementById('acmCancelReason');
            var reason = reasonEl ? reasonEl.value.trim() : '';
            if (reason.length < 3) { acmToast('Write a cancel reason first (min 3 chars).', false); return; }
            if (cancelBtn.getAttribute('data-armed') !== '1') {
                cancelBtn.setAttribute('data-armed', '1');
                cancelBtn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Click again to cancel';
                setTimeout(function () {
                    cancelBtn.setAttribute('data-armed', '');
                    cancelBtn.innerHTML = '<i class="fa-solid fa-ban"></i> Cancel ticket';
                }, 5000);
                return;
            }
            cancelBtn.disabled = true;
            try {
                await acmApi('ticket_cancel', { id: id, reason: reason }, 'POST');
                window.location.reload();
            } catch (err) {
                acmToast(err.message || 'Action failed.', false);
                cancelBtn.disabled = false;
            }
        });
    }
    // S4 device revoke buttons (two-click arm, like cancel).
    document.querySelectorAll('[data-revoke]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var reasonEl = document.getElementById('acmRevokeReason');
            var reason = reasonEl ? reasonEl.value.trim() : '';
            if (reason.length < 3) { acmToast('Write a revoke reason first (min 3 chars).', false); return; }
            if (btn.getAttribute('data-armed') !== '1') {
                btn.setAttribute('data-armed', '1');
                btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Confirm revoke';
                setTimeout(function () {
                    btn.setAttribute('data-armed', '');
                    btn.innerHTML = 'Revoke';
                }, 5000);
                return;
            }
            btn.disabled = true;
            try {
                await acmApi('device_revoke', { id: id, device_uuid: btn.getAttribute('data-revoke'), reason: reason }, 'POST');
                window.location.reload();
            } catch (err) {
                acmToast(err.message || 'Action failed.', false);
                btn.disabled = false;
            }
        });
    });
})();
</script>
<?php acm_chrome_close(); ?>
