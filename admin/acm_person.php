<?php
/**
 * ACM S1 — Persons & accounts. Search, register, 360° view, submit ticket.
 * Roles: super_admin, accounts_office.
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

$canView = AcmPolicy::can($role, 'person', 'view');
$canEdit = AcmPolicy::can($role, 'person', 'edit');
$canTicket = AcmPolicy::can($role, 'ticket', 'create');
// S7: per-account suspend/reactivate (member/parent rows only;
// the service re-guards role + state, so hidden buttons are
// convenience, never security).
$canSuspend = AcmPolicy::can($role, 'account', 'suspend');
$canReactivate = AcmPolicy::can($role, 'account', 'reactivate');
// S8: deactivation is super_admin-only (#25); the matrix
// deliberately lacks the verb, so only the bypass grants it.
$canDeactivate = AcmPolicy::can($role, 'account', 'deactivate');
$canLifecycle = $canSuspend || $canReactivate || $canDeactivate;
$mode = 'search';
$personId = (int)($_GET['id'] ?? 0);
if ($personId > 0) {
    $mode = 'view';
}

$results = [];
$registerMatches = [];
$person = null;
$accounts = [];
$personTickets = [];
$dups = [];
$openCount = 0;
$pageError = '';
$q = trim((string)($_GET['q'] ?? ''));
try {
    $persons = new AcmPersonService($pdo);
    $tickets = new AcmTicketService($pdo, $persons);
    $openCount = (int)($tickets->overviewStats($role, $actorId)['open'] ?? 0);
    if ($mode === 'search' && $q !== '') {
        $results = $persons->search($q);
        // THE REGISTER IS THE DIRECTORY OF PEOPLE. Most members have a
        // `members` row and no acm_persons row yet, so a search that only
        // looks at acm_persons cannot find the person the office is holding a
        // paper file for. This is a READ of the register, on the same screen.
        // Nothing here writes: "Open account record" projects the person from
        // the register (person_resolve) and opens the account side. HR remains
        // the only writer of who exists.
        $stmt = $pdo->prepare(
            'SELECT m.id, m.member_code, m.student_name, m.full_name_am,'
            . ' m.father_name, m.grandfather_name, m.baptismal_name, m.gender,'
            . ' m.date_of_birth, m.status, m.current_section, m.is_teacher, m.is_staff,'
            . ' m.phone_primary, m.phone_number,'
            . ' (SELECT p.id FROM acm_persons p WHERE p.member_id = m.id ORDER BY p.id ASC LIMIT 1) AS person_id'
            . ' FROM members m'
            . " WHERE m.status <> 'archived' AND ("
            // One placeholder per occurrence: config.php sets
            // ATTR_EMULATE_PREPARES false, so a reused named parameter is
            // HY093 at runtime (pinned by test_pdo_repeated_named_placeholders).
            . ' m.student_name LIKE :q1 OR m.full_name_am LIKE :q2 OR m.father_name LIKE :q3'
            . ' OR m.grandfather_name LIKE :q4 OR m.member_code LIKE :q5'
            . ' OR m.phone_number LIKE :q6 OR m.phone_primary LIKE :q7)'
            . ' ORDER BY m.student_name ASC LIMIT 20'
        );
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 60)) . '%';
        $stmt->execute([
            ':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like,
            ':q5' => $like, ':q6' => $like, ':q7' => $like,
        ]);
        $registerMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($mode === 'view') {
        $person = $persons->find($personId);
        if ($person === null) {
            http_response_code(404);
            echo 'Person was not found.';
            exit;
        }
        $accounts = $persons->accounts($personId);
        $stmt = $pdo->prepare(
            'SELECT id, ticket_no, account_type, status, created_at FROM acm_activation_tickets'
            . ' WHERE person_id = :p ORDER BY id DESC LIMIT 20'
        );
        $stmt->execute([':p' => $personId]);
        $personTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dups = $persons->duplicates($person);
    }
} catch (\Throwable $e) {
    error_log('ACM person failure: ' . $e->getMessage());
    $pageError = 'Person data is temporarily unavailable.';
}

acm_head($mode === 'view' ? 'Person' : 'Persons & accounts');
acm_chrome_open('persons', 'Persons & accounts', 'One human, independently managed accounts', $openCount);
?>
<?php if ($pageError !== ''): ?>
<div class="acm-alert err"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($pageError) ?></div>
<?php endif; ?>

<?php if ($mode === 'search'): ?>
<div class="v2-toolbar">
    <form method="get" class="v2-search" role="search">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input type="search" name="q" value="<?= e($q) ?>"
               placeholder="Name, phone, or ID reference…" aria-label="Search persons">
        <button class="btn bp" type="submit">Search</button>
        <?php if ($q !== ''): ?>
            <a class="btn bo" href="/admin/acm_person.php">Clear</a>
        <?php endif; ?>
    </form>
    <?php /* No "Register person": HR owns registration. See
             docs/design/acm-department-boundaries.md. */ ?>
