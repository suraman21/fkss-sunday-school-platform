<?php
/**
 * ACM P0 — Ticket detail. Identity evidence + manual decision.
 * Roles: super_admin, registrar, account_activator.
 */
define('ACM_BOOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/acm_layout.php';
require_once __DIR__ . '/backend/services/Acm/AcmPolicy.php';
require_once __DIR__ . '/backend/services/Acm/AcmPersonService.php';
require_once __DIR__ . '/backend/services/Acm/AcmTicketService.php';

use App\Services\Acm\AcmPersonService;
use App\Services\Acm\AcmPolicy;
use App\Services\Acm\AcmTicketService;

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
$canDecide = $isOpen && !$isMine && AcmPolicy::can($role, 'ticket', 'approve');
$dups = $ticket['live_duplicates'] ?? [];
$hasDupWarnings = !empty($dups['same_phone']) || !empty($dups['same_name_dob']);
$od = !empty($ticket['sla_due']) && strtotime((string)$ticket['sla_due']) < time() && $isOpen;

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
    <?php if ($isMine && $isOpen): ?>
    <div class="acm-alert warn" style="margin-top:.8rem"><i class="fa-solid fa-scale-balanced"></i> Maker–checker: you submitted this ticket, so the decision buttons are disabled for you.</div>
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
        <label class="flbl" for="acmNote">Note / reason (required to reject or request changes)</label>
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
        <button class="btn bp" data-act="approve"><i class="fa-solid fa-check"></i> Approve &amp; provision</button>
        <button class="btn bo" data-act="request_changes"><i class="fa-solid fa-pen"></i> Request changes</button>
        <button class="btn bd" data-act="reject"><i class="fa-solid fa-xmark"></i> Reject</button>
        <?php endif; ?>
    </div>
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
})();
</script>
<?php acm_chrome_close(); ?>
