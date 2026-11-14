<?php
/**
 * ACM S3 — code alphabet (design §D3.2). Pure static helpers.
 * ------------------------------------------------------------
 * Crockford base32 WITHOUT the ambiguous characters 0/O/1/I/L
 * (and U, per Crockford, to avoid accidental words): 30 symbols.
 * SELECTOR: 6 chars, public, indexed lookup key. SECRET: 8 chars
 * (~39 bits), hash-only storage, shown once at issuance.
 * Unbiased generation via rejection sampling over random_bytes.
 */

namespace SSMS\Acm\Activation;

use SSMS\Acm\Contracts\CodePurpose;

class CodeAlphabet
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const SELECTOR_LEN = 6;
    public const SECRET_LEN = 8;

    public static function random(int $length): string
    {
        $alphabet = self::ALPHABET;
        $base = strlen($alphabet);
        // Rejection sampling: accept only bytes below the largest
        // multiple of $base so every symbol is equally likely.
        $limit = 256 - (256 % $base);
        $out = '';
        while (strlen($out) < $length) {
            $bytes = random_bytes($length - strlen($out));
            for ($i = 0, $n = strlen($bytes); $i < $n && strlen($out) < $length; $i++) {
                $v = ord($bytes[$i]);
                if ($v < $limit) {
                    $out .= $alphabet[$v % $base];
                }
            }
        }
        return $out;
    }

    /** Normalize user-typed code material: strip separators, uppercase. */
    public static function normalize(string $value): string
    {
        return strtoupper(str_replace(['-', ' ', "\t", "\n"], '', trim($value)));
    }

    public static function isValid(string $value, int $length): bool
    {
        return strlen($value) === $length
            && strspn($value, self::ALPHABET) === $length;
    }

    /** Human distinguishability only — never trusted alone (§D3.2). */
    public static function prefixFor(CodePurpose $purpose): string
    {
        return match ($purpose) {
            CodePurpose::Activation => 'A',
            CodePurpose::DeviceAdd => 'D',
            CodePurpose::Reset => 'R',
        };
    }
}
