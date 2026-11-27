<?php
/**
 * Members app — router.
 *
 * Deliberately tiny and explicit. There is no reflection, no annotation
 * scanning and no "controller" string from the URL. A route table is written
 * out in routes/web.php, so the complete attack surface of this app is one
 * readable file.
 */

declare(strict_types=1);

namespace SSMS\Members\Http;

use SSMS\Members\Http\Middleware\Middleware;

final class Router
{
    /**
     * @var list<array{
     *   method:string,
     *   pattern:string,
     *   handler:callable,
     *   middleware:list<Middleware>
     * }>
     */
    private array $routes = [];

    /**
     * Register a route.
     *
     * Patterns use `{name}` placeholders: `/lesson/{id}`. The captured value is
     * passed to the handler as a string — handlers cast explicitly, so a route
     * param is never magically "already an int".
     *
     * @param callable(array<string,string>, Request): Response $handler
     * @param list<Middleware> $middleware
     */
    public function get(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /** @param list<Middleware> $middleware */
    public function post(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /**
     * @param callable(array<string,string>, Request): Response $handler
     * @param list<Middleware> $middleware
     */
    private function add(string $method, string $pattern, callable $handler, array $middleware): void
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => '/' . trim($pattern, '/'),
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * Dispatch. Returns the handler's Response, or a 404.
     *
     * A path that matches a DIFFERENT method returns 405 rather than 404 —
     * "this exists but not that way" is more useful to a client and costs
     * nothing to say.
     */
    public function dispatch(Request $request): Response
    {
        $path = '/' . trim($request->path(), '/');
        $methodNotAllowed = false;

        foreach ($this->routes as $route) {
            $params = $this->match($route['pattern'], $path);
            if ($params === null) {
                continue;
            }
            if ($route['method'] !== $request->method()) {
                $methodNotAllowed = true;
                continue;
            }

            $response = ($route['handler'])($params, $request);
            if (!$response instanceof Response) {
                throw new \RuntimeException('A route handler must return a Response.');
            }

            // Middleware runs on the way OUT, so a header added by middleware
            // cannot be lost by a handler that builds its own response.
            foreach (array_reverse($route['middleware']) as $middleware) {
                $response = $middleware->process($request, $response);
            }
            return $response;
        }

        return Response::html(
            '<!doctype html><meta charset="utf-8"><title>Not found</title>'
            . '<p style="font-family:system-ui;padding:2rem">Page not found. '
            . '<a href="/members/">Go to the member home page</a>.</p>',
            $methodNotAllowed ? 405 : 404
        );
    }

    /**
     * Match one pattern, returning captured params or null.
     *
     * @return array<string,string>|null
     */
    private function match(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }
        if (!str_contains($pattern, '{')) {
            return null;
        }

        $regex = preg_replace(
            '/\{([a-z_][a-z0-9_]*)\}/i',
            '(?P<$1>[A-Za-z0-9_-]+)',
            $pattern
        );
        if (!is_string($regex)) {
            return null;
        }

        if (preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        return $params;
    }
}