</div>
<?php if ($q !== '' && !empty($registerMatches)): ?>
<div class="cd">
    <div class="ct"><i class="fa-solid fa-book"></i> In the member register</div>
    <p style="font-size:.74rem;color:var(--dim);margin-bottom:.6rem">
        The register is HR's. People appear here automatically — nothing is registered
        in this console. "Open account record" prepares the account side from what HR
        already holds; it creates no person data and changes no register field.
    </p>
    <div class="tw"><table>
        <thead><tr><th>Code</th><th>Name</th><th>Father</th><th>Section</th><th>Status</th><th>Account</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($registerMatches as $rm): ?>
            <?php
            $hasPerson = !empty($rm['person_id']);
            $roles = [];
            if ((int)($rm['is_teacher'] ?? 0) === 1) { $roles[] = 'teacher'; }
            if ((int)($rm['is_staff'] ?? 0) === 1) { $roles[] = 'staff'; }
            ?>
            <tr>
                <td><span class="bg bg-info"><?= e((string)($rm['member_code'] ?? '—')) ?></span></td>
                <td><span class="amharic"><?= e((string)($rm['full_name_am'] ?? $rm['student_name'] ?? '')) ?></span>
                    <?php if ($roles !== []): ?>
                        <span style="font-size:.62rem;color:var(--dim)"> · <?= e(implode(', ', $roles)) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= e((string)($rm['father_name'] ?? '—')) ?></td>
                <td><?= e((string)($rm['current_section'] ?? '—')) ?></td>
                <td><?= e((string)($rm['status'] ?? '—')) ?></td>
                <td>
                    <?php if ($hasPerson): ?>
                        <a href="/admin/acm_person.php?id=<?= (int)$rm['person_id'] ?>">#<?= (int)$rm['person_id'] ?></a>
                    <?php else: ?>
                        <span style="color:var(--dim)">none yet</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$hasPerson && $canView): ?>
                        <button class="btn bp bs" data-acm-resolve="<?= (int)$rm['id'] ?>">
                            Open account record
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="cd">
    <div class="ct"><i class="fa-solid fa-users"></i> Results</div>
    <?php if ($q === ''): ?>
    <div class="v2-state">
        <i class="fa-solid fa-magnifying-glass"></i>
        <div class="v2-state-title">Find a person</div>
        <p>The search covers the Amharic and English name, the father's name,
           the phone number, and the ID document reference.</p>
    </div>
    <?php elseif (empty($results)): ?>
    <div class="v2-state">
        <i class="fa-solid fa-user-xmark"></i>
        <div class="v2-state-title">No person matches “<?= e($q) ?>”</div>
        <p>
            The search covers name, father's name, phone and ID reference, in
            this console's records and in the member register. A person who is
            not in either does not exist yet: HR registers members, and the
            register reaches this console automatically.
        </p>

    </div>
    <?php else: ?>
    <div class="tw"><table>
        <thead><tr><th>Name</th><th>Father</th><th>Phone</th><th>Member</th><th>Guardian</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($results as $row): ?>
            <tr>
                <td><span class="amharic"><?= e((string)($row['full_name_am'] ?? '')) ?></span></td>
                <td><?= e((string)($row['father_name'] ?? '—')) ?></td>
                <td><?= e((string)($row['phone'] ?? '—')) ?></td>
                <td><?= !empty($row['member_id']) ? '#' . (int)$row['member_id'] : '—' ?></td>
                <td><?= !empty($row['guardian_id']) ? '#' . (int)$row['guardian_id'] : '—' ?></td>
                <td><a href="/admin/acm_person.php?id=<?= (int)$row['id'] ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php /* The register-person form is GONE, deliberately. HR is the only source
         of truth for who exists; ACM projects the register and owns the account.
         The console opens an account record from a register row instead.
         See docs/design/acm-department-boundaries.md. */ ?>

