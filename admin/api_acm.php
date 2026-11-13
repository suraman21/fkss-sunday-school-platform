<?php
/**
 * ============================================================
 * ACM P0 console API — Access Control Management.
 * ============================================================
 *   GET  ?action=overview                            → KPI stats
 *   GET  ?action=queue[&status=&account_type=&q=&mine=1&overdue=1&limit=&offset=]
 *   GET  ?action=ticket&id=                          → full ticket detail
 *   GET  ?action=person_search&q=                    → person lookup
 *   GET  ?action=person&id=                          → person + accounts + tickets
 *   POST action=person_create                        → create person (registrar)
 *   POST action=person_update                        → edit person (registrar)
 *   POST action=ticket_create                       → submit ticket (registrar)
 *   POST action=claim | release | note               → queue ops
 *   POST action=decide&decision=approve|reject|request_changes
 *
 * Same JSON contract and security posture as api_identity.php:
 * session auth, ROLE_MAP guard, AcmPolicy per action, CSRF on
 * POST, prepared statements (in services), audit logging.
 * P0 roles: super_admin, registrar, account_activator.
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/Acm/AcmPolicy.php';
require_once __DIR__ . '/backend/services/Acm/AcmPersonService.php';
require_once __DIR__ . '/backend/services/Acm/AcmTicketService.php';

use App\Services\Acm\AcmPersonService;
use App\Services\Acm\AcmPolicy;
use App\Services\Acm\AcmTicketService;
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
            $ticket = $tickets->release((int)($_POST['id'] ?? 0), $actorId, $role === 'super_admin');
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
            $ticket = $tickets->decide(
                (int)($_POST['id'] ?? 0),
                $actorId,
                $decision,
                (string)($_POST['note'] ?? ''),
                !empty($_POST['verified_parent_id'])
            );
            acm_audit($pdo, 'acm.ticket.' . $decision, [
                'ticket_no' => (string)($ticket['ticket_no'] ?? ''),
                'account_type' => (string)($ticket['account_type'] ?? ''),
                'person_id' => (int)($ticket['person_id'] ?? 0),
            ], 'acm_ticket', (int)$ticket['id']);
            acm_ok(['ticket' => $ticket]);
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
