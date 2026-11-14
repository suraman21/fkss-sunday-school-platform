<?php
/**
 * ACM S3 — account provisioning core.
 * ------------------------------------------------------------
 * Extracted from AcmTicketService::provision (+ username and email
 * helpers) with two changes: accounts are created PENDING
 * (users.is_active = 0, person link pending) and become usable
 * only after S4 device verification (login already rejects
 * inactive accounts, so no other gate is needed); and username
 * allocation retries on unique-constraint collisions (D3.4).
 * Runs inside the caller's transaction (grant consumption +
 * provisioning commit atomically).
 * S7 adds suspend/reactivate (temporary suspension lifecycle):
 * revoke-first, then ONE transaction owned HERE (link flip +
 * users.is_active flip + history row). State is verified under
 * lock, so concurrent losers get honest state errors.
 */

namespace SSMS\Acm\Accounts;

use PDO;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Policy\AcmPolicy;

class AccountProvisioner
{
    private PDO $pdo;
    /**
     * @var callable|null (int $userId): void. Mysqli-backed,
     * injected by the console (the ACM package stays PDO-only --
     * S4 familyRevoker precedent). Suspend FAILS CLOSED without
     * one: nothing changes, no history row.
     */
    private $sessionRevoker;

    public function __construct(PDO $pdo, ?callable $sessionRevoker = null)
    {
        $this->pdo = $pdo;
        $this->sessionRevoker = $sessionRevoker;
    }

