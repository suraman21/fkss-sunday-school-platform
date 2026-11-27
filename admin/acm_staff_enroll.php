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
} catch (\Throwable $e) {
    error_log('ACM staff-enroll queue failure: ' . $e->getMessage());
    $pageError = 'The queue is temporarily unavailable.';
}

// The sidebar count is a decoration on this page, so it gets its own try.
// Bundling it with the queue above meant a failure in overviewStats (a
// missing tickets table, say) blanked a list that had loaded perfectly well,
// and the officer was told the queue was unavailable when it was not.
try {
    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
    $openCount = (int)($tickets->overviewStats($role, $actorId)['open'] ?? 0);
} catch (\Throwable $e) {
    $openCount = 0;
}

// The states an officer works through. "consumed"/"expired" are history and
// stay reachable, but they are not the default view.
$statuses = [
    '' => 'All',
    'requested' => 'Waiting',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'consumed' => 'Consumed',
    'expired' => 'Expired',
    'canceled' => 'Canceled',
];

/** Keep the filters when a tab or page link is built. */
$url = static function (array $overrides = []) use ($fStatus, $offset, $limit): string {
    $q = array_filter([
        'status' => $fStatus,
        'offset' => $offset > 0 ? (string)$offset : '',
    ], static fn($v) => $v !== '' && $v !== null);
    foreach ($overrides as $k => $v) {
        if ($v === '' || $v === null || $v === false) {
            unset($q[$k]);
        } else {
            $q[$k] = (string)$v;
        }
    }
    return '/admin/acm_staff_enroll.php' . ($q !== [] ? '?' . http_build_query($q) : '');
};

/** "3 h" reads better than a timestamp that the officer must subtract. */
$ago = static function (?string $ts): string {
    if ($ts === null || $ts === '' || $ts === '0000-00-00 00:00:00') {
        return '—';
    }
    $then = strtotime($ts);
    if ($then === false) {
        return '—';
    }
    $diff = time() - $then;
    if ($diff < 0) {
        $h = (int)round(-$diff / 3600);
        return $h < 48 ? 'in ' . $h . ' h' : 'in ' . (int)round($h / 24) . ' days';
    }
    $mins = (int)floor($diff / 60);
    if ($mins < 1) {
        return 'just now';
    }
    if ($mins < 60) {
        return $mins . ' min ago';
    }
    $h = (int)floor($mins / 60);
    return $h < 24 ? $h . ' h ago' : (int)floor($h / 24) . ' d ago';
};

$waitingCount = 0;
foreach ($rows as $r) {
    if ((string)($r['status'] ?? '') === 'requested') {
        $waitingCount++;
    }
}

