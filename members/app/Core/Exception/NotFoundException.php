<?php
/**
 * Members app — NotFoundException (HTTP 404).
 */

declare(strict_types=1);

namespace SSMS\Members\Core\Exception;

/**
 * Thrown when a request fails because of not found.
 * The status travels with the exception so the handler never has to guess.
 */
final class NotFoundException extends AppException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, 404, 'NOT_FOUND', $details);
    }
}
