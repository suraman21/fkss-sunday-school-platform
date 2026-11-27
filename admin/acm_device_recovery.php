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
acm_chrome_open(
    'recovery',
    'Device recovery',
    'Replace a lost or replaced phone for an account that is already active',
    0
);
?>

<div class="v2-toolbar" style="margin-bottom:var(--v2-s4)">
    <a class="btn bo" href="/admin/acm_queue.php">
        <i class="fa-solid fa-arrow-left"></i> Activation queue
    </a>
    <span style="flex:1"></span>
    <span class="bg bg-info" title="What this screen can and cannot do">
        <i class="fa-solid fa-shield-halved"></i> cannot create an account, change a password, or issue an activation code
    </span>
</div>

<form method="get" class="v2-search" role="search" style="max-width:640px;margin-bottom:var(--v2-s3)">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <input type="search" name="q" value="<?= e($q) ?>"
           placeholder="Search the person by name, phone, or ID document…"
           aria-label="Search people">
    <button class="btn bp" type="submit">Search</button>
    <?php if ($q !== ''): ?>
        <a class="btn bo" href="/admin/acm_device_recovery.php">Clear</a>
    <?php endif; ?>
</form>

<?php if ($searchError !== ''): ?>
    <div class="acm-alert err">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= e($searchError) ?>
    </div>
<?php endif; ?>

<?php if ($q === ''): ?>
    <div class="cd">
        <div class="ct"><i class="fa-solid fa-mobile-screen"></i> Finding the right person</div>
        <div class="sub" style="line-height:1.65">
            <p style="margin-top:0">
                Device recovery is for a member whose account is <strong>already
                active</strong> and who has lost or replaced their phone.
            </p>
            <p>
                Opening a recovery ticket creates an auditable record. Approve it,
                then issue it an <strong>add-a-device code</strong> &mdash; that code is
                what the member redeems on the new phone.
            </p>
            <p style="margin-bottom:0;color:var(--v2-warn)">
                <i class="fa-solid fa-triangle-exclamation"></i>
                Verify the person's identity before continuing. Whoever redeems the
                code binds a device to this account, and that device can then sign in.
            </p>
        </div>
    </div>
<?php elseif ($matches === []): ?>
    <div class="v2-state">
        <i class="fa-solid fa-user-slash"></i>
        <div class="v2-state-title">No person matched “<?= e($q) ?>”</div>
        <p>
            The search covers name, phone and ID document. If the member is certain
            of their details, the office register may need correcting first — a
            recovery ticket binds a device to an account that already exists.
        </p>
        <div class="v2-state-actions">
            <a class="btn bo" href="/admin/acm_person.php">Persons &amp; accounts</a>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($matches as $person): ?>
    <?php $accounts = acm_recoverable_accounts($persons, (int)$person['id']); ?>
    <div class="v2-card" style="margin-bottom:var(--v2-s3)">
        <div class="v2-card-head <?= $accounts === [] ? 'is-warn' : 'is-ok' ?>">
            <i class="fa-solid <?= $accounts === [] ? 'fa-circle-minus' : 'fa-circle-check' ?>"
               style="color:var(--<?= $accounts === [] ? 'v2-warn' : 'v2-ok' ?>)"></i>
            <strong class="amharic" style="color:var(--v2-ink);font-size:var(--v2-t-small)">
                <?= e((string)($person['full_name_am'] ?? '')) ?>
            </strong>
            <?php if (!empty($person['full_name_en'])): ?>
                <span style="color:var(--v2-ink-3);font-size:var(--v2-t-small)">
                    <?= e((string)$person['full_name_en']) ?>
                </span>
            <?php endif; ?>
            <span style="margin-left:auto;font-size:var(--v2-t-micro);color:var(--v2-ink-3)">
                Person #<?= (int)$person['id'] ?>
                · <?= count($accounts) ?> active account<?= count($accounts) === 1 ? '' : 's' ?>
            </span>
        </div>

        <div class="v2-card-body">
            <?php if ($accounts === []): ?>
                <?php /* The old copy said "no active account" and stopped. The
                         officer needs to know which of the three other things
                         to do instead, because each has a different screen. */ ?>
                <div class="v2-rail-hint" style="margin:0">
                    This person has no <strong>active</strong> account, so there is no
                    device to replace. Different problem, different fix:
                </div>
                <dl class="v2-facts" style="margin-top:var(--v2-s3)">
                    <div class="v2-fact">
                        <dt>Activation never finished</dt>
                        <dd style="font-size:var(--v2-t-small)">Reissue the activation code
                            from their ticket in the <a href="/admin/acm_queue.php"
                            style="color:var(--v2-accent)">queue</a>.</dd>
                    </div>
                    <div class="v2-fact">
                        <dt>Account suspended</dt>
                        <dd style="font-size:var(--v2-t-small)">Reactivate it on
                            <a href="/admin/acm_person.php?id=<?= (int)$person['id'] ?>"
                            style="color:var(--v2-accent)">their person record</a> first.</dd>
                    </div>
                    <div class="v2-fact">
                        <dt>Never activated</dt>
                        <dd style="font-size:var(--v2-t-small)">They need an activation
                            request, not a recovery.</dd>
                    </div>
                </dl>
            <?php else: ?>
                <div class="v2-list">
                    <?php foreach ($accounts as $account): ?>
                        <?php $lastSeen = $account['last_login'] ?? null; ?>
                        <div class="v2-row" style="grid-template-columns:auto minmax(0,1fr) minmax(0,1fr) minmax(0,1fr) auto">
                            <span class="bg bg-p"><?= e((string)$account['account_type']) ?></span>
                            <div style="min-width:0">
                                <div class="v2-code" style="font-size:var(--v2-t-small)">
                                    <?= e((string)$account['username']) ?>
                                </div>
                                <div class="v2-row-meta">
                                    linked <?= e((string)($account['linked_at'] ?? '—')) ?>
                                </div>
                            </div>
                            <div class="v2-row-cell v2-col-hide">
                                <span style="font-size:var(--v2-t-micro);color:var(--v2-ink-3)">Last sign-in</span>
                                <span style="display:block;font-size:var(--v2-t-small)">
                                    <?= $lastSeen !== null ? e((string)$lastSeen) : 'never' ?>
                                </span>
                            </div>
                            <div class="v2-row-cell v2-col-hide">
                                <span class="bg bg-ok">active</span>
                            </div>
                            <div>
                                <button class="btn bp bs acm-recover"
                                        data-person="<?= (int)$person['id'] ?>"
                                        data-type="<?= e((string)$account['account_type']) ?>"
                                        data-username="<?= e((string)$account['username']) ?>"
                                        data-name="<?= e((string)($person['full_name_am'] ?? '')) ?>">
                                    <i class="fa-solid fa-mobile-screen"></i> Recover
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<!-- The reason is collected in a real dialog instead of window.prompt():
     the audit trail needs a considered sentence, and prompt() cannot show
     who the recovery is for or be styled, translated or keyboard-managed. -->
