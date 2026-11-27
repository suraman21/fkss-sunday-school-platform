<?php
/**
 * ACM S12 — Activation queue: the office's own ticket list.
 * ------------------------------------------------------------
 * HOW THIS DIFFERS FROM acm_requests.php, AND WHY BOTH EXIST
 *   `requests` are unverified claims from the member app. Most never reach
 *   here: if the ID and first name do not agree with the register, the
 *   platform refuses the claim and creates no row (see AcmActivationDetector
 *   and AcmClaimRefused). What survives is a person the register already
 *   recognises.
 *
 *   `queue` is the office's working list of TICKETS -- the paper trail a
 *   decision produces, whether started by a member, the office counter, or a
 *   device recovery. Same visual language as requests, different object.
 *
 * WHAT CHANGED IN THE REDESIGN
 *   The old filter bar was six controls in a row (status dropdown, type
 *   dropdown, search, "mine", "overdue", Filter button) and every row had
 *   eight columns ending in a plain ticket number. That is a form, not a
 *   tool. Now: one status segment control that shows the working states,
 *   one search field, and two toggles that the officer actually uses
 *   (assigned to me / past SLA). The eight columns became the five that
 *   drive a decision: who, what kind, its state, its deadline, and who owns
 *   it. Phone is still one click away on the ticket.
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
$overdueCount = 0;
$mineCount = 0;
$statsKnown = true;
$pageError = '';

try {
    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
    $result = $tickets->queue([
        'status' => $fStatus,
        'account_type' => $fType,
        'q' => $fQ,
        'mine' => $fMine ? $actorId : 0,
        'overdue' => $fOverdue ? 1 : 0,
    ], $limit, $offset);
    $rows = $result['rows'];
    $total = (int)$result['total'];
} catch (\Throwable $e) {
    error_log('ACM queue failure: ' . $e->getMessage());
    $statsKnown = false;
    $pageError = 'The ticket queue could not be loaded.';
}

if ($statsKnown) {
    try {
        $stats = $tickets->overviewStats($role, $actorId);
        $openCount = (int)($stats['open'] ?? 0);
        $overdueCount = (int)($stats['overdue'] ?? 0);
        $mineCount = (int)($stats['mine'] ?? 0);
    } catch (\Throwable $e) {
        error_log('ACM queue stats failure: ' . $e->getMessage());
        $statsKnown = false;
    }
}

// The states an officer works through. Everything else (issued, active,
// archived) is history and belongs in search or the ticket itself.
$working = [
    '' => 'All open',
    'submitted' => 'New',
    'in_review' => 'In review',
    'changes_requested' => 'Changes asked',
    'approved' => 'Approved',
    'code_issued' => 'Code issued',
];

/** Build a queue URL that keeps the filters the officer set. */
$url = static function (array $overrides = []) use ($fStatus, $fType, $fQ, $fMine, $fOverdue, $offset, $limit): string {
    $q = array_filter([
        'status' => $fStatus,
        'account_type' => $fType,
        'q' => $fQ,
        'mine' => $fMine ? '1' : '',
        'overdue' => $fOverdue ? '1' : '',
        'offset' => $offset > 0 ? (string)$offset : '',
    ], static fn($v) => $v !== '' && $v !== null);
    foreach ($overrides as $k => $v) {
        if ($v === '' || $v === null || $v === false) {
            unset($q[$k]);
        } else {
            $q[$k] = (string)$v;
        }
    }
    return '/admin/acm_queue.php' . ($q !== [] ? '?' . http_build_query($q) : '');
};

$statusLabel = [
    'submitted' => 'New', 'in_review' => 'In review',
    'changes_requested' => 'Changes asked', 'approved' => 'Approved',
    'code_issued' => 'Code issued', 'grant_verified' => 'Grant verified',
    'account_pending_device' => 'Pending device', 'active' => 'Active',
    'expired' => 'Expired', 'activation_failed' => 'Failed', 'canceled' => 'Canceled',
    'revoked' => 'Revoked', 'rejected' => 'Rejected', 'provisioned' => 'Provisioned',
    'archived' => 'Archived', 'draft' => 'Draft',
];
$openStates = ['submitted', 'in_review', 'changes_requested'];

