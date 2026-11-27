<?php
/**
 * ACM S11 — Self-service activation requests.
 * ------------------------------------------------------------
 * The queue a student's request lands in when they submit their ID
 * number on the public endpoint. Design:
 * docs/design/acm-self-service-activation.md §3.2.
 *
 * Roles: super_admin, accounts_office (AcmPolicy::RES_REQUEST).
 *
 * WHAT THIS SCREEN IS CAREFUL ABOUT
 *
 *   1. It never auto-matches. `candidates` is a suggestion list; the
 *      officer picks the person, and `match_basis` records whether the
 *      code and name actually agreed. A "manual" basis is visible in
 *      the detail panel because that is the case an audit needs to find.
 *
 *   2. Approval is a single POST. Everything after it — issuing the
 *      code, reading it to the student — happens in the existing
 *      ticket screen, which the response links to. This page does not
 *      duplicate the code ceremony.
 *
 *   3. It shows the repeat count. `request_count` > 1 means either a
 *      stuck student or someone probing; both deserve a look before
 *      approval.
 */
define('ACM_BOOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/acm_layout.php';
require_once __DIR__ . '/../acm/autoload.php';

use SSMS\Acm\Identity\AcmPersonService;
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

$fStatus = (string)($_GET['status'] ?? 'pending_review');
if (!in_array($fStatus, ['pending_review', 'approved', 'rejected', 'expired', 'canceled'], true)) {
    $fStatus = 'pending_review';
}
// Lookup by the reference the member was given, or their ID number, name,
// or the last digits of their phone. Without this the only way to find one
// request was to scroll the status tabs -- and request_ref is the ONLY
// string the member is handed, so it is the one an officer arrives with.
$fQ = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($fQ) > 120) {
    $fQ = mb_substr($fQ, 0, 120);
}
$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit = 25;

$rows = [];
$pendingCount = 0;
$pageError = '';
try {
    $requests = new AcmActivationRequestService($pdo);
    $rows = $requests->queue($actorId, $fStatus, $limit, $offset, $fQ);
    // The chrome badge means "work waiting", not "rows on this page".
    // Asking for the pending count keeps it correct on every tab.
    // Unfiltered and uncapped by the search: the badge means "work
    // waiting", not "rows matching what I typed".
    if ($fStatus === 'pending_review' && $fQ === '') {
        $pendingCount = count($rows) + $offset;
    } else {
        $pendingCount = count($requests->queue($actorId, 'pending_review', 100, 0));
    }
} catch (\Throwable $e) {
    error_log('acm_requests_queue_failed');
    $pageError = 'The request queue could not be loaded.';
}

/** The only page where a request is approved or rejected. */
$canDecide = AcmPolicy::can($role, AcmPolicy::RES_REQUEST, 'approve');
$statusLabel = [
    'pending_review' => 'Pending review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'expired' => 'Expired',
    'canceled' => 'Canceled',
];

acm_head('Activation requests');
acm_chrome_open('requests', 'Activation requests', 'Self-service requests from members and parents', $pendingCount);
?>

<?php /* The heading and the one-liner are already in the chrome header
         (acm_chrome_open takes both). Repeating them here printed
         "Activation requests" twice on every visit. */ ?>
<p class="sub">
    A member submits their ID number from the member app or web; it lands here.
    Approving creates the ticket — it does not issue the code. Open the ticket
    afterwards to issue and read out the code.
</p>

<?php if ($pageError !== ''): ?>
    <div class="acm-alert err"><i class="fa-solid fa-triangle-exclamation"></i>
        <?= e($pageError) ?> Apply migration 099 if this is a new deployment.
    </div>
<?php endif; ?>

<form method="get" class="acm-filterbar">
    <?php /* The status tabs stay as links so they keep working without JS;
             the search submits and carries the current tab with it. */ ?>
    <?php foreach ($statusLabel as $key => $label): ?>
        <a class="btn <?= $key === $fStatus ? '' : 'bo' ?>"
           href="/admin/acm_requests.php?status=<?= urlencode($key) ?><?= $fQ !== '' ? '&amp;q=' . urlencode($fQ) : '' ?>">
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>
    <input type="hidden" name="status" value="<?= e($fStatus) ?>">
    <input class="inp" type="search" name="q" value="<?= e($fQ) ?>"
           placeholder="Reference, ID number, name…" aria-label="Search requests">
    <button class="btn bo" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
    <?php if ($fQ !== ''): ?>
        <a class="btn bo" href="/admin/acm_requests.php?status=<?= urlencode($fStatus) ?>">Clear</a>
    <?php endif; ?>
</form>

<?php if ($rows === []): ?>
    <div class="acm-empty">
        <i class="fa-solid fa-inbox"></i>
        <?php if ($fQ !== '') : ?>
            <p>No <?= e(strtolower($statusLabel[$fStatus])) ?> requests match
               &ldquo;<?= e($fQ) ?>&rdquo;. The search covers the reference, the
               ID number given, the name, and the last four digits of the phone.
               Try another status tab &mdash; the match may have been decided.</p>
        <?php else: ?>
            <p>No <?= e(strtolower($statusLabel[$fStatus])) ?> requests.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="tw"><table>
        <thead>
            <tr>
                <th>Reference</th>
                <th>Type</th>
                <th>ID number given</th>
                <th>Name given</th>
                <th>Phone</th>
                <th>Repeats</th>
                <th>Received</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php
            $repeats = (int)($row['request_count'] ?? 1);
            $claimedBy = (int)($row['claimed_by'] ?? 0);
            ?>
            <tr>
                <td><code><?= e((string)$row['request_ref']) ?></code></td>
                <td><?= e((string)$row['account_type']) ?></td>
                <td><strong><?= e((string)$row['member_code']) ?></strong></td>
                <td><?= e((string)$row['name_key']) ?></td>
                <td><?= $row['phone_last4'] !== null ? '…' . e((string)$row['phone_last4']) : '—' ?></td>
                <td>
                    <?php if ($repeats > 1): ?>
                        <span class="bg bg-w" title="Identical submissions collapsed onto this request">
                            <?= $repeats ?>&times;
                        </span>
                    <?php else: ?>
                        <span class="dim">1</span>
                    <?php endif; ?>
                </td>
                <td class="dim"><?= e((string)$row['created_at']) ?></td>
                <td>
                    <?php if ($claimedBy > 0 && $row['status'] === 'pending_review'): ?>
                        <span class="bg bg-info">claimed</span>
                    <?php else: ?>
                        <span class="bg <?= acm_status_bg((string)$row['status']) ?>">
                            <?= e($statusLabel[(string)$row['status']] ?? (string)$row['status']) ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <a class="btn bo" href="/admin/acm_request.php?id=<?= (int)$row['id'] ?>">
                        <i class="fa-solid fa-arrow-right"></i> Open
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>

    <?php $qs = $_GET; ?>
    <div class="acm-btn-row">
        <?php if ($offset > 0): $qs['offset'] = max(0, $offset - $limit); ?>
            <a class="btn bo" href="?<?= e(http_build_query($qs)) ?>"><i class="fa-solid fa-arrow-left"></i> Newer</a>
        <?php endif; ?>
        <?php if (count($rows) === $limit): $qs['offset'] = $offset + $limit; ?>
            <a class="btn bo" href="?<?= e(http_build_query($qs)) ?>">Older <i class="fa-solid fa-arrow-right"></i></a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php acm_chrome_close(); ?>
