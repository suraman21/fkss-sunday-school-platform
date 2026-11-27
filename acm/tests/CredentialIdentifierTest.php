<?php
/**
 * Establishing credentials must bind a REAL sign-in identifier.
 *
 * THE BUG THIS FILE EXISTS FOR
 *   The member activation page failed on its final step with
 *   "The username is already in use" -- for a member who had never chosen a
 *   username. Reproduced under executed PHP:
 *
 *       $ticket['member_code']   (acm_activation_tickets has no such column)
 *         -> (string) null  ==  ''
 *         -> AuthenticatorDirectory::register()
 *         -> "That identifier cannot be used to sign in."
 *         -> api_acm.php maps EVERY InvalidArgumentException here to
 *            err(..., 409, username_unavailable)
 *         -> the member is told their username is taken.
 *
 *   No migration adds member_code to acm_activation_tickets (sql/079 defines
 *   the table; 100/101/102/103 add other columns). The code lives on the
 *   REGISTER, reachable through the ticket's member_id -- which is also the
 *   only correct source, since the register owns the code.
 *
 * WHAT IS PINNED
 *   1. With no chosen username, the identifier is members.member_code.
 *   2. A ticket whose member has no code fails as `member_code_missing` -- the
 *      office-fixable case -- not with a message accusing the identifier of
 *      being taken.
 *   3. A chosen username still wins.
 */

declare(strict_types=1);

namespace SSMS\Acm\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Activation\ActivationException;
use SSMS\Acm\Activation\ActivationService;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Requests\AuthenticatorDirectory;

require_once __DIR__ . '/../src/Identity/AmharicNameMatcher.php';
require_once __DIR__ . '/../src/Identity/AcmPersonService.php';
require_once __DIR__ . '/../src/Accounts/AcmTicketService.php';
require_once __DIR__ . '/../src/Activation/ActivationException.php';
require_once __DIR__ . '/../src/Activation/ActivationService.php';
require_once __DIR__ . '/../src/Requests/AuthenticatorDirectory.php';

final class CredentialIdentifierTest extends TestCase
{
    private const GRANT = 'GRANTTOKEN0123456789abcd';

    /** Test-only accessor: the fake needs the same token the test sends. */
    public static function grant(): string
    {
        return self::GRANT;
    }

    private const KEY   = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private function service(CredentialFakePdo $pdo): ActivationService
    {
        $persons = new AcmPersonService($pdo);
        return new ActivationService($pdo, new AcmTicketService($pdo, $persons), $persons);
    }

    public function test_the_sign_in_identifier_is_the_registers_member_code(): void
    {
        $pdo = new CredentialFakePdo();
        $pdo->register[1] = 'C-27456';

        $ref = $this->service($pdo)->establishCredentials(self::GRANT, 'password123', self::KEY, null);

        // The account's username is DERIVED from the code by the provisioner
        // ('m' + alphanumerics), so assert on the identifier rather than
        // restating that derivation here.
        $this->assertSame('mc27456', $ref->username);
        // The authenticator row stores only a hash, so assert on that.
        $this->assertContains(
            AuthenticatorDirectory::hash('C-27456'),
            $pdo->registeredHashes,
            'the authenticator row must carry the register member code'
        );
        $this->assertNotContains(
            AuthenticatorDirectory::hash(''),
            $pdo->registeredHashes,
            'the empty identifier is exactly what broke activation'
        );
    }

    public function test_a_ticket_with_NO_member_code_fails_as_a_ticket_problem(): void
    {
        // No register row for member_id: there is nothing to bind.
        $pdo = new CredentialFakePdo();
        $pdo->register = [];

        try {
            $this->service($pdo)->establishCredentials(self::GRANT, 'password123', self::KEY, null);
            $this->fail('expected a refusal');
        } catch (ActivationException $e) {
            // Its own code, so the member is told to contact the office
            // rather than to retry -- there is nothing they can retype.
            $this->assertSame('member_code_missing', $e->machineCode());
            $this->assertStringNotContainsString(
                'already in use', $e->getMessage(),
                'this bug told the member their username was taken'
            );
        }

        $this->assertSame([], $pdo->registeredHashes);
    }

