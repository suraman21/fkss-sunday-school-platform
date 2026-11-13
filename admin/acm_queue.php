<?php
/**
 * ACM P0 — Activation queue. Filterable ticket table.
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

$fStatus = trim((string)($_GET['status'] ?? ''));
$fType = trim((string)($_GET['account_type'] ?? ''));
$fQ = trim((string)($_GET['q'] ?? ''));
$fMine = !empty($_GET['mine']);
$fOverdue = !empty($_GET['overdue']);
$limit = 25;
$offset = max(0, (int)($_GET['offset'] ?? 0));
$rows = [];
$total = 0;
$openCount = 0;
$pageError = '';
try {
    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
    $result = $tickets->queue([
        'status' => $fStatus, 'account_type' => $fType, 'q' => $fQ,
        'mine' => $fMine ? $actorId : 0, 'overdue' => $fOverdue ? 1 : 0,
    ], $limit, $offset);
    $rows = $result['rows'];
    $total = $result['total'];
    $openCount = (int)($tickets->overviewStats($role, $actorId)['open'] ?? 0);
} catch (\Throwable $e) {
    error_log('ACM queue failure: ' . $e->getMessage());
    $pageError = 'The queue is temporarily unavailable.';
}

$statuses = ['' => 'Open (all)', 'submitted' => 'Submitted', 'in_review' => 'In review',
    'changes_requested' => 'Changes requested', 'approved' => 'Approved',
    'rejected' => 'Rejected', 'provisioned' => 'Provisioned', 'archived' => 'Archived'];

acm_head('Activation queue');
acm_chrome_open('queue', 'Activation queue', 'Manual review: verify identity, then approve or reject', $openCount);
?>
<?php if ($pageError !== ''): ?>
<div class="acm-alert err"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($pageError) ?></div>
<?php endif; ?>
<form method="get" class="acm-filterbar">
    <select name="status" aria-label="Status">
        <?php foreach ($statuses as $val => $label): ?>
        <option value="<?= e($val) ?>"<?= $val === $fStatus ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="account_type" aria-label="Account type">
        <option value="">Member + Parent</option>
        <option value="member"<?= $fType === 'member' ? ' selected' : '' ?>>Member</option>
        <option value="parent"<?= $fType === 'parent' ? ' selected' : '' ?>>Parent</option>
    </select>
    <input type="search" name="q" value="<?= e($fQ) ?>" placeholder="Ticket, name, phone…" aria-label="Search">
    <label style="font-size:.78rem;color:#cbd5e1"><input type="checkbox" name="mine" value="1"<?= $fMine ? ' checked' : '' ?>> Mine</label>
    <label style="font-size:.78rem;color:#cbd5e1"><input type="checkbox" name="overdue" value="1"<?= $fOverdue ? ' checked' : '' ?>> Overdue</label>
    <button class="acm-btn acm-btn-ghost" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
</form>

<div class="acm-card">
    <h3><i class="fa-solid fa-inbox"></i> <?= (int)$total ?> ticket<?= $total === 1 ? '' : 's' ?></h3>
    <?php if (empty($rows)): ?>
    <div class="acm-empty"><i class="fa-solid fa-circle-check"></i> No tickets match these filters.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="acm-table">
        <thead><tr><th>Ticket</th><th>Person</th><th>Phone</th><th>Type</th><th>Status</th><th>Submitter</th><th>SLA due</th><th>Assignee</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php $od = !empty($row['sla_due']) && strtotime((string)$row['sla_due']) < time()
                && in_array($row['status'], ['submitted', 'in_review', 'changes_requested'], true); ?>
            <tr>
                <td><a href="/admin/acm_ticket.php?id=<?= (int)$row['id'] ?>"><?= e((string)($row['ticket_no'] ?? '')) ?></a></td>
                <td><span class="eth"><?= e((string)($row['full_name_am'] ?? '')) ?></span></td>
                <td><?= e((string)($row['phone'] ?? '—')) ?></td>
                <td><?= e((string)($row['account_type'] ?? '')) ?></td>
                <td><span class="acm-pill acm-st-<?= e((string)($row['status'] ?? '')) ?>"><?= e(str_replace('_', ' ', (string)($row['status'] ?? ''))) ?></span></td>
                <td><?= e((string)($row['submitter_name'] ?? '—')) ?></td>
                <td class="<?= $od ? 'acm-overdue' : '' ?>"><?= e((string)($row['sla_due'] ?? '—')) ?></td>
                <td><?= e((string)($row['assignee_name'] ?? '—')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php
    $qs = $_GET;
    $prev = $offset - $limit;
    $next = $offset + $limit;
    ?>
    <div class="acm-btn-row">
        <?php if ($prev >= 0): $qs['offset'] = $prev; ?>
        <a class="acm-btn acm-btn-ghost" href="?<?= e(http_build_query($qs)) ?>"><i class="fa-solid fa-arrow-left"></i> Newer</a>
        <?php endif; ?>
        <?php if ($next < $total): $qs['offset'] = $next; ?>
        <a class="acm-btn acm-btn-ghost" href="?<?= e(http_build_query($qs)) ?>">Older <i class="fa-solid fa-arrow-right"></i></a>
        <?php endif; ?>
        <span style="font-size:.75rem;color:#7c8aa5;margin-left:auto">Showing <?= (int)min($offset + 1, $total) ?>–<?= (int)min($offset + $limit, $total) ?> of <?= (int)$total ?></span>
    </div>
    <?php endif; ?>
</div>
<?php acm_chrome_close(); ?>
