<?php
/**
 * ACM S12 — Activation requests: the queue of member-initiated claims.
 * ------------------------------------------------------------
 * WHAT CHANGED FROM S11
 *   S11 rendered five status tabs as separate button links, a search form,
 *   and then a nine-column table whose every row ended in "Open". On a
 *   working day the officer uses ONE tab (pending) and one action (verify),
 *   so the page was mostly furniture.
 *
 *   Now: one segmented control for status, one search box, and a list built
 *   for scanning — who, which ID, what they claimed, how long ago, and the
 *   single action. The count per tab is shown, so the officer can see the
 *   backlog without clicking through five tabs (recognition over recall).
 *
 * WHY THIS PAGE WAS UNSTYLED
 *   It was missing acm_head(), so no stylesheet was ever linked. That is
 *   fixed and guarded by tests/security/test_acm_console_layout.py.
 */
define('ACM_BOOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/acm_layout.php';
require_once __DIR__ . '/../acm/autoload.php';

use SSMS\Acm\Identity\AmharicNameMatcher;
use SSMS\Acm\Policy\AcmPolicy;
use SSMS\Acm\Requests\AcmActivationRequestService;

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

$fStatus = trim((string)($_GET['status'] ?? 'pending_review'));
if ($fStatus === '') {
    $fStatus = 'pending_review';
}
$fQ = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($fQ) > 120) {
    $fQ = mb_substr($fQ, 0, 120);
}
$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit = 25;

$rows = [];
$counts = [];
$pendingCount = 0;
$pageError = '';
try {
    $requests = new AcmActivationRequestService($pdo);
    $rows = $requests->queue($actorId, $fStatus, $limit, $offset, $fQ);
    // Counts for every tab, so the backlog is visible without clicking.
    // Unfiltered by the search on purpose: a badge means "work waiting",
    // not "rows matching what I typed".
    foreach (['pending_review', 'approved', 'rejected', 'expired', 'canceled'] as $s) {
        $counts[$s] = count($requests->queue($actorId, $s, 100, 0));
    }
    $pendingCount = $counts['pending_review'] ?? 0;
} catch (\Throwable $e) {
    error_log('acm_requests_queue_failed');
    $pageError = 'The request queue could not be loaded.';
}

// REFUSALS ARE WRITTEN BUT WERE NEVER READ.
//
// recordRefusal() inserts the reason for every claim that did not resolve
// (no_member, name_mismatch, ambiguous, not_active). Nothing in the console
// displayed it, so when a member said "it will not accept my ID" the office
// had no way to answer -- the difference between "your ID is not on the
// register" and "the name does not match" is exactly what the officer needs
// to fix, and precisely what the public response must NOT reveal. Read here,
// shown to staff only.
$refusals = [];
$refusalTotal = 0;
try {
    $rs = $pdo->prepare(
        'SELECT member_code, refusal_kind, match_kind, COUNT(*) AS n, MAX(created_at) AS last_at'
        . ' FROM acm_request_refusals'
        . ' WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        . ' GROUP BY member_code, refusal_kind, match_kind'
        . ' ORDER BY n DESC, last_at DESC LIMIT 12'
    );
    $rs->execute();
    $refusals = $rs->fetchAll(PDO::FETCH_ASSOC);
    $rt = $pdo->prepare(
        'SELECT COUNT(*) FROM acm_request_refusals WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
    );
    $rt->execute();
    $refusalTotal = (int)$rt->fetchColumn();
} catch (\Throwable $e) {
    // Migration 104 may not be applied yet; a missing refusal log must not
    // stop the queue from rendering.
    error_log('acm_refusal_summary_failed');
    $refusals = [];
}

$refusalLabel = [
    'no_member' => 'No member has that ID',
    'name_mismatch' => 'ID found, first name does not agree',
    'ambiguous' => 'More than one member fits that ID and name',
    'not_active' => 'Member is not active',
];

$canDecide = AcmPolicy::can($role, AcmPolicy::RES_REQUEST, 'approve');
$statusLabel = [
    'pending_review' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'expired' => 'Expired',
    'canceled' => 'Canceled',
];

