<?php
declare(strict_types=1);

namespace MembersPortal\Support;

use RuntimeException;

/**
 * An error that carries an HTTP status and a message safe to show a user.
 *
 * Why this exists in the skeleton: it is the type every layer will throw
 * across a boundary. Anything that is NOT this class is an internal failure
 * — logged, and answered with a generic message. Keeping the two apart is
 * what makes "did I mean to show this text to a member?" a decision rather
 * than an accident.
 *
 * Statuses used so far: 404 (no such page), 500 (unexpected).
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
}
