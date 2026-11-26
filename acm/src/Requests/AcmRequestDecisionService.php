<?php
/**
 * ACM self-service activation — the approval transaction.
 * ------------------------------------------------------------
 * Design: docs/design/acm-self-service-activation.md
 * Depends on: 099 (requests), 100 (ticket open guard),
 *             101 (authenticator bindings)
 *
 * WHY THIS IS A SEPARATE CLASS FROM AcmActivationRequestService
 *   The request service deals with untrusted input and must never
 *   bind anything to a person. This class performs the one operation
 *   that turns a claim into a real, authorized account link, so it is
 *   the place where a mistake hands one person another's data. Keeping
 *   it separate means the untrusted half cannot reach the binding code
 *   by accident.
 *
 * THE INVARIANT
 *   Approving a request is idempotent and atomic: either a person is
 *   bound, a ticket exists, and the request says approved — or nothing
 *   changed. A double-click, a retry, or two officers hitting approve
 *   in the same millisecond produce ONE ticket.
 *
 * HOW
 *   1. Lock the request row FOR UPDATE. The lock is the serialization
 *      point: a second approver blocks here until the first commits,
 *      then sees status = 'approved' and stops.
 *   2. Compare-and-set the status as the last write, so even if a
 *      future caller forgets the lock the UPDATE cannot double-apply.
 *   3. The ticket insert is protected independently by migration 100's
 *      UNIQUE (open_guard) — a second ticket for the same person and
 *      account type is refused by the database, not by a pre-check.
 *
 * Every failure path rolls back the whole thing. There is no partial
 * approval state.
 */

declare(strict_types=1);

namespace SSMS\Acm\Requests;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use SSMS\Acm\Accounts\AccountProvisioner;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Identity\AcmPersonService;

final class AcmRequestDecisionService
{
    private PDO $pdo;
    private AcmTicketService $tickets;
    private AcmPersonService $persons;
    private AccountProvisioner $provisioner;

    public function __construct(PDO $pdo, AcmTicketService $tickets, AcmPersonService $persons)
    {
        $this->pdo = $pdo;
        $this->tickets = $tickets;
        $this->persons = $persons;
        $this->provisioner = new AccountProvisioner($pdo);
    }

    /**
     * Approve a request against a specific person.
     *
     * @param int      $requestId
     * @param int      $officerId        the deciding officer
     * @param int      $personId         acm_persons.id the officer CHOSE
     * @param int|null $memberId         members.id   (member accounts)
     * @param int|null $guardianId       guardians.id (parent accounts)
     * @param string   $matchBasis       from AcmActivationRequestService::matchBasis()
     * @return array{ticket_id:int,ticket_no:string,person_id:int}
     * @throws InvalidArgumentException when the request cannot be approved
     */
    public function approve(
        int $requestId,
        int $officerId,
        int $personId,
        ?int $memberId,
        ?int $guardianId,
        string $matchBasis
    ): array {
        if ($officerId <= 0) {
            throw new InvalidArgumentException('Not authenticated.');
        }
        if (!in_array($matchBasis, ['member_code', 'member_code+phone', 'member_code+name', 'manual'], true)) {
            throw new InvalidArgumentException('Unknown match basis.');
        }

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // 1. Serialize on the request row. A concurrent approver
            //    blocks here; when it proceeds, the status check below
            //    stops it.
            $lock = $this->pdo->prepare(
                'SELECT id, status, account_type, member_code, name_key, phone_last4,'
                . ' claimed_by, person_id, ticket_id'
                . ' FROM acm_activation_requests WHERE id = :id FOR UPDATE'
            );
            $lock->execute([':id' => $requestId]);
            $request = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($request)) {
                throw new InvalidArgumentException('Request not found.');
            }
            if ($request['status'] !== 'pending_review') {
                throw new InvalidArgumentException(
                    'Request is ' . str_replace('_', ' ', (string)$request['status']) . ', not pending review.'
                );
            }

            // 2. The person the officer chose must exist and must not
            //    already contradict the claim's link. AcmPersonService
            //    is the owner of that record; we only read it.
            $person = $this->persons->find($personId);
            if ($person === null) {
                throw new InvalidArgumentException('The selected person was not found.');
            }

            $accountType = (string)$request['account_type'];
            if ($accountType === 'member') {
                if ($memberId === null) {
                    throw new InvalidArgumentException('A member account needs a member record.');
                }
                if (!$this->rowExists('members', $memberId)) {
                    throw new InvalidArgumentException('The linked member was not found.');
                }
            } else {
                if ($guardianId === null) {
                    throw new InvalidArgumentException('A parent account needs a guardian record.');
                }
                if (!$this->rowExists('guardians', $guardianId)) {
                    throw new InvalidArgumentException('The linked guardian was not found.');
                }
            }

