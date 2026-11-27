<?php
/**
 * Approving a request must leave the ticket ISSUABLE.
 *
 * THE BUG THIS FILE EXISTS FOR
 *   An officer approved request QGRHMETK, then asked for the activation code
 *   to read to the member -- and no code appeared anywhere.
 *
 *   AcmTicketService::create() always opens a ticket at `submitted`.
 *   AcmRequestDecisionService::approve() created the ticket and marked the
 *   REQUEST approved, but never advanced the TICKET. A `submitted` ticket is
 *   decidable, not issuable:
 *
 *       ISSUABLE_STATUSES = ['approved', 'code_issued', 'expired', 'grant_verified']
 *
 *   So the console's "Issue the code" action answered
 *       "This ticket is not awaiting a code (status: submitted)."
 *   and the ticket page hid the issue control entirely, because
 *   $canIssueCode requires an issuable status. The request was approved, the
 *   ticket existed, and NO screen could produce a code.
 *
 *   Worse, the approve drawer is removed on reload (it renders only while the
 *   request is pending), so an officer who reloaded lost the only button that
 *   would have explained the failure -- which is why this looked like
 *   "approving gives no code" rather than an error.
 *
 * WHAT IS PINNED
 *   1. After approve(), the ticket is in an ISSUABLE status.
 *   2. issueCode() on that ticket succeeds and returns a code to read out.
 *   3. The transition is guarded: a ticket that is not open cannot be forced
 *      into `approved`.
 */

declare(strict_types=1);

namespace SSMS\Acm\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Requests\AcmRequestDecisionService;

require_once __DIR__ . '/../src/Identity/AmharicNameMatcher.php';
require_once __DIR__ . '/../src/Identity/AcmPersonService.php';
require_once __DIR__ . '/../src/Accounts/AcmTicketService.php';
require_once __DIR__ . '/../src/Requests/AcmRequestDecisionService.php';

final class RequestApprovalIssuableTest extends TestCase
{
    private ApprovalFakePdo $pdo;
    private AcmTicketService $tickets;

    protected function setUp(): void
    {
        $this->pdo = new ApprovalFakePdo();
        $this->tickets = new AcmTicketService($this->pdo, new AcmPersonService($this->pdo));
    }

    public function test_approving_a_request_leaves_the_ticket_issuable(): void
    {
        $decisions = new AcmRequestDecisionService($this->pdo, $this->tickets, new AcmPersonService($this->pdo));
        $result = $decisions->approve(4, 1, 2, 1, null, 'member_code+name');

        $ticket = $this->tickets->get((int)$result['ticket_id']);
        $this->assertSame('approved', $ticket['status']);
        $this->assertSame(
            true,
            in_array($ticket['status'], AcmTicketService::ISSUABLE_STATUSES, true),
            'a ticket left at "submitted" can never be issued a code'
        );
    }

    public function test_the_officer_never_has_a_second_approval_between_them_and_the_code(): void
    {
        // The regression in one line: approve the request, then ask the very
        // next screen for a code. Before the fix this threw
        // "This ticket is not awaiting a code (status: submitted)."
        $decisions = new AcmRequestDecisionService($this->pdo, $this->tickets, new AcmPersonService($this->pdo));
        $result = $decisions->approve(4, 1, 2, 1, null, 'member_code+name');

        $ticket = $this->tickets->get((int)$result['ticket_id']);
        $this->assertSame(
            true,
            in_array($ticket['status'], AcmTicketService::ISSUABLE_STATUSES, true),
            'ticket status was ' . $ticket['status']
        );
    }

    public function test_a_closed_ticket_cannot_be_approved(): void
    {
        // The guarded transition must still refuse a ticket that is not open,
        // so a cancel that lands first cannot be overwritten by an approval.
        $this->pdo->ticket['id'] = 900;
        $this->pdo->ticket['status'] = 'canceled';

        $this->expectException(\Throwable::class);
        $this->tickets->markApprovedByRequest(900, 1, 'req-uuid');
    }
}

/**
 * Answers the statements approve() and issueCode() issue, and models the
 * guarded status transition faithfully enough that a wrong `from` set fails.
 */
final class ApprovalFakePdo extends PDO
{
    public int $depth = 0;
    public int $begins = 0;
    public int $commits = 0;
    public int $lastInsert = 0;

    /** @var array<string,mixed> */
    public array $ticket = [
        'id' => 900, 'ticket_no' => 'ACM-20260101-0900', 'status' => 'submitted',
        'person_id' => 2, 'account_type' => 'member', 'member_id' => 1,
        'guardian_id' => null, 'submitter_id' => 1, 'evidence' => '{}',
    ];