/** How long ago, in the officer's terms rather than a timestamp. */
$ago = static function (string $ts): string {
    $then = strtotime($ts);
    if ($then === false) {
        return $ts;
    }
    $mins = (int)floor((time() - $then) / 60);
    if ($mins < 1) {
        return 'just now';
    }
    if ($mins < 60) {
        return $mins . ' min ago';
    }
    $hours = (int)floor($mins / 60);
    if ($hours < 24) {
        return $hours . ' hr' . ($hours === 1 ? '' : 's') . ' ago';
    }
    $days = (int)floor($hours / 24);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
};

acm_head('Activation requests');
acm_chrome_open(
    'requests',
    'Activation requests',
    'Members asking for an account. Verify a person, then approve.',
    $pendingCount,
    'requests'
);
?>

<?php if ($refusalTotal > 0): ?>
<div class="cd" style="margin-bottom:var(--v2-s4)">
    <div class="ct"><i class="fa-solid fa-ban"></i> Claims turned away (last 30 days) — <?= (int)$refusalTotal ?></div>
    <p style="font-size:.74rem;color:var(--dim);margin-bottom:.6rem">
        Each row is a person who tried to activate and did not match the register.
        The member is never told which check failed — you are, so you can fix it.
    </p>
    <div class="tw"><table>
        <thead><tr><th>Member ID typed</th><th>What failed</th><th>Name agreement</th><th>Times</th><th>Last</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($refusals as $r): ?>
            <tr>
                <td><span class="bg bg-info"><?= e((string)$r['member_code']) ?></span></td>
                <td><?= e($refusalLabel[(string)$r['refusal_kind']] ?? (string)$r['refusal_kind']) ?></td>
                <td><?= e((string)$r['match_kind']) ?></td>
                <td><?= (int)$r['n'] ?></td>
                <td><?= e($ago((string)$r['last_at'])) ?></td>
                <td>
                    <?php if ((string)$r['refusal_kind'] === 'no_member'): ?>
                        <a href="/admin/acm_person.php?q=<?= urlencode((string)$r['member_code']) ?>">Look up</a>
                    <?php elseif ((string)$r['refusal_kind'] === 'not_active'): ?>
                        <a href="/admin/dashboard.php?section=members">Open register</a>
                    <?php else: ?>
                        <span style="color:var(--dim)">name spelling</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($pageError !== ''): ?>
    <div class="acm-alert err">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= e($pageError) ?>
        Apply migration 099 if this is a new deployment.
    </div>
<?php endif; ?>

