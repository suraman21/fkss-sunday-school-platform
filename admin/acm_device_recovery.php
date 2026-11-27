<?php
/**
 * ACM S11 — Device recovery for an account whose device is lost.
 * ------------------------------------------------------------
 * Design: docs/design/acm-self-service-activation.md §5.2.
 *
 * THE GAP THIS FILLS
 *   A member account is DEVICE-BOUND on purpose: a stolen code is
 *   useless without the device that holds the private key. The cost of
 *   that protection is that a lost or replaced phone locks the account
 *   out, and until now there was no way back in:
 *
 *     - acm_ticket.php only shows the code panel for ISSUABLE_STATUSES
 *       (approved, code_issued, grant_verified, expired). An account
 *       that is `active`, or a ticket at `account_pending_device`, is
 *       not issuable, so no code can be issued from it.
 *     - There is no other path that mints a device_add grant: the
 *       chained grant only exists inside an in-flight activation.
 *
 *   So a lost device meant a permanently unusable account. This screen
 *   is the sanctioned way back, and it is deliberately NARROW: it only
 *   mints a device_add grant for an account that already exists and is
 *   already active. It cannot create an account, cannot change a
 *   password, and cannot issue an ACTIVATION code.
 *
 * WHY A NEW TICKET AND NOT REOPENING THE OLD ONE
 *   Reusing the activation ticket would rewrite the history of how the
 *   account came to exist. A fresh ticket at 'approved' gives the
 *   recovery its own auditable record, and it is immediately inside
 *   ISSUABLE_STATUSES so the existing panel can issue the code without
 *   touching the ceremony.
 *
 * ROLES: accounts_office / super_admin. This is the highest-trust
 * action in the console after deactivation: whoever approves it can
 * bind their own device to someone else's account. That is why it is
 * behind the office seat, requires a reason, and is audited.
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
if (!AcmPolicy::can($role, AcmPolicy::RES_CODE, 'issue')) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

try {
    $persons = new AcmPersonService($pdo);
    $tickets = new AcmTicketService($pdo, $persons);
} catch (\Throwable $e) {
    error_log('acm_device_recovery_boot_failed');
    http_response_code(503);
    acm_head('Device recovery unavailable');
    acm_chrome_open('queue', 'Device recovery unavailable', '', 0);
    echo '<div class="acm-alert err">Recovery is unavailable right now. Check the ACM migrations.</div>';
    acm_chrome_close();
    exit;
}

// How many accounts one search returns. Bounded: this is an operator
// tool, not a directory browser.
$q = trim((string)($_GET['q'] ?? ''));
$matches = [];
$searchError = '';
if ($q !== '') {
    try {
        $matches = $persons->search($q, 20);
    } catch (\Throwable $e) {
        error_log('acm_device_recovery_search_failed');
        $searchError = 'The search could not be completed.';
    }
}

/**
 * Active accounts for a person, which is the only thing this screen may
 * act on. An account that never finished activation is a different
 * problem (reissue the activation code), and a suspended account must
 * not be bound to a new device.
 */
function acm_recoverable_accounts(AcmPersonService $persons, int $personId): array
{
    $out = [];
    foreach ($persons->accounts($personId) as $account) {
        if ((string)($account['status'] ?? '') === 'active' && (int)($account['is_active'] ?? 0) === 1) {
            $out[] = $account;
        }
    }
    return $out;
}

acm_head('Device recovery');
acm_chrome_open('queue', 'Device recovery', 'Replace a lost or replaced device', 0);
?>
<div class="acm-btn-row" style="margin-bottom:1rem">
    <a class="btn bo" href="/admin/acm_queue.php"><i class="fa-solid fa-arrow-left"></i> Activation queue</a>
</div>

<?php /* The page heading and the one-line explanation are already in the
         chrome header (acm_chrome_open takes both). Repeating them here
         printed "Device recovery" twice on every visit. */ ?>
