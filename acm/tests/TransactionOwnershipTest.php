<?php
/**
 * Transaction ownership: a method must JOIN a caller's transaction, never nest.
 *
 * THE BUG THIS FILE EXISTS FOR
 *   Approving an activation request in the console answered
 *   "Service unavailable." and created nothing. Reproduced with executed PHP,
 *   against a fake PDO that enforces PDO's real nesting rule:
 *
 *       AcmRequestDecisionService::approve()   beginTransaction()   depth 1
 *         -> AcmTicketService::create()        beginTransaction()   THROWS
 *
 *   Real PDO refuses the nested call with "There is already an active
 *   transaction". That PDOException is not an InvalidArgumentException, so
 *   admin/api_acm.php's `catch (InvalidArgumentException)` missed it and the
 *   generic `catch (\Throwable)` answered a bare 500 "Service unavailable.",
 *   which the page shows as a red toast with no explanation.
 *
 *   approve() cannot simply drop its own transaction: it also has to
 *   compare-and-set the request row in the same unit of work as the ticket
 *   insert. So the fix belongs in the ticket service, which must not care
 *   whether a transaction is already open.
 *
 * WHY A FAKE, AND WHY THIS FAKE
 *   There is no MySQL in this environment, and the failure is not about data:
 *   it is about who owns the transaction. The fake's whole job is therefore to
 *   be as STRICT as PDO -- nested beginTransaction() throws, commit() and
 *   rollBack() with nothing open throw, and depth is tracked so a leaked
 *   transaction is visible. A permissive fake would have hidden this bug,
 *   which is exactly why the previous lack of any test here went unnoticed.
 */

declare(strict_types=1);

namespace SSMS\Acm\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Identity\AcmPersonService;

require_once __DIR__ . '/../src/Identity/AmharicNameMatcher.php';
require_once __DIR__ . '/../src/Identity/AcmPersonService.php';
require_once __DIR__ . '/../src/Accounts/AcmTicketService.php';

final class TransactionOwnershipTest extends TestCase
{
    public function test_the_fake_really_does_refuse_a_nested_transaction(): void
    {
        // Meta-test. If this ever passes silently, every assertion below
        // becomes worthless because the fake stopped modelling PDO.
        $pdo = new TransactionFakePdo();
        $pdo->beginTransaction();
        $this->expectException(PDOException::class);
        $pdo->beginTransaction();
    }

    public function test_create_joins_a_caller_transaction_instead_of_nesting(): void
    {
        $pdo = new TransactionFakePdo();
        $pdo->beginTransaction();

        $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
        $ticket = $tickets->create(['person_id' => 2, 'account_type' => 'member', 'member_id' => 1], 1);

        $this->assertSame('ACM-20260101-0900', $ticket['ticket_no']);
        // The caller's transaction is still OPEN: create() must not commit
        // work it does not own.
        $this->assertTrue($pdo->inTransaction(), 'create() committed the caller\'s transaction');
        $this->assertSame(0, $pdo->commits, 'create() must not commit a transaction it joined');
        $this->assertSame(1, $pdo->begins, 'create() must not open a second transaction');
    }

