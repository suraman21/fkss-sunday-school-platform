<?php
/**
 * Members app — ForbiddenException (HTTP 403).
 */

declare(strict_types=1);

namespace SSMS\Members\Core\Exception;

/**
 * Thrown when a request fails because of permission.
 * The status travels with the exception so the handler never has to guess.
 */
final class ForbiddenException extends AppException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, 403, 'FORBIDDEN', $details);
    }
}
