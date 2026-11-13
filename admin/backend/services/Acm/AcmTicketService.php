<?php
/**
 * ACM P0 — activation ticket service (state machine + provisioning).
 * ------------------------------------------------------------
 * submitted -> in_review -> approved -> provisioned
 *                     \-> changes_requested -> (resubmit path is P3)
 *                     \-> rejected
 * Maker-checker: decided_by must differ from submitter_id (all roles).
 * Approve provisions the users row + person link in ONE transaction.
 * P0 credentials: random unusable hash; first credential is issued by
 * helpdesk until the P3 invite flow ships (documented limitation).
 */

namespace App\Services\Acm;

use InvalidArgumentException;
use PDO;
use RuntimeException;

class AcmTicketService
{
    public const SLA_HOURS = 48;

    public const OPEN_STATUSES = ['submitted', 'in_review', 'changes_requested'];
    public const DECIDABLE_STATUSES = ['submitted', 'in_review', 'changes_requested'];

    private PDO $pdo;
    private AcmPersonService $persons;

    public function __construct(PDO $pdo, AcmPersonService $persons)
    {
        $this->pdo = $pdo;
        $this->persons = $persons;
    }

    public function create(array $data, int $submitterId): array
    {
        if ($submitterId <= 0) {
            throw new InvalidArgumentException('Not authenticated.');
        }
        $personId = (int)($data['person_id'] ?? 0);
        $person = $this->persons->find($personId);
        if ($person === null) {
            throw new InvalidArgumentException('Person was not found.');
        }
        $accountType = (string)($data['account_type'] ?? '');
        if ($accountType !== 'member' && $accountType !== 'parent') {
            throw new InvalidArgumentException('Account type must be member or parent.');
        }
        $memberId = $this->toNullInt($data['member_id'] ?? $person['member_id']);
        $guardianId = $this->toNullInt($data['guardian_id'] ?? $person['guardian_id']);
        if ($accountType === 'member' && $memberId === null) {
            throw new InvalidArgumentException('A member account needs a linked member record.');
        }
        if ($accountType === 'parent' && $guardianId === null) {
            throw new InvalidArgumentException('A parent account needs a linked guardian record.');
        }
        if ($memberId !== null && !$this->tableRowExists('members', $memberId)) {
            throw new InvalidArgumentException('Linked member was not found.');
        }
        if ($guardianId !== null && !$this->tableRowExists('guardians', $guardianId)) {
            throw new InvalidArgumentException('Linked guardian was not found.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, ticket_no, status FROM acm_activation_tickets'
            . ' WHERE person_id = :pid AND account_type = :at'
            . " AND status IN ('submitted','in_review','changes_requested') LIMIT 1"
        );
        $stmt->execute([':pid' => $personId, ':at' => $accountType]);
        $open = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($open)) {
            throw new InvalidArgumentException(
                'An open ticket already exists for this person (' . (string)($open['ticket_no'] ?? $open['id']) . ').'
            );
        }

        $evidence = $this->buildEvidence($person, $memberId, $guardianId);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO acm_activation_tickets (uuid, person_id, account_type, status,'
                . ' member_id, guardian_id, evidence, submitter_id, sla_due)'
                . ' VALUES (:uuid, :pid, :at, \'submitted\', :mid, :gid, :ev, :sub,'
                . ' DATE_ADD(NOW(), INTERVAL ' . (int)self::SLA_HOURS . ' HOUR))'
            );
            $stmt->execute([
                ':uuid' => AcmPersonService::uuid4(),
                ':pid'  => $personId,
                ':at'   => $accountType,
                ':mid'  => $memberId,
                ':gid'  => $guardianId,
                ':ev'   => json_encode($evidence, JSON_UNESCAPED_UNICODE),
                ':sub'  => $submitterId,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $ticketNo = 'ACM-' . date('Ymd') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
            $upd = $this->pdo->prepare(
                'UPDATE acm_activation_tickets SET ticket_no = :no WHERE id = :id'
            );
            $upd->execute([':no' => $ticketNo, ':id' => $id]);
            $this->addEvent($id, $submitterId, 'submitted', (string)($data['note'] ?? ''), null);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $ticket = $this->get($id);
        if ($ticket === null) {
            throw new RuntimeException('Ticket was created but could not be reloaded.');
        }
        return $ticket;
    }

    public function get(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT t.*, p.full_name_am, p.full_name_en, p.father_name, p.phone, p.email,'
            . ' p.id_doc_type, p.id_doc_ref, p.date_of_birth,'
            . ' s.username AS submitter_name, a.username AS assignee_name, d.username AS decider_name'
            . ' FROM acm_activation_tickets t'
            . ' JOIN acm_persons p ON p.id = t.person_id'
            . ' LEFT JOIN users s ON s.id = t.submitter_id'
            . ' LEFT JOIN users a ON a.id = t.assignee_id'
            . ' LEFT JOIN users d ON d.id = t.decided_by'
            . ' WHERE t.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ticket)) {
            return null;
        }
        $person = $this->persons->find((int)$ticket['person_id']);
        $ticket['person'] = $person;
        $ticket['member'] = $ticket['member_id'] ? $this->memberRow((int)$ticket['member_id']) : null;
        $ticket['guardian'] = $ticket['guardian_id'] ? $this->guardianRow((int)$ticket['guardian_id']) : null;
        $ticket['family_links'] = $ticket['member_id'] ? $this->familyLinks((int)$ticket['member_id']) : [];
        $ticket['suggestions'] = $ticket['guardian_id']
            ? $this->linkSuggestions((int)$ticket['guardian_id']) : [];
        $ticket['events'] = $this->events($id);
        $ticket['live_duplicates'] = is_array($person) ? $this->persons->duplicates($person) : [];
        $ticket['evidence_parsed'] = json_decode((string)($ticket['evidence'] ?? ''), true);
        return $ticket;
    }

    public function queue(array $filters, int $limit = 25, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $where = [];
        $params = [];
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, ['draft', 'submitted', 'in_review', 'changes_requested', 'approved', 'rejected', 'provisioned', 'archived'], true)) {
                throw new InvalidArgumentException('Unknown status filter.');
            }
            $where[] = 't.status = :status';
            $params[':status'] = $status;
        } else {
            $where[] = "t.status IN ('submitted','in_review','changes_requested')";
        }
        $at = trim((string)($filters['account_type'] ?? ''));
        if ($at === 'member' || $at === 'parent') {
            $where[] = 't.account_type = :at';
            $params[':at'] = $at;
        }
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 60)) . '%';
            $where[] = '(t.ticket_no LIKE :q ESCAPE \'\\\' OR p.full_name_am LIKE :q ESCAPE \'\\\''
                . ' OR p.full_name_en LIKE :q ESCAPE \'\\\' OR p.phone LIKE :q ESCAPE \'\\\')';
            $params[':q'] = $like;
        }
        if (!empty($filters['mine'])) {
            $where[] = 't.assignee_id = :mine';
            $params[':mine'] = (int)$filters['mine'];
        }
        if (!empty($filters['overdue'])) {
            $where[] = "t.sla_due IS NOT NULL AND t.sla_due < NOW()";
        }
        $whereSql = implode(' AND ', $where);
        $cnt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM acm_activation_tickets t JOIN acm_persons p ON p.id = t.person_id'
            . ' WHERE ' . $whereSql
        );
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.ticket_no, t.account_type, t.status, t.sla_due, t.created_at,'
            . ' p.full_name_am, p.phone, s.username AS submitter_name, a.username AS assignee_name'
            . ' FROM acm_activation_tickets t JOIN acm_persons p ON p.id = t.person_id'
            . ' LEFT JOIN users s ON s.id = t.submitter_id'
            . ' LEFT JOIN users a ON a.id = t.assignee_id'
            . ' WHERE ' . $whereSql
            . ' ORDER BY (t.sla_due IS NOT NULL AND t.sla_due < NOW()) DESC, t.sla_due ASC, t.id ASC'
            . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function claim(int $id, int $userId): array
    {
        $this->pdo->beginTransaction();
        try {
            $ticket = $this->lockedTicket($id);
            if ($ticket['status'] === 'in_review' && (int)$ticket['assignee_id'] === $userId) {
                $this->pdo->commit();
                return $this->get($id);
            }
            if ($ticket['status'] !== 'submitted'
                && !($ticket['status'] === 'in_review' && (int)$ticket['assignee_id'] === 0)) {
                throw new InvalidArgumentException('Only unclaimed submitted tickets can be claimed.');
            }
            $stmt = $this->pdo->prepare(
                "UPDATE acm_activation_tickets SET status = 'in_review', assignee_id = :u WHERE id = :id"
            );
            $stmt->execute([':u' => $userId, ':id' => $id]);
            $this->addEvent($id, $userId, 'claimed', '', null);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->get($id);
    }

    public function release(int $id, int $userId, bool $isSuperAdmin): array
    {
        $this->pdo->beginTransaction();
        try {
            $ticket = $this->lockedTicket($id);
            if ($ticket['status'] !== 'in_review') {
                throw new InvalidArgumentException('Only in-review tickets can be released.');
            }
            if (!$isSuperAdmin && (int)$ticket['assignee_id'] !== $userId) {
                throw new InvalidArgumentException('Only the assignee can release this ticket.');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE acm_activation_tickets SET status = \'submitted\', assignee_id = NULL WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
            $this->addEvent($id, $userId, 'released', '', null);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->get($id);
    }

    public function addNote(int $id, int $userId, string $note): array
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('Note is required (max 2000 chars).');
        }
        $ticket = $this->get($id);
        if ($ticket === null) {
            throw new InvalidArgumentException('Ticket was not found.');
        }
        if (!in_array($ticket['status'], self::OPEN_STATUSES, true)) {
            throw new InvalidArgumentException('Closed tickets cannot receive notes.');
        }
        $this->addEvent($id, $userId, 'note', $note, null);
        return $this->get($id);
    }

    /**
     * Decide + (on approve) provision. One transaction, row-locked.
     * Maker-checker: decider must differ from submitter — no override in P0.
     */
    public function decide(
        int $id,
        int $userId,
        string $decision,
        string $note,
        bool $verifiedParentId
    ): array {
        if ($decision !== 'approve' && $decision !== 'reject' && $decision !== 'request_changes') {
            throw new InvalidArgumentException('Unknown decision.');
        }
        $note = trim($note);
        if (($decision === 'reject' || $decision === 'request_changes')
            && (mb_strlen($note) < 3 || mb_strlen($note) > 2000)) {
            throw new InvalidArgumentException('A reason (3-2000 chars) is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $ticket = $this->lockedTicket($id);
            if (!in_array($ticket['status'], self::DECIDABLE_STATUSES, true)) {
                throw new InvalidArgumentException('Ticket is already decided.');
            }
            if ((int)$ticket['submitter_id'] === $userId) {
                throw new InvalidArgumentException('Maker-checker: the submitter cannot decide this ticket.');
            }
            $person = $this->persons->find((int)$ticket['person_id']);
            if ($person === null) {
                throw new RuntimeException('Ticket person is missing.');
            }
            if ($decision === 'approve') {
                $dups = $this->persons->duplicates($person);
                $hasWarnings = !empty($dups['same_phone']) || !empty($dups['same_name_dob']);
                if ($hasWarnings && !$verifiedParentId) {
                    throw new InvalidArgumentException(
                        'Duplicate warnings are open: confirm the parent/member ID check to approve.'
                    );
                }
                $newUserId = $this->provision($ticket, $person, $userId);
                $stmt = $this->pdo->prepare(
                    'UPDATE acm_activation_tickets SET status = \'provisioned\', decided_by = :u,'
                    . ' decision_note = :note, decided_at = NOW() WHERE id = :id'
                );
                $stmt->execute([':u' => $userId, ':note' => ($note === '' ? null : $note), ':id' => $id]);
                $this->addEvent($id, $userId, 'approved', $note, ['provisioned_user_id' => $newUserId]);
                $this->addEvent($id, $userId, 'provisioned', '', ['provisioned_user_id' => $newUserId]);
            } else {
                $status = $decision === 'reject' ? 'rejected' : 'changes_requested';
                $event = $decision === 'reject' ? 'rejected' : 'changes_requested';
                $stmt = $this->pdo->prepare(
                    'UPDATE acm_activation_tickets SET status = :st, decided_by = :u,'
                    . ' decision_note = :note, decided_at = NOW() WHERE id = :id'
                );
                $stmt->execute([':st' => $status, ':u' => $userId, ':note' => $note, ':id' => $id]);
                $this->addEvent($id, $userId, $event, $note, null);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->get($id);
    }

    public function overviewStats(string $role, int $userId): array
    {
        $byStatus = [];
        $stmt = $this->pdo->query(
            'SELECT status, COUNT(*) c FROM acm_activation_tickets GROUP BY status'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byStatus[(string)$row['status']] = (int)$row['c'];
        }
        $open = $this->pdo->query(
            "SELECT COUNT(*) FROM acm_activation_tickets"
            . " WHERE status IN ('submitted','in_review','changes_requested')"
        )->fetchColumn();
        $overdue = $this->pdo->query(
            'SELECT COUNT(*) FROM acm_activation_tickets'
            . " WHERE status IN ('submitted','in_review','changes_requested')"
            . ' AND sla_due IS NOT NULL AND sla_due < NOW()'
        )->fetchColumn();
        $unclaimed = $this->pdo->query(
            'SELECT COUNT(*) FROM acm_activation_tickets'
            . " WHERE status = 'submitted' AND assignee_id IS NULL"
        )->fetchColumn();
        $mine = 0;
        if ($userId > 0) {
            $s = $this->pdo->prepare(
                'SELECT COUNT(*) FROM acm_activation_tickets'
                . " WHERE assignee_id = :u AND status IN ('submitted','in_review','changes_requested')"
            );
            $s->execute([':u' => $userId]);
            $mine = (int)$s->fetchColumn();
        }
        $week = $this->pdo->query(
            'SELECT COUNT(*) FROM acm_activation_tickets'
            . " WHERE status = 'provisioned' AND decided_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();
        return [
            'by_status' => $byStatus,
            'open' => (int)$open,
            'overdue' => (int)$overdue,
            'unclaimed' => (int)$unclaimed,
            'mine' => $mine,
            'provisioned_week' => (int)$week,
            'role' => $role,
        ];
    }

    private function provision(array $ticket, array $person, int $actorId): int
    {
        $accountType = (string)$ticket['account_type'];
        $username = $this->buildUsername($accountType, $ticket, $person);
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, email, full_name, role, password_hash, is_active, member_id)'
            . ' VALUES (:u, :e, :fn, :r, :ph, 1, :mid)'
        );
        $stmt->execute([
            ':u'   => $username,
            ':e'   => $this->personEmail($person),
            ':fn'  => mb_substr(
                (string)(($person['full_name_en'] ?? '') !== '' ? $person['full_name_en'] : $person['full_name_am']),
                0, 100
            ),
            ':r'   => $accountType,
            ':ph'  => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]),
            ':mid' => $accountType === 'member' ? (int)$ticket['member_id'] : null,
        ]);
        $userId = (int)$this->pdo->lastInsertId();
        $link = $this->pdo->prepare(
            'INSERT INTO acm_person_accounts (person_id, user_id, account_type, status, linked_by, linked_at)'
            . ' VALUES (:p, :u, :at, \'active\', :by, NOW())'
        );
        $link->execute([
            ':p' => (int)$ticket['person_id'], ':u' => $userId, ':at' => $accountType, ':by' => $actorId,
        ]);
        return $userId;
    }

    private function buildUsername(string $accountType, array $ticket, array $person): string
    {
        if ($accountType === 'member' && !empty($ticket['member_id'])) {
            $member = $this->memberRow((int)$ticket['member_id']);
            $code = preg_replace('/[^a-z0-9]/i', '', strtolower((string)($member['member_code'] ?? '')));
            $base = $code !== '' ? 'm' . $code : 'm' . (int)$ticket['person_id'];
        } else {
            $n9 = AcmPersonService::normalizePhoneN9($person['phone'] ?? null);
            $base = $n9 !== null ? 'p' . $n9 : 'p' . (int)$ticket['person_id'];
        }
        $base = substr($base, 0, 40);
        $candidate = $base;
        for ($i = 2; $i < 100; $i++) {
            if (!$this->usernameTaken($candidate)) {
                return $candidate;
            }
            $candidate = substr($base, 0, 38) . '-' . $i;
        }
        return $base . '-' . substr(md5((string)$ticket['id'] . microtime(true)), 0, 6);
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

    private function buildEvidence(array $person, ?int $memberId, ?int $guardianId): array
    {
        return [
            'captured_at' => date('Y-m-d H:i:s'),
            'person' => $person,
            'member' => $memberId ? $this->memberRow($memberId) : null,
            'guardian' => $guardianId ? $this->guardianRow($guardianId) : null,
            'family_links' => $memberId ? $this->familyLinks($memberId) : [],
            'duplicate_flags' => $this->persons->duplicates($person),
        ];
    }

    private function memberRow(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, member_code, registration_type, full_name_am, student_name, baptismal_name,'
            . ' father_name, grandfather_name, full_name_en, gender, date_of_birth,'
            . ' current_section, education_level, age_group'
            . ' FROM members WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function guardianRow(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, display_name, phone_primary, phone_alt, city, sub_city, woreda, status'
            . ' FROM guardians WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function familyLinks(int $memberId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mg.guardian_id, mg.relation, mg.is_primary, mg.contact_priority,'
            . ' g.display_name, g.phone_primary, g.status'
            . ' FROM member_guardians mg JOIN guardians g ON g.id = mg.guardian_id'
            . ' WHERE mg.member_id = :mid ORDER BY mg.is_primary DESC, mg.contact_priority ASC'
        );
        $stmt->execute([':mid' => $memberId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function linkSuggestions(int $guardianId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, member_id, guardian_id, status, band, evidence, created_at'
                . ' FROM guardian_link_suggestions'
                . ' WHERE guardian_id = :gid ORDER BY id DESC LIMIT 10'
            );
            $stmt->execute([':gid' => $guardianId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function events(int $ticketId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.event, e.note, e.meta, e.created_at, u.username AS actor_name'
            . ' FROM acm_ticket_events e LEFT JOIN users u ON u.id = e.actor_id'
            . ' WHERE e.ticket_id = :t ORDER BY e.id ASC'
        );
        $stmt->execute([':t' => $ticketId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function addEvent(int $ticketId, int $actorId, string $event, string $note, ?array $meta): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO acm_ticket_events (ticket_id, actor_id, event, note, meta)'
            . ' VALUES (:t, :a, :e, :n, :m)'
        );
        $stmt->execute([
            ':t' => $ticketId,
            ':a' => $actorId > 0 ? $actorId : null,
            ':e' => $event,
            ':n' => ($note === '' ? null : mb_substr($note, 0, 2000)),
            ':m' => $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function lockedTicket(int $id): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Ticket was not found.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM acm_activation_tickets WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InvalidArgumentException('Ticket was not found.');
        }
        return $row;
    }

    private function tableRowExists(string $table, int $id): bool
    {
        if ($id <= 0 || ($table !== 'members' && $table !== 'guardians')) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM `' . $table . '` WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    private function toNullInt($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $i = (int)$v;
        return $i > 0 ? $i : null;
    }
}
