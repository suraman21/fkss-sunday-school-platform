<?php
/**
 * ACM P0 — Persons & accounts. Search, register, 360° view, submit ticket.
 * Roles: super_admin, registrar, account_activator (activator is view-only here).
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

$canCreate = AcmPolicy::can($role, 'person', 'create');
$canEdit = AcmPolicy::can($role, 'person', 'edit');
$canTicket = AcmPolicy::can($role, 'ticket', 'create');
$mode = 'search';
$personId = (int)($_GET['id'] ?? 0);
if ($personId > 0) {
    $mode = 'view';
} elseif (!empty($_GET['new'])) {
    $mode = 'new';
}

$results = [];
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

acm_head($mode === 'view' ? 'Person' : ($mode === 'new' ? 'Register person' : 'Persons & accounts'));
acm_chrome_open('persons', 'Persons & accounts', 'One human, independently managed accounts', $openCount);
?>
<?php if ($pageError !== ''): ?>
<div class="acm-alert err"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($pageError) ?></div>
<?php endif; ?>

<?php if ($mode === 'search'): ?>
<form method="get" class="acm-filterbar">
    <input class="inp" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, phone, ID ref…" aria-label="Search persons" style="min-width:260px">
    <button class="btn bo" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
    <?php if ($canCreate): ?>
    <a class="btn bp" href="/admin/acm_person.php?new=1"><i class="fa-solid fa-user-plus"></i> Register person</a>
    <?php endif; ?>
</form>
<div class="cd">
    <div class="ct"><i class="fa-solid fa-users"></i> Results</div>
    <?php if ($q === ''): ?>
    <div class="acm-empty"><i class="fa-solid fa-magnifying-glass"></i> Search by name, phone or ID reference to begin.</div>
    <?php elseif (empty($results)): ?>
    <div class="acm-empty"><i class="fa-solid fa-user-xmark"></i> No persons match. <?= $canCreate ? 'Register one with the button above.' : '' ?></div>
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

<?php if ($mode === 'new'): ?>
<div class="cd">
    <div class="ct"><i class="fa-solid fa-user-plus"></i> Register person</div>
    <?php if (!$canCreate): ?>
    <div class="acm-alert warn">Only registrars can register persons.</div>
    <?php else: ?>
    <form id="acmPersonForm" class="acm-form" onsubmit="return false">
        <div><label class="flbl" for="f_am">Full name — Amharic *</label><input class="inp" id="f_am" name="full_name_am" required maxlength="150"></div>
        <div><label class="flbl" for="f_en">Full name — English</label><input class="inp" id="f_en" name="full_name_en" maxlength="150"></div>
        <div><label class="flbl" for="f_bap">Baptismal name</label><input class="inp" id="f_bap" name="baptismal_name" maxlength="150"></div>
        <div><label class="flbl" for="f_father">Father name</label><input class="inp" id="f_father" name="father_name" maxlength="150"></div>
        <div><label class="flbl" for="f_gender">Gender</label><select class="inp" id="f_gender" name="gender"><option value="">—</option><option value="male">Male</option><option value="female">Female</option></select></div>
        <div><label class="flbl" for="f_dob">Birth date (GC)</label><input class="inp" id="f_dob" name="date_of_birth" type="date"></div>
        <div><label class="flbl" for="f_phone">Phone</label><input class="inp" id="f_phone" name="phone" maxlength="30" placeholder="09XXXXXXXX"></div>
        <div><label class="flbl" for="f_email">Email</label><input class="inp" id="f_email" name="email" type="email" maxlength="100"></div>
        <div><label class="flbl" for="f_doctype">ID document type</label><input class="inp" id="f_doctype" name="id_doc_type" maxlength="40" placeholder="Kebele ID / Passport / …"></div>
        <div><label class="flbl" for="f_docref">ID document ref</label><input class="inp" id="f_docref" name="id_doc_ref" maxlength="100"></div>
        <div><label class="flbl" for="f_member">Linked member ID (members.id)</label><input class="inp" id="f_member" name="member_id" inputmode="numeric" placeholder="optional"></div>
        <div><label class="flbl" for="f_guardian">Linked guardian ID (guardians.id)</label><input class="inp" id="f_guardian" name="guardian_id" inputmode="numeric" placeholder="optional"></div>
        <div class="full"><label class="flbl" for="f_notes">Notes</label><textarea class="inp" id="f_notes" name="notes" rows="2" maxlength="2000"></textarea></div>
        <div class="acm-btn-row full"><button class="btn bp" id="acmPersonSave"><i class="fa-solid fa-floppy-disk"></i> Save person</button></div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

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
        <div class="tw"><table>
            <thead><tr><th>Username</th><th>Type</th><th>Status</th><th>Last login</th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $acc): ?>
                <tr>
                    <td><?= e((string)($acc['username'] ?? '')) ?></td>
                    <td><?= e((string)($acc['account_type'] ?? '')) ?></td>
                    <td><?= e((string)($acc['status'] ?? '')) ?><?= (int)($acc['is_active'] ?? 1) === 1 ? '' : ' (disabled)' ?></td>
                    <td><?= e((string)($acc['last_login'] ?? 'never')) ?></td>
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
    var save = document.getElementById('acmPersonSave');
    if (save) {
        save.addEventListener('click', async function () {
            var f = document.getElementById('acmPersonForm');
            var fd = new FormData(f);
            var params = {};
            fd.forEach(function (v, k) { params[k] = v; });
            save.disabled = true;
            try {
                var d = await acmApi('person_create', params, 'POST');
                window.location.href = '/admin/acm_person.php?id=' + d.person.id;
            } catch (err) { acmToast(err.message || 'Save failed.', false); save.disabled = false; }
        });
    }
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
