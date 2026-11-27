<?php
/**
 * Members app — UnauthorizedException (HTTP 401).
 */

declare(strict_types=1);

namespace SSMS\Members\Core\Exception;

/**
 * Thrown when a request fails because of auth.
 * The status travels with the exception so the handler never has to guess.
 */
final class UnauthorizedException extends AppException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, 401, 'UNAUTHENTICATED', $details);
    }
}
