<?php
/**
 * ACM P0 — person identity service.
 * One row per human; accounts attach via acm_person_accounts so a
 * single person can later hold member + parent logins independently.
 */

namespace SSMS\Acm\Identity;

use InvalidArgumentException;
use PDO;

class AcmPersonService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            unpack('N', substr($b, 0, 4))[1],
            unpack('n', substr($b, 4, 2))[1],
            unpack('n', substr($b, 6, 2))[1],
            unpack('n', substr($b, 8, 2))[1],
            unpack('N', substr($b, 10, 2))[1] * 4294967296 + unpack('N', substr($b, 12, 4))[1]
        );
    }

    /** Same normalization as guardians.phone_primary_n9: last 9 digits. */
    public static function normalizePhoneN9($phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string)$phone);
        if ($digits === '' || strlen($digits) < 9) {
            return null;
        }
        return substr($digits, -9);
    }

    public function create(array $data, int $actorId): array
    {
        $nameAm = trim((string)($data['full_name_am'] ?? ''));
        if (mb_strlen($nameAm) < 2 || mb_strlen($nameAm) > 150) {
            throw new InvalidArgumentException('Full name (Amharic) is required (2-150 chars).');
        }
        $email = trim((string)($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email address is invalid.');
        }
        $gender = $data['gender'] ?? null;
        if ($gender !== null && $gender !== '' && $gender !== 'male' && $gender !== 'female') {
            throw new InvalidArgumentException('Gender must be male or female.');
        }
        $dob = trim((string)($data['date_of_birth'] ?? ''));
        if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            throw new InvalidArgumentException('Date of birth must be YYYY-MM-DD.');
        }
        $memberId = $this->toNullInt($data['member_id'] ?? null);
        $guardianId = $this->toNullInt($data['guardian_id'] ?? null);
        if ($memberId !== null && !$this->rowExists('members', (int)$memberId)) {
            throw new InvalidArgumentException('Linked member was not found.');
        }
        if ($guardianId !== null && !$this->rowExists('guardians', (int)$guardianId)) {
            throw new InvalidArgumentException('Linked guardian was not found.');
        }

        $uuid = self::uuid4();
        $stmt = $this->pdo->prepare(
            'INSERT INTO acm_persons (uuid, full_name_am, full_name_en, baptismal_name, father_name,'
            . ' grandfather_name, gender, date_of_birth, dob_ec_day, dob_ec_month, dob_ec_year,'
            . ' phone, phone_n9, email, id_doc_type, id_doc_ref, member_id, guardian_id, notes, created_by)'
            . ' VALUES (:uuid, :am, :en, :bap, :father, :grand, :gender, :dob, :ecd, :ecm, :ecy,'
            . ' :phone, :n9, :email, :doctype, :docref, :member, :guardian, :notes, :by)'
        );
        $stmt->execute([
            ':uuid'    => $uuid,
            ':am'      => $nameAm,
            ':en'      => $this->toNullStr($data['full_name_en'] ?? null, 150),
            ':bap'     => $this->toNullStr($data['baptismal_name'] ?? null, 150),
            ':father'  => $this->toNullStr($data['father_name'] ?? null, 150),
            ':grand'   => $this->toNullStr($data['grandfather_name'] ?? null, 150),
            ':gender'  => ($gender === '' ? null : $gender),
            ':dob'     => ($dob === '' ? null : $dob),
            ':ecd'     => $this->toNullInt($data['dob_ec_day'] ?? null),
            ':ecm'     => $this->toNullInt($data['dob_ec_month'] ?? null),
            ':ecy'     => $this->toNullInt($data['dob_ec_year'] ?? null),
            ':phone'   => $this->toNullStr($data['phone'] ?? null, 30),
            ':n9'      => self::normalizePhoneN9($data['phone'] ?? null),
            ':email'   => ($email === '' ? null : substr($email, 0, 100)),
            ':doctype' => $this->toNullStr($data['id_doc_type'] ?? null, 40),
            ':docref'  => $this->toNullStr($data['id_doc_ref'] ?? null, 100),
            ':member'  => $memberId,
            ':guardian'=> $guardianId,
            ':notes'   => $this->toNullStr($data['notes'] ?? null, 2000),
            ':by'      => $actorId > 0 ? $actorId : null,
        ]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'uuid' => $uuid];
    }

    public function update(int $id, array $data): bool
    {
        if ($id <= 0 || !$this->find($id)) {
            throw new InvalidArgumentException('Person was not found.');
        }
        $fields = [
            'full_name_en' => $this->toNullStr($data['full_name_en'] ?? null, 150),
            'baptismal_name' => $this->toNullStr($data['baptismal_name'] ?? null, 150),
            'father_name' => $this->toNullStr($data['father_name'] ?? null, 150),
            'grandfather_name' => $this->toNullStr($data['grandfather_name'] ?? null, 150),
            'phone' => $this->toNullStr($data['phone'] ?? null, 30),
            'email' => $this->toNullStr($data['email'] ?? null, 100),
            'id_doc_type' => $this->toNullStr($data['id_doc_type'] ?? null, 40),
            'id_doc_ref' => $this->toNullStr($data['id_doc_ref'] ?? null, 100),
            'notes' => $this->toNullStr($data['notes'] ?? null, 2000),
        ];
        if (array_key_exists('full_name_am', $data)) {
            $nameAm = trim((string)$data['full_name_am']);
            if (mb_strlen($nameAm) < 2 || mb_strlen($nameAm) > 150) {
                throw new InvalidArgumentException('Full name (Amharic) is required (2-150 chars).');
            }
            $fields['full_name_am'] = $nameAm;
        }
        $fields['phone_n9'] = self::normalizePhoneN9($data['phone'] ?? null);
        $set = [];
        $params = [':id' => $id];
        foreach ($fields as $col => $val) {
            $set[] = "`$col` = :$col";
            $params[":$col"] = $val;
        }
        $stmt = $this->pdo->prepare('UPDATE acm_persons SET ' . implode(', ', $set) . ' WHERE id = :id');
        return $stmt->execute($params);
    }

    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM acm_persons WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 60)) . '%';
        $n9 = self::normalizePhoneN9($q);
        // One placeholder per occurrence: PDO cannot reuse a named
        // parameter under native prepares (config.php sets
        // ATTR_EMULATE_PREPARES to false), and the resulting HY093 made
        // every person search fail, not just the odd input.
        $sql = 'SELECT id, full_name_am, full_name_en, father_name, phone, email, member_id, guardian_id'
            . ' FROM acm_persons WHERE full_name_am LIKE :q1 ESCAPE \'\\\\\''
            . ' OR full_name_en LIKE :q2 ESCAPE \'\\\\\' OR father_name LIKE :q3 ESCAPE \'\\\\\''
            . ' OR phone LIKE :q4 ESCAPE \'\\\\\' OR id_doc_ref LIKE :q5 ESCAPE \'\\\\\'';
        $params = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like];
        if ($n9 !== null) {
            $sql .= ' OR phone_n9 = :n9';
            $params[':n9'] = $n9;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Live duplicate flags for review screens (ids only, caller resolves). */
    public function duplicates(array $person): array
    {
        $id = (int)($person['id'] ?? 0);
        $out = ['same_phone' => [], 'same_name_dob' => []];
        $n9 = $person['phone_n9'] ?? self::normalizePhoneN9($person['phone'] ?? null);
        if (is_string($n9) && $n9 !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT id, full_name_am, phone FROM acm_persons WHERE phone_n9 = :n9 AND id <> :id LIMIT 10'
            );
            $stmt->execute([':n9' => $n9, ':id' => $id]);
            $out['same_phone'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $name = trim((string)($person['full_name_am'] ?? ''));
        $dob = (string)($person['date_of_birth'] ?? '');
        if ($name !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            $stmt = $this->pdo->prepare(
                'SELECT id, full_name_am, date_of_birth FROM acm_persons'
                . ' WHERE full_name_am = :nm AND date_of_birth = :dob AND id <> :id LIMIT 10'
            );
            $stmt->execute([':nm' => $name, ':dob' => $dob, ':id' => $id]);
            $out['same_name_dob'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }

    public function accounts(int $personId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pa.id, pa.account_type, pa.status, pa.linked_at, u.id AS user_id,'
            . ' u.username, u.role, u.is_active, u.last_login'
            . ' FROM acm_person_accounts pa JOIN users u ON u.id = pa.user_id'
            . ' WHERE pa.person_id = :pid ORDER BY pa.id ASC'
        );
        $stmt->execute([':pid' => $personId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The person row for a registered member, creating it on first use.
     *
     * WHY THIS EXISTS
     *   A member may activate an account through the public claim route, and
     *   that route refuses any claim whose person link is missing
     *   (AcmActivationDetector returns `no_account`). Nothing ever created
     *   that row for an existing member: the only writer was the manual
     *   person_create in the persons console, and migration 079 creates the
     *   table while leaving the register untouched. So the self-service route
     *   could only ever serve the handful of members somebody had registered
     *   by hand -- every other member was refused no matter what they typed.
     *
     *   The register IS the directory of people, so the person row is derived
     *   from it rather than asked for again: name, father, grandfather,
     *   baptismal name, gender, birth date and phone all already exist on
     *   `members`, and `member_id` is what makes the two rows the same human.
     *
     * SAFE TO CALL TWICE
     *   Returns an existing row untouched (never overwrites a person an
     *   officer has edited).
     *
     *   HONEST LIMIT: `acm_persons.member_id` has a plain KEY, NOT a UNIQUE
     *   key (sql/079), so the database does not stop a second row for the
     *   same member. Two genuinely simultaneous first-claims for one member
     *   can therefore both insert. The window is one statement wide and only
     *   a first claim (afterwards the SELECT short-circuits), and a duplicate
     *   person is recoverable -- `duplicates()` reports it and the office can
     *   merge -- whereas a phantom UNIQUE key would have silently been no
     *   protection at all. Migration 107 adds the real constraint once the
     *   existing duplicates are dealt with.
     *
     * @param int $memberId members.id, already resolved by the detector
     * @param int $actorId  who caused it: 0 for the member themselves
     * @return int|null acm_persons.id, or null when the member is unknown
     */
    public function ensureForMember(int $memberId, int $actorId = 0): ?int
    {
        if ($memberId <= 0) {
            return null;
        }
        $existing = $this->personIdForMember($memberId);
        if ($existing !== null) {
            return $existing;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, full_name_am, full_name_en, student_name, baptismal_name,'
            . ' father_name, grandfather_name, gender, date_of_birth,'
            . ' dob_ec_day, dob_ec_month, dob_ec_year, phone_primary, phone_number'
            . ' FROM members WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $memberId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($m)) {
            return null;
        }

        // The register's primary name column is NOT NULL, but a migrated row
        // can still be blank; fall back through the names it does hold so a
        // person row is never created without a name to show.
        $nameAm = trim((string)($m['full_name_am'] ?? ''));
        if ($nameAm === '') {
            $nameAm = trim((string)($m['student_name'] ?? ''));
        }
        if ($nameAm === '') {
            return null;
        }
        $phone = trim((string)($m['phone_primary'] ?? ''));
        if ($phone === '') {
            $phone = trim((string)($m['phone_number'] ?? ''));
        }

        try {
            $created = $this->create([
                'full_name_am'      => $nameAm,
                'full_name_en'      => (string)($m['full_name_en'] ?? ''),
                'baptismal_name'    => (string)($m['baptismal_name'] ?? ''),
                'father_name'       => (string)($m['father_name'] ?? ''),
                'grandfather_name'  => (string)($m['grandfather_name'] ?? ''),
                'gender'            => (string)($m['gender'] ?? ''),
                'date_of_birth'     => (string)($m['date_of_birth'] ?? ''),
                'dob_ec_day'        => $m['dob_ec_day'] ?? null,
                'dob_ec_month'      => $m['dob_ec_month'] ?? null,
                'dob_ec_year'       => $m['dob_ec_year'] ?? null,
                'phone'             => $phone,
                'member_id'         => $memberId,
                'notes'             => 'Derived from the member register on first activation.',
            ], $actorId);
            return (int)$created['id'];
        } catch (\PDOException $race) {
            // Reached once migration 107's unique key is present: the loser of
            // a race adopts the winner's row. Before 107 there is no
            // constraint to lose against, and the re-read below returns the
            // row just inserted, so this branch simply never fires.
            $won = $this->personIdForMember($memberId);
            if ($won !== null) {
                return $won;
            }
            throw $race;
        }
    }

    /**
     * The person row for a registered guardian (a parent), created on first use.
     *
     * THE VERIFIED-LINK RULE IS INHERITED, ON PURPOSE.
     *   apiParentCanSeeMember() only lets a parent see children whose
     *   member_guardians row has verified_by set (api/v1/core/acl.php). A
     *   parent account projected from an UNVERIFIED link would therefore sign
     *   in and see no children at all -- a failure that looks exactly like "the
     *   app is broken". So this refuses rather than projecting a parent whose
     *   link HR has not verified; the console reports it so HR can stamp the
     *   link, and the projection succeeds on the next attempt.
     *
     * Idempotent, same as ensureForMember(): an existing row is returned
     * untouched so an officer's annotations are never overwritten.
     *
     * @throws InvalidArgumentException when the guardian has no verified child
     */
    public function ensureForGuardian(int $guardianId, int $actorId = 0): ?int
    {
        if ($guardianId <= 0) {
            return null;
        }
        $existing = $this->personIdForGuardian($guardianId);
        if ($existing !== null) {
            return $existing;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, display_name, phone_primary, phone_alt, city, sub_city,'
            . ' woreda, mender, block_number, house, status'
            . ' FROM guardians WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $guardianId]);
        $g = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($g)) {
            return null;
        }
        if ((string)($g['status'] ?? '') !== 'active') {
            // Merged/archived guardians are not people to sign in as; a merged
            // one is reachable through the row it was merged into.
            return null;
        }

        // Is there at least one child whose link an officer verified?
        $verify = $this->pdo->prepare(
            'SELECT COUNT(*) FROM member_guardians'
            . ' WHERE guardian_id = :gid AND verified_by IS NOT NULL'
        );
        $verify->execute([':gid' => $guardianId]);
        if ((int)$verify->fetchColumn() < 1) {
            throw new InvalidArgumentException(
                'This guardian has no verified child link yet. HR must confirm '
                . 'the family link before a parent account can be issued.'
            );
        }

        $name = trim((string)($g['display_name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $phone = trim((string)($g['phone_primary'] ?? ''));
        if ($phone === '') {
            $phone = trim((string)($g['phone_alt'] ?? ''));
        }

        try {
            $created = $this->create([
                'full_name_am' => $name,
                'phone'        => $phone,
                'guardian_id'  => $guardianId,
                // The address lives on the guardian, not the child; note it so
                // an officer reading the person row knows where it came from.
                'notes'        => 'Derived from the guardian register (verified family link).',
            ], $actorId);
            return (int)$created['id'];
        } catch (\PDOException $race) {
            $won = $this->personIdForGuardian($guardianId);
            if ($won !== null) {
                return $won;
            }
            throw $race;
        }
    }

    /** acm_persons.id linked to this guardians.id, or null. */
    public function personIdForGuardian(int $guardianId): ?int
    {
        if ($guardianId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM acm_persons WHERE guardian_id = :gid ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([':gid' => $guardianId]);
        $id = $stmt->fetchColumn();
        return $id === false || $id === null ? null : (int)$id;
    }

    /** acm_persons.id linked to this members.id, or null. */
    public function personIdForMember(int $memberId): ?int
    {
        if ($memberId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM acm_persons WHERE member_id = :mid ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([':mid' => $memberId]);
        $id = $stmt->fetchColumn();
        return $id === false || $id === null ? null : (int)$id;
    }

    private function rowExists(string $table, int $id): bool
    {
        if ($id <= 0 || !in_array($table, ['members', 'guardians'], true)) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM `' . $table . '` WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    private function toNullStr($v, int $max): ?string
    {
        $s = trim((string)($v ?? ''));
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $max);
    }

    private function toNullInt($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        return (int)$v;
    }
}
