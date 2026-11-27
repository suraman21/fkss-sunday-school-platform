<?php
/**
 * Members app — template renderer.
 *
 * Plain PHP templates with automatic escaping. Templates live outside the web
 * root, so a template can never be requested directly — which is the failure
 * mode that turns a `.php` template into a file-disclosure bug.
 */

declare(strict_types=1);

namespace SSMS\Members\Http\Support;

use RuntimeException;
use SSMS\Members\Core\Config;

final class View
{
    private string $viewsPath;

    public function __construct(?string $viewsPath = null)
    {
        $this->viewsPath = $viewsPath ?? MEMBERS_ROOT . '/resources/views';
    }

    /**
     * Render a template to a string.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->resolve($template);
        $data['config'] = $data['config'] ?? [];

        // Capture output. `extract` runs in this method's scope only, so a
        // template cannot reach the View object's own properties.
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data);
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
        return (string)ob_get_clean();
    }

    /**
     * Template names are a fixed slug shape — no dots, no slashes beyond the
     * one separating a folder, no `..`. That makes path traversal
     * unrepresentable rather than merely blocked.
     */
    private function resolve(string $template): string
    {
        if (preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)?$#', $template) !== 1) {
            throw new RuntimeException('Invalid template name: ' . $template);
        }
        $file = $this->viewsPath . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template not found: ' . $template);
        }
        return $file;
    }

    /** Languages offered by the UI, for the language toggle. */
    /** @return list<string> */
    public static function locales(): array
    {
        $locales = Config::get('locales', ['am']);
        return is_array($locales) ? array_values(array_filter($locales, 'is_string')) : ['am'];
    }
}