acm_head('Staff enrollment');
acm_chrome_open(
    'staff_enroll',
    'Staff enrollment',
    'Verify the staffer, then approve. Approval mints a one-time enrollment token.',
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
    <nav class="v2-tabs" aria-label="Request status">
        <?php foreach ($statuses as $val => $label): ?>
            <a class="v2-tab" href="<?= e($url(['status' => $val, 'offset' => ''])) ?>"
               <?= $val === $fStatus ? 'aria-current="page"' : '' ?>>
                <?= e($label) ?>
                <?php if ($val === '' && $waitingCount > 0): ?>
                    <span class="v2-tab-count"><?= (int)$waitingCount ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <span style="flex:1"></span>
    <span style="font-size:var(--v2-t-micro);color:var(--v2-ink-4)">
        <?= (int)$total ?> request<?= $total === 1 ? '' : 's' ?>
    </span>
</div>

<?php if ($pageError !== ''): ?>
    <div class="v2-state">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div class="v2-state-title">The list is unknown, not empty</div>
        <p>The enrollment queue failed to load. Reload the page; the server error
           log has the reason.</p>
    </div>
<?php elseif (empty($rows)): ?>
    <div class="v2-state">
        <i class="fa-solid fa-circle-check" style="color:var(--v2-ok)"></i>
        <div class="v2-state-title">Nothing <?= $fStatus === '' ? 'at all' : 'in this state' ?></div>
        <p>Staff enrollment requests appear here when a staffer asks to enroll a
           device. Approval mints a one-time token, shown once.</p>
    </div>
<?php else: ?>
    <div class="v2-list" role="list">
        <?php foreach ($rows as $row): ?>
            <?php
            $status = (string)($row['status'] ?? '');
            $pending = $status === 'requested';
            $mine = (int)($row['requester_uid'] ?? 0) === $actorId;
            $name = trim((string)($row['staff_name'] ?? ''));
            $username = trim((string)($row['staff_username'] ?? ''));
            $label = $name !== '' ? $name : ($username !== '' ? $username : 'User #' . (int)($row['user_id'] ?? 0));
            $initial = mb_strtoupper(mb_substr($label, 0, 1));
            $expired = $pending && !empty($row['expires_at'])
                && strtotime((string)$row['expires_at']) < time();
            ?>
            <div class="v2-row" role="listitem"
                 style="grid-template-columns:34px minmax(0,1.7fr) minmax(0,1fr) 104px minmax(0,1fr) 190px">
                <div class="v2-avatar" aria-hidden="true"><?= e($initial) ?></div>

                <div style="min-width:0">
                    <div class="v2-row-name"><?= e($label) ?></div>
                    <div class="v2-row-meta">
                        <?= $username !== '' ? '@' . e($username) : 'no username' ?>
                        · #<?= (int)$row['id'] ?>
                        <?php if ($mine): ?>
                            · <span title="Maker–checker: you filed this request">filed by you</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="v2-row-cell v2-col-hide">
                    <span style="font-size:var(--v2-t-micro);color:var(--v2-ink-3)">Operation</span>
                    <span class="v2-code" style="display:block;font-size:var(--v2-t-small)">
                        <?= e(str_replace('_', ' ', (string)($row['operation'] ?? '—'))) ?>
                    </span>
                </div>

                <div>
                    <span class="bg <?= acm_status_bg($status) ?>">
                        <?= e($statuses[$status] ?? str_replace('_', ' ', $status)) ?>
                    </span>
                    <?php if ($expired): ?>
                        <span class="bg bg-bad" title="The request window has passed">expired</span>
                    <?php endif; ?>
                </div>

                <div class="v2-row-cell v2-col-hide">
                    <span style="font-size:var(--v2-t-micro);color:var(--v2-ink-3)">Requested</span>
                    <span style="display:block;font-size:var(--v2-t-small)">
                        <?= e($ago((string)($row['created_at'] ?? ''))) ?>
                    </span>
                </div>

                <div style="text-align:right" id="acmAct-<?= (int)$row['id'] ?>">
                    <?php if ($pending && !$mine && !$expired): ?>
                        <button class="btn v2-ok bs" data-act="approved" data-id="<?= (int)$row['id'] ?>">
                            <i class="fa-solid fa-check"></i> Approve
                        </button>
                        <button class="btn v2-danger bs" data-act="rejected" data-id="<?= (int)$row['id'] ?>">
                            <i class="fa-solid fa-xmark"></i> Reject
                        </button>
                    <?php elseif ($pending && $mine): ?>
                        <?php /* Approving your own request is what maker–checker
                                 exists to stop, so this is a statement of why,
                                 not a disabled button with no explanation. */ ?>
                        <span class="bg bg-n" title="Maker–checker: another officer must decide"
                              style="font-size:10px">Self-filed — another officer decides</span>
                    <?php else: ?>
                        <span class="dim" style="font-size:var(--v2-t-micro)">
                            <?= ($row['decision_note'] ?? '') !== '' ? e((string)$row['decision_note']) : '—' ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
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
(function () {
    // The enrollment token is shown ONCE and is the whole point of approving,
    // so it is revealed in the row that was approved rather than in a banner
    // at the top of the page that could be missed or overwritten by the next
    // approval. Same show-once rule as an activation code.
    function revealToken(rowActions, grant) {
        if (!rowActions) { return; }
        rowActions.innerHTML = '';
        var wrap = document.createElement('div');
        wrap.style.textAlign = 'left';

        var label = document.createElement('div');
        label.style.cssText = 'font-size:10px;text-transform:uppercase;letter-spacing:.07em;'
            + 'color:var(--v2-ink-4);font-weight:600;margin-bottom:2px';
        label.textContent = 'ONE-TIME ENROLLMENT TOKEN — relay once';

        var token = document.createElement('div');
        token.className = 'v2-code';
        token.style.cssText = 'font-size:11px;word-break:break-all;color:var(--v2-ink)';
        token.textContent = grant.token || '—';

        var expiry = document.createElement('div');
        expiry.style.cssText = 'font-size:10px;color:var(--v2-ink-4);margin-top:2px';
        expiry.textContent = 'Expires ' + (grant.expires_at || '?') + ' · reloading masks it';

        var copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'btn bo bs';
        copy.style.marginTop = '6px';
        copy.innerHTML = '<i class="fa-solid fa-copy"></i> Copy';
        copy.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(grant.token || '');
                if (window.acmToast) { acmToast('Copied.', true); }
            } catch (e) {
                if (window.acmToast) { acmToast('Copy failed — select it by hand.', false); }
            }
        });

        wrap.appendChild(label);
        wrap.appendChild(token);
        wrap.appendChild(expiry);
        wrap.appendChild(copy);
        rowActions.appendChild(wrap);
        token.setAttribute('tabindex', '-1');
        token.focus();
    }

    document.querySelectorAll('button[data-act]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            // Two-step confirm: these decisions mint a credential, so a
            // stray click must not be enough.
            if (btn.getAttribute('data-armed') !== '1') {
                btn.setAttribute('data-armed', '1');
                var orig = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Confirm';
                setTimeout(function () {
                    btn.setAttribute('data-armed', '');
                    btn.innerHTML = orig;
                }, 5000);
                return;
            }
            btn.disabled = true;
            var rowActions = document.getElementById('acmAct-' + btn.getAttribute('data-id'));
            try {
                var d = await acmApi('staff_enroll_decide', {
                    id: btn.getAttribute('data-id'),
                    decision: btn.getAttribute('data-act'),
                    note: ''
                }, 'POST');
                if (d.grant && d.grant.token) {
                    revealToken(rowActions, d.grant);
                } else {
                    window.location.reload();
                }
            } catch (err) {
                acmToast(err.message || 'Action failed.', false);
                btn.disabled = false;
            }
        });
    });
})();
</script>

<?php acm_chrome_close(); ?>
