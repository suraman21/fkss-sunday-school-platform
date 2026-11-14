<?php
/** Loads and interpolates per-deployment Telegram bot messages. */
namespace App\Services;

require_once __DIR__ . '/TelegramBotMessages.php';

final class TelegramMessageService
{
    /** @var array<string,array<string,string>>|null */
    private static ?array $cache = null;

    /** @return array<string,array<string,string>> */
    private static function load(\mysqli $conn): array
    {
        if (self::$cache !== null) return self::$cache;
        self::$cache = [];
        foreach (TelegramBotMessages::defaults() as $key => $langs) {
            foreach ($langs as $lang => $text) {
                self::$cache[$key][$lang] = $text;
            }
        }
        try {
            $result = $conn->query('SELECT message_key, language, message_text FROM telegram_bot_messages');
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $key = (string)$row['message_key'];
                    $lang = (string)$row['language'];
                    if ($key !== '' && $lang !== '') {
                        self::$cache[$key][$lang] = (string)$row['message_text'];
                    }
                }
                $result->free();
            }
        } catch (\Throwable $e) {
            // Table may not exist yet during migration; fall back to defaults.
        }
        return self::$cache;
    }

    /** Invalidate the in-process cache (after admin saves a change). */
    public static function invalidate(): void { self::$cache = null; }

    /** Render a message with simple {placeholder} replacement. */
    public static function get(\mysqli $conn, string $key, array $vars = [], string $lang = 'am'): string
    {
        $all = self::load($conn);
        $text = (string)($all[$key][$lang] ?? $all[$key]['am'] ?? "[missing:$key]");
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
        return $text;
    }

    /** Ensure all default rows are written to DB (idempotent seed). */
    public static function seedDefaults(\mysqli $conn): int
    {
        $count = 0;
        $stmt = $conn->prepare(
            'INSERT IGNORE INTO telegram_bot_messages (message_key,language,message_text) VALUES (?,?,?)'
        );
        if (!$stmt) return 0;
        foreach (TelegramBotMessages::defaults() as $key => $langs) {
            foreach ($langs as $lang => $text) {
                $stmt->bind_param('sss', $key, $lang, $text);
                if ($stmt->execute()) $count += $stmt->affected_rows;
            }
        }
        $stmt->close();
        return $count;
    }
}
