<?php
/**
 * Members app — read-only configuration accessor.
 */

declare(strict_types=1);

namespace SSMS\Members\Core;

use RuntimeException;

/**
 * Typed, read-only access to config/app.php.
 *
 * WHY A CLASS RATHER THAN A GLOBAL ARRAY
 *   `$config['learning']['open_lessons_per_unit']` is a typo waiting to become
 *   a null. Dot-notation with a required default parameter means a missing key
 *   fails loudly at the call site instead of silently returning null three
 *   layers down.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $values = [];

    private static bool $loaded = false;

    /** Load once. Called by the bootstrap; safe to call repeatedly. */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new RuntimeException('Configuration file did not return an array: ' . $path);
        }
        self::$values = $values;
        self::$loaded = true;
    }

    /**
     * Read a dotted key. The parameter has no default on purpose: a caller that
     * cannot name a fallback has not thought about the missing case.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, mixed $default): mixed
    {
        $cursor = self::$values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    /** Integer read with a mandatory fallback — used for limits and TTLs. */
    public static function int(string $key, int $default): int
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (int)$value : $default;
    }

    /** Boolean read. Accepts real booleans and the strings "1"/"true". */
    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool)$value;
    }

    /** Test seam: reset loaded state. Not used in production paths. */
    public static function resetForTests(): void
    {
        self::$values = [];
        self::$loaded = false;
    }
}