/** Deadline in words: "2 days late" is actionable, a timestamp is not. */
$sla = static function (?string $due, string $status) use ($openStates): array {
    if ($due === null || $due === '' || !in_array($status, $openStates, true)) {
        return ['', ''];
    }
    $ts = strtotime($due);
    if ($ts === false) {
        return ['', ''];
    }
    $diff = $ts - time();
    $hours = (int)round(abs($diff) / 3600);
    $text = $hours < 1 ? 'due now'
        : ($hours < 48 ? $hours . ' h' : (int)round($hours / 24) . ' days');
    return $diff < 0 ? [$text . ' late', 'bad'] : ['in ' . $text, ''];
};

acm_head('Activation queue');
acm_chrome_open(
    'queue',
    'Activation queue',
    'Tickets the office is working, oldest and most overdue first',
    $openCount
);
?>

<?php if ($pageError !== ''): ?>
    <div class="acm-alert err">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= e($pageError) ?>
        This list is unknown, not empty.
    </div>
<?php endif; ?>

<div class="v2-toolbar">
    <nav class="v2-tabs" aria-label="Ticket status">
        <?php foreach ($working as $val => $label): ?>
            <a class="v2-tab"
               href="<?= e($url(['status' => $val, 'offset' => ''])) ?>"
               <?= $val === $fStatus ? 'aria-current="page"' : '' ?>>
                <?= e($label) ?>
                <?php if ($val === '' && $openCount > 0): ?>
                    <span class="v2-tab-count"><?= $openCount ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <form method="get" class="v2-search" role="search">
        <input type="hidden" name="status" value="<?= e($fStatus) ?>">
        <?php if ($fType !== ''): ?>
            <input type="hidden" name="account_type" value="<?= e($fType) ?>">
        <?php endif; ?>
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input type="search" name="q" value="<?= e($fQ) ?>"
               placeholder="Ticket number, name, or phone…" aria-label="Search tickets">
        <button class="btn bp" type="submit">Search</button>
        <?php if ($fQ !== ''): ?>
            <a class="btn bo" href="<?= e($url(['q' => ''])) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="v2-toolbar" style="margin-top:calc(-1 * var(--v2-s1))">
    <a class="v2-tab" href="<?= e($url(['mine' => $fMine ? '' : '1', 'offset' => ''])) ?>"
       style="<?= $fMine ? 'background:var(--v2-accent-soft);color:var(--v2-accent);font-weight:650' : '' ?>"
       <?= $fMine ? 'aria-current="true"' : '' ?>>
        <i class="fa-solid fa-user-check" aria-hidden="true"></i> Assigned to me
        <?php if ($mineCount > 0): ?>
            <span class="v2-tab-count"><?= $mineCount ?></span>
        <?php endif; ?>
    </a>
    <a class="v2-tab" href="<?= e($url(['overdue' => $fOverdue ? '' : '1', 'offset' => ''])) ?>"
       style="<?= $fOverdue ? 'background:var(--v2-bad-soft);color:var(--v2-bad);font-weight:650' : '' ?>"
       <?= $fOverdue ? 'aria-current="true"' : '' ?>>
        <i class="fa-solid fa-clock" aria-hidden="true"></i> Past the 48 h target
        <?php if ($overdueCount > 0): ?>
            <span class="v2-tab-count"><?= $overdueCount ?></span>
        <?php endif; ?>
    </a>
    <?php if ($fMine || $fOverdue || $fQ !== ''): ?>
        <a class="btn bo bs" href="/admin/acm_queue.php">Reset filters</a>
    <?php endif; ?>
    <span style="flex:1"></span>
    <a class="btn bo bs" href="/admin/acm_requests.php">
        <i class="fa-solid fa-user-clock"></i> Member requests
    </a>
</div>

