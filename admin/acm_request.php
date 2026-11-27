<?php
/**
 * ACM S12 — One activation request: verify the person, then decide.
 * ------------------------------------------------------------
 * WHAT CHANGED FROM S11, AND WHY
 *   S11 showed the claim first and offered a table of "candidates" to pick
 *   from. In practice the officer arrives with a reference from a member
 *   standing in front of them, and the only question that matters is "is
 *   this the same person?". So the page is now built around that question:
 *
 *     left   the MEMBER RECORD -- photo, name, section, class, birth date
 *            and age, Christian name. What the register says.
 *     right  the DECISION RAIL -- approve / reject, adjacent to the record
 *            they act on (Fitts's Law; proximity of action to object).
 *
 *   The claim ("what they typed") is kept visually separate, because
 *   conflating what was typed with what is stored is how a wrong approval
 *   gets made.
 *
 * THE RULE THIS SCREEN STILL ENFORCES
 *   The platform never decides who the requester is by itself. Detection
 *   (AcmActivationDetector) has already narrowed this to exactly one member
 *   or this request would not exist. `match_basis` is still computed from
 *   the person finally chosen, so an approval that did not rely on the code
 *   and the name is visible in the audit trail as `manual`.
 *
 * APPROVAL CREATES THE TICKET, NOT THE CODE. Issuing and reading out the
 * code stays in acm_ticket.php, which has the single show-once path.
 */
