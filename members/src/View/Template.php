<?php
declare(strict_types=1);

namespace MembersPortal\View;

/**
 * The only way a page becomes HTML.
 *
 * A template receives exactly two things: an escaping helper and the array
 * the page prepared. It cannot reach $_SESSION, a service or the database,
 * because nothing is in scope to reach them with. That is the point: a leak
 * becomes a visible mistake in a diff instead of a query hidden in a view.
 *
 * Layout is two steps, not one: the page renders first, then the layout
 * receives it as $content. A page therefore never repeats the <html> shell,
 * and a layout never contains page logic.
 */
final class Template
{
    /**
     * @param string              $name template path below templates/, no extension
     * @param array<string,mixed> $data values the page prepared
     */
    public static function render(string $name, array $data = []): string
    {
        $file = dirname(__DIR__, 2) . '/templates/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Template not found: ' . $name);
        }

        $e = self::escaper();

        ob_start();
        require $file;

        return (string)ob_get_clean();
    }

    /**
     * Render a page inside the portal layout.
     *
     * @param array<string,mixed> $data
     */
    public static function page(string $name, array $data = [], string $title = 'Members'): string
    {
        $content = self::render('pages/' . $name, $data);

        return self::render('layouts/base', [
            'title'        => $title,
            'content'      => $content,
            'session'      => $data['session'] ?? null,
            'current_slug' => $data['current_slug'] ?? '',
        ]);
    }

    /**
     * Escaper handed to templates as $e.
     *
     * ENT_QUOTES for both quote styles, ENT_SUBSTITUTE so invalid UTF-8 from
     * a database row cannot blank the page, and an explicit charset so the
     * output does not depend on the default_charset ini setting.
     */
    private static function escaper(): \Closure
    {
        return static fn($value): string
            => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
