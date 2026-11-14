<?php
/**
 * ============================================================
 * ACM S1 console API — Access Control Management.
 * ============================================================
 *   GET  ?action=overview                            → KPI stats
 *   GET  ?action=queue[&status=&account_type=&q=&mine=1&overdue=1&limit=&offset=]
 *   GET  ?action=ticket&id=                          → full ticket detail
 *   GET  ?action=person_search&q=                    → person lookup
 *   GET  ?action=person&id=                          → person + accounts + tickets
 *   POST action=person_create                        → create person (office)
 *   POST action=person_update                        → edit person (office)
 *   POST action=ticket_create                       → submit ticket (office)
 *   POST action=claim | release | note               → queue ops
 *   POST action=decide&decision=approve|reject|request_changes
 *   POST action=code_issue&id=                       → issue activation code (plaintext once)
 *   GET  ?action=code_status&id=                     → code state (never secret/grant)
 *   POST action=ticket_cancel&id=&reason=            → cancel + cascade revoke
 *   GET  ?action=device_list&id=                     → ticket account devices (S4)
 *   POST action=device_revoke&id=&device_uuid=&reason= → revoke + family cascade (S4)
 *   GET  ?action=office_mode_get                     → mode + staffing + history
 *   POST action=office_mode_set                      → switch mode (super_admin)
 *   GET  ?action=approvers_list | reviewers_list     → active designations
 *   POST action=approvers_designate | approvers_revoke      (super_admin)
 *   POST action=reviewers_designate | reviewers_revoke      (super_admin)
 *   GET  ?action=office_users&q=                     → user search (super_admin)
 *   GET  ?action=office_audit&month=YYYY-MM          → monthly report + hash
 *   POST action=office_attest                        → attest month (reviewer)
 *
 * Same JSON contract and security posture as api_identity.php:
 * session auth, ROLE_MAP guard, AcmPolicy per action, CSRF on
 * POST, prepared statements (in services), audit logging.
 * S1 roles: super_admin, accounts_office. Reviewers are a
 * designation (AcmOfficeService), not a user role.
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/RefreshTokenService.php';
require_once __DIR__ . '/backend/services/AccountCredentialService.php';
require_once __DIR__ . '/../acm/autoload.php';

use SSMS\Acm\Accounts\AccountProvisioner;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Activation\ActivationService;
use SSMS\Acm\Activation\CodeAlphabet;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Contracts\CodePurpose;
use SSMS\Acm\Device\DeviceCrypto;
use SSMS\Acm\Device\DeviceService;
use SSMS\Acm\Enrollment\StaffEnrollmentRequestService;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Office\AcmOfficeService;
use SSMS\Acm\OfficeNetwork\OfficeNetworkSignal;
use SSMS\Acm\Policy\AcmPolicy;
use App\Services\SecurityAuditService;

function acm_ok(array $data = []): void
{
    echo json_encode(array_merge(['status' => 'success'], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function acm_err(string $message, int $httpCode = 400): void
{
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    acm_err('Not authenticated.', 401);
}
$role = (string)($_SESSION['admin_role'] ?? '');
$actorId = (int)($_SESSION['admin_id'] ?? 0);
if ($actorId <= 0 || !in_array($role, AcmPolicy::consoleRoles(), true)) {
    acm_err('Access denied.', 403);
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    acm_err('Service unavailable.', 503);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = trim((string)(($method === 'POST' ? $_POST['action'] : $_GET['action']) ?? ''));
if ($method === 'POST' && !validateCsrf($_POST['csrf_token'] ?? null)) {
    acm_err('Invalid session token. Reload and retry.', 403);
}

$persons = new AcmPersonService($pdo);
$tickets = new AcmTicketService($pdo, $persons);
$ceremony = new ActivationService($pdo, $tickets, $persons);
$office = new AcmOfficeService($pdo);
// S7 suspension (revoker mirrors the S4 shape: mysqli-backed,
// fails closed without a live handle -- suspend refuses rather
// than half-suspending).
$consoleSessionRevoker = null;
if (isset($conn) && $conn instanceof \mysqli) {
    $consoleSessionRevoker = static function (int $userId) use ($conn): void {
        (new \App\Services\MysqliCredentialRepository($conn))->revokeAllRefreshSessions($userId);
    };
}
// S4 device registry (revoker mirrors the mobile route: mysqli-backed,
// fails closed without a live handle).
$consoleRevoker = null;
if (isset($conn) && $conn instanceof \mysqli) {
    $consoleRevoker = static function (int $deviceId) use ($conn): int {
        static $refreshForRevoke = null;
        if ($refreshForRevoke === null) {
            $refreshForRevoke = new \App\Services\RefreshTokenService(
                $conn,
                static function (): string {
                    throw new \RuntimeException('Refresh issuance is unavailable here.');
                },
                3600 // Unused on the revoke path (TTL applies to issuance only).
            );
        }
        return $refreshForRevoke->revokeDeviceFamilies($deviceId);
    };
}
$devices = new DeviceService($pdo, $tickets, $consoleRevoker);
// S8 deactivation (device kill loop over the S4 service: the
// revoke is idempotent, so a crash mid-loop converges on
// retry. Fails closed without a live handle, like S4/S7).
$consoleDeviceRevoker = null;
if ($consoleRevoker !== null) {
    $consoleDeviceRevoker = function (int $userId, string $reason) use ($devices, $actorId, $role): int {
        $officer = new AccountContext($actorId, $role);
        $processed = 0;
        foreach ($devices->listForUser($userId) as $device) {
            $devices->revokeWithReport($officer, (string)$device['uuid'], $reason);
            $processed++;
        }
        return $processed;
    };
}
$provisioner = new AccountProvisioner($pdo, $consoleSessionRevoker, $consoleDeviceRevoker);
$isSuperAdmin = $role === 'super_admin';

function acm_audit(PDO $pdo, string $action, array $details, ?string $entityType, ?int $entityId): void
{
    try {
        SecurityAuditService::record($pdo, $action, $details, $entityType, $entityId);
    } catch (\Throwable $e) {
        error_log('ACM audit write failed: ' . $e->getMessage());
    }
}

try {
    switch ($action) {
        case 'overview':
            acm_ok(['stats' => $tickets->overviewStats($role, $actorId)]);
            break;

        case 'queue': {
            if (!AcmPolicy::can($role, AcmPolicy::RES_TICKET, 'view')) {
                acm_err('Access denied.', 403);
            }
            $filters = [
                'status' => $_GET['status'] ?? '',
                'account_type' => $_GET['account_type'] ?? '',
                'q' => $_GET['q'] ?? '',
                'mine' => !empty($_GET['mine']) ? $actorId : 0,
                'overdue' => !empty($_GET['overdue']) ? 1 : 0,
            ];
            $result = $tickets->queue(
                $filters,
                (int)($_GET['limit'] ?? 25),
                (int)($_GET['offset'] ?? 0)
            );
            acm_ok(['queue' => $result['rows'], 'total' => $result['total']]);
            break;
        }

        case 'ticket': {
            if (!AcmPolicy::can($role, AcmPolicy::RES_TICKET, 'view')) {
                acm_err('Access denied.', 403);
            }
            $ticket = $tickets->get((int)($_GET['id'] ?? 0));
            if ($ticket === null) {
                acm_err('Ticket was not found.', 404);
            }
            acm_ok(['ticket' => $ticket]);
            break;
        }

        case 'person_search': {
            if (!AcmPolicy::can($role, AcmPolicy::RES_PERSON, 'view')) {
                acm_err('Access denied.', 403);
            }
            acm_ok(['persons' => $persons->search((string)($_GET['q'] ?? ''))]);
            break;
        }

        case 'person': {
            if (!AcmPolicy::can($role, AcmPolicy::RES_PERSON, 'view')) {
                acm_err('Access denied.', 403);
            }
            $person = $persons->find((int)($_GET['id'] ?? 0));
            if ($person === null) {
                acm_err('Person was not found.', 404);
            }
            $stmt = $pdo->prepare(
                'SELECT id, ticket_no, account_type, status, created_at, decided_at'
                . ' FROM acm_activation_tickets WHERE person_id = :p ORDER BY id DESC LIMIT 20'
            );
            $stmt->execute([':p' => (int)$person['id']]);
            acm_ok([
                'person' => $person,
                'accounts' => $persons->accounts((int)$person['id']),
                'tickets' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'duplicates' => $persons->duplicates($person),
            ]);
            break;
        }

        case 'person_create': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_PERSON, 'create')) {
                acm_err('Access denied.', 403);
            }
            $created = $persons->create($_POST, $actorId);
            acm_audit($pdo, 'acm.person.created', [
                'person_id' => $created['id'],
                'has_member_link' => !empty($_POST['member_id']) ? 1 : 0,
                'has_guardian_link' => !empty($_POST['guardian_id']) ? 1 : 0,
            ], 'acm_person', (int)$created['id']);
            acm_ok(['person' => $persons->find((int)$created['id'])]);
            break;
        }

        case 'person_update': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_PERSON, 'edit')) {
                acm_err('Access denied.', 403);
            }
            $persons->update((int)($_POST['id'] ?? 0), $_POST);
            acm_audit($pdo, 'acm.person.updated', ['person_id' => (int)($_POST['id'] ?? 0)], 'acm_person', (int)($_POST['id'] ?? 0));
            acm_ok(['person' => $persons->find((int)($_POST['id'] ?? 0))]);
            break;
        }

        case 'ticket_create': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_TICKET, 'create')) {
                acm_err('Access denied.', 403);
            }
            $ticket = $tickets->create($_POST, $actorId);
            acm_audit($pdo, 'acm.ticket.submitted', [
                'ticket_no' => (string)($ticket['ticket_no'] ?? ''),
                'account_type' => (string)($ticket['account_type'] ?? ''),
                'person_id' => (int)($ticket['person_id'] ?? 0),
            ], 'acm_ticket', (int)$ticket['id']);
            acm_ok(['ticket' => $ticket]);
            break;
        }

        case 'claim': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_TICKET, 'claim')) {
                acm_err('Access denied.', 403);
            }
            $ticket = $tickets->claim((int)($_POST['id'] ?? 0), $actorId);
            acm_audit($pdo, 'acm.ticket.claimed', [
                'ticket_no' => (string)($ticket['ticket_no'] ?? ''),
            ], 'acm_ticket', (int)$ticket['id']);
            acm_ok(['ticket' => $ticket]);
            break;
        }

        case 'release': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_TICKET, 'claim')) {
                acm_err('Access denied.', 403);
            }
            $ticket = $tickets->release((int)($_POST['id'] ?? 0), $actorId, $isSuperAdmin);
            acm_audit($pdo, 'acm.ticket.released', [
                'ticket_no' => (string)($ticket['ticket_no'] ?? ''),
            ], 'acm_ticket', (int)$ticket['id']);
            acm_ok(['ticket' => $ticket]);
            break;
        }

        case 'note': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_TICKET, 'note')) {
                acm_err('Access denied.', 403);
            }
            $ticket = $tickets->addNote((int)($_POST['id'] ?? 0), $actorId, (string)($_POST['note'] ?? ''));
            acm_audit($pdo, 'acm.ticket.note', [
                'ticket_no' => (string)($ticket['ticket_no'] ?? ''),
            ], 'acm_ticket', (int)$ticket['id']);
            acm_ok(['ticket' => $ticket]);
            break;
        }

        case 'decide': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            $decision = (string)($_POST['decision'] ?? '');
            $needed = $decision === 'approve' ? 'approve'
                : ($decision === 'reject' ? 'reject'
                : ($decision === 'request_changes' ? 'request_changes' : 'approve'));
            if (!AcmPolicy::can($role, AcmPolicy::RES_TICKET, $needed)) {
                acm_err('Access denied.', 403);
            }
            $officeCtx = [
                'mode' => $office->mode(),
                // Solo mode is designee-only with no super_admin shortcut:
                // the governed break-glass is a (reasoned, audited)
                // self-designation first.
                'is_designee' => $office->isActiveApprover($actorId),
                'active_officers' => $office->countActiveOfficers(),
            ];
            $ticket = $tickets->decide(
                (int)($_POST['id'] ?? 0),
                $actorId,
                $decision,
                (string)($_POST['note'] ?? ''),
                !empty($_POST['verified_parent_id']),
                $officeCtx
            );
            acm_audit($pdo, 'acm.ticket.' . $decision, [
                'ticket_no' => (string)($ticket['ticket_no'] ?? ''),
                'account_type' => (string)($ticket['account_type'] ?? ''),
                'person_id' => (int)($ticket['person_id'] ?? 0),
                'mode' => $officeCtx['mode'],
            ], 'acm_ticket', (int)$ticket['id']);
            acm_ok(['ticket' => $ticket]);
            break;
        }

        case 'office_mode_get': {
            acm_ok([
                'mode' => $office->mode(),
                'active_officers' => $office->countActiveOfficers(),
                'is_designee' => $office->isActiveApprover($actorId),
                'is_reviewer' => $office->isActiveReviewer($actorId),
                'history' => $office->modeHistory(),
            ]);
            break;
        }

        case 'office_mode_set': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            $mode = $office->setMode(
                (string)($_POST['mode'] ?? ''),
                (string)($_POST['reason'] ?? ''),
                (string)($_POST['effective_date'] ?? ''),
                $actorId,
                $isSuperAdmin
            );
            acm_audit($pdo, 'acm.office.mode_set', [
                'mode' => $mode,
            ], 'acm_office', null);
            acm_ok(['mode' => $mode]);
            break;
        }

        case 'approvers_list': {
            acm_ok(['approvers' => $office->approvers()]);
            break;
        }

        case 'reviewers_list': {
            acm_ok(['reviewers' => $office->reviewers()]);
            break;
        }

        case 'approvers_designate': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            $row = $office->designateApprover(
                (int)($_POST['user_id'] ?? 0),
                (string)($_POST['reason'] ?? ''),
                (string)($_POST['effective'] ?? ''),
                $actorId,
                $isSuperAdmin
            );
            acm_audit($pdo, 'acm.office.approver_designated', [
                'username' => $row['username'],
            ], 'acm_office', (int)$row['id']);
            acm_ok(['designation' => $row]);
            break;
        }

        case 'approvers_revoke': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            $office->revokeApprover(
                (int)($_POST['user_id'] ?? 0),
                (string)($_POST['reason'] ?? ''),
                (string)($_POST['effective'] ?? ''),
                $actorId,
                $isSuperAdmin
            );
            acm_audit($pdo, 'acm.office.approver_revoked', [
                'user_id' => (int)($_POST['user_id'] ?? 0),
            ], 'acm_office', null);
            acm_ok([]);
            break;
        }

        case 'reviewers_designate': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            $row = $office->designateReviewer(
                (int)($_POST['user_id'] ?? 0),
                (string)($_POST['reason'] ?? ''),
                (string)($_POST['effective'] ?? ''),
                $actorId,
                $isSuperAdmin
            );
            acm_audit($pdo, 'acm.office.reviewer_designated', [
                'username' => $row['username'],
            ], 'acm_office', (int)$row['id']);
            acm_ok(['designation' => $row]);
            break;
        }

        case 'reviewers_revoke': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            $office->revokeReviewer(
                (int)($_POST['user_id'] ?? 0),
                (string)($_POST['reason'] ?? ''),
                (string)($_POST['effective'] ?? ''),
                $actorId,
                $isSuperAdmin
            );
            acm_audit($pdo, 'acm.office.reviewer_revoked', [
                'user_id' => (int)($_POST['user_id'] ?? 0),
            ], 'acm_office', null);
            acm_ok([]);
            break;
        }

        case 'office_users': {
            if (!$isSuperAdmin) {
                acm_err('Access denied.', 403);
            }
            $q = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) {
                acm_ok(['users' => []]);
                break;
            }
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 60)) . '%';
            $stmt = $pdo->prepare(
                'SELECT id, username, full_name, role FROM users'
                . " WHERE is_active = 1 AND (username LIKE :q ESCAPE '\\'"
                . " OR full_name LIKE :q ESCAPE '\\') ORDER BY username ASC LIMIT 20"
            );
            $stmt->execute([':q' => $like]);
            acm_ok(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'office_audit': {
            if (!AcmPolicy::can($role, AcmPolicy::RES_AUDIT, 'view')) {
                acm_err('Access denied.', 403);
            }
            $month = trim((string)($_GET['month'] ?? date('Y-m')));
            acm_ok(['report' => $office->auditReport($month)]);
            break;
        }

        case 'office_attest': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            $row = $office->attest(
                trim((string)($_POST['month'] ?? '')),
                (string)($_POST['note'] ?? ''),
                (string)($_POST['report_fingerprint'] ?? ''),
                $actorId
            );
            acm_audit($pdo, 'acm.office.attested', [
                'month' => (string)($row['month'] ?? ''),
                'kind' => (string)($row['kind'] ?? ''),
                'attestation_id' => (int)($row['id'] ?? 0),
            ], 'acm_office', (int)($row['id'] ?? 0));
            acm_ok(['attestation' => $row]);
            break;
        }

        case 'code_issue': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_CODE, 'issue')) {
                acm_err('Access denied.', 403);
            }
            // Deploy-time policy (D3.7): issuance stays ungated by
            // default; when acm.office_network_issue is '1' the
            // issuing browser must also sit on an office network.
            $signal = $ceremony->networkSignal();
            if ($signal->gatesIssuance()
                && !$signal->allows(OfficeNetworkSignal::resolveClientIp($_SERVER, $signal->trustedProxies()))) {
                acm_err('Code issuance is not available from this network.', 403);
            }
            // S6: reset issuance for credential-recovery
            // ceremonies (strict purpose vocabulary).
            $purposeRaw = (string)($_POST['purpose'] ?? 'activation');
            if ($purposeRaw !== 'activation'
                && $purposeRaw !== 'device_add'
                && $purposeRaw !== 'reset'
            ) {
                acm_err('Unknown code purpose.', 400);
            }
            $issued = $ceremony->issueCode(
                new AccountContext($actorId, $role),
                (string)($_POST['id'] ?? ''),
                $purposeRaw === 'device_add'
                    ? CodePurpose::DeviceAdd
                    : ($purposeRaw === 'reset' ? CodePurpose::Reset : CodePurpose::Activation)
            );
            $ticket = $tickets->get((int)($_POST['id'] ?? 0));
            // Audit carries the public selector only — never the
            // secret (pin-enforced, test_no_token_or_password_keys).
            acm_audit($pdo, 'acm.code.issued', [
                'ticket_no' => (string)($ticket['ticket_no'] ?? ''),
                'selector' => $issued->selector,
                'purpose' => $issued->purpose->value,
                'expires_at' => $issued->expiresAt,
            ], 'acm_ticket', (int)($ticket['id'] ?? 0));
            // Plaintext travels ONLY in this response: the page shows
            // it once, then masks it (D3.2). Never logged, never stored.
            acm_ok(['code' => [
                'display' => $issued->display(CodeAlphabet::prefixFor($issued->purpose)),
                'selector' => $issued->selector,
                'expires_at' => $issued->expiresAt,
            ], 'ticket' => $ticket]);
            break;
        }

        case 'code_status': {
            if (!AcmPolicy::can($role, AcmPolicy::RES_CODE, 'view')) {
                acm_err('Access denied.', 403);
            }
            acm_ok(['status' => $ceremony->codeStatus((int)($_GET['id'] ?? 0))]);
            break;
        }

        case 'ticket_cancel': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_TICKET, 'cancel')) {
                acm_err('Access denied.', 403);
            }
            $ticket = $tickets->cancel(
                (int)($_POST['id'] ?? 0), $actorId, (string)($_POST['reason'] ?? '')
            );
            acm_audit($pdo, 'acm.ticket.canceled', [
                'ticket_no' => (string)($ticket['ticket_no'] ?? ''),
                'account_type' => (string)($ticket['account_type'] ?? ''),
                'person_id' => (int)($ticket['person_id'] ?? 0),
            ], 'acm_ticket', (int)$ticket['id']);
            acm_ok(['ticket' => $ticket]);
            break;
        }

        case 'device_list': {
            if (!AcmPolicy::can($role, AcmPolicy::RES_DEVICE, 'manage')) {
                acm_err('Access denied.', 403);
            }
            $ticket = $tickets->get((int)($_GET['id'] ?? 0));
            if ($ticket === null) {
                acm_err('Ticket was not found.', 404);
            }
            // Ticket-scoped: officers see only this ticket's account.
            // Short fingerprints server-side (D4.6 minimalism).
            $ticketUserId = $provisioner->userIdFor(
                (int)$ticket['person_id'], (string)$ticket['account_type']
            );
            $rows = $ticketUserId === null ? [] : $devices->listForUser($ticketUserId);
            acm_ok(['devices' => array_map(static function (array $row): array {
                return [
                    'device_uuid' => (string)$row['uuid'],
                    'name' => $row['name'] !== null ? (string)$row['name'] : null,
                    'fingerprint' => DeviceCrypto::shortFingerprint((string)$row['fingerprint']),
                    'alg' => (string)$row['alg'],
                    'status' => (string)$row['status'],
                    'first_seen' => (string)$row['first_seen'],
                    'last_seen' => $row['last_seen'] !== null ? (string)$row['last_seen'] : null,
                    'installation' => substr((string)$row['installation_id'], 0, 8) . '...',
                ];
            }, $rows)]);
            break;
        }

        case 'device_revoke': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_DEVICE, 'manage')) {
                acm_err('Access denied.', 403);
            }
            $ticket = $tickets->get((int)($_POST['id'] ?? 0));
            if ($ticket === null) {
                acm_err('Ticket was not found.', 404);
            }
            $ticketUserId = $provisioner->userIdFor(
                (int)$ticket['person_id'], (string)$ticket['account_type']
            );
            // Ticket scope enforced BEFORE the service call (officers
            // bypass the owner check inside revoke, so the route pins
            // the device to this ticket's account first).
            $scopedFp = null;
            if ($ticketUserId !== null) {
                foreach ($devices->listForUser($ticketUserId) as $row) {
                    if (hash_equals((string)$row['uuid'], (string)($_POST['device_uuid'] ?? ''))) {
                        $scopedFp = (string)$row['fingerprint'];
                        break;
                    }
                }
            }
            if ($scopedFp === null) {
                acm_err('Device was not found for this ticket.', 404);
            }
            $report = $devices->revokeWithReport(
                new AccountContext($actorId, $role),
                (string)($_POST['device_uuid'] ?? ''),
                (string)($_POST['reason'] ?? '')
            );
            $tickets->recordEvent(
                (int)$ticket['id'], $actorId, 'device_revoked',
                (string)($_POST['reason'] ?? ''),
                [
                    'device_fp' => DeviceCrypto::shortFingerprint($scopedFp),
                    'families' => $report['families'],
                ]
            );
            acm_audit($pdo, 'acm.device.revoked', [
                'ticket_no' => (string)($ticket['ticket_no'] ?? ''),
                'device_fp' => DeviceCrypto::shortFingerprint($scopedFp),
                'families' => $report['families'],
                'already' => $report['already'],
            ], 'acm_ticket', (int)$ticket['id']);
            acm_ok(['revoked' => true, 'families' => $report['families']]);
            break;
        }

        case 'account_suspend': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_ACCOUNT, 'suspend')) {
                acm_err('Access denied.', 403);
            }
            $result = $provisioner->suspend(
                (int)($_POST['id'] ?? 0),
                (string)($_POST['reason'] ?? ''),
                new AccountContext($actorId, $role)
            );
            // Reviewer-visible: the office report only lists
            // `acm.`-prefixed actions (LIKE 'acm.%').
            acm_audit($pdo, 'acm.account.suspended', [
                'username' => $result['username'],
                'account_type' => $result['account_type'],
                'reason' => trim((string)($_POST['reason'] ?? '')),
                'link' => 'active->suspended',
            ], 'user', $result['user_id']);
            acm_ok(['suspended' => true, 'account' => $result]);
            break;
        }

        case 'account_reactivate': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_ACCOUNT, 'reactivate')) {
                acm_err('Access denied.', 403);
            }
            $result = $provisioner->reactivate(
                (int)($_POST['id'] ?? 0),
                (string)($_POST['reason'] ?? ''),
                new AccountContext($actorId, $role)
            );
            acm_audit($pdo, 'acm.account.reactivated', [
                'username' => $result['username'],
                'account_type' => $result['account_type'],
                'reason' => trim((string)($_POST['reason'] ?? '')),
                'link' => 'suspended->active',
            ], 'user', $result['user_id']);
            acm_ok(['reactivated' => true, 'account' => $result]);
            break;
        }

        case 'account_deactivate': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_ACCOUNT, 'deactivate')) {
                acm_err('Access denied.', 403);
            }
            $result = $provisioner->deactivate(
                (int)($_POST['id'] ?? 0),
                (string)($_POST['reason'] ?? ''),
                new AccountContext($actorId, $role)
            );
            acm_audit($pdo, 'acm.account.deactivated', [
                'username' => $result['username'],
                'account_type' => $result['account_type'],
                'reason' => trim((string)($_POST['reason'] ?? '')),
                'devices' => $result['devices'],
                'link' => 'active/suspended->revoked',
            ], 'user', $result['user_id']);
            acm_ok(['deactivated' => true, 'account' => $result]);
            break;
        }

        // S10: staff enrollment request queue. Same issuance-class
        // policy (RES_CODE/issue) + office-network gate as code
        // issuance — no new policy surface. Audit uses the `acm.`
        // prefix so the reviewer report picks events up with zero
        // report changes.
        case 'staff_enroll_queue': {
            if (!AcmPolicy::can($role, AcmPolicy::RES_CODE, 'view')) {
                acm_err('Access denied.', 403);
            }
            $requests = new StaffEnrollmentRequestService($pdo);
            $result = $requests->queue(
                trim((string)($_GET['status'] ?? '')),
                25,
                max(0, (int)($_GET['offset'] ?? 0))
            );
            acm_ok(['rows' => $result['rows'], 'total' => $result['total']]);
            break;
        }

        case 'staff_enroll_request': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_CODE, 'issue')) {
                acm_err('Access denied.', 403);
            }
            $requests = new StaffEnrollmentRequestService($pdo);
            $row = $requests->request(
                (int)($_POST['user_id'] ?? 0),
                $actorId,
                (string)($_POST['operation'] ?? 'activation')
            );
            acm_audit($pdo, 'acm.staff_enroll.requested', [
                'request_id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'operation' => (string)$row['operation'],
            ], 'acm_staff_enrollment_request', (int)$row['id']);
            acm_ok(['request' => $row]);
            break;
        }

        case 'staff_enroll_decide': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_CODE, 'issue')) {
                acm_err('Access denied.', 403);
            }
            $signal = $ceremony->networkSignal();
            if ($signal->gatesIssuance()
                && !$signal->allows(OfficeNetworkSignal::resolveClientIp($_SERVER, $signal->trustedProxies()))) {
                acm_err('Enrollment approval is not available from this network.', 403);
            }
            $requests = new StaffEnrollmentRequestService($pdo);
            $result = $requests->decide(
                (int)($_POST['id'] ?? 0),
                $actorId,
                (string)($_POST['decision'] ?? ''),
                (string)($_POST['note'] ?? ''),
                static function (array $req) use ($ceremony): array {
                    return $ceremony->mintStaffEnrollGrant($req);
                }
            );
            $req = $result['request'];
            // Audit carries the grant fingerprint only — never the
            // token (same secret rule as code_issue).
            $grantFp = is_array($result['grant'])
                ? substr((string)($result['grant']['fingerprint'] ?? ''), 0, 6) . '...'
                : null;
            acm_audit($pdo, 'acm.staff_enroll.' . (string)$req['status'], [
                'request_id' => (int)$req['id'],
                'user_id' => (int)$req['user_id'],
                'operation' => (string)$req['operation'],
                'grant_fp' => $grantFp,
            ], 'acm_staff_enrollment_request', (int)$req['id']);
            // Plaintext travels ONLY in this response: the page shows
            // it once (officer relays to the staffer out-of-band, same
            // as an issued code), then masks it (D3.2). Never logged,
            // never stored.
            acm_ok(['request' => $req, 'grant' => $result['grant']]);
            break;
        }

        case 'staff_enroll_cancel': {
            if ($method !== 'POST') {
                acm_err('Method not allowed.', 405);
            }
            if (!AcmPolicy::can($role, AcmPolicy::RES_CODE, 'issue')) {
                acm_err('Access denied.', 403);
            }
            $requests = new StaffEnrollmentRequestService($pdo);
            $row = $requests->cancel((int)($_POST['id'] ?? 0), $actorId);
            acm_audit($pdo, 'acm.staff_enroll.canceled', [
                'request_id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
            ], 'acm_staff_enrollment_request', (int)$row['id']);
            acm_ok(['request' => $row]);
            break;
        }

        default:
            acm_err('Unknown action.', 404);
    }
} catch (InvalidArgumentException $e) {
    acm_err($e->getMessage(), 400);
} catch (\Throwable $e) {
    error_log('ACM API failure action=' . $action . ': ' . $e->getMessage());
    acm_err('Service unavailable.', 500);
}
