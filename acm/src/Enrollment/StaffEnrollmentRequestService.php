<?php

declare(strict_types=1);

namespace SSMS\Acm\Enrollment;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use SSMS\Acm\Identity\AcmPersonService;

/**
 * ACM S10 — staff enrollment request queue.
 *
 * Request-approve flow (owner decision #35, Shape B): a staffer
 * (session-authed users.id) files a REQUEST; an officer decides it in
 * the console and the approval mints a `staff_enroll` grant. Staff have
 * no acm_persons rows and no tickets — requests key on users.id. No
 * self-approval: requester and decider must differ.
 */
final class StaffEnrollmentRequestService
{
    public const REQUEST_TTL_HOURS = 48;

    private const OPERATIONS = ['activation', 'device_add'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * File an enrollment request for a staffer.
     *
     * @return array{id:int,uuid:string,user_id:int,operation:string,status:string,expires_at:string}
     */
    public function request(int $userId, int $requesterUid, string $operation = 'activation'): array
    {
        if ($requesterUid <= 0) {
            throw new InvalidArgumentException('Not authenticated.');
        }
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException('Unknown enrollment operation.');
        }
        $this->assertEnrollableStaff($userId);
        $this->pdo->beginTransaction();
        try {
            $open = $this->pdo->prepare(
                'SELECT id, uuid FROM acm_staff_enrollment_requests'
                . " WHERE user_id = :u AND status = 'requested' LIMIT 1"
            );
            $open->execute([':u' => $userId]);
            $dup = $open->fetch(PDO::FETCH_ASSOC);
            if (is_array($dup)) {
                throw new InvalidArgumentException(
                    'An open enrollment request already exists for this staffer (#'
                    . (string)($dup['id'] ?? '?') . ').'
                );
            }
            $ins = $this->pdo->prepare(
                'INSERT INTO acm_staff_enrollment_requests'
                . ' (uuid, user_id, requester_uid, operation, status, expires_at)'
                . " VALUES (:uuid, :u, :r, :op, 'requested',"
                . ' DATE_ADD(NOW(), INTERVAL ' . (int)self::REQUEST_TTL_HOURS . ' HOUR))'
            );
            $ins->execute([
                ':uuid' => AcmPersonService::uuid4(),
                ':u' => $userId,
                ':r' => $requesterUid,
                ':op' => $operation,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $row = $this->get($id);
        if ($row === null) {
            throw new RuntimeException('Request was filed but could not be reloaded.');
        }
        return $row;
    }

    /**
     * Officer decision. Approval mints the registration grant inline —
     * decide and grant-issuance are one atomic step. No self-approval.
     *
     * @return array{request:array,grant:array|null}
     */
    public function decide(
        int $requestId,
        int $deciderUid,
        string $decision,
        string $note,
        callable $grantMinter
    ): array {
        if ($deciderUid <= 0) {
            throw new InvalidArgumentException('Not authenticated.');
        }
        if ($decision !== 'approved' && $decision !== 'rejected') {
            throw new InvalidArgumentException('Decision must be approved or rejected.');
        }
        $this->pdo->beginTransaction();
        try {
            $sel = $this->pdo->prepare(
                'SELECT * FROM acm_staff_enrollment_requests WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $sel->execute([':id' => $requestId]);
            $req = $sel->fetch(PDO::FETCH_ASSOC);
            if (!is_array($req)) {
                throw new InvalidArgumentException('Enrollment request was not found.');
            }
            if ((string)$req['status'] !== 'requested') {
                throw new InvalidArgumentException(
                    'This request is no longer pending (status: ' . (string)$req['status'] . ').'
                );
            }
            if (strtotime((string)$req['expires_at']) < time()) {
                $exp = $this->pdo->prepare(
                    "UPDATE acm_staff_enrollment_requests SET status = 'expired'"
                    . " WHERE id = :id AND status = 'requested'"
                );
                $exp->execute([':id' => $requestId]);
                $this->pdo->commit();
                throw new InvalidArgumentException('This request has expired.');
            }
            if ((int)$req['requester_uid'] === $deciderUid) {
                throw new InvalidArgumentException('Requester cannot approve their own request.');
            }
            $grant = null;
            $grantId = null;
            if ($decision === 'approved') {
                $grant = $grantMinter($req);
                if (!is_array($grant) || (int)($grant['id'] ?? 0) <= 0) {
                    throw new RuntimeException('Grant minting failed.');
                }
                $grantId = (int)$grant['id'];
            }
            $upd = $this->pdo->prepare(
                'UPDATE acm_staff_enrollment_requests'
                . ' SET status = :st, decided_by = :d, decided_at = NOW(),'
                . ' decision_note = :n, grant_id = :g'
                . " WHERE id = :id AND status = 'requested'"
            );
            $upd->execute([
                ':st' => $decision,
                ':d' => $deciderUid,
                ':n' => substr($note, 0, 255),
                ':g' => $grantId,
                ':id' => $requestId,
            ]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('Request was decided concurrently.');
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $row = $this->get($requestId);
        if ($row === null) {
            throw new RuntimeException('Request was decided but could not be reloaded.');
        }
        return ['request' => $row, 'grant' => $grant];
    }

    /**
     * Cancel a pending request (requester or any officer).
     */
    public function cancel(int $requestId, int $actorUid): array
    {
        if ($actorUid <= 0) {
            throw new InvalidArgumentException('Not authenticated.');
        }
        $upd = $this->pdo->prepare(
            "UPDATE acm_staff_enrollment_requests SET status = 'canceled'"
            . " WHERE id = :id AND status = 'requested'"
        );
        $upd->execute([':id' => $requestId]);
        if ($upd->rowCount() !== 1) {
            throw new InvalidArgumentException('Request is not pending or was not found.');
        }
        $row = $this->get($requestId);
        if ($row === null) {
            throw new RuntimeException('Request was canceled but could not be reloaded.');
        }
        return $row;
    }

    /**
     * Mark the approved request consumed after its grant registers a
     * device (completeFull `staff_enroll` branch). Idempotent: only an
     * approved request transitions; anything else is a no-op.
     */
    public function markConsumed(int $requestId): void
    {
        $upd = $this->pdo->prepare(
            "UPDATE acm_staff_enrollment_requests SET status = 'consumed'"
            . " WHERE id = :id AND status = 'approved'"
        );
        $upd->execute([':id' => $requestId]);
    }

    /**
     * Lookup by minted grant (completeFull `staff_enroll` branch).
     */
    public function findByGrantId(int $grantId): ?array
    {
        if ($grantId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, uuid, user_id, requester_uid, operation, status,'
            . ' decided_by, decided_at, decision_note, grant_id, expires_at'
            . ' FROM acm_staff_enrollment_requests WHERE grant_id = :g LIMIT 1'
        );
        $stmt->execute([':g' => $grantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array{id:int,uuid:string,user_id:int,requester_uid:int,operation:string,status:string,decided_by:int|null,decided_at:string|null,decision_note:string|null,grant_id:int|null,expires_at:string}|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, uuid, user_id, requester_uid, operation, status,'
            . ' decided_by, decided_at, decision_note, grant_id, expires_at'
            . ' FROM acm_staff_enrollment_requests WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Officer queue: pending first, then recent decided.
     *
     * @return array{rows:array,total:int}
     */
    public function queue(string $status, int $limit = 25, int $offset = 0): array
    {
        $where = '';
        $params = [];
        if ($status !== '') {
            $where = ' WHERE r.status = :st';
            $params[':st'] = $status;
        }
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM acm_staff_enrollment_requests r' . $where
        );
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $sel = $this->pdo->prepare(
            'SELECT r.id, r.uuid, r.user_id, r.requester_uid, r.operation, r.status,'
            . ' r.decided_by, r.decided_at, r.decision_note, r.grant_id, r.expires_at,'
            . ' r.created_at, u.username AS staff_username, u.full_name AS staff_name'
            . ' FROM acm_staff_enrollment_requests r'
            . ' LEFT JOIN users u ON u.id = r.user_id'
            . $where
            . " ORDER BY (r.status = 'requested') DESC, r.created_at DESC"
            . ' LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset)
        );
        $sel->execute($params);
        return ['rows' => $sel->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * The enrollment target must be a live staff login: users row
     * present, is_active = 1, and holding a staff role. Enrollment
     * never revives a deactivated account.
     */
    private function assertEnrollableStaff(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Staff user was not found.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT is_active, role FROM users WHERE id = :u LIMIT 1'
        );
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InvalidArgumentException('Staff user was not found.');
        }
        if ((int)$row['is_active'] !== 1) {
            throw new InvalidArgumentException('Staff account is deactivated.');
        }
        if (!in_array((string)$row['role'], [
            'super_admin', 'school_admin', 'info_dept',
            'edu_dept', 'finance_dept', 'material_dept',
        ], true)) {
            throw new InvalidArgumentException('User is not a staff account.');
        }
    }
}