            // 3. An existing open ticket means a ceremony is already in
            //    flight. Migration 100 makes this impossible to reach
            //    twice, but checking first produces a clear message
            //    instead of a raw 23000.
            $open = $this->pdo->prepare(
                'SELECT id, ticket_no FROM acm_activation_tickets'
                . ' WHERE person_id = :p AND account_type = :at'
                . " AND status IN ('submitted','in_review','changes_requested','approved','code_issued','grant_verified','account_pending_device')"
                . ' LIMIT 1'
            );
            $open->execute([':p' => $personId, ':at' => $accountType]);
            $openTicket = $open->fetch(PDO::FETCH_ASSOC);
            if (is_array($openTicket)) {
                throw new InvalidArgumentException(
                    'This person already has an open activation ticket (' . (string)$openTicket['ticket_no'] . ').'
                );
            }

            // 4. Pin the person's identity link if the record lacks it.
            //    AcmPersonService::update() deliberately cannot change
            //    member_id/guardian_id, so this is the single point where
            //    a link is established, which keeps the contradiction
            //    check in AcmTicketService::create() meaningful.
            $this->ensurePersonLink($personId, $person, $memberId, $guardianId);

            // 5. Create the ticket through the existing service, so the
            //    ticket number sequence, maker-checker rules and audit
            //    events all behave exactly as an office-created ticket.
            $ticket = $this->tickets->create([
                'person_id'    => $personId,
                'account_type' => $accountType,
                'member_id'    => $memberId,
                'guardian_id'  => $guardianId,
            ], $officerId);

            // 6. Compare-and-set the request. Last write wins the race
            //    even if a future caller forgets the FOR UPDATE above.
            $decide = $this->pdo->prepare(
                'UPDATE acm_activation_requests'
                . " SET status = 'approved', decided_by = :uid, decided_at = NOW(),"
                . '     person_id = :pid, ticket_id = :tid, match_basis = :basis,'
                . '     member_code = member_code'
                . " WHERE id = :id AND status = 'pending_review'"
            );
            $decide->execute([
                ':uid'   => $officerId,
                ':pid'   => $personId,
                ':tid'   => (int)$ticket['id'],
                ':basis' => $matchBasis,
                ':id'    => $requestId,
            ]);
            if ($decide->rowCount() !== 1) {
                // Lost the race after the lock (only possible if a
                // caller bypassed it). Roll the ticket back.
                throw new RuntimeException('The request was decided concurrently.');
            }

            $this->recordEvent($requestId, 'approved', $officerId, null, [
                'ticket_no'   => (string)($ticket['ticket_no'] ?? ''),
                'match_basis' => $matchBasis,
                'person_id'   => $personId,
            ]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return [
                'ticket_id' => (int)$ticket['id'],
                'ticket_no' => (string)($ticket['ticket_no'] ?? ''),
                'person_id' => $personId,
            ];
        } catch (\Throwable $error) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Establish the person's member/guardian link when absent. Never
     * overwrites a different link: that is the contradiction
     * AcmTicketService::create() refuses, and refusing it here with a
     * clearer message is better than a raw exception later.
     */
    private function ensurePersonLink(int $personId, array $person, ?int $memberId, ?int $guardianId): void
    {
        $currentMember = isset($person['member_id']) && $person['member_id'] !== null ? (int)$person['member_id'] : null;
        $currentGuardian = isset($person['guardian_id']) && $person['guardian_id'] !== null ? (int)$person['guardian_id'] : null;

        if ($memberId !== null && $currentMember !== null && $currentMember !== $memberId) {
            throw new InvalidArgumentException(
                'This person is already linked to member #' . $currentMember
                . '. Correct the person record instead of the request.'
            );
        }
        if ($guardianId !== null && $currentGuardian !== null && $currentGuardian !== $guardianId) {
            throw new InvalidArgumentException(
                'This person is already linked to guardian #' . $currentGuardian
                . '. Correct the person record instead of the request.'
            );
        }
        if ($memberId === null && $guardianId === null) {
            return;
        }

        // Written directly, NOT through AcmPersonService::update(),
        // because that method deliberately cannot touch member_id /
        // guardian_id (see its $fields list). This is the single
        // sanctioned point where a link is established for an existing
        // person, and it only ever fills an empty column — the
        // contradiction checks above already refused any overwrite.
        $newMember   = $memberId   ?? $currentMember;
        $newGuardian = $guardianId ?? $currentGuardian;

        $stmt = $this->pdo->prepare(
            'UPDATE acm_persons SET member_id = :m, guardian_id = :g WHERE id = :id'
        );
        $stmt->execute([
            ':m'  => $newMember,
            ':g'  => $newGuardian,
            ':id' => $personId,
        ]);
    }

    private function rowExists(string $table, int $id): bool
    {
        // Whitelisted table names only; never interpolate caller input.
        if (!in_array($table, ['members', 'guardians'], true)) {
            throw new InvalidArgumentException('Unknown table.');
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM `' . $table . '` WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    private function recordEvent(int $requestId, string $event, ?int $actorId, ?string $note = null, ?array $meta = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO acm_request_events (request_id, event, actor_id, note, meta)'
            . ' VALUES (:rid, :ev, :actor, :note, :meta)'
        );
        $stmt->execute([
            ':rid'   => $requestId,
            ':ev'    => $event,
            ':actor' => $actorId,
            ':note'  => $note,
            ':meta'  => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
