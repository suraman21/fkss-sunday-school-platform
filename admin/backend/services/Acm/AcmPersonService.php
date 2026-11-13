<?php
/**
 * ACM P0 — person identity service.
 * One row per human; accounts attach via acm_person_accounts so a
 * single person can later hold member + parent logins independently.
 */

namespace App\Services\Acm;

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
        $sql = 'SELECT id, full_name_am, full_name_en, father_name, phone, email, member_id, guardian_id'
            . ' FROM acm_persons WHERE full_name_am LIKE :q ESCAPE \'\\\''
            . ' OR full_name_en LIKE :q ESCAPE \'\\\' OR father_name LIKE :q ESCAPE \'\\\''
            . ' OR phone LIKE :q ESCAPE \'\\\' OR id_doc_ref LIKE :q ESCAPE \'\\\'';
        $params = [':q' => $like];
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
