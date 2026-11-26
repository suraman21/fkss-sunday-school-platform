<?php
declare(strict_types=1);

namespace MembersPortal\Http;

use MembersPortal\View\Template;

/**
 * How a page answers. Small on purpose.
 *
 * The portal is a browser application and returns HTML. It does not expose a
 * JSON API: the machine-readable surface for members and parents belongs in
 * /api/v1, where apiRequireAuth(), the token layer and the ACL helpers
 * already exist and are tested. The prototype this portal is based on grew a
 * second parallel API and that is a large part of why it could not be
 * adopted.
 */
final class Response
{
    /** Send an HTML body with the headers every page needs. */
    public static function html(string $body, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            // A member's own data is never cached by a proxy or a shared PC.
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
        }
        echo $body;
    }

    /** Render a page inside the layout and send it. */
    public static function page(string $name, array $data = [], string $title = 'Members', int $status = 200): void
    {
        self::html(Template::page($name, $data, $title), $status);
    }
}
