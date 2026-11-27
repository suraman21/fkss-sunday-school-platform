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
            // Strip the app's own mount prefix so routes are written relative
            // to it ("/", "/lessons") and work under an Apache Alias.
            $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
            $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
            if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base));
            }
            if (str_ends_with($path, '/index.php')) {
                $path = substr($path, 0, -strlen('index.php'));
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