    public function test_approve_against_an_open_caller_transaction_does_not_nest(): void
    {
        // The precise production failure, minus the request service: a caller
        // that already holds a transaction creates a ticket.
        $pdo = new TransactionFakePdo();
        $pdo->beginTransaction();
        $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));

        try {
            $tickets->create(['person_id' => 2, 'account_type' => 'member', 'member_id' => 1], 1);
        } catch (PDOException $e) {
            $this->fail('nested beginTransaction() reached PDO: ' . $e->getMessage());
        }

        $pdo->commit();
        $this->assertSame(0, $pdo->depth, 'the transaction bookkeeping did not balance');
    }

    public function test_create_still_owns_its_transaction_when_it_is_the_outermost_caller(): void
    {
        // The same code path, called standalone, must still be atomic.
        $pdo = new TransactionFakePdo();
        $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
        $tickets->create(['person_id' => 2, 'account_type' => 'member', 'member_id' => 1], 1);

        $this->assertSame(1, $pdo->begins, 'standalone create() must open exactly one transaction');
        $this->assertSame(1, $pdo->commits, 'standalone create() must commit it');
        $this->assertSame(0, $pdo->depth);
    }

    public function test_a_failed_create_leaves_no_transaction_open(): void
    {
        $pdo = new TransactionFakePdo();
        $pdo->failNextInsert = true;
        $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));

        try {
            $tickets->create(['person_id' => 2, 'account_type' => 'member', 'member_id' => 1], 1);
            $this->fail('expected the insert to fail');
        } catch (PDOException $expected) {
            // the injected failure
        }

        $this->assertSame(0, $pdo->depth, 'a failed create() leaked an open transaction');
        $this->assertSame(1, $pdo->rollBacks, 'create() must roll back its own transaction');
    }

    public function test_a_failed_create_does_not_roll_back_the_callers_transaction(): void
    {
        // The subtle half of the rule. When create() joins a transaction it
        // must NOT abort it on failure: the caller may still want to record
        // something (an audit row, a refusal) or retry.
        $pdo = new TransactionFakePdo();
        $pdo->failNextInsert = true;
        $pdo->beginTransaction();
        $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));

        try {
            $tickets->create(['person_id' => 2, 'account_type' => 'member', 'member_id' => 1], 1);
            $this->fail('expected the insert to fail');
        } catch (PDOException $expected) {
            // the injected failure
        }

        $this->assertSame(0, $pdo->rollBacks, 'create() rolled back a transaction it did not own');
        $this->assertTrue($pdo->inTransaction(), 'the caller\'s transaction must survive');
    }
}

/**
 * A PDO that is as strict as the real driver about transactions, and answers
 * the handful of statements the ticket service issues.
 */
final class TransactionFakePdo extends PDO
{
    public int $depth = 0;
    public int $begins = 0;
    public int $commits = 0;
    public int $rollBacks = 0;
    public int $lastInsert = 0;
    public bool $failNextInsert = false;

    public function __construct()
    {
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        return new TransactionFakeStmt((string)$query, $this);
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
        $this->rollBacks++;
        return true;
    }

    #[\ReturnTypeWillChange]
    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }
}

final class TransactionFakeStmt
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];
    private int $affected = 1;

    public function __construct(private string $sql, private TransactionFakePdo $db)
    {
    }

    public function execute(array $params = []): bool
    {
        $sql = $this->sql;
        $this->rows = [];
        $this->affected = 1;

        if (str_contains($sql, 'INSERT INTO acm_activation_tickets')) {
            if ($this->db->failNextInsert) {
                $this->db->failNextInsert = false;
                throw new PDOException('INSERT failed');
            }
            $this->db->lastInsert = 900;
            return true;
        }
        if (str_contains($sql, 'FROM acm_activation_tickets t')) {
            // get(), the reload after the write
            $this->rows = [[
                'id' => 900, 'ticket_no' => 'ACM-20260101-0900', 'status' => 'submitted',
                'person_id' => 2, 'account_type' => 'member', 'member_id' => 1,
                'guardian_id' => null, 'submitter_id' => 1, 'evidence' => '{}',
            ]];
            return true;
        }
        if (str_contains($sql, 'FROM acm_activation_tickets')) {
            return true;  // the open-ticket pre-check: nothing in flight
        }
        if (preg_match('/SELECT (id|1) FROM `(members|guardians)`/', $sql) === 1) {
            $this->rows = [['id' => (int)($params[':id'] ?? 0)]];
            return true;
        }
        if (str_contains($sql, 'information_schema')) {
            $this->affected = 0;   // columns probed for absence
            return true;
        }
        if (str_contains($sql, 'FROM acm_persons')) {
            $this->rows = [[
                'id' => 2, 'member_id' => 1, 'guardian_id' => null,
                'student_name' => 'ሸምህ ወልዱ ሸምህ', 'full_name_am' => 'ሸምህ ወልዱ ሸምህ',
                'full_name_en' => null, 'father_name' => 'ወልዱ',
                'phone_primary' => '+251912345678',
            ]];
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