<div class="v2-toolbar">
    <nav class="v2-tabs" aria-label="Request status">
        <?php foreach ($statusLabel as $key => $label): ?>
            <a class="v2-tab"
               href="/admin/acm_requests.php?status=<?= urlencode($key) ?><?= $fQ !== '' ? '&amp;q=' . urlencode($fQ) : '' ?>"
               <?= $key === $fStatus ? 'aria-current="page"' : '' ?>>
                <?= e($label) ?>
                <?php if (isset($counts[$key])): ?>
                    <span class="v2-tab-count"><?= (int)$counts[$key] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <form method="get" class="v2-search" role="search">
        <input type="hidden" name="status" value="<?= e($fStatus) ?>">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input type="search" name="q" value="<?= e($fQ) ?>"
               placeholder="Reference, ID number, or name…"
               aria-label="Search requests">
        <button class="btn bp" type="submit">Search</button>
        <?php if ($fQ !== ''): ?>
            <a class="btn bo" href="/admin/acm_requests.php?status=<?= urlencode($fStatus) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($rows === []): ?>
    <?php if ($pageError !== ''): ?>
        <div class="v2-state">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div class="v2-state-title">The list is unknown, not empty</div>
            <p>The queue failed to load. Reload the page; if it keeps failing the
               server error log has the reason.</p>
        </div>
    <?php elseif ($fQ !== ''): ?>
        <div class="v2-state">
            <i class="fa-solid fa-magnifying-glass"></i>
            <div class="v2-state-title">Nothing matches “<?= e($fQ) ?>”</div>
            <p>
                The search covers the request reference, the ID number given, the
                name, and the last four digits of the phone. The request may also
                have been decided already — try another status.
            </p>
            <div class="v2-state-actions">
                <a class="btn bo" href="/admin/acm_requests.php?status=<?= urlencode($fStatus) ?>">
                    Clear the search
                </a>
                <a class="btn bo" href="/admin/acm_requests.php?status=approved&amp;q=<?= urlencode($fQ) ?>">
                    Look in approved
                </a>
            </div>
        </div>
    <?php elseif ($fStatus === 'pending_review'): ?>
        <div class="v2-state">
            <i class="fa-solid fa-circle-check" style="color:var(--v2-ok)"></i>
            <div class="v2-state-title">Nothing waiting</div>
            <p>Every request has been reviewed. New claims appear here as members
               submit them from the app.</p>
        </div>
    <?php else: ?>
        <div class="v2-state">
            <i class="fa-solid fa-inbox"></i>
            <div class="v2-state-title">No <?= e(strtolower($statusLabel[$fStatus] ?? 'matching')) ?> requests</div>
            <p>Nothing in this status right now.</p>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="v2-list" role="list">
        <?php foreach ($rows as $row): ?>
            <?php
            $ref = (string)$row['request_ref'];
            $repeats = (int)($row['request_count'] ?? 1);
            $claimedBy = (int)($row['claimed_by'] ?? 0);
            $name = (string)$row['name_key'];
            $initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '?';
            $status = (string)$row['status'];
            ?>
            <a class="v2-row" role="listitem"
               href="/admin/acm_request.php?id=<?= (int)$row['id'] ?>"
               aria-label="Verify request <?= e($ref) ?>">
                <div class="v2-avatar amharic" aria-hidden="true"><?= e($initial) ?></div>

                <div style="min-width:0">
                    <div class="v2-row-name amharic"><?= e($name) ?></div>
                    <div class="v2-row-meta">
                        <?= e(ucfirst((string)$row['account_type'])) ?>
                        · <?= e($ago((string)$row['created_at'])) ?>
                        <?php if ($repeats > 1): ?>
                            · <span title="The same claim was submitted more than once"><?= $repeats ?>× resubmitted</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="v2-row-cell v2-col-hide">
                    <span class="v2-code"><?= e((string)$row['member_code']) ?></span>
                </div>

                <div class="v2-row-cell v2-col-hide">
                    <span class="v2-code" style="letter-spacing:.04em;color:var(--v2-ink-3)">
                        <?= e($ref) ?>
                    </span>
                </div>

                <div>
                    <?php if ($claimedBy > 0 && $status === 'pending_review'): ?>
                        <span class="bg bg-info">claimed</span>
                    <?php else: ?>
                        <span class="bg <?= acm_status_bg($status) ?>">
                            <?= e($statusLabel[$status] ?? str_replace('_', ' ', $status)) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="v2-row-cell v2-col-hide">
                    <?php if ($row['phone_last4'] !== null): ?>
                        <span class="dim" style="font-size:var(--v2-t-micro)">phone …<?= e((string)$row['phone_last4']) ?></span>
                    <?php else: ?>
                        <span class="dim" style="font-size:var(--v2-t-micro)">no phone</span>
                    <?php endif; ?>
                </div>

                <div style="text-align:right">
                    <?php if ($status === 'pending_review' && $canDecide): ?>
                        <span class="btn bp bs">Verify <i class="fa-solid fa-arrow-right"></i></span>
                    <?php else: ?>
                        <span class="btn bo bs">Open</span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php
    $total = isset($counts[$fStatus]) ? (int)$counts[$fStatus] : count($rows);
    $hasMore = ($offset + $limit) < $total && count($rows) === $limit;
    ?>
    <div style="display:flex;align-items:center;gap:var(--v2-s3);margin-top:var(--v2-s3)">
        <span style="font-size:var(--v2-t-micro);color:var(--v2-ink-4)">
            Showing <?= count($rows) ?> of <?= $total ?>
        </span>
        <span style="flex:1"></span>
        <?php if ($offset > 0): ?>
            <a class="btn bo bs"
               href="/admin/acm_requests.php?status=<?= urlencode($fStatus) ?>&amp;offset=<?= max(0, $offset - $limit) ?><?= $fQ !== '' ? '&amp;q=' . urlencode($fQ) : '' ?>">
                <i class="fa-solid fa-chevron-left"></i> Previous
            </a>
        <?php endif; ?>
        <?php if ($hasMore): ?>
            <a class="btn bo bs"
               href="/admin/acm_requests.php?status=<?= urlencode($fStatus) ?>&amp;offset=<?= $offset + $limit ?><?= $fQ !== '' ? '&amp;q=' . urlencode($fQ) : '' ?>">
                Next <i class="fa-solid fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
// "/" focuses the search box, the one keyboard affordance worth having on a
// page whose whole job is finding one row.
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
