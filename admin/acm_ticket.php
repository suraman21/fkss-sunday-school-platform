<?php
/**
 * ACM S1 — Ticket detail. Identity evidence + manual decision.
 * Roles: super_admin, accounts_office.
 */
define('ACM_BOOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/acm_layout.php';
require_once __DIR__ . '/../acm/autoload.php';

use SSMS\Acm\Accounts\AccountProvisioner;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Activation\ActivationService;
use SSMS\Acm\Device\DeviceCrypto;
use SSMS\Acm\Device\DeviceService;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Office\AcmOfficeService;
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

$ticket = null;
$openCount = 0;
$pageError = '';
try {
    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
    $ticket = $tickets->get((int)($_GET['id'] ?? 0));
} catch (\Throwable $e) {
    error_log('ACM ticket failure: ' . $e->getMessage());
    $pageError = 'Ticket is temporarily unavailable.';
}
// The sidebar's open count is decoration here. It used to share the try above,
// so one failing aggregate query put "Ticket is temporarily unavailable." above
// a ticket that had loaded perfectly -- an officer reading that would stop
// trusting the page, or worse, stop reading it.
if ($ticket !== null) {
    try {
        $openCount = (int)($tickets->overviewStats($role, $actorId)['open'] ?? 0);
    } catch (\Throwable $e) {
        $openCount = 0;
    }
}
if ($ticket === null && $pageError === '') {
    http_response_code(404);
    echo 'Ticket was not found.';
    exit;
}

$status = (string)($ticket['status'] ?? '');
$isOpen = in_array($status, ['submitted', 'in_review', 'changes_requested'], true);
$isMine = $ticket !== null && (int)($ticket['submitter_id'] ?? 0) === $actorId;
$canClaim = $isOpen && $status === 'submitted' && AcmPolicy::can($role, 'ticket', 'claim');
$canRelease = $status === 'in_review' && AcmPolicy::can($role, 'ticket', 'claim')
    && ($role === 'super_admin' || (int)($ticket['assignee_id'] ?? 0) === $actorId);
$canNote = $isOpen && AcmPolicy::can($role, 'ticket', 'note');
$officeMode = 'separated';
$isDesignee = false;
try {
    $officeSvc = new AcmOfficeService($pdo);
    $officeMode = $officeSvc->mode();
    $isDesignee = $officeSvc->isActiveApprover($actorId);
} catch (\Throwable $e) {
    $officeMode = 'separated';
}
// Separated: maker-checker by user ID. Solo: designee-only, and a
// designee may self-decide with a recorded reason (server-enforced,
// flagged in the monthly audit).
$modeAllowsDecide = $officeMode === 'solo' ? $isDesignee : !$isMine;
$canDecide = $isOpen && AcmPolicy::can($role, 'ticket', 'approve') && $modeAllowsDecide;
$soloSelf = $isOpen && $isMine && $officeMode === 'solo' && $isDesignee;
$dups = $ticket['live_duplicates'] ?? [];
$hasDupWarnings = !empty($dups['same_phone']) || !empty($dups['same_name_dob']);
$od = !empty($ticket['sla_due']) && strtotime((string)$ticket['sla_due']) < time() && $isOpen;
// S3 ceremony panel: issuing needs an issuable state + code.issue;
// viewing needs code.view. codeStatus() throws pre-082, so the
// panel stays hidden until the migration lands (safe reader).
$canIssueCode = in_array($status, AcmTicketService::ISSUABLE_STATUSES, true)
    && AcmPolicy::can($role, 'code', 'issue');
$canCancel = in_array($status, AcmTicketService::CANCELABLE_STATUSES, true)
    && AcmPolicy::can($role, 'ticket', 'cancel');
$codeInfo = null;
if ($ticket !== null && AcmPolicy::can($role, 'code', 'view')) {
    try {
        $codeInfo = (new ActivationService(
            $pdo, $tickets, new AcmPersonService($pdo)
        ))->codeStatus((int)$ticket['id']);
    } catch (\Throwable $e) {
        $codeInfo = null;
    }
}
$showCeremony = $codeInfo !== null && in_array($status, [
    'approved', 'code_issued', 'grant_verified', 'account_pending_device',
    'expired', 'activation_failed',
], true);
// S4 device panel: ticket account's registered devices. Null rows =
// registry unavailable (pre-084 safe reader, like codeStatus above).
// The page only LISTS (revoke goes through api_acm, which owns the
// mysqli-backed family cascade), so no revoker is injected here.
$canManageDevices = AcmPolicy::can($role, 'device', 'manage');
$deviceRows = null;
$deviceUserId = null;
// S5: live bound-family counts per device uuid. Separate try: a
// counts failure (pre-086) hides the BADGES, never the S4 rows.
$boundFamilyCounts = null;
if ($ticket !== null && $canManageDevices) {
    try {
        $deviceUserId = (new AccountProvisioner($pdo))->userIdFor(
            (int)$ticket['person_id'], (string)$ticket['account_type']
        );
        $deviceRows = $deviceUserId === null ? [] : (new DeviceService($pdo, $tickets))->listForUser(
            $deviceUserId
        );
    } catch (\Throwable $e) {
        $deviceRows = null;
    }
    if ($deviceRows !== null && $deviceUserId !== null) {
        try {
            $boundFamilyCounts = (new DeviceService($pdo, $tickets))->boundFamilyCounts($deviceUserId);
        } catch (\Throwable $e) {
            $boundFamilyCounts = null;
        }
    }
}
$showDevices = $canManageDevices && $deviceUserId !== null;

/**
 * How long until the SLA, in words. "2 days late" is actionable; a timestamp
 * is not. Same wording and the same open-only rule as the queue, so a ticket
 * does not disagree with the list it was opened from.
 */
$slaOf = static function (string $due, string $status) use ($isOpen): array {
    if (!$isOpen || $due === '') {
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
/**
 * The member's request reference, when this ticket came from one.
 *
 * An activation code is verified against a REQUEST, not a ticket: the member
 * cannot activate with the digits alone. The request card shows the reference
 * at issue time, but a ticket can be reissued weeks later, by then from this
 * page -- so the reference has to be reachable from here too. Safe reader: a
 * pre-099 deployment simply has no reference to show.
 */
$ticketRef = '';
if ($showCeremony && $ticket !== null) {
    try {
        $refStmt = $pdo->prepare(
            'SELECT request_ref FROM acm_activation_requests WHERE ticket_id = :t LIMIT 1'
        );
        $refStmt->execute([':t' => (int)$ticket['id']]);
        $ticketRef = trim((string)($refStmt->fetchColumn() ?: ''));
    } catch (\Throwable $e) {
        $ticketRef = '';
    }
}

$slaText = $slaTone = '';
if (!empty($ticket['sla_due'])) {
    [$slaText, $slaTone] = $slaOf((string)$ticket['sla_due'], $status);
}

/**
 * Why this officer cannot decide, in one sentence. The old page printed a
 * generic warning and then a row of disabled-looking buttons; naming the rule
 * (maker-checker, solo designee, solo self-approval) is what tells them who
 * to hand the ticket to.
 */
$blockedReason = '';
if ($isOpen && !$canDecide) {
    if ($isMine && $officeMode !== 'solo') {
        $blockedReason = 'You filed this ticket, so someone else decides it. '
            . 'Claim and hand it over, or leave it for the designated reviewer.';
    } elseif ($officeMode === 'solo' && !$isDesignee) {
        $blockedReason = 'Solo mode: only a designated approver can decide. '
            . 'The office designations page shows who that is.';
    } elseif (!AcmPolicy::can($role, 'ticket', 'approve')) {
        $blockedReason = 'Your role cannot approve tickets. You can still add a note '
            . 'for the person who can.';
    }
}
$soloSelfNote = $soloSelf
    ? 'Solo self-approval: you are the designated approver and you filed this '
      . 'ticket. A written reason is required, and the decision is flagged in the '
      . 'monthly audit.'
    : '';
$statusLabel = $isOpen ? 'Open' : (in_array($status, ['approved', 'code_issued',
    'grant_verified', 'account_pending_device', 'active', 'provisioned'], true)
    ? 'Approved' : 'Closed');

acm_head((string)($ticket['ticket_no'] ?? 'Ticket'));
acm_chrome_open('queue', (string)($ticket['ticket_no'] ?? 'Ticket'),
    'Activation review · ' . $statusLabel, $openCount);
?>

<?php if ($pageError !== ''): ?>
<div class="acm-alert err"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($pageError) ?></div>
<?php endif; ?>

<?php if ($ticket === null): ?>
<?php /* S12: without this, a ticket that failed to load still rendered every
         card -- "Person —", "Birth date —", "No member record linked" -- which
         reads as a ticket whose data is empty rather than a load that failed.
         An officer could reasonably conclude the person has no details on
         file. Render one honest state instead. */ ?>
<div class="v2-state">
    <i class="fa-solid fa-circle-question"></i>
    <div class="v2-state-title">This ticket could not be opened</div>
    <p>
        <?= $pageError !== ''
            ? 'The ticket is unavailable right now. Reload the page; if it keeps failing the server error log has the reason.'
            : 'No ticket with that number exists. It may have been mistyped, or the link may be out of date.' ?>
    </p>
    <div class="v2-state-actions">
        <a class="btn bp" href="/admin/acm_queue.php"><i class="fa-solid fa-inbox"></i> Back to the queue</a>
        <a class="btn bo" href="/admin/acm_requests.php">Member requests</a>
    </div>
</div>
<?php acm_chrome_close(); return; ?>
<?php endif; ?>

<!-- ============ command bar: where this ticket stands, and what to do ============ -->
<div class="v2-toolbar">
    <a class="btn bo" href="/admin/acm_queue.php"><i class="fa-solid fa-arrow-left"></i> Queue</a>
    <span class="bg <?= acm_status_bg($status) ?>"><?= e($statusLabel) ?></span>
    <span class="bg bg-n"><?= e(ucfirst((string)($ticket['account_type'] ?? ''))) ?> account</span>
    <span class="bg <?= $officeMode === 'solo' ? 'bg-w' : 'bg-n' ?>"
          title="<?= $officeMode === 'solo'
              ? 'One person decides; a designated approver may self-approve with a reason'
              : 'Maker-checker: the person who filed a ticket never decides it' ?>">
        <?= e(ucfirst($officeMode)) ?> mode
    </span>
    <?php if (!empty($ticket['self_approved'])): ?>
        <span class="bg bg-w" title="Decided by the person who filed it, in solo mode">self-approved</span>
    <?php endif; ?>
    <?php if ($slaText !== '' && $slaTone === 'bad'): ?>
        <span class="bg bg-bad"><i class="fa-solid fa-clock"></i> <?= e($slaText) ?></span>
    <?php endif; ?>
    <span style="flex:1"></span>
    <?php if ($canClaim): ?>
        <button class="btn bo" data-act="claim"><i class="fa-solid fa-hand"></i> Claim for review</button>
    <?php endif; ?>
    <?php if ($canRelease): ?>
        <button class="btn bo" data-act="release"><i class="fa-solid fa-rotate-left"></i> Release claim</button>
    <?php endif; ?>
</div>

<?php if ($hasDupWarnings): ?>
<div class="acm-alert warn">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <strong>Possible duplicate.</strong>
    Another person on file may be the same person. Check the physical ID before
    approving — approval needs the confirmation box in Review actions below.
    <?php if (!empty($dups['same_phone'])): ?>
        <br>Same phone: <?= e(implode(', ', array_map(function ($r) {
            return '#' . (int)$r['id'] . ' ' . (string)($r['full_name_am'] ?? '');
        }, $dups['same_phone']))) ?>
    <?php endif; ?>
    <?php if (!empty($dups['same_name_dob'])): ?>
        <br>Same name and birth date: <?= e(implode(', ', array_map(function ($r) {
            return '#' . (int)$r['id'];
        }, $dups['same_name_dob']))) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="v2-verify">

    <!-- ---------------- LEFT: the evidence, as the register holds it ---------------- -->
    <div style="display:flex;flex-direction:column;gap:var(--v2-s4);min-width:0">

        <div class="v2-card">
            <div class="v2-card-head">
                <i class="fa-solid fa-id-card" style="color:var(--v2-ink-3)"></i>
                <strong style="color:var(--v2-ink);font-size:var(--v2-t-small)">Person evidence</strong>
                <a href="/admin/acm_person.php?id=<?= (int)$ticket['person_id'] ?>"
                   style="margin-left:auto;font-size:var(--v2-t-micro);color:var(--v2-accent);text-decoration:none">
                    Open the person <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
            <div class="v2-card-body">
                <div class="v2-identity">
                    <div class="v2-photo"><i class="fa-solid fa-user"></i></div>
                    <div style="min-width:0">
                        <div class="v2-name amharic"><?= e((string)($ticket['full_name_am'] ?? '')) ?></div>
                        <?php if (($ticket['full_name_en'] ?? '') !== ''): ?>
                            <div class="v2-name-sub"><?= e((string)$ticket['full_name_en']) ?></div>
                        <?php endif; ?>
                        <div class="v2-chips">
                            <span class="bg bg-n"><?= e((string)($ticket['account_type'] ?? '')) ?></span>
                            <?php if ($slaText !== ''): ?>
                                <span class="bg <?= $slaTone === 'bad' ? 'bg-bad' : 'bg-n' ?>">
                                    <i class="fa-solid fa-clock"></i> <?= e($slaText) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <dl class="v2-facts">
                    <?php
                    $fact = static function (string $label, string $value) {
                        $missing = trim($value) === '' || trim($value) === '—';
                        echo '<div class="v2-fact"><dt>' . e($label) . '</dt><dd'
                            . ($missing ? ' class="is-missing"' : '') . '>'
                            . ($missing ? 'not on file' : e($value)) . '</dd></div>';
                    };
                    $fact('Father', (string)($ticket['father_name'] ?? '—'));
                    $fact('Phone', (string)($ticket['phone'] ?? '—'));
                    $fact('Email', (string)($ticket['email'] ?? '—'));
                    $fact('Birth date', (string)($ticket['date_of_birth'] ?? '—'));
                    $fact('ID document', trim((string)($ticket['id_doc_type'] ?? '')
                        . ' ' . (string)($ticket['id_doc_ref'] ?? '')) ?: '—');
                    $submitter = trim((string)($ticket['submitter_name'] ?? ''));
                    $fact('Filed by', $submitter !== ''
                        ? $submitter . ' · ' . (string)($ticket['created_at'] ?? '')
                        : (string)($ticket['created_at'] ?? '—'));
                    ?>
                </dl>
            </div>
        </div>

        <?php $member = $ticket['member'] ?? null; $guardian = $ticket['guardian'] ?? null; ?>
        <div class="v2-card">
            <div class="v2-card-head">
                <i class="fa-solid fa-link" style="color:var(--v2-ink-3)"></i>
                <strong style="color:var(--v2-ink);font-size:var(--v2-t-small)">Linked records</strong>
            </div>
            <div class="v2-card-body">
                <?php if (is_array($member)): ?>
                    <dl class="v2-facts" style="margin-top:0;padding-top:0;border-top:none">
                        <div class="v2-fact"><dt>Member</dt><dd class="amharic">
                            <?= e((string)($member['full_name_am'] ?? '')) ?>
                            (<?= e((string)($member['student_name'] ?? '')) ?>)</dd></div>
                        <div class="v2-fact"><dt>Code</dt><dd class="v2-code">
                            <?= e((string)($member['member_code'] ?? '—')) ?>
                            · <?= e((string)($member['registration_type'] ?? '')) ?></dd></div>
                        <div class="v2-fact"><dt>Section</dt><dd>
                            <?= e((string)($member['current_section'] ?? '—')) ?>
                            · <?= e((string)($member['education_level'] ?? '')) ?></dd></div>
                        <div class="v2-fact"><dt>Baptismal name</dt><dd class="amharic">
                            <?= e((string)($member['baptismal_name'] ?? '—')) ?></dd></div>
                    </dl>
                <?php else: ?>
                    <div class="v2-matchline is-warn" style="margin:0">
                        <i class="fa-solid fa-link-slash"></i>
                        No member record is linked, so there is nothing to check the
                        claim against. Approving would create an account on the
                        ticket's own details.
                    </div>
                <?php endif; ?>
                <?php if (is_array($guardian)): ?>
                    <dl class="v2-facts" style="margin-top:var(--v2-s4)">
                        <div class="v2-fact"><dt>Guardian</dt><dd><?= e((string)($guardian['display_name'] ?? '')) ?></dd></div>
                        <div class="v2-fact"><dt>Phones</dt><dd class="v2-code">
                            <?= e((string)($guardian['phone_primary'] ?? '—')) ?> /
                            <?= e((string)($guardian['phone_alt'] ?? '—')) ?></dd></div>
                        <div class="v2-fact"><dt>Address</dt><dd>
                            <?= e(trim((string)($guardian['city'] ?? '') . ' '
                                . (string)($guardian['sub_city'] ?? '') . ' '
                                . (string)($guardian['woreda'] ?? '')) ?: 'not on file') ?></dd></div>
                    </dl>
                <?php else: ?>
                    <div style="margin-top:var(--v2-s3);font-size:var(--v2-t-small);color:var(--v2-ink-4)">
                        No guardian record linked.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($ticket['family_links'])): ?>
        <div class="v2-card">
            <div class="v2-card-head">
                <i class="fa-solid fa-people-roof" style="color:var(--v2-ink-3)"></i>
                <strong style="color:var(--v2-ink);font-size:var(--v2-t-small)">Family links</strong>
                <span style="margin-left:auto;font-size:var(--v2-t-micro);color:var(--v2-ink-4)">
                    member ↔ guardians
                </span>
            </div>
            <div class="tw" style="border:none;border-radius:0">
                <table>
                    <thead><tr><th>Guardian</th><th>Relation</th><th>Primary</th><th>Phone</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($ticket['family_links'] as $link): ?>
                        <tr>
                            <td><?= e((string)($link['display_name'] ?? '')) ?></td>
                            <td><?= e((string)($link['relation'] ?? '')) ?></td>
                            <td><?= !empty($link['is_primary']) ? 'Yes' : 'No' ?></td>
                            <td class="v2-code"><?= e((string)($link['phone_primary'] ?? '—')) ?></td>
                            <td><span class="bg bg-n"><?= e((string)($link['status'] ?? '')) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ---------------- the ceremony: code, devices ---------------- -->
        <?php if ($showCeremony): ?>
        <div class="v2-card">
            <div class="v2-card-head">
                <i class="fa-solid fa-key" style="color:var(--v2-ink-3)"></i>
                <strong style="color:var(--v2-ink);font-size:var(--v2-t-small)">Activation code</strong>
                <?php if (is_array($codeInfo['active'] ?? null)): ?>
                    <span class="bg bg-ok" style="margin-left:auto">live</span>
                <?php endif; ?>
            </div>
            <div class="v2-card-body">
                <?php $liveCode = is_array($codeInfo['active'] ?? null) ? $codeInfo['active'] : null; ?>
                <?php $lastCode = is_array($codeInfo['last'] ?? null) ? $codeInfo['last'] : null; ?>
                <?php if ($liveCode !== null): ?>
                    <?php $isOtpCode = (string)($liveCode['purpose'] ?? '') === 'activation'; ?>
                    <dl class="v2-facts" style="margin-top:0;padding-top:0;border-top:none">
                        <div class="v2-fact">
                            <dt><?= $isOtpCode ? 'Code' : 'Selector' ?></dt>
                            <dd class="v2-code">
                                <?php if ($isOtpCode): ?>
                                    six-digit one-time code
                                <?php else: ?>
                                    <?= e((string)($liveCode['selector'] ?? '')) ?> ·
                                    <?= e((string)($liveCode['prefix'] ?? '')) ?> ·
                                    <?= e((string)($liveCode['purpose'] ?? '')) ?>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div class="v2-fact">
                            <dt>Expires</dt>
                            <dd><?= e((string)($liveCode['expires_at'] ?? '')) ?>
                                <?php if (empty($liveCode['live'])): ?>
                                    <span class="bg bg-bad">expired — reissue to resume</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div class="v2-fact">
                            <dt>Attempts left</dt>
                            <dd>
                            <?php
                            // The OTP budget lives in acm_otp_attempts, not in the code
                            // row's `attempts` column (failOtp() never touches that), so
                            // reading the row would always say "0 used" -- including on a
                            // ticket that had just been burnt. codeStatus() reports the
                            // real figure as otp_used.
                            $otpBudget = !empty($isOtpCode) && isset($codeInfo['otp_limit']);
                            $limit = $otpBudget ? (int)$codeInfo['otp_limit'] : (int)($liveCode['max_attempts'] ?? 0);
                            $used  = $otpBudget ? (int)($codeInfo['otp_used'] ?? 0) : (int)($liveCode['attempts'] ?? 0);
                            ?>
                            <?= max(0, $limit - $used) ?> of <?= $limit ?>
                            <?php if ($otpBudget): ?>
                                <div style="font-size:var(--v2-t-micro);color:var(--v2-ink-4)">
                                    per ticket, across all issued codes
                                </div>
                            <?php endif; ?>
                            </dd>
                        </div>
                    </dl>
                <?php elseif ($lastCode !== null): ?>
                    <dl class="v2-facts" style="margin-top:0;padding-top:0;border-top:none">
                        <?php /* An OTP row has no selector, so rendering one as '' would
                                 show a leading separator with nothing before it. */ ?>
                        <div class="v2-fact">
                            <dt>Last code</dt>
                            <dd class="v2-code">
                                <?php if (trim((string)($lastCode['selector'] ?? '')) !== ''): ?>
                                    <?= e((string)$lastCode['selector']) ?> ·
                                <?php endif; ?>
                                <?= e(str_replace('_', ' ', (string)($lastCode['status'] ?? ''))) ?>
                                <?= (string)($lastCode['purpose'] ?? '') === 'activation' ? ' · 6-digit OTP' : '' ?>
                            </dd>
                        </div>
                        <?php $lastIsOtp = (string)($lastCode['purpose'] ?? '') === 'activation' && isset($codeInfo['otp_limit']); ?>
                        <div class="v2-fact">
                            <dt>Attempts used</dt>
                            <dd><?= $lastIsOtp ? (int)($codeInfo['otp_used'] ?? 0) : (int)($lastCode['attempts'] ?? 0) ?>
                                of <?= $lastIsOtp ? (int)$codeInfo['otp_limit'] : (int)($lastCode['max_attempts'] ?? 0) ?></dd>
                        </div>
                    </dl>
                <?php else: ?>
                    <div style="font-size:var(--v2-t-small);color:var(--v2-ink-4)">
                        No code has been issued for this ticket yet.
                    </div>
                <?php endif; ?>

                <?php if ((int)($codeInfo['velocity_1h'] ?? 0) >= (int)($codeInfo['velocity_limit'] ?? 30)): ?>
                <div class="acm-alert err" style="margin-top:var(--v2-s3)">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    Unusual verification activity in the last hour
                    (<?= (int)($codeInfo['velocity_1h'] ?? 0) ?> failures). This ticket may
                    be under attack — consider canceling it.
                </div>
                <?php endif; ?>

                <div id="acmCodeOnce" class="acm-alert warn" style="display:none;margin-top:var(--v2-s3)"></div>

                <?php if ($ticketRef !== ''): ?>
                <div class="v2-claim" style="margin-top:var(--v2-s3)">
                    <div class="v2-claim-label">
                        <i class="fa-solid fa-hashtag"></i> Read these two out together
                    </div>
                    <div style="display:flex;align-items:center;gap:var(--v2-s4);flex-wrap:wrap">
                        <div>
                            <div style="font-size:var(--v2-t-micro);color:var(--v2-ink-4)">Request reference</div>
                            <div class="v2-code" style="font-size:var(--v2-t-h2);letter-spacing:.1em">
                                <?= e($ticketRef) ?>
                            </div>
                        </div>
                        <div style="font-size:var(--v2-t-small);color:var(--v2-ink-3);max-width:24rem">
                            The code is checked against this reference only, and works
                            once. Without it the member cannot finish activation.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="v2-toolbar" style="margin:var(--v2-s3) 0 0">
                    <?php if ($canIssueCode): ?>
                        <select class="inp" id="acmIssuePurpose" aria-label="Code purpose" style="max-width:12rem">
                            <option value="activation">Activation code</option>
                            <option value="device_add">Add-a-device code</option>
                            <option value="reset">Recovery code</option>
                        </select>
                        <button class="btn bp" id="acmIssueBtn">
                            <i class="fa-solid fa-key"></i> <?= $liveCode !== null ? 'Reissue code' : 'Issue code' ?>
                        </button>
                    <?php endif; ?>
                    <span style="flex:1"></span>
                    <?php if ($canIssueCode): ?>
                        <span style="font-size:var(--v2-t-micro);color:var(--v2-ink-4);max-width:26rem">
                            <i class="fa-solid fa-eye"></i>
                            The full code shows once — read it out in person, then it is
                            masked forever. Reissuing kills the previous code.
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($canCancel): ?>
                <div class="v2-toolbar" style="margin:var(--v2-s3) 0 0;border-top:1px solid var(--v2-line);padding-top:var(--v2-s3)">
                    <input class="inp" id="acmCancelReason" maxlength="2000"
                           placeholder="Why is this ticket being canceled?" style="flex:1;min-width:12rem"
                           aria-label="Cancel reason">
                    <button class="btn v2-danger" id="acmCancelBtn">
                        <i class="fa-solid fa-ban"></i> Cancel ticket
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($showDevices): ?>
        <div class="v2-card">
            <div class="v2-card-head">
                <i class="fa-solid fa-mobile-screen" style="color:var(--v2-ink-3)"></i>
                <strong style="color:var(--v2-ink);font-size:var(--v2-t-small)">Registered devices</strong>
            </div>
            <?php if ($deviceRows === null): ?>
                <div class="v2-card-body" style="font-size:var(--v2-t-small);color:var(--v2-ink-4)">
                    The device registry is unavailable (migration pending), so this is
                    unknown rather than "no devices".
                </div>
            <?php elseif ($deviceRows === []): ?>
                <div class="v2-card-body" style="font-size:var(--v2-t-small);color:var(--v2-ink-4)">
                    No devices registered yet. A device is added by the member, after
                    activation, from the app.
                </div>
            <?php else: ?>
                <div class="tw" style="border:none;border-radius:0">
                    <table>
                        <thead><tr><th>Device</th><th>Fingerprint</th><th>Last seen</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($deviceRows as $dr): ?>
                            <tr>
                                <td>
                                    <?= e((string)($dr['name'] ?? 'Unnamed device')) ?>
                                    <span class="bg <?= ($dr['status'] ?? '') === 'revoked' ? 'bg-bad' : 'bg-ok' ?>">
                                        <?= e((string)($dr['status'] ?? '')) ?>
                                    </span>
                                    <?php if (is_array($boundFamilyCounts)): ?>
                                        <?php $bound = (int)($boundFamilyCounts[(string)($dr['uuid'] ?? '')] ?? 0); ?>
                                        <?php if ($bound > 0): ?>
                                            <span class="bg bg-w" title="Families that need a fresh proof from this device"><?= $bound ?> bound</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="v2-code" style="font-size:var(--v2-t-micro)">
                                    <?= e(DeviceCrypto::shortFingerprint((string)($dr['fingerprint'] ?? ''))) ?>
                                    · <?= e((string)($dr['alg'] ?? '')) ?>
                                </td>
                                <td style="font-size:var(--v2-t-micro);color:var(--v2-ink-3)">
                                    <?= e((string)($dr['last_seen'] ?? 'never')) ?>
                                </td>
                                <td style="text-align:right">
                                    <?php if (($dr['status'] ?? '') !== 'revoked'): ?>
                                        <button class="btn v2-danger bs" data-revoke="<?= e((string)($dr['uuid'] ?? '')) ?>">
                                            Revoke
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="v2-card-body" style="padding-top:0">
                    <input class="inp" id="acmRevokeReason" maxlength="2000"
                           placeholder="Why is this device being revoked?" style="width:100%"
                           aria-label="Revoke reason">
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="v2-card">
            <div class="v2-card-head">
                <i class="fa-solid fa-clock-rotate-left" style="color:var(--v2-ink-3)"></i>
                <strong style="color:var(--v2-ink);font-size:var(--v2-t-small)">Timeline</strong>
            </div>
            <div class="v2-card-body">
                <?php if (empty($ticket['events'])): ?>
                    <div style="font-size:var(--v2-t-small);color:var(--v2-ink-4)">
                        No events recorded on this ticket.
                    </div>
                <?php else: ?>
                <ul class="v2-timeline">
                    <?php foreach ($ticket['events'] as $ev): ?>
                    <li>
                        <div class="v2-ev"><?= e(str_replace('_', ' ', (string)($ev['event'] ?? ''))) ?>
                            <?php if (!empty($ev['actor_name'])): ?>
                                <span style="font-weight:450;color:var(--v2-ink-3)">by <?= e((string)$ev['actor_name']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="v2-ev-meta"><?= e((string)($ev['created_at'] ?? '')) ?></div>
                        <?php if (!empty($ev['note'])): ?>
                            <div style="font-size:var(--v2-t-small);color:var(--v2-ink-2);margin-top:2px">
                                <?= e((string)$ev['note']) ?>
                            </div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ---------------- RIGHT: what to do about it ---------------- -->
    <div class="v2-rail">

        <?php if ($canNote || $canDecide): ?>
        <div class="v2-rail-card">
            <div class="v2-rail-title">
                <?= $canDecide ? 'Decide' : ($blockedReason !== '' ? 'Someone else decides' : 'Add a note') ?>
            </div>
            <p class="v2-rail-hint">
                <?php if ($soloSelfNote !== ''): ?>
                    <?= e($soloSelfNote) ?>
                <?php elseif ($blockedReason !== '') : ?>
                    <?= e($blockedReason) ?>
                <?php elseif ($canDecide): ?>
                    Say what you checked. A reason is required to reject or ask for
                    changes, and it is what the audit reads later.
                <?php else: ?>
                    Your note goes on the timeline for whoever decides.
                <?php endif; ?>
            </p>

            <label class="flbl" for="acmNote" style="font-size:var(--v2-t-micro);color:var(--v2-ink-3);text-transform:uppercase;letter-spacing:.07em;font-weight:600">
                Note<?= $soloSelf ? ' (required for self-approval)' : ($canDecide ? ' (required to reject or ask for changes)' : '') ?>
            </label>
            <textarea class="inp" id="acmNote" rows="3" maxlength="2000"
                      placeholder="What did you verify?" style="width:100%;margin-top:var(--v2-s1)"></textarea>

            <?php if ($canDecide && $hasDupWarnings): ?>
            <label style="display:flex;gap:var(--v2-s2);align-items:flex-start;margin-top:var(--v2-s3);font-size:var(--v2-t-small);color:var(--v2-ink-2)">
                <input type="checkbox" id="acmVerified" style="margin-top:2px">
                <span>I physically verified the parent/member ID against these details
                and accept the duplicate warnings.</span>
            </label>
            <?php endif; ?>

            <div class="v2-rail-actions" style="margin-top:var(--v2-s3)">
                <?php if ($canDecide): ?>
                    <button class="btn v2-ok" data-act="approve">
                        <i class="fa-solid fa-check"></i> Approve
                    </button>
                    <button class="btn bo" data-act="request_changes">
                        <i class="fa-solid fa-pen"></i> Request changes
                    </button>
                    <button class="btn v2-danger" data-act="reject">
                        <i class="fa-solid fa-xmark"></i> Reject
                    </button>
                    <p class="v2-rail-hint" style="margin:var(--v2-s2) 0 0">
                        Approval authorizes a code — no account exists until the member
                        completes activation in the app.
                    </p>
                <?php endif; ?>
                <?php if ($canNote): ?>
                    <button class="btn <?= $canDecide ? 'bo' : 'bp' ?>" data-act="note">
                        <i class="fa-solid fa-comment"></i> Add note
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="v2-rail-card">
            <div class="v2-rail-title" style="font-size:var(--v2-t-small)">Assignment</div>
            <dl class="v2-facts" style="margin-top:var(--v2-s3);padding-top:0;border-top:none">
                <div class="v2-fact"><dt>Assignee</dt>
                    <dd class="<?= ($ticket['assignee_name'] ?? '') === '' ? 'is-missing' : '' ?>">
                        <?= e((string)($ticket['assignee_name'] ?? '') ?: 'unclaimed') ?></dd></div>
                <div class="v2-fact"><dt>Filed</dt>
                    <dd><?= e((string)($ticket['created_at'] ?? '')) ?></dd></div>
                <div class="v2-fact"><dt>Deadline</dt>
                    <dd class="<?= $slaTone === 'bad' ? 'is-missing' : '' ?>">
                        <?= $slaText !== '' ? e($slaText) : 'closed — no deadline' ?></dd></div>
                <?php if (!empty($ticket['decided_by'])): ?>
                <div class="v2-fact"><dt>Decided by</dt>
                    <dd><?= e((string)($ticket['decider_name'] ?? '')) ?>
                        <div style="font-size:var(--v2-t-micro);color:var(--v2-ink-4)">
                            <?= e((string)($ticket['decided_at'] ?? '')) ?></div></dd></div>
                <div class="v2-fact" style="grid-column:1/-1"><dt>Decision note</dt>
                    <dd><?= e((string)($ticket['decision_note'] ?? '—')) ?></dd></div>
                <?php endif; ?>
            </dl>
        </div>

    </div>
</div>

<script>
(function () {
    var id = <?= (int)$ticket['id'] ?>;
    var reloadPending = false;
    function note() { var el = document.getElementById('acmNote'); return el ? el.value : ''; }
    function busy(btn, on) { btn.disabled = on; btn.classList.toggle('is-busy', on); }

    /* Issuing a code must NOT navigate: the code shows once, in this page, and
       a reload would destroy the only copy the officer will ever see. Deciding
       does reload, but only after this long enough for the toast to be read. */
    function reloadSoon() {
        if (reloadPending) { return; }
        reloadPending = true;
        setTimeout(function () { window.location.reload(); }, 900);
    }

    document.querySelectorAll('[data-act]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var act = btn.getAttribute('data-act');
            var reasonRequired = (act === 'reject' || act === 'request_changes');

            if (act === 'note' && !note().trim()) {
                acmToast('Write the note first.', false);
                return;
            }
            if (reasonRequired && note().trim().length < 3) {
                acmToast(act === 'reject'
                    ? 'Write why you are rejecting this ticket.'
                    : 'Write what needs to change.', false);
                var nl = document.getElementById('acmNote');
                if (nl) { nl.focus(); }
                return;
            }

            /* Approving, rejecting and asking for changes are not undoable from
               this screen, so each one asks for a second click rather than
               relying on the officer to notice a toast afterwards. */
            if (act !== 'claim' && act !== 'release' && act !== 'note'
                && btn.getAttribute('data-armed') !== '1') {
                btn.setAttribute('data-armed', '1');
                var orig = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Click again to confirm';
                btn._dt = setTimeout(function () {
                    btn.setAttribute('data-armed', '');
                    btn.innerHTML = orig;
                }, 6000);
                return;
            }
            clearTimeout(btn._dt);
            busy(btn, true);
            try {
                if (act === 'claim' || act === 'release') {
                    await acmApi(act, { id: id }, 'POST');
                } else if (act === 'note') {
                    await acmApi('note', { id: id, note: note() }, 'POST');
                } else {
                    var params = { id: id, decision: act, note: note() };
                    var v = document.getElementById('acmVerified');
                    if (v) { params.verified_parent_id = v.checked ? 1 : 0; }
                    await acmApi('decide', params, 'POST');
                }
                reloadSoon();
            } catch (err) {
                acmToast(err.message || 'Action failed.', false);
                busy(btn, false);
                btn.setAttribute('data-armed', '');
            }
        });
    });

    // The ceremony: issue, then show the code once, in place.
    var issueBtn = document.getElementById('acmIssueBtn');
    if (issueBtn) {
        issueBtn.addEventListener('click', async function () {
            busy(issueBtn, true);
            try {
                var purposeEl = document.getElementById('acmIssuePurpose');
                var purpose = purposeEl ? purposeEl.value : 'activation';
                var d = await acmApi('code_issue', { id: id, purpose: purpose }, 'POST');
                var box = document.getElementById('acmCodeOnce');
                var code = (d.code && d.code.display) ? d.code.display : '';
                var ref = <?= json_encode($ticketRef) ?>;
                box.style.display = 'block';
                if (d.code && d.code.kind === 'otp') {
                    // An activation code is a six-digit OTP, and the member must
                    // ALSO give the reference of their own request: verification
                    // is scoped to one ticket, which is what keeps 10^6 values
                    // safe to guess against.
                    box.textContent = 'ACTIVATION CODE: ' + code
                        + '  (expires ' + ((d.code.expires_at) ? d.code.expires_at : '?') + ')\n'
                        + 'Give the member BOTH: this code and their request reference'
                        + (ref ? ' (' + ref + ')' : '') + '. '
                        + 'The code is checked only against their request, and works once. '
                        + 'Reloading this page masks it forever.';
                    box.style.whiteSpace = 'pre-line';
                } else {
                    box.textContent = 'ONE-TIME CODE: ' + code
                        + ' (expires ' + ((d.code.expires_at) ? d.code.expires_at : '?')
                        + '). Read it to the user in person — reloading this page masks it forever.';
                }
                issueBtn.innerHTML = '<i class="fa-solid fa-key"></i> Reissue code';
                busy(issueBtn, false);
            } catch (err) {
                acmToast(err.message || 'The code could not be issued.', false);
                busy(issueBtn, false);
            }
        });
    }

    var cancelBtn = document.getElementById('acmCancelBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', async function () {
            var reasonEl = document.getElementById('acmCancelReason');
            var reason = reasonEl ? reasonEl.value.trim() : '';
            if (reason.length < 3) {
                acmToast('Write a cancel reason first (min 3 characters).', false);
                if (reasonEl) { reasonEl.focus(); }
                return;
            }
            if (cancelBtn.getAttribute('data-armed') !== '1') {
                cancelBtn.setAttribute('data-armed', '1');
                cancelBtn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Click again to cancel';
                setTimeout(function () {
                    cancelBtn.setAttribute('data-armed', '');
                    cancelBtn.innerHTML = '<i class="fa-solid fa-ban"></i> Cancel ticket';
                }, 6000);
                return;
            }
            busy(cancelBtn, true);
            try {
                await acmApi('ticket_cancel', { id: id, reason: reason }, 'POST');
                reloadSoon();
            } catch (err) {
                acmToast(err.message || 'The ticket could not be canceled.', false);
                busy(cancelBtn, false);
            }
        });
    }

    // Device revoke (two-click arm, like cancel).
    document.querySelectorAll('[data-revoke]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var reasonEl = document.getElementById('acmRevokeReason');
            var reason = reasonEl ? reasonEl.value.trim() : '';
            if (reason.length < 3) {
                acmToast('Write a revoke reason first (min 3 characters).', false);
                if (reasonEl) { reasonEl.focus(); }
                return;
            }
            if (btn.getAttribute('data-armed') !== '1') {
                btn.setAttribute('data-armed', '1');
                btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Confirm revoke';
                setTimeout(function () {
                    btn.setAttribute('data-armed', '');
                    btn.innerHTML = 'Revoke';
                }, 6000);
                return;
            }
            busy(btn, true);
            try {
                await acmApi('device_revoke',
                    { id: id, device_uuid: btn.getAttribute('data-revoke'), reason: reason }, 'POST');
                reloadSoon();
            } catch (err) {
                acmToast(err.message || 'The device could not be revoked.', false);
                busy(btn, false);
                btn.setAttribute('data-armed', '');
            }
        });
    });
})();
</script>
<?php acm_chrome_close(); ?>
