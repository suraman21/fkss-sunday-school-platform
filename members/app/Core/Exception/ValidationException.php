<?php
/**
 * Members app — ValidationException (HTTP 422).
 */

declare(strict_types=1);

namespace SSMS\Members\Core\Exception;

/**
 * Thrown when a request fails because of validation.
 * The status travels with the exception so the handler never has to guess.
 */
final class ValidationException extends AppException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, 422, 'VALIDATION_FAILED', $details);
    }
}
