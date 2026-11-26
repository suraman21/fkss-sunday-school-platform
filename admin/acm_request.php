<?php
/**
 * ACM S11 — One activation request: review, then approve or reject.
 * ------------------------------------------------------------
 * Design: docs/design/acm-self-service-activation.md §3.2, §4.1.
 *
 * THE ONE RULE THIS SCREEN EXISTS TO ENFORCE
 *   The platform never decides who the requester is. It shows
 *   CANDIDATES and the officer chooses. `match_basis` is computed from
 *   what the officer picked, so a request approved against a person
 *   whose code or name did not agree records `manual` and is visible in
 *   the audit trail as such. A wrong match here gives one person
 *   another's data, which is why this is a human decision.
 *
 * APPROVAL CREATES THE TICKET, NOT THE CODE. Every ceremony step after
 * this point lives in the existing ticket screen (acm_ticket.php), so
 * code issuance keeps its single show-once path.
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

$id = (int)($_GET['id'] ?? 0);
$requests = new AcmActivationRequestService($pdo);
$request = $id > 0 ? $requests->get($id) : null;
if ($request === null) {
    http_response_code(404);
    acm_chrome_open('requests', 'Request not found', '', 0);
    echo '<div class="acm-empty"><i class="fa-solid fa-circle-question"></i><p>No request with that id.</p></div>';
    acm_chrome_close();
    exit;
}

$candidates = $requests->candidates($request);
$eventStmt = $pdo->prepare(
    'SELECT event, actor_id, note, meta, created_at FROM acm_request_events'
    . ' WHERE request_id = :rid ORDER BY id ASC LIMIT 200'
);
$eventStmt->execute([':rid' => $id]);
$events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

// Person search results, when the officer needed to look someone up
// instead of accepting a suggestion.
$q = trim((string)($_GET['q'] ?? ''));
$searchResults = [];
if ($q !== '') {
    $persons = new AcmPersonService($pdo);
    $searchResults = $persons->search($q, 20);
}

$canApprove = AcmPolicy::can($role, AcmPolicy::RES_REQUEST, 'approve');
$canReject = AcmPolicy::can($role, AcmPolicy::RES_REQUEST, 'reject');
$isPending = $request['status'] === 'pending_review';

acm_chrome_open('requests', 'Activation request ' . (string)$request['request_ref'], '', 0);
?>
<div class="acm-btn-row" style="margin-bottom:1rem">
    <a class="btn bo" href="/admin/acm_requests.php"><i class="fa-solid fa-arrow-left"></i> Back to requests</a>
</div>

<h1 class="pg">Request <code><?= e((string)$request['request_ref']) ?></code></h1>

<div class="cd">
    <div class="ct">The claim</div>
    <dl>
        <dt>Account type</dt><dd><?= e((string)$request['account_type']) ?></dd>
        <dt>ID number given</dt><dd><strong><?= e((string)$request['member_code']) ?></strong></dd>
        <dt>Name given</dt><dd><?= e((string)$request['name_key']) ?></dd>
        <dt>Phone (last 4)</dt><dd><?= $request['phone_last4'] !== null ? '&hellip;' . e((string)$request['phone_last4']) : 'not supplied' ?></dd>
        <dt>Repeats</dt>
        <dd>
            <?= (int)($request['request_count'] ?? 1) ?>
            <?php if ((int)($request['request_count'] ?? 1) > 1): ?>
                <span class="bg bg-w">review before approving</span>
            <?php endif; ?>
        </dd>
        <dt>Received</dt><dd><?= e((string)$request['created_at']) ?></dd>
        <dt>Status</dt>
        <dd><span class="bg <?= acm_status_bg((string)$request['status']) ?>">
            <?= e(str_replace('_', ' ', (string)$request['status'])) ?></span></dd>
        <?php if ($request['match_basis'] !== null): ?>
            <dt>Match basis</dt>
            <dd>
                <code><?= e((string)$request['match_basis']) ?></code>
                <?php if ($request['match_basis'] === 'manual'): ?>
                    <span class="bg bg-w">manual</span>
                <?php endif; ?>
            </dd>
        <?php endif; ?>
    </dl>
    <p class="dim">
        Everything above is what the requester TYPED. Nothing here is verified.
    </p>
</div>

<?php if ($isPending && $canApprove): ?>
<div class="cd">
    <div class="ct">Approve against a person</div>
    <p class="dim">
        Pick the person this request belongs to. If the ID number and name
        do not match the record, the approval is recorded as
        <code>manual</code> &mdash; that is allowed, but it is visible to
        audit. Approving creates the ticket; it does not issue a code.
    </p>

    <?php if ($candidates !== []): ?>
        <table>
            <thead>
                <tr>
                    <th>Person</th><th>Member code</th><th>Name (AM)</th>
                    <th>Status</th><th>Basis</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($candidates as $cand): ?>
                <?php
                $basis = AcmActivationRequestService::matchBasis($request, [
                    'member_code' => $cand['member_code'] ?? '',
                    'student_name' => $cand['student_name'] ?? '',
                    'full_name_am' => $cand['full_name_am'] ?? '',
                    'father_name' => $cand['father_name'] ?? '',
                    'phone_primary' => $cand['phone_primary'] ?? '',
                ]);
                ?>
                <tr>
                    <td>#<?= (int)$cand['id'] ?></td>
                    <td><code><?= e((string)($cand['member_code'] ?? '')) ?></code></td>
                    <td class="amharic"><?= e((string)($cand['full_name_am'] ?? '')) ?></td>
                    <td><?= e((string)($cand['status'] ?? '')) ?></td>
                    <td>
                        <span class="bg <?= $basis === 'manual' ? 'bg-w' : 'bg-ok' ?>">
                            <?= e($basis) ?>
                        </span>
                    </td>
                    <td>
                        <button class="btn acm-approve"
                                data-request="<?= $id ?>"
                                data-person="<?= (int)$cand['id'] ?>"
                                data-member="<?= (int)($cand['id']) ?>"
                                data-ref="<?= e((string)$request['request_ref']) ?>">
                            <i class="fa-solid fa-check"></i> Approve
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="acm-alert">
            No candidate matched the ID number given.
            <a href="?id=<?= $id ?>&amp;q=">Search persons</a> to find them,
            or reject the request.
        </div>
    <?php endif; ?>

    <form method="get" class="acm-filterbar" style="margin-top:1rem">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input class="inp" type="text" name="q" value="<?= e($q) ?>"
               placeholder="Search persons by name, phone or code&hellip;">
        <button class="btn bo" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
    </form>

    <?php if ($searchResults !== []): ?>
        <table style="margin-top:1rem">
            <thead><tr><th>Person</th><th>Name (AM)</th><th>Phone</th><th>Linked record</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($searchResults as $p): ?>
                <?php
                // A member account needs a members row to bind. If this
                // person has none, the officer must link the record on
                // the person screen FIRST: approving without it would
                // either fail or bind a member account to nothing.
                $linkMember = isset($p['member_id']) && $p['member_id'] !== null ? (int)$p['member_id'] : 0;
                $linkGuardian = isset($p['guardian_id']) && $p['guardian_id'] !== null ? (int)$p['guardian_id'] : 0;
                $needed = $request['account_type'] === 'member' ? $linkMember : $linkGuardian;
                ?>
                <tr>
                    <td>#<?= (int)$p['id'] ?></td>
                    <td class="amharic"><?= e((string)($p['full_name_am'] ?? '')) ?></td>
                    <td><?= e((string)($p['phone'] ?? '—')) ?></td>
                    <td>
                        <?php if ($needed > 0): ?>
                            <span class="bg bg-ok">#<?= $needed ?></span>
                        <?php else: ?>
                            <span class="bg bg-bad">none — link it first</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($needed > 0): ?>
                            <button class="btn bo acm-approve"
                                    data-request="<?= $id ?>"
                                    data-person="<?= (int)$p['id'] ?>"
                                    data-member="<?= $request['account_type'] === 'member' ? $linkMember : '' ?>"
                                    data-guardian="<?= $request['account_type'] === 'parent' ? $linkGuardian : '' ?>"
                                    data-ref="<?= e((string)$request['request_ref']) ?>">
                                Approve as manual
                            </button>
                        <?php else: ?>
                            <a class="btn bo" href="/admin/acm_person.php?id=<?= (int)$p['id'] ?>">Open person</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isPending && $canReject): ?>
<div class="cd">
    <div class="ct">Reject</div>
    <p class="dim">The requester is not told why. A reason is required for the record.</p>
    <div class="acm-filterbar">
        <input class="inp" type="text" id="acmRejectReason" placeholder="Reason (3-2000 characters)">
        <button class="btn bo" id="acmRejectBtn" data-request="<?= $id ?>">
            <i class="fa-solid fa-xmark"></i> Reject request
        </button>
    </div>
</div>
<?php endif; ?>

<div class="cd">
    <div class="ct">Timeline</div>
    <?php if ($events === []): ?>
        <p class="dim">No events recorded.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td class="dim"><?= e((string)$ev['created_at']) ?></td>
                    <td><?= e((string)$ev['event']) ?></td>
                    <td><?= (int)($ev['actor_id'] ?? 0) > 0 ? '#' . (int)$ev['actor_id'] : 'requester' ?></td>
                    <td class="dim"><?= e((string)($ev['note'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
(function () {
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    async function post(action, params) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', csrf());
        Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); });
        var r = await fetch('/admin/api_acm.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        var d = await r.json().catch(function () { return { status: 'error', message: 'Bad response' }; });
        if (d.status !== 'success') { throw new Error(d.message || 'Request failed'); }
        return d;
    }

    document.querySelectorAll('.acm-approve').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var body = { id: btn.getAttribute('data-request'), person_id: btn.getAttribute('data-person') };
            // Only a member-code candidate carries its member id; a
            // searched person is bound through the person record.
            if (btn.getAttribute('data-member')) { body.member_id = btn.getAttribute('data-member'); }
            if (btn.getAttribute('data-guardian')) { body.guardian_id = btn.getAttribute('data-guardian'); }
            if (!confirm('Approve this request? This creates the activation ticket.')) { return; }
            btn.disabled = true;
            try {
                var d = await post('request_approve', body);
                alert('Ticket ' + (d.ticket ? d.ticket.ticket_no : '') + ' created. Open it to issue the code.');
                window.location.href = '/admin/acm_ticket.php?id=' + (d.ticket ? d.ticket.ticket_id : '');
            } catch (e) {
                alert(e.message);
                btn.disabled = false;
            }
        });
    });

    var rejectBtn = document.getElementById('acmRejectBtn');
    if (rejectBtn) {
        rejectBtn.addEventListener('click', async function () {
            var reason = (document.getElementById('acmRejectReason') || {}).value || '';
            if (reason.trim().length < 3) { alert('Write a reason first (min 3 characters).'); return; }
            if (!confirm('Reject this request?')) { return; }
            rejectBtn.disabled = true;
            try {
                await post('request_reject', { id: rejectBtn.getAttribute('data-request'), reason: reason });
                window.location.reload();
            } catch (e) {
                alert(e.message);
                rejectBtn.disabled = false;
            }
        });
    }
})();
</script>

<?php acm_chrome_close(); ?>
