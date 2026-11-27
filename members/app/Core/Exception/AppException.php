<?php
/**
 * Members app — base application exception.
 *
 * The HTTP status lives ON the exception so a controller can throw the honest
 * thing ("this is not yours") and let one handler turn it into a response.
 * Otherwise every controller repeats the same try/catch ladder and eventually
 * one of them gets the status wrong.
 */

declare(strict_types=1);

namespace SSMS\Members\Core\Exception;

use RuntimeException;

class AppException extends RuntimeException
{
    /** Machine-readable code the client may branch on (never prose-matched). */
    private string $errorCode;

    /** Additional safe, non-secret detail for the client. */
    /** @var array<string,mixed> */
    private array $details;

    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        string $message,
        int $httpStatus = 400,
        string $errorCode = 'APP_ERROR',
        array $details = []
    ) {
        parent::__construct($message, $httpStatus);
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    public function httpStatus(): int
    {
        return $this->getCode() > 0 ? $this->getCode() : 400;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