<p class="sub">
    For a member who has lost or replaced their phone. This opens a recovery
    ticket for an account that is already active. Approve that ticket, then
    issue it an <strong>add-a-device</strong> code &mdash; the code is what the
    member redeems on their new phone. This screen cannot create an account,
    change a password, or send an activation code.
</p>

<div class="acm-alert">
    <i class="fa-solid fa-shield-halved"></i>
    Verify the person's identity before continuing. Whoever redeems the code
    binds a device to this account, and that device can then sign in.
</div>

<form method="get" class="acm-filterbar" style="margin:1rem 0">
    <input class="inp" type="text" name="q" value="<?= e($q) ?>"
           placeholder="Search the person by name, phone or ID document&hellip;">
    <button class="btn bo" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
</form>

<?php if ($searchError !== ''): ?>
    <div class="acm-alert err"><?= e($searchError) ?></div>
<?php endif; ?>

<?php if ($q !== '' && $matches === []): ?>
    <div class="acm-empty"><i class="fa-solid fa-user-slash"></i><p>No person matched that search.</p></div>
<?php endif; ?>

<?php foreach ($matches as $person): ?>
    <?php $accounts = acm_recoverable_accounts($persons, (int)$person['id']); ?>
    <div class="cd">
        <div class="ct">
            #<?= (int)$person['id'] ?> &middot;
            <span class="amharic"><?= e((string)($person['full_name_am'] ?? '')) ?></span>
            <?php if (!empty($person['full_name_en'])): ?>
                &middot; <?= e((string)$person['full_name_en']) ?>
            <?php endif; ?>
        </div>

        <?php if ($accounts === []): ?>
            <p class="dim">
                No active account on this person. If activation was never finished,
                reissue the activation code from the ticket instead.
            </p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Account</th><th>Username</th><th>Linked</th><th>Last sign-in</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td><?= e((string)$account['account_type']) ?></td>
                        <td><code><?= e((string)$account['username']) ?></code></td>
                        <td class="dim"><?= e((string)$account['linked_at']) ?></td>
                        <td class="dim"><?= $account['last_login'] !== null ? e((string)$account['last_login']) : 'never' ?></td>
                        <td>
                            <button class="btn bo acm-recover"
                                    data-person="<?= (int)$person['id'] ?>"
                                    data-type="<?= e((string)$account['account_type']) ?>"
                                    data-username="<?= e((string)$account['username']) ?>">
                                <i class="fa-solid fa-mobile-screen"></i> Open recovery ticket
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div id="acmRecoverOnce" class="acm-alert warn" style="display:none;margin-top:1rem"></div>

<script>
(function () {
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    var box = document.getElementById('acmRecoverOnce');

    document.querySelectorAll('.acm-recover').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var reason = prompt(
                'Reason for device recovery (required, min 3 characters).\n'
                + 'This is recorded in the audit trail.'
            );
            if (reason === null) { return; }
            if (reason.trim().length < 3) { alert('Write a reason (min 3 characters).'); return; }

            var fd = new FormData();
            fd.append('action', 'device_recover');
            fd.append('csrf_token', csrf());
            fd.append('person_id', btn.getAttribute('data-person'));
            fd.append('account_type', btn.getAttribute('data-type'));
            fd.append('reason', reason);

            btn.disabled = true;
            try {
                var r = await fetch('/admin/api_acm.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                var d = await r.json().catch(function () { return { status: 'error', message: 'Bad response' }; });
                if (d.status !== 'success') { throw new Error(d.message || 'Request failed'); }
                box.style.display = 'block';
                box.innerHTML = 'Recovery ticket <strong>' + d.ticket_no + '</strong> created. '
                    + 'Approve it, then issue it an add-a-device code and read the code '
                    + 'to the member. '
                    + '<a href="/admin/acm_ticket.php?id=' + d.ticket_id + '">Open ticket</a>';
            } catch (e) {
                alert(e.message);
                btn.disabled = false;
            }
        });
    });
})();
</script>

<?php acm_chrome_close(); ?>