<?php if ($mode === 'view' && is_array($person)): ?>
<div class="acm-grid2">
    <div class="cd">
        <div class="ct"><i class="fa-solid fa-id-card"></i> <span class="amharic"><?= e((string)($person['full_name_am'] ?? '')) ?></span></div>
        <dl class="acm-kv">
            <dt>Name (EN)</dt><dd><?= e((string)($person['full_name_en'] ?? '—')) ?></dd>
            <dt>Baptismal</dt><dd><?= e((string)($person['baptismal_name'] ?? '—')) ?></dd>
            <dt>Father</dt><dd><?= e((string)($person['father_name'] ?? '—')) ?></dd>
            <dt>Gender / DOB</dt><dd><?= e((string)($person['gender'] ?? '—')) ?> · <?= e((string)($person['date_of_birth'] ?? '—')) ?></dd>
            <dt>Phone</dt><dd><?= e((string)($person['phone'] ?? '—')) ?></dd>
            <dt>Email</dt><dd><?= e((string)($person['email'] ?? '—')) ?></dd>
            <dt>ID document</dt><dd><?= e(trim((string)($person['id_doc_type'] ?? '') . ' ' . (string)($person['id_doc_ref'] ?? '')) ?: '—') ?></dd>
            <dt>Links</dt><dd>member <?= !empty($person['member_id']) ? '#' . (int)$person['member_id'] : '—' ?> · guardian <?= !empty($person['guardian_id']) ? '#' . (int)$person['guardian_id'] : '—' ?></dd>
        </dl>
        <?php if (!empty($dups['same_phone']) || !empty($dups['same_name_dob'])): ?>
        <div class="acm-alert warn" style="margin-top:.7rem"><i class="fa-solid fa-triangle-exclamation"></i> Possible duplicates:
            <?php foreach (array_merge($dups['same_phone'], $dups['same_name_dob']) as $dd): ?>
            <a href="/admin/acm_person.php?id=<?= (int)$dd['id'] ?>">#<?= (int)$dd['id'] ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="cd">
        <div class="ct"><i class="fa-solid fa-key"></i> Accounts</div>
        <?php if (empty($accounts)): ?>
        <div style="font-size:.8rem;color:var(--dim)">No accounts yet — submit an activation ticket below.</div>
        <?php else: ?>
        <?php if ($canLifecycle): ?>
        <div class="acm-btn-row" style="margin:.4rem 0">
            <input class="inp" id="acmLifecycleReason" maxlength="2000" placeholder="Reason for suspend / reactivate / deactivate (required)" style="flex:1;min-width:12rem" aria-label="Lifecycle reason">
            <?php if ($canDeactivate): ?>
            <input class="inp" id="acmDeactivateConfirm" maxlength="100" placeholder="Type username to confirm deactivation" style="flex:1;min-width:10rem" aria-label="Deactivation confirm" autocomplete="off">
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="tw"><table>
            <thead><tr><th>Username</th><th>Type</th><th>Status</th><th>Last login</th><?php if ($canLifecycle): ?><th>Lifecycle</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($accounts as $acc): ?>
                <tr>
                    <td><?= e((string)($acc['username'] ?? '')) ?></td>
                    <td><?= e((string)($acc['account_type'] ?? '')) ?></td>
                    <td><?= e((string)($acc['status'] ?? '')) ?><?= (int)($acc['is_active'] ?? 1) === 1 ? '' : ' (disabled)' ?></td>
                    <td><?= e((string)($acc['last_login'] ?? 'never')) ?></td>
                    <?php if ($canLifecycle): ?>
                    <td>
                        <?php if (in_array((string)($acc['role'] ?? ''), ['member', 'parent'], true)): ?>
                            <?php if (($acc['status'] ?? '') === 'active' && (int)($acc['is_active'] ?? 1) === 1 && $canSuspend): ?>
                            <button class="btn bd" data-acm-lifecycle="account_suspend" data-acm-user="<?= (int)($acc['user_id'] ?? 0) ?>">Suspend</button>
                            <?php elseif ((($acc['status'] ?? '') === 'suspended' || ((string)($acc['status'] ?? '') === 'active' && (int)($acc['is_active'] ?? 1) !== 1)) && $canReactivate): ?>
                            <button class="btn bp" data-acm-lifecycle="account_reactivate" data-acm-user="<?= (int)($acc['user_id'] ?? 0) ?>">Reactivate</button>
                            <?php endif; ?>
                            <?php if ($canDeactivate && in_array((string)($acc['status'] ?? ''), ['active', 'suspended'], true)): ?>
                            <button class="btn bd" data-acm-lifecycle="account_deactivate" data-acm-user="<?= (int)($acc['user_id'] ?? 0) ?>" data-acm-confirm="<?= e((string)($acc['username'] ?? '')) ?>">Deactivate</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <div class="ct" style="margin-top:1rem"><i class="fa-solid fa-ticket"></i> Tickets</div>
        <?php if (empty($personTickets)): ?>
        <div style="font-size:.8rem;color:var(--dim)">No tickets.</div>
        <?php else: ?>
        <div class="tw"><table>
            <thead><tr><th>Ticket</th><th>Type</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($personTickets as $pt): ?>
                <tr>
                    <td><a href="/admin/acm_ticket.php?id=<?= (int)$pt['id'] ?>"><?= e((string)($pt['ticket_no'] ?? '')) ?></a></td>
                    <td><?= e((string)($pt['account_type'] ?? '')) ?></td>
                    <td><span class="bg <?= acm_status_bg((string)($pt['status'] ?? '')) ?>"><?= e(str_replace('_', ' ', (string)($pt['status'] ?? ''))) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canTicket): ?>
