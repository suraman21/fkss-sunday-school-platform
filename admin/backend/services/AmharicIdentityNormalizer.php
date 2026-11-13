<?php
/**
 * Search-only Amharic identity canonicalization.
 *
 * This is deliberately lossy and MUST NOT replace the spelling stored or
 * displayed to staff. Version the result so a later mapping can be backfilled
 * without silently changing identity semantics.
 */
namespace App\Services;

final class AmharicIdentityNormalizer
{
    public const VERSION = 1;

    /** @var array<string,string> */
    private const FOLD = [
        // ሐ / ኀ / ኸ families -> ሀ family, preserving vowel order.
        'ሐ'=>'ሀ','ሑ'=>'ሁ','ሒ'=>'ሂ','ሓ'=>'ሃ','ሔ'=>'ሄ','ሕ'=>'ህ','ሖ'=>'ሆ','ሗ'=>'ሏ',
        'ኀ'=>'ሀ','ኁ'=>'ሁ','ኂ'=>'ሂ','ኃ'=>'ሃ','ኄ'=>'ሄ','ኅ'=>'ህ','ኆ'=>'ሆ','ኋ'=>'ሏ',
        'ኸ'=>'ሀ','ኹ'=>'ሁ','ኺ'=>'ሂ','ኻ'=>'ሃ','ኼ'=>'ሄ','ኽ'=>'ህ','ኾ'=>'ሆ','ዃ'=>'ሏ',
        // ሠ -> ሰ family.
        'ሠ'=>'ሰ','ሡ'=>'ሱ','ሢ'=>'ሲ','ሣ'=>'ሳ','ሤ'=>'ሴ','ሥ'=>'ስ','ሦ'=>'ሶ','ሧ'=>'ሷ',
        // ዐ / ኣ -> አ family.
        'ዐ'=>'አ','ዑ'=>'ኡ','ዒ'=>'ኢ','ዓ'=>'ኣ','ዔ'=>'ኤ','ዕ'=>'እ','ዖ'=>'ኦ',
        'ኣ'=>'አ',
        // ፀ -> ጸ family.
        'ፀ'=>'ጸ','ፁ'=>'ጹ','ፂ'=>'ጺ','ፃ'=>'ጻ','ፄ'=>'ጼ','ፅ'=>'ጽ','ፆ'=>'ጾ',
        // Frequently interchangeable sixth/second order wa spelling.
        'ዉ'=>'ው',
    ];

    public static function normalize($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = trim((string)$value);
        if ($text === '' || preg_match('//u', $text) !== 1) {
            return '';
        }
        if (class_exists('Normalizer')) {
            $normal = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normal)) {
                $text = $normal;
            }
        }
        $text = strtr($text, self::FOLD);
        $text = str_replace(
            ['፡','።','፣','፤','፥','፦','፧','፨','·','•','–','—','_'],
            ' ',
            $text
        );
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        return trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /** @return array{student:string,father:string,grandfather:string,full:string,pair_hash:string} */
    public static function splitFullName($value): array
    {
        $full = self::normalize($value);
        $parts = $full === '' ? [] : preg_split('/\s+/u', $full, 3, PREG_SPLIT_NO_EMPTY);
        $student = (string)($parts[0] ?? '');
        $father = (string)($parts[1] ?? '');
        $grandfather = (string)($parts[2] ?? '');
        return [
            'student' => $student,
            'father' => $father,
            'grandfather' => $grandfather,
            'full' => $full,
            'pair_hash' => ($student !== '' && $father !== '')
                ? self::pairHash($student, $father)
                : '',
        ];
    }

    /** Binary 128-bit blocking key for the canonical first/father pair. */
    public static function pairHash(string $student,string $father): string
    {
        return ($student!==''&&$father!=='')?md5($student."\0".$father,true):'';
    }

    public static function phoneSuffix($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $digits = preg_replace('/\D+/', '', (string)$value);
        return is_string($digits) && strlen($digits) >= 9 ? substr($digits, -9) : '';
    }
}