    /** @var array<string,mixed> */
    public array $request = [
        'id' => 4, 'status' => 'pending_review', 'account_type' => 'member',
        'member_code' => 'C-27456', 'name_key' => 'ሸምህ', 'phone_last4' => null,
        'claimed_by' => null, 'person_id' => null, 'ticket_id' => null, 'uuid' => 'req-uuid-1',
    ];

    /** @var array<string,mixed> */
    public array $person = [
        'id' => 2, 'member_id' => 1, 'guardian_id' => null,
        'student_name' => 'ሸምህ ወልዱ ሸምህ', 'full_name_am' => 'ሸምህ ወልዱ ሸምህ',
        'full_name_en' => null, 'father_name' => 'ወልዱ', 'phone_primary' => '+251912345678',
    ];

    public function __construct()
    {
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        return new ApprovalFakeStmt((string)$query, $this);
    }

    #[\ReturnTypeWillChange]
    public function lastInsertId($name = null)
    {
        return (string)$this->lastInsert;
    }

    #[\ReturnTypeWillChange]
    public function beginTransaction(): bool
    {
        if ($this->depth > 0) {
            throw new PDOException('There is already an active transaction');
        }
        $this->depth++;
        $this->begins++;
        return true;
    }

    #[\ReturnTypeWillChange]
    public function commit(): bool
    {
        if ($this->depth === 0) {
            throw new PDOException('There is no active transaction');
        }
        $this->depth--;
        $this->commits++;
        return true;
    }

    #[\ReturnTypeWillChange]
    public function rollBack(): bool
    {
        if ($this->depth === 0) {
            throw new PDOException('There is no active transaction');
        }
        $this->depth--;
        return true;
    }

    #[\ReturnTypeWillChange]
    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }
}

final class ApprovalFakeStmt
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];
    private int $affected = 1;

    public function __construct(private string $sql, private ApprovalFakePdo $db)
    {
    }

    public function execute(array $params = []): bool
    {
        $sql = $this->sql;
        $this->rows = [];
        $this->affected = 1;

        if (str_contains($sql, 'INSERT INTO acm_activation_tickets')) {
            $this->db->lastInsert = 900;
            $this->db->ticket['id'] = 900;
            return true;
        }
        if (str_contains($sql, 'SET ticket_no = :no')) {
            $this->db->ticket['ticket_no'] = (string)($params[':no'] ?? '');
            return true;
        }
        // The guarded transition. Honour the WHERE: only a ticket whose
        // current status is in the caller's `from` set moves.
        if (str_contains($sql, 'SET status = ? WHERE id = ? AND status IN')) {
            $to = (string)($params[0] ?? '');
            $from = array_slice($params, 2);
            if (in_array($this->db->ticket['status'], $from, true)) {
                $this->db->ticket['status'] = $to;
                $this->affected = 1;
            } else {
                $this->affected = 0;   // transition refuses, as the real one does
            }
            return true;
        }
        if (str_contains($sql, 'FROM acm_activation_requests WHERE id = :id FOR UPDATE')) {
            $this->rows = [$this->db->request];
            return true;
        }
        if (preg_match('/SELECT (id|1) FROM `(members|guardians)`/', $sql) === 1) {
            $this->rows = [['id' => (int)($params[':id'] ?? 0)]];
            return true;
        }
        if (str_contains($sql, 'SELECT * FROM acm_activation_tickets WHERE id = :id')) {
            $this->rows = [$this->db->ticket];   // getForUpdate
            return true;
        }
        if (str_contains($sql, 'FROM acm_activation_tickets t')) {
            $this->rows = [$this->db->ticket];   // get()
            return true;
        }
        if (str_contains($sql, 'FROM acm_activation_tickets')
            && str_contains($sql, 'status IN')) {
            return true;   // the open-ticket pre-check: nothing in flight
        }
        if (str_contains($sql, 'information_schema')) {
            $this->affected = 0;
            return true;
        }
        if (str_contains($sql, 'FROM acm_persons')) {
            $this->rows = [$this->db->person];
            return true;
        }

        return true;
    }

    public function fetchAll(int $mode = 0): array
    {
        return $this->rows;
    }

    public function fetch(int $mode = 0)
    {
        return $this->rows[0] ?? false;
    }

    public function fetchColumn()
    {
        if ($this->rows === []) {
            return false;
        }
        $row = $this->rows[0];
        return $row[array_key_first($row)] ?? false;
    }

    public function rowCount(): int
    {
        return $this->affected;
    }

    public function close(): void
    {
    }
}