<div class="cd">
    <div class="ct"><i class="fa-solid fa-paper-plane"></i> Submit activation ticket</div>
    <form id="acmTicketForm" class="acm-form" onsubmit="return false">
        <div><label class="flbl" for="t_type">Account type *</label>
            <select class="inp" id="t_type" name="account_type"><option value="member">Member</option><option value="parent">Parent</option></select></div>
        <div><label class="flbl" for="t_note">Submitter note</label><input class="inp" id="t_note" name="note" maxlength="500" placeholder="optional context for the reviewer"></div>
        <div class="acm-btn-row full"><button class="btn bp" id="acmTicketSubmit"><i class="fa-solid fa-paper-plane"></i> Submit for review</button></div>
    </form>
    <div style="font-size:.75rem;color:var(--dim)">Member accounts need a linked member record; parent accounts need a linked guardian record (see links above).</div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
(function () {
    // "Open account record": project the person from the register, then open
    // them. No identity data is sent -- only which register row is meant.
    document.querySelectorAll('[data-acm-resolve]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            btn.disabled = true;
            try {
                var d = await acmApi('person_resolve',
                    { member_id: btn.getAttribute('data-acm-resolve') }, 'POST');
                window.location.href = '/admin/acm_person.php?id=' + d.person.id;
            } catch (err) {
                acmToast(err.message || 'Could not open the account record.', false);
                btn.disabled = false;
            }
        });
    });
    document.querySelectorAll('[data-acm-lifecycle]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var reason = document.getElementById('acmLifecycleReason');
            var text = reason ? reason.value.trim() : '';
            if (text === '') { acmToast('Reason is required.', false); return; }
            // S8: terminal deactivation needs the username
            // re-typed (user-delete's phrase-dialog precedent,
            // inline; the service re-validates everything).
            var need = btn.getAttribute('data-acm-confirm');
            if (need !== null) {
                var c = document.getElementById('acmDeactivateConfirm');
                if (!c || c.value.trim() !== need) { acmToast('Type the username to confirm deactivation.', false); return; }
            }
            btn.disabled = true;
            try {
                await acmApi(btn.getAttribute('data-acm-lifecycle'), {
                    id: btn.getAttribute('data-acm-user'),
                    reason: text
                }, 'POST');
                window.location.reload();
            } catch (err) { acmToast(err.message || 'Action failed.', false); btn.disabled = false; }
        });
    });
    var sub = document.getElementById('acmTicketSubmit');
    if (sub) {
        sub.addEventListener('click', async function () {
            sub.disabled = true;
            try {
                var d = await acmApi('ticket_create', {
                    person_id: <?= (int)$personId ?>,
                    account_type: document.getElementById('t_type').value,
                    note: document.getElementById('t_note').value
                }, 'POST');
                window.location.href = '/admin/acm_ticket.php?id=' + d.ticket.id;
            } catch (err) { acmToast(err.message || 'Submit failed.', false); sub.disabled = false; }
        });
    }
})();
</script>
<?php acm_chrome_close(); ?>
