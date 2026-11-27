<?php
/**
 * Members app — HTTP response value object.
 *
 * Immutable: a handler builds a response and returns it. Nothing writes headers
 * as a side effect of rendering, which is what makes the middleware chain
 * (security headers, caching) reliable.
 */

declare(strict_types=1);

namespace SSMS\Members\Http;

final class Response
{
    private int $status;
    private string $body;

    /** @var array<string,string> */
    private array $headers;

    private bool $sent = false;

    /**
     * @param array<string,string> $headers
     */
    private function __construct(int $status, string $body, array $headers)
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    /**
     * Public HTML.
     *
     * `Cache-Control: no-store` is not a hint here — every page in this app is
     * personalised, and a shared proxy caching one member's dashboard for
     * another is a data breach. HTML is never cached.
     */
    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Static assets are the only cacheable responses, and their version is in
     * the query string, so a long max-age is safe and a deploy still lands.
     */
    public static function asset(string $body, string $contentType): self
    {
        return new self(200, $body, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public static function notFound(string $body = 'Not found'): self
    {
        return self::html($body, 404);
    }

    /** Add or replace a header. Returns a new instance. */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Write to the client exactly once. */
    public function send(): void
    {
        if ($this->sent) {
            return;
        }
        $this->sent = true;

        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ($this->status !== 204) {
            echo $this->body;
        }
    }
}
