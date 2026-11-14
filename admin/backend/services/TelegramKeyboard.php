<?php
/** Small helpers for building Telegram reply keyboards and inline keyboards. */
namespace App\Services;

final class TelegramKeyboard
{
    /** Build a reply-keyboard structure (custom keyboard below the input). */
    public static function reply(array $rows, bool $resize = true, bool $oneTime = false, string $placeholder = ''): array
    {
        $k = ['keyboard' => $rows, 'resize_keyboard' => $resize];
        if ($oneTime) $k['one_time_keyboard'] = true;
        if ($placeholder !== '') $k['input_field_placeholder'] = $placeholder;
        return $k;
    }

    /** Build an inline keyboard (buttons attached to the message). Each entry is [text, callback_data]. */
    public static function inline(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $r = [];
            foreach ($row as $btn) {
                if (!is_array($btn)) continue;
                $b = ['text' => (string)$btn[0]];
                if (isset($btn[1]) && $btn[1] !== '') {
                    if (str_starts_with($btn[1], 'url:')) $b['url'] = substr($btn[1],4);
                    else $b['callback_data'] = substr((string)$btn[1],0,64);
                }
                $r[] = $b;
            }
            if ($r!==[]) $out[] = $r;
        }
        return ['inline_keyboard' => $out];
    }

    public static function remove(): array
    {
        return ['remove_keyboard' => true];
    }

    /** Construct a sendMessage or editMessageText payload. */
    public static function msg(int $chatId, string $text, ?array $markup = null, ?int $replyTo = null): array
    {
        $p = ['method'=>'sendMessage','chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML'];
        if ($markup !== null) $p['reply_markup'] = $markup;
        if ($replyTo !== null) $p['reply_to_message_id'] = $replyTo;
        return $p;
    }
}
