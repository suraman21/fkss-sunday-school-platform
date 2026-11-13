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
 */

namespace SSMS\Acm\Accounts;

use PDO;
use SSMS\Acm\Identity\AcmPersonService;

class AccountProvisioner
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
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