    /**
     * Insert the users row + person link. The caller owns the
     * transaction and the duplicate check (hasAccount).
     * Username collisions resolve inside the transaction via
     * unique-constraint retry (D3.4): the SELECT pre-check wins
     * the common case, the UNIQUE KEY on users.username wins
     * the race. Bounded: 5 attempts, then activation_failed.
     * $actorId 0 = the app user (no console actor): linked_by NULL.
     */
    public function provision(array $ticket, array $person, string $passwordHash, int $actorId): int
    {
        $accountType = (string)$ticket['account_type'];
        $base = $this->buildUsername($accountType, $ticket, $person);
        $email = $this->personEmail($person);
        $fullName = mb_substr(
            (string)(($person['full_name_en'] ?? '') !== '' ? $person['full_name_en'] : $person['full_name_am']),
            0, 100
        );
        $memberId = $accountType === 'member' ? (int)$ticket['member_id'] : null;
        $userId = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // Re-probed every attempt: after a lost race the next
            // free suffix is looked up again instead of guessed.
            $username = $this->nextFreeUsername($base, (int)$ticket['id']);
            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO users (username, email, full_name, role, password_hash, is_active, member_id)'
                    . ' VALUES (:u, :e, :fn, :r, :ph, 0, :mid)'
                );
                $stmt->execute([
                    ':u' => $username, ':e' => $email, ':fn' => $fullName,
                    ':r' => $accountType, ':ph' => $passwordHash, ':mid' => $memberId,
                ]);
                $userId = (int)$this->pdo->lastInsertId();
                break;
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000' || $attempt === 4) {
                    throw $e;
                }
                // Either key may have raced. The email is optional
                // (nullable by design, pre-checked above), so a lost
                // email race degrades to NULL while the username —
                // the account identity — is re-probed above.
                $email = null;
            }
        }
        if ($userId === null || $userId <= 0) {
            throw new \RuntimeException('Username allocation failed.');
        }
        $link = $this->pdo->prepare(
            'INSERT INTO acm_person_accounts (person_id, user_id, account_type, status, linked_by, linked_at)'
            . ' VALUES (:p, :u, :at, \'pending\', :by, NOW())'
        );
        $link->execute([
            ':p' => (int)$ticket['person_id'], ':u' => $userId, ':at' => $accountType,
            ':by' => $actorId > 0 ? $actorId : null,
        ]);
        return $userId;
    }

    /**
     * True when the person already holds an account of this type that
     * must not be duplicated. Pending accounts count: a person
     * mid-ceremony must finish (or be canceled), never double
     * provision. Suspended counts too (recovery path, not a new
     * account). Only revoked leaves room for reprovisioning.
     */
    public function hasAccount(int $personId, string $accountType): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM acm_person_accounts'
            . " WHERE person_id = :p AND account_type = :at"
            . " AND status IN ('pending','active','suspended') LIMIT 1"
        );
        $stmt->execute([':p' => $personId, ':at' => $accountType]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * S4: provisioned user id for a person + account type (active
     * link, newest wins). Null when no active link exists. Single
     * home for the lookup (grant resolution + console device list).
     */
    public function userIdFor(int $personId, string $accountType): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM acm_person_accounts'
            . ' WHERE person_id = :p AND account_type = :at AND status = \'active\''
            . ' ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':p' => $personId, ':at' => $accountType]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function usernameForUser(int $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT username FROM users WHERE id = :u LIMIT 1');
        $stmt->execute([':u' => $userId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string)$name;
    }

    // ------------------------------------------------------------
    // S7: temporary suspension lifecycle (member/parent only).
    // Suspend is revoke-FIRST, then one transaction owned here
    // (link flip + is_active flip + history row). Reactivate is
    // link-first inside one transaction. State is verified under
    // lock (FOR UPDATE): concurrent losers get honest state
    // errors, never half-writes or duplicate history rows.
    // ------------------------------------------------------------

    /**
     * Suspend an ACTIVE account. Guards (policy, target role,
     * reason) run before the revoker fires; the revoker fires
     * before the transaction opens.
     *
     * @return array{user_id:int,username:string,account_type:string,person_id:int}
     */
    public function suspend(int $userId, string $reason, AccountContext $ctx): array
    {
        $this->requireLifecycleVerb($ctx, 'suspend');
        $reason = $this->cleanReason($reason);
        if ($this->sessionRevoker === null) {
            // Fail closed BEFORE anything changes (S4/S6
            // precedent): a suspend that cannot revoke sessions
            // must error, never half-suspend.
            throw new \RuntimeException('Account suspension is unavailable.');
        }
        $target = $this->suspendableTarget($userId);
        if ($target['status'] !== 'active' || (int)$target['is_active'] !== 1) {
            // Fail fast (no pointless revoke); the transaction
            // re-verifies under lock -- this read is advisory.
            throw new \InvalidArgumentException('Account is not active.');
        }
        // Revoke FIRST (#24): a crash here leaves sessions dead +
        // account active -- the user re-logs in (self-healing)
        // and a retry proceeds normally. The reverse order would
        // strand live sessions behind a state error on retry.
        ($this->sessionRevoker)($userId);
        $this->pdo->beginTransaction();
        try {
            $locked = $this->lockedLink($userId);
            if ($locked === null
                || $locked['status'] !== 'active'
                || (int)$locked['is_active'] !== 1
            ) {
                throw new \InvalidArgumentException('Account is not active.');
            }
            $this->pdo->prepare(
                "UPDATE acm_person_accounts SET status = 'suspended'"
                . ' WHERE user_id = :u AND status = \'active\''
            )->execute([':u' => $userId]);
            $this->pdo->prepare(
                'UPDATE users SET is_active = 0 WHERE id = :u AND is_active = 1'
            )->execute([':u' => $userId]);
            $this->insertHistory($userId, $locked, 'suspended', $reason, $ctx->userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return [
            'user_id' => $userId,
            'username' => (string)$target['username'],
            'account_type' => (string)$target['account_type'],
            'person_id' => (int)$target['person_id'],
        ];
    }

    /**
     * Reactivate a suspended account. Accepts the suspended link
     * AND the drifted state (active link + gated bit: staff
     * flipped is_active directly via user administration) --
     * converge, don't strand. Pending/revoked links refuse: the
     * ceremony (S3/S4) and deactivation (step 13) own those.
     *
     * @return array{user_id:int,username:string,account_type:string,person_id:int}
     */
    public function reactivate(int $userId, string $reason, AccountContext $ctx): array
    {
        $this->requireLifecycleVerb($ctx, 'reactivate');
        $reason = $this->cleanReason($reason);
        $target = $this->suspendableTarget($userId);
        if (!$this->isSuspendedState($target)) {
            throw new \InvalidArgumentException('Account is not suspended.');
        }
        $this->pdo->beginTransaction();
        try {
            $locked = $this->lockedLink($userId);
            if ($locked === null || !$this->isSuspendedState($locked)) {
                throw new \InvalidArgumentException('Account is not suspended.');
            }
            // Link-first order: a crash leaves the account gated;
            // retry converges (both UPDATEs are conditional and
            // repeat-safe). No revoker: sessions were revoked at
            // suspend time; the user logs in fresh.
            $this->pdo->prepare(
                "UPDATE acm_person_accounts SET status = 'active'"
                . ' WHERE user_id = :u AND status = \'suspended\''
            )->execute([':u' => $userId]);
            $this->pdo->prepare(
                'UPDATE users SET is_active = 1 WHERE id = :u AND is_active = 0'
            )->execute([':u' => $userId]);
            $this->insertHistory($userId, $locked, 'reactivated', $reason, $ctx->userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return [
            'user_id' => $userId,
            'username' => (string)$target['username'],
            'account_type' => (string)$target['account_type'],
            'person_id' => (int)$target['person_id'],
        ];
    }

    /**
     * Suspended = suspended link (any bit) OR drifted (active
     * link + gated bit). Pending/revoked links are never
     * suspended -- other lifecycles own them.
     */
    private function isSuspendedState(array $row): bool
    {
        if (($row['status'] ?? '') === 'suspended') {
            return true;
        }
        return ($row['status'] ?? '') === 'active' && (int)($row['is_active'] ?? 1) !== 1;
    }

    /**
     * Policy + existence + target-role guards. State checks stay
     * with the callers (advisory pre-read, authoritative lock).
     *
     * @return array{person_id:int,account_type:string,status:string,
     *   username:string,role:string,is_active:int}
     */
    private function suspendableTarget(int $userId): array
    {
        $row = $this->linkRow($userId, false);
        if ($row === null) {
            throw new \InvalidArgumentException('Unknown account.');
        }
        if ($row['role'] !== 'member' && $row['role'] !== 'parent') {
            // v2 D1.1: the office suspends non-officer accounts.
            // Staff and officers stay in user administration
            // (super_admin-only, separate audit trail).
            throw new \InvalidArgumentException(
                'Staff and officer accounts are managed in user administration.'
            );
        }
        return $row;
    }

    private function requireLifecycleVerb(AccountContext $ctx, string $verb): void
    {
        if (!AcmPolicy::can($ctx->role, AcmPolicy::RES_ACCOUNT, $verb)) {
            throw new \InvalidArgumentException('Access denied.');
        }
    }

    private function cleanReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Reason is required.');
        }
        if (mb_strlen($reason) > 2000) {
            throw new \InvalidArgumentException(
                'Reason is too long (2000 characters maximum).'
            );
        }
        return $reason;
    }

    /**
     * The users row + person link, optionally locked. Null when
     * the user or the link is missing (never suspended here).
     */
    private function linkRow(int $userId, bool $forUpdate): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT pa.person_id, pa.account_type, pa.status,'
            . ' u.username, u.role, u.is_active'
            . ' FROM acm_person_accounts pa JOIN users u ON u.id = pa.user_id'
            . ' WHERE pa.user_id = :u LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function lockedLink(int $userId): ?array
    {
        return $this->linkRow($userId, true);
    }

    private function insertHistory(
        int $userId,
        array $locked,
        string $action,
        string $reason,
        int $actorId
    ): void {

        $stmt = $this->pdo->prepare(
            'INSERT INTO acm_account_suspensions'
            . ' (user_id, person_id, account_type, action, reason, actor_id)'
            . ' VALUES (:u, :p, :at, :a, :r, :by)'
        );
        $stmt->execute([
            ':u' => $userId,
            ':p' => (int)$locked['person_id'],
            ':at' => (string)$locked['account_type'],
            ':a' => $action,
            ':r' => $reason,
            ':by' => $actorId > 0 ? $actorId : null,
        ]);
    }

    private function buildUsername(string $accountType, array $ticket, array $person): string
    {
        if ($accountType === 'member' && !empty($ticket['member_id'])) {
            $member = $this->memberCode((int)$ticket['member_id']);
            $code = preg_replace('/[^a-z0-9]/i', '', strtolower((string)$member));
            $base = $code !== '' ? 'm' . $code : 'm' . (int)$ticket['person_id'];
        } else {
            $n9 = AcmPersonService::normalizePhoneN9($person['phone'] ?? null);
            $base = $n9 !== null ? 'p' . $n9 : 'p' . (int)$ticket['person_id'];
        }
        return substr($base, 0, 40);
    }

    /**
     * First free username for $base (numeric suffix on collision).
     * Best-effort pre-check: the UNIQUE KEY is the real arbiter.
     */
    private function nextFreeUsername(string $base, int $ticketId): string
    {
        $candidate = $base;
        for ($i = 2; $i < 100; $i++) {
            if (!$this->usernameTaken($candidate)) {
                return $candidate;
            }
            $candidate = substr($base, 0, 38) . '-' . $i;
        }
        return $base . '-' . substr(md5((string)$ticketId . microtime(true)), 0, 6);
    }

    private function usernameTaken(string $username): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        return $stmt->fetchColumn() !== false;
    }

    private function personEmail(array $person): ?string
    {
        $email = trim((string)($person['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        if ($stmt->fetchColumn() !== false) {
            return null;
        }
        return substr($email, 0, 100);
    }

    private function memberCode(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT member_code FROM members WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $code = $stmt->fetchColumn();
        return $code === false ? '' : (string)$code;
    }
}