<div id="acmRecoverDialog" hidden
     style="position:fixed;inset:0;z-index:300;display:grid;place-items:center;
            background:rgba(16,19,26,.45);padding:var(--v2-s4)">
    <div class="v2-card" role="dialog" aria-modal="true" aria-labelledby="acmRecoverTitle"
         style="max-width:520px;width:100%">
        <div class="v2-card-head is-warn">
            <i class="fa-solid fa-mobile-screen" style="color:var(--v2-warn)"></i>
            <strong id="acmRecoverTitle" style="color:var(--v2-ink);font-size:var(--v2-t-small)">
                Open a recovery ticket
            </strong>
        </div>
        <div class="v2-card-body">
            <p class="v2-rail-hint" style="margin-top:0">
                For <strong class="amharic" id="acmRecoverWho">—</strong>.
                This creates an auditable ticket; it does not yet change the device.
            </p>
            <label class="v2-claim-label" for="acmRecoverReason" style="margin-bottom:var(--v2-s1)">
                Reason (recorded permanently)
            </label>
            <textarea class="inp" id="acmRecoverReason" rows="3" maxlength="2000"
                      style="width:100%;resize:vertical"
                      placeholder="e.g. Phone lost on 25 Nov; member present with their student ID."></textarea>
            <div class="acm-alert err" id="acmRecoverErr" style="display:none;margin-top:var(--v2-s2)"></div>
            <div class="v2-rail-actions" style="flex-direction:row;gap:var(--v2-s2);margin-top:var(--v2-s3)">
                <button class="btn bp" id="acmRecoverGo" type="button">
                    <i class="fa-solid fa-check"></i> Open the ticket
                </button>
                <button class="btn bo" id="acmRecoverCancel" type="button">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var dlg = document.getElementById('acmRecoverDialog');
    var reason = document.getElementById('acmRecoverReason');
    var err = document.getElementById('acmRecoverErr');
    var go = document.getElementById('acmRecoverGo');
    var who = document.getElementById('acmRecoverWho');
    var whoName = document.getElementById('acmRecoverWhoName');
    var current = null;
    var lastFocus = null;

    function open(btn) {
        current = {
            person: btn.getAttribute('data-person'),
            type: btn.getAttribute('data-type'),
            username: btn.getAttribute('data-username')
        };
        if (who) { who.textContent = btn.getAttribute('data-name') || current.username; }
        if (err) { err.style.display = 'none'; }
        if (reason) { reason.value = ''; }
        lastFocus = btn;
        dlg.hidden = false;
        if (reason) { reason.focus(); }
    }
    function close() {
        dlg.hidden = true;
        current = null;
        if (lastFocus) { lastFocus.focus(); }
    }

    document.querySelectorAll('.acm-recover').forEach(function (btn) {
        btn.addEventListener('click', function () { open(btn); });
    });
    document.getElementById('acmRecoverCancel').addEventListener('click', close);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) { close(); } });
    document.addEventListener('keydown', function (e) {
        if (!dlg.hidden && e.key === 'Escape') { close(); }
    });

    go.addEventListener('click', async function () {
        if (!current) { return; }
        var text = reason ? reason.value.trim() : '';
        if (text.length < 3) {
            if (err) { err.textContent = 'Write a reason of at least 3 characters.'; err.style.display = ''; }
            if (reason) { reason.focus(); }
            return;
        }
        go.disabled = true;
        try {
            // The shared helper: it owns the CSRF token and the error shape.
            var d = await acmApi('device_recover', {
                person_id: current.person,
                account_type: current.type,
                reason: text
            }, 'POST');
            close();
            window.location.href = '/admin/acm_ticket.php?id=' + (d.ticket_id || '');
        } catch (e) {
            if (err) { err.textContent = e.message; err.style.display = ''; }
            go.disabled = false;
        }
    });
})();
</script>

<?php acm_chrome_close(); ?>
