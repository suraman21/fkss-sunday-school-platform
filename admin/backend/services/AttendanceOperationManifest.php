<?php
/** Pure internal request-identity codec. A digest is NOT a receipt or authority. */
namespace App\Services;

final class AttendanceOperationManifest
{
    public const MAX_RECORDS = 2000;
    public const MAX_NOTE_SCALARS = 500;
    public const MAX_EXACT_ID = 9007199254740991;

    /**
     * Strict canonical DTO only, not a raw legacy upload adapter. Caller must
     * supply the ORIGINAL year/context; never infer it from today's scope.
     * No normalization, authorization, roster validation, storage or activation.
     */
    public static function create(array $input): array
    {
        self::keys($input, ['owner_user_id', 'client_op_id', 'packet_kind',
            'class_id', 'academic_year_id', 'date', 'records']);
        foreach (['owner_user_id', 'class_id', 'academic_year_id'] as $key) {
            self::id($input[$key]);
        }
        if (!is_string($input['client_op_id'])
            || !preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $input['client_op_id'])
            || !in_array($input['packet_kind'], ['draft', 'submit'], true)) {
            throw new \InvalidArgumentException('Invalid manifest operation identity.');
        }
        $date = $input['date'];
        if (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
            || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
            throw new \InvalidArgumentException('Invalid original manifest date.');
        }
        $records = $input['records'];
        if (!is_array($records) || !array_is_list($records)
            || count($records) < 1 || count($records) > self::MAX_RECORDS) {
            throw new \InvalidArgumentException('Invalid manifest record list.');
        }
        $seen = [];
        foreach ($records as $record) {
            if (!is_array($record)) throw new \InvalidArgumentException('Invalid manifest record.');
            self::keys($record, ['member_id', 'status', 'notes']);
            self::id($record['member_id']);
            if (isset($seen[$record['member_id']])) {
                throw new \InvalidArgumentException('Duplicate manifest member.');
            }
            $seen[$record['member_id']] = true;
            if (!in_array($record['status'], ['present', 'absent', 'late', 'excused'], true)) {
                throw new \InvalidArgumentException('Invalid manifest status.');
            }
            $notes = $record['notes'];
            if (!is_string($notes) || strlen($notes) > self::MAX_NOTE_SCALARS * 4
                || preg_match('//u', $notes) !== 1
                || preg_match_all('/./us', $notes) > self::MAX_NOTE_SCALARS) {
                throw new \InvalidArgumentException('Invalid manifest notes.');
            }
        }
        // Local copy: canonical ordering must not rewrite the captured input.
        usort($records, static fn(array $a, array $b): int => $a['member_id'] <=> $b['member_id']);
        $hash = hash_init('sha256');
        self::frame($hash, 'ssms.attendance.records.v1');
        self::frame($hash, (string)count($records));
        foreach ($records as $record) {
            self::frame($hash, (string)$record['member_id']);
            self::frame($hash, $record['status']);
            self::frame($hash, $record['notes']);
        }
        $recordsHash = hash_final($hash);
        $manifest = ['manifest_version' => 1, 'domain' => 'attendance'];
        $hash = hash_init('sha256');
        self::frame($hash, 'ssms.attendance.manifest.v1');
        foreach (['owner_user_id', 'client_op_id', 'packet_kind', 'class_id', 'academic_year_id', 'date'] as $key) {
            $manifest[$key] = $input[$key];
            self::frame($hash, (string)$input[$key]);
        }
        self::frame($hash, (string)count($records));
        self::frame($hash, $recordsHash);
        $manifest['record_count'] = count($records);
        $manifest['records_sha256'] = $recordsHash;
        $manifest['manifest_sha256'] = hash_final($hash);
        return $manifest;
    }

    private static function keys(array $input, array $keys): void
    {
        if (count($input) !== count($keys) || array_diff(array_keys($input), $keys) !== []) {
            throw new \InvalidArgumentException('Unsupported or missing manifest field.');
        }
    }

    private static function id($value): void
    {
        if (!is_int($value) || $value < 1 || $value > self::MAX_EXACT_ID) {
            throw new \InvalidArgumentException('Invalid manifest integer identity.');
        }
    }

    private static function frame(\HashContext $hash, string $bytes): void
    {
        hash_update($hash, strlen($bytes) . ':');
        hash_update($hash, $bytes);
        hash_update($hash, ',');
    }
}