    public function test_a_chosen_username_still_wins(): void
    {
        $pdo = new CredentialFakePdo();
        $pdo->register[1] = 'C-27456';

        $ref = $this->service($pdo)->establishCredentials(
            self::GRANT, 'password123', self::KEY, 'shimels'
        );

        $this->assertSame('shimels', $ref->username);
        $this->assertContains(AuthenticatorDirectory::hash('shimels'), $pdo->registeredHashes);
        $this->assertNotContains(
            AuthenticatorDirectory::hash('C-27456'),
            $pdo->registeredHashes,
            "the member's own choice must not be overridden by their code"
        );
    }
}

/**
 * Answers establishCredentials' statements with a faithful ticket: the ticket
 * row has NO member_code, because the real table does not.
 */
final class CredentialFakePdo extends PDO
{
    public int $depth = 0;
    public int $lastInsert = 77;
    public string $lastUsername = '';

    /** @var array<string,mixed> */
    public array $ticket = [
        'id' => 900, 'status' => 'grant_verified', 'person_id' => 2,
        'account_type' => 'member', 'member_id' => 1, 'guardian_id' => null,
        'submitter_id' => 1, 'evidence' => '{}',
        // NO member_code -- see the class docblock.
    ];

    /** @var array<int,string> members.id => member_code */
    public array $register = [];

    /** @var list<string> identifier hashes the service tried to bind */
    public array $registeredHashes = [];

    /** @var array<string,mixed> */
    public array $person = [
        'id' => 2, 'member_id' => 1, 'full_name_am' => 'ሸምህ ወልዱ ሸምህ',
        'full_name_en' => null, 'father_name' => 'ወልዱ', 'phone' => null, 'email' => null,
    ];

    public function __construct()
    {
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        return new CredentialFakeStmt((string)$query, $this);
    }

    #[\ReturnTypeWillChange]
    public function lastInsertId($name = null)
    {
        return (string)$this->lastInsert;
    }

    #[\ReturnTypeWillChange]
    public function beginTransaction(): bool
    {
        $this->depth++;
        return true;
    }

    #[\ReturnTypeWillChange]
    public function commit(): bool
    {
        $this->depth--;
        return true;
    }

    #[\ReturnTypeWillChange]
    public function rollBack(): bool
    {
        $this->depth--;
        return true;
    }

    #[\ReturnTypeWillChange]
    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }
}

final class CredentialFakeStmt
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];
    private int $affected = 1;

    public function __construct(private string $sql, private CredentialFakePdo $db)
    {
    }

    public function execute(array $params = []): bool
    {
        $sql = $this->sql;
        $this->rows = [];
        $this->affected = 1;

        if (str_contains($sql, 'FROM acm_activation_grants')) {
            $this->rows = [[
                'id' => 5, 'ticket_id' => 900, 'operation' => 'activation',
                'grant_hash' => password_hash(CredentialIdentifierTest::grant(), PASSWORD_DEFAULT),
                'consumed_at' => null, 'revoked_at' => null, 'live' => 1,
            ]];
            return true;
        }
        if (str_contains($sql, 'consumed_at = NOW()')) {
            return true;
        }
        if (str_contains($sql, 'SELECT * FROM acm_activation_tickets')) {
            $this->rows = [$this->db->ticket];
            return true;
        }
        if (str_contains($sql, 'SELECT member_code FROM members')) {
            $id = (int)($params[':id'] ?? 0);
            $this->rows = isset($this->db->register[$id])
                ? [['member_code' => $this->db->register[$id]]]
                : [];
            return true;
        }
        if (str_contains($sql, 'INSERT INTO users')) {
            $this->db->lastInsert = 77;
            $this->db->lastUsername = (string)($params[':u'] ?? '');
            return true;
        }
        if (str_contains($sql, 'UPDATE users SET username')) {
            $this->db->lastUsername = (string)($params[':u'] ?? '');
            return true;
        }
        if (str_contains($sql, 'INSERT INTO acm_person_accounts')) {
            return true;
        }
        if (str_contains($sql, 'INSERT INTO acm_authenticators')) {
            // The row stores identifier_hash. Recording it lets the test assert
            // exactly WHICH identifier the service tried to bind.
            $this->db->registeredHashes[] = (string)($params[':h'] ?? '');
            return true;
        }
        if (str_contains($sql, 'FROM acm_authenticators')) {
            $this->rows = [];      // nobody owns it
            return true;
        }
        if (str_contains($sql, 'SELECT username FROM users')) {
            $this->rows = [['username' => $this->db->lastUsername]];
            return true;
        }
        if (str_contains($sql, 'FROM acm_persons')) {
            $this->rows = [$this->db->person];
            return true;
        }
        if (str_contains($sql, 'information_schema')) {
            $this->affected = 0;
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