define('ACM_BOOT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/acm_layout.php';
require_once __DIR__ . '/../acm/autoload.php';

use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Identity\AmharicNameMatcher;
use SSMS\Acm\Policy\AcmPolicy;
use SSMS\Acm\Requests\AcmActivationDetector;
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
$request = null;
$loadError = '';
try {
    $requests = new AcmActivationRequestService($pdo);
    $request = $id > 0 ? $requests->get($id) : null;
} catch (\Throwable $e) {
    error_log('acm_request_load_failed');
    $loadError = 'The request could not be loaded. Apply migration 099 if this is a new deployment.';
}
if ($loadError !== '') {
    http_response_code(503);
    acm_head('Request unavailable');
    acm_chrome_open('requests', 'Request unavailable', '', 0);
    echo '<div class="acm-alert err">' . e($loadError) . '</div>';
    acm_chrome_close();
    exit;
}
if ($request === null) {
    http_response_code(404);
    acm_head('Request not found');
    acm_chrome_open('requests', 'Request not found', '', 0);
    echo '<div class="v2-state"><i class="fa-solid fa-circle-question"></i>'
        . '<div class="v2-state-title">No request with that reference</div>'
        . '<p>The reference may be mistyped, or the request may have been removed.</p>'
        . '<div class="v2-state-actions"><a class="btn bo" href="/admin/acm_requests.php">'
        . '<i class="fa-solid fa-arrow-left"></i> Back to requests</a></div></div>';
    acm_chrome_close();
    exit;
}

// ---------------------------------------------------------------- data
$detector = new AcmActivationDetector($pdo);
$detection = $detector->detect(
    (string)$request['member_code'],
    AmharicNameMatcher::firstName((string)$request['name_key']),
    (string)$request['account_type']
);
// The register row the detection settled on (empty when it did not resolve).
$member = $detection['candidates'][0] ?? null;
$person = $detection['person_id'] !== null
    ? (new AcmPersonService($pdo))->find((int)$detection['person_id'])
    : null;

$eventStmt = $pdo->prepare(
    'SELECT event, actor_id, note, meta, created_at FROM acm_request_events'
    . ' WHERE request_id = :rid ORDER BY id ASC LIMIT 200'
);
$eventStmt->execute([':rid' => $id]);
$events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

// Fallback search: the officer may be holding a record the detector could
// not resolve (a nickname, a family that shares a code).
$q = trim((string)($_GET['q'] ?? ''));
$searchResults = [];
if ($q !== '') {
    $searchResults = (new AcmPersonService($pdo))->search($q, 20);
}

// The next request waiting, so finishing one can move straight to the next
// instead of bouncing back to the queue (goal-gradient: keep the run going).
$nextPendingId = 0;
try {
    $nextRows = $requests->queue($actorId, 'pending_review', 5, 0);
    foreach ($nextRows as $nr) {
        if ((int)$nr['id'] !== $id) {
            $nextPendingId = (int)$nr['id'];
            break;
        }
    }
} catch (\Throwable $e) {
    $nextPendingId = 0;
}

$canApprove = AcmPolicy::can($role, AcmPolicy::RES_REQUEST, 'approve');
$canReject = AcmPolicy::can($role, AcmPolicy::RES_REQUEST, 'reject');
$isPending = $request['status'] === 'pending_review';
$resolved = $detection['kind'] === AcmActivationDetector::RESOLVED;

// ---------------------------------------------------------------- helpers
/** Age from a birth date, or the stored age when only that exists. */
$ageOf = static function (?string $dob, ?int $stored): ?int {
    if ($dob !== null && $dob !== '' && $dob !== '0000-00-00') {
        try {
            $d = new DateTimeImmutable($dob);
            $now = new DateTimeImmutable('today');
            if ($d > $now) {
                return null;
            }
            return (int)$d->diff($now)->y;
        } catch (\Throwable) {
            // fall through to the stored value
        }
    }
    return $stored !== null && $stored > 0 ? $stored : null;
};
$dob = $member['date_of_birth'] ?? null;
$age = $ageOf($dob !== '' ? (string)$dob : null, isset($member['age']) ? (int)$member['age'] : null);

$matchKind = (string)($detection['match'] ?? 'none');
$matchDetail = (string)($detection['detail'] ?? '');
$matchLabel = [
    'exact' => 'Name matches the register exactly',
    'homophone' => 'Same Amharic sounds, different letters',
    'close' => 'Close match — check the spelling',
    'ambiguous' => 'More than one member fits',
    'different' => 'Does not match the register',
    'none' => 'No member record',
][$matchKind] ?? 'No member record';

$statusLabel = [
    'pending_review' => 'Pending review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'expired' => 'Expired',
    'canceled' => 'Canceled',
];

acm_head('Verify ' . (string)$request['request_ref']);
acm_chrome_open(
    'requests',
    'Verify request ' . (string)$request['request_ref'],
    'Confirm this is the same person, then decide',
    0
);
?>

<div class="v2-toolbar" style="margin-bottom:var(--v2-s4)">
    <a class="btn bo" href="/admin/acm_requests.php">
        <i class="fa-solid fa-arrow-left"></i> All requests
    </a>
    <span class="bg <?= acm_status_bg((string)$request['status']) ?>">
        <?= e($statusLabel[(string)$request['status']] ?? (string)$request['status']) ?>
    </span>
    <span class="v2-code"><?= e((string)$request['request_ref']) ?></span>
    <?php if ((int)($request['request_count'] ?? 1) > 1): ?>
        <span class="bg bg-w" title="The same claim was submitted more than once">
            <i class="fa-solid fa-rotate"></i> resubmitted <?= (int)$request['request_count'] ?>×
        </span>
    <?php endif; ?>
</div>

<div class="v2-verify">

    <!-- ============ LEFT: the person, as the register knows them ============ -->
    <div>
        <div class="v2-card">
            <div class="v2-card-head <?= $resolved ? 'is-ok' : 'is-warn' ?>">
                <i class="fa-solid <?= $resolved ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"
                   style="color:var(--<?= $resolved ? 'v2-ok' : 'v2-warn' ?>)"></i>
                <strong style="color:var(--v2-ink);font-size:var(--v2-t-small)">
                    <?= $resolved ? 'Matched to a registered member' : 'No confident match' ?>
                </strong>
                <span style="margin-left:auto;font-size:var(--v2-t-micro);color:var(--v2-ink-3)">
                    <?= e($matchLabel) ?>
                </span>
            </div>

            <div class="v2-card-body">
                <?php if ($member !== null): ?>
                    <div class="v2-identity">
                        <div class="v2-photo">
                            <?php if (($member['photo_path'] ?? '') !== ''): ?>
                                <img src="<?= e((string)$member['photo_path']) ?>" alt=""
                                     onerror="this.parentNode.innerHTML='<i class=&quot;fa-solid fa-user&quot;></i>'">
                            <?php else: ?>
                                <i class="fa-solid fa-user"></i>
                            <?php endif; ?>
                        </div>
                        <div style="min-width:0">
                            <div class="v2-name amharic"><?= e((string)$member['full_name_am']) ?></div>
                            <?php if (($member['full_name_en'] ?? '') !== ''): ?>
                                <div class="v2-name-sub"><?= e((string)$member['full_name_en']) ?></div>
                            <?php endif; ?>
                            <div class="v2-chips">
                                <span class="bg bg-n">ID <?= e((string)$member['member_code']) ?></span>
                                <?php if (($member['current_section'] ?? '') !== ''): ?>
                                    <span class="bg bg-info"><?= e((string)$member['current_section']) ?></span>
                                <?php endif; ?>
                                <?php if (($member['education_level'] ?? '') !== ''): ?>
                                    <span class="bg bg-p"><?= e((string)$member['education_level']) ?></span>
                                <?php endif; ?>
                                <span class="bg <?= acm_status_bg((string)$member['status']) ?>">
                                    <?= e(ucfirst((string)$member['status'])) ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <dl class="v2-facts">
                        <div class="v2-fact">
                            <dt>Section</dt>
                            <dd class="<?= ($member['current_section'] ?? '') === '' ? 'is-missing' : '' ?>">
                                <?= ($member['current_section'] ?? '') !== '' ? e((string)$member['current_section']) : 'not recorded' ?>
                            </dd>
                        </div>
                        <div class="v2-fact">
                            <dt>Class</dt>
                            <dd class="<?= ($member['education_level'] ?? '') === '' ? 'is-missing' : '' ?>">
                                <?= ($member['education_level'] ?? '') !== '' ? e((string)$member['education_level']) : 'not recorded' ?>
                            </dd>
                        </div>
                        <div class="v2-fact">
                            <dt>Christian name</dt>
                            <dd class="<?= ($member['christian_name'] ?? '') === '' ? 'is-missing' : 'amharic' ?>">
                                <?= ($member['christian_name'] ?? '') !== '' ? e((string)$member['christian_name']) : 'not recorded' ?>
                            </dd>
                        </div>
                        <div class="v2-fact">
                            <dt>Date of birth</dt>
                            <dd class="<?= $dob === null || $dob === '' ? 'is-missing' : '' ?>" data-num>
                                <?= $dob !== null && $dob !== '' ? e(date('j M Y', strtotime((string)$dob))) : 'not recorded' ?>
                            </dd>
                        </div>
                        <div class="v2-fact">
                            <dt>Age</dt>
                            <dd class="<?= $age === null ? 'is-missing' : '' ?>" data-num>
                                <?= $age !== null ? (int)$age . ' years' : 'unknown' ?>
                            </dd>
                        </div>
                        <div class="v2-fact">
                            <dt>Gender</dt>
                            <dd class="<?= ($member['gender'] ?? '') === '' ? 'is-missing' : '' ?>">
                                <?= ($member['gender'] ?? '') !== '' ? e(ucfirst((string)$member['gender'])) : 'not recorded' ?>
                            </dd>
                        </div>
                        <div class="v2-fact">
                            <dt>Father's name</dt>
                            <dd class="<?= ($member['father_name'] ?? '') === '' ? 'is-missing' : 'amharic' ?>">
                                <?= ($member['father_name'] ?? '') !== '' ? e((string)$member['father_name']) : 'not recorded' ?>
                            </dd>
                        </div>
                        <div class="v2-fact">
                            <dt>Account</dt>
                            <dd>
                                <?php if ($person === null): ?>
                                    <span class="bg bg-w">no person record yet</span>
                                <?php else: ?>
                                    <a href="/admin/acm_person.php?id=<?= (int)$person['id'] ?>"
                                       style="color:var(--v2-accent);text-decoration:none">
                                        Person #<?= (int)$person['id'] ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:.6rem"></i>
                                    </a>
                                <?php endif; ?>
                            </dd>
                        </div>
                    </dl>
                <?php else: ?>
                    <div class="v2-state">
                        <i class="fa-solid fa-user-slash"></i>
                        <div class="v2-state-title">No member record to show</div>
                        <p>
                            The ID number on this request does not resolve to a registered
                            member that matches the name given. Search for the person
                            below, or reject and ask them to contact the office.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- what they typed, kept apart from what we have -->
        <div class="v2-claim">
            <div class="v2-claim-label">
                <i class="fa-solid fa-keyboard"></i> What the member submitted
            </div>
            <div class="v2-claim-pair">
                <div>
                    <div class="v2-claim-k">ID number</div>
                    <div class="v2-claim-v v2-code"><?= e((string)$request['member_code']) ?></div>
                </div>
                <div>
                    <div class="v2-claim-k">Name given</div>
                    <div class="v2-claim-v amharic"><?= e((string)$request['name_key']) ?></div>
                </div>
                <div>
                    <div class="v2-claim-k">Phone (last 4)</div>
                    <div class="v2-claim-v">
                        <?= $request['phone_last4'] !== null ? '…' . e((string)$request['phone_last4']) : '—' ?>
                    </div>
                </div>
                <div>
                    <div class="v2-claim-k">Account type</div>
                    <div class="v2-claim-v"><?= e(ucfirst((string)$request['account_type'])) ?></div>
                </div>
                <div>
                    <div class="v2-claim-k">Received</div>
                    <div class="v2-claim-v" data-num><?= e(date('j M, H:i', strtotime((string)$request['created_at']))) ?></div>
                </div>
            </div>
        </div>

        <!-- fallback lookup -->
        <?php if ($isPending): ?>
        <div class="cd" style="margin-top:var(--v2-s3)">
            <div class="ct"><i class="fa-solid fa-magnifying-glass"></i> Looking for someone else?</div>
            <form method="get" class="v2-search" style="max-width:none">
                <input type="hidden" name="id" value="<?= $id ?>">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="q" value="<?= e($q) ?>"
                       placeholder="Search people by name, phone or ID…"
                       aria-label="Search people">
                <button class="btn bp" type="submit">Search</button>
            </form>

            <?php if ($searchResults !== []): ?>
                <div class="v2-list" style="margin-top:var(--v2-s3)">
                    <?php foreach ($searchResults as $p): ?>
                        <?php
                        $linkMember = isset($p['member_id']) && $p['member_id'] !== null ? (int)$p['member_id'] : 0;
                        $linkGuardian = isset($p['guardian_id']) && $p['guardian_id'] !== null ? (int)$p['guardian_id'] : 0;
                        $needed = $request['account_type'] === 'member' ? $linkMember : $linkGuardian;
                        ?>
                        <div class="v2-row" style="grid-template-columns:34px minmax(0,2fr) 120px 130px">
                            <div class="v2-avatar"><i class="fa-solid fa-user"></i></div>
                            <div>
                                <div class="v2-row-name amharic"><?= e((string)($p['full_name_am'] ?? '')) ?></div>
                                <div class="v2-row-meta"><?= e((string)($p['phone'] ?? 'no phone')) ?></div>
                            </div>
                            <div class="v2-row-cell">
                                <?php if ($needed > 0): ?>
                                    <span class="bg bg-ok">link ready</span>
                                <?php else: ?>
                                    <?php /* Approving without a member/guardian
                                             link would create a ticket that
                                             cannot be provisioned. The office
                                             has to attach the record first, so
                                             the button is replaced by the reason
                                             rather than disabled and silent. */ ?>
                                    <span class="bg bg-bad" title="Open the person and link the member or guardian record before approving">link it first</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <?php if ($needed > 0 && $canApprove): ?>
                                    <button class="btn v2-ok acm-approve" style="width:100%"
                                            data-request="<?= $id ?>"
                                            data-person="<?= (int)$p['id'] ?>"
                                            data-member="<?= $request['account_type'] === 'member' ? $linkMember : '' ?>"
                                            data-guardian="<?= $request['account_type'] === 'parent' ? $linkGuardian : '' ?>"
                                            data-ref="<?= e((string)$request['request_ref']) ?>">
                                        Approve
                                    </button>
                                <?php else: ?>
                                    <a class="btn bo" style="width:100%"
                                       href="/admin/acm_person.php?id=<?= (int)$p['id'] ?>">Open</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($q !== ''): ?>
                <p class="dim" style="margin-top:var(--v2-s3);font-size:var(--v2-t-small)">
                    No person matches “<?= e($q) ?>”.
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- timeline -->
        <div class="cd">
            <div class="ct"><i class="fa-solid fa-clock-rotate-left"></i> History</div>
            <?php if ($events === []): ?>
                <p class="dim" style="font-size:var(--v2-t-small)">No events recorded yet.</p>
            <?php else: ?>
                <ul class="v2-timeline">
                    <?php foreach ($events as $ev): ?>
                        <li>
                            <div class="v2-ev"><?= e(ucfirst(str_replace('_', ' ', (string)$ev['event']))) ?></div>
                            <div class="v2-ev-meta">
                                <?= e(date('j M Y, H:i', strtotime((string)$ev['created_at']))) ?>
                                <?php if ((int)($ev['actor_id'] ?? 0) > 0): ?>
                                    · by officer #<?= (int)$ev['actor_id'] ?>
                                <?php else: ?>
                                    · by the member
                                <?php endif; ?>
                                <?php if (($ev['note'] ?? '') !== ''): ?>
                                    · <?= e((string)$ev['note']) ?>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============ RIGHT: the decision ============ -->
    <div class="v2-rail">
        <?php if ($isPending && $canApprove && $resolved && $person !== null): ?>
            <!-- S12: the whole decision happens here. Approving used to send the
                 officer to acm_ticket.php in a new page load; now the ticket and
                 then the code appear in this panel, so the person standing at
                 the counter never watches the officer navigate. -->
            <div class="v2-rail-card" id="acmDecisionPanel">
                <div id="acmApproveStage">
                    <div class="v2-rail-title">Approve</div>
                    <div class="v2-matchline <?= in_array($matchKind, ['exact', 'homophone'], true) ? 'is-ok' : 'is-warn' ?>">
                        <i class="fa-solid <?= in_array($matchKind, ['exact', 'homophone'], true) ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"
                           style="margin-top:2px"></i>
                        <span><?= e($matchDetail !== '' ? $matchDetail : $matchLabel) ?></span>
                    </div>
                    <p class="v2-rail-hint" style="margin-top:var(--v2-s3)">
                        Creates the activation ticket. You will be offered the code
                        next, to read out once.
                    </p>
                    <div class="v2-rail-actions">
                        <button class="btn bp acm-approve" data-primary="1"
                                data-request="<?= $id ?>"
                                data-person="<?= (int)$detection['person_id'] ?>"
                                data-member="<?= (int)($detection['member_id'] ?? 0) ?>"
                                data-guardian="<?= $detection['guardian_id'] !== null ? (int)$detection['guardian_id'] : '' ?>"
                                data-ref="<?= e((string)$request['request_ref']) ?>">
                            <i class="fa-solid fa-check"></i> Approve
                        </button>
                    </div>
                </div>

                <!-- revealed in place once approved -->
                <div id="acmTicketStage" hidden>
                    <div class="v2-rail-title" style="display:flex;align-items:center;gap:var(--v2-s2)">
                        <i class="fa-solid fa-circle-check" style="color:var(--v2-ok)"></i>
                        Approved
                    </div>
                    <p class="v2-rail-hint">
                        Ticket <strong class="v2-code" id="acmTicketNo">—</strong> created.
                    </p>

                    <div id="acmIssueStage">
                        <div class="v2-rail-title" style="font-size:var(--v2-t-small);margin-top:var(--v2-s2)">
                            Next: the activation code
                        </div>
                        <p class="v2-rail-hint">
                            The member types this in the app. It is shown once — read
                            it out, then it is masked for good.
                        </p>
                        <div class="v2-rail-actions">
                            <button class="btn bp" id="acmIssueBtn">
                                <i class="fa-solid fa-key"></i> Issue the code
                            </button>
                        </div>
                        <div class="acm-alert err" id="acmIssueError" style="display:none;margin-top:var(--v2-s2)"></div>
                    </div>

                    <div id="acmCodeBox" hidden>
                        <div style="text-align:center;padding:var(--v2-s4) 0 var(--v2-s3)">
                            <div style="font-size:var(--v2-t-micro);color:var(--v2-ink-4);text-transform:uppercase;letter-spacing:.08em;margin-bottom:var(--v2-s1)">
                                Activation code
                            </div>
                            <div id="acmCodeValue"
                                 style="font-size:1.875rem;font-weight:700;letter-spacing:.14em;color:var(--v2-ink);font-variant-numeric:tabular-nums">
                                —
                            </div>
                            <div id="acmCodeExpiry" style="font-size:var(--v2-t-micro);color:var(--v2-ink-4);margin-top:var(--v2-s1)"></div>
                        </div>
                        <!-- The OTP is scoped to ONE request: verification looks the
                             code up against this request reference, so the member
                             cannot activate without both. Saying "read the code"
                             and stopping would leave them stuck at the form. -->
                        <div class="v2-claim" style="text-align:center">
                            <div class="v2-claim-label" style="justify-content:center">
                                <i class="fa-solid fa-hashtag"></i> Give them this reference too
                            </div>
                            <div class="v2-code" style="font-size:1.125rem;letter-spacing:.1em">
                                <?= e((string)$request['request_ref']) ?>
                            </div>
                        </div>
                        <div class="v2-rail-actions" style="margin-top:var(--v2-s3)">
                            <button class="btn bo" id="acmCopyCode" type="button">
                                <i class="fa-solid fa-copy"></i> Copy code + reference
                            </button>
                        </div>
                        <p class="v2-rail-hint" style="margin-top:var(--v2-s3)">
                            Shown once. Reloading loses the code — reissue from the
                            ticket if that happens. It works a single time, against
                            this reference only.
                        </p>
                    </div>

                    <div class="v2-rail-actions" style="margin-top:var(--v2-s3);border-top:1px solid var(--v2-line);padding-top:var(--v2-s3)">
                        <?php if ($nextPendingId > 0): ?>
                            <a class="btn bp" href="/admin/acm_request.php?id=<?= $nextPendingId ?>">
                                Review the next request <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        <?php endif; ?>
                        <a class="btn bo" href="/admin/acm_ticket.php?id=" id="acmTicketLink" hidden>
                            Open the full ticket
                        </a>
                        <a class="btn bo" href="/admin/acm_requests.php">Back to the queue</a>
                    </div>
                </div>
            </div>
        <?php elseif ($isPending && !$resolved): ?>
            <div class="v2-rail-card">
                <div class="v2-rail-title">Needs a decision</div>
                <div class="v2-matchline is-warn">
                    <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px"></i>
                    <span><?= e($matchLabel) ?>. Pick the person from the search on the left
                    to approve, or reject the request.</span>
                </div>
            </div>
        <?php elseif (!$isPending): ?>
            <div class="v2-rail-card">
                <div class="v2-rail-title">Decided</div>
                <p class="v2-rail-hint">
                    This request is <?= e(strtolower($statusLabel[(string)$request['status']] ?? (string)$request['status'])) ?>.
                    <?php if (($request['match_basis'] ?? null) !== null): ?>
                        Approved on the basis of <code><?= e((string)$request['match_basis']) ?></code>.
                    <?php endif; ?>
                </p>
                <?php if ((int)($request['ticket_id'] ?? 0) > 0): ?>
                    <a class="btn bo" style="width:100%;justify-content:center"
                       href="/admin/acm_ticket.php?id=<?= (int)$request['ticket_id'] ?>">
                        <i class="fa-solid fa-ticket"></i> Open the ticket
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isPending && $canReject): ?>
            <div class="v2-rail-card">
                <div class="v2-rail-title">Reject</div>
                <p class="v2-rail-hint">
                    The member is not told why. A reason is required for the record.
                </p>
                <label class="v2-sr" for="acmRejectReason">Reason for rejecting</label>
                <textarea class="inp" id="acmRejectReason" rows="2"
                          style="width:100%;resize:vertical;margin-bottom:var(--v2-s2)"
                          placeholder="Reason (at least 3 characters)"></textarea>
                <div class="v2-rail-actions">
                    <button class="btn v2-danger" id="acmRejectBtn" data-request="<?= $id ?>">
                        <i class="fa-solid fa-xmark"></i> Reject request
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <div class="v2-rail-card">
            <div class="v2-rail-title" style="font-size:var(--v2-t-small)">Shortcuts</div>
            <p class="v2-rail-hint" style="margin-bottom:var(--v2-s2)">
                <span class="v2-kbd">A</span> approve ·
                <span class="v2-kbd">R</span> reject ·
                <span class="v2-kbd">/</span> search ·
                <span class="v2-kbd">Esc</span> back
            </p>
            <a class="btn bo" style="width:100%;justify-content:center" href="/admin/acm_requests.php">
                <i class="fa-solid fa-arrow-left"></i> Back to the queue
            </a>
        </div>
    </div>
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
    function busy(btn, on) {
        btn.classList.toggle('is-busy', on);
        btn.setAttribute('aria-busy', on ? 'true' : 'false');
    }

    var approveButtons = document.querySelectorAll('.acm-approve');
    approveButtons.forEach(function (btn, index) {
        btn.addEventListener('click', async function () {
            var body = { id: btn.getAttribute('data-request'), person_id: btn.getAttribute('data-person') };
            if (btn.getAttribute('data-member')) { body.member_id = btn.getAttribute('data-member'); }
            if (btn.getAttribute('data-guardian')) { body.guardian_id = btn.getAttribute('data-guardian'); }
            if (!confirm('Approve this request? This creates the activation ticket.')) { return; }
            busy(btn, true);
            try {
                var d = await post('request_approve', body);
                revealTicket(d.ticket || {});
            } catch (e) {
                acmToast(e.message, false);
                busy(btn, false);
            }
        });
        // Keyboard: the first approve action is reachable with "a".
        if (index === 0) { btn.dataset.primary = '1'; }
    });

    // ---- S12 inline ceremony ------------------------------------------
    // Approving no longer navigates. The panel swaps to the ticket, then to
    // the code, so the officer stays where the person is standing.
    var ticketId = 0;

    function revealTicket(ticket) {
        ticketId = ticket.ticket_id || ticket.id || 0;
        var stage = document.getElementById('acmTicketStage');
        var approve = document.getElementById('acmApproveStage');
        if (!stage) { return; }
        if (approve) { approve.hidden = true; }
        stage.hidden = false;
        var no = document.getElementById('acmTicketNo');
        if (no) { no.textContent = ticket.ticket_no || '—'; }
        var link = document.getElementById('acmTicketLink');
        if (link && ticketId) { link.href = '/admin/acm_ticket.php?id=' + ticketId; }
        acmToast('Approved. Ticket ' + (ticket.ticket_no || '') + ' created.', true);
        // Focus moves to the next step, so a keyboard or screen-reader user
        // lands on the action that now matters rather than staying on a
        // button that just disappeared.
        var issue = document.getElementById('acmIssueBtn');
        if (issue) { issue.focus(); }
    }

    var issueBtn = document.getElementById('acmIssueBtn');
    if (issueBtn) {
        issueBtn.addEventListener('click', async function () {
            if (!ticketId) { return; }
            busy(issueBtn, true);
            var errBox = document.getElementById('acmIssueError');
            if (errBox) { errBox.style.display = 'none'; }
            try {
                var d = await post('code_issue', { id: ticketId, purpose: 'activation' });
                var code = (d.code || {});
                var box = document.getElementById('acmCodeBox');
                var val = document.getElementById('acmCodeValue');
                if (val) { val.textContent = code.display || '—'; }
                var exp = document.getElementById('acmCodeExpiry');
                if (exp && code.expires_at) {
                    exp.textContent = 'Expires ' + code.expires_at;
                }
                var issueStage = document.getElementById('acmIssueStage');
                if (issueStage) { issueStage.hidden = true; }
                if (box) { box.hidden = false; }
                var copy = document.getElementById('acmCopyCode');
                if (copy && code.display) {
                    // Both halves are needed for the member to activate.
                    copy.dataset.value = code.display + ' — reference <?= e((string)$request['request_ref']) ?>';
                }
            } catch (e) {
                // Issuing can fail on a terminal ticket, a closed network gate,
                // or a burned out OTP budget. All of those are actionable, so
                // they are shown in place rather than as a transient toast.
                if (errBox) {
                    errBox.textContent = e.message;
                    errBox.style.display = '';
                } else {
                    acmToast(e.message, false);
                }
                busy(issueBtn, false);
            }
        });
    }

    var copyBtn = document.getElementById('acmCopyCode');
    if (copyBtn) {
        copyBtn.addEventListener('click', async function () {
            var v = copyBtn.dataset.value || '';
            try {
                await navigator.clipboard.writeText(v);
                acmToast('Copied.', true);
            } catch (err) {
                acmToast('Copy failed — select it and copy by hand.', false);
            }
        });
    }

    var rejectBtn = document.getElementById('acmRejectBtn');
    var rejectReason = document.getElementById('acmRejectReason');
    if (rejectBtn) {
        rejectBtn.addEventListener('click', async function () {
            var reason = (rejectReason || {}).value || '';
            if (reason.trim().length < 3) {
                acmToast('Write a reason first (at least 3 characters).', false);
                if (rejectReason) { rejectReason.focus(); }
                return;
            }
            if (!confirm('Reject this request?')) { return; }
            busy(rejectBtn, true);
            try {
                await post('request_reject', { id: rejectBtn.getAttribute('data-request'), reason: reason });
                window.location.reload();
            } catch (e) {
                acmToast(e.message, false);
                busy(rejectBtn, false);
            }
        });
    }

    // Keyboard shortcuts. Deliberately NOT active while typing in a field,
    // so a name containing "a" does not approve somebody.
    document.addEventListener('keydown', function (e) {
        var tag = (e.target.tagName || '').toLowerCase();
        var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
        if (typing || e.metaKey || e.ctrlKey || e.altKey) { return; }
        if (e.key === 'a' || e.key === 'A') {
            var primary = document.querySelector('.acm-approve[data-primary="1"]');
            var apr = document.getElementById('acmApproveStage');
            // Only while the approve step is still on screen; after approval
            // "a" must not re-fire an action that has already happened.
            if (primary && (!apr || !apr.hidden)) { e.preventDefault(); primary.click(); }
        } else if (e.key === 'r' || e.key === 'R') {
            if (rejectReason) { e.preventDefault(); rejectReason.focus(); }
        } else if (e.key === '/') {
            var search = document.querySelector('.v2-search input');
            if (search) { e.preventDefault(); search.focus(); }
        } else if (e.key === 'Escape') {
            window.location.href = '/admin/acm_requests.php';
        }
    });
})();
</script>

<?php acm_chrome_close(); ?>
