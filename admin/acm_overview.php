<?php
/**
 * ACM P0 — Overview. KPI cards + open-ticket spotlight.
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

$stats = null;
$spotlight = [];
$pageError = '';
try {
    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
    $stats = $tickets->overviewStats($role, $actorId);
    $spotlight = $tickets->queue([], 8, 0)['rows'];
} catch (\Throwable $e) {
    error_log('ACM overview failure: ' . $e->getMessage());
    $pageError = 'Statistics are temporarily unavailable.';
}

acm_head('Overview');
acm_chrome_open('overview', 'Access Control — Overview', 'Activation workload at a glance', (int)($stats['open'] ?? 0));
?>
<?php if ($pageError !== ''): ?>
<div class="acm-alert err"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($pageError) ?></div>
<?php endif; ?>
<div class="sg">
    <div class="sc"><div class="ico" style="background:rgba(245,158,11,.1);color:var(--warn)"><i class="fa-solid fa-inbox"></i></div><div class="val"><?= (int)($stats['open'] ?? 0) ?></div><div class="lbl">Open tickets</div></div>
    <div class="sc"><div class="ico" style="background:rgba(239,68,68,.1);color:var(--bad)"><i class="fa-solid fa-clock"></i></div><div class="val"><?= (int)($stats['overdue'] ?? 0) ?></div><div class="lbl">Overdue (48h SLA)</div></div>
    <div class="sc"><div class="ico" style="background:rgba(6,182,212,.1);color:var(--info)"><i class="fa-solid fa-user-check"></i></div><div class="val"><?= (int)($stats['mine'] ?? 0) ?></div><div class="lbl">Claimed by me</div></div>
    <div class="sc"><div class="ico" style="background:rgba(16,185,129,.1);color:var(--ok)"><i class="fa-solid fa-user-plus"></i></div><div class="val"><?= (int)($stats['provisioned_week'] ?? 0) ?></div><div class="lbl">Provisioned (7 days)</div></div>
</div>

<div class="cd" style="margin-top:1rem">
    <div class="ct"><i class="fa-solid fa-fire"></i> Needs attention</div>
    <?php if (empty($spotlight)): ?>
    <div class="acm-empty"><i class="fa-solid fa-circle-check"></i> Queue is clear. Nothing waiting for review.</div>
    <?php else: ?>
    <div class="tw"><table>
        <thead><tr><th>Ticket</th><th>Person</th><th>Type</th><th>Status</th><th>SLA due</th><th>Assignee</th></tr></thead>
        <tbody>
        <?php foreach ($spotlight as $row): ?>
            <?php $od = !empty($row['sla_due']) && strtotime((string)$row['sla_due']) < time(); ?>
            <tr>
                <td><a href="/admin/acm_ticket.php?id=<?= (int)$row['id'] ?>"><?= e((string)($row['ticket_no'] ?? '')) ?></a></td>
                <td><span class="amharic"><?= e((string)($row['full_name_am'] ?? '')) ?></span></td>
                <td><?= e((string)($row['account_type'] ?? '')) ?></td>
                <td><span class="bg <?= acm_status_bg((string)($row['status'] ?? '')) ?>"><?= e(str_replace('_', ' ', (string)($row['status'] ?? ''))) ?></span></td>
                <td class="<?= $od ? 'acm-overdue' : '' ?>"><?= e((string)($row['sla_due'] ?? '—')) ?></td>
                <td><?= e((string)($row['assignee_name'] ?? '—')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="acm-btn-row"><a class="btn bo" href="/admin/acm_queue.php"><i class="fa-solid fa-inbox"></i> Open full queue</a></div>
    <?php endif; ?>
</div>

<div class="cd">
    <div class="ct"><i class="fa-solid fa-circle-info"></i> Your seat</div>
    <div style="font-size:.82rem;color:var(--text)">
    <?php if ($role === 'registrar'): ?>
        Registrar: create persons, link family, submit activation tickets. Approval is done by an Account Activator (maker–checker).
    <?php elseif ($role === 'account_activator'): ?>
        Account Activator: review the queue, verify member/parent identity evidence, approve or reject with a note. You cannot decide tickets you submitted.
    <?php else: ?>
        Super Admin: full ACM access. Every decision, grant and provisioning is audit-trailed.
    <?php endif; ?>
    </div>
</div>
<?php acm_chrome_close(); ?>
