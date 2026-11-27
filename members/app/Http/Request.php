<?php
/**
 * Members app — HTTP request value object.
 *
 * Nothing in this app reads `$_GET`, `$_POST` or `$_SERVER` directly outside
 * this class. One funnel means one place to audit for input handling, and one
 * place to change if the deployment shape changes.
 */

declare(strict_types=1);

namespace SSMS\Members\Http;

final class Request
{
    private string $method;
    private string $path;

    /** @var array<string,string> */
    private array $query;

    /** @var array<string,mixed> */
    private array $post;

    /** @var array<string,string> */
    private array $headers;

    /** @var array<string,mixed> */
    private array $server;

    /**
     * @param array<string,string> $query
     * @param array<string,mixed>  $post
     * @param array<string,string> $headers
     * @param array<string,mixed>  $server
     */
    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $post = [],
        array $headers = [],
        array $server = []
    ) {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->post = $post;
        $this->headers = $headers;
        $this->server = $server;
    }

    /** Build from PHP superglobals. The only place that touches them. */
    public static function fromGlobals(): self
    {
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Path resolution: support a front controller under a subdirectory
        // and the common `?route=` fallback, exactly like api/v1/index.php.
        $path = '';
        if (isset($_GET['route']) && is_string($_GET['route'])) {
            $path = $_GET['route'];
        } elseif (isset($_SERVER['PATH_INFO']) && is_string($_SERVER['PATH_INFO'])) {
            $path = $_SERVER['PATH_INFO'];
        } else {
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
            $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
            $path = self::stripMountPrefix($path, (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            // /members/index.php/home -> /home, and a bare /index.php -> /
            if (str_contains($path, '/index.php')) {
                $path = str_replace('/index.php', '', $path);
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string)$key, 'HTTP_') && is_string($value)) {
                $name = strtolower(str_replace('_', '-', substr((string)$key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        /** @var array<string,string> $query */
        $query = array_filter($_GET, 'is_string');

        return new self($method, '/' . trim($path, '/'), $query, $_POST, $headers, $_SERVER);
    }

    /**
     * Remove the deployment mount prefix from a request path.
     *
     * WHY THIS IS NOT `dirname(SCRIPT_NAME)`
     *   Apache sets SCRIPT_NAME to the path AFTER an internal rewrite. With
     *   `members/.htaccess` sending /members/home to public/index.php, the
     *   value is "/members/public/index.php". Taking dirname() of that yields
     *   "/members/public" -- a prefix "/members/home" does not start with -- so
     *   nothing was stripped and the router (which only knows "/home") matched
     *   nothing. Every page 404'd at the one mount the app advertises.
     *
     *   MEASURED, not guessed: with the old code, a request for /members/home
     *   resolved to the path "/members/home", served at
     *   SCRIPT_NAME=/members/public/index.php.
     *
     * WHAT IS TRIED, IN ORDER
     *   Every candidate is derived from SCRIPT_NAME and applied only on a
     *   segment boundary, so "/membership" is never mistaken for "/members".
     *   - the script's own directory, "/members/public"
     *   - that directory without a trailing "public", "/members"
     *   - the same two with a bare "/index.php" stripped, for a request that
     *     arrives as /members/index.php/home
     *
     *   The longest matching candidate wins implicitly by trying specific
     *   prefixes first. Unmatched paths are returned unchanged, so serving the
     *   app at a document root (SCRIPT_NAME=/index.php) still works.
     *
     * THIS IS NOT TRUSTING THE CLIENT
     *   Every candidate comes from SCRIPT_NAME, which is server-set. REQUEST_URI
     *   is client-supplied but is only ever the string being trimmed. A hostile
     *   "/members/../../etc" does not resolve to a route: the router matches
     *   exact path patterns and the leading ".." survives the trim, so it 404s.
     */
    private static function stripMountPrefix(string $path, string $scriptName): string
    {
        $scriptName = str_replace('\\', '/', $scriptName);
        if ($scriptName === '') {
            return $path;
        }

        $dir = rtrim(dirname($scriptName), '/');
        $candidates = [];

        if ($dir !== '' && $dir !== '/' && $dir !== '.') {
            $candidates[] = $dir;                       // /members/public
            if (str_ends_with($dir, '/public')) {       // /members
                $candidates[] = substr($dir, 0, -strlen('/public'));
            }
        }
        if (str_ends_with($scriptName, '/index.php')) {
            $trimmed = substr($scriptName, 0, -strlen('/index.php'));
            if ($trimmed !== '') {
                $candidates[] = $trimmed;               // /members/index.php -> /members
            }
        }

        // Most specific first, deduplicated, empty never included.
        $candidates = array_values(array_unique(array_filter(
            $candidates,
            static fn(string $c): bool => $c !== '' && $c !== '/'
        )));
        usort($candidates, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($candidates as $base) {
            if ($path === $base) {
                return '/';
            }
            if (str_starts_with($path, $base . '/')) {
                return substr($path, strlen($base));
            }
        }

        return $path;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** Query-string value, or null. Always a string — never an array. */
    public function query(string $key): ?string
    {
        $value = $this->query[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * POST value as a trimmed string, or null.
     *
     * Arrays are rejected rather than flattened: a form field that arrives as
     * `name[]=x` is either a bug or an attack, and silently taking the first
     * element turns a malformed request into a valid-looking one.
     */
    public function post(string $key): ?string
    {
        $value = $this->post[$key] ?? null;
        return is_string($value) ? trim($value) : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Bearer token from the Authorization header, or null. */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header === null) {
            return null;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    /**
     * Client IP, for rate limiting only.
     *
     * X-Forwarded-For is honoured ONLY when the request arrives from a trusted
     * proxy, and only the LAST hop is taken. Trusting the first entry lets a
     * client forge its own limiter key by sending its own header, which turns
     * rate limiting into a no-op.
     */
    public function clientIp(array $trustedProxies = []): string
    {
        $remote = (string)($this->server['REMOTE_ADDR'] ?? '');
        if ($trustedProxies === [] || !in_array($remote, $trustedProxies, true)) {
            return $remote;
        }
        $forwarded = $this->header('x-forwarded-for');
        if ($forwarded === null || $forwarded === '') {
            return $remote;
        }
        $hops = array_map('trim', explode(',', $forwarded));
        $last = end($hops);
        return is_string($last) && filter_var($last, FILTER_VALIDATE_IP) ? $last : $remote;
    }

    public function isSecure(): bool
    {
        $https = (string)($this->server['HTTPS'] ?? '');
        return ($https !== '' && strtolower($https) !== 'off')
            || (string)($this->server['SERVER_PORT'] ?? '') === '443';
    }
}
