<?php
/**
 * Role-aware validation and capability policy for member registration.
 *
 * Controllers remain responsible for persistence and file handling; this class
 * defines which registration contract a caller is allowed to use.
 */

namespace App\Services;

use InvalidArgumentException;

final class MemberRegistrationPolicy
{
    private const REGISTRATION_TYPES = ['waiting', 'transfer', 'direct'];
    private const MEMBER_TYPES = ['regular', 'special_regular', 'honorary'];
    private const STATUSES = ['active', 'warning', 'inactive'];
    private const GENDERS = ['male', 'female'];
    private const TIERS = ['temporary', 'permanent'];

    /**
     * @return array{quick_add:bool,allow_uploads:bool,allow_upgrade:bool,full_name_am:string,registration_type:string,member_type:string,status:string,gender:string,membership_tier:string,phone_number:string,current_section:string}
     */
    public static function prepare(array $input, string $role): array
    {
        // School-admin registration (quick_add) was removed entirely on
        // 2026-11-18: the school dashboard no longer registers members.
        if ($role === 'school_admin') {
            throw new InvalidArgumentException('School administrators cannot register members.');
        }
        // quick_add is now always false (capability removed); the contract
        // keys stay so existing callers keep working unchanged.
        $quickAdd = false;
        $fullName = self::text($input['full_name_am'] ?? '', 150, 'Full name');
        if ($fullName === '') {
            throw new InvalidArgumentException('Full name is required.');
        }
        if ($quickAdd && count(preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: []) < 2) {
            throw new InvalidArgumentException('Student and father name are required.');
        }

        $genderInput = $input['gender'] ?? '';
        if (!is_scalar($genderInput)) {
            throw new InvalidArgumentException('Invalid gender.');
        }
        $genderRaw = trim((string)$genderInput);
        if ($quickAdd && $genderRaw === '') {
            throw new InvalidArgumentException('Gender is required.');
        }
        $gender = self::enum($genderRaw === '' ? 'male' : $genderRaw, self::GENDERS, 'gender');
        // M8: temporary tier is ALWAYS waiting type (import coerces the same
        // way; the HR form locks it client-side). A crafted temp+direct POST
        // used to slip through and mint a code immediately, contradicting the
        // codes-pending doctrine for waiting members.
        $tierEarly = $quickAdd
            ? 'permanent'
            : self::enum($input['membership_tier'] ?? 'permanent', self::TIERS, 'membership tier');
        $registrationType = ($tierEarly === 'temporary')
            ? 'waiting'
            : self::enum(
                trim((string)($input['registration_type'] ?? 'waiting')),
                self::REGISTRATION_TYPES,
                'registration type'
            );
        // PROMOTION CONSISTENCY (2026-11-18, mirror of M8 above): a
        // permanent tier is never waiting type. permanent+waiting can only
        // arrive from a legacy row, the HR form's untouched default, or a
        // crafted POST — coerce to direct. Transfer keeps its background.
        if ($tierEarly === 'permanent' && $registrationType === 'waiting') {
            $registrationType = 'direct';
        }
        $phone = self::text($input['phone_number'] ?? '', 30, 'Phone number');
        if ($phone !== '' && !preg_match('/^[0-9+()\- .]+$/', $phone)) {
            throw new InvalidArgumentException('Phone number contains unsupported characters.');
        }

        return [
            'quick_add' => $quickAdd,
            'allow_uploads' => !$quickAdd,
            'allow_upgrade' => !$quickAdd && in_array($role, ['super_admin', 'hr_dept'], true),
            'full_name_am' => $fullName,
            'registration_type' => $registrationType,
            'member_type' => $quickAdd
                ? 'regular'
                : self::enum($input['member_type'] ?? 'regular', self::MEMBER_TYPES, 'member type'),
            'status' => $quickAdd
                ? 'active'
                : self::enum($input['status'] ?? 'active', self::STATUSES, 'status'),
            'gender' => $gender,
            'membership_tier' => $tierEarly,
            'phone_number' => $phone,
            'current_section' => self::text($input['current_section'] ?? '', 100, 'Section'),
        ];
    }

    private static function text($value, int $max, string $label): string
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Invalid ' . $label . '.');
        }
        $value = trim((string)$value);
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException($label . ' must be valid UTF-8.');
        }
        $value = (string)preg_replace('/\s+/u', ' ', $value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $max) {
            throw new InvalidArgumentException($label . ' is too long.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException($label . ' contains unsupported characters.');
        }
        return $value;
    }

    private static function enum($value, array $allowed, string $label): string
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Invalid ' . $label . '.');
        }
        $value = trim((string)$value);
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Invalid ' . $label . '.');
        }
        return $value;
    }
}
