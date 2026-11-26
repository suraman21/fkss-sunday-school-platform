<?php
declare(strict_types=1);

namespace MembersPortal\Support;

use RuntimeException;

/**
 * An error that carries an HTTP status and a message safe to show a user.
 *
 * Anything thrown that is NOT this class is treated as an internal error:
 * logged, and answered with a generic 500. That split is the whole point —
 * it makes "did I mean to show this text to a member?" a decision rather
 * than an accident.
 */
final class HttpException extends RuntimeException
{
    private int $status;

    /** Extra public fields (e.g. a machine-readable code). Never diagnostics. */
    private array $publicPayload;

    public function __construct(int $status, string $message, array $publicPayload = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->publicPayload = $publicPayload;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function publicPayload(): array
    {
        return $this->publicPayload;
    }

    public static function notFound(string $message = 'Page not found.'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'You do not have access to this page.'): self
    {
        return new self(403, $message);
    }
}
