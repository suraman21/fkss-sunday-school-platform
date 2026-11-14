<?php
/**
 * ACM S10 — Staff enrollment request queue. Officer approves or
 * rejects; approval mints a `staff_enroll` grant whose token is
 * shown ONCE for relay to the staffer (same show-once rule as an
 * issued code). No self-approval (server-enforced).
 * Roles: super_admin, accounts_office.
 */
define('ACM_BOOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/acm_layout.php';
require_once __DIR__ . '/../acm/autoload.php';

use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Enrollment\StaffEnrollmentRequestService;
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

$fStatus = trim((string)($_GET['status'] ?? ''));
$limit = 25;
$offset = max(0, (int)($_GET['offset'] ?? 0));
$rows = [];
$total = 0;
$openCount = 0;
$pageError = '';
try {
    $requests = new StaffEnrollmentRequestService($pdo);
    $result = $requests->queue($fStatus, $limit, $offset);
    $rows = $result['rows'];
    $total = $result['total'];
    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
    $openCount = (int)($tickets->overviewStats($role, $actorId)['open'] ?? 0);
} catch (\Throwable $e) {
    error_log('ACM staff-enroll queue failure: ' . $e->getMessage());
    $pageError = 'The queue is temporarily unavailable.';
}

$statuses = ['' => 'All', 'requested' => 'Requested', 'approved' => 'Approved',
    'rejected' => 'Rejected', 'consumed' => 'Consumed',
    'canceled' => 'Canceled', 'expired' => 'Expired'];

acm_head('Staff enrollment');
acm_chrome_open('staff_enroll', 'Staff enrollment', 'Request–approve: verify the staffer, then approve or reject', $openCount);
?>
<?php if ($pageError !== ''): ?>
<div class="acm-alert err"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($pageError) ?></div>
<?php endif; ?>
<form method="get" class="acm-filterbar">
    <select class="inp" name="status" aria-label="Status">
        <?php foreach ($statuses as $val => $label): ?>
        <option value="<?= e($val) ?>"<?= $val === $fStatus ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn bo" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
</form>

<div class="cd">
    <div class="ct"><i class="fa-solid fa-id-badge"></i> <?= (int)$total ?> request<?= $total === 1 ? '' : 's' ?></div>
    <div id="acmGrantOnce" class="acm-alert warn" style="display:none"></div>
    <?php if (empty($rows)): ?>
    <div class="acm-empty"><i class="fa-solid fa-circle-check"></i> No requests match these filters.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <div class="tw"><table>
        <thead><tr><th>#</th><th>Staffer</th><th>Operation</th><th>Status</th><th>Requested</th><th>Expires</th><th>Decided by</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php $mine = (int)($row['requester_uid'] ?? 0) === $actorId;
            $pending = (string)($row['status'] ?? '') === 'requested'; ?>
            <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><?= e((string)($row['staff_name'] ?? $row['staff_username'] ?? ('#' . (int)$row['user_id']))) ?>
                    <span style="font-size:.72rem;color:var(--dim)">(<?= e((string)($row['staff_username'] ?? '')) ?>)</span></td>
                <td><?= e((string)($row['operation'] ?? '')) ?></td>
                <td><span class="bg <?= acm_status_bg((string)($row['status'] ?? '')) ?>"><?= e(str_replace('_', ' ', (string)($row['status'] ?? ''))) ?></span></td>
                <td><?= e((string)($row['created_at'] ?? '—')) ?></td>
                <td><?= e((string)($row['expires_at'] ?? '—')) ?></td>
                <td><?= $pending ? '—' : e((string)($row['decision_note'] ?? '')) ?></td>
                <td>
                    <?php if ($pending && !$mine): ?>
                    <button class="btn bo" data-act="approved" data-id="<?= (int)$row['id'] ?>"><i class="fa-solid fa-check"></i> Approve</button>
                    <button class="btn bo" data-act="rejected" data-id="<?= (int)$row['id'] ?>"><i class="fa-solid fa-xmark"></i> Reject</button>
                    <?php elseif ($pending && $mine): ?>
                    <span style="font-size:.75rem;color:var(--dim)" title="Maker–checker: you filed this request">Self-filed — another officer decides</span>
                    <?php else: ?>
                    <span style="font-size:.75rem;color:var(--dim)">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    </div>
    <?php
    $qs = $_GET;
    $prev = $offset - $limit;
    $next = $offset + $limit;
    ?>
    <div class="acm-btn-row">
        <?php if ($prev >= 0): $qs['offset'] = $prev; ?>
        <a class="btn bo" href="?<?= e(http_build_query($qs)) ?>"><i class="fa-solid fa-arrow-left"></i> Newer</a>
        <?php endif; ?>
        <?php if ($next < $total): $qs['offset'] = $next; ?>
        <a class="btn bo" href="?<?= e(http_build_query($qs)) ?>">Older <i class="fa-solid fa-arrow-right"></i></a>
        <?php endif; ?>
        <span style="font-size:.75rem;color:var(--dim);margin-left:auto">Showing <?= (int)min($offset + 1, $total) ?>–<?= (int)min($offset + $limit, $total) ?> of <?= (int)$total ?></span>
    </div>
    <?php endif; ?>
</div>
<script>
document.querySelectorAll('button[data-act]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        if (btn.getAttribute('data-armed') !== '1') {
            btn.setAttribute('data-armed', '1');
            var orig = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Confirm';
            setTimeout(function () { btn.setAttribute('data-armed', ''); btn.innerHTML = orig; }, 5000);
            return;
        }
        btn.disabled = true;
        try {
            var d = await acmApi('staff_enroll_decide', {
                id: btn.getAttribute('data-id'),
                decision: btn.getAttribute('data-act'),
                note: ''
            }, 'POST');
            if (d.grant && d.grant.token) {
                var box = document.getElementById('acmGrantOnce');
                box.style.display = 'block';
                box.textContent = 'ONE-TIME ENROLLMENT TOKEN: ' + d.grant.token
                    + ' (expires ' + (d.grant.expires_at || '?')
                    + '). Relay it to the staffer in person — reloading this page masks it forever.';
                btn.closest('tr').querySelector('td:nth-child(4)').textContent = 'approved';
                btn.parentElement.innerHTML = '<span style="font-size:.75rem;color:var(--dim)">Decided</span>';
            } else {
                window.location.reload();
            }
        } catch (err) {
            acmToast(err.message || 'Action failed.', false);
            btn.disabled = false;
        }
    });
});
</script>
<?php acm_chrome_close(); ?>