<?php if (empty($rows)): ?>
    <div class="v2-state">
        <?php if (!$statsKnown): ?>
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div class="v2-state-title">The list is unknown, not empty</div>
            <p>The queue failed to load. Reload the page; the server error log
               has the reason.</p>
        <?php elseif ($fQ !== '' || $fMine || $fOverdue || $fStatus !== ''): ?>
            <i class="fa-solid fa-filter-circle-xmark"></i>
            <div class="v2-state-title">No tickets match these filters</div>
            <p>
                <?= $fQ !== '' ? 'Nothing matches “' . e($fQ) . '”. ' : '' ?>
                Try another status, or reset the filters.
            </p>
            <div class="v2-state-actions">
                <a class="btn bp" href="/admin/acm_queue.php">Reset filters</a>
            </div>
        <?php else: ?>
            <i class="fa-solid fa-circle-check" style="color:var(--v2-ok)"></i>
            <div class="v2-state-title">The queue is clear</div>
            <p>No open tickets. New ones appear here when the office starts an
               activation, or a member request is approved.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="v2-list" role="list">
        <?php foreach ($rows as $row): ?>
            <?php
            $status = (string)($row['status'] ?? '');
            $name = (string)($row['full_name_am'] ?? '');
            $initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '?';
            [$slaText, $slaTone] = $sla(isset($row['sla_due']) ? (string)$row['sla_due'] : null, $status);
            $assignee = trim((string)($row['assignee_name'] ?? ''));
            ?>
            <a class="v2-row" role="listitem"
               href="/admin/acm_ticket.php?id=<?= (int)$row['id'] ?>"
               aria-label="Open ticket <?= e((string)($row['ticket_no'] ?? '')) ?>">
                <div class="v2-avatar amharic" aria-hidden="true"><?= e($initial) ?></div>

                <div style="min-width:0">
                    <div class="v2-row-name amharic"><?= $name !== '' ? e($name) : 'No name on the person record' ?></div>
                    <div class="v2-row-meta v2-code">
                        <?= e((string)($row['ticket_no'] ?? '')) ?>
                        · <?= e(date('j M, H:i', strtotime((string)($row['created_at'] ?? 'now')))) ?>
                    </div>
                </div>

                <div class="v2-row-cell v2-col-hide">
                    <?= e(ucfirst((string)($row['account_type'] ?? ''))) ?>
                    <?php if (($row['phone'] ?? '') !== ''): ?>
                        <span class="dim" style="font-size:var(--v2-t-micro);display:block">
                            <?= e((string)$row['phone']) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div>
                    <span class="bg <?= acm_status_bg($status) ?>">
                        <?= e($statusLabel[$status] ?? str_replace('_', ' ', $status)) ?>
                    </span>
                </div>

                <div class="v2-row-cell v2-col-hide">
                    <?php if ($slaText !== ''): ?>
                        <span class="bg bg-<?= $slaTone === 'bad' ? 'bad' : 'n' ?>"
                              title="Target: <?= e((string)($row['sla_due'] ?? '')) ?>">
                            <i class="fa-solid fa-clock" style="font-size:.55rem"></i> <?= e($slaText) ?>
                        </span>
                    <?php else: ?>
                        <span class="dim" style="font-size:var(--v2-t-micro)">—</span>
                    <?php endif; ?>
                </div>

                <div class="v2-row-cell v2-col-hide">
                    <?php if ($assignee !== ''): ?>
                        <span style="font-size:var(--v2-t-micro)"><?= e($assignee) ?></span>
                    <?php elseif (in_array($status, $openStates, true)): ?>
                        <span class="bg bg-w" style="font-size:10px">unclaimed</span>
                    <?php else: ?>
                        <span class="dim" style="font-size:var(--v2-t-micro)">—</span>
                    <?php endif; ?>
                </div>

                <div style="text-align:right">
                    <span class="btn bo bs">Open <i class="fa-solid fa-arrow-right"></i></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <div style="display:flex;align-items:center;gap:var(--v2-s3);margin-top:var(--v2-s3)">
        <span style="font-size:var(--v2-t-micro);color:var(--v2-ink-4)">
            Showing <?= (int)min($offset + 1, $total) ?>–<?= (int)min($offset + $limit, $total) ?>
            of <?= (int)$total ?>
        </span>
        <span style="flex:1"></span>
        <?php if ($offset > 0): ?>
            <a class="btn bo bs" href="<?= e($url(['offset' => (string)max(0, $offset - $limit)])) ?>">
                <i class="fa-solid fa-chevron-left"></i> Newer
            </a>
        <?php endif; ?>
        <?php if ($offset + $limit < $total): ?>
            <a class="btn bo bs" href="<?= e($url(['offset' => (string)($offset + $limit)])) ?>">
                Older <i class="fa-solid fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || e.metaKey || e.ctrlKey) { return; }
    if (e.key === '/') {
        var box = document.querySelector('.v2-search input[type="search"]');
        if (box) { e.preventDefault(); box.focus(); }
    }
});
</script>

<?php acm_chrome_close(); ?>
