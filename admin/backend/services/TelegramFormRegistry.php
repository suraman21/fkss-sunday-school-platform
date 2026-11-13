<?php
/** Safe, closed registry for Telegram registration questions. */
namespace App\Services;

use InvalidArgumentException;

final class TelegramFormRegistry
{
    /** @var array<string,array<string,mixed>> */
    private const FIELDS = [
        'full_name_am' => ['max'=>150, 'skip'=>false],
        'baptismal_name' => ['max'=>150, 'skip'=>true],
        'gender' => ['max'=>10, 'skip'=>false],
        'dob_ec' => ['max'=>10, 'skip'=>true],
        'phone_number' => ['max'=>30, 'skip'=>true],
        'guardian_name' => ['max'=>150, 'skip'=>true],
        'guardian_phone1' => ['max'=>30, 'skip'=>true],
        'city' => ['max'=>60, 'skip'=>true],
        'sub_city' => ['max'=>60, 'skip'=>true],
        'woreda' => ['max'=>10, 'skip'=>true],
        'mender' => ['max'=>10, 'skip'=>true],
        'house_number' => ['max'=>20, 'skip'=>true],
        'education_level' => ['max'=>60, 'skip'=>true],
        'spiritual_education' => ['max'=>100, 'skip'=>true],
        'current_section' => ['max'=>100, 'skip'=>true],
    ];

    /** @return list<string> */
    public static function keys(): array { return array_keys(self::FIELDS); }
    /** @return array<string,array<string,mixed>> */
    public static function definitions(): array { return self::FIELDS; }
    public static function exists(string $key): bool { return isset(self::FIELDS[$key]); }

    /** @return array{display:string,normalized:string} */
    public static function validate(string $key, $raw, bool $required = false): array
    {
        if (!isset(self::FIELDS[$key]) || !is_scalar($raw)) {
            throw new InvalidArgumentException('Unsupported registration field.');
        }
        $value = trim((string)$raw);
        $skip = in_array(strtolower($value), ['/skip','skip','ዝለል'], true);
        if ($skip) {
            if ($required || !self::FIELDS[$key]['skip']) {
                throw new InvalidArgumentException('This answer is required.');
            }
            return ['display'=>'', 'normalized'=>''];
        }
        if ($value === '') {
            if ($required) throw new InvalidArgumentException('This answer is required.');
            return ['display'=>'', 'normalized'=>''];
        }
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Please enter valid text.');
        }
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > (int)self::FIELDS[$key]['max']) {
            throw new InvalidArgumentException('The answer is too long.');
        }

        if ($key === 'full_name_am') {
            $parts = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) < 2) throw new InvalidArgumentException('Enter at least first and father name.');
            return ['display'=>$value, 'normalized'=>AmharicIdentityNormalizer::normalize($value)];
        }
        if ($key === 'gender') {
            $map = ['male'=>'male','female'=>'female','ወንድ'=>'male','ሴት'=>'female'];
            $normalized = $map[function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value)] ?? null;
            if ($normalized === null) throw new InvalidArgumentException('Choose Male or Female.');
            return ['display'=>$normalized === 'male' ? 'ወንድ / Male' : 'ሴት / Female', 'normalized'=>$normalized];
        }
        if ($key === 'dob_ec') {
            if (!preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $m)) {
                throw new InvalidArgumentException('Use Ethiopian day/month/year, for example 12/04/2002.');
            }
            $day=(int)$m[1]; $month=(int)$m[2]; $year=(int)$m[3];
            $maxDay = $month === 13 ? (($year % 4 === 3) ? 6 : 5) : 30;
            if ($month < 1 || $month > 13 || $day < 1 || $day > $maxDay || $year < 1900 || $year > 2200) {
                throw new InvalidArgumentException('That Ethiopian date is not valid.');
            }
            $canonical = sprintf('%02d/%02d/%04d', $day, $month, $year);
            return ['display'=>$canonical, 'normalized'=>$canonical];
        }
        if (in_array($key, ['phone_number','guardian_phone1'], true)) {
            if (!preg_match('/^[0-9+()\- .]+$/', $value)) throw new InvalidArgumentException('Enter a valid phone number.');
            $suffix = AmharicIdentityNormalizer::phoneSuffix($value);
            if ($suffix === '') throw new InvalidArgumentException('Enter at least 9 phone digits.');
            return ['display'=>$value, 'normalized'=>$suffix];
        }
        $nameLike = in_array($key, ['baptismal_name','guardian_name'], true);
        return ['display'=>$value, 'normalized'=>$nameLike ? AmharicIdentityNormalizer::normalize($value) : $value];
    }
}
